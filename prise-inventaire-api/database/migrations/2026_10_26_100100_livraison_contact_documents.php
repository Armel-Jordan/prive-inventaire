<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Livrer à » et contact des documents de vente (plan de parité fripv2, socle S4.1) : le contact du client et son
 * adresse de livraison sur les devis, commandes, factures et bons de livraison, avec une copie de l'adresse au moment
 * du document (une adresse modifiée ensuite ne change pas les documents faits) et ses instructions de livraison (celles de l'adresse).
 *
 * Ajouts seulement : colonnes facultatives, sans clé étrangère.
 */
return new class extends Migration
{
    private const TABLES = ['devis', 'com_client_entete', 'factures', 'bons_livraison'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'contact_id')) {
                    $t->unsignedBigInteger('contact_id')->nullable()->index();
                }
                if (! Schema::hasColumn($table, 'adresse_livraison_id')) {
                    $t->unsignedBigInteger('adresse_livraison_id')->nullable()->index();
                }
                if (! Schema::hasColumn($table, 'livraison_adresse')) {
                    // Copie de l'adresse choisie : libellé, adresse, ville, code postal, pays, sur quelques lignes.
                    $t->text('livraison_adresse')->nullable();
                }
                if (! Schema::hasColumn($table, 'instructions_livraison')) {
                    $t->string('instructions_livraison', 500)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['contact_id', 'adresse_livraison_id'] as $colonne) {
                    if (Schema::hasColumn($table, $colonne)) {
                        $t->dropIndex("{$table}_{$colonne}_index");
                        $t->dropColumn($colonne);
                    }
                }
                foreach (['livraison_adresse', 'instructions_livraison'] as $colonne) {
                    if (Schema::hasColumn($table, $colonne)) {
                        $t->dropColumn($colonne);
                    }
                }
            });
        }
    }
};
