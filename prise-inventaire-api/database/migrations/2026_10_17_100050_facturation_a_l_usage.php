<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturation à l'usage (plan de parité fripv2, phase 1.8 : abonnements facturés à la consommation) : un abonnement
 * peut facturer, à chaque échéance, la quantité relevée sur la période (consommation saisie ou importée) au lieu
 * d'une quantité fixe, au-delà d'une quantité incluse.
 *
 * Ajouts seulement : abonnements_clients.mode_quantite (fixe par défaut) et quantite_incluse ; table
 * abonnement_releves ; abonnement_echeances.rien_a_facturer (consommation couverte par la quantité incluse).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('abonnements_clients', 'mode_quantite')) {
            Schema::table('abonnements_clients', function (Blueprint $table) {
                // fixe : la quantité de l'abonnement ; releve : la consommation relevée sur la période.
                $table->string('mode_quantite', 10)->default('fixe');
                $table->unsignedInteger('quantite_incluse')->default(0);
            });
        }
        if (! Schema::hasTable('abonnement_releves')) {
            Schema::create('abonnement_releves', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('abonnement_id')->constrained('abonnements_clients')->cascadeOnDelete();
                $table->date('date_releve');
                $table->unsignedInteger('quantite');
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('cree_par')->nullable();
                $table->timestamps();

                $table->index(['abonnement_id', 'date_releve']);
            });
        }
        if (! Schema::hasColumn('abonnement_echeances', 'rien_a_facturer')) {
            Schema::table('abonnement_echeances', function (Blueprint $table) {
                $table->boolean('rien_a_facturer')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('abonnement_echeances', 'rien_a_facturer')) {
            Schema::table('abonnement_echeances', fn (Blueprint $table) => $table->dropColumn('rien_a_facturer'));
        }
        Schema::dropIfExists('abonnement_releves');
        if (Schema::hasColumn('abonnements_clients', 'mode_quantite')) {
            Schema::table('abonnements_clients', fn (Blueprint $table) => $table->dropColumn(['mode_quantite', 'quantite_incluse']));
        }
    }
};
