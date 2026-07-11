<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rend les contraintes d'unicité métier PAR TENANT : remplace les index uniques
 * globaux (ex. fournisseurs.code) par des index composites (tenant_id, colonne).
 * Sans ça, deux entreprises ne peuvent pas réutiliser le même code (et l'INSERT
 * échoue même quand la validation applicative l'autorise).
 */
return new class extends Migration
{
    /** table => colonnes dont l'unicité doit devenir (tenant_id, colonne). */
    private array $map = [
        'fournisseurs' => ['code'],
        'employes' => ['numero'],
        'produits' => ['numero'],
        'secteurs' => ['code', 'qr_code'],
        'camions' => ['immatriculation'],
        'zones_preparation' => ['code'],
    ];

    public function up(): void
    {
        foreach ($this->map as $table => $columns) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                // Retire l'index unique global existant (nommage conventionnel {table}_{col}_unique).
                try {
                    Schema::table($table, fn (Blueprint $t) => $t->dropUnique([$column]));
                } catch (\Throwable $e) {
                    // Index absent ou nommé autrement — on continue.
                }

                Schema::table($table, function (Blueprint $t) use ($table, $column) {
                    $t->unique(['tenant_id', $column], $table.'_'.$column.'_tenant_unique');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->map as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                try {
                    Schema::table($table, fn (Blueprint $t) => $t->dropUnique($table.'_'.$column.'_tenant_unique'));
                } catch (\Throwable $e) {
                    // ignore
                }

                try {
                    Schema::table($table, fn (Blueprint $t) => $t->unique([$column]));
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }
    }
};
