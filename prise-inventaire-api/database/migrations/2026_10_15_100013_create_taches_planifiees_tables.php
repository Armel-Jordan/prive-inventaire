<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tâches planifiées (plan de parité fripv2, phase 0.10) : traitements automatiques prédéfinis
 * par l'application, que chaque entreprise active et règle (récurrence, heure), avec l'historique
 * de leurs exécutions. Tables neuves ; toutes les tâches sont inactives tant qu'on ne les active pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taches_planifiees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 60);
            $table->boolean('actif')->default(false);
            // horaire, quotidienne, hebdomadaire, mensuelle, une_fois
            $table->string('recurrence', 20)->default('quotidienne');
            // HH:MM, dans le fuseau de l'application ; pour « horaire », seules les minutes comptent.
            $table->string('heure', 5)->default('03:00');
            // 1 = lundi … 7 = dimanche
            $table->json('jours_semaine')->nullable();
            $table->unsignedTinyInteger('jour_du_mois')->nullable();
            $table->timestamp('prochaine_execution')->nullable();
            $table->timestamp('derniere_execution')->nullable();
            // succes, erreur
            $table->string('dernier_resultat', 10)->nullable();
            // en_attente, en_cours
            $table->string('statut', 20)->default('en_attente');
            $table->timestamp('debut_execution_courante')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'taches_planifiees_code_unique');
            $table->index(['actif', 'prochaine_execution'], 'taches_planifiees_dues_index');
        });

        Schema::create('taches_executions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('tache_planifiee_id')->constrained('taches_planifiees')->cascadeOnDelete();
            $table->string('code', 60);
            // planifie (automatique) ou manuel
            $table->string('mode', 10);
            $table->timestamp('debut');
            $table->timestamp('fin')->nullable();
            $table->unsignedInteger('duree_ms')->nullable();
            $table->string('resultat', 10)->nullable();
            $table->text('message')->nullable();
            $table->unsignedBigInteger('declenche_par')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taches_executions');
        Schema::dropIfExists('taches_planifiees');
    }
};
