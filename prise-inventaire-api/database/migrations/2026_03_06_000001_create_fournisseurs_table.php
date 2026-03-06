<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fournisseurs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('raison_sociale', 255);
            $table->text('adresse')->nullable();
            $table->string('telephone', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('contact_nom', 100)->nullable();
            $table->string('contact_telephone', 20)->nullable();
            $table->string('conditions_paiement', 100)->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fournisseurs');
    }
};
