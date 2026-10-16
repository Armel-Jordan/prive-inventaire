<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taux de taxe à 4 décimales au lieu de 2 : certains taux en ont 3 (TVQ du Québec : 9,975 %),
 * que decimal(5,2) arrondissait à 9,98 %. Élargissement seulement : les taux existants
 * ne changent pas de valeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_taxes', function (Blueprint $table) {
            $table->decimal('taux', 8, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_taxes', function (Blueprint $table) {
            $table->decimal('taux', 5, 2)->default(0)->change();
        });
    }
};
