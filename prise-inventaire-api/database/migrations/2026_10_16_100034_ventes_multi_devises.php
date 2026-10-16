<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ventes en plusieurs devises (plan de parité fripv2, phase 1.5) : un client peut avoir sa devise (USD, EUR…), que
 * reprennent ses documents de vente ; chaque document garde son taux de change, valeur d'une unité de sa devise dans
 * la devise de l'entreprise. Les cumuls (encours, comptes clients, tableaux de bord) sont convertis avec ce taux.
 *
 * Ajouts seulement : clients.devise ; devise et taux_change sur les devis, commandes, factures, notes de crédit,
 * acomptes et demandes d'acompte. Sans devise (null), un document est dans la devise de l'entreprise, au taux 1 :
 * rien ne change pour les documents existants.
 */
return new class extends Migration
{
    private const DOCUMENTS = ['devis', 'com_client_entete', 'factures', 'avoirs', 'acomptes', 'demandes_acompte'];

    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'devise')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('devise', 3)->nullable();
            });
        }
        foreach (self::DOCUMENTS as $document) {
            if (Schema::hasColumn($document, 'devise')) {
                continue;
            }
            Schema::table($document, function (Blueprint $table) {
                $table->string('devise', 3)->nullable();
                // Valeur d'une unité de la devise du document dans la devise de l'entreprise.
                $table->decimal('taux_change', 18, 8)->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach (self::DOCUMENTS as $document) {
            Schema::table($document, function (Blueprint $table) {
                $table->dropColumn(['devise', 'taux_change']);
            });
        }
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('devise');
        });
    }
};
