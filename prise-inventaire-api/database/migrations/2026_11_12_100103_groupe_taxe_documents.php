<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groupe de taxes du client et des documents de vente (plan de parité fripv2, socle S4.1 ; groupes par défaut et
 * exemptions) : le groupe de la fiche client est proposé sur ses devis, commandes et factures ; le groupe du document
 * s'applique à ses lignes qui n'en ont pas (un groupe vide exonère).
 *
 * Ajouts seulement : colonnes facultatives, sans clé étrangère (comme groupe_taxe_id sur les lignes).
 */
return new class extends Migration
{
    private const TABLES = ['clients', 'devis', 'com_client_entete', 'factures'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'groupe_taxe_id')) {
                    $t->unsignedBigInteger('groupe_taxe_id')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'groupe_taxe_id')) {
                    $t->dropIndex("{$table}_groupe_taxe_id_index");
                    $t->dropColumn('groupe_taxe_id');
                }
            });
        }
    }
};
