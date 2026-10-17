<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client de passage (plan de parité fripv2, phase 1.1) : un client de l'entreprise, créé à la première vente au
 * comptoir, sert aux clients qui n'ont pas de fiche ; leurs coordonnées (nom, téléphone, courriel, adresse) se notent
 * sur le document lui-même et figurent sur son PDF et ses courriels.
 *
 * Ajouts seulement : clients.de_passage ; coordonnées du client de passage sur les devis, commandes et factures.
 */
return new class extends Migration
{
    private const DOCUMENTS = ['devis', 'com_client_entete', 'factures'];

    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'de_passage')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->boolean('de_passage')->default(false);
            });
        }
        foreach (self::DOCUMENTS as $document) {
            if (Schema::hasColumn($document, 'passage_nom')) {
                continue;
            }
            Schema::table($document, function (Blueprint $table) {
                $table->string('passage_nom', 150)->nullable();
                $table->string('passage_telephone', 30)->nullable();
                $table->string('passage_courriel', 150)->nullable();
                $table->string('passage_adresse', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::DOCUMENTS as $document) {
            if (Schema::hasColumn($document, 'passage_nom')) {
                Schema::table($document, function (Blueprint $table) {
                    $table->dropColumn(['passage_nom', 'passage_telephone', 'passage_courriel', 'passage_adresse']);
                });
            }
        }
        if (Schema::hasColumn('clients', 'de_passage')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropColumn('de_passage');
            });
        }
    }
};
