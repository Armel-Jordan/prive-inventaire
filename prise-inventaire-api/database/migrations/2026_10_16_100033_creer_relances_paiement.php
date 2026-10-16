<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Relances de paiement (plan de parité fripv2, phase 1.5) : chaque relance d'une facture en retard est notée, par
 * courriel (envoyé depuis l'écran ou par la tâche planifiée des relances) ou faite autrement (téléphone, courrier),
 * avec son niveau (1re, 2e relance…).
 *
 * Ajouts seulement : table facture_relances. Droits du nouveau module « relances » : ceux des factures pour la
 * consultation, ceux d'un paiement (modifier les factures) pour relancer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('facture_relances')) {
            Schema::create('facture_relances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('facture_id')->constrained('factures')->cascadeOnDelete();
                $table->foreignId('client_id')->constrained('clients');
                // 1re, 2e, 3e relance… de la facture.
                $table->unsignedSmallInteger('niveau');
                $table->date('date_relance');
                // courriel, telephone, courrier, autre
                $table->string('mode', 20);
                // Courriel envoyé (historique des courriels).
                $table->unsignedBigInteger('envoi_id')->nullable();
                // Envoyée par la tâche planifiée des relances.
                $table->boolean('automatique')->default(false);
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('cree_par')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'client_id']);
            });
        }

        // Rejouable : droits du module « relances » pour chaque groupe qui n'en a pas encore (rôles système et
        // groupes des entreprises ; les anciens rôles personnalisés sans entreprise restent tels quels).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        foreach (DB::table('role_permissions')->where('module', 'factures')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'relances',
                'can_view' => $ligne->can_view, 'can_create' => $ligne->can_edit,
                'can_edit' => $ligne->can_edit, 'can_delete' => $ligne->can_delete,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('module', 'relances')->delete();
        Schema::dropIfExists('facture_relances');
    }
};
