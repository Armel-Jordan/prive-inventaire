<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmployeController;
use App\Http\Controllers\EmployeTenantController;
use App\Http\Controllers\MouvementInventaireController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\ScanController;
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

    // Produits
    Route::get('/produits', [ProduitController::class, 'index']);

    // Scans
    Route::post('/produit/valider', [ScanController::class, 'validerProduit']);
    Route::post('/scan/enregistrer', [ScanController::class, 'enregistrer']);
    Route::get('/scan/historique', [ScanController::class, 'historique']);
    Route::put('/scan/{id}', [ScanController::class, 'modifier']);
    Route::delete('/scan/{id}', [ScanController::class, 'supprimer']);

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
