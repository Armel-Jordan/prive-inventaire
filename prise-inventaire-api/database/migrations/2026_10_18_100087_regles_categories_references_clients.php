<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prix et clients (plan de parité fripv2, phase 1.2c) : une règle de prix peut viser une catégorie de clients ; un client
 * a ses propres références pour les produits qu'il achète (reprises sur ses devis, commandes, factures et dans
 * l'espace client).
 *
 * Ajouts seulement : regles_prix.categorie_client_id, table client_produits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('regles_prix', 'categorie_client_id')) {
            Schema::table('regles_prix', function (Blueprint $table) {
                // Catégorie de clients visée (vide : tous les clients). Une catégorie utilisée ne se supprime pas.
                $table->unsignedBigInteger('categorie_client_id')->nullable()->after('client_id')->index();
            });
        }

        if (! Schema::hasTable('client_produits')) {
            Schema::create('client_produits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
                // Référence (et désignation) du produit chez le client : son numéro d'article, sa façon de le nommer.
                $table->string('reference', 60);
                $table->string('description', 255)->nullable();
                $table->timestamps();

                $table->unique(['client_id', 'produit_id']);
                $table->unique(['client_id', 'reference']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_produits');
        if (Schema::hasColumn('regles_prix', 'categorie_client_id')) {
            Schema::table('regles_prix', fn (Blueprint $table) => $table->dropColumn('categorie_client_id'));
        }
    }
};
