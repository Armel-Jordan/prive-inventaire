<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Acomptes et demandes d'acompte (plan de parité fripv2, phase 1.5).
 *
 * - Un acompte est un paiement reçu d'un client avant sa facture : libre, ou réservé à une de ses commandes. Il
 *   s'applique au paiement de ses factures (un paiement de la facture rattaché à l'acompte) et ce qui en reste se
 *   rembourse.
 * - Une demande d'acompte (numérotée) demande un dépôt au client : brouillon, envoyée, puis payée ; la marquer payée
 *   enregistre l'acompte.
 *
 * Ajouts seulement : tables acomptes, acompte_remboursements, demandes_acompte ; facture_paiements.acompte_id.
 * Droits des nouveaux modules « acomptes » et « demandes_acompte » : ceux des factures, pour chaque groupe.
 */
return new class extends Migration
{
    /** Module nouveau => module dont il reprend les droits. */
    private const MODULES = ['acomptes' => 'factures', 'demandes_acompte' => 'factures'];

    /** Action nommée => action des factures dont elle reprend le réglage. */
    private const ACTIONS = [
        'acomptes.appliquer' => 'factures.encaisser',
        'acomptes.rembourser' => 'factures.encaisser',
        'demandes_acompte.envoyer' => 'factures.emettre',
        'demandes_acompte.encaisser' => 'factures.encaisser',
    ];

    public function up(): void
    {
        // Rejouable, comme 100029 : les tables déjà créées ne le sont pas de nouveau ; les droits se complètent.
        if (! Schema::hasTable('acomptes')) {
            $this->creerTables();
        }
        $this->reglerDroits();
    }

    private function creerTables(): void
    {
        Schema::create('demandes_acompte', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('numero', 30);
            $table->foreignId('client_id')->constrained('clients');
            $table->date('date_demande');
            $table->text('description');
            $table->decimal('montant', 12, 2);
            // brouillon, envoyee, payee
            $table->string('statut', 20)->default('brouillon');
            // Documents du client auxquels la demande se rapporte ; l'acompte payé est réservé à la commande.
            $table->unsignedBigInteger('devis_id')->nullable();
            $table->unsignedBigInteger('commande_id')->nullable();
            $table->unsignedBigInteger('acompte_id')->nullable();
            $table->dateTime('envoyee_le')->nullable();
            $table->dateTime('payee_le')->nullable();
            $table->unsignedBigInteger('cree_par')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'numero']);
            $table->index(['tenant_id', 'statut']);
        });

        Schema::create('acomptes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('client_id')->constrained('clients');
            $table->date('date_acompte');
            // especes, cheque, virement, carte, autre
            $table->string('mode', 20);
            $table->string('reference', 100)->nullable();
            $table->decimal('montant', 12, 2);
            // Réservé à une commande du client : appliqué de lui-même à sa facture, à l'émission.
            $table->unsignedBigInteger('commande_id')->nullable()->index();
            // Demande d'acompte payée qui l'a créé.
            $table->unsignedBigInteger('demande_id')->nullable()->index();
            $table->text('notes')->nullable();
            // Totaux appliqués sur des factures et remboursés : le reste est disponible.
            $table->decimal('montant_applique', 12, 2)->default(0);
            $table->decimal('montant_rembourse', 12, 2)->default(0);
            $table->unsignedBigInteger('enregistre_par')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'client_id']);
        });

        Schema::create('acompte_remboursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acompte_id')->constrained('acomptes')->cascadeOnDelete();
            $table->date('date_remboursement');
            $table->decimal('montant', 12, 2);
            $table->string('mode', 20)->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('enregistre_par')->nullable();
            $table->timestamps();
        });

        // Paiement d'une facture fait avec un acompte (application).
        Schema::table('facture_paiements', function (Blueprint $table) {
            $table->unsignedBigInteger('acompte_id')->nullable()->index()->after('echeance_id');
        });
    }

    private function reglerDroits(): void
    {
        // Droits des modules : ceux des factures, pour chaque groupe (rôles système et groupes des entreprises ; les
        // anciens rôles personnalisés sans entreprise, masqués à toutes, restent tels quels).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        foreach (self::MODULES as $module => $modele) {
            foreach (DB::table('role_permissions')->where('module', $modele)->whereIn('role_id', $groupes)->get() as $ligne) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $ligne->role_id, 'module' => $module,
                    'can_view' => $ligne->can_view, 'can_create' => $ligne->can_create,
                    'can_edit' => $ligne->can_edit, 'can_delete' => $ligne->can_delete,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        foreach (self::ACTIONS as $action => $modele) {
            foreach (DB::table('role_actions')->where('permission', $modele)->whereIn('role_id', $groupes)->get() as $reglee) {
                DB::table('role_actions')->insertOrIgnore([
                    'role_id' => $reglee->role_id, 'permission' => $action, 'accorde' => $reglee->accorde,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach (DB::table('admin_user_permissions')->where('permission', $modele)->get() as $exception) {
                DB::table('admin_user_permissions')->insertOrIgnore([
                    'admin_user_id' => $exception->admin_user_id, 'permission' => $action, 'accorde' => $exception->accorde,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('module', array_keys(self::MODULES))->delete();
        DB::table('role_actions')->whereIn('permission', array_keys(self::ACTIONS))->delete();
        DB::table('admin_user_permissions')->whereIn('permission', array_keys(self::ACTIONS))->delete();
        // Les paiements faits avec un acompte restent des paiements de leur facture.
        Schema::table('facture_paiements', function (Blueprint $table) {
            $table->dropIndex(['acompte_id']);
            $table->dropColumn('acompte_id');
        });
        Schema::dropIfExists('acompte_remboursements');
        Schema::dropIfExists('acomptes');
        Schema::dropIfExists('demandes_acompte');
    }
};
