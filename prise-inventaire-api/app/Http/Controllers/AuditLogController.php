<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Liste des logs d'audit avec filtres — isolés par tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $query = AuditLog::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('model_type')) {
            $query->where('model_type', $request->model_type);
        }

        if ($request->filled('model_id')) {
            $query->where('model_id', $request->model_id);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('date_debut')) {
            $query->whereDate('created_at', '>=', $request->date_debut);
        }

        if ($request->filled('date_fin')) {
            $query->whereDate('created_at', '<=', $request->date_fin);
        }

        $limit = min($request->integer('limit', 100), 500);
        $logs = $query->limit($limit)->get();

        return response()->json($logs);
    }

    /**
     * Historique d'un élément spécifique — isolé par tenant.
     */
    public function history(string $modelType, string $modelId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $logs = AuditLog::where('tenant_id', $tenantId)
            ->where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($logs);
    }

    /**
     * Statistiques des audits — isolées par tenant.
     */
    public function stats(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $today = now()->startOfDay();
        $thisWeek = now()->startOfWeek();
        $thisMonth = now()->startOfMonth();

        $base = fn () => AuditLog::where('tenant_id', $tenantId);

        return response()->json([
            'today' => $base()->where('created_at', '>=', $today)->count(),
            'this_week' => $base()->where('created_at', '>=', $thisWeek)->count(),
            'this_month' => $base()->where('created_at', '>=', $thisMonth)->count(),
            'by_action' => [
                'create' => $base()->where('action', 'create')->where('created_at', '>=', $thisMonth)->count(),
                'update' => $base()->where('action', 'update')->where('created_at', '>=', $thisMonth)->count(),
                'delete' => $base()->where('action', 'delete')->where('created_at', '>=', $thisMonth)->count(),
            ],
            'by_model' => $base()->where('created_at', '>=', $thisMonth)
                ->selectRaw('model_type, count(*) as count')
                ->groupBy('model_type')
                ->pluck('count', 'model_type'),
        ]);
    }
}
