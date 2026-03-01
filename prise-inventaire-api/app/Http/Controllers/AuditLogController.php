<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Liste des logs d'audit avec filtres
     */
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()->orderByDesc('created_at');

        if ($request->has('action') && $request->action) {
            $query->where('action', $request->action);
        }

        if ($request->has('model_type') && $request->model_type) {
            $query->where('model_type', $request->model_type);
        }

        if ($request->has('model_id') && $request->model_id) {
            $query->where('model_id', $request->model_id);
        }

        if ($request->has('user_id') && $request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('date_debut') && $request->date_debut) {
            $query->whereDate('created_at', '>=', $request->date_debut);
        }

        if ($request->has('date_fin') && $request->date_fin) {
            $query->whereDate('created_at', '<=', $request->date_fin);
        }

        $limit = min($request->get('limit', 100), 500);
        $logs = $query->limit($limit)->get();

        return response()->json($logs);
    }

    /**
     * Historique d'un élément spécifique
     */
    public function history(string $modelType, string $modelId): JsonResponse
    {
        $logs = AuditLog::where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($logs);
    }

    /**
     * Statistiques des audits
     */
    public function stats(): JsonResponse
    {
        $today = now()->startOfDay();
        $thisWeek = now()->startOfWeek();
        $thisMonth = now()->startOfMonth();

        return response()->json([
            'today' => AuditLog::where('created_at', '>=', $today)->count(),
            'this_week' => AuditLog::where('created_at', '>=', $thisWeek)->count(),
            'this_month' => AuditLog::where('created_at', '>=', $thisMonth)->count(),
            'by_action' => [
                'create' => AuditLog::where('action', 'create')->where('created_at', '>=', $thisMonth)->count(),
                'update' => AuditLog::where('action', 'update')->where('created_at', '>=', $thisMonth)->count(),
                'delete' => AuditLog::where('action', 'delete')->where('created_at', '>=', $thisMonth)->count(),
            ],
            'by_model' => AuditLog::where('created_at', '>=', $thisMonth)
                ->selectRaw('model_type, count(*) as count')
                ->groupBy('model_type')
                ->pluck('count', 'model_type'),
        ]);
    }
}
