<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rôles et permissions par module (chantier C7) : remet la base dans l'état attendu par l'écran
 * « Rôles » et par /permissions/me, quelle que soit son histoire.
 *
 * - Deux migrations créaient role_permissions avec des structures différentes. Sur une base créée
 *   dans l'ordre, l'ancienne (role, permission) l'emportait : les permissions par module n'existaient
 *   pas et un manager ou un utilisateur voyait un menu vide. L'ancienne table est conservée sous le
 *   nom role_permissions_anciennes (RoleController et CheckPermission la lisent) ; la nouvelle est créée.
 * - Les 4 rôles système (tenant_id NULL) existent, avec une ligne par module : la matrice reprend
 *   celle des migrations précédentes. Les lignes déjà présentes ne sont jamais modifiées.
 * - Le nom d'un rôle est unique par entreprise, et non plus sur toute la plateforme.
 */
return new class extends Migration
{
    private const MODULES_INVENTAIRE = [
        'dashboard', 'inventaires', 'statistiques', 'comparaison', 'alertes',
        'historique', 'tracabilite', 'relocalisation', 'planification',
        'approbations', 'rapports', 'inventaire_tournant', 'produits',
        'secteurs', 'employes', 'roles', 'fiches_employes',
    ];

    private const MODULES_ACHATS = ['fournisseurs', 'commandes_fournisseur', 'receptions'];

    private const MODULES_VENTES = [
        'clients', 'commandes_client', 'factures', 'bons_livraison', 'camions', 'tournees',
        'mouvements_inventaire', 'localisations', 'zones_preparation',
    ];

    private const MODULES_GESTION = ['configuration', 'gestion_prix', 'alertes_config', 'devis', 'comptabilite', 'previsions_stock'];

    private const ROLES_SYSTEME = [
        'admin' => 'Administrateur - Accès complet',
        'manager' => 'Manager - Accès étendu sans suppression',
        'user' => 'Utilisateur - Accès limité',
        'readonly' => 'Lecture seule - Consultation uniquement',
    ];

    public function up(): void
    {
        if (Schema::hasTable('role_permissions') && ! Schema::hasColumn('role_permissions', 'role_id')) {
            Schema::rename('role_permissions', 'role_permissions_anciennes');
        }

        if (! Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('role_id');
                $table->string('module', 50);
                $table->boolean('can_view')->default(false);
                $table->boolean('can_create')->default(false);
                $table->boolean('can_edit')->default(false);
                $table->boolean('can_delete')->default(false);
                $table->timestamps();

                $table->foreign('role_id')->references('id')->on('roles_custom')->cascadeOnDelete();
                $table->unique(['role_id', 'module']);
            });
        }

        // D'abord l'unicité par entreprise : un ancien rôle personnalisé sans entreprise peut porter le
        // nom d'un rôle système absent, ce que l'unicité globale interdirait à l'insertion ci-dessous.
        $this->nomUniqueParEntreprise();

        foreach (self::ROLES_SYSTEME as $nom => $description) {
            $role = DB::table('roles_custom')->whereNull('tenant_id')->where('is_system', true)->where('nom', $nom)->first();
            $roleId = $role?->id ?? DB::table('roles_custom')->insertGetId([
                'nom' => $nom, 'description' => $description, 'is_system' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($this->matrice($nom) as $module => [$voir, $creer, $modifier, $supprimer]) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId, 'module' => $module,
                    'can_view' => $voir, 'can_create' => $creer, 'can_edit' => $modifier, 'can_delete' => $supprimer,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Droits par défaut d'un rôle système : [voir, créer, modifier, supprimer] par module, tels que les
     * posaient create_role_permissions_table et les migrations add_*_permissions qui l'ont suivie.
     *
     * @return array<string, array{bool, bool, bool, bool}>
     */
    private function matrice(string $role): array
    {
        $droits = [];
        foreach (self::MODULES_INVENTAIRE as $module) {
            $droits[$module] = match ($role) {
                'admin' => [true, true, true, true],
                'manager' => in_array($module, ['roles', 'fiches_employes'], true)
                    ? [false, false, false, false] : [true, true, true, false],
                'user' => [
                    in_array($module, ['dashboard', 'inventaires', 'statistiques', 'relocalisation', 'produits', 'secteurs'], true),
                    in_array($module, ['inventaires', 'relocalisation'], true),
                    $module === 'inventaires',
                    false,
                ],
                default => [in_array($module, ['dashboard', 'inventaires', 'statistiques', 'rapports'], true), false, false, false],
            };
        }
        foreach (self::MODULES_ACHATS as $module) {
            $droits[$module] = match ($role) {
                'admin' => [true, true, true, true],
                'manager' => [true, true, true, false],
                'user' => [true, $module === 'receptions', false, false],
                default => [true, false, false, false],
            };
        }
        foreach (self::MODULES_VENTES as $module) {
            $droits[$module] = match ($role) {
                'admin' => [true, true, true, true],
                'manager' => [true, true, true, ! in_array($module, ['clients', 'camions'], true)],
                'user' => in_array($module, ['commandes_client', 'bons_livraison'], true) ? [true, true, true, false] : [true, false, false, false],
                default => [true, false, false, false],
            };
        }
        foreach (self::MODULES_GESTION as $module) {
            $droits[$module] = match ($role) {
                'admin' => [true, true, true, true],
                'manager' => $module === 'configuration' ? [false, false, false, false] : [true, true, true, false],
                'user' => [in_array($module, ['devis', 'comptabilite', 'previsions_stock'], true), false, false, false],
                default => [false, false, false, false],
            };
        }
        $droits['referentiels'] = match ($role) {
            'admin' => [true, true, true, true],
            'manager' => [true, true, true, false],
            default => [false, false, false, false],
        };

        return $droits;
    }

    /** Remplace l'unicité globale du nom par une unicité par entreprise (index vérifiés avant d'y toucher). */
    private function nomUniqueParEntreprise(): void
    {
        $index = collect(Schema::getIndexes('roles_custom'));
        $uniqueSurNom = $index->first(fn ($i) => $i['unique'] && ! $i['primary'] && $i['columns'] === ['nom']);
        $uniqueParEntreprise = $index->contains(fn ($i) => $i['unique'] && $i['columns'] === ['tenant_id', 'nom']);

        Schema::table('roles_custom', function (Blueprint $table) use ($uniqueSurNom, $uniqueParEntreprise) {
            if ($uniqueSurNom) {
                $table->dropUnique($uniqueSurNom['name']);
            }
            if (! $uniqueParEntreprise) {
                $table->unique(['tenant_id', 'nom'], 'roles_custom_tenant_nom_unique');
            }
        });
    }

    public function down(): void
    {
        $index = collect(Schema::getIndexes('roles_custom'));
        Schema::table('roles_custom', function (Blueprint $table) use ($index) {
            if ($index->contains(fn ($i) => $i['name'] === 'roles_custom_tenant_nom_unique')) {
                $table->dropUnique('roles_custom_tenant_nom_unique');
            }
        });

        if (Schema::hasTable('role_permissions_anciennes')) {
            Schema::dropIfExists('role_permissions');
            Schema::rename('role_permissions_anciennes', 'role_permissions');
        }
    }
};
