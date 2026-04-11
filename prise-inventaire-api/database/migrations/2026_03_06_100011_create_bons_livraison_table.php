<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bons_livraison', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique();
            $table->foreignId('facture_id')->constrained('factures')->onDelete('restrict');
            $table->enum('mode_livraison', ['entreprise', 'retrait_client'])->default('entreprise');
            $table->enum('statut', [
                'cree',
                'en_preparation',
                'pret',
                'en_livraison',
                'livre_complet',
                'livre_partiel',
                'annule',
            ])->default('cree');
            $table->datetime('date_preparation')->nullable();
            $table->datetime('date_pret')->nullable();
            $table->datetime('date_livraison')->nullable();
            $table->unsignedBigInteger('preparateur_id')->nullable();
            $table->text('signature_client')->nullable();
            $table->text('notes_livraison')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bons_livraison');
    }
};
