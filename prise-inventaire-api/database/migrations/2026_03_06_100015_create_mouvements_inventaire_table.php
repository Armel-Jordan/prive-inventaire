<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvements_inventaire', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('produit_id');
            $table->enum('type_mouvement', [
                'reservation',
                'annulation_reservation',
                'sortie_preparation',
                'chargement_camion',
                'livraison_client',
                'retour_camion',
                'retrait_client'
            ]);
            $table->integer('quantite');
            $table->enum('localisation_source_type', ['secteur', 'zone_preparation', 'camion'])->nullable();
            $table->unsignedBigInteger('localisation_source_id')->nullable();
            $table->enum('localisation_dest_type', ['secteur', 'zone_preparation', 'camion', 'client'])->nullable();
            $table->unsignedBigInteger('localisation_dest_id')->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('motif')->nullable();
            $table->unsignedBigInteger('effectue_par')->nullable();
            $table->timestamps();

            $table->index(['produit_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_inventaire');
    }
};
