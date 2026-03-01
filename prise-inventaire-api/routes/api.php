<?php

use App\Http\Controllers\EmployeController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\ScanController;
use Illuminate\Support\Facades\Route;

Route::get('/employes', [EmployeController::class, 'index']);
Route::get('/produits', [ProduitController::class, 'index']);

Route::post('/produit/valider', [ScanController::class, 'validerProduit']);
Route::post('/scan/enregistrer', [ScanController::class, 'enregistrer']);
Route::get('/scan/historique', [ScanController::class, 'historique']);
Route::put('/scan/{id}', [ScanController::class, 'modifier']);
Route::delete('/scan/{id}', [ScanController::class, 'supprimer']);
