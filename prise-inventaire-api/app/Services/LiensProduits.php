<?php

namespace App\Services;

use App\Models\ProduitLien;
use App\Models\ProduitTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Liens entre produits (plan de parité fripv2, phase 2.3) :
 * - ensemble (kit) : un produit composé d'autres produits, chacun en une quantité ; il se vend en un article et
 *   consomme ses composants (sa vente réserve ses composants ; un ensemble peut en contenir un autre, sans boucle) ;
 * - annexe : produit ajouté d'office sur les lignes de vente du produit, en sa quantité par unité vendue ;
 * - équivalent : substitut possible en cas de manque, dans les deux sens, avec une remarque.
 */
class LiensProduits
{
    /** Profondeur maximale des ensembles imbriqués. */
    private const PROFONDEUR = 10;

    /** @var array<int, array<int, float>>|null ensemble => [composant => quantité] */
    private ?array $composition = null;

    /**
     * Les liens d'un produit, par type, avec le produit lié, et les ensembles qui le contiennent.
     *
     * @return array{composants: list<array>, annexes: list<array>, equivalents: list<array>, ensembles: list<array>}
     */
    public function liste(ProduitTenant $produit): array
    {
        $liens = ProduitLien::query()->where('produit_id', $produit->id)->with('lie:id,numero,description,mesure,actif,prix_unitaire')->orderBy('id')->get();
        $presenter = fn (ProduitLien $l) => [
            'id' => $l->id, 'type' => $l->type, 'lie_id' => $l->lie_id, 'quantite' => $l->quantite, 'remarque' => $l->remarque,
            'produit' => $l->lie?->only(['id', 'numero', 'description', 'mesure', 'actif', 'prix_unitaire']),
        ];
        $ensembles = ProduitLien::query()->where('type', ProduitLien::COMPOSANT)->where('lie_id', $produit->id)
            ->with('produit:id,numero,description')->get()
            ->map(fn (ProduitLien $l) => ['id' => $l->produit_id, 'numero' => $l->produit?->numero, 'description' => $l->produit?->description, 'quantite' => $l->quantite]);

        return [
            'composants' => $liens->where('type', ProduitLien::COMPOSANT)->map($presenter)->values()->all(),
            'annexes' => $liens->where('type', ProduitLien::ANNEXE)->map($presenter)->values()->all(),
            'equivalents' => $liens->where('type', ProduitLien::EQUIVALENT)->map($presenter)->values()->all(),
            'ensembles' => $ensembles->values()->all(),
        ];
    }

    /** Lie un produit à un autre (composant, annexe ou équivalent). */
    public function ajouter(ProduitTenant $produit, array $donnees): ProduitLien
    {
        $type = $donnees['type'];
        $lie = ProduitTenant::query()->find($donnees['lie_id']);
        if (! $lie) {
            throw ValidationException::withMessages(['lie_id' => __('Produit introuvable.')]);
        }
        if ($lie->id === $produit->id) {
            throw ValidationException::withMessages(['lie_id' => __('Un produit ne se lie pas à lui-même.')]);
        }
        if (! $lie->actif) {
            throw ValidationException::withMessages(['lie_id' => __(":numero est désactivé : réactivez-le d'abord.", ['numero' => $lie->numero])]);
        }
        if (ProduitLien::query()->where('type', $type)->where('produit_id', $produit->id)->where('lie_id', $lie->id)->exists()) {
            throw ValidationException::withMessages(['lie_id' => __(':numero est déjà lié à ce produit.', ['numero' => $lie->numero])]);
        }
        if ($type === ProduitLien::COMPOSANT && $this->contient($lie->id, $produit->id)) {
            throw ValidationException::withMessages(['lie_id' => __(":numero contient déjà :produit : l'ensemble tournerait en rond.", ['numero' => $lie->numero, 'produit' => $produit->numero])]);
        }

        return DB::transaction(function () use ($produit, $lie, $type, $donnees) {
            $lien = ProduitLien::create([
                'type' => $type, 'produit_id' => $produit->id, 'lie_id' => $lie->id,
                'quantite' => $type === ProduitLien::EQUIVALENT ? 1 : round((float) ($donnees['quantite'] ?? 1), 3),
                'remarque' => $this->remarque($donnees),
            ]);
            // Un équivalent l'est dans les deux sens.
            if ($type === ProduitLien::EQUIVALENT) {
                ProduitLien::query()->firstOrCreate(
                    ['type' => ProduitLien::EQUIVALENT, 'produit_id' => $lie->id, 'lie_id' => $produit->id],
                    ['quantite' => 1, 'remarque' => $lien->remarque],
                );
            }
            $this->composition = null;

            return $lien->load('lie:id,numero,description,mesure,actif,prix_unitaire');
        });
    }

