<?php

namespace App\Services\Portail;

use App\Models\Client;
use App\Models\ClientAdresse;
use App\Models\ClientProduit;
use App\Models\ComClientEntete;
use App\Models\ComClientLigne;
use App\Models\Configuration;
use App\Models\Notification;
use App\Models\PortailCompte;
use App\Models\PortailParametres;
use App\Models\ProduitTenant;
use App\Models\TenantParametres;
use App\Services\CalculPrix;
use App\Services\EntetesVente;
use App\Services\TaxesDocument;
use App\Support\NumeroCommandeClient;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catalogue et commandes du portail client. Les prix sont ceux du client (CalculPrix : liste de prix, remise,
 * règles de quantité), toujours recalculés ici : un prix envoyé par le navigateur n'est jamais repris. Une
 * commande arrive « en attente » : l'entreprise l'accepte ou la refuse comme toute commande client.
 */
class CommandesPortail
{
    public function __construct(private CalculPrix $calcul, private TaxesDocument $taxes) {}

    /** Produits proposés au portail : actifs, dans les catégories choisies par l'entreprise. */
    public function produitsProposes(): Builder
    {
        $categories = PortailParametres::courants()->categories;

        return ProduitTenant::query()->where('actif', true)
            // Nature du produit (socle S2) : vendu et visible dans l'espace client.
            ->where('vendu', true)->where('visible_portail', true)
            ->when($categories, fn (Builder $q) => $q->whereIn('categorie', $categories));
    }

    /**
     * Une page du catalogue, au prix du client (quantité 1), dans la langue du visiteur (descriptions traduites de la
     * fiche produit, sinon celles de la fiche), avec la description longue, le fabricant et l'image s'il y en a une.
     */
    public function catalogue(Client $client, ?string $recherche, ?string $categorie, int $page, ?string $langue = null): array
    {
        $produits = $this->produitsProposes()
            ->when($categorie, fn (Builder $q) => $q->where('categorie', $categorie))
            ->when($recherche, fn (Builder $q) => $q->where(fn (Builder $r) => $r->where('description', 'like', "%{$recherche}%")->orWhere('numero', 'like', "%{$recherche}%")
                ->orWhereHas('traductions', fn (Builder $t) => $t->where('description', 'like', "%{$recherche}%"))
                // Sa propre référence du produit (phase 1.2c).
                ->orWhereIn('id', ClientProduit::where('client_id', $client->id)->where('reference', 'like', "%{$recherche}%")->select('produit_id'))))
            ->with(['traductions', 'fabricant:id,nom'])
            ->orderBy('description')->orderBy('numero')
            ->paginate(24, ['id', 'numero', 'description', 'mesure', 'categorie', 'description_longue', 'fabricant_id', 'reference_fabricant', 'image_id'], 'page', $page);
        $prix = collect($this->calcul->calculer($client, $produits->getCollection()->map(fn (ProduitTenant $p) => ['produit_id' => $p->id, 'quantite' => 1])->all(), now()))
            ->keyBy('produit_id');
        $references = ClientProduit::pourClient($client->id, $produits->getCollection()->pluck('id')->all());

        return [
            'data' => $produits->getCollection()->map(fn (ProduitTenant $p) => $p->only(['id', 'numero', 'mesure', 'categorie', 'reference_fabricant']) + [
                'description' => $p->descriptionEn($langue),
                'description_longue' => $p->descriptionEn($langue, 'description_longue'),
                'fabricant' => $p->fabricant?->nom,
                'image' => $p->image_id !== null,
                'prix' => $prix[$p->id]['prix'] ?? null,
                'prix_liste' => $prix[$p->id]['prix_liste'] ?? null,
                'reference_client' => $references[$p->id]['reference'] ?? null,
            ])->values(),
            'current_page' => $produits->currentPage(),
            'last_page' => $produits->lastPage(),
            'total' => $produits->total(),
            'categories' => $this->produitsProposes()->whereNotNull('categorie')->where('categorie', '!=', '')
                ->distinct()->orderBy('categorie')->pluck('categorie')->values(),
        ];
    }

    /**
     * Prix du panier pour ce client (les règles de quantité s'appliquent). Les produits qui ne sont plus
     * proposés sont signalés « indisponible ».
     *
     * @param  list<array{produit_id: int, quantite: int}>  $lignes
     */
    public function prix(Client $client, array $lignes): array
    {
        $proposes = $this->produitsProposes()->whereIn('id', array_column($lignes, 'produit_id'))->get(['id', 'numero', 'description', 'mesure'])->keyBy('id');
        $prix = collect($this->calcul->calculer($client, $lignes, now()))->keyBy('produit_id');
        $references = ClientProduit::pourClient($client->id, array_column($lignes, 'produit_id'));

        return array_map(function (array $ligne) use ($proposes, $prix, $references) {
            $produit = $proposes->get($ligne['produit_id']);
            $unitaire = $produit ? ($prix[$produit->id]['prix'] ?? null) : null;

            return [
                'produit_id' => (int) $ligne['produit_id'],
                'quantite' => (int) $ligne['quantite'],
                'numero' => $produit?->numero,
                'description' => $produit?->description,
                'mesure' => $produit?->mesure,
                'reference_client' => $references[(int) $ligne['produit_id']]['reference'] ?? null,
                'prix' => $unitaire,
                'montant' => $unitaire !== null ? round($unitaire * $ligne['quantite'], 2) : null,
                'indisponible' => ! $produit || $unitaire === null,
            ];
        }, $lignes);
    }

