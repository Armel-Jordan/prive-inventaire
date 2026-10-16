<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paramètres d'une tâche planifiée (ex. délai en jours d'une alerte « facture impayée »), réglés par chaque
 * entreprise. Les valeurs par défaut sont celles du catalogue App\Support\TachesPlanifiees.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('taches_planifiees', 'parametres')) {
            return;
        }

        Schema::table('taches_planifiees', function (Blueprint $table) {
            $table->json('parametres')->nullable()->after('jour_du_mois');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('taches_planifiees', 'parametres')) {
            Schema::table('taches_planifiees', function (Blueprint $table) {
                $table->dropColumn('parametres');
            });
        }
    }
};
