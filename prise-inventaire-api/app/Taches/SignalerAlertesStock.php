<?php

namespace App\Taches;

use App\Models\Notification;
use App\Models\ProduitTenant;
use App\Models\ScanTenant;

/**
 * Signale les produits passés sous leur seuil d'alerte (même calcul que l'écran Alertes stock :
 * stock = somme des quantités scannées). Pas de nouvelle notification tant qu'une alerte non lue
 * existe déjà pour le produit.
 */
class SignalerAlertesStock implements Tache
{
    public function executer(): string
    {
        $produits = ProduitTenant::whereNotNull('seuil_alerte')->where('seuil_alerte', '>', 0)->where('actif', true)->get();
        if ($produits->isEmpty()) {
            return 'Aucun produit avec un seuil d\'alerte.';
        }

        $stocks = ScanTenant::whereIn('numero', $produits->pluck('numero'))
            ->whereNull('deleted_at')
            ->groupBy('numero')
            ->selectRaw('numero, SUM(quantite) as total')
            ->pluck('total', 'numero');

        $nonLues = Notification::where('type', 'alerte_stock')->where('lu', false)->get()
            ->map(fn (Notification $n) => $n->data['produit'] ?? null)->filter()->all();

        $signales = 0;
        foreach ($produits as $produit) {
            $stock = (float) ($stocks[$produit->numero] ?? 0);
            $libelle = trim("{$produit->numero} {$produit->description}");
            if ($stock < (float) $produit->seuil_alerte && ! in_array($libelle, $nonLues, true)) {
                Notification::create_alerte_stock($libelle, $stock, (float) $produit->seuil_alerte);
                $signales++;
            }
        }

        return $signales === 0 ? 'Aucune nouvelle alerte de stock.' : "{$signales} produit(s) sous le seuil signalé(s).";
    }
}
