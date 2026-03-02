<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\EmployeController;
use App\Http\Controllers\EmployeTenantController;
use App\Http\Controllers\MouvementInventaireController;
use App\Http\Controllers\MouvementTenantController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\ProduitTenantController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\ScanTenantController;
use App\Http\Controllers\SecteurController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\AlerteStockController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\TransfertPlanifieController;
use Illuminate\Support\Facades\Route;

// ============================================
// Routes publiques (sans authentification)
// ============================================
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/super-admin/login', [SuperAdminController::class, 'login']);

// ============================================
// Routes Super Admin (gestion des tenants)
// ============================================
Route::prefix('super-admin')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/stats', [SuperAdminController::class, 'getStats']);
    Route::get('/tenants', [SuperAdminController::class, 'getTenants']);
    Route::post('/tenants', [SuperAdminController::class, 'createTenant']);
    Route::put('/tenants/{id}', [SuperAdminController::class, 'updateTenant']);
    Route::post('/tenants/{id}/renew', [SuperAdminController::class, 'renewTenant']);
    Route::delete('/tenants/{id}', [SuperAdminController::class, 'deleteTenant']);
    Route::get('/tenants/{tenantId}/admins', [SuperAdminController::class, 'getTenantAdmins']);
    Route::post('/tenants/{tenantId}/admins', [SuperAdminController::class, 'createTenantAdmin']);
    Route::put('/tenants/{tenantId}/admins/{adminId}', [SuperAdminController::class, 'updateTenantAdmin']);
    Route::delete('/tenants/{tenantId}/admins/{adminId}', [SuperAdminController::class, 'deleteTenantAdmin']);
});

// ============================================
// Routes authentifiées (utilisateur connecté)
// ============================================
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
});

// ============================================
// Routes Tenant (avec middleware tenant)
// ============================================
Route::middleware(['auth:sanctum'])->group(function () {
    // Employés CRUD
    Route::get('/employes', [EmployeTenantController::class, 'index']);
    Route::post('/employes', [EmployeTenantController::class, 'store']);
    Route::get('/employes/{id}', [EmployeTenantController::class, 'show']);
    Route::put('/employes/{id}', [EmployeTenantController::class, 'update']);
    Route::delete('/employes/{id}', [EmployeTenantController::class, 'destroy']);

    // Produits CRUD
    Route::get('/produits', [ProduitTenantController::class, 'index']);
    Route::post('/produits', [ProduitTenantController::class, 'store']);
    Route::get('/produits/{id}', [ProduitTenantController::class, 'show']);
    Route::put('/produits/{id}', [ProduitTenantController::class, 'update']);
    Route::delete('/produits/{id}', [ProduitTenantController::class, 'destroy']);

    // Scans
    Route::post('/scan/enregistrer', [ScanTenantController::class, 'enregistrer']);
    Route::get('/scan/historique', [ScanTenantController::class, 'historique']);
    Route::put('/scan/{id}', [ScanTenantController::class, 'modifier']);
    Route::delete('/scan/{id}', [ScanTenantController::class, 'supprimer']);

    // Secteurs CRUD
    Route::get('/secteurs', [SecteurController::class, 'index']);
    Route::post('/secteurs', [SecteurController::class, 'store']);
    Route::get('/secteurs/{id}', [SecteurController::class, 'show']);
    Route::put('/secteurs/{id}', [SecteurController::class, 'update']);
    Route::delete('/secteurs/{id}', [SecteurController::class, 'destroy']);

    // Mouvements d'inventaire (ancien système)
    Route::get('/mouvements', [MouvementInventaireController::class, 'index']);
    Route::post('/mouvements', [MouvementInventaireController::class, 'store']);
    Route::get('/mouvements/{id}', [MouvementInventaireController::class, 'show']);
    Route::get('/mouvements/scan/{scanId}', [MouvementInventaireController::class, 'getByScan']);

    // Relocalisation (nouveau système)
    Route::prefix('relocalisation')->group(function () {
        Route::get('/', [MouvementTenantController::class, 'index']);
        Route::post('/', [MouvementTenantController::class, 'store']);
        Route::get('/stats', [MouvementTenantController::class, 'stats']);
        Route::get('/{id}', [MouvementTenantController::class, 'show']);
        Route::post('/par-secteur', [MouvementTenantController::class, 'relocalisationParSecteur']);
        Route::post('/arrivage-lot', [MouvementTenantController::class, 'arrivageLot']);
        Route::get('/produit/{numero}', [MouvementTenantController::class, 'historyByProduit']);
        Route::get('/secteur/{secteur}', [MouvementTenantController::class, 'historyBySecteur']);
    });

    // Alertes de stock
    Route::prefix('alertes')->group(function () {
        Route::get('/', [AlerteStockController::class, 'index']);
        Route::get('/stats', [AlerteStockController::class, 'stats']);
        Route::put('/produit/{id}/seuil', [AlerteStockController::class, 'updateSeuil']);
        Route::post('/seuils-batch', [AlerteStockController::class, 'updateSeuilsBatch']);
    });

    // Audit logs (historique des modifications)
    Route::prefix('audit')->group(function () {
        Route::get('/', [AuditLogController::class, 'index']);
        Route::get('/stats', [AuditLogController::class, 'stats']);
        Route::get('/{modelType}/{modelId}', [AuditLogController::class, 'history']);
    });

    // Transferts planifiés
    Route::prefix('transferts-planifies')->group(function () {
        Route::get('/', [TransfertPlanifieController::class, 'index']);
        Route::get('/stats', [TransfertPlanifieController::class, 'stats']);
        Route::get('/upcoming', [TransfertPlanifieController::class, 'upcoming']);
        Route::post('/', [TransfertPlanifieController::class, 'store']);
        Route::get('/{id}', [TransfertPlanifieController::class, 'show']);
        Route::put('/{id}', [TransfertPlanifieController::class, 'update']);
        Route::post('/{id}/execute', [TransfertPlanifieController::class, 'execute']);
        Route::post('/{id}/cancel', [TransfertPlanifieController::class, 'cancel']);
        Route::delete('/{id}', [TransfertPlanifieController::class, 'destroy']);
    });

    // Utilisateurs admin CRUD
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::post('/users', [AdminUserController::class, 'store']);
    Route::put('/users/{id}', [AdminUserController::class, 'update']);
    Route::delete('/users/{id}', [AdminUserController::class, 'destroy']);
});
