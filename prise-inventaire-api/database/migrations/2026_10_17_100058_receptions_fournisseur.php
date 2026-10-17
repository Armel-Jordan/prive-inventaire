<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Réception fournisseur documentée (plan de parité fripv2, phase 3.3 : « Réception fournisseur ») : chaque réception
 * devient un document numéroté qui regroupe ses lignes, avec le bon de livraison du fournisseur, le connaissement du
 * transporteur et une note ; chaque ligne porte le coût unitaire réellement facturé. Une réception se corrige (date,
 * références, coûts) ou s'annule tant que rien n'en dépend.
 *
 * Ajouts seulement :
 *  - table receptions (entête) ;
 *  - reception_arrivages_ligne : reception_id, cout_unitaire, annulee (les réceptions enregistrées avant restent sans
 *    entête et comptent comme avant) ;
 *  - com_four_ligne.quantite_abandonnee : reste que le fournisseur ne livrera pas (ligne ou commande clôturée), gardé
 *    visible alors que la quantité commandée est ramenée à la quantité reçue ;
 *  - action « receptions.annuler », réglée comme le droit de supprimer des réceptions pour chaque groupe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('receptions')) {
            Schema::create('receptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('numero', 30);
                $table->foreignId('fournisseur_id')->constrained('fournisseurs')->restrictOnDelete();
                $table->date('date_reception');
                // Numéro du bon de livraison du fournisseur et du connaissement du transporteur, tels qu'ils figurent
                // sur les documents reçus : ils servent à rapprocher la réception de la facture du fournisseur.
                $table->string('bon_livraison', 60)->nullable();
                $table->string('connaissement', 60)->nullable();
                $table->text('notes')->nullable();
                $table->string('statut', 20)->default('enregistree');
                $table->foreignId('recue_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->timestamp('annulee_le')->nullable();
                $table->foreignId('annulee_par')->nullable()->constrained('admin_users')->nullOnDelete();
                $table->string('motif_annulation', 255)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'numero']);
                $table->index(['tenant_id', 'date_reception']);
            });
        }

        Schema::table('reception_arrivages_ligne', function (Blueprint $table) {
            if (! Schema::hasColumn('reception_arrivages_ligne', 'reception_id')) {
                $table->foreignId('reception_id')->nullable()->after('id')->constrained('receptions')->nullOnDelete();
            }
            if (! Schema::hasColumn('reception_arrivages_ligne', 'cout_unitaire')) {
                // Coût unitaire facturé (devise de la commande, par unité d'achat) ; vide = prix de la ligne de commande.
                $table->decimal('cout_unitaire', 14, 4)->nullable()->after('quantite_recue');
            }
            if (! Schema::hasColumn('reception_arrivages_ligne', 'annulee')) {
                // Réception annulée : ses quantités ne comptent plus (commande, stock attendu, valorisation).
                $table->boolean('annulee')->default(false)->after('notes');
            }
        });

        Schema::table('com_four_ligne', function (Blueprint $table) {
            if (! Schema::hasColumn('com_four_ligne', 'quantite_abandonnee')) {
                $table->unsignedInteger('quantite_abandonnee')->default(0)->after('quantite_recue');
            }
        });

        // Rejouable : l'action pour chaque groupe qui ne l'a pas encore (rôles système et groupes des entreprises).
        $groupes = DB::table('roles_custom')->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('tenant_id'))->pluck('id');
        foreach (DB::table('role_permissions')->where('module', 'receptions')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_actions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'permission' => 'receptions.annuler', 'accorde' => (bool) $ligne->can_delete,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_actions')->where('permission', 'receptions.annuler')->delete();
        Schema::table('com_four_ligne', fn (Blueprint $table) => $table->dropColumn('quantite_abandonnee'));
        Schema::table('reception_arrivages_ligne', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reception_id');
            $table->dropColumn(['cout_unitaire', 'annulee']);
        });
        Schema::dropIfExists('receptions');
    }
};
