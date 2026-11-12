<?php

namespace App\Services;

use App\Models\Devis;
use App\Models\DevisLigne;
use App\Support\TauxTaxe;

/**
 * Taxes du devis (plan de parité fripv2, DE1), calculées comme celles des commandes : chaque ligne prend le groupe du
 * devis (celui du client par défaut, un groupe vide exonère), sinon le groupe par défaut de l'entreprise ; sans groupe,
 * le taux par défaut de l'entreprise. Le détail par taxe va dans document_taxes (document « devis ») pour le PDF.
 *
 * Refait à chaque enregistrement d'un devis brouillon (création, modification, copie) : les taxes suivent les lignes
 * et le groupe du moment. La conversion en commande ou en facture calcule les siennes de la même façon.
 */
class TaxesDevis
{
    public function __construct(private readonly TaxesDocument $taxes) {}

    public function appliquer(Devis $devis): void
    {
        $lignes = $devis->lignes()->get();
        $this->taxes->supprimer('devis', $lignes->pluck('id'));
        $groupe = $this->taxes->groupePour([], $devis->groupe_taxe_id);
        $tauxDefaut = $groupe ? null : TauxTaxe::parDefaut();

        foreach ($lignes as $ligne) {
            /** @var DevisLigne $ligne */
            $ligne->montant_ht = (float) $ligne->montant_ligne;
            $detail = $this->taxes->appliquer($ligne, $groupe);
            if ($detail === null) {
                $tva = round((float) $ligne->montant_ht * $tauxDefaut / 100, 2);
                $ligne->groupe_taxe_id = null;
                $ligne->taux_tva = $tauxDefaut;
                $ligne->montant_tva = $tva;
                $ligne->montant_ttc = round((float) $ligne->montant_ht + $tva, 2);
            }
            $ligne->save();
            $this->taxes->enregistrer('devis', $ligne->id, $detail);
        }

        $devis->recalculerTotal();
    }

    /** Retire le détail des taxes de lignes supprimées. */
    public function oublier(iterable $ligneIds): void
    {
        $this->taxes->supprimer('devis', collect($ligneIds)->all());
    }
}