    /**
     * Crée la commande du client, en attente de validation par l'entreprise, et prévient l'entreprise.
     *
     * @param  array{lignes: list<array{produit_id: int, quantite: int}>, date_livraison_souhaitee?: ?string, adresse_livraison_id?: ?int, notes?: ?string}  $donnees
     *
     * @throws ValidationException
     */
    public function commander(PortailCompte $compte, Client $client, array $donnees): ComClientEntete
    {
        $tenantId = (int) app(TenantContext::class)->getTenantId();
        $config = Configuration::pourEntite('commande', $tenantId);
        if (! $config || ! $config->auto_increment) {
            throw ValidationException::withMessages(['lignes' => __('Les commandes en ligne ne sont pas encore prêtes chez nous : contactez-nous pour passer votre commande.')]);
        }

        $lignes = $this->prix($client, $donnees['lignes']);
        foreach ($lignes as $index => $ligne) {
            if ($ligne['indisponible']) {
                throw ValidationException::withMessages(["lignes.{$index}" => __("Un produit de votre panier n'est plus proposé : retirez-le pour commander.")]);
            }
        }

        $adresse = null;
        if (! empty($donnees['adresse_livraison_id'])) {
            $adresse = ClientAdresse::where('client_id', $client->id)->where('actif', true)->find($donnees['adresse_livraison_id'])
                ?? throw ValidationException::withMessages(['adresse_livraison_id' => __('Adresse de livraison inconnue.')]);
        }
        $notes = array_filter([
            'Commande passée sur le portail client par '.$compte->nom.' ('.$compte->email.').',
            $adresse ? 'Livraison : '.trim(($adresse->libelle ? $adresse->libelle.' — ' : '').$adresse->adresse.', '.$adresse->code_postal.' '.$adresse->ville) : null,
            trim((string) ($donnees['notes'] ?? '')) ?: null,
        ]);
        // Taux de taxe par défaut de l'entreprise (paramètres), comme les autres documents de vente.
        $taux = TenantParametres::query()->value('tva_taux');
        $taux = $taux !== null ? (float) $taux : (float) ComClientLigne::TAUX_TVA_DEFAUT;
        $groupe = $this->taxes->groupePour([]);

        $commande = DB::transaction(function () use ($compte, $client, $donnees, $tenantId, $lignes, $notes, $taux, $groupe) {
            // Le représentant du client suit sa commande.
            $commande = ComClientEntete::create(app(EntetesVente::class)->pourCreation([], $client) + [
                'tenant_id' => $tenantId,
                'numero' => Configuration::consommerNumero('commande', $tenantId),
                'client_id' => $client->id,
                'date_commande' => today()->toDateString(),
                'date_livraison_souhaitee' => $donnees['date_livraison_souhaitee'] ?? null,
                'numero_commande_client' => NumeroCommandeClient::valeur($donnees['numero_commande_client'] ?? null),
                'remise_globale' => 0,
                'notes' => implode("\n", $notes),
                'statut' => 'en_attente',
            ]);
            $commande->forceFill(['portail_compte_id' => $compte->id])->save();

            foreach ($lignes as $donneesLigne) {
                $ligne = new ComClientLigne([
                    'produit_id' => $donneesLigne['produit_id'],
                    'quantite' => $donneesLigne['quantite'],
                    'prix_unitaire_ht' => $donneesLigne['prix'],
                    'taux_tva' => $taux,
                    'remise_ligne' => 0,
                ]);
                $ligne->calculerMontants();
                $detail = $this->taxes->appliquer($ligne, $groupe);
                $commande->lignes()->save($ligne);
                $this->taxes->enregistrer('commande_client', $ligne->id, $detail);
            }
            $commande->load('lignes');
            $commande->calculerMontants();
            $commande->save();

            return $commande;
        });

        $devise = (string) TenantParametres::query()->value('devise_symbole');
        Notification::create([
            'type' => 'commande_portail',
            'titre' => 'Nouvelle commande du portail client',
            'message' => "{$client->raison_sociale} a passé la commande {$commande->numero} (".number_format((float) $commande->montant_ttc, 2, ',', ' ').($devise !== '' ? " {$devise}" : '').') : à accepter ou refuser.',
            'lien' => '/commandes-client',
            'data' => ['commande_id' => $commande->id, 'client_id' => $client->id],
        ]);

        return $commande;
    }
}
