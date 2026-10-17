<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-entrepôts (plan de parité fripv2, phase 2.5) : les entrepôts du référentiel passent au-dessus des secteurs,
 * qui restent valides tels quels (un secteur sans entrepôt continue de servir). Une commande fournisseur peut être
 * livrée à un entrepôt (« Livrer à » : son adresse sur le bon de commande).
 *
 * Ajouts seulement : colonnes facultatives entrepot_id sur secteurs et com_four_entete.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('secteurs', 'entrepot_id')) {
            Schema::table('secteurs', function (Blueprint $table) {
                $table->foreignId('entrepot_id')->nullable()->constrained('entrepots')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('com_four_entete', 'entrepot_id')) {
            Schema::table('com_four_entete', function (Blueprint $table) {
                $table->foreignId('entrepot_id')->nullable()->constrained('entrepots')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['com_four_entete', 'secteurs'] as $table) {
            if (Schema::hasColumn($table, 'entrepot_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('entrepot_id'));
            }
        }
    }
};
