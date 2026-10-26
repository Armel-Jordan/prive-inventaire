<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Option « transporteur obligatoire » (plan de parité fripv2, phase 1.11b ; inactive par défaut) : un devis, une commande,
 * une facture ou une commande fournisseur saisis doivent avoir un transporteur, dès que l'entreprise en a un d'actif.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_parametres', 'transporteur_obligatoire')) {
            Schema::table('tenant_parametres', function (Blueprint $table) {
                $table->boolean('transporteur_obligatoire')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_parametres', 'transporteur_obligatoire')) {
            Schema::table('tenant_parametres', fn (Blueprint $t) => $t->dropColumn('transporteur_obligatoire'));
        }
    }
};
