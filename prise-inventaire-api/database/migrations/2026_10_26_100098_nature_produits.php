<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nature et options du produit (plan de parité fripv2, socle S2 de docs/reste-a-faire/03) : des cases de la fiche
 * produit, avec les valeurs par défaut qui reproduisent l'existant (tout produit est stocké, acheté, vendu, valorisé,
 * commissionnable, visible dans l'espace client et garde ses reliquats).
 */
return new class extends Migration
{
    /** Colonne => valeur par défaut. */
    private const CASES = [
        'service' => false,
        'stocke' => true,
        'achete' => true,
        'vendu' => true,
        'valorise' => true,
        'commissionnable' => true,
        'visible_portail' => true,
        'conserve_reliquat' => true,
    ];

    public function up(): void
    {
        foreach (self::CASES as $colonne => $defaut) {
            if (! Schema::hasColumn('produits', $colonne)) {
                Schema::table('produits', fn (Blueprint $t) => $t->boolean($colonne)->default($defaut));
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::CASES) as $colonne) {
            if (Schema::hasColumn('produits', $colonne)) {
                Schema::table('produits', fn (Blueprint $t) => $t->dropColumn($colonne));
            }
        }
    }
};
