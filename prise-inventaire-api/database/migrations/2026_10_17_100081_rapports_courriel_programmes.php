<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Envoi et programmation des rapports (plan de parité fripv2, phase 5.2) : un rapport de la bibliothèque part par
 * courriel, tout de suite ou à date fixe (chaque jour, chaque semaine, chaque mois), sur une période relative (veille,
 * mois précédent…), en PDF, Excel ou CSV.
 *
 * Ajouts seulement : colonne rapport de courriel_envois (le rapport à joindre, refait à chaque tentative) ; table
 * rapports_programmes ; actions « envoyer » (d'après le droit de voir les rapports) et « programmer » (d'après celui de
 * les modifier) du module des rapports, pour chaque groupe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('courriel_envois', 'rapport')) {
            Schema::table('courriel_envois', function (Blueprint $table) {
                // Rapport joint : code, valeurs des paramètres, format, langue et fuseau.
                $table->json('rapport')->nullable();
            });
        }

        if (! Schema::hasTable('rapports_programmes')) {
            Schema::create('rapports_programmes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                // Code du rapport de la bibliothèque.
                $table->string('code', 60);
                $table->string('nom', 150);
                // Paramètres du rapport hors période (regroupement, catégorie…).
                $table->json('parametres')->nullable();
                // Période relative calculée à chaque envoi (veille, mois précédent…) ; vide : la période par défaut.
                $table->string('periode', 30)->nullable();
                $table->string('format', 4)->default('pdf');
                $table->string('langue', 2)->default('fr');
                $table->json('destinataires');
                // quotidienne, hebdomadaire (jour 1 = lundi … 7 = dimanche) ou mensuelle (jour 1 à 31 : le dernier du mois s'il est plus court).
                $table->string('frequence', 20);
                $table->unsignedTinyInteger('jour')->nullable();
                $table->boolean('actif')->default(true);
                // Compte dont les droits s'appliquent à l'envoi (le dernier à avoir réglé l'envoi).
                $table->unsignedBigInteger('compte_id')->nullable();
                $table->date('prochain_envoi')->nullable();
                $table->timestamp('dernier_envoi_le')->nullable();
                $table->unsignedBigInteger('dernier_envoi_id')->nullable();
                $table->string('derniere_erreur', 500)->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'actif', 'prochain_envoi'], 'rapports_programmes_echeance_index');
            });
        }

        $this->reglerDroits();
    }

    private function reglerDroits(): void
    {
        // Rejouable : les actions pour chaque groupe qui ne les a pas encore (rôles système et groupes des entreprises).
        if (! Schema::hasColumns('role_permissions', ['role_id', 'module']) || ! Schema::hasTable('role_actions')) {
            return;
        }
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        foreach (DB::table('role_permissions')->where('module', 'rapports')->whereIn('role_id', $groupes)->get() as $ligne) {
            foreach (['rapports.envoyer' => (bool) $ligne->can_view, 'rapports.programmer' => $ligne->can_view && $ligne->can_edit] as $permission => $accorde) {
                DB::table('role_actions')->insertOrIgnore([
                    'role_id' => $ligne->role_id, 'permission' => $permission, 'accorde' => $accorde,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_actions')->whereIn('permission', ['rapports.envoyer', 'rapports.programmer'])->delete();
        Schema::dropIfExists('rapports_programmes');
        if (Schema::hasColumn('courriel_envois', 'rapport')) {
            Schema::table('courriel_envois', fn (Blueprint $table) => $table->dropColumn('rapport'));
        }
    }
};
