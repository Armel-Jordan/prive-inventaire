<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvement_relocalisation', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['arrivage', 'transfert', 'sortie', 'ajustement']);
            $table->string('produit_numero', 50);
            $table->string('produit_nom', 255)->nullable();
            $table->string('secteur_source', 100)->nullable();
            $table->string('secteur_destination', 100)->nullable();
            $table->decimal('quantite', 15, 4);
            $table->string('unite_mesure', 20)->nullable()->default('unité');
            $table->string('motif', 255)->nullable();
            $table->string('employe', 100);
            $table->dateTime('date_mouvement');
            $table->timestamps();

            $table->index('type');
            $table->index('produit_numero');
            $table->index('secteur_source');
            $table->index('secteur_destination');
            $table->index('date_mouvement');
            $table->index('employe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvement_relocalisation');
    }
};
