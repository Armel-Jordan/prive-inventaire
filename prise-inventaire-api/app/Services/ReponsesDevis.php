<?php

namespace App\Services;

use App\Models\CourrielParametres;
use App\Models\Devis;
use App\Models\Notification;
use App\Models\PortailParametres;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Réponse en ligne aux devis (plan de parité fripv2, phase 1.3) : le courriel d'un devis porte un lien vers une page
 * publique du site de l'espace client, où le client accepte ou refuse le devis. Le lien est le même à chaque envoi
 * (un courriel déjà envoyé reste valable) ; la réponse passe le devis à Accepté ou Refusé et prévient l'entreprise.
 */
class ReponsesDevis
{
    /** Devis qui peuvent encore recevoir une réponse (un brouillon partagé devient envoyé). */
    public const STATUTS_OUVERTS = [Devis::STATUT_BROUILLON, Devis::STATUT_ENVOYE];

    /** Le lien est proposé : réglage de l'entreprise (activé par défaut). */
    public function active(): bool
    {
        return (bool) (CourrielParametres::first()?->devis_reponse_en_ligne ?? true);
    }

    /** Adresse du site de l'espace client : ESPACE_CLIENT_URL, sinon celle transmise par le back-office ; null si inconnue. */
    public function site(): ?string
    {
        $adresse = config('portail.url') ?: PortailParametres::courants()->url_espace_client;

        return filled($adresse) ? rtrim((string) $adresse, '/') : null;
    }

    /** Le devis peut encore être accepté ou refusé (ni décidé, ni expiré). */
    public function ouvert(Devis $devis): bool
    {
        return in_array($devis->statut, self::STATUTS_OUVERTS, true) && ! $devis->estExpire();
    }

    /** Lien de réponse du devis ; null si la réponse en ligne est désactivée, le devis fermé ou le site inconnu. */
    public function lien(Devis $devis): ?string
    {
        $site = $this->site();

        return $site && $this->active() && $this->ouvert($devis) ? $site.'/devis/'.$this->jeton($devis) : null;
    }

    /** Jeton du lien, créé à la première demande puis toujours le même ; deux demandes simultanées gardent le même. */
    public function jeton(Devis $devis): string
    {
        if (blank($devis->reponse_jeton_hash)) {
            $jeton = Str::random(64);
            Devis::whereKey($devis->getKey())->whereNull('reponse_jeton_hash')
                ->update(['reponse_jeton' => Crypt::encryptString($jeton), 'reponse_jeton_hash' => hash('sha256', $jeton)]);
            $devis->refresh();
        }

        return (string) $devis->reponse_jeton;
    }

    /** Envoi au client (courriel, lien partagé) : un brouillon passe à Envoyé. */
    public function marquerEnvoye(int $devisId): void
    {
        Devis::whereKey($devisId)->where('statut', Devis::STATUT_BROUILLON)
            ->update(['statut' => Devis::STATUT_ENVOYE, 'envoye_le' => now()]);
    }

    /**
     * Devis d'un lien de réponse, l'entreprise courante étant alors la sienne ; null si le lien ne correspond à aucun
     * devis ou si l'entreprise n'est plus active (ou n'a plus les ventes).
     */
    public function devisDuLien(string $jeton): ?Devis
    {
        $trouve = Devis::withoutGlobalScopes()->whereNull('deleted_at')->where('reponse_jeton_hash', hash('sha256', $jeton))->first(['id', 'tenant_id']);
        $tenant = $trouve ? Tenant::where('id', $trouve->tenant_id)->where('actif', true)->first() : null;
        if (! $tenant || $tenant->isExpired() || ! $tenant->hasModule('ventes')) {
            return null;
        }
        app(TenantContext::class)->setTenantId((int) $tenant->id);

        return Devis::with(['client', 'lignes.produit'])->find($trouve->id);
    }

