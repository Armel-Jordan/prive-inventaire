<?php

namespace App\Http\Controllers;

use App\Models\ProduitMobile;
use Illuminate\Http\JsonResponse;

class ProduitController extends Controller
{
    public function index(): JsonResponse
    {
        $produits = ProduitMobile::select('NUMERO', 'DESCRIPTION', 'MESURE', 'TYPE')
            ->orderBy('NUMERO')
            ->get();

        return response()->json($produits);
    }
}
