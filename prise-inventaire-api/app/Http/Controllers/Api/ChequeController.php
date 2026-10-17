<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Cheques;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Chèques des déboursés payés par chèque : numérotation (chéquier du compte bancaire) et impression. */
class ChequeController extends Controller
{
    public function __construct(private readonly Cheques $cheques) {}

    /** Numérote les chèques de déboursés : le prochain numéro du chéquier, ou leur référence pour une réimpression. */
    public function numeroter(Request $request): JsonResponse
    {
        $donnees = $request->validate(['debourses' => 'required|array|min:1|max:200', 'debourses.*' => 'integer']);

        return response()->json(['cheques' => $this->cheques->numeroter(array_map('intval', $donnees['debourses']), $request->user()?->id)]);
    }

    /** Les chèques numérotés, un par page : debourses=1,2,3 ; langue du montant en lettres (fr, en). */
    public function pdf(Request $request): Response
    {
        $donnees = $request->validate(['debourses' => 'required|string|max:3000', 'langue' => 'nullable|in:fr,en']);
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $donnees['debourses'])))));
        if ($ids === [] || count($ids) > 200) {
            throw ValidationException::withMessages(['debourses' => 'Choisissez de 1 à 200 déboursés.']);
        }

        return $this->cheques->pdf($ids, $donnees['langue'] ?? 'fr')->stream(count($ids) === 1 ? 'cheque.pdf' : 'cheques.pdf');
    }
}
