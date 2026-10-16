<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conditions de paiement sur les clients et les factures, escompte pour paiement rapide (plan de parité fripv2,
 * phase 1.5) : la condition du référentiel (« 2/10 Net 30 ») donne l'échéance d'une facture et l'escompte accordé à un
 * paiement fait dans les jours d'escompte, quand il règle tout le reste de la facture.
 *
 * Ajouts seulement : clients.condition_paiement_id, factures.condition_paiement_id et montant_escompte,
 * facture_paiements.escompte. Sans condition, rien ne change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('condition_paiement_id')->nullable()->index();
        });
        Schema::table('factures', function (Blueprint $table) {
            $table->unsignedBigInteger('condition_paiement_id')->nullable()->index()->after('date_echeance');
            // Total des escomptes accordés aux paiements de la facture.
            $table->decimal('montant_escompte', 12, 2)->default(0)->after('montant_credite');
        });
        Schema::table('facture_paiements', function (Blueprint $table) {
            // Escompte accordé avec ce paiement (en plus du montant reçu).
            $table->decimal('escompte', 12, 2)->default(0)->after('montant');
        });
    }

    public function down(): void
    {
        Schema::table('facture_paiements', function (Blueprint $table) {
            $table->dropColumn('escompte');
        });
        Schema::table('factures', function (Blueprint $table) {
            $table->dropIndex(['condition_paiement_id']);
            $table->dropColumn(['condition_paiement_id', 'montant_escompte']);
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['condition_paiement_id']);
            $table->dropColumn('condition_paiement_id');
        });
    }
};
