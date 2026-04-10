<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        // Scans
        $scansTotal = DB::table('inventaire_scan')->whereNull('deleted_at')->count();

        // Produits (numéros uniques dans les scans)
        $produitsTotal = DB::table('inventaire_scan')
            ->whereNull('deleted_at')
            ->distinct('numero')
            ->count('numero');

        // Secteurs
        $secteursTotal = DB::table('secteurs')->where('actif', true)->count();

        // Employés
        $employesTotal = DB::table('employes')->where('actif', true)->count();

        // Mouvements relocalisation
        $mouvementsTotal = 0;
        $mouvementsToday = 0;
        $mouvementsByType = [];
        try {
            if (DB::getSchemaBuilder()->hasTable('mouvement_relocalisation')) {
                $mouvementsTotal = DB::table('mouvement_relocalisation')->count();
                $mouvementsToday = DB::table('mouvement_relocalisation')
                    ->whereDate('date_mouvement', today())->count();
                $mouvementsByType = DB::table('mouvement_relocalisation')
                    ->select('type', DB::raw('count(*) as count'))
                    ->groupBy('type')->pluck('count', 'type')->toArray();
            }
        } catch (\Exception $e) {
            // Table non disponible — valeurs par défaut
        }

        // Transferts planifiés (7 prochains jours)
        $transfertsPlanifies = 0;
        try {
            $transfertsPlanifies = DB::table('transferts_planifies')
                ->where('statut', 'planifie')
                ->whereDate('date_planifiee', '>=', today())
                ->whereDate('date_planifiee', '<=', today()->addDays(7))
                ->count();
        } catch (\Exception $e) {
            // Table non disponible
        }

        // Approbations en attente
        $approbationsEnAttente = DB::table('approbations')
            ->where('statut', 'en_attente')
            ->count();

        // Notifications non lues
        $notificationsNonLues = DB::table('notifications')
            ->where('lu', false)
            ->count();

        return response()->json([
            'inventaire' => [
                'scans' => $scansTotal,
                'produits' => $produitsTotal,
                'secteurs' => $secteursTotal,
                'employes' => $employesTotal,
            ],
            'relocalisation' => [
                'total' => $mouvementsTotal,
                'today' => $mouvementsToday,
                'by_type' => $mouvementsByType,
            ],
            'actions' => [
                'alertes' => 0, // Pas de table alertes_stock
                'notifications' => $notificationsNonLues,
                'planifications' => $transfertsPlanifies,
                'approbations' => $approbationsEnAttente,
            ],
        ]);
    }
}
