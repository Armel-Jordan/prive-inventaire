<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approbations', function (Blueprint $table) {
            $table->id();
            $table->string('type_mouvement', 20); // arrivage, transfert, sortie
            $table->string('produit_numero', 50);
            $table->string('produit_nom', 200)->nullable();
            $table->string('secteur_source', 50)->nullable();
            $table->string('secteur_destination', 50)->nullable();
            $table->decimal('quantite', 15, 3);
            $table->string('unite_mesure', 20)->nullable();
            $table->string('motif', 255)->nullable();
            $table->string('demandeur', 100);
            $table->string('statut', 20)->default('en_attente'); // en_attente, approuve, rejete
            $table->string('approbateur', 100)->nullable();
            $table->dateTime('date_decision')->nullable();
            $table->text('commentaire_approbateur')->nullable();
            $table->decimal('seuil_declenchement', 15, 3)->nullable();
            $table->timestamps();

            $table->index('statut');
            $table->index('demandeur');
            $table->index('created_at');
        });

        // Ajouter une colonne pour le seuil d'approbation dans les paramètres
        Schema::create('parametres_approbation', function (Blueprint $table) {
            $table->id();
            $table->string('type_mouvement', 20);
            $table->decimal('seuil_quantite', 15, 3)->default(100);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approbations');
        Schema::dropIfExists('parametres_approbation');
    }
};
