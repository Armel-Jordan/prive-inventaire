<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes de support et suggestions (plan de parité fripv2, phase 6.7) : un compte écrit à l'éditeur du logiciel (une
 * demande d'aide ou une suggestion), suit son statut et échange avec lui ; l'éditeur (super-administrateur) les voit
 * toutes, répond et fait avancer le statut.
 *
 * Ajouts seulement : tables demandes_support et demandes_support_messages ; module de droits « support » (écrire et
 * suivre ses demandes : tout compte qui voit le tableau de bord ; voir toutes celles de l'entreprise : d'après la
 * configuration).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('demandes_support')) {
            Schema::create('demandes_support', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
                // support (demande d'aide) ou suggestion.
                $table->string('type', 20);
                $table->string('sujet', 200);
                $table->text('description');
                // basse, normale, haute, urgente.
                $table->string('priorite', 20)->default('normale');
                // ouverte, en_cours, en_attente (réponse de l'usager attendue), resolue, fermee.
                $table->string('statut', 20)->default('ouverte');
                // Écran d'où la demande est partie.
                $table->string('ecran', 255)->nullable();
                $table->timestamp('dernier_message_le')->nullable();
                // usager ou editeur : qui a écrit en dernier.
                $table->string('dernier_auteur', 20)->nullable();
                $table->timestamp('resolue_le')->nullable();
                $table->timestamps();

                $table->index(['statut', 'updated_at']);
            });
        }
        if (! Schema::hasTable('demandes_support_messages')) {
            Schema::create('demandes_support_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('demande_support_id')->constrained('demandes_support')->cascadeOnDelete();
                // usager (compte de l'entreprise) ou editeur (super-administrateur).
                $table->string('auteur', 20);
                $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->unsignedBigInteger('super_admin_id')->nullable();
                $table->text('message');
                $table->timestamps();
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
        $configuration = DB::table('role_permissions')->where('module', 'configuration')->whereIn('role_id', $groupes)->pluck('can_view', 'role_id');
        foreach (DB::table('role_permissions')->where('module', 'dashboard')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'support',
                'can_view' => (bool) ($configuration[$ligne->role_id] ?? false), 'can_create' => (bool) $ligne->can_view,
                'can_edit' => false, 'can_delete' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('module', 'support')->delete();
        Schema::dropIfExists('demandes_support_messages');
        Schema::dropIfExists('demandes_support');
    }
};
