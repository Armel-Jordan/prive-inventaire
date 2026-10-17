<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unités de vente des produits (plan de parité fripv2, phase 2.2) : un produit compté dans son unité de stock (sa
 * mesure) se vend aussi dans d'autres unités du référentiel (la caisse de 12, le carton…), chacune avec son facteur
 * (unités de stock dans une unité) et, au besoin, son propre prix.
 *
 * Ajouts seulement :
 * - table produit_unites ;
 * - unite et facteur sur les lignes des devis, commandes client, factures, bons de livraison, notes de crédit, retours
 *   client et sur les reliquats clients : vides, la ligne est dans l'unité de stock du produit (facteur 1), comme avant.
 */
return new class extends Migration
{
    /** Tables des lignes qui portent une unité. */
    private const LIGNES = [
        'devis_lignes', 'com_client_ligne', 'facture_lignes', 'bon_livraison_lignes', 'avoir_lignes', 'retour_client_lignes',
        'reliquats_clients',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('produit_unites')) {
            Schema::create('produit_unites', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
                $table->foreignId('unite_id')->constrained('unites');
                // Unités de stock (la mesure du produit) dans une unité : 12 pour la caisse de 12.
                $table->decimal('facteur', 18, 6);
                // Prix de vente de cette unité ; vide : le prix du produit multiplié par le facteur.
                $table->decimal('prix', 15, 4)->nullable();
                // Unité proposée sur les lignes de vente (sinon l'unité de stock).
                $table->boolean('par_defaut')->default(false);
                $table->timestamps();

                $table->unique(['tenant_id', 'produit_id', 'unite_id'], 'produit_unites_unique');
            });
        }

        foreach (self::LIGNES as $nom) {
            Schema::table($nom, function (Blueprint $table) use ($nom) {
                if (! Schema::hasColumn($nom, 'unite')) {
                    // Code de l'unité de la ligne ; vide : l'unité de stock du produit.
                    $table->string('unite', 20)->nullable();
                }
                if (! Schema::hasColumn($nom, 'facteur')) {
                    // Unités de stock dans une unité de la ligne, figé à la saisie ; vide : 1.
                    $table->decimal('facteur', 18, 6)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::LIGNES as $nom) {
            Schema::table($nom, function (Blueprint $table) use ($nom) {
                foreach (['unite', 'facteur'] as $colonne) {
                    if (Schema::hasColumn($nom, $colonne)) {
                        $table->dropColumn($colonne);
                    }
                }
            });
        }
        Schema::dropIfExists('produit_unites');
    }
};
