<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produit_fournisseur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produit_id')->constrained('produits')->onDelete('cascade');
            $table->foreignId('fournisseur_id')->constrained('fournisseurs')->onDelete('cascade');
            $table->string('reference_fournisseur', 50)->nullable();
            $table->decimal('prix_achat', 10, 2)->nullable();
            $table->integer('delai_livraison')->nullable();
            $table->boolean('fournisseur_principal')->default(false);
            $table->timestamps();

            $table->unique(['produit_id', 'fournisseur_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_fournisseur');
    }
};
