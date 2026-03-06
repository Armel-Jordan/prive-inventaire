<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournee_bons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournee_id')->constrained('tournees')->onDelete('cascade');
            $table->foreignId('bon_livraison_id')->constrained('bons_livraison')->onDelete('cascade');
            $table->integer('ordre_livraison')->default(1);
            $table->time('heure_livraison')->nullable();
            $table->enum('statut', ['en_attente', 'livre', 'echec'])->default('en_attente');
            $table->text('motif_echec')->nullable();
            $table->timestamps();

            $table->unique(['tournee_id', 'bon_livraison_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournee_bons');
    }
};
