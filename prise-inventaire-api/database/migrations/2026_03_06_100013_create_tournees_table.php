<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournees', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique();
            $table->date('date_tournee');
            $table->foreignId('camion_id')->nullable()->constrained('camions')->onDelete('set null');
            $table->unsignedBigInteger('livreur_id')->nullable();
            $table->string('zone', 100)->nullable();
            $table->enum('statut', ['planifiee', 'en_cours', 'terminee', 'annulee'])->default('planifiee');
            $table->time('heure_depart')->nullable();
            $table->time('heure_retour')->nullable();
            $table->integer('km_depart')->nullable();
            $table->integer('km_retour')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournees');
    }
};
