<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        // Ces requêtes utilisent le query builder brut (hors Global Scope Eloquent) :
        // on scope explicitement par tenant_id pour ne pas agréger les données d'autres tenants.
        $tenantId = app(TenantContext::class)->getTenantId();

        // Scans
        $scansTotal = DB::table('inventaire_scan')->where('tenant_id', $tenantId)->whereNull('deleted_at')->count();

        // Produits (numéros uniques dans les scans)
        $produitsTotal = DB::table('inventaire_scan')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->distinct('numero')
            ->count('numero');

        // Secteurs
        $secteursTotal = DB::table('secteurs')->where('tenant_id', $tenantId)->where('actif', true)->count();

        // Employés
        $employesTotal = DB::table('employes')->where('tenant_id', $tenantId)->where('actif', true)->count();

        // Mouvements relocalisation
        $mouvementsTotal = 0;
        $mouvementsToday = 0;
        $mouvementsByType = [];
        try {
            if (DB::getSchemaBuilder()->hasTable('mouvement_relocalisation')) {
                $mouvementsTotal = DB::table('mouvement_relocalisation')->where('tenant_id', $tenantId)->count();
                $mouvementsToday = DB::table('mouvement_relocalisation')
                    ->where('tenant_id', $tenantId)
                    ->whereDate('date_mouvement', today())->count();
                $mouvementsByType = DB::table('mouvement_relocalisation')
                    ->where('tenant_id', $tenantId)
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
                ->where('tenant_id', $tenantId)
                ->where('statut', 'planifie')
                ->whereDate('date_planifiee', '>=', today())
                ->whereDate('date_planifiee', '<=', today()->addDays(7))
                ->count();
        } catch (\Exception $e) {
            // Table non disponible
        }

        // Approbations en attente
        $approbationsEnAttente = DB::table('approbations')
            ->where('tenant_id', $tenantId)
            ->where('statut', 'en_attente')
            ->count();

        // Notifications non lues
        $notificationsNonLues = DB::table('notifications')
            ->where('tenant_id', $tenantId)
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
