<?php

namespace App\Support;

use App\Taches\ComptabiliserDocuments;
use App\Taches\ConvertirEcrituresRecurrentes;
use App\Taches\ExecuterTransfertsPlanifies;
use App\Taches\FacturerAbonnements;
use App\Taches\FacturerLocations;
use App\Taches\NettoyerNotifications;
use App\Taches\RelancerDevisSansReponse;
use App\Taches\RelancerFacturesEnRetard;
use App\Taches\RenouvelerContrats;
use App\Taches\SignalerAlertesStock;
use App\Taches\SignalerCommandesFournisseurEnRetard;
use App\Taches\SignalerControlesTechniques;
use App\Taches\SignalerFacturesImpayees;
use App\Taches\SignalerLivraisonsEnRetard;
use App\Taches\SignalerTransfertsImminents;

/**
 * Catalogue des tâches planifiées : traitements prédéfinis par l'application, avec leur réglage
 * par défaut. Chaque entreprise les active et les règle elle-même ; ajouter une tâche se fait ici.
 */
final class TachesPlanifiees
{
    public const RECURRENCES = ['horaire', 'quotidienne', 'hebdomadaire', 'mensuelle', 'une_fois'];

    /** code => [classe, récurrence par défaut, heure par défaut] */
    public const TACHES = [
        'transferts_planifies' => [ExecuterTransfertsPlanifies::class, 'horaire', '00:00'],
        'transferts_imminents' => [SignalerTransfertsImminents::class, 'quotidienne', '07:00'],
        'alertes_stock' => [SignalerAlertesStock::class, 'quotidienne', '07:00'],
        'nettoyage_notifications' => [NettoyerNotifications::class, 'quotidienne', '02:00'],
        'factures_impayees' => [SignalerFacturesImpayees::class, 'quotidienne', '07:00'],
        'relances_paiement' => [RelancerFacturesEnRetard::class, 'quotidienne', '08:00'],
        'relances_devis' => [RelancerDevisSansReponse::class, 'quotidienne', '08:30'],
        'facturation_abonnements' => [FacturerAbonnements::class, 'quotidienne', '06:00'],
        'facturation_locations' => [FacturerLocations::class, 'quotidienne', '06:10'],
        'renouvellement_contrats' => [RenouvelerContrats::class, 'quotidienne', '05:50'],
        'commandes_fournisseur_en_retard' => [SignalerCommandesFournisseurEnRetard::class, 'quotidienne', '07:00'],
        'livraisons_en_retard' => [SignalerLivraisonsEnRetard::class, 'quotidienne', '07:00'],
        'controles_techniques' => [SignalerControlesTechniques::class, 'quotidienne', '07:00'],
        'ecritures_recurrentes' => [ConvertirEcrituresRecurrentes::class, 'quotidienne', '05:30'],
        'comptabilisation' => [ComptabiliserDocuments::class, 'quotidienne', '23:30'],
    ];

    /**
     * Paramètres réglables d'une tâche et leur valeur par défaut. « jours » : délai de l'alerte (retard toléré
     * pour une facture, une commande ou une livraison ; préavis pour un contrôle technique).
     */
    public const PARAMETRES = [
        'factures_impayees' => ['jours' => 30],
        // Retard minimal avant la 1re relance, puis intervalle entre deux relances.
        'relances_paiement' => ['jours' => 15],
        // Délai sans réponse avant la 1re relance d'un devis, puis intervalle entre deux relances.
        'relances_devis' => ['jours' => 7],
        // Factures d'abonnement créées émises plutôt qu'en brouillon.
        'facturation_abonnements' => ['emettre' => false],
        // Factures de loyer créées émises plutôt qu'en brouillon.
        'facturation_locations' => ['emettre' => false],
        'commandes_fournisseur_en_retard' => ['jours' => 0],
        'livraisons_en_retard' => ['jours' => 3],
        'controles_techniques' => ['jours' => 30],
    ];

    /** Module requis par une tâche : sans lui, l'écran des alertes ne la propose pas. */
    public const MODULES = [
        'factures_impayees' => 'ventes',
        'relances_paiement' => 'ventes',
        'relances_devis' => 'ventes',
        'facturation_abonnements' => 'ventes',
        'facturation_locations' => 'ventes',
        'renouvellement_contrats' => 'ventes',
        'commandes_fournisseur_en_retard' => 'achats',
        'livraisons_en_retard' => 'ventes',
        'controles_techniques' => 'ventes',
        'ecritures_recurrentes' => 'finance',
        'comptabilisation' => 'finance',
    ];

    public static function codes(): array
    {
        return array_keys(self::TACHES);
    }

    /** @return class-string<\App\Taches\Tache> */
    public static function classe(string $code): string
    {
        return self::TACHES[$code][0];
    }

    /** Paramètres par défaut d'une tâche (vide si elle n'en a pas). */
    public static function parametresParDefaut(string $code): array
    {
        return self::PARAMETRES[$code] ?? [];
    }
}
