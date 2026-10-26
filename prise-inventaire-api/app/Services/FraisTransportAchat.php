<?php

namespace App\Services;

use App\Models\Fournisseur;
use App\Models\Frais;
use App\Models\Transporteur;

/**
 * Frais de transport côté achats (plan de parité fripv2, phase 1.11b, option 15) : le frais d'achat d'un transporteur
 * est proposé en ligne « Frais de transport – {transporteur} » sur les factures des fournisseurs qu'il livre.
 *
 * - Le transporteur est celui des commandes facturées, sinon celui du fournisseur.
 * - Montant : celui du frais (dans la devise de l'entreprise), ou son pourcentage de la marchandise ; rien à 0.
 * - Comme dans fripv2, rien sur une facture dans une autre devise que celle de l'entreprise.
 * - Ce n'est qu'une proposition : la ligne se modifie ou se retire avant l'enregistrement.
 */
class FraisTransportAchat
{
    /**
     * @param  string|null  $devise  devise de la facture (null : celle de l'entreprise)
     * @return array{produit_id: int, description: string, quantite: float, prix_unitaire: float, reception_ligne_id: null, frais_transport: true}|null
     */
    public function ligne(Fournisseur $fournisseur, ?int $transporteurId, ?string $devise, float $marchandise = 0.0): ?array
    {
        if ($devise !== null) {
            return null;
        }
        $transporteur = Transporteur::query()->find($transporteurId ?: $fournisseur->transporteur_id);
        $frais = $transporteur?->frais_livraison_achat_id ? Frais::with('produit:id,deleted_at,actif')->find($transporteur->frais_livraison_achat_id) : null;
        if (! $frais || ! $frais->actif || ! $frais->produit || $frais->produit->trashed()) {
            return null;
        }
        $montant = $frais->mode === Frais::POURCENTAGE ? round($marchandise * (float) $frais->valeur / 100, 2) : round((float) $frais->valeur, 2);
        if ($montant <= 0) {
            return null;
        }

        return [
            'produit_id' => $frais->produit_id,
            'description' => __('Frais de transport – :transporteur', ['transporteur' => $transporteur->nom]),
            'quantite' => 1.0,
            'prix_unitaire' => $montant,
            'reception_ligne_id' => null,
            'frais_transport' => true,
        ];
    }
}
