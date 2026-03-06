<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('raison_sociale', 255);
            $table->text('adresse_facturation');
            $table->text('adresse_livraison')->nullable();
            $table->string('ville', 100);
            $table->string('code_postal', 10);
            $table->string('telephone', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('contact_nom', 100)->nullable();
            $table->string('contact_telephone', 20)->nullable();
            $table->decimal('encours_max', 12, 2)->nullable();
            $table->decimal('encours_actuel', 12, 2)->default(0);
            $table->decimal('taux_remise_global', 5, 2)->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
