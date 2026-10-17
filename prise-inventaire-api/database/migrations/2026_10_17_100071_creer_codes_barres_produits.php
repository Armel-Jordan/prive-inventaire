<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Codes-barres des produits (plan de parité fripv2, phase 2.1) : un produit a plusieurs codes (EAN-13, EAN-8, UPC-A,
 * Code 128 ou interne), chacun pour une quantité (le carton de 12 a son propre code) ; un scan d'un de ces codes compte
 * pour son produit.
 *
 * Ajouts seulement : table produit_codes_barres (code unique par entreprise) ; inventaire_scan.code_lu (le code lu,
 * quand il a été rapporté au numéro de son produit). Les droits sont ceux des produits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('produit_codes_barres')) {
            Schema::create('produit_codes_barres', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
                $table->string('code', 50);
                // ean13, ean8, upca, code128, interne
                $table->string('type', 10);
                // Unités du produit comptées par lecture du code (1 : l'unité ; 12 : le carton).
                $table->decimal('quantite', 12, 3)->default(1);
                $table->boolean('principal')->default(false);
                $table->string('notes', 255)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'code'], 'produit_codes_barres_code_unique');
            });
        }
        Schema::table('inventaire_scan', function (Blueprint $table) {
            if (! Schema::hasColumn('inventaire_scan', 'code_lu')) {
                $table->string('code_lu', 50)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_codes_barres');
        if (Schema::hasColumn('inventaire_scan', 'code_lu')) {
            Schema::table('inventaire_scan', fn (Blueprint $table) => $table->dropColumn('code_lu'));
        }
    }
};
