<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relances des devis restés sans réponse (plan de parité fripv2, phase 1.3) : nombre de relances envoyées et date de
 * la dernière, pour la tâche planifiée « relances_devis » et la fiche du devis.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('devis', 'relances')) {
            Schema::table('devis', function (Blueprint $table) {
                $table->unsignedSmallInteger('relances')->default(0);
                $table->timestamp('derniere_relance_le')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('devis', 'relances')) {
            Schema::table('devis', function (Blueprint $table) {
                $table->dropColumn(['relances', 'derniere_relance_le']);
            });
        }
    }
};
