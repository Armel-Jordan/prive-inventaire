<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signature électronique à la livraison (plan de parité fripv2, phase 1.6) : image de la signature du client (PNG
 * encodé), nom du signataire et moment de la signature, imprimés sur le bon de livraison.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bons_livraison', function (Blueprint $table) {
            // Une image encodée dépasse vite les 64 Ko d'un TEXT.
            $table->longText('signature_client')->nullable()->change();
        });
        if (! Schema::hasColumn('bons_livraison', 'signataire_nom')) {
            Schema::table('bons_livraison', function (Blueprint $table) {
                $table->string('signataire_nom', 150)->nullable()->after('signature_client');
                $table->timestamp('signe_le')->nullable()->after('signataire_nom');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bons_livraison', 'signataire_nom')) {
            Schema::table('bons_livraison', function (Blueprint $table) {
                $table->dropColumn(['signataire_nom', 'signe_le']);
            });
        }
    }
};
