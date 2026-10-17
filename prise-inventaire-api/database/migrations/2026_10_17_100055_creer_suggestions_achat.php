<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suggestions d'achat (plan de parité fripv2, phase 3.2 : « Suggestion de commandes aux fournisseurs ») : PI analyse
 * le stock (sous son minimum) et les reliquats clients pas encore commandés, propose les quantités à commander par
 * fournisseur, et les suggestions acceptées deviennent des commandes fournisseur, une par fournisseur.
 *
 * Ajouts seulement : produits.stock_maximum (la quantité visée par le réassort, au-dessus du seuil d'alerte qui sert de
 * minimum) ; table suggestions_achat. Droits du nouveau module « suggestions_achat » : ceux des commandes fournisseur ;
 * gérer les suggestions comme créer une commande, en suggérer une (depuis un produit) pour qui voit les produits.
 */
return new class extends Migration
{
    /** Action nommée => [module, colonne] dont elle reprend le réglage, pour chaque groupe. */
    private const ACTIONS = [
        'suggestions_achat.gerer' => ['commandes_fournisseur', 'can_create'],
        'suggestions_achat.suggerer' => ['produits', 'can_view'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('produits') && ! Schema::hasColumn('produits', 'stock_maximum')) {
            Schema::table('produits', function (Blueprint $table) {
                $table->decimal('stock_maximum', 12, 2)->nullable();
            });
        }
        if (! Schema::hasTable('suggestions_achat')) {
            Schema::create('suggestions_achat', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('produit_id')->index();
                $table->unsignedBigInteger('fournisseur_id')->nullable()->index();
                // inventaire (stock sous le minimum), reliquat (client en attente), manuelle (signalée depuis un produit).
                $table->string('source', 15);
                $table->unsignedBigInteger('reliquat_id')->nullable()->index();
                $table->unsignedInteger('quantite_suggeree')->default(0);
                // Quantité à commander, fixée à l'acceptation (la suggérée par défaut).
                $table->unsignedInteger('quantite')->nullable();
                // en_attente, acceptee, commandee.
                $table->string('statut', 15)->default('en_attente');
                $table->string('remarque', 255)->nullable();
                // Qui a suggéré (suggestion manuelle) ou accepté : le « commis » de fripv2.
                $table->unsignedBigInteger('commis_id')->nullable();
                $table->string('commis_nom', 150)->nullable();
                $table->unsignedBigInteger('com_four_ligne_id')->nullable()->index();
                $table->timestamps();

                $table->index(['tenant_id', 'statut']);
            });
        }
        $this->reglerDroits();
    }

    private function reglerDroits(): void
    {
        // Rejouable : droits du module pour chaque groupe qui n'en a pas encore (rôles système et groupes des
        // entreprises ; les anciens rôles personnalisés sans entreprise restent tels quels).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        foreach (DB::table('role_permissions')->where('module', 'commandes_fournisseur')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'suggestions_achat',
                'can_view' => $ligne->can_view, 'can_create' => $ligne->can_create,
                'can_edit' => $ligne->can_edit, 'can_delete' => $ligne->can_delete,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (self::ACTIONS as $action => [$module, $colonne]) {
            foreach (DB::table('role_permissions')->where('module', $module)->whereIn('role_id', $groupes)->get() as $ligne) {
                DB::table('role_actions')->insertOrIgnore([
                    'role_id' => $ligne->role_id, 'permission' => $action, 'accorde' => (bool) $ligne->{$colonne},
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('module', 'suggestions_achat')->delete();
        DB::table('role_actions')->whereIn('permission', array_keys(self::ACTIONS))->delete();
        Schema::dropIfExists('suggestions_achat');
        if (Schema::hasColumn('produits', 'stock_maximum')) {
            Schema::table('produits', function (Blueprint $table) {
                $table->dropColumn('stock_maximum');
            });
        }
    }
};
