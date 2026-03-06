<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produit_localisations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('produit_id');
            $table->enum('localisation_type', ['secteur', 'zone_preparation', 'camion']);
            $table->unsignedBigInteger('localisation_id');
            $table->integer('quantite')->default(0);
            $table->enum('statut', ['disponible', 'reserve', 'en_preparation', 'en_transit'])->default('disponible');
            $table->unsignedBigInteger('commande_id')->nullable();
            $table->timestamps();

            $table->index(['produit_id', 'localisation_type', 'localisation_id'], 'produit_loc_idx');
            $table->index(['localisation_type', 'localisation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_localisations');
    }
};
