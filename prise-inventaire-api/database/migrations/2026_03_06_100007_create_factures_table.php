<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique();
            $table->foreignId('commande_id')->nullable()->constrained('com_client_entete')->onDelete('restrict');
            $table->foreignId('client_id')->constrained('clients')->onDelete('restrict');
            $table->unsignedBigInteger('facture_mere_id')->nullable();
            $table->date('date_facture');
            $table->date('date_echeance')->nullable();
            $table->enum('statut', [
                'brouillon',
                'emise',
                'partiellement_payee',
                'payee',
                'annulee',
            ])->default('brouillon');
            $table->decimal('montant_ht', 12, 2)->default(0);
            $table->decimal('montant_tva', 12, 2)->default(0);
            $table->decimal('montant_ttc', 12, 2)->default(0);
            $table->decimal('montant_paye', 12, 2)->default(0);
            $table->decimal('reste_a_payer', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('facture_mere_id')->references('id')->on('factures')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factures');
    }
};
