<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relevés bancaires importés (plan de parité fripv2, phase 4.6 : « Flux bancaire », ici par fichier CSV, OFX ou CAMT
 * plutôt que par une connexion Plaid) : les opérations du relevé de la banque, reliées une à une aux mouvements du compte
 * comptable du compte bancaire (une opération peut en régler plusieurs ; un mouvement n'est relié qu'à une opération).
 *
 * Ajouts seulement : comptes_bancaires.import_reglages (colonnes du CSV de la banque, mémorisées) ; tables
 * releves_bancaires (fichiers importés), releve_bancaire_lignes (opérations) et releve_bancaire_liens. Les droits
 * existent déjà (« banque.rapprocher », « banque.saisir »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comptes_bancaires', function (Blueprint $table) {
            if (! Schema::hasColumn('comptes_bancaires', 'import_reglages')) {
                $table->json('import_reglages')->nullable();
            }
        });

        if (! Schema::hasTable('releves_bancaires')) {
            Schema::create('releves_bancaires', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('compte_bancaire_id')->constrained('comptes_bancaires')->restrictOnDelete();
                $table->string('fichier', 255);
                // csv, ofx, camt
                $table->string('format', 10);
                $table->unsignedInteger('nombre_lignes')->default(0);
                $table->unsignedInteger('doublons')->default(0);
                $table->foreignId('importe_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('releve_bancaire_lignes')) {
            Schema::create('releve_bancaire_lignes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('releve_id')->constrained('releves_bancaires')->cascadeOnDelete();
                $table->foreignId('compte_bancaire_id')->constrained('comptes_bancaires')->restrictOnDelete();
                $table->date('date_operation');
                $table->string('libelle', 255);
                $table->string('reference', 100)->nullable();
                // Positif : argent reçu ; négatif : argent sorti.
                $table->decimal('montant', 15, 2);
                // Identifiant de l'opération (FITID d'un OFX, référence de la banque d'un CAMT, sinon une empreinte de la
                // ligne) : un réimport ne la double pas.
                $table->string('identifiant', 100);
                $table->boolean('exclue')->default(false);
                $table->string('motif_exclusion', 255)->nullable();
                $table->timestamps();

                // Noms courts : MySQL limite les identifiants à 64 caractères.
                $table->unique(['tenant_id', 'compte_bancaire_id', 'identifiant'], 'releve_lignes_identifiant_unique');
                $table->index(['tenant_id', 'compte_bancaire_id', 'date_operation'], 'releve_lignes_compte_date_index');
            });
        }

        if (! Schema::hasTable('releve_bancaire_liens')) {
            Schema::create('releve_bancaire_liens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('releve_ligne_id')->constrained('releve_bancaire_lignes')->cascadeOnDelete();
                // Un mouvement du compte comptable n'est relié qu'à une opération du relevé.
                $table->foreignId('ecriture_ligne_id')->unique('releve_liens_ecriture_unique')
                    ->constrained('ecritures_comptables_lignes', 'id', 'releve_liens_ecriture_foreign')->cascadeOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('releve_bancaire_liens');
        Schema::dropIfExists('releve_bancaire_lignes');
        Schema::dropIfExists('releves_bancaires');
        Schema::table('comptes_bancaires', fn (Blueprint $table) => $table->dropColumn('import_reglages'));
    }
};
