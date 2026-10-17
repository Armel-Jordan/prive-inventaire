<?php

namespace App\Services;

use App\Models\Acompte;
use App\Models\Avoir;
use App\Models\Client;
use App\Models\Configuration;
use App\Models\Encaissement;
use App\Models\EncaissementLigne;
use App\Models\Facture;
use App\Models\FacturePaiement;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Encaissements des clients (plan de parité fripv2, phase 4.4), comme l'écran « Encaissement d'un client » de fripv2 :
 * - l'argent reçu en une fois (chèque, virement…) se répartit sur les factures dues du client, dans la devise de
 *   l'encaissement : chaque part devient un paiement de sa facture (reste à payer, échéances, encours, comptes clients
 *   et caisse suivent comme pour tout paiement) ;
 * - le surplus reste au crédit du client : un acompte, qu'il pourra appliquer plus tard ou se faire rembourser ; un
 *   encaissement sans facture est donc une avance ;
 * - une répartition qui dépasse l'argent reçu demande d'accorder la différence en escompte, partagé entre les factures
 *   au prorata de leur part ;
 * - un encaissement se corrige (référence, notes) ou s'annule : ses paiements sont retirés des factures, son surplus
 *   supprimé s'il n'a pas servi. La comptabilisation automatique (phase 4.3) le passe au journal en une écriture.
 */
class Encaissements
{
    private const TOLERANCE = 0.005;

    public function __construct(private readonly Caisses $caisses, private readonly Acomptes $acomptes) {}

    public function relations(): array
    {
        return [
            'client:id,code,raison_sociale,devise',
            'lignes.facture:id,numero,date_facture,date_echeance,montant_ttc,montant_paye,reste_a_payer,statut,devise',
            'acompte', 'auteur:id,nom', 'auteurAnnulation:id,nom',
        ];
    }

    /**
     * Ce que le client doit dans une devise (vide : la sienne) : ses factures dues, l'échéance la plus ancienne
     * d'abord (sans échéance, une facture est due à sa date, comme dans les comptes clients), et les crédits dont il
     * dispose déjà (acomptes, notes de crédit).
     */
    public function aRegler(Client $client, ?string $devise, ?float $taux = null): array
    {
        $code = app(DevisesVente::class)->pourDocument($devise, $taux ?? 1, $client)['devise'];
        $echeance = fn (Facture $f) => ($f->date_echeance ?? $f->date_facture)->toDateString();
        $factures = Facture::query()->where('client_id', $client->id)->whereIn('statut', Acomptes::FACTURES_DUES)->where('reste_a_payer', '>', 0)
            ->get(['id', 'numero', 'date_facture', 'date_echeance', 'montant_ttc', 'montant_paye', 'montant_credite', 'montant_escompte', 'reste_a_payer', 'statut', 'devise'])
            ->filter(fn (Facture $f) => DevisesVente::memeDevise($f->devise, $code))
            ->sortBy([fn (Facture $a, Facture $b) => $echeance($a) <=> $echeance($b), ['date_facture', 'asc'], ['id', 'asc']])->values();
        $acomptes = Acompte::query()->where('client_id', $client->id)->get()
            ->filter(fn (Acompte $a) => $a->disponible > 0 && DevisesVente::memeDevise($a->devise, $code));
        $avoirs = Avoir::query()->where('client_id', $client->id)->whereIn('statut', Avoir::EMISES)->get()
            ->filter(fn (Avoir $a) => $a->credit_disponible > 0 && DevisesVente::memeDevise($a->devise, $code));

        return [
            'devise' => $code,
            'factures' => $factures->map(fn (Facture $f) => $f->only(['id', 'numero', 'montant_ttc', 'montant_paye', 'montant_credite', 'montant_escompte', 'reste_a_payer', 'statut', 'devise']) + [
                'date_facture' => $f->date_facture?->toDateString(),
                'date_echeance' => $f->date_echeance?->toDateString(),
                'jours_retard' => $echeance($f) < today()->toDateString() ? (int) Carbon::parse($echeance($f))->diffInDays(today()) : 0,
            ])->all(),
            'total_du' => round($factures->sum('reste_a_payer'), 2),
            'credits' => [
                'acomptes' => round($acomptes->sum('disponible'), 2),
                'avoirs' => round($avoirs->sum('credit_disponible'), 2),
            ],
        ];
    }

