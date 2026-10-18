<?php

namespace App\Support;

use App\Models\Avoir;
use App\Models\BonLivraison;
use App\Models\ComClientEntete;
use App\Models\ComFourEntete;
use App\Models\DemandeAcompte;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\RetourFournisseur;

/**
 * Catalogue des courriels : types d'envoi, variables utilisables dans chaque type et modèles
 * par défaut (utilisés tant que l'entreprise n'a pas écrit les siens). Aucune valeur propre à
 * un secteur d'activité : les textes restent neutres et chaque entreprise les remplace.
 */
final class Courriels
{
    public const LANGUES = ['fr', 'en'];

    /** Variables propres à chaque type ; celles de l'entreprise s'ajoutent à tous. */
    public const TYPES = [
        // lien_reponse : page où le client accepte ou refuse le devis (vide si la réponse en ligne n'est pas possible).
        'devis' => ['numero', 'date', 'date_validite', 'client_nom', 'client_code', 'contact_nom', 'montant_total', 'lien_reponse'],
        'commande_client' => ['numero', 'date', 'date_livraison_souhaitee', 'client_nom', 'client_code', 'contact_nom', 'montant_ht', 'montant_ttc'],
        'facture' => ['numero', 'date', 'date_echeance', 'client_nom', 'client_code', 'contact_nom', 'montant_ht', 'montant_ttc', 'montant_paye', 'reste_a_payer'],
        'avoir' => ['numero', 'date', 'numero_facture', 'motif', 'client_nom', 'client_code', 'contact_nom', 'montant_ht', 'montant_ttc', 'credit_disponible'],
        'demande_acompte' => ['numero', 'date', 'client_nom', 'client_code', 'contact_nom', 'montant', 'description'],
        'relance' => ['numero', 'date', 'date_echeance', 'jours_retard', 'niveau', 'client_nom', 'client_code', 'contact_nom', 'montant_ttc', 'montant_paye', 'reste_a_payer'],
        // Relance d'un devis resté sans réponse (PDF du devis joint, carte de réponse en ligne).
        'relance_devis' => ['numero', 'date', 'date_validite', 'jours_restants', 'niveau', 'client_nom', 'client_code', 'contact_nom', 'montant_total', 'lien_reponse'],
        'bon_livraison' => ['numero', 'date_livraison', 'numero_facture', 'client_nom', 'client_code', 'contact_nom'],
        'commande_fournisseur' => ['numero', 'date', 'date_livraison_prevue', 'fournisseur_nom', 'fournisseur_code', 'contact_nom', 'montant_total'],
        'retour_fournisseur' => ['numero', 'date', 'type_retour', 'numero_rma', 'fournisseur_nom', 'fournisseur_code', 'contact_nom', 'montant'],
        // Rapport de la bibliothèque joint (PDF, Excel ou CSV) : son titre, sa période, ses paramètres, sa date.
        // analyse_ia : commentaire de l'IA d'un envoi programmé qui porte une question (vide sinon).
        'rapport' => ['rapport', 'periode', 'parametres', 'date_rapport', 'analyse_ia'],
        'general' => [],
    ];

    public const VARIABLES_ENTREPRISE = [
        'entreprise_nom', 'entreprise_adresse', 'entreprise_telephone', 'entreprise_courriel', 'entreprise_numero', 'entreprise_numero_taxe',
    ];

    /** Document source de chaque type (aucun pour un courriel général). */
    public const DOCUMENTS = [
        'devis' => Devis::class,
        'commande_client' => ComClientEntete::class,
        'facture' => Facture::class,
        'avoir' => Avoir::class,
        'demande_acompte' => DemandeAcompte::class,
        'relance' => Facture::class,
        'relance_devis' => Devis::class,
        'bon_livraison' => BonLivraison::class,
        'commande_fournisseur' => ComFourEntete::class,
        'retour_fournisseur' => RetourFournisseur::class,
    ];

    /** Module de l'entreprise dont dépend chaque type de document. */
    public const MODULES = [
        'devis' => 'ventes',
        'commande_client' => 'ventes',
        'facture' => 'ventes',
        'avoir' => 'ventes',
        'demande_acompte' => 'ventes',
        'relance' => 'ventes',
        'relance_devis' => 'ventes',
        'bon_livraison' => 'ventes',
        'commande_fournisseur' => 'achats',
        'retour_fournisseur' => 'achats',
    ];

