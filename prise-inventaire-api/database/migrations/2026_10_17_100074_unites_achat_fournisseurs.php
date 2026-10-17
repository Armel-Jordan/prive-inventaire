<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unités d'achat par fournisseur (plan de parité fripv2, phase 2.2, suite) : un fournisseur livre un produit dans son
 * conditionnement (la caisse de 24 chez l'un, de 12 chez l'autre) ; la commande fournisseur le reprend.
 *
 * Ajouts seulement : produit_fournisseur.unite_achat et qte_par_unite_achat. Vides, le conditionnement du produit
 * s'applique, comme avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produit_fournisseur', function (Blueprint $table) {
            if (! Schema::hasColumn('produit_fournisseur', 'unite_achat')) {
                $table->string('unite_achat', 30)->nullable();
            }
            if (! Schema::hasColumn('produit_fournisseur', 'qte_par_unite_achat')) {
                // Unités de stock dans une unité d'achat de ce fournisseur.
                $table->unsignedSmallInteger('qte_par_unite_achat')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('produit_fournisseur', function (Blueprint $table) {
            foreach (['unite_achat', 'qte_par_unite_achat'] as $colonne) {
                if (Schema::hasColumn('produit_fournisseur', $colonne)) {
                    $table->dropColumn($colonne);
                }
            }
        });
    }
};
