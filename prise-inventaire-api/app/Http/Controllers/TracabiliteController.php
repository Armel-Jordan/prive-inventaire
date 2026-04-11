<?php

namespace App\Http\Controllers;

use App\Models\MouvementTenant;
use App\Models\ScanTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TracabiliteController extends Controller
{
    /**
     * Historique complet d'un produit — isolé par tenant.
     */
    public function produitHistory(string $numero): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $mouvements = MouvementTenant::where('tenant_id', $tenantId)
            ->where('produit_numero', $numero)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($m) => [
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
            ]);

        $scans = ScanTenant::where('tenant_id', $tenantId)
            ->where('numero', $numero)
            ->orderByDesc('date_saisie')
            ->get()
            ->map(fn ($s) => [
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
            ]);

        $historique = $mouvements->concat($scans)->sortByDesc('date')->values();

        $stats = [
            'total_mouvements' => $mouvements->count(),
            'total_scans' => $scans->count(),
            'secteurs_visites' => $this->getSecteursVisites($numero, $tenantId),
            'derniere_localisation' => $this->getDerniereLocalisation($numero, $tenantId),
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
     * Recherche de produits pour la traçabilité — isolée par tenant.
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->get('q', '');

        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $tenantId = auth()->user()->tenant_id;

        $produitsMovements = MouvementTenant::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('produit_numero', 'like', "%{$query}%")
                ->orWhere('produit_nom', 'like', "%{$query}%"))
            ->select('produit_numero', 'produit_nom')
            ->distinct()->limit(10)->get();

        $produitsScans = ScanTenant::where('tenant_id', $tenantId)
            ->where('numero', 'like', "%{$query}%")
            ->select('numero as produit_numero')
            ->selectRaw('NULL as produit_nom')
            ->distinct()->limit(10)->get();

        $produits = $produitsMovements->concat($produitsScans)
            ->unique('produit_numero')->take(10)->values();

        return response()->json($produits);
    }

    /**
     * Timeline d'un produit — isolée par tenant.
     */
    public function timeline(string $numero): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $events = collect();

        MouvementTenant::where('tenant_id', $tenantId)
            ->where('produit_numero', $numero)
            ->orderBy('created_at')
            ->get()
            ->each(function ($m) use (&$events) {
                $events->push([
                    'date' => $m->created_at->format('Y-m-d H:i:s'),
                    'type' => $m->type,
                    'description' => $this->getMouvementDescription($m),
                    'secteur' => $m->secteur_destination ?? $m->secteur_source,
                    'quantite' => $m->quantite,
                    'employe' => $m->employe,
                ]);
            });

        ScanTenant::where('tenant_id', $tenantId)
            ->where('numero', $numero)
            ->orderBy('date_saisie')
            ->get()
            ->each(function ($s) use (&$events) {
                $events->push([
                    'date' => $s->date_saisie,
                    'type' => 'inventaire',
                    'description' => "Inventaire dans {$s->secteur}",
                    'secteur' => $s->secteur,
                    'quantite' => $s->quantite,
                    'employe' => $s->employe,
                ]);
            });

        return response()->json($events->sortBy('date')->values());
    }

    private function getSecteursVisites(string $numero, int $tenantId): array
    {
        $secteursMouvements = MouvementTenant::where('tenant_id', $tenantId)
            ->where('produit_numero', $numero)
            ->whereNotNull('secteur_destination')
            ->pluck('secteur_destination')->unique();

        $secteursScans = ScanTenant::where('tenant_id', $tenantId)
            ->where('numero', $numero)
            ->pluck('secteur')->unique();

        return $secteursMouvements->concat($secteursScans)->unique()->values()->toArray();
    }

    private function getDerniereLocalisation(string $numero, int $tenantId): ?string
    {
        $dernierMouvement = MouvementTenant::where('tenant_id', $tenantId)
            ->where('produit_numero', $numero)
            ->whereNotNull('secteur_destination')
            ->orderByDesc('created_at')->first();

        $dernierScan = ScanTenant::where('tenant_id', $tenantId)
            ->where('numero', $numero)
            ->orderByDesc('date_saisie')->first();

        if (! $dernierMouvement && ! $dernierScan) {
            return null;
        }
        if (! $dernierMouvement) {
            return $dernierScan->secteur;
        }
        if (! $dernierScan) {
            return $dernierMouvement->secteur_destination;
        }

        return $dernierMouvement->created_at > $dernierScan->date_saisie
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
