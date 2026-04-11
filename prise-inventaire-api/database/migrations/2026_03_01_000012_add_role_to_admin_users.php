<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ajouter la colonne role si elle n'existe pas
        if (! Schema::hasColumn('admin_users', 'role')) {
            Schema::table('admin_users', function (Blueprint $table) {
                $table->string('role', 20)->default('user')->after('password');
            });
        }

        // Table des permissions par rôle
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 20);
            $table->string('permission', 50);
            $table->timestamps();

            $table->unique(['role', 'permission']);
            $table->index('role');
        });

        // Insérer les permissions par défaut
        $permissions = [
            // Admin - toutes les permissions
            ['role' => 'admin', 'permission' => 'scans.read'],
            ['role' => 'admin', 'permission' => 'scans.write'],
            ['role' => 'admin', 'permission' => 'scans.delete'],
            ['role' => 'admin', 'permission' => 'produits.read'],
            ['role' => 'admin', 'permission' => 'produits.write'],
            ['role' => 'admin', 'permission' => 'produits.delete'],
            ['role' => 'admin', 'permission' => 'secteurs.read'],
            ['role' => 'admin', 'permission' => 'secteurs.write'],
            ['role' => 'admin', 'permission' => 'secteurs.delete'],
            ['role' => 'admin', 'permission' => 'employes.read'],
            ['role' => 'admin', 'permission' => 'employes.write'],
            ['role' => 'admin', 'permission' => 'employes.delete'],
            ['role' => 'admin', 'permission' => 'relocalisation.read'],
            ['role' => 'admin', 'permission' => 'relocalisation.write'],
            ['role' => 'admin', 'permission' => 'approbations.read'],
            ['role' => 'admin', 'permission' => 'approbations.approve'],
            ['role' => 'admin', 'permission' => 'rapports.read'],
            ['role' => 'admin', 'permission' => 'users.read'],
            ['role' => 'admin', 'permission' => 'users.write'],
            ['role' => 'admin', 'permission' => 'settings.write'],

            // Manager - lecture/écriture sans suppression ni gestion users
            ['role' => 'manager', 'permission' => 'scans.read'],
            ['role' => 'manager', 'permission' => 'scans.write'],
            ['role' => 'manager', 'permission' => 'produits.read'],
            ['role' => 'manager', 'permission' => 'produits.write'],
            ['role' => 'manager', 'permission' => 'secteurs.read'],
            ['role' => 'manager', 'permission' => 'secteurs.write'],
            ['role' => 'manager', 'permission' => 'employes.read'],
            ['role' => 'manager', 'permission' => 'employes.write'],
            ['role' => 'manager', 'permission' => 'relocalisation.read'],
            ['role' => 'manager', 'permission' => 'relocalisation.write'],
            ['role' => 'manager', 'permission' => 'approbations.read'],
            ['role' => 'manager', 'permission' => 'approbations.approve'],
            ['role' => 'manager', 'permission' => 'rapports.read'],

            // User - lecture/écriture basique
            ['role' => 'user', 'permission' => 'scans.read'],
            ['role' => 'user', 'permission' => 'scans.write'],
            ['role' => 'user', 'permission' => 'produits.read'],
            ['role' => 'user', 'permission' => 'secteurs.read'],
            ['role' => 'user', 'permission' => 'employes.read'],
            ['role' => 'user', 'permission' => 'relocalisation.read'],
            ['role' => 'user', 'permission' => 'relocalisation.write'],

            // Readonly - lecture seule
            ['role' => 'readonly', 'permission' => 'scans.read'],
            ['role' => 'readonly', 'permission' => 'produits.read'],
            ['role' => 'readonly', 'permission' => 'secteurs.read'],
            ['role' => 'readonly', 'permission' => 'employes.read'],
            ['role' => 'readonly', 'permission' => 'relocalisation.read'],
            ['role' => 'readonly', 'permission' => 'rapports.read'],
        ];

        foreach ($permissions as $perm) {
            \DB::table('role_permissions')->insert([
                ...$perm,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
        Schema::dropIfExists('role_permissions');
    }
};
