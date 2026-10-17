<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lots et numéros de série à la livraison (plan de parité fripv2, phase 2.8b) : une ligne de bon de livraison (ou de
 * vente au comptoir) qui sort un lot ou un numéro de série le note, avec le client, pour retrouver qui a reçu un lot
 * (rappel) et l'histoire d'un numéro de série.
 *
 * Ajouts seulement : colonnes facultatives de lot_mouvements et numeros_serie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_mouvements', function (Blueprint $table) {
            if (! Schema::hasColumn('lot_mouvements', 'bon_livraison_ligne_id')) {
                $table->foreignId('bon_livraison_ligne_id')->nullable()->constrained('bon_livraison_lignes')->nullOnDelete();
            }
            if (! Schema::hasColumn('lot_mouvements', 'facture_ligne_id')) {
                $table->foreignId('facture_ligne_id')->nullable()->constrained('facture_lignes')->nullOnDelete();
            }
            if (! Schema::hasColumn('lot_mouvements', 'client_id')) {
                $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            }
        });

        Schema::table('numeros_serie', function (Blueprint $table) {
            if (! Schema::hasColumn('numeros_serie', 'bon_livraison_ligne_id')) {
                $table->foreignId('bon_livraison_ligne_id')->nullable()->constrained('bon_livraison_lignes')->nullOnDelete();
            }
            if (! Schema::hasColumn('numeros_serie', 'facture_ligne_id')) {
                $table->foreignId('facture_ligne_id')->nullable()->constrained('facture_lignes')->nullOnDelete();
            }
            if (! Schema::hasColumn('numeros_serie', 'client_id')) {
                $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            }
            if (! Schema::hasColumn('numeros_serie', 'livre_le')) {
                $table->date('livre_le')->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['numeros_serie' => ['bon_livraison_ligne_id', 'facture_ligne_id', 'client_id'], 'lot_mouvements' => ['bon_livraison_ligne_id', 'facture_ligne_id', 'client_id']] as $table => $cles) {
            foreach ($cles as $cle) {
                if (Schema::hasColumn($table, $cle)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId($cle));
                }
            }
        }
        if (Schema::hasColumn('numeros_serie', 'livre_le')) {
            Schema::table('numeros_serie', fn (Blueprint $t) => $t->dropColumn('livre_le'));
        }
    }
};
