<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facture_paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facture_id')->constrained('factures')->onDelete('cascade');
            $table->foreignId('echeance_id')->nullable()->constrained('facture_echeances')->onDelete('set null');
            $table->date('date_paiement');
            $table->decimal('montant', 12, 2);
            $table->enum('mode_paiement', ['especes', 'cheque', 'virement', 'carte', 'autre'])->default('virement');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('enregistre_par')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facture_paiements');
    }
};
