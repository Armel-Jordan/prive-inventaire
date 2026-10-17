<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chargement du camion (plan de parité fripv2, phase 1.6 : « Chargement ») : les colis se cochent comme chargés
 * (bl_colis.charge_le, déjà prévu) ; la marchandise d'un arrêt qui n'est dans aucun colis (le vrac) se coche ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tournee_bons', 'vrac_charge_le')) {
            Schema::table('tournee_bons', function (Blueprint $table) {
                $table->timestamp('vrac_charge_le')->nullable();
                $table->foreignId('vrac_charge_par')->nullable()->constrained('admin_users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tournee_bons', 'vrac_charge_le')) {
            Schema::table('tournee_bons', function (Blueprint $table) {
                $table->dropConstrainedForeignId('vrac_charge_par');
                $table->dropColumn('vrac_charge_le');
            });
        }
    }
};
