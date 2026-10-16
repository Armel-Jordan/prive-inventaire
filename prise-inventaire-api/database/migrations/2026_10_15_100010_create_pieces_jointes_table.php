<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pièces jointes (plan de parité fripv2, phase 0.7) : fichiers rattachés à une fiche (client,
 * fournisseur, produit, employé) ou à un document (devis, commande, facture…). Le fichier est
 * rangé sous un nom aléatoire ; le nom d'origine est conservé ici. Table neuve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pieces_jointes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // client, fournisseur, produit, employe, devis, commande_client, facture, bon_livraison, commande_fournisseur
            $table->string('entite', 40);
            $table->unsignedBigInteger('entite_id');
            $table->string('categorie', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('nom_original');
            $table->string('disque', 30);
            $table->string('chemin', 500);
            $table->string('type_mime', 150)->nullable();
            $table->unsignedBigInteger('taille')->default(0);
            $table->unsignedBigInteger('ajoute_par')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'entite', 'entite_id'], 'pieces_jointes_entite_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pieces_jointes');
    }
};
