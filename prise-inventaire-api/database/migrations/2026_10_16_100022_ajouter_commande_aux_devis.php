<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commande issue de la conversion d'un devis. Un devis accepté pouvait être converti plusieurs fois, en
 * autant de commandes identiques ; il garde désormais la sienne, qu'il affiche et qui bloque une seconde
 * conversion. Colonne facultative : les devis existants n'en ont pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devis', function (Blueprint $table) {
            $table->foreignId('commande_id')->nullable()->after('statut')->constrained('com_client_entete')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devis', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commande_id');
        });
    }
};
