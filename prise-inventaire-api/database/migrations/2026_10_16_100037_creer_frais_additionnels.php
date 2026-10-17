<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frais additionnels et de livraison (plan de parité fripv2, phase 1.4) : frais que les devis, commandes et factures
 * proposent d'eux-mêmes, comme une ligne du produit qui les porte (manutention, emballage, matière dangereuse,
 * livraison…). Montant fixe ou pourcentage, sur tout document, sur les documents qui contiennent certains produits, ou
 * comme frais de livraison choisi dans la fiche d'un client.
 *
 * Ajouts seulement : tables frais et frais_produits, clients.frais_livraison_id. Réglage réservé à la configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('frais')) {
            Schema::create('frais', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('libelle', 100);
                // Produit (de préférence un service) qui porte la ligne ajoutée aux documents.
                $table->unsignedBigInteger('produit_id');
                // montant (fixe, dans la devise de l'entreprise) ou pourcentage (du montant HT des lignes concernées)
                $table->string('mode', 20);
                $table->decimal('valeur', 12, 4);
                // document (tout document qui contient des produits), produits (seulement ceux associés au frais),
                // livraison (frais de livraison choisi dans la fiche d'un client)
                $table->string('application', 20);
                // Une fois par document, ou une fois par ligne concernée (montant fixe).
                $table->string('repetition', 20)->default('document');
                $table->boolean('sur_devis')->default(true);
                $table->boolean('sur_commandes')->default(true);
                $table->boolean('sur_factures')->default(true);
                $table->boolean('actif')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('frais_produits')) {
            Schema::create('frais_produits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('frais_id')->constrained('frais')->cascadeOnDelete();
                $table->unsignedBigInteger('produit_id')->index();
                $table->unique(['frais_id', 'produit_id']);
            });
        }

        if (! Schema::hasColumn('clients', 'frais_livraison_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->unsignedBigInteger('frais_livraison_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'frais_livraison_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('frais_livraison_id');
            });
        }
        Schema::dropIfExists('frais_produits');
        Schema::dropIfExists('frais');
    }
};
