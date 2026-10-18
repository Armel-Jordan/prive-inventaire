<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Filtres mémorisés par usager (plan de parité fripv2, phase 5.3) : chaque compte enregistre sous un nom les filtres
 * d'un écran (rapport de la bibliothèque, historique des scans, clients…) et peut en marquer un « par défaut », appliqué
 * à l'ouverture de l'écran.
 *
 * Ajouts seulement : table filtres_memorises. Données personnelles du compte : aucun droit de module.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('filtres_memorises')) {
            return;
        }
        Schema::create('filtres_memorises', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
            // Écran des filtres : « scans », « clients », « rapport:ventes-par-periode »…
            $table->string('ecran', 80);
            $table->string('nom', 100);
            $table->json('valeurs');
            $table->boolean('par_defaut')->default(false);
            $table->timestamps();

            $table->unique(['admin_user_id', 'ecran', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filtres_memorises');
    }
};
