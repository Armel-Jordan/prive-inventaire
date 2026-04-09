<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComFourEntete;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class BonCommandePdfController extends Controller
{
    private function authenticateFromToken(Request $request): bool
    {
        $token = $request->query('token');
        if (!$token) {
            return false;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken || !$accessToken->tokenable) {
            return false;
        }

        auth()->setUser($accessToken->tokenable);
        return true;
    }

    public function generate(Request $request, $id): Response|\Illuminate\Http\JsonResponse
    {
        if (!$this->authenticateFromToken($request)) {
            return response()->json(['message' => 'Non autorisé'], 401);
        }

        $commande = ComFourEntete::findOrFail($id);
        $commande->load(['fournisseur', 'lignes.produit', 'createdBy']);

        $pdf = Pdf::loadView('pdf.bon-commande', ['commande' => $commande]);
        $pdf->setPaper('A4', 'portrait');

        return $pdf->download("bon-commande-{$commande->numero}.pdf");
    }

    public function preview(Request $request, $id): Response|\Illuminate\Http\JsonResponse
    {
        if (!$this->authenticateFromToken($request)) {
            return response()->json(['message' => 'Non autorisé'], 401);
        }

        $commande = ComFourEntete::findOrFail($id);
        $commande->load(['fournisseur', 'lignes.produit', 'createdBy']);

        $pdf = Pdf::loadView('pdf.bon-commande', ['commande' => $commande]);
        $pdf->setPaper('A4', 'portrait');

        return $pdf->stream("bon-commande-{$commande->numero}.pdf");
    }
}
