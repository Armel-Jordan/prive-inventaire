<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taxes sur le devis (plan de parité fripv2, DE1) : chaque ligne porte son groupe, son taux, ses montants HT, de taxes
 * et TTC ; le devis ses totaux. Le détail par taxe va dans document_taxes (document « devis »).
 *
 * Ajouts seulement : colonnes nullables (un devis enregistré avant reste hors taxes jusqu'à sa prochaine modification).
 * montant_total reste le total HT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devis_lignes', function (Blueprint $t) {
            if (! Schema::hasColumn('devis_lignes', 'groupe_taxe_id')) {
                $t->unsignedBigInteger('groupe_taxe_id')->nullable();
                $t->decimal('taux_tva', 6, 3)->nullable();
                $t->decimal('montant_ht', 15, 2)->nullable();
                $t->decimal('montant_tva', 15, 2)->nullable();
                $t->decimal('montant_ttc', 15, 2)->nullable();
            }
        });
        Schema::table('devis', function (Blueprint $t) {
            if (! Schema::hasColumn('devis', 'montant_ttc')) {
                $t->decimal('montant_ht', 15, 2)->nullable();
                $t->decimal('montant_tva', 15, 2)->nullable();
                $t->decimal('montant_ttc', 15, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('devis', fn (Blueprint $t) => $t->dropColumn(['montant_ht', 'montant_tva', 'montant_ttc']));
        Schema::table('devis_lignes', fn (Blueprint $t) => $t->dropColumn(['groupe_taxe_id', 'taux_tva', 'montant_ht', 'montant_tva', 'montant_ttc']));
    }
};
