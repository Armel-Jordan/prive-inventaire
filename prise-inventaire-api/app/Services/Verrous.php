<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\ComFourEntete;
use App\Models\Devis;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Verrou;
use App\Support\Droits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Verrous des fiches en cours de modification (plan de parité fripv2, phase 6.3) : le compte qui ouvre une fiche en
 * modification la verrouille (l'écran renouvelle le verrou chaque minute) ; le verrou tombe s'il n'est plus renouvelé.
 * Avec le droit de libérer, on prend la main sur la fiche d'un autre compte, ou on la libère depuis la liste des fiches
 * en cours de modification. L'écran qui renouvelle un verrou perdu entre-temps l'apprend (et ne le reprend pas).
 */
class Verrous
{
    /** Fiches verrouillables => [module dont il faut le droit de modifier, modèle de la fiche]. */
    public const ENTITES = [
        'client' => ['clients', Client::class],
        'fournisseur' => ['fournisseurs', Fournisseur::class],
        'produit' => ['produits', ProduitTenant::class],
        'devis' => ['devis', Devis::class],
        'commande_client' => ['commandes_client', ComClientEntete::class],
        'commande_fournisseur' => ['commandes_fournisseur', ComFourEntete::class],
    ];

    /** Durée d'un verrou qui n'est plus renouvelé (fenêtre fermée sans le lever, navigateur coupé…). */
    public const DUREE_SECONDES = 180;

    public function __construct(private readonly Droits $droits) {}

    public static function module(string $entite): string
    {
        return self::ENTITES[$entite][0];
    }

    /** La fiche existe-t-elle dans l'entreprise courante ? */
    public function existe(string $entite, int $id): bool
    {
        return self::ENTITES[$entite][1]::query()->whereKey($id)->exists();
    }

    /** Le verrou d'un autre compte sur la fiche, s'il tient encore. */
    public function autre(string $entite, int $id, AdminUser $compte): ?Verrou
    {
        $verrou = Verrou::with('compte:id,nom')->where('entite', $entite)->where('entite_id', $id)->first();

        return $verrou && $verrou->actif() && $verrou->admin_user_id !== (int) $compte->id ? $verrou : null;
    }

    /**
     * Pose ou renouvelle le verrou du compte. Si un autre compte tient la fiche, son verrou est rendu (sauf à prendre la
     * main). Au renouvellement, un verrou perdu entre-temps (fiche libérée par un responsable, ou modifiée par un autre
     * compte) n'est pas repris : il est rendu, l'écran repart de la fiche enregistrée. « depuis » marque le début de la
     * modification par le compte qui tient le verrou.
     *
     * @return array{0: ?Verrou, 1: ?Verrou} le verrou du compte, ou celui qui l'empêche
     */
    public function poser(string $entite, int $id, AdminUser $compte, bool $prendreLaMain = false, bool $renouveler = false): array
    {
        try {
            return $this->tenter($entite, $id, $compte, $prendreLaMain, $renouveler);
        } catch (UniqueConstraintViolationException) {
            // Deux écrans ont posé le premier verrou de la fiche en même temps : le second relit celui du premier.
            return $this->tenter($entite, $id, $compte, $prendreLaMain, $renouveler);
        }
    }

    /** @return array{0: ?Verrou, 1: ?Verrou} */
    private function tenter(string $entite, int $id, AdminUser $compte, bool $prendreLaMain, bool $renouveler): array
    {
        return DB::transaction(function () use ($entite, $id, $compte, $prendreLaMain, $renouveler) {
            $verrou = Verrou::query()->where('entite', $entite)->where('entite_id', $id)->lockForUpdate()->first();
            $sien = $verrou && $verrou->admin_user_id === (int) $compte->id;
            $aUnAutre = $verrou && $verrou->actif() && ! $sien;
            $perdu = $renouveler && $verrou && (! $sien || $verrou->libere_le !== null);
            if ($aUnAutre ? ! $prendreLaMain : $perdu) {
                return [null, $verrou->load('compte:id,nom', 'liberePar:id,nom')];
            }
            // Le sien, expiré pendant une absence de l'écran puis renouvelé : la même modification continue.
            $nouveau = ! $sien || (! $verrou->actif() && ! $renouveler);
            $verrou ??= new Verrou(['entite' => $entite, 'entite_id' => $id]);
            $verrou->fill([
                'admin_user_id' => $compte->id, 'expire_le' => now()->addSeconds(self::DUREE_SECONDES),
                'libere_par_id' => null, 'libere_le' => null,
            ]);
            if ($nouveau) {
                $verrou->created_at = now();
            }
            $verrou->save();

            return [$verrou->load('compte:id,nom'), null];
        }, 3);
    }

    /**
     * Lève le verrou du compte (fenêtre de modification fermée). La ligne reste, expirée, pour qu'un autre écran du
     * compte qui la renouvellerait sache qu'il n'a rien perdu, et qu'un autre compte devancé l'apprenne ; les verrous
     * levés depuis plus d'un jour sont effacés.
     */
    public function lever(string $entite, int $id, AdminUser $compte): void
    {
        Verrou::query()->where('entite', $entite)->where('entite_id', $id)->where('admin_user_id', $compte->id)
            ->where('expire_le', '>', now())->update(['expire_le' => now()]);
        Verrou::query()->where('expire_le', '<', now()->subDay())->delete();
    }

    /** Libère la fiche d'un autre compte (depuis la liste des fiches en cours de modification). */
    public function liberer(Verrou $verrou, AdminUser $par): void
    {
        $verrou->forceFill(['expire_le' => now(), 'libere_par_id' => $par->id, 'libere_le' => now()])->save();
    }

    /**
     * Fiches en cours de modification dans l'entreprise, les plus anciennes d'abord : celles des modules que le compte
     * peut consulter, avec leur libellé et le lien de leur écran.
     */
    public function enCours(AdminUser $compte): array
    {
        $verrous = Verrou::with('compte:id,nom')->whereNull('libere_le')->where('expire_le', '>', now())
            ->orderBy('created_at')->orderBy('id')->get()
            ->filter(fn (Verrou $v) => isset(self::ENTITES[$v->entite]) && $this->droits->permet($compte, self::module($v->entite), 'voir'));
        $fiches = $verrous->groupBy('entite')->map(fn ($groupe, $entite) => self::ENTITES[$entite][1]::query()
            ->whereKey($groupe->pluck('entite_id')->all())->get()->keyBy('id'));

        return $verrous->map(function (Verrou $verrou) use ($fiches) {
            $fiche = $fiches[$verrou->entite][$verrou->entite_id] ?? null;

            return $fiche ? ['id' => $verrou->id] + self::presenter($verrou) + self::decrire($verrou->entite, $fiche) : null;
        })->filter()->values()->all();
    }

    /** Libellé et lien de l'écran d'une fiche. */
    private static function decrire(string $entite, Model $fiche): array
    {
        $recherche = fn (string $page, ?string $valeur) => $page.'?recherche='.rawurlencode((string) $valeur);
        [$libelle, $lien] = match ($entite) {
            'client' => [$fiche->raison_sociale, "/clients/{$fiche->id}"],
            'fournisseur' => [$fiche->raison_sociale, "/fournisseurs/{$fiche->id}"],
            'produit' => [trim("{$fiche->numero} — {$fiche->description}", ' —'), $recherche('/produits', $fiche->numero)],
            'devis' => [$fiche->numero, $recherche('/devis', $fiche->numero)],
            'commande_client' => [$fiche->numero, $recherche('/commandes-client', $fiche->numero)],
            'commande_fournisseur' => [$fiche->numero, $recherche('/commandes-fournisseur', $fiche->numero)],
        };

        return ['libelle' => $libelle, 'lien' => $lien];
    }

    /** Message d'une fiche qu'un verrou empêche de modifier (celui d'un autre compte, ou le sien perdu). */
    public static function message(Verrou $verrou): string
    {
        $nom = $verrou->compte?->nom ?? __('un autre compte');

        return match (true) {
            $verrou->actif() => __('Cette fiche est en cours de modification par :nom : réessayez quand la modification sera terminée.', ['nom' => $nom]),
            $verrou->libere_le !== null => __('Votre modification a été interrompue : :nom a libéré la fiche. Rouvrez-la pour repartir de sa version enregistrée.', [
                'nom' => $verrou->liberePar?->nom ?? __('un responsable'),
            ]),
            default => __('Votre modification a été interrompue : :nom a modifié la fiche entre-temps. Rouvrez-la pour repartir de sa version enregistrée.', ['nom' => $nom]),
        };
    }

    public static function presenter(Verrou $verrou): array
    {
        return [
            'entite' => $verrou->entite,
            'entite_id' => $verrou->entite_id,
            'actif' => $verrou->actif(),
            'par' => $verrou->compte?->nom,
            'par_id' => $verrou->admin_user_id,
            'depuis' => $verrou->created_at?->toIso8601String(),
            'expire_le' => $verrou->expire_le?->toIso8601String(),
            'libere_par' => $verrou->libere_le ? $verrou->liberePar?->nom : null,
        ];
    }
}
