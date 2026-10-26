<?php

namespace App\Services;

use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Devise;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use Illuminate\Support\Facades\DB;

/**
 * Prix proposés sur les lignes d'une commande fournisseur (anomalie A5, fripv2 : le coûtant prévu) : pour chaque
 * produit, le dernier prix payé à ce fournisseur (dernière commande validée, envoyée ou reçue), sinon le prix convenu
 * de son catalogue. Le prix est ramené à l'unité d'achat actuelle du produit chez ce fournisseur (une caisse de 12 =
 * 12 × le prix d'une unité) et à la devise de la commande. Rien n'est enregistré : l'écran propose, l'usager décide.
 */
class PrixAchatProposes
{
    /** Statuts d'une commande dont le prix compte comme payé : ni brouillon, ni annulée. */
    private const STATUTS_RETENUS = [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE, ComFourEntete::STATUT_COMPLETE];

    public function __construct(
        private readonly UnitesAchat $unites,
        private readonly DevisesVente $devises,
    ) {}

    /**
     * @param  list<int>  $produitIds
     * @param  float  $taux  valeur d'une unité de la devise de la commande dans celle de l'entreprise
     * @return array<int, array{prix: ?float, origine: ?string, document: ?string}>
     */
    public function pour(Fournisseur $fournisseur, array $produitIds, ?string $devise, float $taux): array
    {
        $reference = $this->devises->reference();
        $cible = $this->code($devise, $reference);
        $tauxCible = $cible === $reference ? 1.0 : max($taux, 0.0);
        $produits = ProduitTenant::whereIn('id', $produitIds)->get()->keyBy('id');

        $propositions = [];
        foreach ($produitIds as $produitId) {
            $produit = $produits[$produitId] ?? null;
            $propositions[$produitId] = $produit && $tauxCible > 0
                ? $this->proposition($produit, $fournisseur, $cible, $tauxCible, $reference)
                : ['prix' => null, 'origine' => null, 'document' => null];
        }

        return $propositions;
    }

    /** @return array{prix: ?float, origine: ?string, document: ?string} */
    private function proposition(ProduitTenant $produit, Fournisseur $fournisseur, string $cible, float $tauxCible, string $reference): array
    {
        $facteur = $this->unites->pour($produit, $fournisseur->id)['qte_par_unite_achat'];

        $dernier = ComFourLigne::query()
            ->join('com_four_entete', 'com_four_entete.id', '=', 'com_four_ligne.com_four_entete_id')
            ->where('com_four_entete.fournisseur_id', $fournisseur->id)
            ->whereIn('com_four_entete.statut', self::STATUTS_RETENUS)
            ->where('com_four_ligne.produit_id', $produit->id)
            ->where('com_four_ligne.prix_unitaire', '>', 0)
            ->orderByDesc('com_four_entete.date_commande')->orderByDesc('com_four_ligne.id')
            ->first(['com_four_ligne.prix_unitaire', 'com_four_ligne.qte_par_unite_achat', 'com_four_entete.numero', 'com_four_entete.devise', 'com_four_entete.taux_change']);
        if ($dernier) {
            // Prix d'une unité de stock à l'époque, puis de l'unité d'achat d'aujourd'hui.
            $prix = (float) $dernier->prix_unitaire / max(1, (int) ($dernier->qte_par_unite_achat ?? 1)) * $facteur;
            $converti = $this->convertir($prix, $this->code($dernier->devise, $reference), (float) ($dernier->taux_change ?: 1), $cible, $tauxCible, $reference);

            return ['prix' => $converti, 'origine' => $converti === null ? null : 'dernier_paye', 'document' => $dernier->numero];
        }

        // Prix convenu du catalogue : par unité d'achat du catalogue (celle retenue ici), dans la devise du fournisseur.
        $convenu = DB::table('produit_fournisseur')->where('produit_id', $produit->id)->where('fournisseur_id', $fournisseur->id)->value('prix_achat');
        if ($convenu !== null && (float) $convenu > 0) {
            $origine = $this->code($fournisseur->devise, $reference);
            $converti = $this->convertir((float) $convenu, $origine, $this->tauxReferentiel($origine, $reference), $cible, $tauxCible, $reference);

            return ['prix' => $converti, 'origine' => $converti === null ? null : 'convenu', 'document' => null];
        }

        return ['prix' => null, 'origine' => null, 'document' => null];
    }

    /** Prix d'une devise dans une autre, par la devise de l'entreprise ; null si un taux manque. */
    private function convertir(float $prix, string $origine, ?float $tauxOrigine, string $cible, float $tauxCible, string $reference): ?float
    {
        if ($origine === $cible) {
            return round($prix, 2);
        }
        $tauxOrigine = $origine === $reference ? 1.0 : $tauxOrigine;
        if (! $tauxOrigine || $tauxOrigine <= 0 || $tauxCible <= 0) {
            return null;
        }

        return round($prix * $tauxOrigine / $tauxCible, 2);
    }

    private function tauxReferentiel(string $code, string $reference): ?float
    {
        if ($code === $reference) {
            return 1.0;
        }
        $taux = Devise::where('code', $code)->where('actif', true)->value('taux');

        return is_numeric($taux) && (float) $taux > 0 ? (float) $taux : null;
    }

    private function code(?string $devise, string $reference): string
    {
        $code = strtoupper(trim((string) $devise));

        return $code === '' ? $reference : $code;
    }
}
