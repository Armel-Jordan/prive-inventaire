<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('com_client_entete', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique();
            $table->foreignId('client_id')->constrained('clients')->onDelete('restrict');
            $table->date('date_commande');
            $table->date('date_livraison_souhaitee')->nullable();
            $table->enum('statut', [
                'brouillon',
                'en_attente',
                'acceptee',
                'refusee',
                'facturee',
                'annulee',
            ])->default('brouillon');
            $table->decimal('remise_globale', 5, 2)->default(0);
            $table->decimal('montant_ht', 12, 2)->default(0);
            $table->decimal('montant_tva', 12, 2)->default(0);
            $table->decimal('montant_ttc', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('motif_refus')->nullable();
            $table->unsignedBigInteger('validee_par')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('com_client_entete');
    }
};
