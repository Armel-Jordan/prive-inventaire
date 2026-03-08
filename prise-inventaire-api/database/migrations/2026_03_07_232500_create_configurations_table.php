<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configurations', function (Blueprint $table) {
            $table->id();
            $table->string('entite', 50); // produit, employe, secteur
            $table->string('prefixe', 10)->default('');
            $table->string('suffixe', 10)->default('');
            $table->integer('longueur')->default(5);
            $table->string('separateur', 5)->default('');
            $table->boolean('auto_increment')->default(true);
            $table->integer('prochain_numero')->default(1);
            $table->timestamps();

            $table->unique('entite');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configurations');
    }
};
