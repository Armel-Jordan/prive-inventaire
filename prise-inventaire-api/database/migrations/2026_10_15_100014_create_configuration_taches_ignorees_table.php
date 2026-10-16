<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuration à compléter (plan de parité fripv2, phase 0.11) : les réglages recommandés qu'une
 * entreprise écarte parce qu'ils ne la concernent pas, avec la raison et l'auteur. Le reste du
 * parcours est calculé à partir des données ; rien d'autre n'est stocké. Table neuve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_taches_ignorees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('cle', 60);
            $table->string('raison');
            $table->unsignedBigInteger('ignore_par')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'cle'], 'configuration_taches_ignorees_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_taches_ignorees');
    }
};
