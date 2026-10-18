<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Questions en langage naturel sur les rapports (plan de parité fripv2, phase 5.4) : l'IA (Claude, d'Anthropic)
 * répond en exécutant les rapports de la bibliothèque que le compte peut ouvrir ; chaque question est gardée avec sa
 * réponse, les rapports utilisés, le modèle et les jetons consommés.
 *
 * Ajouts seulement : table questions_ia ; action « questionner » du module des rapports, d'après le droit de les voir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('questions_ia')) {
            Schema::create('questions_ia', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('admin_user_id')->constrained('admin_users')->cascadeOnDelete();
                $table->text('question');
                $table->string('langue', 2)->default('fr');
                // en_cours, reussie ou echec.
                $table->string('statut', 20)->default('en_cours');
                $table->string('titre', 200)->nullable();
                $table->text('reponse')->nullable();
                // Rapports exécutés (code et valeurs des paramètres), et celui proposé pour le détail.
                $table->json('rapports')->nullable();
                $table->json('rapport')->nullable();
                $table->string('modele', 60)->nullable();
                $table->unsignedInteger('jetons_entree')->default(0);
                $table->unsignedInteger('jetons_sortie')->default(0);
                $table->unsignedInteger('duree_ms')->nullable();
                $table->string('erreur', 500)->nullable();
                $table->timestamps();

                $table->index(['admin_user_id', 'created_at']);
            });
        }

        // Rejouable : l'action pour chaque groupe qui ne l'a pas encore (rôles système et groupes des entreprises).
        if (! Schema::hasColumns('role_permissions', ['role_id', 'module']) || ! Schema::hasTable('role_actions')) {
            return;
        }
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        foreach (DB::table('role_permissions')->where('module', 'rapports')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_actions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'permission' => 'rapports.questionner', 'accorde' => (bool) $ligne->can_view,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_actions')->where('permission', 'rapports.questionner')->delete();
        Schema::dropIfExists('questions_ia');
    }
};
