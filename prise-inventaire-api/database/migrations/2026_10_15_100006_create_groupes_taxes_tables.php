<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groupes de taxes (plan de parité fripv2, phase 0.4) : combinaisons ordonnées des taxes
 * déjà déclarées (tenant_taxes), ex. TPS + TVQ, TVA + CAC (taxe sur la taxe). Tables neuves :
 * le calcul actuel des documents n'est pas modifié.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groupes_taxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 20);
            $table->string('nom', 100);
            $table->boolean('par_defaut')->default(false);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('groupe_taxe_lignes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('groupe_taxe_id')->constrained('groupes_taxes')->cascadeOnDelete();
            $table->foreignId('tenant_taxe_id')->constrained('tenant_taxes');
            $table->unsignedTinyInteger('ordre')->default(0);
            // base, base_et_taxes_precedentes, taxe_precedente
            $table->string('applique_sur', 30)->default('base');
            $table->timestamps();

            $table->unique(['groupe_taxe_id', 'tenant_taxe_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groupe_taxe_lignes');
        Schema::dropIfExists('groupes_taxes');
    }
};
