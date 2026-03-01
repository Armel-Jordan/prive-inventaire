<?php

use App\Http\Controllers\EmployeController;
use App\Http\Controllers\MouvementInventaireController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SecteurController;
use Illuminate\Support\Facades\Route;

Route::get('/employes', [EmployeController::class, 'index']);
Route::get('/produits', [ProduitController::class, 'index']);

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
