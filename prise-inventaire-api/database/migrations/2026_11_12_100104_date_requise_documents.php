<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date requise des documents de vente (plan de parité fripv2, socle S4.1) : la date à laquelle le client veut être livré,
 * sur le devis, la facture et le bon de livraison (la commande a déjà sa date de livraison souhaitée, qui la reprend).
 *
 * Ajouts seulement : colonne facultative.
 */
return new class extends Migration
{
    private const TABLES = ['devis', 'factures', 'bons_livraison'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'date_requise')) {
                    $t->date('date_requise')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'date_requise')) {
                    $t->dropColumn('date_requise');
                }
            });
        }
    }
};
