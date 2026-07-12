<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute la colonne tenant_id (isolation multi-tenant) aux tables métier qui ne
 * l'avaient pas encore. Colonne nullable + index, sans FK — cohérent avec les
 * migrations d'ajout existantes et compatible sqlite (tests).
 *
 * Le backfill des lignes existantes est réalisé par une migration dédiée.
 */
return new class extends Migration
{
    private array $tables = [
        'produits',
        'employes',
        'secteurs',
        'notifications',
        'mouvements_inventaire', // modèle MouvementVente (à distinguer de mouvement_inventaire)
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
                });
            }
        }

        // Index composite produits (tenant_id, deleted_at) — sauté par add_dashboard_indexes
        // faute de colonne à l'époque.
        if (Schema::hasTable('produits')
            && Schema::hasColumn('produits', 'tenant_id')
            && Schema::hasColumn('produits', 'deleted_at')
            && $this->indexMissing('produits', 'produits_tenant_deleted_at_index')) {
            Schema::table('produits', function (Blueprint $t) {
                $t->index(['tenant_id', 'deleted_at'], 'produits_tenant_deleted_at_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('produits') && ! $this->indexMissing('produits', 'produits_tenant_deleted_at_index')) {
            Schema::table('produits', function (Blueprint $t) {
                $t->dropIndex('produits_tenant_deleted_at_index');
            });
        }

        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('tenant_id');
                });
            }
        }
    }

    private function indexMissing(string $table, string $name): bool
    {
        return ! collect(Schema::getIndexes($table))
            ->contains(fn ($index) => $index['name'] === $name);
    }
};
