<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verrouillage d'une fiche pendant sa modification (plan de parité fripv2, phase 6.3) : le compte qui ouvre une fiche
 * en modification la verrouille quelques minutes (renouvelé tant que la fenêtre reste ouverte) ; un autre compte voit
 * qui la modifie, et l'API refuse son enregistrement.
 *
 * Ajouts seulement : table verrous (un verrou par fiche) ; module de droits « verrous » (voir les fiches en cours de
 * modification, prendre la main ou libérer une fiche : d'après la configuration).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('verrous')) {
            Schema::create('verrous', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                // client, fournisseur, produit, devis, commande_client, commande_fournisseur.
                $table->string('entite', 40);
                $table->unsignedBigInteger('entite_id');
                $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
                $table->timestamp('expire_le');
                // Fiche libérée par un responsable : le compte qui la modifiait l'apprend au renouvellement suivant.
                $table->foreignId('libere_par_id')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamp('libere_le')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'entite', 'entite_id']);
                // Fiches en cours de modification, effacement des verrous levés depuis plus d'un jour.
                $table->index(['tenant_id', 'expire_le']);
            });
        }

        $this->reglerDroits();
    }

    private function reglerDroits(): void
    {
        // Rejouable : le module pour chaque groupe qui ne l'a pas encore (rôles système et groupes des entreprises).
        if (! Schema::hasColumns('role_permissions', ['role_id', 'module'])) {
            return;
        }
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        foreach (DB::table('role_permissions')->where('module', 'configuration')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'verrous',
                // « Prendre la main » s'appuie sur le droit de modifier.
                'can_view' => (bool) $ligne->can_view, 'can_create' => false, 'can_edit' => (bool) $ligne->can_edit, 'can_delete' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('module', 'verrous')->delete();
        Schema::dropIfExists('verrous');
    }
};
