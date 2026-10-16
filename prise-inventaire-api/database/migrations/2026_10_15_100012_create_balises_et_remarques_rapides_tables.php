<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Balises et remarques rapides (plan de parité fripv2, phase 0.9).
 * - Balises : étiquettes de classement définies par l'entreprise (Région → Nord, Sud…), à un
 *   choix ou à plusieurs, posées sur les fiches et documents, puis utilisées pour retrouver.
 * - Remarques rapides : textes réutilisables insérés dans les notes des documents.
 * Tables neuves : rien d'existant n'est modifié.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balises', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('nom', 60);
            // choix : un seul item par fiche ; options : plusieurs.
            $table->string('type', 10)->default('choix');
            // Types de fiche concernés (client, produit, facture…).
            $table->json('entites');
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('balise_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('balise_id')->constrained('balises')->cascadeOnDelete();
            $table->string('libelle', 60);
            $table->string('couleur', 10)->default('gris');
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('balise_affectations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('balise_item_id')->constrained('balise_items')->cascadeOnDelete();
            $table->string('entite', 40);
            $table->unsignedBigInteger('entite_id');
            $table->timestamps();

            $table->unique(['balise_item_id', 'entite', 'entite_id'], 'balise_affectations_unique');
            $table->index(['tenant_id', 'entite', 'entite_id'], 'balise_affectations_fiche_index');
        });

        Schema::create('remarques_rapides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // Classement libre (ne restreint rien) ; vide = non classée.
            $table->string('categorie', 60)->nullable();
            $table->text('texte');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remarques_rapides');
        Schema::dropIfExists('balise_affectations');
        Schema::dropIfExists('balise_items');
        Schema::dropIfExists('balises');
    }
};
