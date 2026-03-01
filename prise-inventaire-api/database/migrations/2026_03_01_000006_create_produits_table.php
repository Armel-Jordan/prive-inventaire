<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produits', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 50)->unique();
            $table->string('description', 255);
            $table->string('mesure', 20)->default('UN');
            $table->string('type', 50)->nullable();
            $table->string('categorie', 100)->nullable();
            $table->decimal('prix_unitaire', 15, 4)->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
