<?php

namespace App\Http\Controllers;

use App\Models\Employe;
use Illuminate\Http\JsonResponse;

class EmployeController extends Controller
{
    public function index(): JsonResponse
    {
        $employes = Employe::select('NUMERO', 'NOM')
            ->orderBy('NOM')
            ->get();

        return response()->json($employes);
    }
}
