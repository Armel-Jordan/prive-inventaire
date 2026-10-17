<?php

namespace App\Services;

use App\Models\Entrepot;
use App\Models\ProduitTenant;
use App\Models\ScanTenant;
use App\Models\Secteur;

/**
 * Stock par entrepôt (plan de parité fripv2, phase 2.5). PI compte son stock par les scans : la somme des scans d'un
 * produit, comme les disponibilités et les alertes de stock. Chaque scan va à l'entrepôt de son secteur, reconnu par son
 * code ou son nom (le web enregistre le code, Android le nom) ; un secteur sans entrepôt, ou inconnu, reste « sans
 * entrepôt ». Rien n'est enregistré : c'est un état.
 */
class StocksEntrepots
{
    /**
     * @return array{
     *     entrepots: list<array{id: int, code: string, nom: string, actif: bool}>,
     *     produits: list<array{produit_id: ?int, numero: string, description: ?string, mesure: ?string, total: float, entrepots: array<int, float>, sans_entrepot: float}>,
     *     totaux: array{entrepots: array<int, float>, sans_entrepot: float, total: float}
     * }
     */
    public function stock(?string $recherche = null, ?int $entrepotId = null): array
    {
        $index = $this->entrepotsParAppellation();
        $motif = filled($recherche) ? '%'.addcslashes(trim((string) $recherche), '%_\\').'%' : null;
        // Recherche : le numéro ou la description du produit.
        $numeros = $motif ? ProduitTenant::withTrashed()->where(fn ($q) => $q->where('numero', 'like', $motif)->orWhere('description', 'like', $motif))->pluck('numero') : null;

        $scans = ScanTenant::query()
            ->when($motif, fn ($q) => $q->where(fn ($r) => $r->whereIn('numero', $numeros)->orWhere('numero', 'like', $motif)))
            ->groupBy('numero', 'secteur')->selectRaw('numero, secteur, SUM(quantite) AS quantite')->get();

        $parProduit = [];
        foreach ($scans as $scan) {
            $entrepot = $index[mb_strtolower((string) $scan->secteur)] ?? null;
            $quantite = (float) $scan->quantite;
            $ligne = $parProduit[$scan->numero] ?? ['entrepots' => [], 'sans_entrepot' => 0.0, 'total' => 0.0];
            if ($entrepot === null) {
                $ligne['sans_entrepot'] += $quantite;
            } else {
                $ligne['entrepots'][$entrepot] = ($ligne['entrepots'][$entrepot] ?? 0.0) + $quantite;
            }
            $ligne['total'] += $quantite;
            $parProduit[$scan->numero] = $ligne;
        }

        $fiches = ProduitTenant::withTrashed()->whereIn('numero', array_map('strval', array_keys($parProduit)))
            ->get(['id', 'numero', 'description', 'mesure'])->keyBy('numero');
        $produits = collect($parProduit)
            ->map(fn (array $l, string|int $numero) => [
                'produit_id' => $fiches[(string) $numero]->id ?? null,
                'numero' => (string) $numero,
                'description' => $fiches[(string) $numero]->description ?? null,
                'mesure' => $fiches[(string) $numero]->mesure ?? null,
                'total' => round($l['total'], 4),
                'entrepots' => array_filter(array_map(fn (float $q) => round($q, 4), $l['entrepots']), fn (float $q) => $q != 0),
                'sans_entrepot' => round($l['sans_entrepot'], 4),
            ])
            // Produits qui ont du stock (ou un solde négatif) ; avec un entrepôt choisi, ceux qui en ont dans cet entrepôt.
            ->filter(fn (array $p) => $entrepotId ? isset($p['entrepots'][$entrepotId]) : ($p['total'] != 0 || $p['entrepots'] || $p['sans_entrepot'] != 0))
            ->sortBy('numero', SORT_NATURAL)->values();

        $totaux = ['entrepots' => [], 'sans_entrepot' => 0.0, 'total' => 0.0];
        foreach ($produits as $produit) {
            foreach ($produit['entrepots'] as $id => $quantite) {
                $totaux['entrepots'][$id] = round(($totaux['entrepots'][$id] ?? 0.0) + $quantite, 4);
            }
            $totaux['sans_entrepot'] = round($totaux['sans_entrepot'] + $produit['sans_entrepot'], 4);
            $totaux['total'] = round($totaux['total'] + $produit['total'], 4);
        }

        // Colonnes : les entrepôts actifs, et ceux (désactivés, retirés) qui gardent du stock.
        $colonnes = Entrepot::withTrashed()->where(fn ($q) => $q->where(fn ($a) => $a->where('actif', true)->whereNull('deleted_at'))
            ->orWhereIn('id', array_keys($totaux['entrepots'])))
            ->orderBy('code')->get(['id', 'code', 'nom', 'actif', 'deleted_at']);

        return [
            'entrepots' => $colonnes->map(fn (Entrepot $e) => ['id' => $e->id, 'code' => $e->code, 'nom' => $e->nom, 'actif' => $e->actif && ! $e->trashed()])->values()->all(),
            'produits' => $produits->all(),
            'totaux' => $totaux,
        ];
    }

    /**
     * Entrepôt de chaque appellation de secteur (code et nom, en minuscules). Les secteurs désactivés ou retirés
     * comptent aussi (leurs scans restent du stock) ; un secteur actif l'emporte sur un ancien du même nom.
     *
     * @return array<string, ?int>
     */
    private function entrepotsParAppellation(): array
    {
        $index = [];
        $secteurs = Secteur::withTrashed()->get(['id', 'code', 'nom', 'entrepot_id', 'actif', 'deleted_at'])
            ->sortBy(fn (Secteur $s) => [$s->trashed() ? 0 : 1, $s->actif ? 1 : 0]);
        foreach ($secteurs as $secteur) {
            $index[mb_strtolower((string) $secteur->nom)] = $secteur->entrepot_id;
        }
        // Les codes ensuite : ils l'emportent sur les noms (un code ne désigne qu'un secteur).
        foreach ($secteurs as $secteur) {
            $index[mb_strtolower((string) $secteur->code)] = $secteur->entrepot_id;
        }

        return $index;
    }
}
