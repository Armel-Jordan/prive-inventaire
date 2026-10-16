<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prise d'inventaire formelle (plan de parité fripv2, phase 2.7), par-dessus les scans : une session de
 * comptage (secteurs, période) regroupe les scans existants ; sa validation fige les quantités retenues,
 * qui deviennent l'inventaire officiel et la référence de la session suivante. Tables neuves : les scans
 * et le reste de l'application ne changent pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prises_inventaire', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('numero', 30);
            $table->string('libelle', 150);
            // en_cours, validee, annulee
            $table->string('statut', 20)->default('en_cours');
            $table->boolean('tous_secteurs')->default(false);
            // Période de comptage : les scans saisis entre ces dates comptent pour la session.
            $table->dateTime('date_debut');
            $table->dateTime('date_fin')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('creee_par')->nullable();
            $table->unsignedBigInteger('cloturee_par')->nullable();
            $table->dateTime('cloturee_le')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'numero']);
        });

        Schema::create('prises_inventaire_secteurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prise_inventaire_id')->constrained('prises_inventaire')->cascadeOnDelete();
            $table->unsignedBigInteger('secteur_id');

            $table->unique(['prise_inventaire_id', 'secteur_id'], 'prises_inventaire_secteurs_unique');
        });

        // Quantité retenue saisie par un responsable (recomptage manuel), avec sa raison, avant validation.
        Schema::create('prises_inventaire_corrections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('prise_inventaire_id')->constrained('prises_inventaire')->cascadeOnDelete();
            $table->string('secteur', 255);
            $table->string('produit_numero', 50);
            $table->decimal('quantite_retenue', 15, 4);
            $table->string('raison')->nullable();
            $table->unsignedBigInteger('auteur_id')->nullable();
            $table->timestamps();

            $table->unique(['prise_inventaire_id', 'secteur', 'produit_numero'], 'prises_inventaire_corrections_unique');
        });

        // Lignes figées à la validation : l'inventaire officiel.
        Schema::create('prises_inventaire_lignes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('prise_inventaire_id')->constrained('prises_inventaire')->cascadeOnDelete();
            $table->unsignedBigInteger('secteur_id')->nullable()->index();
            $table->string('secteur', 255);
            $table->string('produit_numero', 50)->index();
            $table->decimal('quantite_comptee', 15, 4)->default(0);
            $table->unsignedInteger('nb_scans')->default(0);
            $table->decimal('quantite_reference', 15, 4)->nullable();
            $table->unsignedBigInteger('reference_prise_id')->nullable();
            $table->decimal('quantite_retenue', 15, 4);
            $table->decimal('ecart', 15, 4)->nullable();
            $table->decimal('entrees_depuis', 15, 4)->nullable();
            $table->decimal('sorties_depuis', 15, 4)->nullable();
            $table->string('raison')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prises_inventaire_lignes');
        Schema::dropIfExists('prises_inventaire_corrections');
        Schema::dropIfExists('prises_inventaire_secteurs');
        Schema::dropIfExists('prises_inventaire');
    }
};
