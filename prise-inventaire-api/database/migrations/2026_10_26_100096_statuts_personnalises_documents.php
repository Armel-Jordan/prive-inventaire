<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statuts personnalisés sur les documents (plan de parité fripv2, phase 1.11c) : une étiquette libre de repérage, en
 * couleur (fond et texte), sans effet sur le cycle du document.
 *
 * Ajouts seulement : colonnes facultatives, sans clé étrangère (les étiquettes se retirent en douceur).
 */
return new class extends Migration
{
    private const TABLES = ['devis', 'com_client_entete', 'factures', 'bons_livraison', 'com_four_entete', 'receptions'];

    public function up(): void
    {
        if (! Schema::hasColumn('statuts_personnalises', 'couleur_texte')) {
            Schema::table('statuts_personnalises', fn (Blueprint $t) => $t->string('couleur_texte', 7)->nullable());
        }
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'statut_personnalise_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('statut_personnalise_id')->nullable()->index());
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'statut_personnalise_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropIndex("{$table}_statut_personnalise_id_index");
                    $t->dropColumn('statut_personnalise_id');
                });
            }
        }
        if (Schema::hasColumn('statuts_personnalises', 'couleur_texte')) {
            Schema::table('statuts_personnalises', fn (Blueprint $t) => $t->dropColumn('couleur_texte'));
        }
    }
};
