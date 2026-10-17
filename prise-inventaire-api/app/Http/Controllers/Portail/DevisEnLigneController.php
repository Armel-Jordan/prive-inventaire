<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Devis;
use App\Services\DocumentsVentePdf;
use App\Services\ReponsesDevis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Page publique d'un devis, ouverte par le lien de son courriel (plan de parité fripv2, phase 1.3) : le client le
 * consulte, télécharge le PDF, l'accepte ou le refuse, sans compte. Le lien seul donne accès à ce devis.
 */
class DevisEnLigneController extends Controller
{
    public function __construct(private readonly ReponsesDevis $reponses) {}

    public function show(string $jeton): JsonResponse
    {
        $devis = $this->reponses->devisDuLien($jeton);

        return $devis ? response()->json($this->reponses->presenter($devis)) : $this->introuvable();
    }

    public function pdf(string $jeton, DocumentsVentePdf $documents): Response|JsonResponse
    {
        $devis = $this->reponses->devisDuLien($jeton);

        return $devis ? $documents->pdf($documents->devis($devis))->download("devis-{$devis->numero}.pdf") : $this->introuvable();
    }

    public function accepter(Request $request, string $jeton): JsonResponse
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'commentaire' => ['nullable', 'string', 'max:2000'],
        ], ['nom.required' => 'Indiquez votre nom pour accepter le devis.']);

        return $this->repondre($jeton, true, $donnees);
    }

    public function refuser(Request $request, string $jeton): JsonResponse
    {
        $donnees = $request->validate([
            'nom' => ['nullable', 'string', 'max:150'],
            'commentaire' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->repondre($jeton, false, $donnees);
    }

    private function repondre(string $jeton, bool $accepte, array $donnees): JsonResponse
    {
        $devis = $this->reponses->devisDuLien($jeton);
        if (! $devis) {
            return $this->introuvable();
        }
        $refus = $this->reponses->repondre($devis, $accepte, $donnees['nom'] ?? null, $donnees['commentaire'] ?? null);
        $devis = Devis::with(['client', 'lignes.produit'])->findOrFail($devis->id);

        return $refus
            ? response()->json(['message' => $refus] + $this->reponses->presenter($devis), 422)
            : response()->json(['message' => $accepte ? 'Merci : le devis est accepté.' : 'Votre refus est enregistré.'] + $this->reponses->presenter($devis));
    }

    private function introuvable(): JsonResponse
    {
        return response()->json(['message' => 'Ce lien ne correspond à aucun devis.'], 404);
    }
}
