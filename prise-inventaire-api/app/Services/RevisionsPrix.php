<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\ListePrix;
use App\Models\ListePrixProduit;
use App\Models\ProduitTenant;
use App\Models\RevisionPrix;
use App\Models\RevisionPrixLigne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Révisions de prix planifiées (plan de parité fripv2, phase 1.2d).
 *
 * - Ajustement : sur les produits d'une catégorie (ou tous), le prix de base, les prix saisis d'une liste, ou les deux
 *   sur toutes les listes actives, augmentés ou baissés d'un pourcentage ou d'un montant ; arrondis à la précision de la
 *   liste (2 décimales pour le prix de base) ; jamais sous zéro. Le prix d'une liste calculé par sa formule (sans prix
 *   saisi) suit le prix de base : ce n'est pas une ligne de la révision, il change avec le prix de base.
 * - Application : chaque ligne écrit son nouveau prix et note le prix qu'elle remplace ; une révision ne s'applique
 *   qu'une fois, tout de suite ou à sa date d'entrée en vigueur (tâche planifiée « revisions_prix »).
 */
class RevisionsPrix
{
    /** Prix au plus dans une révision. */
    public const LIGNES_MAX = 5000;

    /** Décimales du prix de base (une liste a sa précision). */
    public const PRECISION_BASE = 2;

    /**
     * Nouveaux prix d'un ajustement, à partir des prix en place, dans l'ordre des produits puis des listes.
     *
     * @param  array{categorie?: ?string, cible: string, liste_prix_id?: ?int, type: string, valeur: float|int|string, inclure_inactifs?: bool}  $reglage
     * @return list<array{produit_id: int, liste_prix_id: ?int, nouveau_prix: float}>
     */
    public function ajuster(array $reglage): array
    {
        $produits = ProduitTenant::query()
            ->when($reglage['categorie'] ?? null, fn ($q, $categorie) => $q->where('categorie', $categorie))
            ->when(! ($reglage['inclure_inactifs'] ?? false), fn ($q) => $q->where('actif', true))
            ->orderBy('numero')
            ->get(['id', 'prix_unitaire']);
        $listes = match ($reglage['cible']) {
            'base' => collect(),
            'liste' => ListePrix::whereKey($reglage['liste_prix_id'] ?? 0)->get(),
            default => ListePrix::where('actif', true)->orderBy('nom')->get(),
        };
        $saisis = $this->prixSaisis($listes->pluck('id'));
        $valeur = (float) $reglage['valeur'];
        $ajuste = fn (float $prix, int $precision) => max(0.0, round(
            $reglage['type'] === 'pourcentage' ? $prix * (1 + $valeur / 100) : $prix + $valeur,
            $precision,
        ));

        $lignes = [];
        foreach ($produits as $produit) {
            if ($reglage['cible'] !== 'liste' && $produit->prix_unitaire !== null) {
                $lignes[] = ['produit_id' => $produit->id, 'liste_prix_id' => null, 'nouveau_prix' => $ajuste((float) $produit->prix_unitaire, self::PRECISION_BASE)];
            }
            foreach ($listes as $liste) {
                $prix = $saisis["{$produit->id}:{$liste->id}"] ?? null;
                if ($prix !== null) {
                    $lignes[] = ['produit_id' => $produit->id, 'liste_prix_id' => $liste->id, 'nouveau_prix' => $ajuste($prix, $liste->precision)];
                }
            }
        }

        if (count($lignes) > self::LIGNES_MAX) {
            throw ValidationException::withMessages(['valeur' => __('Cet ajustement touche :nombre prix ; une révision en compte :max au plus : limitez-le à une catégorie ou à une liste.', [
                'nombre' => count($lignes), 'max' => self::LIGNES_MAX,
            ])]);
        }

        return $lignes;
    }

