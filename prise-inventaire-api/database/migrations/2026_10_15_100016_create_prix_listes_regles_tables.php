<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prix et remises (plan de parité fripv2, phase 1.2a) : listes de prix (formule sur le prix de base et
 * prix saisis par produit), liste de prix d'un client et règles de prix (prix client, paliers de quantité,
 * promotions). Tables neuves ; seule une colonne facultative (liste_prix_id) s'ajoute aux clients. Le
 * prix de base reste produits.prix_unitaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listes_prix', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('nom', 100);
            $table->string('description')->nullable();
            // Prix d'un produit sans prix saisi : prix de base × (1 + ajustement / 100). Ex. -10 pour 10 % de moins.
            $table->decimal('ajustement_pourcentage', 8, 4)->default(0);
            $table->unsignedTinyInteger('precision')->default(2);
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'nom']);
        });

        Schema::create('listes_prix_produits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('liste_prix_id')->constrained('listes_prix')->cascadeOnDelete();
            $table->unsignedBigInteger('produit_id')->index();
            $table->decimal('prix', 15, 4);
            $table->timestamps();

            $table->unique(['liste_prix_id', 'produit_id'], 'listes_prix_produits_unique');
        });

        // Sans clé étrangère : une liste supprimée détache ses clients (fait par l'API).
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('liste_prix_id')->nullable()->index();
        });

        Schema::create('regles_prix', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('libelle', 150);
            // Portée : chaque critère vide veut dire « tous ».
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('liste_prix_id')->nullable()->index();
            $table->unsignedBigInteger('produit_id')->nullable()->index();
            $table->string('categorie_produit', 100)->nullable();
            // Conditions.
            $table->unsignedInteger('quantite_min')->default(1);
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            // Effet : prix_fixe, remise_pourcentage ou remise_montant (sur le prix de la liste du client).
            $table->string('type', 20);
            $table->decimal('valeur', 15, 4);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regles_prix');
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['liste_prix_id']);
            $table->dropColumn('liste_prix_id');
        });
        Schema::dropIfExists('listes_prix_produits');
        Schema::dropIfExists('listes_prix');
    }
};
