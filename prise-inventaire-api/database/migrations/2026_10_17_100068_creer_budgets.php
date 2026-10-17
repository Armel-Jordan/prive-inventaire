<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budgets (plan de parité fripv2, phase 4.7) : le budget de chaque compte budgétable, période par période d'un exercice,
 * comparé ensuite au réel du journal.
 *
 * Ajouts seulement : comptes_comptables.budgetable (le compte paraît dans la feuille du budget) ; table
 * budgets_comptables (un montant par compte et par période) ; module de droits « budgets » (consulter, modifier les
 * montants), réglé pour chaque groupe d'après ses droits de comptabilité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comptes_comptables', function (Blueprint $table) {
            if (! Schema::hasColumn('comptes_comptables', 'budgetable')) {
                $table->boolean('budgetable')->default(false);
            }
        });

        if (! Schema::hasTable('budgets_comptables')) {
            Schema::create('budgets_comptables', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('exercice_id')->constrained('exercices_comptables')->restrictOnDelete();
                $table->foreignId('compte_id')->constrained('comptes_comptables')->restrictOnDelete();
                $table->foreignId('periode_id')->constrained('periodes_comptables')->restrictOnDelete();
                // Dans le sens naturel du compte : un produit ou une charge prévus s'écrivent en positif.
                $table->decimal('montant', 15, 2);
                $table->foreignId('modifie_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();

                // Noms courts : MySQL limite les identifiants à 64 caractères.
                $table->unique(['tenant_id', 'compte_id', 'periode_id'], 'budgets_compte_periode_unique');
                $table->index(['tenant_id', 'exercice_id'], 'budgets_exercice_index');
            });
        }

        $this->reglerDroits();
    }

    private function reglerDroits(): void
    {
        // Rejouable : droits du module pour chaque groupe qui n'en a pas encore (rôles système et groupes des
        // entreprises ; les anciens rôles personnalisés sans entreprise restent tels quels).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        if (! Schema::hasColumns('role_permissions', ['role_id', 'module']) || ! Schema::hasTable('role_actions')) {
            return;
        }
        // Consulter comme la comptabilité ; modifier les montants comme la saisie d'écritures (l'action réglée pour le
        // groupe, sinon sa base).
        $ecrire = DB::table('role_actions')->where('permission', 'comptabilite.ecrire')->whereIn('role_id', $groupes)->pluck('accorde', 'role_id');
        foreach (DB::table('role_permissions')->where('module', 'comptabilite')->whereIn('role_id', $groupes)->get() as $ligne) {
            $saisir = (bool) ($ecrire[$ligne->role_id] ?? $ligne->can_create);
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'budgets',
                'can_view' => $ligne->can_view, 'can_create' => $saisir, 'can_edit' => $saisir, 'can_delete' => $saisir,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('role_actions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'permission' => 'budgets.saisir', 'accorde' => $saisir,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_actions')->where('permission', 'budgets.saisir')->delete();
        DB::table('role_permissions')->where('module', 'budgets')->delete();
        Schema::dropIfExists('budgets_comptables');
        if (Schema::hasColumn('comptes_comptables', 'budgetable')) {
            Schema::table('comptes_comptables', fn (Blueprint $table) => $table->dropColumn('budgetable'));
        }
    }
};