    /**
     * Lignes présentées : produit, liste, prix actuel (en place, ou celui que la révision a remplacé une fois appliquée),
     * nouveau prix et, pour un prix de base, les listes actives dont le prix du produit suit la formule (il changera
     * avec le prix de base).
     *
     * @param  iterable<array{produit_id: int, liste_prix_id: ?int, nouveau_prix: float, id?: int, prix_remplace?: ?float}|RevisionPrixLigne>  $lignes
     */
    public function presenter(iterable $lignes, bool $appliquee = false): array
    {
        $lignes = collect($lignes)->map(fn ($l) => $l instanceof RevisionPrixLigne ? $l->only(['id', 'produit_id', 'liste_prix_id', 'nouveau_prix', 'prix_remplace']) : $l);
        $produitIds = $lignes->pluck('produit_id')->unique()->values();
        $produits = $produitIds->chunk(1000)->flatMap(fn (Collection $ids) => ProduitTenant::withTrashed()->whereIn('id', $ids->all())
            ->get(['id', 'numero', 'description', 'categorie', 'prix_unitaire', 'actif', 'deleted_at']))->keyBy('id');
        $actives = ListePrix::where('actif', true)->orderBy('nom')->pluck('id')->map(fn ($id) => (int) $id);
        $noms = ListePrix::whereIn('id', $lignes->pluck('liste_prix_id')->filter()->unique()->all())->pluck('nom', 'id');
        $saisis = $appliquee ? [] : $this->prixSaisis(null, $produitIds);
        $presentes = $lignes->mapWithKeys(fn (array $l) => ["{$l['produit_id']}:".($l['liste_prix_id'] ?? 'base') => true]);

        return $lignes->map(function (array $l) use ($produits, $actives, $noms, $saisis, $presentes, $appliquee) {
            $produit = $produits->get($l['produit_id']);
            $liste = $l['liste_prix_id'] ?? null;
            $actuel = match (true) {
                $appliquee => $l['prix_remplace'] ?? null,
                $liste === null => $produit?->prix_unitaire !== null ? (float) $produit->prix_unitaire : null,
                default => $saisis["{$l['produit_id']}:{$liste}"] ?? null,
            };

            return [
                'id' => $l['id'] ?? null,
                'produit_id' => (int) $l['produit_id'],
                'numero' => $produit?->numero,
                'description' => $produit?->description,
                'categorie' => $produit?->categorie,
                'produit_actif' => (bool) ($produit?->actif && $produit->deleted_at === null),
                'liste_prix_id' => $liste,
                'liste' => $liste === null ? null : $noms->get($liste),
                'prix_actuel' => $actuel,
                'nouveau_prix' => (float) $l['nouveau_prix'],
                'formules' => $liste !== null || $appliquee ? [] : $actives
                    ->reject(fn (int $id) => isset($saisis["{$l['produit_id']}:{$id}"]) || isset($presentes["{$l['produit_id']}:{$id}"]))
                    ->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * Crée ou modifie une révision en cours (brouillon ou planifiée) et remplace ses lignes. Une révision planifiée
     * garde sa date, requise.
     *
     * @param  array{nom: string, notes?: ?string, date_effet?: ?string, categorie?: ?string, cible?: ?string, liste_prix_id?: ?int, type_ajustement?: ?string, valeur_ajustement?: float|int|string|null, lignes: list<array{produit_id: int, liste_prix_id?: ?int, nouveau_prix: float|int|string}>}  $donnees
     */
    public function enregistrer(?RevisionPrix $revision, array $donnees, AdminUser $compte): RevisionPrix
    {
        if ($revision && ! $revision->enCours()) {
            throw ValidationException::withMessages(['statut' => __('Une révision appliquée ou annulée ne se modifie plus.')]);
        }
        if ($revision?->statut === RevisionPrix::PLANIFIEE && empty($donnees['date_effet'])) {
            throw ValidationException::withMessages(['date_effet' => __("Une révision planifiée a besoin de sa date d'entrée en vigueur.")]);
        }
        $lignes = $this->verifierLignes($donnees['lignes']);

        return DB::transaction(function () use ($revision, $donnees, $lignes, $compte) {
            $entete = [
                'nom' => trim($donnees['nom']),
                'notes' => filled($donnees['notes'] ?? null) ? trim($donnees['notes']) : null,
                'date_effet' => $donnees['date_effet'] ?? null,
                'categorie' => filled($donnees['categorie'] ?? null) ? $donnees['categorie'] : null,
                'cible' => $donnees['cible'] ?? null,
                'liste_prix_id' => ($donnees['cible'] ?? null) === 'liste' ? ($donnees['liste_prix_id'] ?? null) : null,
                'type_ajustement' => $donnees['type_ajustement'] ?? null,
                'valeur_ajustement' => $donnees['valeur_ajustement'] ?? null,
                'modifie_par' => $compte->id,
            ];
            if ($revision) {
                $revision = RevisionPrix::whereKey($revision->id)->lockForUpdate()->firstOrFail();
                if (! $revision->enCours()) {
                    throw ValidationException::withMessages(['statut' => __('Une révision appliquée ou annulée ne se modifie plus.')]);
                }
                $revision->update($entete);
                $revision->lignes()->delete();
            } else {
                $revision = RevisionPrix::create($entete + ['statut' => RevisionPrix::BROUILLON, 'cree_par' => $compte->id]);
            }

            $maintenant = now();
            foreach (array_chunk($lignes, 500) as $paquet) {
                RevisionPrixLigne::insert(array_map(fn (array $l) => [
                    'tenant_id' => $revision->tenant_id,
                    'revision_prix_id' => $revision->id,
                    'produit_id' => $l['produit_id'],
                    'liste_prix_id' => $l['liste_prix_id'],
                    'nouveau_prix' => $l['nouveau_prix'],
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ], $paquet));
            }

            return $revision->refresh();
        });
    }

    /** Fixe la date d'entrée en vigueur : la tâche planifiée appliquera la révision ce jour-là. */
    public function planifier(RevisionPrix $revision, string $date, AdminUser $compte): RevisionPrix
    {
        return DB::transaction(function () use ($revision, $date, $compte) {
            $revision = $this->verrouiller($revision);
            if (! $revision->lignes()->exists()) {
                throw ValidationException::withMessages(['lignes' => __("Cette révision n'a aucun prix à appliquer.")]);
            }
            $revision->update([
                'statut' => RevisionPrix::PLANIFIEE, 'date_effet' => $date, 'planifiee_par' => $compte->id, 'planifiee_le' => now(),
            ]);

            return $revision;
        });
    }

    /**
     * Applique les nouveaux prix (une seule fois) : prix de base du produit, ou prix saisi dans la liste (créé s'il
     * n'y en avait plus). Sans compte : par la tâche planifiée. Retourne le nombre de prix écrits.
     */
    public function appliquer(RevisionPrix $revision, ?AdminUser $compte): int
    {
        return DB::transaction(function () use ($revision, $compte) {
            $revision = $this->verrouiller($revision);
            $lignes = $revision->lignes()->orderBy('id')->get();
            if ($lignes->isEmpty()) {
                throw ValidationException::withMessages(['lignes' => __("Cette révision n'a aucun prix à appliquer.")]);
            }

            $produitIds = $lignes->pluck('produit_id')->unique()->values();
            $bases = [];
            foreach ($produitIds->chunk(1000) as $ids) {
                $bases += ProduitTenant::withTrashed()->whereIn('id', $ids->all())->pluck('prix_unitaire', 'id')->all();
            }
            $saisis = $this->prixSaisis($lignes->pluck('liste_prix_id')->filter()->unique()->values(), $produitIds);

            foreach ($lignes as $ligne) {
                if ($ligne->liste_prix_id === null) {
                    $avant = $bases[$ligne->produit_id] ?? null;
                    ProduitTenant::withTrashed()->whereKey($ligne->produit_id)->update(['prix_unitaire' => $ligne->nouveau_prix]);
                } else {
                    $avant = $saisis["{$ligne->produit_id}:{$ligne->liste_prix_id}"] ?? null;
                    ListePrixProduit::updateOrCreate(
                        ['liste_prix_id' => $ligne->liste_prix_id, 'produit_id' => $ligne->produit_id],
                        ['prix' => $ligne->nouveau_prix],
                    );
                }
                if ($avant !== null) {
                    $ligne->update(['prix_remplace' => $avant]);
                }
            }

            $revision->update(['statut' => RevisionPrix::APPLIQUEE, 'appliquee_le' => now(), 'appliquee_par' => $compte?->id]);

            return $lignes->count();
        });
    }

    public function annuler(RevisionPrix $revision, AdminUser $compte): RevisionPrix
    {
        return DB::transaction(function () use ($revision, $compte) {
            $revision = $this->verrouiller($revision);
            $revision->update(['statut' => RevisionPrix::ANNULEE, 'annulee_le' => now(), 'annulee_par' => $compte->id]);

            return $revision;
        });
    }

    /** Une révision appliquée reste : c'est l'historique des prix. */
    public function supprimer(RevisionPrix $revision): void
    {
        DB::transaction(function () use ($revision) {
            $revision = RevisionPrix::whereKey($revision->id)->lockForUpdate()->firstOrFail();
            if ($revision->statut === RevisionPrix::APPLIQUEE) {
                throw ValidationException::withMessages(['statut' => __("Une révision appliquée ne se supprime pas : elle garde l'historique des prix.")]);
            }
            $revision->delete();
        });
    }

    /** Relit la révision verrouillée ; refuse si elle n'est plus en cours (déjà appliquée, ou annulée). */
    private function verrouiller(RevisionPrix $revision): RevisionPrix
    {
        $revision = RevisionPrix::whereKey($revision->id)->lockForUpdate()->firstOrFail();
        if ($revision->statut === RevisionPrix::APPLIQUEE) {
            throw ValidationException::withMessages(['statut' => __('Cette révision a déjà été appliquée.')]);
        }
        if ($revision->statut === RevisionPrix::ANNULEE) {
            throw ValidationException::withMessages(['statut' => __('Cette révision est annulée.')]);
        }

        return $revision;
    }

    /**
     * Lignes reçues : produits et listes de l'entreprise, un même prix une seule fois.
     *
     * @param  list<array{produit_id: int|string, liste_prix_id?: int|string|null, nouveau_prix: float|int|string}>  $lignes
     * @return list<array{produit_id: int, liste_prix_id: ?int, nouveau_prix: float}>
     */
    private function verifierLignes(array $lignes): array
    {
        $produitIds = collect($lignes)->pluck('produit_id')->map(fn ($id) => (int) $id)->unique()->values();
        $produits = $produitIds->chunk(1000)->flatMap(fn (Collection $ids) => ProduitTenant::withTrashed()->whereIn('id', $ids->all())->pluck('id'))
            ->flip();
        $listes = ListePrix::whereIn('id', collect($lignes)->pluck('liste_prix_id')->filter()->unique()->all())->pluck('id')->flip();

        $vues = [];
        $erreurs = [];
        $propres = [];
        foreach (array_values($lignes) as $i => $ligne) {
            $produit = (int) $ligne['produit_id'];
            $liste = isset($ligne['liste_prix_id']) && $ligne['liste_prix_id'] !== '' ? (int) $ligne['liste_prix_id'] : null;
            if (! $produits->has($produit)) {
                $erreurs["lignes.{$i}.produit_id"] = __('Produit introuvable.');
            } elseif ($liste !== null && ! $listes->has($liste)) {
                $erreurs["lignes.{$i}.liste_prix_id"] = __('Liste de prix introuvable.');
            } elseif (isset($vues["{$produit}:".($liste ?? 'base')])) {
                $erreurs["lignes.{$i}"] = __('Ce prix figure deux fois dans la révision.');
            }
            $vues["{$produit}:".($liste ?? 'base')] = true;
            $propres[] = ['produit_id' => $produit, 'liste_prix_id' => $liste, 'nouveau_prix' => (float) $ligne['nouveau_prix']];
        }
        if ($erreurs) {
            throw ValidationException::withMessages(array_slice($erreurs, 0, 20, true));
        }

        return $propres;
    }

    /**
     * Prix saisis dans des listes (toutes si null), pour des produits (tous si null) : « produit:liste » => prix.
     *
     * @return array<string, float>
     */
    private function prixSaisis(?Collection $listeIds, ?Collection $produitIds = null): array
    {
        if ($listeIds !== null && $listeIds->isEmpty()) {
            return [];
        }
        $requete = fn () => ListePrixProduit::query()->when($listeIds !== null, fn ($q) => $q->whereIn('liste_prix_id', $listeIds->all()));
        $lignes = $produitIds === null
            ? $requete()->get(['liste_prix_id', 'produit_id', 'prix'])
            : $produitIds->chunk(1000)->flatMap(fn (Collection $ids) => $requete()->whereIn('produit_id', $ids->all())->get(['liste_prix_id', 'produit_id', 'prix']));

        return collect($lignes)->mapWithKeys(fn (ListePrixProduit $p) => ["{$p->produit_id}:{$p->liste_prix_id}" => (float) $p->prix])->all();
    }
}
