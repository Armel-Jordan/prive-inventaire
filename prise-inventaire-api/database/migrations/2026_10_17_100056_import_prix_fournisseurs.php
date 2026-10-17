<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Import des grilles de prix fournisseur (plan de parité fripv2, phase 3.6 : « Importer une grille de prix
 * fournisseur ») : le fichier de prix reçu d'un fournisseur met à jour le prix d'achat des produits de son catalogue.
 *
 * Ajouts seulement : table profils_import (réglages d'import mémorisés par nom : colonnes, ligne de début, fournisseur) ;
 * action « fournisseurs.importer_prix », réglée comme le droit de modifier les fournisseurs pour chaque groupe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profils_import')) {
            Schema::create('profils_import', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                // Type d'import (prix_fournisseur), pour d'autres imports à venir.
                $table->string('type', 40);
                $table->string('nom', 100);
                $table->json('reglages');
                $table->unsignedBigInteger('cree_par')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'type', 'nom']);
            });
        }
        // Rejouable : l'action pour chaque groupe qui ne l'a pas encore (rôles système et groupes des entreprises).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        foreach (DB::table('role_permissions')->where('module', 'fournisseurs')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_actions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'permission' => 'fournisseurs.importer_prix', 'accorde' => (bool) $ligne->can_edit,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_actions')->where('permission', 'fournisseurs.importer_prix')->delete();
        Schema::dropIfExists('profils_import');
    }
};
