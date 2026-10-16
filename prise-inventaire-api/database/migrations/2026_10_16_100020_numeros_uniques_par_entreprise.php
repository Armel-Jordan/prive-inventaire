<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numéros de documents, codes clients et alertes de stock uniques PAR ENTREPRISE, à la suite de
 * scope_unique_indexes_to_tenant (fournisseurs, employés, produits, secteurs, camions, zones).
 * Chaque entreprise numérote avec ses propres préfixes : avec un index unique global, la deuxième
 * entreprise qui émettait « FAC-0001 » (ou « BC-0001 », « DEV-0001 »…) échouait en erreur SQL.
 * Les index sont vérifiés avant d'y toucher : la migration se rejoue sans erreur.
 */
return new class extends Migration
{
    /** table => colonnes dont l'unicité devient (tenant_id, colonne). */
    private const COLONNES = [
        'clients' => ['code'],
        'devis' => ['numero'],
        'com_client_entete' => ['numero'],
        'com_four_entete' => ['numero'],
        'factures' => ['numero'],
        'bons_livraison' => ['numero'],
        'tournees' => ['numero'],
        'alertes_stock' => ['produit_numero'],
    ];

    public function up(): void
    {
        foreach (self::COLONNES as $table => $colonnes) {
            foreach ($colonnes as $colonne) {
                if (! Schema::hasTable($table) || ! Schema::hasColumns($table, ['tenant_id', $colonne])) {
                    continue;
                }

                $index = collect(Schema::getIndexes($table));
                $global = $index->first(fn ($i) => $i['unique'] && ! $i['primary'] && $i['columns'] === [$colonne]);
                $parEntreprise = $index->contains(fn ($i) => $i['unique'] && $i['columns'] === ['tenant_id', $colonne]);

                Schema::table($table, function (Blueprint $t) use ($table, $colonne, $global, $parEntreprise) {
                    if ($global) {
                        $t->dropUnique($global['name']);
                    }
                    if (! $parEntreprise) {
                        $t->unique(['tenant_id', $colonne], "{$table}_{$colonne}_tenant_unique");
                    }
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLONNES as $table => $colonnes) {
            foreach ($colonnes as $colonne) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $colonne)) {
                    continue;
                }

                $index = collect(Schema::getIndexes($table));
                Schema::table($table, function (Blueprint $t) use ($table, $colonne, $index) {
                    if ($index->contains(fn ($i) => $i['name'] === "{$table}_{$colonne}_tenant_unique")) {
                        $t->dropUnique("{$table}_{$colonne}_tenant_unique");
                    }
                });
            }
        }
    }
};
