<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Champs personnalisés (plan de parité fripv2, phase 0.8) : chaque entreprise ajoute ses propres
 * champs aux fiches (clients, fournisseurs, produits, employés) et aux documents (devis, commandes,
 * factures…), puis en saisit les valeurs fiche par fiche. Tables neuves : rien d'existant ne change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('champs_personnalises', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('entite', 40);
            $table->string('code', 60);
            $table->string('libelle', 100);
            // texte, texte_long, nombre, date, case, liste, liste_multiple, courriel, url, telephone
            $table->string('type', 20);
            // Choix d'une liste (texte JSON) ; affichage d'un nombre (nombre, montant, pourcentage) et décimales.
            $table->json('options')->nullable();
            $table->string('format', 20)->nullable();
            $table->unsignedTinyInteger('decimales')->nullable();
            $table->boolean('obligatoire')->default(false);
            $table->boolean('sans_doublon')->default(false);
            $table->string('valeur_defaut', 255)->nullable();
            $table->string('aide', 240)->nullable();
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'entite', 'code'], 'champs_personnalises_code_unique');
        });

        Schema::create('champs_personnalises_valeurs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('champ_personnalise_id')->constrained('champs_personnalises')->cascadeOnDelete();
            $table->unsignedBigInteger('entite_id');
            // Texte, nombre (point décimal), date (AAAA-MM-JJ), case (1/0), choix multiples en JSON.
            $table->text('valeur')->nullable();
            $table->timestamps();

            $table->unique(['champ_personnalise_id', 'entite_id'], 'champs_personnalises_valeurs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('champs_personnalises_valeurs');
        Schema::dropIfExists('champs_personnalises');
    }
};
