<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche fournisseur complète (plan de parité fripv2, phase 3.1) : contacts et adresses multiples,
 * coordonnées bancaires et remarques datées. Tables neuves ; la fiche et les commandes existantes ne
 * changent pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fournisseur_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('fournisseur_id')->constrained('fournisseurs')->cascadeOnDelete();
            $table->string('nom', 150);
            $table->string('fonction', 100)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('telephone_secondaire', 30)->nullable();
            $table->string('email')->nullable();
            $table->boolean('principal')->default(false);
            $table->boolean('recoit_commandes')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('fournisseur_adresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('fournisseur_id')->constrained('fournisseurs')->cascadeOnDelete();
            $table->string('libelle', 100);
            $table->text('adresse');
            $table->string('ville', 100)->nullable();
            $table->string('code_postal', 20)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('pays', 100)->nullable();
            // Lieu où l'on vient chercher la marchandise (dépôt, usine).
            $table->boolean('enlevement')->default(false);
            $table->boolean('principale')->default(false);
            $table->text('instructions')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('fournisseur_comptes_bancaires', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('fournisseur_id')->constrained('fournisseurs')->cascadeOnDelete();
            $table->string('banque', 150);
            $table->string('agence', 150)->nullable();
            $table->string('titulaire', 150)->nullable();
            // Numéro local (RIB…) ou IBAN : au moins l'un des deux.
            $table->string('numero_compte', 50)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('bic', 11)->nullable();
            $table->string('devise', 3)->nullable();
            $table->boolean('principal')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('actif')->default(true);
            // Dernier utilisateur qui a saisi ou modifié le compte (changement de coordonnées bancaires).
            $table->unsignedBigInteger('modifie_par')->nullable();
            $table->timestamps();
        });

        Schema::create('fournisseur_remarques', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('fournisseur_id')->constrained('fournisseurs')->cascadeOnDelete();
            $table->unsignedBigInteger('auteur_id')->nullable();
            $table->text('texte');
            $table->boolean('epinglee')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fournisseur_remarques');
        Schema::dropIfExists('fournisseur_comptes_bancaires');
        Schema::dropIfExists('fournisseur_adresses');
        Schema::dropIfExists('fournisseur_contacts');
    }
};
