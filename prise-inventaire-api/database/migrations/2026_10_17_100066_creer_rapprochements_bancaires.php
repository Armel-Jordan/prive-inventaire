<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rapprochement bancaire (plan de parité fripv2, phase 4.6 : « Conciliation bancaire ») : pour un compte bancaire et un
 * relevé (date, solde), les lignes de son compte comptable pointées une à une ; terminé quand l'écart avec le relevé est
 * nul. Une ligne pointée ne se modifie plus.
 *
 * Ajouts seulement : table rapprochements_bancaires, colonne ecritures_comptables_lignes.rapprochement_id. Les droits
 * existent déjà (action « banque.rapprocher »).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rapprochements_bancaires')) {
            Schema::create('rapprochements_bancaires', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('compte_bancaire_id')->constrained('comptes_bancaires')->restrictOnDelete();
                // Relevé de la banque : sa date et son solde de fin ; solde de départ : celui du relevé précédent.
                $table->date('date_releve');
                $table->decimal('solde_releve', 15, 2);
                $table->decimal('solde_depart', 15, 2)->default(0);
                // en_cours, termine
                $table->string('statut', 20)->default('en_cours');
                $table->text('notes')->nullable();
                $table->foreignId('cree_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamp('termine_le')->nullable();
                $table->foreignId('termine_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamps();

                $table->index(['tenant_id', 'compte_bancaire_id', 'date_releve'], 'rapprochements_compte_date_index');
            });
        }

        Schema::table('ecritures_comptables_lignes', function (Blueprint $table) {
            if (! Schema::hasColumn('ecritures_comptables_lignes', 'rapprochement_id')) {
                // Nom court : MySQL limite les identifiants à 64 caractères.
                $table->foreignId('rapprochement_id')->nullable()
                    ->constrained('rapprochements_bancaires', 'id', 'lignes_rapprochement_foreign')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ecritures_comptables_lignes', function (Blueprint $table) {
            $table->dropForeign('lignes_rapprochement_foreign');
            $table->dropColumn('rapprochement_id');
        });
        Schema::dropIfExists('rapprochements_bancaires');
    }
};
