<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Point de vente (plan de parité fripv2, phase 1.10b) : vente au comptoir en un geste (facture créée, émise et
 * encaissée, paiement en plusieurs modes, monnaie rendue), reçu imprimé.
 *
 * Ajouts seulement : facture_paiements.montant_recu (l'argent réellement remis pour un paiement en espèces ; le
 * paiement, lui, ne compte que ce qui est dû : la différence est la monnaie rendue, réimprimée sur le reçu). Droits du
 * nouveau module « point_de_vente » : ceux des factures ; vendre au comptoir comme créer une facture.
 */
return new class extends Migration
{
    /** Action nommée => [module, colonne] dont elle reprend le réglage, pour chaque groupe. */
    private const ACTIONS = [
        'point_de_vente.vendre' => ['factures', 'can_create'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('facture_paiements') && ! Schema::hasColumn('facture_paiements', 'montant_recu')) {
            Schema::table('facture_paiements', function (Blueprint $table) {
                $table->decimal('montant_recu', 14, 2)->nullable();
            });
        }
        $this->reglerDroits();
    }

    private function reglerDroits(): void
    {
        // Rejouable : droits du module pour chaque groupe qui n'en a pas encore (rôles système et groupes des
        // entreprises ; les anciens rôles personnalisés sans entreprise restent tels quels).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        foreach (DB::table('role_permissions')->where('module', 'factures')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'point_de_vente',
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
        DB::table('role_permissions')->where('module', 'point_de_vente')->delete();
        DB::table('role_actions')->whereIn('permission', array_keys(self::ACTIONS))->delete();
        if (Schema::hasColumn('facture_paiements', 'montant_recu')) {
            Schema::table('facture_paiements', function (Blueprint $table) {
                $table->dropColumn('montant_recu');
            });
        }
    }
};
