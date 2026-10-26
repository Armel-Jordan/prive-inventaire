<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numéro de commande du client (plan de parité fripv2, socle S4.1) : sa référence (30 caractères) sur la commande, la
 * facture et le bon de livraison ; la fiche client peut l'exiger.
 */
return new class extends Migration
{
    private const TABLES = ['com_client_entete', 'factures', 'bons_livraison'];

    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'commande_client_obligatoire')) {
            Schema::table('clients', fn (Blueprint $t) => $t->boolean('commande_client_obligatoire')->default(false));
        }
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'numero_commande_client')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('numero_commande_client', 30)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'numero_commande_client')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('numero_commande_client'));
            }
        }
        if (Schema::hasColumn('clients', 'commande_client_obligatoire')) {
            Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('commande_client_obligatoire'));
        }
    }
};
