<?php

namespace App\Http\Controllers;

use App\Models\MouvementTenant;
use App\Models\ScanTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RapportController extends Controller
{
    /**
     * Rapport mensuel des mouvements par secteur
     */
    public function mouvementsParSecteur(Request $request): JsonResponse
    {
        $mois = $request->get('mois', now()->month);
        $annee = $request->get('annee', now()->year);

        // Mouvements entrants par secteur (arrivages + transferts entrants)
        $entrants = MouvementTenant::whereYear('created_at', $annee)
            ->whereMonth('created_at', $mois)
            ->whereNotNull('secteur_destination')
            ->whereIn('type', ['arrivage', 'transfert'])
            ->select('secteur_destination as secteur')
            ->selectRaw('COUNT(*) as nombre')
            ->selectRaw('SUM(quantite) as quantite_totale')
            ->groupBy('secteur_destination')
            ->get()
            ->keyBy('secteur');

        // Mouvements sortants par secteur (sorties + transferts sortants)
        $sortants = MouvementTenant::whereYear('created_at', $annee)
            ->whereMonth('created_at', $mois)
            ->whereNotNull('secteur_source')
            ->whereIn('type', ['sortie', 'transfert'])
            ->select('secteur_source as secteur')
            ->selectRaw('COUNT(*) as nombre')
            ->selectRaw('SUM(quantite) as quantite_totale')
            ->groupBy('secteur_source')
            ->get()
            ->keyBy('secteur');

        // Combiner les secteurs
        $secteurs = collect($entrants->keys())
            ->merge($sortants->keys())
            ->unique()
            ->sort()
            ->values();

        $rapport = $secteurs->map(function ($secteur) use ($entrants, $sortants) {
            $entrant = $entrants->get($secteur);
            $sortant = $sortants->get($secteur);

            return [
                'secteur' => $secteur,
                'entrants' => [
                    'nombre' => $entrant ? $entrant->nombre : 0,
                    'quantite' => $entrant ? (float) $entrant->quantite_totale : 0,
                ],
                'sortants' => [
                    'nombre' => $sortant ? $sortant->nombre : 0,
                    'quantite' => $sortant ? (float) $sortant->quantite_totale : 0,
                ],
                'solde' => ($entrant ? (float) $entrant->quantite_totale : 0) - ($sortant ? (float) $sortant->quantite_totale : 0),
            ];
        });

        // Totaux
        $totaux = [
            'entrants' => [
                'nombre' => $rapport->sum('entrants.nombre'),
                'quantite' => $rapport->sum('entrants.quantite'),
            ],
            'sortants' => [
                'nombre' => $rapport->sum('sortants.nombre'),
                'quantite' => $rapport->sum('sortants.quantite'),
            ],
        ];

        return response()->json([
            'mois' => $mois,
            'annee' => $annee,
            'rapport' => $rapport,
            'totaux' => $totaux,
        ]);
    }

    /**
     * Rapport d'activité par employé
     */
    public function activiteParEmploye(Request $request): JsonResponse
    {
        $mois = $request->get('mois', now()->month);
        $annee = $request->get('annee', now()->year);

        $mouvements = MouvementTenant::whereYear('created_at', $annee)
            ->whereMonth('created_at', $mois)
            ->select('employe')
            ->selectRaw('COUNT(*) as nombre_mouvements')
            ->selectRaw('SUM(quantite) as quantite_totale')
            ->groupBy('employe')
            ->orderByDesc('nombre_mouvements')
            ->get();

        $scans = ScanTenant::whereYear('date_saisie', $annee)
            ->whereMonth('date_saisie', $mois)
            ->select('employe')
            ->selectRaw('COUNT(*) as nombre_scans')
            ->selectRaw('SUM(quantite) as quantite_totale')
            ->groupBy('employe')
            ->get()
            ->keyBy('employe');

        $rapport = $mouvements->map(function ($m) use ($scans) {
            $scan = $scans->get($m->employe);

            return [
                'employe' => $m->employe,
                'mouvements' => $m->nombre_mouvements,
                'scans' => $scan ? $scan->nombre_scans : 0,
                'quantite_mouvements' => (float) $m->quantite_totale,
                'quantite_scans' => $scan ? (float) $scan->quantite_totale : 0,
            ];
        });

        return response()->json([
            'mois' => $mois,
            'annee' => $annee,
            'rapport' => $rapport,
        ]);
    }

    /**
     * Évolution mensuelle sur l'année
     */
    public function evolutionAnnuelle(Request $request): JsonResponse
    {
        $annee = $request->get('annee', now()->year);

        $mouvements = MouvementTenant::whereYear('created_at', $annee)
            ->selectRaw('MONTH(created_at) as mois')
            ->selectRaw('COUNT(*) as nombre')
            ->selectRaw('SUM(quantite) as quantite')
            ->groupByRaw('MONTH(created_at)')
            ->orderByRaw('MONTH(created_at)')
            ->get()
            ->keyBy('mois');

        $scans = ScanTenant::whereYear('date_saisie', $annee)
            ->selectRaw('MONTH(date_saisie) as mois')
            ->selectRaw('COUNT(*) as nombre')
            ->selectRaw('SUM(quantite) as quantite')
            ->groupByRaw('MONTH(date_saisie)')
            ->orderByRaw('MONTH(date_saisie)')
            ->get()
            ->keyBy('mois');

        $moisNoms = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
        ];

        $rapport = collect(range(1, 12))->map(function ($mois) use ($mouvements, $scans, $moisNoms) {
            $mouvement = $mouvements->get($mois);
            $scan = $scans->get($mois);

            return [
                'mois' => $mois,
                'nom' => $moisNoms[$mois],
                'mouvements' => $mouvement ? $mouvement->nombre : 0,
                'scans' => $scan ? $scan->nombre : 0,
                'quantite_mouvements' => $mouvement ? (float) $mouvement->quantite : 0,
                'quantite_scans' => $scan ? (float) $scan->quantite : 0,
            ];
        });

        return response()->json([
            'annee' => $annee,
            'rapport' => $rapport,
        ]);
    }

    /**
     * Top produits du mois
     */
    public function topProduits(Request $request): JsonResponse
    {
        $mois = $request->get('mois', now()->month);
        $annee = $request->get('annee', now()->year);
        $limit = min($request->get('limit', 10), 50);

        $produits = MouvementTenant::whereYear('created_at', $annee)
            ->whereMonth('created_at', $mois)
            ->select('produit_numero', 'produit_nom')
            ->selectRaw('COUNT(*) as nombre_mouvements')
            ->selectRaw('SUM(quantite) as quantite_totale')
            ->groupBy('produit_numero', 'produit_nom')
            ->orderByDesc('nombre_mouvements')
            ->limit($limit)
            ->get();

        return response()->json([
            'mois' => $mois,
            'annee' => $annee,
            'produits' => $produits,
        ]);
    }
}
