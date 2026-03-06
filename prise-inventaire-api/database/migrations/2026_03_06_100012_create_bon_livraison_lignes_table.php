<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bon_livraison_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bon_id')->constrained('bons_livraison')->onDelete('cascade');
            $table->unsignedBigInteger('produit_id');
            $table->integer('quantite_a_livrer');
            $table->integer('quantite_preparee')->default(0);
            $table->integer('quantite_livree')->default(0);
            $table->enum('statut_ligne', [
                'a_preparer',
                'en_cours',
                'prepare',
                'charge',
                'livre'
            ])->default('a_preparer');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bon_livraison_lignes');
    }
};
