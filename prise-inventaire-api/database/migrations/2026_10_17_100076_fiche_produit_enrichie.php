<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche produit enrichie (plan de parité fripv2, phase 2.4) : fabricant (référentiel) et référence chez lui,
 * description longue (spécifications), image principale (une image jointe à la fiche), descriptions traduites
 * (l'espace client les montre dans la langue du visiteur).
 *
 * Ajouts seulement : colonnes de produits ; table produit_traductions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            if (! Schema::hasColumn('produits', 'fabricant_id')) {
                $table->foreignId('fabricant_id')->nullable()->constrained('fabricants')->nullOnDelete();
            }
            if (! Schema::hasColumn('produits', 'reference_fabricant')) {
                $table->string('reference_fabricant', 60)->nullable();
            }
            if (! Schema::hasColumn('produits', 'description_longue')) {
                $table->text('description_longue')->nullable();
            }
            if (! Schema::hasColumn('produits', 'image_id')) {
                // Image principale : une des images jointes à la fiche.
                $table->foreignId('image_id')->nullable()->constrained('pieces_jointes')->nullOnDelete();
            }
        });

        if (! Schema::hasTable('produit_traductions')) {
            Schema::create('produit_traductions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
                $table->string('langue', 5);
                $table->string('description', 255)->nullable();
                $table->text('description_longue')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'produit_id', 'langue'], 'produit_traductions_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_traductions');
        Schema::table('produits', function (Blueprint $table) {
            foreach (['fabricant_id', 'image_id'] as $cle) {
                if (Schema::hasColumn('produits', $cle)) {
                    $table->dropConstrainedForeignId($cle);
                }
            }
            foreach (['reference_fabricant', 'description_longue'] as $colonne) {
                if (Schema::hasColumn('produits', $colonne)) {
                    $table->dropColumn($colonne);
                }
            }
        });
    }
};
