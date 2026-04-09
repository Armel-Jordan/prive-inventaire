<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_parametres', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();

            // Informations entreprise
            $table->string('nom_entreprise')->nullable();
            $table->text('adresse')->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('siret', 20)->nullable();
            $table->string('tva_numero', 50)->nullable();
            $table->string('logo_url')->nullable();

            // Devise & TVA
            $table->string('devise_symbole', 5)->default('€');
            $table->string('devise_code', 5)->default('EUR');
            $table->decimal('tva_taux', 5, 2)->default(20.00);

            // Délais par défaut
            $table->unsignedSmallInteger('delai_paiement_jours')->default(30);
            $table->unsignedSmallInteger('delai_livraison_jours')->default(7);

            // Alertes stock
            $table->string('stock_alerte_email')->nullable();
            $table->unsignedSmallInteger('stock_seuil_defaut')->default(5);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_parametres');
    }
};
