<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Frais;
use Illuminate\Support\Collection;

/**
 * Frais additionnels et de livraison d'un document de vente (plan de parité fripv2, phase 1.4), comme les frais que
 * fripv2 ajoute d'eux-mêmes aux devis, commandes et factures :
 *
 * - Seuls les frais actifs du type de document, dont le produit existe encore, sont proposés.
 * - Les lignes de marchandise déclenchent les frais ; les lignes d'un produit de frais n'en déclenchent pas d'autres.
 * - Frais « document » : dès qu'il y a une ligne ; « produits » : seulement pour les lignes de ses produits ;
 *   « livraison » : le frais de livraison choisi dans la fiche du client.
 * - Montant fixe (dans la devise de l'entreprise, converti dans celle du document), une fois par document ou une fois
 *   par ligne concernée ; ou pourcentage du montant HT des lignes concernées.
 * - Un frais dont le produit est déjà sur le document n'est pas proposé de nouveau (document modifié, ligne saisie à
 *   la main).
 *
 * Rien n'est ajouté d'office : l'écran propose les lignes, que l'utilisateur garde ou retire.
 */
class FraisDocuments
{
    /** Type de document => colonne qui l'active sur un frais. */
    public const DOCUMENTS = ['devis' => 'sur_devis', 'commande' => 'sur_commandes', 'facture' => 'sur_factures'];

    /**
     * @param  list<array{produit_id?: ?int, quantite?: mixed, prix_unitaire_ht?: mixed, remise_ligne?: mixed}>  $lignes  lignes du document, dans sa devise
     * @param  float  $taux  valeur d'une unité de la devise du document dans celle de l'entreprise (1 : même devise)
     * @return list<array{frais_id: int, libelle: string, produit_id: int, produit: ?array, quantite: int, prix_unitaire_ht: float, montant_ht: float}>
     */
    public function calculer(string $document, ?Client $client, array $lignes, float $taux = 1.0): array
    {
        $frais = Frais::with(['produit' => fn ($q) => $q->select('id', 'numero', 'description', 'actif', 'deleted_at'), 'produits:id'])
            ->where('actif', true)->where(self::DOCUMENTS[$document], true)->orderBy('libelle')->get();
        if ($frais->isEmpty()) {
            return [];
        }
        $produitsDeFrais = $frais->pluck('produit_id')->map(fn ($id) => (int) $id)->all();
        $saisies = collect($lignes)->filter(fn (array $l) => ! empty($l['produit_id']) && (float) ($l['quantite'] ?? 0) > 0);
        $presents = $saisies->pluck('produit_id')->map(fn ($id) => (int) $id)->all();
        $marchandise = $saisies->reject(fn (array $l) => in_array((int) $l['produit_id'], $produitsDeFrais, true))->values();
        if ($marchandise->isEmpty()) {
            return [];
        }

        $proposes = [];
        foreach ($frais as $f) {
            if (! $f->produit || $f->produit->trashed() || $f->produit->actif === false || in_array($f->produit_id, $presents, true)) {
                continue;
            }
            $concernees = $this->lignesConcernees($f, $marchandise, $client);
            if ($concernees->isEmpty()) {
                continue;
            }
            if ($f->mode === Frais::POURCENTAGE) {
                $quantite = 1;
                $prix = round($this->montantHt($concernees) * (float) $f->valeur / 100, 2);
            } else {
                $quantite = $f->repetition === 'ligne' ? $concernees->count() : 1;
                $prix = round((float) $f->valeur / ($taux > 0 ? $taux : 1.0), 2);
            }
            if ($prix <= 0) {
                continue;
            }
            $proposes[] = [
                'frais_id' => $f->id,
                'libelle' => $f->libelle,
                'produit_id' => $f->produit_id,
                'produit' => $f->produit->only(['id', 'numero', 'description']),
                'quantite' => $quantite,
                'prix_unitaire_ht' => $prix,
                'montant_ht' => round($quantite * $prix, 2),
            ];
        }

        return $proposes;
    }

    /** Lignes de marchandise qui déclenchent le frais. */
    private function lignesConcernees(Frais $frais, Collection $marchandise, ?Client $client): Collection
    {
        return match ($frais->application) {
            Frais::PRODUITS => $marchandise->filter(fn (array $l) => $frais->produits->contains('id', (int) $l['produit_id']))->values(),
            Frais::LIVRAISON => $client && (int) $client->frais_livraison_id === $frais->id ? $marchandise : collect(),
            default => $marchandise,
        };
    }

    private function montantHt(Collection $lignes): float
    {
        return (float) $lignes->sum(fn (array $l) => (float) ($l['quantite'] ?? 0) * (float) ($l['prix_unitaire_ht'] ?? 0) * (1 - (float) ($l['remise_ligne'] ?? 0) / 100));
    }
}
