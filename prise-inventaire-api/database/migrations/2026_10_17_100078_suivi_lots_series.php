<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lots, numéros de série et dates de péremption (plan de parité fripv2, phase 2.8a) :
 *  - produits.suivi : vide (aucun suivi, comme avant), « lot » ou « serie » ; produits.peremption : la date de
 *    péremption d'un lot est exigée à la réception ;
 *  - lots : un lot par produit et numéro, avec sa date de péremption et le fournisseur de sa première réception ;
 *  - lot_mouvements : le journal des quantités d'un lot (en unités de stock) ; son solde est ce qui en reste ;
 *  - numeros_serie : un exemplaire par numéro, avec son statut (en stock, livré, retourné…) ;
 *  - reception_arrivages_ligne.lot_id : le lot reçu par la ligne.
 * Module « lots » (registre et péremptions) : voir comme on voit les produits, modifier comme on les modifie.
 *
 * Ajouts seulement : un produit sans suivi se reçoit et se vend exactement comme avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            if (! Schema::hasColumn('produits', 'suivi')) {
                $table->string('suivi', 10)->nullable();
            }
            if (! Schema::hasColumn('produits', 'peremption')) {
                $table->boolean('peremption')->default(false);
            }
        });

        if (! Schema::hasTable('lots')) {
            Schema::create('lots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('produit_id')->constrained('produits');
                $table->string('numero', 50);
                $table->date('date_peremption')->nullable();
                $table->foreignId('fournisseur_id')->nullable()->constrained('fournisseurs')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'produit_id', 'numero'], 'lots_produit_numero_unique');
                $table->index(['tenant_id', 'date_peremption']);
            });
        }

        if (! Schema::hasTable('lot_mouvements')) {
            Schema::create('lot_mouvements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('lot_id')->constrained('lots')->cascadeOnDelete();
                // reception, annulation_reception (puis livraison, retours… avec la phase 2.8b)
                $table->string('type', 30);
                // En unités de stock ; négatif pour une sortie.
                $table->decimal('quantite', 14, 4);
                $table->foreignId('reception_ligne_id')->nullable()->constrained('reception_arrivages_ligne')->nullOnDelete();
                $table->date('date_mouvement');
                $table->unsignedBigInteger('cree_par')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('numeros_serie')) {
            Schema::create('numeros_serie', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('produit_id')->constrained('produits');
                $table->string('numero', 100);
                // en_stock, livre, retourne, rebut
                $table->string('statut', 15)->default('en_stock');
                $table->foreignId('reception_ligne_id')->nullable()->constrained('reception_arrivages_ligne')->nullOnDelete();
                $table->foreignId('fournisseur_id')->nullable()->constrained('fournisseurs')->nullOnDelete();
                $table->date('recu_le')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'produit_id', 'numero'], 'numeros_serie_produit_numero_unique');
            });
        }

        if (! Schema::hasColumn('reception_arrivages_ligne', 'lot_id')) {
            Schema::table('reception_arrivages_ligne', function (Blueprint $table) {
                $table->foreignId('lot_id')->nullable()->constrained('lots')->nullOnDelete();
            });
        }

        $this->reglerDroits();
    }

    private function reglerDroits(): void
    {
        // Rejouable : droits du module pour chaque groupe qui n'en a pas encore (rôles système et groupes des
        // entreprises ; les anciens rôles personnalisés sans entreprise restent tels quels).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        if (! Schema::hasColumns('role_permissions', ['role_id', 'module'])) {
            return;
        }
        // Consulter les lots comme on voit les produits ; les corriger comme on les modifie.
        foreach (DB::table('role_permissions')->where('module', 'produits')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'lots',
                'can_view' => $ligne->can_view, 'can_create' => false, 'can_edit' => $ligne->can_edit, 'can_delete' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('module', 'lots')->delete();
        if (Schema::hasColumn('reception_arrivages_ligne', 'lot_id')) {
            Schema::table('reception_arrivages_ligne', fn (Blueprint $t) => $t->dropConstrainedForeignId('lot_id'));
        }
        Schema::dropIfExists('numeros_serie');
        Schema::dropIfExists('lot_mouvements');
        Schema::dropIfExists('lots');
        Schema::table('produits', function (Blueprint $table) {
            foreach (['suivi', 'peremption'] as $colonne) {
                if (Schema::hasColumn('produits', $colonne)) {
                    $table->dropColumn($colonne);
                }
            }
        });
    }
};
