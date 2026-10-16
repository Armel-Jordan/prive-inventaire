<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taxes détaillées des documents (plan de parité fripv2, phase 0.4b). Ajouts seulement :
 *  - groupe_taxe_id facultatif sur les lignes de commande client et de facture ;
 *  - document_taxes : détail de chaque taxe appliquée à une ligne, en instantané (nom, taux),
 *    pour que le document ne change pas si la taxe est modifiée ensuite.
 * Une ligne sans groupe garde le calcul au taux unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['com_client_ligne', 'facture_lignes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('groupe_taxe_id')->nullable()->after('taux_tva')->constrained('groupes_taxes')->nullOnDelete();
            });
        }

        Schema::create('document_taxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // commande_client, facture
            $table->string('document', 30);
            $table->unsignedBigInteger('ligne_id');
            $table->unsignedBigInteger('tenant_taxe_id')->nullable();
            $table->string('nom', 100);
            $table->decimal('taux', 8, 4);
            $table->string('applique_sur', 30);
            $table->decimal('base', 15, 2);
            $table->decimal('montant', 15, 2);
            $table->timestamps();

            $table->index(['document', 'ligne_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_taxes');

        foreach (['com_client_ligne', 'facture_lignes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('groupe_taxe_id');
            });
        }
    }
};
