<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardRequest;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService
    ) {}

    /**
     * GET /api/dashboard
     *
     * Paramètres optionnels :
     *   ?period=today|week|month|year  (défaut : month)
     *
     * Réponse : agrégats ventes, achats, inventaire, finance + meta période.
     */
    public function index(DashboardRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $period   = $request->input('period', 'month');

        $data = $this->dashboardService->getData($tenantId, $period);

        return response()->json($data);
    }
}
