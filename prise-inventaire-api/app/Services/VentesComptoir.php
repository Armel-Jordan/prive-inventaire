<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Configuration;
use App\Models\FacturePaiement;
use App\Models\ProduitTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vente au comptoir (plan de parité fripv2, phase 1.10b), comme la facturation sur un poste de caisse et la fenêtre
 * « Payer facture » de fripv2 :
 * - les produits sont au prix du client (liste, remise, règles), converti dans sa devise, sauf prix saisi ; sans client
 *   choisi, la vente va au client de passage ;
 * - la vente est une facture créée, émise et encaissée d'un coup, sur la caisse du poste s'il en a une ;
 * - le paiement se répartit entre plusieurs modes ; seules les espèces peuvent dépasser ce qui reste dû : l'excédent est
 *   la monnaie rendue, et seul ce qui est dû est encaissé (l'argent remis est noté pour le reçu) ;
 * - le client de passage paie tout, et un paiement en espèces règle tout ; un client en compte peut ne payer qu'une
 *   partie, ou rien : le reste demeure à recevoir ;
 * - une carte cadeau paie jusqu'à son solde, comme un mode qui ne peut pas dépasser ; elle n'apporte pas d'argent dans la
 *   caisse (une vente payée seulement par carte cadeau n'a pas besoin de caisse ouverte).
 */
class VentesComptoir
{
    private const TOLERANCE = 0.005;

    public function __construct(
        private readonly FacturesVente $factures,
        private readonly CalculPrix $prix,
        private readonly Caisses $caisses,
        private readonly DevisesVente $devises,
        private readonly ClientsDePassage $passage,
        private readonly CartesCadeaux $cartes,
    ) {}

    /** Produits actifs à vendre : le numéro exact d'abord (lecture d'un code), puis ceux qui commencent par la saisie ou la contiennent. */
    public function produits(string $recherche, int $limite = 20): Collection
    {
        $recherche = trim($recherche);
        if ($recherche === '') {
            return collect();
        }
        $motif = addcslashes($recherche, '%_\\');

        return ProduitTenant::where('actif', true)
            ->where(fn ($q) => $q->where('numero', 'like', "%{$motif}%")->orWhere('description', 'like', "%{$motif}%"))
            ->orderByRaw('CASE WHEN numero = ? THEN 0 WHEN numero LIKE ? THEN 1 ELSE 2 END', [$recherche, "{$motif}%"])
            ->orderBy('numero')
            ->limit($limite)
            ->get(['id', 'numero', 'description', 'mesure', 'prix_unitaire']);
    }

    /**
     * Aperçu de la vente : prix de chaque ligne (celui du client, sauf prix saisi) et totaux avec les taxes, sans rien
     * enregistrer. Une ligne sans prix est signalée (`sans_prix`) et reste hors des totaux.
     *
     * @param  list<array{produit_id: int, quantite: int, prix_unitaire_ht?: mixed}>  $lignes
     */
    public function calculer(?int $clientId, array $lignes, string $date): array
    {
        $client = $this->client($clientId);
        $devise = $this->devises->pourDocument(null, null, $client);
        $lignes = $this->auPrix($client, $devise, $lignes, $date, false);
        $avecPrix = array_values(array_filter($lignes, fn (array $l) => $l['prix_unitaire_ht'] !== null));
        $calcul = $this->factures->calculerLignes($avecPrix);
        $montants = $calcul['lignes'];
        $i = 0;

        return [
            'client' => $client->only(['id', 'code', 'raison_sociale', 'de_passage']),
            'devise' => $devise['devise'],
            'lignes' => array_map(function (array $l) use ($montants, &$i) {
                if ($l['prix_unitaire_ht'] === null) {
                    return $l + ['sans_prix' => true, 'montant_ht' => null, 'montant_tva' => null, 'montant_ttc' => null];
                }
                $montant = $montants[$i++];

                return $l + ['sans_prix' => false, 'montant_ht' => round($montant['ht'], 2), 'montant_tva' => round($montant['tvaM'], 2), 'montant_ttc' => round($montant['ttc'], 2)];
            }, $lignes),
            'montant_ht' => round($calcul['montant_ht'], 2),
            'montant_tva' => round($calcul['montant_tva'], 2),
            'montant_ttc' => round($calcul['montant_ttc'], 2),
        ];
    }

    /**
     * Enregistre la vente : facture créée et émise, paiements encaissés sur la session ouverte de la caisse du poste.
     *
     * @param  array{client_id?: ?int, date: string, lignes: list<array{produit_id: int, quantite: int, prix_unitaire_ht?: mixed}>, paiements?: list<array{mode: string, montant: float|string, reference?: ?string, carte_cadeau?: ?string}>, caisse_id?: ?int, notes?: ?string, passage_nom?: ?string, passage_telephone?: ?string, passage_courriel?: ?string, passage_adresse?: ?string}  $donnees
     * @return array{facture: \App\Models\Facture, monnaie: float}
     */
    public function vendre(array $donnees, ?int $auteur, int $tenantId): array
    {
        $config = Configuration::pourEntite('facture', $tenantId);
        if (! $config || ! $config->auto_increment) {
            throw ValidationException::withMessages(['facture' => 'La numérotation automatique des factures doit être active.']);
        }
        $client = $this->client($donnees['client_id'] ?? null);
        $devise = $this->devises->pourDocument(null, null, $client);
        $lignes = $this->auPrix($client, $devise, $donnees['lignes'], $donnees['date'], true);

        return DB::transaction(function () use ($donnees, $auteur, $tenantId, $client, $devise, $lignes) {
            // La caisse du poste d'abord, si de l'argent est reçu : fermée, rien n'est créé (pas même un numéro de facture).
            $argent = collect($donnees['paiements'] ?? [])->contains(fn (array $p) => $p['mode'] !== 'carte_cadeau');
            $session = $argent ? $this->caisses->sessionPourEncaissement($donnees['caisse_id'] ?? null) : null;
            $facture = $this->factures->creer($devise + array_intersect_key($donnees, array_flip(ClientsDePassage::CHAMPS)) + [
                'client_id' => $client->id,
                'date_facture' => $donnees['date'],
                'notes' => $donnees['notes'] ?? null,
                'lignes' => array_map(fn (array $l) => array_intersect_key($l, array_flip(['produit_id', 'quantite', 'prix_unitaire_ht'])), $lignes),
            ], $tenantId);
            $this->factures->emettre($facture, $auteur);
            $reglement = $this->repartir($donnees['paiements'] ?? [], round((float) $facture->fresh()->reste_a_payer, 2), (bool) $client->de_passage, $devise['devise']);
            foreach ($reglement['paiements'] as $paiement) {
                // Carte cadeau : vérifiée et verrouillée, le paiement ne passe pas par la caisse.
                $carte = isset($paiement['carte']) ? $this->cartes->pourPaiement($paiement['carte'], $devise['devise'], $paiement['montant'], $donnees['date']) : null;
                $enregistre = FacturePaiement::create(array_diff_key($paiement, ['carte' => true]) + [
                    'facture_id' => $facture->id,
                    'date_paiement' => $donnees['date'],
                    'enregistre_par' => $auteur,
                    'caisse_session_id' => $carte ? null : $session,
                    'carte_cadeau_id' => $carte?->id,
                ]);
                if ($carte) {
                    $this->cartes->utiliser($carte, $enregistre, $auteur);
                }
            }

            return [
                'facture' => $facture->fresh(['client:id,code,raison_sociale,de_passage', 'lignes.produit:id,numero,description', 'paiements']),
                'monnaie' => $reglement['monnaie'],
            ];
        });
    }

    /** Client de la vente : celui choisi, sinon le client de passage. */
    private function client(?int $clientId): Client
    {
        return $clientId ? Client::findOrFail($clientId) : $this->passage->client();
    }

    /**
     * Lignes au prix du client, converti dans la devise de la vente, sauf prix saisi. À la vente, une ligne sans prix est
     * refusée.
     *
     * @return list<array{produit_id: int, quantite: int, prix_unitaire_ht: ?float, origine: ?string, produit: ?array}>
     */
    private function auPrix(Client $client, array $devise, array $lignes, string $date, bool $exiger): array
    {
        $calcules = $this->prix->calculer($client, array_map(fn (array $l) => ['produit_id' => (int) $l['produit_id'], 'quantite' => (int) $l['quantite']], $lignes), Carbon::parse($date));
        $taux = (float) ($devise['taux_change'] ?: 1);
        $produits = ProduitTenant::withTrashed()->whereIn('id', array_column($lignes, 'produit_id'))->get(['id', 'numero', 'description', 'mesure'])->keyBy('id');

        return array_map(function (array $ligne, array $calcule) use ($taux, $produits, $exiger) {
            $saisi = array_key_exists('prix_unitaire_ht', $ligne) && $ligne['prix_unitaire_ht'] !== null && $ligne['prix_unitaire_ht'] !== '';
            $prix = $saisi ? round((float) $ligne['prix_unitaire_ht'], 2)
                : (($calcule['prix'] ?? null) !== null ? round((float) $calcule['prix'] / $taux, 2) : null);
            $produit = $produits->get((int) $ligne['produit_id']);
            if ($prix === null && $exiger) {
                throw ValidationException::withMessages(['lignes' => 'Aucun prix pour '.($produit?->numero ?? "#{$ligne['produit_id']}").' : saisissez-le.']);
            }

            return [
                'produit_id' => (int) $ligne['produit_id'],
                'quantite' => (int) $ligne['quantite'],
                'prix_unitaire_ht' => $prix,
                'origine' => $saisi ? 'saisi' : ($calcule['origine'] ?? null),
                'produit' => $produit?->only(['id', 'numero', 'description', 'mesure']),
            ];
        }, $lignes, $calcules);
    }

    /**
     * Répartit le paiement : chaque mode garde son montant, sauf les espèces, qui n'encaissent que ce qui reste dû ;
     * l'excédent d'espèces est la monnaie rendue.
     *
     * Une carte cadeau compte comme un mode qui ne peut pas dépasser ; ses lignes sont regroupées par carte (enregistrées en
     * mode « autre », rattachées à la carte).
     *
     * @param  list<array{mode: string, montant: float|string, reference?: ?string, carte_cadeau?: ?string}>  $paiements
     * @return array{paiements: list<array{mode_paiement: string, montant: float, reference: ?string, montant_recu: ?float, carte?: string}>, monnaie: float}
     */
    private function repartir(array $paiements, float $du, bool $dePassage, ?string $devise): array
    {
        $especes = round(array_sum(array_map(fn (array $p) => $p['mode'] === 'especes' ? (float) $p['montant'] : 0.0, $paiements)), 2);
        $autres = round(array_sum(array_map(fn (array $p) => $p['mode'] !== 'especes' ? (float) $p['montant'] : 0.0, $paiements)), 2);
        if ($autres > $du + self::TOLERANCE) {
            throw ValidationException::withMessages(['paiements' => 'Seules les espèces peuvent dépasser le montant dû (la monnaie est rendue) : ramenez les autres modes à '.$this->montant($du, $devise).' au plus.']);
        }
        $manque = round($du - $especes - $autres, 2);
        if ($manque > self::TOLERANCE && $dePassage) {
            throw ValidationException::withMessages(['paiements' => 'Le client de passage paie toute la vente : il manque '.$this->montant($manque, $devise).'.']);
        }
        if ($manque > self::TOLERANCE && $especes > 0) {
            throw ValidationException::withMessages(['paiements' => 'Un paiement en espèces règle toute la vente (la monnaie est rendue) : il manque '.$this->montant($manque, $devise).'.']);
        }
        $monnaie = max(0.0, round($especes + $autres - $du, 2));
        $resultat = [];
        $cartes = [];
        foreach ($paiements as $paiement) {
            if ($paiement['mode'] === 'carte_cadeau') {
                $numero = mb_strtoupper(trim((string) ($paiement['carte_cadeau'] ?? '')));
                $cartes[$numero] = round(($cartes[$numero] ?? 0) + (float) $paiement['montant'], 2);
            } elseif ($paiement['mode'] !== 'especes') {
                $resultat[] = ['mode_paiement' => $paiement['mode'], 'montant' => round((float) $paiement['montant'], 2), 'reference' => $paiement['reference'] ?? null, 'montant_recu' => null];
            }
        }
        foreach ($cartes as $numero => $montant) {
            $resultat[] = ['mode_paiement' => 'autre', 'montant' => $montant, 'reference' => $numero, 'montant_recu' => null, 'carte' => (string) $numero];
        }
        if ($especes - $monnaie > self::TOLERANCE) {
            $resultat[] = ['mode_paiement' => 'especes', 'montant' => round($especes - $monnaie, 2), 'reference' => null, 'montant_recu' => $especes];
        }

        return ['paiements' => $resultat, 'monnaie' => $monnaie];
    }

    private function montant(float $valeur, ?string $devise): string
    {
        return number_format($valeur, 2, ',', ' ').' '.$this->devises->symbole($devise);
    }
}
