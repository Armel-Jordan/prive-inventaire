<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chèques des déboursés (plan de parité fripv2, phase 4.5, « Rapport d'impression de chèque ») : le chèque d'un déboursé
 * payé par chèque prend, à sa première impression, le prochain numéro du chéquier de son compte bancaire (gardé dans sa
 * référence) ; il porte le montant en lettres et le talon des factures payées.
 *
 * Ajouts seulement : comptes_bancaires.prochain_cheque ; debourses.cheque_imprime_le et cheque_imprime_par ; action
 * « Imprimer les chèques » (debourses.cheques), réglée pour chaque groupe comme la saisie d'un déboursé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comptes_bancaires', function (Blueprint $table) {
            if (! Schema::hasColumn('comptes_bancaires', 'prochain_cheque')) {
                $table->unsignedBigInteger('prochain_cheque')->nullable();
            }
        });
        Schema::table('debourses', function (Blueprint $table) {
            if (! Schema::hasColumn('debourses', 'cheque_imprime_le')) {
                $table->timestamp('cheque_imprime_le')->nullable();
                $table->foreignId('cheque_imprime_par')->nullable()->constrained('admin_users')->nullOnDelete();
            }
        });

        $this->reglerDroits();
    }

    private function reglerDroits(): void
    {
        // Rejouable : pour chaque groupe qui n'a pas encore l'action (rôles système et groupes des entreprises).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        if (! Schema::hasColumns('role_permissions', ['role_id', 'module']) || ! Schema::hasTable('role_actions')) {
            return;
        }
        foreach (DB::table('role_permissions')->where('module', 'debourses')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_actions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'permission' => 'debourses.cheques', 'accorde' => (bool) $ligne->can_create,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_actions')->where('permission', 'debourses.cheques')->delete();
        Schema::table('debourses', fn (Blueprint $table) => $table->dropConstrainedForeignId('cheque_imprime_par'));
        Schema::table('debourses', fn (Blueprint $table) => $table->dropColumn('cheque_imprime_le'));
        Schema::table('comptes_bancaires', fn (Blueprint $table) => $table->dropColumn('prochain_cheque'));
    }
};
