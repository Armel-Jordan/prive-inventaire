<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fichiers de virement aux fournisseurs (plan de parité fripv2, phase 4.5, « Gestion des TFE ») : les déboursés payés
 * par virement partent en lots vers la banque, dans un fichier SEPA (pain.001) ou CSV, numéroté par compte bancaire.
 *
 * Ajouts seulement : comptes_bancaires.virement_format, virement_emetteur et prochain_lot ; table virements_lots (le
 * fichier transmis, gardé tel quel) ; debourses.virement_lot_id, virement_statut et virement_compte_id (le compte du
 * fournisseur crédité) ; action « Générer les fichiers de virement » (debourses.virements), réglée comme la saisie d'un
 * déboursé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comptes_bancaires', function (Blueprint $table) {
            if (! Schema::hasColumn('comptes_bancaires', 'prochain_lot')) {
                // sepa, csv : le dernier format employé pour ce compte.
                $table->string('virement_format', 10)->nullable();
                // Identifiant d'émetteur donné par la banque (facultatif).
                $table->string('virement_emetteur', 35)->nullable();
                $table->unsignedInteger('prochain_lot')->default(1);
            }
        });

        if (! Schema::hasTable('virements_lots')) {
            Schema::create('virements_lots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('compte_bancaire_id')->constrained('comptes_bancaires')->restrictOnDelete();
                $table->unsignedInteger('numero');
                $table->string('format', 10);
                $table->date('date_execution');
                // Vide : devise de l'entreprise.
                $table->string('devise', 10)->nullable();
                $table->unsignedInteger('nombre');
                $table->decimal('total', 15, 2);
                $table->string('fichier', 150);
                // Le fichier transmis, gardé tel quel pour le télécharger de nouveau.
                $table->longText('contenu');
                // emis, refuse
                $table->string('statut', 10)->default('emis');
                $table->string('motif_refus', 255)->nullable();
                $table->timestamp('refuse_le')->nullable();
                $table->foreignId('refuse_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->foreignId('cree_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['tenant_id', 'compte_bancaire_id', 'numero'], 'virements_lots_numero_unique');
            });
        }

        Schema::table('debourses', function (Blueprint $table) {
            if (! Schema::hasColumn('debourses', 'virement_lot_id')) {
                $table->foreignId('virement_lot_id')->nullable()->constrained('virements_lots')->nullOnDelete();
                // emis, refuse ; vide : pas encore transmis.
                $table->string('virement_statut', 10)->nullable();
                $table->foreignId('virement_compte_id')->nullable()->constrained('fournisseur_comptes_bancaires')->nullOnDelete();
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
                'role_id' => $ligne->role_id, 'permission' => 'debourses.virements', 'accorde' => (bool) $ligne->can_create,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_actions')->where('permission', 'debourses.virements')->delete();
        Schema::table('debourses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('virement_compte_id');
            $table->dropConstrainedForeignId('virement_lot_id');
            $table->dropColumn('virement_statut');
        });
        Schema::dropIfExists('virements_lots');
        Schema::table('comptes_bancaires', fn (Blueprint $table) => $table->dropColumn(['virement_format', 'virement_emetteur', 'prochain_lot']));
    }
};
