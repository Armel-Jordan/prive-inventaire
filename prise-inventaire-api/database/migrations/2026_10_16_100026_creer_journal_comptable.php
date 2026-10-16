<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal général (plan de parité fripv2, phase 4.2) : journaux de l'entreprise, écritures en partie double et
 * leurs lignes. Les soldes, le grand livre et la balance se calculent à partir des lignes. Tables neuves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journaux_comptables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 10);
            $table->string('libelle', 100);
            // general, ventes, achats, tresorerie, ouverture
            $table->string('type', 20)->default('general');
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('ecritures_comptables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('journal_id')->constrained('journaux_comptables')->restrictOnDelete();
            $table->foreignId('exercice_id')->constrained('exercices_comptables')->restrictOnDelete();
            $table->foreignId('periode_id')->constrained('periodes_comptables')->restrictOnDelete();
            // Code du journal, année de début de l'exercice, rang : OD-2026-00001.
            $table->string('numero', 40);
            $table->date('date');
            $table->string('libelle', 255);
            $table->string('reference', 100)->nullable();
            // manuelle, ouverture, cloture, recurrente
            $table->string('source', 20)->default('manuelle');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('contrepassation_de')->nullable()->constrained('ecritures_comptables')->nullOnDelete();
            $table->decimal('total', 15, 2)->default(0);
            $table->unsignedBigInteger('creee_par')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'numero']);
            $table->index(['tenant_id', 'date']);
        });

        Schema::create('ecritures_comptables_lignes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('ecriture_id')->constrained('ecritures_comptables')->cascadeOnDelete();
            $table->foreignId('compte_id')->constrained('comptes_comptables')->restrictOnDelete();
            $table->string('libelle', 255)->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->unsignedSmallInteger('ordre')->default(0);

            $table->index(['tenant_id', 'compte_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecritures_comptables_lignes');
        Schema::dropIfExists('ecritures_comptables');
        Schema::dropIfExists('journaux_comptables');
    }
};
