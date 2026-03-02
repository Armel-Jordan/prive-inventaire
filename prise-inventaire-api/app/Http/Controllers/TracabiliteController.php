<?php

namespace App\Http\Controllers;

use App\Models\MouvementTenant;
use App\Models\ScanTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TracabiliteController extends Controller
{
    /**
     * Historique complet d'un produit
     */
    public function produitHistory(string $numero): JsonResponse
    {
        // Récupérer tous les mouvements du produit
        $mouvements = MouvementTenant::where('produit_numero', $numero)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($m) {
                return [
                    'id' => $m->id,
                    'type' => 'mouvement',
                    'action' => $m->type,
                    'secteur_source' => $m->secteur_source,
                    'secteur_destination' => $m->secteur_destination,
                    'quantite' => $m->quantite,
                    'unite_mesure' => $m->unite_mesure,
                    'employe' => $m->employe,
                    'motif' => $m->motif,
                    'date' => $m->created_at,
                ];
            });

        // Récupérer tous les scans d'inventaire du produit
        $scans = ScanTenant::where('numero', $numero)
            ->orderByDesc('date_saisie')
            ->get()
            ->map(function ($s) {
                return [
                    'id' => $s->id,
                    'type' => 'inventaire',
                    'action' => $s->type ?? 'scan',
                    'secteur_source' => null,
                    'secteur_destination' => $s->secteur,
                    'quantite' => $s->quantite,
                    'unite_mesure' => $s->unite_mesure,
                    'employe' => $s->employe,
                    'motif' => null,
                    'date' => $s->date_saisie,
                ];
            });

        // Fusionner et trier par date
        $historique = $mouvements->concat($scans)
            ->sortByDesc('date')
            ->values();

        // Calculer les statistiques
        $stats = [
            'total_mouvements' => $mouvements->count(),
            'total_scans' => $scans->count(),
            'secteurs_visites' => $this->getSecteursVisites($numero),
            'derniere_localisation' => $this->getDerniereLocalisation($numero),
            'quantite_totale_entree' => $mouvements->where('action', 'arrivage')->sum('quantite'),
            'quantite_totale_sortie' => $mouvements->where('action', 'sortie')->sum('quantite'),
        ];

        return response()->json([
            'produit_numero' => $numero,
            'historique' => $historique,
            'stats' => $stats,
        ]);
    }

    /**
     * Recherche de produits pour la traçabilité
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->get('q', '');

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        // Chercher dans les mouvements
        $produitsMovements = MouvementTenant::where('produit_numero', 'like', "%{$query}%")
            ->orWhere('produit_nom', 'like', "%{$query}%")
            ->select('produit_numero', 'produit_nom')
            ->distinct()
            ->limit(10)
            ->get();

        // Chercher dans les scans
        $produitsScans = ScanTenant::where('numero', 'like', "%{$query}%")
            ->select('numero as produit_numero')
            ->selectRaw('NULL as produit_nom')
            ->distinct()
            ->limit(10)
            ->get();

        $produits = $produitsMovements->concat($produitsScans)
            ->unique('produit_numero')
            ->take(10)
            ->values();

        return response()->json($produits);
    }

    /**
     * Timeline d'un produit (format chronologique)
     */
    public function timeline(string $numero): JsonResponse
    {
        $events = collect();

        // Mouvements
        $mouvements = MouvementTenant::where('produit_numero', $numero)
            ->orderBy('created_at')
            ->get();

        foreach ($mouvements as $m) {
            $events->push([
                'date' => $m->created_at->format('Y-m-d H:i:s'),
                'type' => $m->type,
                'description' => $this->getMouvementDescription($m),
                'secteur' => $m->secteur_destination ?? $m->secteur_source,
                'quantite' => $m->quantite,
                'employe' => $m->employe,
            ]);
        }

        // Scans
        $scans = ScanTenant::where('numero', $numero)
            ->orderBy('date_saisie')
            ->get();

        foreach ($scans as $s) {
            $events->push([
                'date' => $s->date_saisie,
                'type' => 'inventaire',
                'description' => "Inventaire dans {$s->secteur}",
                'secteur' => $s->secteur,
                'quantite' => $s->quantite,
                'employe' => $s->employe,
            ]);
        }

        return response()->json($events->sortBy('date')->values());
    }

    private function getSecteursVisites(string $numero): array
    {
        $secteursMouvements = MouvementTenant::where('produit_numero', $numero)
            ->whereNotNull('secteur_destination')
            ->pluck('secteur_destination')
            ->unique();

        $secteursScans = ScanTenant::where('numero', $numero)
            ->pluck('secteur')
            ->unique();

        return $secteursMouvements->concat($secteursScans)->unique()->values()->toArray();
    }

    private function getDerniereLocalisation(string $numero): ?string
    {
        // Chercher le dernier mouvement avec destination
        $dernierMouvement = MouvementTenant::where('produit_numero', $numero)
            ->whereNotNull('secteur_destination')
            ->orderByDesc('created_at')
            ->first();

        // Chercher le dernier scan
        $dernierScan = ScanTenant::where('numero', $numero)
            ->orderByDesc('date_saisie')
            ->first();

        if (!$dernierMouvement && !$dernierScan) {
            return null;
        }

        if (!$dernierMouvement) {
            return $dernierScan->secteur;
        }

        if (!$dernierScan) {
            return $dernierMouvement->secteur_destination;
        }

        // Comparer les dates
        $dateMouvement = $dernierMouvement->created_at;
        $dateScan = $dernierScan->date_saisie;

        return $dateMouvement > $dateScan
            ? $dernierMouvement->secteur_destination
            : $dernierScan->secteur;
    }

    private function getMouvementDescription($mouvement): string
    {
        return match ($mouvement->type) {
            'arrivage' => "Arrivage vers {$mouvement->secteur_destination}",
            'transfert' => "Transfert de {$mouvement->secteur_source} vers {$mouvement->secteur_destination}",
            'sortie' => "Sortie de {$mouvement->secteur_source}",
            'ajustement' => "Ajustement dans {$mouvement->secteur_destination}",
            default => "Mouvement {$mouvement->type}",
        };
    }
}
