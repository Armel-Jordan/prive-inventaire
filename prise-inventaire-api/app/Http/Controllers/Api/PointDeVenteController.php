<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Facture;
use App\Services\Caisses;
use App\Services\ClientsDePassage;
use App\Services\RecuVentePdf;
use App\Services\VentesComptoir;
use App\Support\Droits;
use App\Support\TenantRule;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Point de vente : recherche des produits, aperçu de la vente (prix du client et taxes), vente au comptoir (facture
 * créée, émise et encaissée d'un coup) et reçu. Les règles sont dans App\Services\VentesComptoir.
 */
class PointDeVenteController extends Controller
{
    public function __construct(private readonly VentesComptoir $ventes) {}

    /** Produits actifs dont le numéro ou la désignation contient la saisie (numéro exact d'abord). */
    public function produits(Request $request): JsonResponse
    {
        return response()->json($this->ventes->produits((string) $request->query('recherche', '')));
    }

    /** Aperçu : prix de chaque ligne et totaux avec les taxes, rien n'est enregistré. */
    public function calculer(Request $request): JsonResponse
    {
        $donnees = $request->validate($this->reglesLignes(false) + [
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        return response()->json($this->ventes->calculer($donnees['client_id'] ?? null, $donnees['lignes'], $donnees['date'] ?? today()->toDateString()));
    }

    /** Vente au comptoir : il faut aussi les droits de créer, d'émettre et d'encaisser des factures. */
    public function vendre(Request $request, Droits $droits): JsonResponse
    {
        $donnees = $request->validate($this->reglesLignes(true) + [
            // L'application compte en UTC : un jour de marge laisse passer la date du jour de l'utilisateur, quel que soit son fuseau.
            'date' => 'required|date_format:Y-m-d|before_or_equal:tomorrow',
            'paiements' => 'present|array|max:20',
            'paiements.*.mode' => ['required', Rule::in(Caisses::MODES)],
            'paiements.*.montant' => 'required|numeric|min:0.01|max:9999999999',
            'paiements.*.reference' => 'nullable|string|max:100',
            // Caisse du poste : les paiements entrent dans sa session ouverte (refusés si elle est fermée).
            'caisse_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:2000',
            ...ClientsDePassage::regles(),
        ], ['lignes.required' => 'Ajoutez au moins un produit.', 'lignes.min' => 'Ajoutez au moins un produit.']);
        foreach (array_filter(['creer' => true, 'emettre' => true, 'encaisser' => $donnees['paiements'] !== []]) as $action => $requis) {
            if (! $droits->permet($request->user(), 'factures', $action)) {
                return response()->json(['message' => $droits->messageRefus('factures', $action), 'module' => 'factures', 'action' => $action], 403);
            }
        }

        try {
            $vente = $this->ventes->vendre($donnees, $request->user()?->id, $request->user()->tenant_id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $facture = $vente['facture'];

        return response()->json($vente + [
            'message' => "Vente {$facture->numero} enregistrée.".((float) $facture->reste_a_payer > 0.004 ? ' Le reste est porté au compte du client.' : ''),
        ], 201);
    }

    /** Reçu (ticket de 80 mm) d'une facture émise, heure dans le fuseau de l'utilisateur. */
    public function recu(Request $request, int $factureId, RecuVentePdf $recus): Response
    {
        $facture = Facture::findOrFail($factureId);
        if ($facture->statut === 'brouillon') {
            return response()->json(['message' => "Une facture brouillon n'a pas de reçu."], 422);
        }
        $fuseau = (string) $request->query('fuseau', '');

        return $recus->recu($facture, in_array($fuseau, timezone_identifiers_list(), true) ? $fuseau : config('app.timezone'))
            ->stream("recu-{$facture->numero}.pdf");
    }

    /** Client (de passage sans choix) et lignes de produits ; à la vente, au moins une ligne. */
    private function reglesLignes(bool $vente): array
    {
        return [
            'client_id' => ['nullable', 'integer', TenantRule::exists('clients')],
            'lignes' => $vente ? 'required|array|min:1|max:200' : 'present|array|max:200',
            'lignes.*.produit_id' => ['required', 'integer', TenantRule::exists('produits')],
            'lignes.*.quantite' => 'required|integer|min:1|max:999999',
            'lignes.*.prix_unitaire_ht' => 'nullable|numeric|min:0|max:9999999999',
        ];
    }
}
