<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Révisions de prix planifiées (plan de parité fripv2, phase 1.2d) : de nouveaux prix préparés en lot (ajustement en
 * pourcentage ou en montant, retouches à la main), appliqués tout de suite ou à leur date d'entrée en vigueur par une
 * tâche quotidienne, une seule fois.
 *
 * Tables neuves seulement : revisions_prix, revision_prix_lignes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('revisions_prix')) {
            Schema::create('revisions_prix', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('nom', 150);
                $table->text('notes')->nullable();
                // Date d'entrée en vigueur : requise pour planifier.
                $table->date('date_effet')->nullable();
                // brouillon, planifiee, appliquee ou annulee. Une révision appliquée ne l'est qu'une fois.
                $table->string('statut', 20)->default('brouillon');
                // Dernier ajustement fait sur la grille, pour mémoire : catégorie, prix visés, type et valeur.
                $table->string('categorie', 100)->nullable();
                $table->string('cible', 20)->nullable();
                $table->unsignedBigInteger('liste_prix_id')->nullable();
                $table->string('type_ajustement', 20)->nullable();
                $table->decimal('valeur_ajustement', 15, 4)->nullable();
                $table->unsignedBigInteger('cree_par')->nullable();
                $table->unsignedBigInteger('modifie_par')->nullable();
                $table->unsignedBigInteger('planifiee_par')->nullable();
                $table->timestamp('planifiee_le')->nullable();
                // Appliquée sans compte : par la tâche planifiée.
                $table->unsignedBigInteger('appliquee_par')->nullable();
                $table->timestamp('appliquee_le')->nullable();
                $table->unsignedBigInteger('annulee_par')->nullable();
                $table->timestamp('annulee_le')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'statut', 'date_effet']);
            });
        }

        if (! Schema::hasTable('revision_prix_lignes')) {
            Schema::create('revision_prix_lignes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('revision_prix_id')->constrained('revisions_prix')->cascadeOnDelete();
                $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
                // Vide : le prix de base du produit ; sinon son prix saisi dans cette liste.
                $table->foreignId('liste_prix_id')->nullable()->constrained('listes_prix')->cascadeOnDelete();
                $table->decimal('nouveau_prix', 15, 4);
                // Prix en place au moment de l'application (vide : il n'y en avait pas).
                $table->decimal('prix_remplace', 15, 4)->nullable();
                $table->timestamps();

                // Un même prix une seule fois par révision (contrôlé aussi par l'API : MySQL admet plusieurs NULL).
                $table->unique(['revision_prix_id', 'produit_id', 'liste_prix_id'], 'revision_prix_lignes_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('revision_prix_lignes');
        Schema::dropIfExists('revisions_prix');
    }
};
