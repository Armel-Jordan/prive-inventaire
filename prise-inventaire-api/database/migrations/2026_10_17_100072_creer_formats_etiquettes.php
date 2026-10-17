<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Étiquettes des produits (plan de parité fripv2, phase 2.1) : des formats d'étiquettes (rouleau d'une étiquette par
 * page pour une imprimante d'étiquettes, ou planche A4 / Letter à découper) et leur contenu (description, code-barres,
 * numéro, prix…), puis l'impression en PDF.
 *
 * Ajouts seulement : table etiquette_formats (les formats d'origine sont créés au premier affichage, par entreprise) ;
 * module de droits « etiquettes » (consulter et imprimer, gérer les formats), réglé pour chaque groupe d'après ses
 * droits sur les produits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('etiquette_formats')) {
            Schema::create('etiquette_formats', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('nom', 100);
                // rouleau : une étiquette par page, de la taille de l'étiquette ; planche : une grille sur une page.
                $table->string('support', 10);
                $table->decimal('largeur_mm', 6, 2);
                $table->decimal('hauteur_mm', 6, 2);
                // Planche : la page (A4, LETTER), la grille et ses marges.
                $table->string('page', 10)->nullable();
                $table->unsignedSmallInteger('colonnes')->default(1);
                $table->unsignedSmallInteger('rangees')->default(1);
                $table->decimal('marge_haut_mm', 6, 2)->default(0);
                $table->decimal('marge_gauche_mm', 6, 2)->default(0);
                $table->decimal('espace_colonnes_mm', 6, 2)->default(0);
                $table->decimal('espace_rangees_mm', 6, 2)->default(0);
                // Ce que porte l'étiquette : entreprise, description, code_barres, chiffres, numero, prix, prix_taxes, date.
                $table->json('contenu');
                $table->unsignedTinyInteger('taille_police')->default(7);
                $table->boolean('par_defaut')->default(false);
                $table->boolean('actif')->default(true);
                $table->unsignedSmallInteger('ordre')->default(0);
                $table->timestamps();

                $table->unique(['tenant_id', 'nom'], 'etiquette_formats_nom_unique');
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
        // Imprimer comme on voit les produits ; gérer les formats comme on modifie les produits.
        foreach (DB::table('role_permissions')->where('module', 'produits')->whereIn('role_id', $groupes)->get() as $ligne) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'module' => 'etiquettes',
                'can_view' => $ligne->can_view, 'can_create' => $ligne->can_edit, 'can_edit' => $ligne->can_edit, 'can_delete' => $ligne->can_edit,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('role_actions')->insertOrIgnore([
                'role_id' => $ligne->role_id, 'permission' => 'etiquettes.formats', 'accorde' => (bool) $ligne->can_edit,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_actions')->where('permission', 'etiquettes.formats')->delete();
        DB::table('role_permissions')->where('module', 'etiquettes')->delete();
        Schema::dropIfExists('etiquette_formats');
    }
};