    /**
     * Enregistre un encaissement ; reçu sur le poste d'une caisse (`caisse_id`), il entre dans sa session ouverte.
     *
     * @param  array{client_id: int, date_encaissement: string, mode: string, reference?: ?string, montant: float|string, devise?: ?string, taux_change?: mixed, notes?: ?string, escompte?: bool, caisse_id?: ?int, lignes?: list<array{facture_id: int, montant: float|string}>}  $donnees
     */
    public function enregistrer(array $donnees, int $tenantId, ?int $auteur): Encaissement
    {
        $client = Client::findOrFail((int) $donnees['client_id']);
        $devise = app(DevisesVente::class)->pourDocument($donnees['devise'] ?? null, $donnees['taux_change'] ?? null, $client);
        $date = Carbon::parse($donnees['date_encaissement'])->toDateString();
        $montant = round((float) $donnees['montant'], 2);
        // Les lignes à zéro ne comptent pas (factures proposées, rien porté dessus).
        $lignes = collect($donnees['lignes'] ?? [])
            ->map(fn (array $ligne) => ['facture_id' => (int) $ligne['facture_id'], 'montant' => round((float) $ligne['montant'], 2)])
            ->filter(fn (array $ligne) => $ligne['montant'] > 0)->values();
        if ($lignes->pluck('facture_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['lignes' => 'Une facture ne figure qu\'une fois dans un encaissement.']);
        }
        $applique = round($lignes->sum('montant'), 2);
        $ecart = round($montant - $applique, 2);
        $escompte = 0.0;
        if ($ecart < -self::TOLERANCE) {
            if (! ($donnees['escompte'] ?? false)) {
                throw ValidationException::withMessages(['montant' => 'La répartition dépasse de '.$this->montant(-$ecart).' le montant reçu : réduisez-la, ou accordez la différence en escompte.']);
            }
            $escompte = -$ecart;
        }
        $surplus = max(0.0, $ecart);

        return DB::transaction(function () use ($donnees, $tenantId, $auteur, $client, $devise, $date, $montant, $lignes, $applique, $escompte, $surplus) {
            $session = $this->caisses->sessionPourEncaissement($donnees['caisse_id'] ?? null);
            // Verrous sur les factures, dans l'ordre : deux encaissements simultanés ne paient pas deux fois le même reste.
            $factures = Facture::query()->whereIn('id', $lignes->pluck('facture_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lignes as $ligne) {
                $this->verifierFacture($factures->get($ligne['facture_id']), $client, $devise['devise'], $ligne['montant'], $date);
            }

            $numero = $this->numero($tenantId);
            $encaissement = Encaissement::create($devise + [
                'tenant_id' => $tenantId,
                'numero' => $numero,
                'client_id' => $client->id,
                'date_encaissement' => $date,
                'mode' => $donnees['mode'],
                'reference' => $this->texte($donnees['reference'] ?? null),
                'montant' => $montant,
                'montant_applique' => $applique,
                'escompte' => $escompte,
                'surplus' => $surplus,
                'caisse_session_id' => $session,
                'notes' => $this->texte($donnees['notes'] ?? null),
                'statut' => Encaissement::VALIDE,
                'enregistre_par' => $auteur,
            ]);

            $parts = $this->repartirEscompte($escompte, $lignes->pluck('montant')->all());
            foreach ($lignes as $i => $ligne) {
                // Un paiement de la facture comme un autre (reste, statut, échéances, encours), rattaché à l'encaissement.
                $paiement = FacturePaiement::create([
                    'facture_id' => $ligne['facture_id'],
                    'encaissement_id' => $encaissement->id,
                    'date_paiement' => $date,
                    'montant' => round($ligne['montant'] - $parts[$i], 2),
                    'escompte' => $parts[$i],
                    'mode_paiement' => $encaissement->mode,
                    'reference' => mb_substr($encaissement->reference ?: $numero, 0, 100),
                    'notes' => "Encaissement {$numero}",
                    'enregistre_par' => $auteur,
                    'caisse_session_id' => $session,
                ]);
                EncaissementLigne::create([
                    'encaissement_id' => $encaissement->id, 'facture_id' => $ligne['facture_id'], 'montant' => $ligne['montant'],
                    'escompte' => $parts[$i], 'facture_paiement_id' => $paiement->id,
                ]);
            }

            // Le surplus reste au crédit du client : un acompte libre, dans la devise de l'encaissement.
            if ($surplus > 0) {
                $acompte = Acompte::create([
                    'client_id' => $client->id,
                    'date_acompte' => $date,
                    'mode' => $encaissement->mode,
                    'reference' => $numero,
                    'montant' => $surplus,
                    'devise' => $devise['devise'],
                    'taux_change' => $devise['taux_change'],
                    'notes' => "Surplus de l'encaissement {$numero}".($encaissement->reference ? " ({$encaissement->reference})" : ''),
                    'enregistre_par' => $auteur,
                    'caisse_session_id' => $session,
                ]);
                $encaissement->update(['acompte_id' => $acompte->id]);
            }

            // Relu : les colonnes laissées à leur valeur par défaut figurent aussi dans la réponse.
            return $encaissement->fresh($this->relations());
        });
    }

