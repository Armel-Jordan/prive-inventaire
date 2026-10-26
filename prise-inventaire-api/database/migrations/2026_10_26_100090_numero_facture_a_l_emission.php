<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numéro de facture attribué à l'émission (anomalie A11, réglage de l'entreprise, inactif par défaut) : un brouillon
 * porte un numéro provisoire et ne consomme la séquence qu'à son émission ; un brouillon supprimé ne laisse plus de
 * trou dans la numérotation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_parametres', 'numero_facture_a_l_emission')) {
            Schema::table('tenant_parametres', function (Blueprint $table) {
                $table->boolean('numero_facture_a_l_emission')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_parametres', 'numero_facture_a_l_emission')) {
            Schema::table('tenant_parametres', function (Blueprint $table) {
                $table->dropColumn('numero_facture_a_l_emission');
            });
        }
    }
};
