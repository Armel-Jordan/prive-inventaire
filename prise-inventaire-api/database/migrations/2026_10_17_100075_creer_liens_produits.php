<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liens entre produits (plan de parité fripv2, phase 2.3) :
 * - composant : le produit est un ensemble (kit) qui se vend en un article et consomme ses composants ;
 * - annexe : produit ajouté d'office sur les lignes de vente du produit (la pile fournie avec l'appareil) ;
 * - equivalent : substitut possible, dans les deux sens, avec une remarque.
 *
 * Ajouts seulement : table produit_liens.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('produit_liens')) {
            Schema::create('produit_liens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('type', 12);
                $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
                $table->foreignId('lie_id')->constrained('produits')->cascadeOnDelete();
                // Composant ou annexe : quantité pour une unité du produit.
                $table->decimal('quantite', 12, 3)->default(1);
                $table->string('remarque', 255)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'type', 'produit_id', 'lie_id'], 'produit_liens_unique');
                $table->index(['tenant_id', 'type', 'lie_id'], 'produit_liens_lie_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_liens');
    }
};
