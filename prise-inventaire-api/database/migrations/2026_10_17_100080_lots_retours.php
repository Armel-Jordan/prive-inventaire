<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lots et numéros de série des retours (plan de parité fripv2, phase 2.8c) : une ligne de retour client ou fournisseur
 * d'un produit suivi indique le lot ou les numéros de série qui reviennent ou repartent ; le journal du lot note la
 * ligne de retour.
 *
 * Ajouts seulement : colonnes facultatives des lignes de retour et de lot_mouvements.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['retour_client_lignes', 'retour_fournisseur_lignes'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'lot_id')) {
                    $t->foreignId('lot_id')->nullable()->constrained('lots')->nullOnDelete();
                }
                if (! Schema::hasColumn($table, 'numeros_serie')) {
                    $t->json('numeros_serie')->nullable();
                }
            });
        }

        Schema::table('lot_mouvements', function (Blueprint $table) {
            if (! Schema::hasColumn('lot_mouvements', 'retour_client_ligne_id')) {
                $table->foreignId('retour_client_ligne_id')->nullable()->constrained('retour_client_lignes')->nullOnDelete();
            }
            if (! Schema::hasColumn('lot_mouvements', 'retour_fournisseur_ligne_id')) {
                $table->foreignId('retour_fournisseur_ligne_id')->nullable()->constrained('retour_fournisseur_lignes')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        foreach (['retour_client_ligne_id', 'retour_fournisseur_ligne_id'] as $cle) {
            if (Schema::hasColumn('lot_mouvements', $cle)) {
                Schema::table('lot_mouvements', fn (Blueprint $t) => $t->dropConstrainedForeignId($cle));
            }
        }
        foreach (['retour_client_lignes', 'retour_fournisseur_lignes'] as $table) {
            if (Schema::hasColumn($table, 'lot_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('lot_id'));
            }
            if (Schema::hasColumn($table, 'numeros_serie')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('numeros_serie'));
            }
        }
    }
};
