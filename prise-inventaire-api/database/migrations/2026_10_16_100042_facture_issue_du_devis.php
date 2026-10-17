<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devis facturé directement (plan de parité fripv2, phase 1.3) : comme dans fripv2, un devis accepté se convertit en
 * commande ou en facture, une seule fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('devis', 'facture_id')) {
            Schema::table('devis', function (Blueprint $table) {
                $table->foreignId('facture_id')->nullable()->after('commande_id')->constrained('factures')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('devis', 'facture_id')) {
            Schema::table('devis', function (Blueprint $table) {
                $table->dropConstrainedForeignId('facture_id');
            });
        }
    }
};
