<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Représentants commerciaux sur les clients et les documents de vente (plan de parité fripv2, phase 1.11a) : le
 * représentant d'un client est repris par ses devis, commandes et factures ; une facture fige le taux de commission du
 * représentant (un changement de taux ne touche pas les factures déjà faites).
 *
 * Ajouts seulement : colonnes facultatives, sans clé étrangère (les représentants se retirent en douceur).
 */
return new class extends Migration
{
    private const TABLES = ['clients', 'devis', 'com_client_entete', 'factures'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'representant_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('representant_id')->nullable()->index());
            }
        }
        if (! Schema::hasColumn('factures', 'taux_commission')) {
            Schema::table('factures', function (Blueprint $table) {
                // Taux de commission du représentant au jour de la facture, en %.
                $table->decimal('taux_commission', 5, 2)->nullable()->after('representant_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('factures', 'taux_commission')) {
            Schema::table('factures', fn (Blueprint $t) => $t->dropColumn('taux_commission'));
        }
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'representant_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropIndex("{$table}_representant_id_index");
                    $t->dropColumn('representant_id');
                });
            }
        }
    }
};
