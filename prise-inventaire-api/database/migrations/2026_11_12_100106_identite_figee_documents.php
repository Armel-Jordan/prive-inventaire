<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identité figée des documents de vente (plan de parité fripv2, socle S4.5) : à la sortie du brouillon (devis envoyé,
 * commande soumise, facture ou note de crédit émise), le document retient les coordonnées de l'entreprise, le
 * destinataire, le code du client et les libellés de l'en-tête ; le PDF les reprend. Les factures et notes de crédit
 * comptent leurs copies (à partir de la deuxième, « Réimpression »).
 *
 * Ajouts seulement : colonnes facultatives.
 */
return new class extends Migration
{
    private const IDENTITE = ['devis', 'com_client_entete', 'factures', 'avoirs'];

    private const COPIES = ['factures', 'avoirs'];

    public function up(): void
    {
        foreach (self::IDENTITE as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'identite_figee')) {
                    $t->json('identite_figee')->nullable();
                }
                if (in_array($table, self::COPIES, true) && ! Schema::hasColumn($table, 'nb_impressions')) {
                    $t->unsignedInteger('nb_impressions')->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::IDENTITE as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['identite_figee', 'nb_impressions'] as $colonne) {
                    if (Schema::hasColumn($table, $colonne)) {
                        $t->dropColumn($colonne);
                    }
                }
            });
        }
    }
};
