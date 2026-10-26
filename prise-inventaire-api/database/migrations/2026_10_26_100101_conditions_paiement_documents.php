<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Condition de paiement des devis et des commandes (plan de parité fripv2, socle S4.1) : celle du client par défaut,
 * reprise par la commande puis par la facture, qui en calcule l'échéance.
 *
 * Ajouts seulement : colonne facultative, sans clé étrangère (comme sur les factures).
 */
return new class extends Migration
{
    private const TABLES = ['devis', 'com_client_entete'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'condition_paiement_id')) {
                    $t->unsignedBigInteger('condition_paiement_id')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'condition_paiement_id')) {
                    $t->dropIndex("{$table}_condition_paiement_id_index");
                    $t->dropColumn('condition_paiement_id');
                }
            });
        }
    }
};