    /** Nom par défaut du PDF joint, suivi du numéro du document (mêmes noms que les PDF téléchargés). */
    public const FICHIERS = [
        'devis' => 'devis',
        'commande_client' => 'commande',
        'facture' => 'facture',
        'avoir' => 'note-credit',
        'demande_acompte' => 'demande-acompte',
        // La relance joint la facture relancée.
        'relance' => 'facture',
        'relance_devis' => 'devis',
        'bon_livraison' => 'bon-livraison',
        'commande_fournisseur' => 'bon-commande',
        'retour_fournisseur' => 'retour-fournisseur',
    ];

    private const DEFAUTS = [
        'fr' => [
            'devis' => ['Devis {numero} — {entreprise_nom}', "Bonjour,\n\nVeuillez trouver ci-joint notre devis {numero} d'un montant de {montant_total} hors taxes, valable jusqu'au {date_validite}.\n\nNous restons à votre disposition pour toute question.\n\nCordialement,\n{entreprise_nom}"],
            'commande_client' => ['Confirmation de commande {numero} — {entreprise_nom}', "Bonjour,\n\nNous vous confirmons votre commande {numero} du {date}, d'un montant de {montant_ttc}. Vous en trouverez le détail en pièce jointe.\n\nCordialement,\n{entreprise_nom}"],
            'facture' => ['Facture {numero} — {entreprise_nom}', "Bonjour,\n\nVeuillez trouver ci-joint la facture {numero} du {date}, d'un montant de {montant_ttc}.\n\nCordialement,\n{entreprise_nom}"],
            'avoir' => ['Note de crédit {numero} — {entreprise_nom}', "Bonjour,\n\nVeuillez trouver ci-joint la note de crédit {numero} du {date}, d'un montant de {montant_ttc}, établie sur la facture {numero_facture}.\n\nCordialement,\n{entreprise_nom}"],
            'demande_acompte' => ["Demande d'acompte {numero} — {entreprise_nom}", "Bonjour,\n\nVeuillez trouver ci-joint notre demande d'acompte {numero} d'un montant de {montant}.\n\nNous restons à votre disposition pour toute question.\n\nCordialement,\n{entreprise_nom}"],
            'relance' => ['Relance : facture {numero} — {entreprise_nom}', "Bonjour,\n\nSauf erreur de notre part, la facture {numero} du {date}, échue le {date_echeance}, reste impayée : {reste_a_payer} restent dus.\n\nNous vous remercions de bien vouloir procéder à son règlement dans les meilleurs délais. Si votre paiement est déjà parti, merci de ne pas tenir compte de ce message.\n\nCordialement,\n{entreprise_nom}"],
            'relance_devis' => ['Relance : devis {numero} — {entreprise_nom}', "Bonjour,\n\nNous revenons vers vous au sujet de notre devis {numero} du {date}, d'un montant de {montant_total} hors taxes, valable jusqu'au {date_validite}. Vous le trouverez de nouveau en pièce jointe.\n\nNous restons à votre disposition pour en discuter.\n\nCordialement,\n{entreprise_nom}"],
            'bon_livraison' => ['Bon de livraison {numero} — {entreprise_nom}', "Bonjour,\n\nVeuillez trouver ci-joint le bon de livraison {numero}.\n\nCordialement,\n{entreprise_nom}"],
            'commande_fournisseur' => ['Bon de commande {numero} — {entreprise_nom}', "Bonjour,\n\nVeuillez trouver ci-joint notre bon de commande {numero} du {date}. Merci de nous en confirmer la réception et la date de livraison prévue.\n\nCordialement,\n{entreprise_nom}"],
            'retour_fournisseur' => ['Retour de marchandise {numero} — {entreprise_nom}', "Bonjour,\n\nVeuillez trouver ci-joint notre bon de retour {numero} du {date} ({type_retour}), d'une valeur de {montant}. Merci de nous en confirmer la prise en charge et, au besoin, de nous communiquer un numéro d'autorisation de retour.\n\nCordialement,\n{entreprise_nom}"],
            'rapport' => ['Rapport « {rapport} » — {entreprise_nom}', "Bonjour,\n\nVeuillez trouver ci-joint le rapport « {rapport} » du {date_rapport}.\n\n{parametres}\n\n{analyse_ia}\n\nCordialement,\n{entreprise_nom}"],
            'general' => ['Message de {entreprise_nom}', "Bonjour,\n\n\n\nCordialement,\n{entreprise_nom}"],
        ],
        'en' => [
            'devis' => ['Quote {numero} — {entreprise_nom}', "Hello,\n\nPlease find attached our quote {numero} for {montant_total} before tax, valid until {date_validite}.\n\nDo not hesitate to contact us with any questions.\n\nBest regards,\n{entreprise_nom}"],
            'commande_client' => ['Order confirmation {numero} — {entreprise_nom}', "Hello,\n\nWe confirm your order {numero} of {date}, for a total of {montant_ttc}. Please find the details attached.\n\nBest regards,\n{entreprise_nom}"],
            'facture' => ['Invoice {numero} — {entreprise_nom}', "Hello,\n\nPlease find attached invoice {numero} of {date}, for {montant_ttc}.\n\nBest regards,\n{entreprise_nom}"],
            'avoir' => ['Credit note {numero} — {entreprise_nom}', "Hello,\n\nPlease find attached credit note {numero} of {date}, for {montant_ttc}, issued on invoice {numero_facture}.\n\nBest regards,\n{entreprise_nom}"],
            'demande_acompte' => ['Deposit request {numero} — {entreprise_nom}', "Hello,\n\nPlease find attached our deposit request {numero} for {montant}.\n\nDo not hesitate to contact us with any questions.\n\nBest regards,\n{entreprise_nom}"],
            'relance' => ['Reminder: invoice {numero} — {entreprise_nom}', "Hello,\n\nUnless we are mistaken, invoice {numero} of {date}, due on {date_echeance}, remains unpaid: {reste_a_payer} is still due.\n\nWe would be grateful if you could settle it at your earliest convenience. If your payment has already been sent, please disregard this message.\n\nBest regards,\n{entreprise_nom}"],
            'relance_devis' => ['Follow-up: quote {numero} — {entreprise_nom}', "Hello,\n\nWe are following up on our quote {numero} of {date}, for {montant_total} before tax, valid until {date_validite}. You will find it attached again.\n\nWe remain at your disposal to discuss it.\n\nBest regards,\n{entreprise_nom}"],
            'bon_livraison' => ['Delivery note {numero} — {entreprise_nom}', "Hello,\n\nPlease find attached delivery note {numero}.\n\nBest regards,\n{entreprise_nom}"],
            'commande_fournisseur' => ['Purchase order {numero} — {entreprise_nom}', "Hello,\n\nPlease find attached our purchase order {numero} of {date}. Kindly confirm receipt and the expected delivery date.\n\nBest regards,\n{entreprise_nom}"],
            'retour_fournisseur' => ['Goods return {numero} — {entreprise_nom}', "Hello,\n\nPlease find attached our return note {numero} of {date} ({type_retour}), valued at {montant}. Kindly confirm you will handle it and, if needed, send us a return authorization number.\n\nBest regards,\n{entreprise_nom}"],
            'rapport' => ['Report "{rapport}" — {entreprise_nom}', "Hello,\n\nPlease find attached the report \"{rapport}\" of {date_rapport}.\n\n{parametres}\n\n{analyse_ia}\n\nBest regards,\n{entreprise_nom}"],
            'general' => ['Message from {entreprise_nom}', "Hello,\n\n\n\nBest regards,\n{entreprise_nom}"],
        ],
    ];

    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    /** Variables utilisables dans un modèle du type donné. */
    public static function variables(string $type): array
    {
        return array_merge(self::TYPES[$type] ?? [], self::VARIABLES_ENTREPRISE);
    }

    /** @return array{objet: string, contenu: string, nom_fichier: null, repondre_a: null, accuse_lecture: false} */
    public static function modeleParDefaut(string $type, string $langue): array
    {
        [$objet, $contenu] = self::DEFAUTS[$langue][$type] ?? self::DEFAUTS['fr'][$type] ?? self::DEFAUTS['fr']['general'];

        return ['objet' => $objet, 'contenu' => $contenu, 'nom_fichier' => null, 'repondre_a' => null, 'accuse_lecture' => false];
    }
}
