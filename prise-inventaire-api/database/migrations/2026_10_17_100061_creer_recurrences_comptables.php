<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Écritures récurrentes (plan de parité fripv2, phase 4.2 : « Récurrence » et « Transactions récurrentes ») : une
 * écriture manuelle sert de modèle à des copies planifiées (loyer, amortissement, abonnement…) à intervalle régulier ;
 * les dates planifiées se convertissent en écritures, à la main ou par la tâche planifiée. Toutes les écritures d'une
 * récurrence en portent le numéro.
 *
 * Ajouts seulement : tables recurrences_comptables (modèle copié : journal, libellé, lignes) et recurrence_echeances
 * (dates planifiées et écriture créée). Les écritures créées ont la source « recurrente » et l'identifiant de leur
 * récurrence en source_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recurrences_comptables')) {
            Schema::create('recurrences_comptables', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                // Numéro de récurrence propre à l'entreprise (1, 2, …), porté par toutes ses écritures.
                $table->unsignedInteger('numero');
                $table->foreignId('ecriture_modele_id')->nullable()->constrained('ecritures_comptables')->nullOnDelete();
                $table->foreignId('journal_id')->constrained('journaux_comptables')->restrictOnDelete();
                $table->string('libelle', 255);
                $table->string('reference', 100)->nullable();
                // Lignes copiées du modèle : compte_id, libelle, debit, credit.
                $table->json('lignes');
                // journaliere, hebdomadaire, quatorze_jours, mensuelle, trimestrielle, semestrielle, annuelle
                $table->string('frequence', 20);
                $table->date('date_debut');
                $table->unsignedSmallInteger('nombre');
                $table->boolean('active')->default(true);
                $table->foreignId('cree_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['tenant_id', 'numero']);
            });
        }

        if (! Schema::hasTable('recurrence_echeances')) {
            Schema::create('recurrence_echeances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('recurrence_id')->constrained('recurrences_comptables')->cascadeOnDelete();
                $table->date('date_prevue');
                // Écriture créée par la conversion de cette date (vide : pas encore convertie).
                $table->foreignId('ecriture_id')->nullable()->constrained('ecritures_comptables')->nullOnDelete();
                $table->timestamps();

                $table->index(['recurrence_id', 'date_prevue']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrence_echeances');
        Schema::dropIfExists('recurrences_comptables');
    }
};
