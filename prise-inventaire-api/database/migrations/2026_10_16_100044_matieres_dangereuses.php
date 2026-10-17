<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matières dangereuses et poids (plan de parité fripv2, phase 1.6) : numéro ONU, classe de danger et groupe
 * d'emballage des produits, poids et volume unitaires ; frais « matières dangereuses » appliqué d'office ;
 * téléphone d'urgence et déclaration du document de transport, propres à chaque entreprise (et à son pays).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('produits', 'numero_onu')) {
            Schema::table('produits', function (Blueprint $table) {
                $table->string('numero_onu', 10)->nullable();
                $table->string('classe_danger', 10)->nullable();
                $table->string('groupe_emballage', 5)->nullable();
                $table->decimal('poids_kg', 12, 4)->nullable();
                $table->decimal('volume_m3', 12, 6)->nullable();
            });
        }
        if (! Schema::hasColumn('frais', 'matieres_dangereuses')) {
            Schema::table('frais', function (Blueprint $table) {
                // Frais « par produit » appliqué aussi à toute matière dangereuse, sans la rattacher à la main.
                $table->boolean('matieres_dangereuses')->default(false);
            });
        }
        if (! Schema::hasColumn('tenant_parametres', 'md_telephone_urgence')) {
            Schema::table('tenant_parametres', function (Blueprint $table) {
                $table->string('md_telephone_urgence', 60)->nullable();
                $table->text('md_declaration')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('produits', 'numero_onu')) {
            Schema::table('produits', function (Blueprint $table) {
                $table->dropColumn(['numero_onu', 'classe_danger', 'groupe_emballage', 'poids_kg', 'volume_m3']);
            });
        }
        if (Schema::hasColumn('frais', 'matieres_dangereuses')) {
            Schema::table('frais', function (Blueprint $table) {
                $table->dropColumn('matieres_dangereuses');
            });
        }
        if (Schema::hasColumn('tenant_parametres', 'md_telephone_urgence')) {
            Schema::table('tenant_parametres', function (Blueprint $table) {
                $table->dropColumn(['md_telephone_urgence', 'md_declaration']);
            });
        }
    }
};
