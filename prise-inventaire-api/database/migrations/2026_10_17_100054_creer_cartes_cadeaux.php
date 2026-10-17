<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cartes cadeaux (plan de parité fripv2, phase 1.10c : « Cartes cadeaux », « Vente et gestion d'une carte cadeau »,
 * « Certificat cadeau ») : une carte au porteur, vendue puis utilisée comme moyen de paiement jusqu'à épuisement de son
 * solde ; elle se recharge, et son solde se rembourse (elle est alors annulée).
 *
 * Ajouts seulement : tables cartes_cadeaux et carte_cadeau_mouvements (le solde est la somme des mouvements) ;
 * facture_paiements.carte_cadeau_id (paiement fait avec une carte, enregistré en mode « autre » : le mode de paiement est
 * une énumération MySQL, à laquelle rien n'est ajouté). Droits du nouveau module « cartes_cadeaux » : ceux des acomptes ;
 * rembourser comme rembourser un acompte.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cartes_cadeaux')) {
            Schema::create('cartes_cadeaux', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('numero', 30);
                // Client acheteur, à titre indicatif : la carte s'utilise au porteur.
                $table->unsignedBigInteger('client_id')->nullable()->index();
                // Devise de la carte (null : celle de l'entreprise), figée à la vente.
                $table->string('devise', 3)->nullable();
                $table->decimal('montant_initial', 14, 2);
                $table->decimal('solde', 14, 2)->default(0);
                $table->date('date_vente');
                $table->date('expire_le')->nullable();
                $table->boolean('annulee')->default(false);
                $table->string('notes', 1000)->nullable();
                $table->unsignedBigInteger('cree_par')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'numero']);
            });
        }
        if (! Schema::hasTable('carte_cadeau_mouvements')) {
            Schema::create('carte_cadeau_mouvements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->foreignId('carte_cadeau_id')->constrained('cartes_cadeaux')->cascadeOnDelete();
                // vente (+), recharge (+), utilisation (−), annulation_utilisation (+), remboursement (−).
                $table->string('type', 25);
                $table->decimal('montant', 14, 2);
                $table->decimal('solde_apres', 14, 2);
                // Argent reçu ou rendu (vente, recharge, remboursement) : son mode et la caisse où il passe.
                $table->string('mode', 20)->nullable();
                $table->string('reference', 100)->nullable();
                $table->unsignedBigInteger('caisse_session_id')->nullable()->index();
                // Utilisation : la facture payée avec la carte.
                $table->unsignedBigInteger('facture_id')->nullable()->index();
                $table->unsignedBigInteger('facture_paiement_id')->nullable()->index();
                $table->unsignedBigInteger('cree_par')->nullable();
                $table->timestamps();
            });
        }
        if (Schema::hasTable('facture_paiements') && ! Schema::hasColumn('facture_paiements', 'carte_cadeau_id')) {
            Schema::table('facture_paiements', function (Blueprint $table) {
                $table->unsignedBigInteger('carte_cadeau_id')->nullable()->index();
            });
        }
        $this->reglerDroits();
    }

    private function reglerDroits(): void
    {
        // Rejouable : droits du module pour chaque groupe qui n'en a pas encore (rôles système et groupes des
        // entreprises ; les anciens rôles personnalisés sans entreprise restent tels quels).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        // Rembourser suit le droit de rembourser un acompte : réglé pour le groupe, sinon sa base (modifier).
        $rembourser = DB::table('role_actions')->where('permission', 'acomptes.rembourser')->whereIn('role_id', $groupes)
            ->pluck('accorde', 'role_id');
        foreach (DB::table('role_permissions')->where('module', 'acomptes')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'cartes_cadeaux',
                'can_view' => $ligne->can_view, 'can_create' => $ligne->can_create,
                'can_edit' => $ligne->can_edit, 'can_delete' => $ligne->can_delete,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('role_actions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'permission' => 'cartes_cadeaux.rembourser',
                'accorde' => (bool) ($rembourser[$ligne->role_id] ?? $ligne->can_edit),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('module', 'cartes_cadeaux')->delete();
        DB::table('role_actions')->where('permission', 'cartes_cadeaux.rembourser')->delete();
        if (Schema::hasColumn('facture_paiements', 'carte_cadeau_id')) {
            Schema::table('facture_paiements', function (Blueprint $table) {
                $table->dropIndex(['carte_cadeau_id']);
                $table->dropColumn('carte_cadeau_id');
            });
        }
        Schema::dropIfExists('carte_cadeau_mouvements');
        Schema::dropIfExists('cartes_cadeaux');
    }
};