    /** Corrige la référence et les notes d'un encaissement (le reste se corrige en l'annulant puis en le refaisant). */
    public function modifier(Encaissement $encaissement, array $donnees): Encaissement
    {
        if ($encaissement->statut === Encaissement::ANNULE) {
            throw new DomainException("L'encaissement {$encaissement->numero} est annulé : il ne se modifie plus.");
        }

        return DB::transaction(function () use ($encaissement, $donnees) {
            $encaissement->update([
                'reference' => array_key_exists('reference', $donnees) ? $this->texte($donnees['reference']) : $encaissement->reference,
                'notes' => array_key_exists('notes', $donnees) ? $this->texte($donnees['notes']) : $encaissement->notes,
            ]);
            // Les paiements des factures portent la référence de l'encaissement (sinon son numéro).
            FacturePaiement::query()->where('encaissement_id', $encaissement->id)
                ->update(['reference' => mb_substr($encaissement->reference ?: $encaissement->numero, 0, 100)]);

            return $encaissement->load($this->relations());
        });
    }

    /**
     * Annule un encaissement saisi par erreur : ses paiements sont retirés des factures (qui redeviennent dues d'autant)
     * et son surplus, s'il n'a pas servi, est supprimé. L'écriture déjà passée au journal sera contre-passée.
     */
    public function annuler(Encaissement $encaissement, ?string $motif, ?int $auteur): Encaissement
    {
        return DB::transaction(function () use ($encaissement, $motif, $auteur) {
            $encaissement = Encaissement::query()->whereKey($encaissement->id)->lockForUpdate()->firstOrFail();
            if ($encaissement->statut === Encaissement::ANNULE) {
                throw new DomainException("L'encaissement {$encaissement->numero} est déjà annulé.");
            }
            $acompte = $encaissement->acompte_id ? Acompte::query()->whereKey($encaissement->acompte_id)->lockForUpdate()->first() : null;
            if ($acompte && ((float) $acompte->montant_applique > 0 || (float) $acompte->montant_rembourse > 0)) {
                throw new DomainException("Le surplus de l'encaissement {$encaissement->numero}, laissé en acompte, a déjà été appliqué ou remboursé : l'encaissement ne s'annule plus.");
            }
            $factures = Facture::query()->whereIn('id', $encaissement->lignes()->pluck('facture_id'))->orderBy('id')->lockForUpdate()->get();

            FacturePaiement::query()->where('encaissement_id', $encaissement->id)->delete();
            $encaissement->update([
                'statut' => Encaissement::ANNULE,
                'annule_le' => now(),
                'annule_par' => $auteur,
                'motif_annulation' => $this->texte($motif),
                // Le surplus n'appartient plus à l'encaissement : il peut disparaître.
                'acompte_id' => null,
            ]);
            if ($acompte) {
                $this->acomptes->supprimer($acompte);
            }
            // Recalcul autoritatif : reste à payer, statut, échéances, encours du client.
            $factures->each(fn (Facture $facture) => $facture->refresh()->updateMontantPaye());

            return $encaissement->load($this->relations());
        });
    }

