<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `tenant_id` sur les tables filles qui ne l'avaient pas (anomalie A14) : leur isolation ne tenait que par la fiche
 * parente. La colonne est remplie depuis le parent ; ensuite, les modèles la posent d'eux-mêmes (BelongsToTenant).
 */
return new class extends Migration
{
    /** Table fille => [colonne vers le parent, table parente]. */
    private const TABLES = [
        'recurrence_echeances' => ['recurrence_id', 'recurrences_comptables'],
        'facture_fournisseur_lignes' => ['facture_fournisseur_id', 'factures_fournisseurs'],
        'transaction_bancaire_lignes' => ['transaction_bancaire_id', 'transactions_bancaires'],
        'encaissement_lignes' => ['encaissement_id', 'encaissements'],
        'debourse_lignes' => ['debourse_id', 'debourses'],
        'releve_bancaire_liens' => ['releve_ligne_id', 'releve_bancaire_lignes'],
        'frais_produits' => ['frais_id', 'frais'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => [$colonne, $parent]) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->unsignedBigInteger('tenant_id')->nullable()->after('id')->index($table.'_tenant_id_index');
            });
            DB::statement("UPDATE {$table} SET tenant_id = (SELECT {$parent}.tenant_id FROM {$parent} WHERE {$parent}.id = {$table}.{$colonne})");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropIndex($table.'_tenant_id_index');
                    $t->dropColumn('tenant_id');
                });
            }
        }
    }
};
