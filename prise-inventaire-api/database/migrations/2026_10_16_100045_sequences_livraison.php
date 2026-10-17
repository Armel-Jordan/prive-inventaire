<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Séquences de livraison (plan de parité fripv2, phase 1.6) : comme dans fripv2, le lieu de livraison d'un client
 * appartient à une route (itinéraire du référentiel logistique) et y a son rang de passage ; une tournée peut suivre
 * une route.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'itineraire_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->foreignId('itineraire_id')->nullable()->constrained('itineraires')->nullOnDelete();
                $table->unsignedInteger('sequence_livraison')->nullable();
            });
        }
        if (! Schema::hasColumn('tournees', 'itineraire_id')) {
            Schema::table('tournees', function (Blueprint $table) {
                $table->foreignId('itineraire_id')->nullable()->constrained('itineraires')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tournees', 'itineraire_id')) {
            Schema::table('tournees', function (Blueprint $table) {
                $table->dropConstrainedForeignId('itineraire_id');
            });
        }
        if (Schema::hasColumn('clients', 'itineraire_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->dropConstrainedForeignId('itineraire_id');
                $table->dropColumn('sequence_livraison');
            });
        }
    }
};
