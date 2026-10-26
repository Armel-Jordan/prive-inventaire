<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frais des transporteurs (plan de parité fripv2, phase 1.11b, options 15 et 21) :
 * - « Livraison facturée » (vrai par défaut) : décochée, le frais de livraison du client n'est pas proposé sur les
 *   documents de vente expédiés par ce transporteur ;
 * - frais de transport d'achat : un frais de livraison proposé en ligne « Frais de transport » sur les factures des
 *   fournisseurs livrés par ce transporteur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transporteurs', function (Blueprint $table) {
            if (! Schema::hasColumn('transporteurs', 'frais_livraison_applicable')) {
                $table->boolean('frais_livraison_applicable')->default(true);
            }
            if (! Schema::hasColumn('transporteurs', 'frais_livraison_achat_id')) {
                $table->unsignedBigInteger('frais_livraison_achat_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transporteurs', function (Blueprint $table) {
            if (Schema::hasColumn('transporteurs', 'frais_livraison_achat_id')) {
                $table->dropIndex('transporteurs_frais_livraison_achat_id_index');
                $table->dropColumn('frais_livraison_achat_id');
            }
            if (Schema::hasColumn('transporteurs', 'frais_livraison_applicable')) {
                $table->dropColumn('frais_livraison_applicable');
            }
        });
    }
};
