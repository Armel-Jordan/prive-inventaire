<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devis', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 30)->unique();
            $table->foreignId('client_id')->constrained('clients');
            $table->date('date_devis');
            $table->date('date_validite');
            $table->enum('statut', ['brouillon', 'envoye', 'accepte', 'refuse', 'expire'])->default('brouillon');
            $table->decimal('montant_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('devis_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devis_id')->constrained('devis')->onDelete('cascade');
            $table->foreignId('produit_id')->constrained('produits');
            $table->integer('quantite');
            $table->decimal('prix_unitaire', 12, 2);
            $table->decimal('montant_ligne', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devis_lignes');
        Schema::dropIfExists('devis');
    }
};
