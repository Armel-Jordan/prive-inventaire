<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComClientEntete;
use App\Models\Devis;
use App\Models\Facture;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * « Copier vers » (plan de parité fripv2, socle S4.3) : `GET /{documents}/{id}/copie?vers=devis|commande|facture`
 * prépare un nouveau document du type demandé, prérempli avec celui-ci (client, en-tête, lignes et prix), sans rien
 * enregistrer : l'écran du type cible l'ouvre en brouillon, et c'est son enregistrement habituel qui le crée (avec le
 * droit de créer ce type). Les quantités livrées ou facturées, les statuts et les numéros ne suivent pas.
 */
class CopieDocumentController extends Controller
{
    /** Type de document (valeur par défaut de la route) => modèle. */
    private const SOURCES = [
        'devis' => Devis::class,
        'commande_client' => ComClientEntete::class,
        'facture' => Facture::class,
    ];

    /** Champs d'en-tête repris tels quels. */
    private const ENTETE = [
        'client_id', 'notes', 'devise', 'taux_change', 'representant_id', 'transporteur_id', 'groupe_taxe_id', 'condition_paiement_id',
        'contact_id', 'adresse_livraison_id', 'livraison_adresse', 'instructions_livraison', 'numero_commande_client',
        'passage_nom', 'passage_telephone', 'passage_courriel', 'passage_adresse',
    ];

    public function preparer(Request $request, int $id): JsonResponse
    {
        $modele = self::SOURCES[(string) $request->route('document')] ?? abort(404);
        $vers = $request->validate(['vers' => ['required', Rule::in(['devis', 'commande', 'facture'])]])['vers'];
        /** @var Model $source */
        $source = $modele::with('lignes')->findOrFail($id);

        $entete = [];
        foreach (self::ENTETE as $champ) {
            if ($source->offsetExists($champ) || array_key_exists($champ, $source->getAttributes())) {
                $entete[$champ] = $source->getAttribute($champ);
            }
        }
        // La date requise : celle du document, ou la livraison souhaitée d'une commande.
        $dateRequise = $source->getAttribute('date_requise') ?? $source->getAttribute('date_livraison_souhaitee');
        $entete[$vers === 'commande' ? 'date_livraison_souhaitee' : 'date_requise'] = $dateRequise?->toDateString();

        return response()->json($entete + [
            'copie_de' => ['type' => (string) $request->route('document'), 'id' => $source->getKey(), 'numero' => $source->getAttribute('numero')],
            'lignes' => $source->lignes->map(fn (Model $ligne) => $this->ligne($ligne, $vers))->values(),
        ]);
    }

    /**
     * Ligne du document cible : même produit, quantité et unité, au prix net (la remise de la ligne y est comprise, les
     * formulaires n'ayant pas de remise par ligne) ; les taxes se recalculent à l'enregistrement.
     */
    private function ligne(Model $ligne, string $vers): array
    {
        $prix = (float) ($ligne->getAttribute('prix_unitaire_ht') ?? $ligne->getAttribute('prix_unitaire'));
        $net = round($prix * (1 - (float) ($ligne->getAttribute('remise_ligne') ?? 0) / 100), 4);

        return [
            'produit_id' => $ligne->getAttribute('produit_id'),
            'quantite' => $ligne->getAttribute('quantite'),
            'unite' => $ligne->getAttribute('unite'),
            'facteur' => $ligne->getAttribute('facteur'),
            ($vers === 'devis' ? 'prix_unitaire' : 'prix_unitaire_ht') => $net,
        ];
    }
}
