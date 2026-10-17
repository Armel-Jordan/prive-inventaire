<?php

namespace App\Rapports;

use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Fournisseur;

/** Achats par fournisseur : commandes passées sur la période, montant commandé et valeur reçue, en devise de l'entreprise. */
class AchatsParFournisseur extends Rapport
{
    /** Commandes qui comptent : passées au fournisseur, ni brouillons ni annulées. */
    public const COMMANDES_PASSEES = [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE, ComFourEntete::STATUT_COMPLETE];

    public function code(): string
    {
        return 'achats-par-fournisseur';
    }

    public function categorie(): string
    {
        return 'achats';
    }

    public function module(): string
    {
        return 'commandes_fournisseur';
    }

    public function titre(): array
    {
        return ['fr' => 'Achats par fournisseur', 'en' => 'Purchases by supplier'];
    }

    public function description(): array
    {
        return ['fr' => 'Les commandes passées sur la période par fournisseur : nombre, montant commandé et valeur reçue (au prix commandé), en devise de l’entreprise.',
            'en' => 'Orders placed during the period by supplier: count, amount ordered and value received (at the ordered price), in the company currency.'];
    }

    public function parametres(): array
    {
        return $this->periode();
    }

    public function colonnes(array $valeurs): array
    {
        return [
            'code' => ['libelle' => ['fr' => 'Code', 'en' => 'Code'], 'type' => 'texte'],
            'fournisseur' => ['libelle' => ['fr' => 'Fournisseur', 'en' => 'Supplier'], 'type' => 'texte'],
            'commandes' => ['libelle' => ['fr' => 'Commandes', 'en' => 'Orders'], 'type' => 'entier', 'total' => true],
            'commande' => ['libelle' => ['fr' => 'Commandé', 'en' => 'Ordered'], 'type' => 'montant', 'total' => true],
            'recu' => ['libelle' => ['fr' => 'Reçu', 'en' => 'Received'], 'type' => 'montant', 'total' => true],
            'part_recue' => ['libelle' => ['fr' => 'Part reçue', 'en' => 'Share received'], 'type' => 'pourcentage'],
        ];
    }

    public function lignes(array $valeurs): array
    {
        $commandes = ComFourEntete::query()->whereIn('statut', self::COMMANDES_PASSEES)
            ->whereDate('date_commande', '>=', $valeurs['du'])->whereDate('date_commande', '<=', $valeurs['au']);
        $lignes = ComFourLigne::query()->whereIn('com_four_ligne.com_four_entete_id', (clone $commandes)->select('id'))
            ->join('com_four_entete', 'com_four_entete.id', '=', 'com_four_ligne.com_four_entete_id')
            ->groupBy('com_four_entete.fournisseur_id')
            ->selectRaw('com_four_entete.fournisseur_id, COUNT(DISTINCT com_four_entete.id) AS commandes,
                SUM(com_four_ligne.quantite_commandee * com_four_ligne.prix_unitaire * COALESCE(com_four_entete.taux_change, 1)) AS commande,
                SUM(com_four_ligne.quantite_recue * com_four_ligne.prix_unitaire * COALESCE(com_four_entete.taux_change, 1)) AS recu')
            ->get();
        $fournisseurs = Fournisseur::withTrashed()->whereIn('id', $lignes->pluck('fournisseur_id'))->get(['id', 'code', 'raison_sociale'])->keyBy('id');

        return $lignes->map(fn ($l) => [
            'code' => $fournisseurs[$l->fournisseur_id]->code ?? '',
            'fournisseur' => $fournisseurs[$l->fournisseur_id]->raison_sociale ?? '',
            'commandes' => (int) $l->commandes,
            'commande' => $this->argent($l->commande),
            'recu' => $this->argent($l->recu),
            'part_recue' => (float) $l->commande > 0 ? round(100 * (float) $l->recu / (float) $l->commande, 1) : null,
        ])->sortByDesc('commande')->values()->all();
    }
}