    /** Totaux d'une liste d'encaissements valides, dans la devise de l'entreprise. */
    public function resume(Builder $requete): array
    {
        $valides = $requete->where('statut', Encaissement::VALIDE)->get(['montant', 'montant_applique', 'escompte', 'surplus', 'taux_change']);
        $total = fn (string $champ) => round($valides->sum(fn (Encaissement $e) => (float) $e->{$champ} * ((float) $e->taux_change ?: 1)), 2);

        return [
            'nombre' => $valides->count(),
            'montant' => $total('montant'),
            'montant_applique' => $total('montant_applique'),
            'escompte' => $total('escompte'),
            'surplus' => $total('surplus'),
        ];
    }

    /** Une facture que l'encaissement peut payer : du client, due, dans sa devise, sans dépasser son reste, pas postérieure. */
    private function verifierFacture(?Facture $facture, Client $client, ?string $devise, float $montant, string $date): void
    {
        if (! $facture || $facture->client_id !== $client->id) {
            throw ValidationException::withMessages(['lignes' => 'Choisissez des factures du client.']);
        }
        if (! in_array($facture->statut, Acomptes::FACTURES_DUES, true)) {
            throw ValidationException::withMessages(['lignes' => "La facture {$facture->numero} ne reste pas due."]);
        }
        if (! DevisesVente::memeDevise($facture->devise, $devise)) {
            throw ValidationException::withMessages(['lignes' => "La facture {$facture->numero} n'est pas dans la devise de l'encaissement."]);
        }
        if ($montant > (float) $facture->reste_a_payer + self::TOLERANCE) {
            throw ValidationException::withMessages(['lignes' => "Le montant dépasse le reste à payer de la facture {$facture->numero} ({$this->montant($facture->reste_a_payer)})."]);
        }
        if ($date < $facture->date_facture->toDateString()) {
            throw ValidationException::withMessages(['date_encaissement' => "L'encaissement ne peut pas précéder la facture {$facture->numero} ({$facture->date_facture->format('d/m/Y')})."]);
        }
    }

    /**
     * Escompte partagé entre les factures au prorata de leur part ; l'écart d'arrondi va aux plus grosses parts qui ont
     * encore de la place (une facture ne reçoit jamais plus d'escompte que sa part).
     *
     * @param  list<float>  $montants
     * @return list<float>
     */
    private function repartirEscompte(float $escompte, array $montants): array
    {
        $total = array_sum($montants);
        $parts = array_map(fn (float $m) => $escompte > 0 && $total > 0 ? min($m, round($escompte * $m / $total, 2)) : 0.0, $montants);
        $reste = round($escompte - array_sum($parts), 2);
        $ordre = array_keys($montants);
        usort($ordre, fn (int $a, int $b) => $montants[$b] <=> $montants[$a]);
        foreach ($ordre as $i) {
            if (abs($reste) < self::TOLERANCE) {
                break;
            }
            $ajout = $reste > 0 ? min($reste, round($montants[$i] - $parts[$i], 2)) : max($reste, -$parts[$i]);
            $parts[$i] = round($parts[$i] + $ajout, 2);
            $reste = round($reste - $ajout, 2);
        }

        return $parts;
    }

    private function numero(int $tenantId): string
    {
        $config = Configuration::pourEntite('encaissement', $tenantId);
        if (! $config?->auto_increment) {
            throw new DomainException('Numéro requis : activez la numérotation automatique des encaissements (Configuration → Numérotation).');
        }

        return Configuration::consommerNumero('encaissement', $tenantId);
    }

    private function texte(mixed $valeur): ?string
    {
        $texte = trim((string) $valeur);

        return $texte === '' ? null : $texte;
    }

    private function montant(mixed $valeur): string
    {
        return number_format((float) $valeur, 2, ',', ' ');
    }
}
