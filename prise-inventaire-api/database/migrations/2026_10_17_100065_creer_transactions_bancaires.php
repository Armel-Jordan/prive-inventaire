<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Banque (plan de parité fripv2, phase 4.6) : les comptes bancaires de l'entreprise (référentiel existant) reliés à
 * leur compte comptable et à leur journal, les transactions bancaires sans facture (dépôts, retraits, virements entre
 * comptes, frais, intérêts) et le compte bancaire par lequel passent les encaissements et les déboursés.
 *
 * Ajouts seulement :
 *  - comptes_bancaires.compte_comptable_id et .journal_id ;
 *  - tables transactions_bancaires et transaction_bancaire_lignes ;
 *  - encaissements.compte_bancaire_id et debourses.compte_bancaire_id ;
 *  - module de droits « banque », réglé pour chaque groupe d'après ses droits de comptabilité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comptes_bancaires', function (Blueprint $table) {
            if (! Schema::hasColumn('comptes_bancaires', 'compte_comptable_id')) {
                $table->foreignId('compte_comptable_id')->nullable()->constrained('comptes_comptables')->nullOnDelete();
            }
            if (! Schema::hasColumn('comptes_bancaires', 'journal_id')) {
                $table->foreignId('journal_id')->nullable()->constrained('journaux_comptables')->nullOnDelete();
            }
        });

        if (! Schema::hasTable('transactions_bancaires')) {
            Schema::create('transactions_bancaires', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('numero', 30);
                $table->foreignId('compte_bancaire_id')->constrained('comptes_bancaires')->restrictOnDelete();
                $table->date('date_transaction');
                // depot (argent reçu), retrait (argent sorti), virement (vers un autre compte de l'entreprise)
                $table->string('type', 20);
                $table->foreignId('compte_bancaire_destination_id')->nullable()->constrained('comptes_bancaires')->restrictOnDelete();
                $table->string('reference', 100)->nullable();
                $table->string('libelle', 255);
                // Devise du compte bancaire (vide : celle de l'entreprise) et son taux.
                $table->string('devise', 10)->nullable();
                $table->decimal('taux_change', 14, 6)->default(1);
                $table->decimal('montant', 14, 2);
                $table->text('notes')->nullable();
                // valide, annule
                $table->string('statut', 20)->default('valide');
                $table->foreignId('enregistre_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamp('annule_le')->nullable();
                $table->foreignId('annule_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->string('motif_annulation', 255)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'numero']);
                // Nom court : MySQL limite les identifiants à 64 caractères.
                $table->index(['tenant_id', 'compte_bancaire_id', 'date_transaction'], 'transactions_bancaires_compte_date_index');
            });
        }

        if (! Schema::hasTable('transaction_bancaire_lignes')) {
            Schema::create('transaction_bancaire_lignes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transaction_bancaire_id')->constrained('transactions_bancaires')->cascadeOnDelete();
                // Contrepartie du mouvement : produit d'un dépôt, charge d'un retrait (frais, achat sans facture)…
                $table->foreignId('compte_comptable_id')->constrained('comptes_comptables')->restrictOnDelete();
                $table->string('libelle', 255)->nullable();
                // Négatif : passe de l'autre côté (un dépôt net de frais, par exemple).
                $table->decimal('montant', 14, 2);
                $table->unsignedSmallInteger('ordre')->default(0);
            });
        }

        foreach (['encaissements', 'debourses'] as $document) {
            Schema::table($document, function (Blueprint $table) use ($document) {
                if (! Schema::hasColumn($document, 'compte_bancaire_id')) {
                    $table->foreignId('compte_bancaire_id')->nullable()->constrained('comptes_bancaires')->nullOnDelete();
                }
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
        // Consulter comme la comptabilité ; saisir, annuler et rapprocher comme la saisie d'écritures ; relier les comptes
        // comme la modification du plan comptable (chaque action réglée pour le groupe, sinon sa base).
        $actions = fn (string $permission) => DB::table('role_actions')->where('permission', $permission)->whereIn('role_id', $groupes)->pluck('accorde', 'role_id');
        $ecrire = $actions('comptabilite.ecrire');
        $plan = $actions('comptabilite.plan');
        foreach (DB::table('role_permissions')->where('module', 'comptabilite')->whereIn('role_id', $groupes)->get() as $ligne) {
            $saisir = (bool) ($ecrire[$ligne->role_id] ?? $ligne->can_create);
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'banque',
                'can_view' => $ligne->can_view, 'can_create' => $saisir, 'can_edit' => $saisir, 'can_delete' => $saisir,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $reglees = [
                'banque.saisir' => $saisir, 'banque.annuler' => $saisir, 'banque.rapprocher' => $saisir,
                'banque.configurer' => (bool) ($plan[$ligne->role_id] ?? $ligne->can_edit),
            ];
            foreach ($reglees as $permission => $accorde) {
                DB::table('role_actions')->insertOrIgnore([
                    'role_id' => $ligne->role_id, 'permission' => $permission, 'accorde' => $accorde,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_actions')->whereIn('permission', ['banque.saisir', 'banque.annuler', 'banque.rapprocher', 'banque.configurer'])->delete();
        DB::table('role_permissions')->where('module', 'banque')->delete();
        foreach (['encaissements', 'debourses'] as $document) {
            Schema::table($document, fn (Blueprint $table) => $table->dropConstrainedForeignId('compte_bancaire_id'));
        }
        Schema::dropIfExists('transaction_bancaire_lignes');
        Schema::dropIfExists('transactions_bancaires');
        Schema::table('comptes_bancaires', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_id');
            $table->dropConstrainedForeignId('compte_comptable_id');
        });
    }
};
