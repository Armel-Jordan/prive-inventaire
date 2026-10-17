<?php

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\Facture;
use App\Models\ProduitTenant;
use App\Models\ProduitTraduction;
use App\Services\DocumentsVentePdf;
use App\Services\Portail\CommandesPortail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Portail client, côté commandes : catalogue au prix du client, prix du panier, commandes (passer, suivre,
 * annuler tant qu'elles attendent), factures et documents PDF. Tout est limité au client du compte connecté.
 */
class PortailCommandeController extends Controller
{
    public function __construct(private CommandesPortail $commandes, private DocumentsVentePdf $documents) {}

    public function catalogue(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'recherche' => ['nullable', 'string', 'max:100'],
            'categorie' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            // Langue du visiteur : les descriptions traduites de la fiche produit.
            'langue' => ['nullable', Rule::in(ProduitTraduction::LANGUES)],
        ]);

        return response()->json($this->commandes->catalogue($this->client($request), $filtres['recherche'] ?? null, $filtres['categorie'] ?? null,
            (int) ($filtres['page'] ?? 1), $filtres['langue'] ?? null));
    }

    /** Image principale d'un produit proposé au portail (une image jointe à sa fiche). */
    public function image(Request $request, int $id): StreamedResponse
    {
        $this->client($request);
        $produit = $this->commandes->produitsProposes()->whereKey($id)->whereNotNull('image_id')->with('image')->first();
        $image = $produit?->image;
        abort_unless($image && in_array($image->type_mime, ProduitTenant::TYPES_IMAGE, true), 404);
        $stockage = Storage::disk($image->disque);
        abort_unless($stockage->exists($image->chemin), 404);

        return $stockage->response($image->chemin, null, [
            'Content-Type' => $image->type_mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** Prix du panier (les règles de quantité s'appliquent). */
    public function prix(Request $request): JsonResponse
    {
        $donnees = $request->validate($this->reglesLignes());

        return response()->json(['lignes' => $this->commandes->prix($this->client($request), $this->lignes($donnees))]);
    }

    public function index(Request $request): JsonResponse
    {
        $page = ComClientEntete::where('client_id', $this->client($request)->id)
            ->where('statut', '!=', 'brouillon')
            ->withCount('lignes')
            ->orderByDesc('date_commande')->orderByDesc('id')
            ->paginate(20, ['id', 'numero', 'date_commande', 'date_livraison_souhaitee', 'statut', 'montant_ht', 'montant_ttc', 'portail_compte_id', 'devise']);

        return response()->json($page);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $commande = $this->commandeDuClient($request, $id)->load(['lignes.produit' => fn ($q) => $q->withTrashed()->select(['id', 'numero', 'description', 'mesure'])]);
        $facture = Facture::where('commande_id', $commande->id)->whereNotIn('statut', ['brouillon', 'annulee'])->first(['id', 'numero', 'statut']);

        return response()->json([
            'commande' => $commande->only(['id', 'numero', 'date_commande', 'date_livraison_souhaitee', 'statut', 'motif_refus', 'notes', 'montant_ht', 'montant_tva', 'montant_ttc', 'portail_compte_id', 'devise']) + [
                'lignes' => $commande->lignes->map(fn ($l) => [
                    // produit_id : pour « commander à nouveau ».
                    'produit_id' => $l->produit_id,
                    'produit' => $l->produit?->only(['numero', 'description', 'mesure']),
                    'quantite' => $l->quantite,
                    'prix_unitaire_ht' => (float) $l->prix_unitaire_ht,
                    'montant_ht' => (float) $l->montant_ht,
                    'montant_ttc' => (float) $l->montant_ttc,
                ])->values(),
                'annulable' => $this->annulable($commande),
            ],
            'facture' => $facture,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate($this->reglesLignes() + [
            'date_livraison_souhaitee' => ['nullable', 'date', 'after_or_equal:today'],
            'adresse_livraison_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['date_livraison_souhaitee.after_or_equal' => 'La date de livraison souhaitée ne peut pas être passée.']);
        $commande = $this->commandes->commander($request->attributes->get('portail_compte'), $this->client($request), ['lignes' => $this->lignes($donnees)] + $donnees);

        return response()->json(['commande' => $commande->only(['id', 'numero', 'statut', 'montant_ht', 'montant_ttc'])], 201);
    }

    /** Une commande passée sur le portail s'annule tant que l'entreprise ne l'a pas acceptée. */
    public function annuler(Request $request, int $id): JsonResponse
    {
        $commande = $this->commandeDuClient($request, $id);
        if (! $this->annulable($commande)) {
            return response()->json(['message' => 'Cette commande ne peut plus être annulée : contactez-nous.'], 422);
        }
        $commande->update(['statut' => 'annulee']);

        return response()->json(['message' => "Commande {$commande->numero} annulée."]);
    }

    public function pdfCommande(Request $request, int $id): Response
    {
        $commande = $this->commandeDuClient($request, $id);

        return $this->documents->pdf($this->documents->commande($commande))->stream("commande-{$commande->numero}.pdf");
    }

    public function factures(Request $request): JsonResponse
    {
        $page = Facture::where('client_id', $this->client($request)->id)
            ->whereNotIn('statut', ['brouillon', 'annulee'])
            ->orderByDesc('date_facture')->orderByDesc('id')
            ->paginate(20, ['id', 'numero', 'date_facture', 'date_echeance', 'statut', 'montant_ttc', 'reste_a_payer', 'commande_id', 'devise']);

        return response()->json($page);
    }

    public function pdfFacture(Request $request, int $id): Response
    {
        $facture = Facture::where('client_id', $this->client($request)->id)->whereNotIn('statut', ['brouillon', 'annulee'])->findOrFail($id);

        return $this->documents->pdf($this->documents->facture($facture))->stream("facture-{$facture->numero}.pdf");
    }

    private function client(Request $request): Client
    {
        return Client::findOrFail($request->attributes->get('portail_compte')->client_id);
    }

    private function commandeDuClient(Request $request, int $id): ComClientEntete
    {
        return ComClientEntete::where('client_id', $this->client($request)->id)->where('statut', '!=', 'brouillon')->findOrFail($id);
    }

    private function annulable(ComClientEntete $commande): bool
    {
        return $commande->statut === 'en_attente' && $commande->portail_compte_id !== null;
    }

    private function reglesLignes(): array
    {
        return [
            'lignes' => ['required', 'array', 'min:1', 'max:200'],
            'lignes.*.produit_id' => ['required', 'integer'],
            'lignes.*.quantite' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /** @return list<array{produit_id: int, quantite: int}> */
    private function lignes(array $donnees): array
    {
        return array_values(array_map(fn ($l) => ['produit_id' => (int) $l['produit_id'], 'quantite' => (int) $l['quantite']], $donnees['lignes']));
    }
}
