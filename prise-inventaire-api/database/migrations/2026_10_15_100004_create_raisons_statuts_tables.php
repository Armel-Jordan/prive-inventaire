<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raisons et statuts personnalisés (plan de parité fripv2, phase 0.3) : tables neuves,
 * isolées par tenant. Les motifs saisis aujourd'hui en texte libre (mouvements, refus)
 * restent inchangés ; les écrans pourront proposer ces raisons au fil des fonctionnalités.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raisons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // mouvement, ajustement, retour_client, retour_fournisseur, annulation, refus, autre
            $table->string('type', 30);
            $table->string('code', 20);
            $table->string('libelle', 150);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'type', 'code']);
        });

        Schema::create('statuts_personnalises', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // devis, commande_client, bon_livraison, facture, commande_fournisseur, reception, autre
            $table->string('document', 30);
            $table->string('code', 20);
            $table->string('libelle', 100);
            $table->string('couleur', 7)->default('#6b7280');
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'document', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statuts_personnalises');
        Schema::dropIfExists('raisons');
    }
};
