<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transporteurs sur les tiers et les documents (plan de parité fripv2, phase 1.11b) : le transporteur d'un client est
 * repris par ses devis, commandes, factures et bons de livraison, celui d'un fournisseur par ses commandes ; le numéro
 * de suivi (connaissement) passe de la commande à la facture puis au bon de livraison, et chaque colis peut avoir le sien.
 *
 * Ajouts seulement : colonnes facultatives, sans clé étrangère (les transporteurs se retirent en douceur).
 */
return new class extends Migration
{
    private const TRANSPORTEUR = ['clients', 'fournisseurs', 'devis', 'com_client_entete', 'factures', 'bons_livraison', 'com_four_entete'];

    private const SUIVI = ['com_client_entete', 'factures', 'bons_livraison', 'bl_colis'];

    public function up(): void
    {
        foreach (self::TRANSPORTEUR as $table) {
            if (! Schema::hasColumn($table, 'transporteur_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('transporteur_id')->nullable()->index());
            }
        }
        foreach (self::SUIVI as $table) {
            if (! Schema::hasColumn($table, 'numero_suivi')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('numero_suivi', 60)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (self::SUIVI as $table) {
            if (Schema::hasColumn($table, 'numero_suivi')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('numero_suivi'));
            }
        }
        foreach (self::TRANSPORTEUR as $table) {
            if (Schema::hasColumn($table, 'transporteur_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropIndex("{$table}_transporteur_id_index");
                    $t->dropColumn('transporteur_id');
                });
            }
        }
    }
};
