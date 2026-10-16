<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche client complète (plan de parité fripv2, phase 1.1) : catégories de clients, contacts et adresses
 * multiples, remarques datées et clients favoris de chaque utilisateur. Tables neuves ; seule une colonne
 * facultative (categorie_id) s'ajoute aux clients. Les coordonnées actuelles de la fiche ne changent pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('nom', 100);
            $table->string('description')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'nom']);
        });

        // Sans clé étrangère : une catégorie supprimée détache ses clients (fait par l'API).
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('categorie_id')->nullable()->index();
        });

        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('nom', 150);
            $table->string('fonction', 100)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('telephone_secondaire', 30)->nullable();
            $table->string('email')->nullable();
            $table->boolean('principal')->default(false);
            $table->boolean('recoit_factures')->default(false);
            $table->boolean('recoit_commandes')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('client_adresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('libelle', 100);
            $table->text('adresse');
            $table->string('ville', 100)->nullable();
            $table->string('code_postal', 20)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('pays', 100)->nullable();
            $table->boolean('livraison')->default(true);
            $table->boolean('principale')->default(false);
            $table->text('instructions')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('client_remarques', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->unsignedBigInteger('auteur_id')->nullable();
            $table->text('texte');
            $table->boolean('epinglee')->default(false);
            $table->timestamps();
        });

        Schema::create('client_favoris', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_favoris');
        Schema::dropIfExists('client_remarques');
        Schema::dropIfExists('client_adresses');
        Schema::dropIfExists('client_contacts');
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['categorie_id']);
            $table->dropColumn('categorie_id');
        });
        Schema::dropIfExists('client_categories');
    }
};
