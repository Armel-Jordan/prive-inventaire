<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents prioritaires (plan de parité fripv2, socle S4.1, l'étoile) : un devis, une commande, une facture ou un bon de
 * livraison marqué prioritaire, repris par le document qu'on en tire et filtrable dans les listes.
 *
 * Ajouts seulement : colonne faux par défaut.
 */
return new class extends Migration
{
    private const TABLES = ['devis', 'com_client_entete', 'factures', 'bons_livraison'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'prioritaire')) {
                    $t->boolean('prioritaire')->default(false)->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'prioritaire')) {
                    $t->dropIndex("{$table}_prioritaire_index");
                    $t->dropColumn('prioritaire');
                }
            });
        }
    }
};
