<?php

namespace App\Http\Controllers;

use App\Models\MouvementTenant;
use App\Models\ScanTenant;
use App\Models\Secteur;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class InventaireTournantController extends Controller
{
    /**
     * Suggérer les secteurs à vérifier en priorité
     */
    public function suggestions(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $secteurs = Secteur::where('actif', true)->get();
        $derniers = $this->derniersScans($tenantId, $secteurs);

        $suggestions = $secteurs->map(function ($secteur) use ($tenantId, $derniers) {
            // Dernier scan dans ce secteur, sous son code ou son nom.
            $dernierScan = $derniers[$secteur->id] ?? null;
            $appellations = $this->appellations($secteur);

            // Nombre de mouvements depuis le dernier scan (tous, si le secteur n'a jamais été scanné).
            $mouvementsDepuisScan = MouvementTenant::where('tenant_id', $tenantId)
                ->where(fn ($q) => $q->whereIn('secteur_source', $appellations)->orWhereIn('secteur_destination', $appellations))
                ->when($dernierScan, fn ($q) => $q->where('created_at', '>', $dernierScan))
                ->count();

            // Calculer le score de priorité
            // Jours écoulés, toujours positifs (l'écart signé donnait « -0,14 jour » pour un scan du jour) ;
            // null si le secteur n'a jamais été scanné.
            $joursDepuisScan = $dernierScan
                ? (int) $dernierScan->diffInDays(now(), true)
                : null;

            // Score = jours depuis scan + (mouvements * 2) ; un secteur jamais scanné compte pour 365 jours.
            $score = ($joursDepuisScan ?? 365) + ($mouvementsDepuisScan * 2);

            return [
                'secteur' => $secteur->code,
                'nom' => $secteur->nom,
                'dernier_scan' => $dernierScan?->toJSON(),
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
        $tenantId = auth()->user()->tenant_id;
        $secteurs = Secteur::where('actif', true)->get();
        $totalSecteurs = $secteurs->count();
        $derniers = $this->derniersScans($tenantId, $secteurs);

        // Secteurs scannés ce mois, jamais scannés, non scannés depuis 30 jours (sous leur code ou leur nom).
        $scannesCeMois = collect($derniers)->filter(fn (Carbon $date) => $date->isSameMonth(now()))->count();
        $jamaisScannes = $totalSecteurs - count($derniers);
        $dateLimite = now()->subDays(30);
        $nonScannes30Jours = $secteurs->filter(fn (Secteur $secteur) => ! isset($derniers[$secteur->id]) || $derniers[$secteur->id]->lt($dateLimite))->count();

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
     * Historique des inventaires par secteur (sous son code ou son nom).
     */
    public function historiqueSecteur(string $secteur): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $fiche = Secteur::where('code', $secteur)->first();
        $scans = ScanTenant::where('tenant_id', $tenantId)
            ->whereIn('secteur', $fiche ? $this->appellations($fiche) : [$secteur])
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

    /** Appellations d'un secteur dans les scans et les mouvements : son code, et son nom (noté par l'application mobile). */
    private function appellations(Secteur $secteur): array
    {
        return array_values(array_unique(array_filter([(string) $secteur->code, (string) $secteur->nom], fn (string $texte) => $texte !== '')));
    }

    /**
     * Dernier scan de chaque secteur (id => date), qu'il ait été noté sous son code ou sous son nom, sans tenir compte
     * des majuscules ; un code l'emporte sur le nom d'un autre secteur.
     *
     * @return array<int, Carbon>
     */
    private function derniersScans(int $tenantId, Collection $secteurs): array
    {
        $index = [];
        foreach ($secteurs as $secteur) {
            $index[mb_strtolower((string) $secteur->nom)] = $secteur->id;
        }
        foreach ($secteurs as $secteur) {
            $index[mb_strtolower((string) $secteur->code)] = $secteur->id;
        }

        $derniers = [];
        $parAppellation = ScanTenant::where('tenant_id', $tenantId)->whereNotNull('secteur')
            ->select('secteur')->selectRaw('MAX(date_saisie) as dernier')->groupBy('secteur')->get();
        foreach ($parAppellation as $ligne) {
            $id = $index[mb_strtolower((string) $ligne->secteur)] ?? null;
            $date = Carbon::parse($ligne->dernier);
            if ($id !== null && (! isset($derniers[$id]) || $date->gt($derniers[$id]))) {
                $derniers[$id] = $date;
            }
        }

        return $derniers;
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

    private function getRaison(?int $jours, int $mouvements): string
    {
        if ($jours === null) {
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
