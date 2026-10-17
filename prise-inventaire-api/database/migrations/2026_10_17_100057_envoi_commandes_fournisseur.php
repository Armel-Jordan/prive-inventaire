<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bon de commande passé au fournisseur (plan de parité fripv2, phase 3.3) : le jour et l'auteur de l'envoi (« Commandée
 * le » de fripv2), notés quand la commande quitte le brouillon, par le bouton « Valider et envoyer » ou par l'envoi du
 * bon par courriel. Les commandes envoyées avant cette version n'ont pas de date : elle n'était pas notée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('com_four_entete', function (Blueprint $table) {
            $table->timestamp('envoyee_le')->nullable()->after('statut');
            $table->foreignId('envoyee_par')->nullable()->after('envoyee_le')->constrained('admin_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('com_four_entete', function (Blueprint $table) {
            $table->dropConstrainedForeignId('envoyee_par');
            $table->dropColumn('envoyee_le');
        });
    }
};
