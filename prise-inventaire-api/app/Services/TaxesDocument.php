<?php

namespace App\Services;

use App\Models\DocumentTaxe;
use App\Models\GroupeTaxe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Taxes des lignes de documents (commandes client, factures) calculées avec les groupes de taxes.
 *
 * Une ligne n'utilise un groupe que si on lui en donne un (groupe_taxe_id), ou si elle n'a pas
 * de taux explicite et que son document ou l'entreprise en ont un : le groupe du document (socle S4.1,
 * celui du client par défaut, un groupe vide exonère), sinon le groupe par défaut. Sinon, le calcul
 * historique au taux unique s'applique, inchangé. Sans état : sûr d'un tenant à l'autre.
 */
class TaxesDocument
{
    public function __construct(private readonly CalculTaxes $calcul) {}

    /**
     * Groupe à appliquer à une ligne saisie, ou null pour le calcul historique : celui de la ligne, sinon (sans taux
     * saisi) celui du document, sinon le groupe par défaut de l'entreprise.
     */
    public function groupePour(array $ligne, ?int $groupeDocument = null): ?GroupeTaxe
    {
        if (! empty($ligne['groupe_taxe_id'])) {
            return GroupeTaxe::find($ligne['groupe_taxe_id']);
        }

        // Un taux saisi explicitement garde le calcul historique.
        if (isset($ligne['taux_tva'])) {
            return null;
        }

        return ($groupeDocument ? GroupeTaxe::find($groupeDocument) : null)
            ?? GroupeTaxe::where('par_defaut', true)->where('actif', true)->first();
    }

    /**
     * Refait les taxes des lignes enregistrées d'un document dont le groupe (en-tête) vient de changer : chaque ligne
     * prend ce groupe, sinon le groupe par défaut ; sans l'un ni l'autre, elle garde son calcul.
     *
     * @param  iterable<Model>  $lignes
     */
    public function reappliquer(string $document, iterable $lignes, ?int $groupeDocument): void
    {
        $groupe = $this->groupePour([], $groupeDocument);
        if (! $groupe) {
            return;
        }
        foreach ($lignes as $ligne) {
            $this->supprimer($document, [$ligne->getKey()]);
            $detail = $this->appliquer($ligne, $groupe);
            $ligne->save();
            $this->enregistrer($document, $ligne->getKey(), $detail);
        }
    }

    /** Détail des taxes d'un montant HT (voir CalculTaxes::calculer). */
    public function calculer(GroupeTaxe $groupe, float $montantHt): array
    {
        return $this->calcul->calculer($groupe, $montantHt);
    }

    /**
     * Applique un groupe à une ligne dont montant_ht est déjà calculé : taux effectif,
     * total TTC et, si la ligne en a un, montant de TVA. Renvoie le détail à enregistrer.
     */
    public function appliquer(Model $ligne, ?GroupeTaxe $groupe): ?array
    {
        if (! $groupe) {
            return null;
        }

        $detail = $this->calcul->calculer($groupe, (float) $ligne->montant_ht);
        $ligne->groupe_taxe_id = $groupe->id;
        $ligne->taux_tva = $detail['taux_effectif'];
        $ligne->montant_ttc = $detail['montant_ttc'];
        if ($ligne->isFillable('montant_tva')) {
            $ligne->montant_tva = $detail['total_taxes'];
        }

        return $detail;
    }

    public function enregistrer(string $document, int $ligneId, ?array $detail): void
    {
        foreach ($detail['taxes'] ?? [] as $taxe) {
            DocumentTaxe::create([
                'document' => $document,
                'ligne_id' => $ligneId,
                'tenant_taxe_id' => $taxe['taxe_id'],
                'nom' => $taxe['nom'],
                'taux' => $taxe['taux'],
                'applique_sur' => $taxe['applique_sur'],
                'base' => $taxe['base'],
                'montant' => $taxe['montant'],
            ]);
        }
    }

    public function supprimer(string $document, Collection|array $ligneIds): void
    {
        DocumentTaxe::where('document', $document)->whereIn('ligne_id', $ligneIds)->delete();
    }

    /** Recopie le détail d'une ligne vers une autre (ex. commande → facture). */
    public function copier(string $source, int $ligneSource, string $cible, int $ligneCible): void
    {
        DocumentTaxe::where('document', $source)->where('ligne_id', $ligneSource)->orderBy('id')->get()
            ->each(fn (DocumentTaxe $taxe) => DocumentTaxe::create(
                ['document' => $cible, 'ligne_id' => $ligneCible] + $taxe->only(['tenant_taxe_id', 'nom', 'taux', 'applique_sur', 'base', 'montant'])
            ));
    }

    /**
     * Récapitulatif d'un document, par taxe (même nom et même taux) : base et montant cumulés.
     *
     * @return list<array{nom: string, taux: float, base: float, montant: float}>
     */
    public function recapitulatif(string $document, Collection|array $ligneIds): array
    {
        return DocumentTaxe::where('document', $document)->whereIn('ligne_id', $ligneIds)->orderBy('id')->get()
            ->groupBy(fn (DocumentTaxe $taxe) => $taxe->nom.'|'.$taxe->taux)
            ->map(fn (Collection $taxes) => [
                'nom' => $taxes->first()->nom,
                'taux' => (float) $taxes->first()->taux,
                'base' => CalculTaxes::arrondir((float) $taxes->sum('base')),
                'montant' => CalculTaxes::arrondir((float) $taxes->sum('montant')),
            ])
            ->values()
            ->all();
    }
}
