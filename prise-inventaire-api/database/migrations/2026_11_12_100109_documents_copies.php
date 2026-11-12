<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liens « Copié de » et « Copies » entre documents de vente (plan de parité fripv2, socle S4.3) : un document créé par
 * « Copier vers » (ou par la copie d'un devis) garde le document d'origine.
 *
 * Ajout seulement : table documents_copies.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('documents_copies')) {
            return;
        }
        Schema::create('documents_copies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            // Types : devis, commande_client, facture.
            $t->string('source_type', 30);
            $t->unsignedBigInteger('source_id');
            $t->string('cible_type', 30);
            $t->unsignedBigInteger('cible_id');
            $t->unsignedBigInteger('cree_par')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'source_type', 'source_id']);
            $t->index(['tenant_id', 'cible_type', 'cible_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents_copies');
    }
};
