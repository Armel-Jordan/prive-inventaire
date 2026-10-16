<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comptabilité générale (plan de parité fripv2, phase 4.1) : plan comptable de l'entreprise, exercices et
 * périodes comptables. Le plan est générique : il part d'un plan type (SYSCOHADA, PCG, canadien) ou d'un
 * fichier, et chaque compte porte sa nature (place dans le bilan ou le compte de résultat). Tables neuves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comptes_comptables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('numero', 20);
            $table->string('libelle', 150);
            $table->foreignId('parent_id')->nullable()->constrained('comptes_comptables')->restrictOnDelete();
            // actif, passif, capitaux_propres, produit, charge
            $table->string('nature', 20);
            // standard, en_tete, clients, fournisseurs, tresorerie, resultat
            $table->string('type', 20)->default('standard');
            $table->boolean('saisie_manuelle')->default(true);
            $table->boolean('actif')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'numero']);
        });

        Schema::create('exercices_comptables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('libelle', 50);
            $table->date('date_debut');
            $table->date('date_fin');
            // ouvert, cloture
            $table->string('statut', 20)->default('ouvert');
            $table->dateTime('cloture_le')->nullable();
            $table->unsignedBigInteger('cloture_par')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'date_debut']);
        });

        Schema::create('periodes_comptables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('exercice_id')->constrained('exercices_comptables')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->date('date_debut');
            $table->date('date_fin');
            // Une période fermée n'accepte plus d'écriture.
            $table->boolean('fermee')->default(false);
            $table->dateTime('fermee_le')->nullable();
            $table->unsignedBigInteger('fermee_par')->nullable();
            $table->timestamps();

            $table->unique(['exercice_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodes_comptables');
        Schema::dropIfExists('exercices_comptables');
        Schema::dropIfExists('comptes_comptables');
    }
};
