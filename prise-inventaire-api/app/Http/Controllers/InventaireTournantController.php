<?php

namespace App\Http\Controllers;

use App\Models\MouvementTenant;
use App\Models\ScanTenant;
use App\Models\Secteur;
use Illuminate\Http\JsonResponse;

class InventaireTournantController extends Controller
{
    /**
     * Suggérer les secteurs à vérifier en priorité
     */
    public function suggestions(): JsonResponse
    {
        $secteurs = Secteur::where('actif', true)->get();

        $suggestions = $secteurs->map(function ($secteur) {
            // Dernier scan dans ce secteur
            $dernierScan = ScanTenant::where('secteur', $secteur->code)
                ->orderByDesc('date_saisie')
                ->first();

            // Nombre de mouvements depuis le dernier scan
            $mouvementsDepuisScan = 0;
            if ($dernierScan) {
                $mouvementsDepuisScan = MouvementTenant::where(function ($q) use ($secteur) {
                    $q->where('secteur_source', $secteur->code)
                        ->orWhere('secteur_destination', $secteur->code);
                })
                    ->where('created_at', '>', $dernierScan->date_saisie)
                    ->count();
            } else {
                // Si jamais scanné, compter tous les mouvements
                $mouvementsDepuisScan = MouvementTenant::where(function ($q) use ($secteur) {
                    $q->where('secteur_source', $secteur->code)
                        ->orWhere('secteur_destination', $secteur->code);
                })->count();
            }

            // Calculer le score de priorité
            $joursDepuisScan = $dernierScan
                ? now()->diffInDays($dernierScan->date_saisie)
                : 365; // Si jamais scanné, très haute priorité

            // Score = jours depuis scan + (mouvements * 2)
            $score = $joursDepuisScan + ($mouvementsDepuisScan * 2);

            return [
                'secteur' => $secteur->code,
                'nom' => $secteur->nom,
                'dernier_scan' => $dernierScan ? $dernierScan->date_saisie : null,
                'jours_depuis_scan' => $joursDepuisScan,
                'mouvements_depuis_scan' => $mouvementsDepuisScan,
                'score_priorite' => $score,
                'raison' => $this->getRaison($joursDepuisScan, $mouvementsDepuisScan),
            ];
        })
            ->sortByDesc('score_priorite')
            ->values();

        return response()->json([
            'suggestions' => $suggestions,
            'date_generation' => now()->toIso8601String(),
        ]);
    }

    /**
     * Statistiques de l'inventaire tournant
     */
    public function stats(): JsonResponse
    {
        $secteurs = Secteur::where('actif', true)->get();
        $totalSecteurs = $secteurs->count();

        // Secteurs scannés ce mois
        $scannesCeMois = ScanTenant::whereMonth('date_saisie', now()->month)
            ->whereYear('date_saisie', now()->year)
            ->distinct('secteur')
            ->count('secteur');

        // Secteurs jamais scannés
        $secteursAvecScans = ScanTenant::distinct('secteur')->pluck('secteur');
        $jamaisScannes = $secteurs->whereNotIn('code', $secteursAvecScans)->count();

        // Secteurs non scannés depuis 30 jours
        $dateLimite = now()->subDays(30);
        $derniersScansParSecteur = ScanTenant::select('secteur')
            ->selectRaw('MAX(date_saisie) as dernier_scan')
            ->groupBy('secteur')
            ->get()
            ->keyBy('secteur');

        $nonScannes30Jours = $secteurs->filter(function ($secteur) use ($derniersScansParSecteur, $dateLimite) {
            $dernierScan = $derniersScansParSecteur->get($secteur->code);
            if (! $dernierScan) {
                return true;
            }

            return $dernierScan->dernier_scan < $dateLimite;
        })->count();

        // Couverture
        $couverture = $totalSecteurs > 0
            ? round(($scannesCeMois / $totalSecteurs) * 100, 1)
            : 0;

        return response()->json([
            'total_secteurs' => $totalSecteurs,
            'scannes_ce_mois' => $scannesCeMois,
            'jamais_scannes' => $jamaisScannes,
            'non_scannes_30_jours' => $nonScannes30Jours,
            'couverture_mois' => $couverture,
        ]);
    }

    /**
     * Historique des inventaires par secteur
     */
    public function historiqueSecteur(string $secteur): JsonResponse
    {
        $scans = ScanTenant::where('secteur', $secteur)
            ->orderByDesc('date_saisie')
            ->limit(50)
            ->get();

        $stats = [
            'total_scans' => $scans->count(),
            'dernier_scan' => $scans->first()?->date_saisie,
            'produits_uniques' => $scans->unique('numero')->count(),
            'quantite_totale' => $scans->sum('quantite'),
        ];

        return response()->json([
            'secteur' => $secteur,
            'scans' => $scans,
            'stats' => $stats,
        ]);
    }

    /**
     * Planning suggéré pour le mois
     */
    public function planning(): JsonResponse
    {
        $secteurs = Secteur::where('actif', true)->get();
        $joursOuvrables = $this->getJoursOuvrables(now()->year, now()->month);
        $secteursParJour = max(1, ceil($secteurs->count() / count($joursOuvrables)));

        $planning = [];
        $secteurIndex = 0;
        $secteursList = $secteurs->values();

        foreach ($joursOuvrables as $jour) {
            $secteursJour = [];
            for ($i = 0; $i < $secteursParJour && $secteurIndex < $secteursList->count(); $i++) {
                $secteursJour[] = [
                    'code' => $secteursList[$secteurIndex]->code,
                    'nom' => $secteursList[$secteurIndex]->nom,
                ];
                $secteurIndex++;
            }

            if (! empty($secteursJour)) {
                $planning[] = [
                    'date' => $jour->format('Y-m-d'),
                    'jour' => $jour->translatedFormat('l d F'),
                    'secteurs' => $secteursJour,
                ];
            }
        }

        return response()->json([
            'mois' => now()->translatedFormat('F Y'),
            'planning' => $planning,
        ]);
    }

    private function getRaison(int $jours, int $mouvements): string
    {
        if ($jours >= 365) {
            return 'Jamais inventorié';
        }
        if ($jours >= 30 && $mouvements >= 10) {
            return 'Beaucoup de mouvements récents';
        }
        if ($jours >= 60) {
            return 'Non vérifié depuis 2 mois';
        }
        if ($jours >= 30) {
            return 'Non vérifié depuis 1 mois';
        }
        if ($mouvements >= 20) {
            return 'Activité intense';
        }
        if ($mouvements >= 10) {
            return 'Activité modérée';
        }

        return 'Vérification de routine';
    }

    private function getJoursOuvrables(int $annee, int $mois): array
    {
        $jours = [];
        $date = now()->setYear($annee)->setMonth($mois)->startOfMonth();
        $finMois = $date->copy()->endOfMonth();

        while ($date <= $finMois) {
            // Exclure samedi (6) et dimanche (0)
            if ($date->dayOfWeek !== 0 && $date->dayOfWeek !== 6) {
                $jours[] = $date->copy();
            }
            $date->addDay();
        }

        return $jours;
    }
}
