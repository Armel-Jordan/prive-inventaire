<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestion de caisse (plan de parité fripv2, phase 1.10a : « Caisses », « Gestion de caisse », « Fermeture de
 * caisse », « Sommaire de caisse ») : une caisse s'ouvre avec un fond, reçoit les encaissements, les acomptes et
 * les remboursements d'acompte faits sur le poste, des entrées et sorties d'argent, puis se ferme en comptant le
 * tiroir ; l'attendu, le compté et l'écart sont alors figés.
 *
 * Ajouts seulement : référentiel caisses, tables caisse_sessions et caisse_mouvements, colonne caisse_session_id
 * (nullable) sur facture_paiements, acomptes et acompte_remboursements. Droits du nouveau module « caisses » : ceux
 * des factures ; gérer les sessions comme enregistrer un paiement.
 */
return new class extends Migration
{
    /** Tables d'encaissement rattachées à une session de caisse. */
    private const ENCAISSEMENTS = ['facture_paiements', 'acomptes', 'acompte_remboursements'];

    public function up(): void
    {
        if (! Schema::hasTable('caisses')) {
            Schema::create('caisses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('code', 10);
                $table->string('nom', 100);
                $table->boolean('actif')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });
        }
        if (! Schema::hasTable('caisse_sessions')) {
            Schema::create('caisse_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('caisse_id')->constrained('caisses');
                // ouverte, fermee : une seule session ouverte par caisse.
                $table->string('statut', 10)->default('ouverte');
                $table->dateTime('ouverte_le');
                $table->unsignedBigInteger('ouverte_par')->nullable();
                $table->dateTime('fermee_le')->nullable();
                $table->unsignedBigInteger('fermee_par')->nullable();
                $table->string('note_fermeture', 1000)->nullable();
                // Figés à la fermeture : par mode et devise, fond, encaissements, acomptes, remboursements, entrées,
                // sorties, attendu, compté et écart ; total des écarts dans la devise de l'entreprise.
                $table->json('chiffres')->nullable();
                $table->decimal('total_ecart', 14, 2)->nullable();
                $table->timestamps();

                $table->index(['caisse_id', 'statut']);
            });
        }
        if (! Schema::hasTable('caisse_mouvements')) {
            Schema::create('caisse_mouvements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('caisse_session_id')->constrained('caisse_sessions')->cascadeOnDelete();
                // fond (à l'ouverture), entree, sortie.
                $table->string('type', 10);
                $table->string('mode', 20);
                // Devise de l'argent (null : celle de l'entreprise).
                $table->string('devise', 3)->nullable();
                $table->decimal('montant', 14, 2);
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('cree_par')->nullable();
                $table->timestamps();
            });
        }
        foreach (self::ENCAISSEMENTS as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'caisse_session_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('caisse_session_id')->nullable()->index();
                });
            }
        }
        $this->reglerDroits();
    }

    private function reglerDroits(): void
    {
        // Rejouable : droits du module pour chaque groupe qui n'en a pas encore (rôles système et groupes des
        // entreprises ; les anciens rôles personnalisés sans entreprise restent tels quels).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        // Gérer les sessions suit le droit d'enregistrer un paiement : réglé pour le groupe, sinon sa base (modifier).
        $encaisser = DB::table('role_actions')->where('permission', 'factures.encaisser')->whereIn('role_id', $groupes)
            ->pluck('accorde', 'role_id');
        foreach (DB::table('role_permissions')->where('module', 'factures')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'caisses',
                'can_view' => $ligne->can_view, 'can_create' => $ligne->can_create,
                'can_edit' => $ligne->can_edit, 'can_delete' => $ligne->can_delete,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('role_actions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'permission' => 'caisses.gerer',
                'accorde' => (bool) ($encaisser[$ligne->role_id] ?? $ligne->can_edit),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('module', 'caisses')->delete();
        DB::table('role_actions')->where('permission', 'caisses.gerer')->delete();
        foreach (self::ENCAISSEMENTS as $table) {
            if (Schema::hasColumn($table, 'caisse_session_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropIndex(['caisse_session_id']);
                    $t->dropColumn('caisse_session_id');
                });
            }
        }
        Schema::dropIfExists('caisse_mouvements');
        Schema::dropIfExists('caisse_sessions');
        Schema::dropIfExists('caisses');
    }
};
