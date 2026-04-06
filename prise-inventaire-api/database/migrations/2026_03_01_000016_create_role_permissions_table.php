<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Table des rôles personnalisés
        Schema::create('roles_custom', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 50)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        // Table des permissions par module
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->string('module', 50);
            $table->boolean('can_view')->default(false);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->timestamps();

            $table->foreign('role_id')->references('id')->on('roles_custom')->onDelete('cascade');
            $table->unique(['role_id', 'module']);
        });

        // Ajouter colonne role_id aux utilisateurs
        if (!Schema::hasColumn('users', 'role_custom_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('role_custom_id')->nullable();
            });
        }

        // Créer les rôles par défaut
        $this->createDefaultRoles();
    }

    private function createDefaultRoles(): void
    {
        $modules = [
            'dashboard', 'inventaires', 'statistiques', 'comparaison', 'alertes',
            'historique', 'tracabilite', 'relocalisation', 'planification',
            'approbations', 'rapports', 'inventaire_tournant', 'produits',
            'secteurs', 'employes', 'roles', 'fournisseurs', 'commandes_fournisseur',
            'receptions', 'clients', 'commandes_client', 'factures', 'bons_livraison',
            'camions', 'tournees', 'zones_preparation', 'devis', 'comptabilite',
            'previsions_stock', 'gestion_prix', 'alertes_config', 'configuration',
        ];

        // Admin - Tout accès
        $adminId = DB::table('roles_custom')->insertGetId([
            'nom' => 'admin',
            'description' => 'Administrateur - Accès complet',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($modules as $module) {
            DB::table('role_permissions')->insert([
                'role_id' => $adminId,
                'module' => $module,
                'can_view' => true,
                'can_create' => true,
                'can_edit' => true,
                'can_delete' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Manager - Accès étendu sans suppression ni gestion des rôles
        $managerId = DB::table('roles_custom')->insertGetId([
            'nom' => 'manager',
            'description' => 'Manager - Accès étendu sans suppression',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $managerModules = [
            'dashboard', 'inventaires', 'statistiques', 'comparaison', 'alertes',
            'historique', 'tracabilite', 'relocalisation', 'planification',
            'approbations', 'rapports', 'inventaire_tournant', 'produits',
            'secteurs', 'employes'
        ];

        foreach ($modules as $module) {
            $canView = in_array($module, $managerModules);
            $canCreate = $canView && $module !== 'roles';
            $canEdit = $canView && $module !== 'roles';
            $canDelete = false; // Manager ne peut pas supprimer

            DB::table('role_permissions')->insert([
                'role_id' => $managerId,
                'module' => $module,
                'can_view' => $canView,
                'can_create' => $canCreate,
                'can_edit' => $canEdit,
                'can_delete' => $canDelete,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // User - Accès limité
        $userId = DB::table('roles_custom')->insertGetId([
            'nom' => 'user',
            'description' => 'Utilisateur - Accès limité',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userModules = ['dashboard', 'inventaires', 'statistiques', 'relocalisation', 'produits', 'secteurs'];

        foreach ($modules as $module) {
            $canView = in_array($module, $userModules);
            $canCreate = in_array($module, ['inventaires', 'relocalisation']);
            $canEdit = in_array($module, ['inventaires']);

            DB::table('role_permissions')->insert([
                'role_id' => $userId,
                'module' => $module,
                'can_view' => $canView,
                'can_create' => $canCreate,
                'can_edit' => $canEdit,
                'can_delete' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Readonly - Lecture seule
        $readonlyId = DB::table('roles_custom')->insertGetId([
            'nom' => 'readonly',
            'description' => 'Lecture seule - Consultation uniquement',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $readonlyModules = ['dashboard', 'inventaires', 'statistiques', 'rapports'];

        foreach ($modules as $module) {
            DB::table('role_permissions')->insert([
                'role_id' => $readonlyId,
                'module' => $module,
                'can_view' => in_array($module, $readonlyModules),
                'can_create' => false,
                'can_edit' => false,
                'can_delete' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'role_custom_id')) {
                $table->dropColumn('role_custom_id');
            }
        });
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles_custom');
    }
};
