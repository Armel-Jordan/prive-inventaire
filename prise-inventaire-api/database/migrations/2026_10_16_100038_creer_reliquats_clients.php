<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reliquats clients (back orders, plan de parité fripv2, phase 1.4) : un produit qu'un client attend faute de stock,
 * commandé au fournisseur, suivi à la réception, puis facturé quand il est arrivé.
 *
 * Ajouts seulement : table reliquats_clients. Droits du nouveau module « reliquats_clients » : ceux des commandes
 * client pour la saisie ; commander au fournisseur comme créer une commande fournisseur, facturer comme créer une
 * facture.
 */
return new class extends Migration
{
    /** Action nommée => [module, colonne] dont elle reprend le réglage, pour chaque groupe. */
    private const ACTIONS = [
        'reliquats_clients.commander' => ['commandes_fournisseur', 'can_create'],
        'reliquats_clients.facturer' => ['factures', 'can_create'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('reliquats_clients')) {
            Schema::create('reliquats_clients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('client_id')->constrained('clients');
                $table->unsignedBigInteger('produit_id')->index();
                // Quantité attendue par le client, commandée au fournisseur, reçue.
                $table->unsignedInteger('quantite');
                $table->unsignedInteger('quantite_commandee')->default(0);
                $table->unsignedInteger('quantite_recue')->default(0);
                // Prix convenu avec le client, dans la devise du reliquat.
                $table->decimal('prix_unitaire_ht', 12, 2)->default(0);
                $table->decimal('remise_ligne', 5, 2)->default(0);
                $table->string('devise', 3)->nullable();
                $table->decimal('taux_change', 18, 8)->default(1);
                // Commande client d'origine (et sa ligne), ligne de la commande fournisseur, facture.
                $table->unsignedBigInteger('commande_client_id')->nullable()->index();
                $table->unsignedBigInteger('commande_client_ligne_id')->nullable();
                $table->unsignedBigInteger('com_four_ligne_id')->nullable()->index();
                $table->unsignedBigInteger('facture_id')->nullable()->index();
                $table->date('date_requise')->nullable();
                // cree, commande, recu_partiel, recu, facture, annule
                $table->string('statut', 20)->default('cree');
                $table->string('notes', 255)->nullable();
                $table->unsignedBigInteger('cree_par')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'statut']);
                $table->index(['tenant_id', 'client_id']);
            });
        }
        $this->reglerDroits();
    }

    private function reglerDroits(): void
    {
        // Rejouable : droits du module pour chaque groupe qui n'en a pas encore (rôles système et groupes des
        // entreprises ; les anciens rôles personnalisés sans entreprise restent tels quels).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        foreach (DB::table('role_permissions')->where('module', 'commandes_client')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'reliquats_clients',
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
        DB::table('role_permissions')->where('module', 'reliquats_clients')->delete();
        DB::table('role_actions')->whereIn('permission', array_keys(self::ACTIONS))->delete();
        Schema::dropIfExists('reliquats_clients');
    }
};