    /** Quantité d'un composant ou d'une annexe, remarque d'un équivalent (des deux côtés). */
    public function modifier(ProduitLien $lien, array $donnees): ProduitLien
    {
        DB::transaction(function () use ($lien, $donnees) {
            if (array_key_exists('quantite', $donnees) && $lien->type !== ProduitLien::EQUIVALENT) {
                $lien->quantite = round((float) $donnees['quantite'], 3);
            }
            if (array_key_exists('remarque', $donnees)) {
                $lien->remarque = $this->remarque($donnees);
                if ($lien->type === ProduitLien::EQUIVALENT) {
                    $this->inverse($lien)?->update(['remarque' => $lien->remarque]);
                }
            }
            $lien->save();
            $this->composition = null;
        });

        return $lien->load('lie:id,numero,description,mesure,actif,prix_unitaire');
    }

    /** Retire un lien (un équivalent, des deux côtés). */
    public function retirer(ProduitLien $lien): void
    {
        DB::transaction(function () use ($lien) {
            if ($lien->type === ProduitLien::EQUIVALENT) {
                $this->inverse($lien)?->delete();
            }
            $lien->delete();
            $this->composition = null;
        });
    }

    /**
     * Composants élémentaires de chaque ensemble (les ensembles imbriqués dépliés) : quantité de chaque composant pour
     * un ensemble. Un produit sans composants n'y figure pas.
     *
     * @param  list<int>  $ensembles
     * @return array<int, array<int, float>> ensemble => [composant => quantité]
     */
    public function compositions(array $ensembles): array
    {
        $directe = $this->composition();
        $resultat = [];
        foreach ($ensembles as $ensemble) {
            if (! isset($directe[$ensemble])) {
                continue;
            }
            $feuilles = [];
            $this->deplier($directe, $ensemble, 1.0, $feuilles, 0);
            $resultat[$ensemble] = $feuilles;
        }

        return $resultat;
    }

    /**
     * Ensembles (à tous niveaux) qui contiennent l'un de ces produits.
     *
     * @param  list<int>  $produits
     * @return list<int>
     */
    public function ensemblesContenant(array $produits): array
    {
        $parents = [];
        foreach ($this->composition() as $ensemble => $composants) {
            foreach (array_keys($composants) as $composant) {
                $parents[$composant][] = $ensemble;
            }
        }
        $trouves = [];
        $file = $produits;
        while ($file !== []) {
            $produit = array_shift($file);
            foreach ($parents[$produit] ?? [] as $ensemble) {
                if (! isset($trouves[$ensemble])) {
                    $trouves[$ensemble] = true;
                    $file[] = $ensemble;
                }
            }
        }

        return array_keys($trouves);
    }

    /** Le produit $contenant contient-il $contenu, directement ou dans un sous-ensemble (ou est-ce lui) ? */
    private function contient(int $contenant, int $contenu): bool
    {
        if ($contenant === $contenu) {
            return true;
        }
        $directe = $this->composition();
        $vus = [];
        $file = [$contenant];
        while ($file !== []) {
            $produit = array_shift($file);
            foreach (array_keys($directe[$produit] ?? []) as $composant) {
                if ($composant === $contenu) {
                    return true;
                }
                if (! isset($vus[$composant])) {
                    $vus[$composant] = true;
                    $file[] = $composant;
                }
            }
        }

        return false;
    }

    /** @return array<int, array<int, float>> ensemble => [composant => quantité], liens directs */
    private function composition(): array
    {
        if ($this->composition === null) {
            $this->composition = [];
            foreach (ProduitLien::query()->where('type', ProduitLien::COMPOSANT)->get(['produit_id', 'lie_id', 'quantite']) as $lien) {
                $this->composition[$lien->produit_id][$lien->lie_id] = ($this->composition[$lien->produit_id][$lien->lie_id] ?? 0) + (float) $lien->quantite;
            }
        }

        return $this->composition;
    }

    private function deplier(array $directe, int $ensemble, float $facteur, array &$feuilles, int $profondeur): void
    {
        foreach ($directe[$ensemble] ?? [] as $composant => $quantite) {
            if (isset($directe[$composant]) && $profondeur < self::PROFONDEUR) {
                $this->deplier($directe, $composant, $facteur * $quantite, $feuilles, $profondeur + 1);
            } else {
                $feuilles[$composant] = ($feuilles[$composant] ?? 0) + $facteur * $quantite;
            }
        }
    }

    private function inverse(ProduitLien $lien): ?ProduitLien
    {
        return ProduitLien::query()->where('type', $lien->type)->where('produit_id', $lien->lie_id)->where('lie_id', $lien->produit_id)->first();
    }

    private function remarque(array $donnees): ?string
    {
        return trim((string) ($donnees['remarque'] ?? '')) ?: null;
    }
}
