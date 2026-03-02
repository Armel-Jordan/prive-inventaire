<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferts_planifies', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('transfert'); // arrivage, transfert, sortie
            $table->string('produit_numero', 50);
            $table->string('produit_nom', 200)->nullable();
            $table->string('secteur_source', 50)->nullable();
            $table->string('secteur_destination', 50)->nullable();
            $table->decimal('quantite', 15, 3);
            $table->string('unite_mesure', 20)->nullable();
            $table->string('motif', 255)->nullable();
            $table->string('employe', 100);
            $table->dateTime('date_planifiee');
            $table->string('statut', 20)->default('planifie'); // planifie, execute, annule
            $table->string('cree_par', 100)->nullable();
            $table->dateTime('execute_le')->nullable();
            $table->string('execute_par', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('date_planifiee');
            $table->index('statut');
            $table->index('produit_numero');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transferts_planifies');
    }
};
