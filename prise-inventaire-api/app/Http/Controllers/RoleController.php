<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    /**
     * Liste des rôles disponibles
     */
    public function roles(): JsonResponse
    {
        $roles = [
            [
                'id' => 'admin',
                'nom' => 'Administrateur',
                'description' => 'Accès complet à toutes les fonctionnalités',
            ],
            [
                'id' => 'manager',
                'nom' => 'Manager',
                'description' => 'Lecture/écriture, approbations, sans suppression ni gestion utilisateurs',
            ],
            [
                'id' => 'user',
                'nom' => 'Utilisateur',
                'description' => 'Lecture/écriture basique pour scans et relocalisations',
            ],
            [
                'id' => 'readonly',
                'nom' => 'Lecture seule',
                'description' => 'Consultation uniquement, aucune modification',
            ],
        ];

        return response()->json($roles);
    }

    /**
     * Permissions d'un rôle
     */
    public function permissions(string $role): JsonResponse
    {
        $permissions = DB::table('role_permissions')
            ->where('role', $role)
            ->pluck('permission');

        return response()->json([
            'role' => $role,
            'permissions' => $permissions,
        ]);
    }

    /**
     * Toutes les permissions disponibles
     */
    public function allPermissions(): JsonResponse
    {
        $permissions = [
            'scans' => [
                ['id' => 'scans.read', 'nom' => 'Voir les scans'],
                ['id' => 'scans.write', 'nom' => 'Créer/modifier les scans'],
                ['id' => 'scans.delete', 'nom' => 'Supprimer les scans'],
            ],
            'produits' => [
                ['id' => 'produits.read', 'nom' => 'Voir les produits'],
                ['id' => 'produits.write', 'nom' => 'Créer/modifier les produits'],
                ['id' => 'produits.delete', 'nom' => 'Supprimer les produits'],
            ],
            'secteurs' => [
                ['id' => 'secteurs.read', 'nom' => 'Voir les secteurs'],
                ['id' => 'secteurs.write', 'nom' => 'Créer/modifier les secteurs'],
                ['id' => 'secteurs.delete', 'nom' => 'Supprimer les secteurs'],
            ],
            'employes' => [
                ['id' => 'employes.read', 'nom' => 'Voir les employés'],
                ['id' => 'employes.write', 'nom' => 'Créer/modifier les employés'],
                ['id' => 'employes.delete', 'nom' => 'Supprimer les employés'],
            ],
            'relocalisation' => [
                ['id' => 'relocalisation.read', 'nom' => 'Voir les relocalisations'],
                ['id' => 'relocalisation.write', 'nom' => 'Créer des relocalisations'],
            ],
            'approbations' => [
                ['id' => 'approbations.read', 'nom' => 'Voir les approbations'],
                ['id' => 'approbations.approve', 'nom' => 'Approuver/rejeter'],
            ],
            'rapports' => [
                ['id' => 'rapports.read', 'nom' => 'Voir les rapports'],
            ],
            'users' => [
                ['id' => 'users.read', 'nom' => 'Voir les utilisateurs'],
                ['id' => 'users.write', 'nom' => 'Gérer les utilisateurs'],
            ],
            'settings' => [
                ['id' => 'settings.write', 'nom' => 'Modifier les paramètres'],
            ],
            'fournisseurs' => [
                ['id' => 'fournisseurs.read', 'nom' => 'Voir les fournisseurs'],
                ['id' => 'fournisseurs.write', 'nom' => 'Créer/modifier les fournisseurs'],
                ['id' => 'fournisseurs.delete', 'nom' => 'Supprimer les fournisseurs'],
            ],
            'commandes_fournisseur' => [
                ['id' => 'commandes_fournisseur.read', 'nom' => 'Voir les commandes fournisseur'],
                ['id' => 'commandes_fournisseur.write', 'nom' => 'Créer/modifier les commandes'],
                ['id' => 'commandes_fournisseur.valider', 'nom' => 'Valider/envoyer les commandes'],
                ['id' => 'commandes_fournisseur.annuler', 'nom' => 'Annuler les commandes'],
            ],
            'receptions' => [
                ['id' => 'receptions.read', 'nom' => 'Voir les réceptions'],
                ['id' => 'receptions.write', 'nom' => 'Enregistrer les réceptions'],
            ],
        ];

        return response()->json($permissions);
    }

    /**
     * Vérifier si l'utilisateur a une permission
     */
    public function check(Request $request): JsonResponse
    {
        $user = $request->user();
        $permission = $request->get('permission');

        if (! $user || ! $permission) {
            return response()->json(['has_permission' => false]);
        }

        $role = $user->role ?? 'user';

        if ($role === 'admin') {
            return response()->json(['has_permission' => true]);
        }

        $hasPermission = DB::table('role_permissions')
            ->where('role', $role)
            ->where('permission', $permission)
            ->exists();

        return response()->json(['has_permission' => $hasPermission]);
    }

    /**
     * Permissions de l'utilisateur connecté
     */
    public function myPermissions(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['permissions' => []]);
        }

        $role = $user->role ?? 'user';

        if ($role === 'admin') {
            // Admin a toutes les permissions
            $permissions = DB::table('role_permissions')
                ->distinct()
                ->pluck('permission');
        } else {
            $permissions = DB::table('role_permissions')
                ->where('role', $role)
                ->pluck('permission');
        }

        return response()->json([
            'role' => $role,
            'permissions' => $permissions,
        ]);
    }
}
