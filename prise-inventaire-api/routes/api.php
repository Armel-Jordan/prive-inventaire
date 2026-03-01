<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmployeController;
use App\Http\Controllers\EmployeTenantController;
use App\Http\Controllers\MouvementInventaireController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\ProduitTenantController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\ScanTenantController;
use App\Http\Controllers\SecteurController;
use App\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

// ============================================
// Routes publiques (sans authentification)
// ============================================
Route::post('/auth/login', [AuthController::class, 'login']);

// ============================================
// Routes Super Admin (gestion des tenants)
// ============================================
Route::prefix('admin')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store']);
    Route::get('/tenants/{id}', [TenantController::class, 'show']);
    Route::put('/tenants/{id}', [TenantController::class, 'update']);
    Route::delete('/tenants/{id}', [TenantController::class, 'destroy']);
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

    // Mouvements d'inventaire
    Route::get('/mouvements', [MouvementInventaireController::class, 'index']);
    Route::post('/mouvements', [MouvementInventaireController::class, 'store']);
    Route::get('/mouvements/{id}', [MouvementInventaireController::class, 'show']);
    Route::get('/mouvements/scan/{scanId}', [MouvementInventaireController::class, 'getByScan']);
});