    /** Ce que voit le client sur la page du lien. */
    public function presenter(Devis $devis): array
    {
        $parametres = TenantParametres::first();
        $expire = $devis->statut === Devis::STATUT_EXPIRE || (in_array($devis->statut, self::STATUTS_OUVERTS, true) && $devis->estExpire());

        return [
            'entreprise' => [
                'nom' => $parametres?->nom_entreprise ?: Tenant::find($devis->tenant_id)?->nom,
                'logo_url' => $parametres?->logo_url,
                'telephone' => $parametres?->telephone,
                'email' => $parametres?->email,
            ],
            'devis' => [
                'numero' => $devis->numero,
                'date_devis' => $devis->date_devis?->toDateString(),
                'date_validite' => $devis->date_validite?->toDateString(),
                'statut' => $expire ? Devis::STATUT_EXPIRE : $devis->statut,
                // Devise du devis, sinon celle de l'entreprise.
                'devise' => $devis->devise ?: $parametres?->devise_symbole,
                'montant_total' => (float) $devis->montant_total,
                'notes' => $devis->notes,
                // Client de passage : le nom noté sur le devis.
                'client' => $devis->client?->de_passage && filled($devis->passage_nom) ? $devis->passage_nom : $devis->client?->raison_sociale,
                'lignes' => $devis->lignes->map(fn ($l) => [
                    'description' => $l->produit?->description ?? '',
                    'quantite' => (float) $l->quantite,
                    'prix_unitaire' => (float) $l->prix_unitaire,
                    'montant' => (float) $l->montant_ligne,
                ])->values()->all(),
            ],
            'reponse' => $devis->reponse_le ? [
                'le' => $devis->reponse_le->toIso8601String(),
                'en_ligne' => (bool) $devis->reponse_en_ligne,
                'nom' => $devis->reponse_en_ligne ? $devis->reponse_nom : null,
            ] : null,
            'peut_repondre' => $devis->statut === Devis::STATUT_ENVOYE && ! $devis->estExpire(),
        ];
    }

    /**
     * Réponse du client donnée en ligne. Renvoie null si elle est enregistrée, sinon la raison du refus : devis déjà
     * décidé, expiré (il passe alors à Expiré) ou pas encore envoyé.
     */
    public function repondre(Devis $devis, bool $accepte, ?string $nom, ?string $commentaire): ?string
    {
        return DB::transaction(function () use ($devis, $accepte, $nom, $commentaire) {
            $verrouille = Devis::whereKey($devis->getKey())->lockForUpdate()->firstOrFail();
            if ($verrouille->statut !== Devis::STATUT_ENVOYE) {
                return match ($verrouille->statut) {
                    Devis::STATUT_ACCEPTE => 'Ce devis a déjà été accepté.',
                    Devis::STATUT_REFUSE => 'Ce devis a déjà été refusé.',
                    Devis::STATUT_EXPIRE => 'Ce devis a expiré : demandez-en un nouveau.',
                    default => 'Ce devis ne peut pas recevoir de réponse pour le moment.',
                };
            }
            if ($verrouille->estExpire()) {
                $verrouille->update(['statut' => Devis::STATUT_EXPIRE]);

                return "Ce devis a expiré le {$verrouille->date_validite->format('d/m/Y')} : demandez-en un nouveau.";
            }

            $verrouille->update([
                'statut' => $accepte ? Devis::STATUT_ACCEPTE : Devis::STATUT_REFUSE,
                'reponse_le' => now(),
                'reponse_en_ligne' => true,
                'reponse_nom' => filled($nom) ? trim((string) $nom) : null,
                'reponse_commentaire' => filled($commentaire) ? trim((string) $commentaire) : null,
            ]);

            $client = $devis->client?->de_passage && filled($devis->passage_nom) ? $devis->passage_nom : $devis->client?->raison_sociale;
            $signataire = filled($nom) ? ' ('.trim((string) $nom).')' : '';
            Notification::create([
                'type' => $accepte ? 'devis_accepte' : 'devis_refuse',
                'titre' => $accepte ? 'Devis accepté en ligne' : 'Devis refusé en ligne',
                'message' => "{$client}{$signataire} a ".($accepte ? 'accepté' : 'refusé')." le devis {$verrouille->numero}."
                    .($accepte ? ' Il peut être converti en commande.' : ''),
                'lien' => '/devis?recherche='.rawurlencode($verrouille->numero),
                'data' => ['devis_id' => $verrouille->id],
            ]);

            return null;
        });
    }
}
