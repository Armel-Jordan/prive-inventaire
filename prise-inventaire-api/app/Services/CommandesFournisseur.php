<?php

namespace App\Services;

use App\Models\ComFourEntete;
use App\Models\HistoriquePrixAchat;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Commande passée au fournisseur (plan de parité fripv2, phase 3.3), par le bouton « Valider et envoyer » ou par
 * l'envoi du bon par courriel : elle quitte le brouillon, note le jour et l'auteur de l'envoi (« Commandée le » de
 * fripv2) et inscrit ses prix à l'historique des prix d'achat.
 */
class CommandesFournisseur
{
    public function valider(ComFourEntete $commande, ?int $auteur): ComFourEntete
    {
        return DB::transaction(function () use ($commande, $auteur) {
            // Verrou : deux validations simultanées (bouton et courriel) n'inscrivent pas deux fois les prix.
            $commande = ComFourEntete::query()->lockForUpdate()->findOrFail($commande->getKey());
            if ($commande->statut !== ComFourEntete::STATUT_BROUILLON) {
                throw ValidationException::withMessages(['statut' => __('Seules les commandes en brouillon peuvent être validées.')]);
            }

            $commande->update(['statut' => ComFourEntete::STATUT_ENVOYEE, 'envoyee_le' => now(), 'envoyee_par' => $auteur]);
            foreach ($commande->lignes as $ligne) {
                HistoriquePrixAchat::create([
                    'produit_id' => $ligne->produit_id,
                    'fournisseur_id' => $commande->fournisseur_id,
                    'prix_achat' => $ligne->prix_unitaire,
                    'date_effet' => $commande->date_commande,
                    'com_four_entete_id' => $commande->id,
                ]);
            }

            return $commande;
        });
    }

    /**
     * Bon de commande parti par courriel : une commande encore au brouillon est validée par l'envoi, comme dans fripv2.
     * Une commande validée entre-temps (bouton) reste telle quelle : l'envoi a réussi, il ne doit pas paraître en échec.
     */
    public function apresEnvoiCourriel(int $commandeId, ?int $auteur): void
    {
        $commande = ComFourEntete::query()->find($commandeId);
        if ($commande?->statut !== ComFourEntete::STATUT_BROUILLON) {
            return;
        }

        try {
            $this->valider($commande, $auteur);
        } catch (ValidationException) {
            // Validée par quelqu'un d'autre au même moment.
        }
    }
}
