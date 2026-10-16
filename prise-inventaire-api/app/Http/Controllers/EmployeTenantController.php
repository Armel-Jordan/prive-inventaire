<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Configuration;
use App\Models\EmployeTenant;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeTenantController extends Controller
{
    /** Champs du compte d'accès renvoyés avec un employé. */
    private const COMPTE = 'adminUser:id,role,role_custom_id,actif';

    /** Rôles système attribuables : « readonly » (lecture seule) l'est désormais aussi. */
    private const ROLES = 'user,manager,admin,readonly';

    public function index(Request $request): JsonResponse
    {
        $employes = EmployeTenant::with(self::COMPTE)
            ->where('tenant_id', auth()->user()->tenant_id)
            // ?actif=1 : listes de choix (scans, mouvements) sans les employés désactivés.
            ->when($request->has('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->orderBy('nom')
            ->get();

        return response()->json($employes);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'numero' => ['nullable', 'string', 'max:20', TenantRule::unique('employes', 'numero')],
            'nom' => 'required|string|max:100',
            'prenom' => 'nullable|string|max:100',
            // Le courriel est l'identifiant de connexion : obligatoire pour donner l'accès (sinon erreur 500).
            'email' => 'required_with:role|nullable|email|max:100',
            'role' => 'nullable|in:'.self::ROLES,
            'role_custom_id' => $this->regleRolePersonnalise(),
            'password' => 'required_with:role|nullable|string|min:6',
        ], ['email.required_with' => 'Un courriel est nécessaire pour donner l\'accès à l\'application.']);

        $tenantId = auth()->user()->tenant_id;

        // Générer le numéro automatiquement si non fourni
        $numero = $request->numero;
        if (empty($numero)) {
            $config = Configuration::pourEntite('employe', $request->attributes->get('tenant')->id);
            if ($config && $config->auto_increment) {
                $numero = $config->genererNumero();
                $config->incrementer();
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Le numéro est requis (pas de configuration auto-increment)',
                ], 422);
            }
        }

        // Créer ou lier un AdminUser si un rôle est fourni
        $adminUserId = null;
        if ($request->filled('role') && $request->role !== 'remove') {
            $existing = $request->filled('email')
                ? AdminUser::where('email', $request->email)->where('tenant_id', $tenantId)->first()
                : null;
            $this->autoriserAcces($request, $existing, $request->role);
            $this->autoriserRolePersonnalise($request, $existing);

            if ($existing) {
                // Lier au compte existant
                $existing->update(['role' => $request->role, 'role_custom_id' => $request->input('role_custom_id')]);
                $adminUserId = $existing->id;
            } else {
                if (! $request->filled('password')) {
                    return response()->json(['success' => false, 'message' => 'Le mot de passe est requis'], 422);
                }
                $adminUser = AdminUser::create([
                    'tenant_id' => $tenantId,
                    'nom' => trim($request->nom.' '.($request->prenom ?? '')),
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'role' => $request->role,
                    'role_custom_id' => $request->input('role_custom_id'),
                    'actif' => true,
                ]);
                $adminUserId = $adminUser->id;
            }
        }

        $employe = EmployeTenant::create([
            'tenant_id' => $tenantId,
            'admin_user_id' => $adminUserId,
            'numero' => $numero,
            'nom' => $request->nom,
            'prenom' => $request->prenom,
            'email' => $request->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employé créé avec succès',
            'employe' => $employe->load(self::COMPTE),
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $employe = EmployeTenant::with(self::COMPTE)->findOrFail($id);

        return response()->json($employe);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $employe = EmployeTenant::findOrFail($id);

        $request->validate([
            'numero' => ['sometimes', 'string', 'max:20', TenantRule::unique('employes', 'numero', $id)],
            'nom' => 'sometimes|string|max:100',
            'prenom' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:100',
            'actif' => 'sometimes|boolean',
            'role' => 'nullable|in:'.self::ROLES.',remove',
            'role_custom_id' => $this->regleRolePersonnalise(),
            'password' => 'nullable|string|min:6',
        ]);

        // Désactivation ou réactivation : elle s'applique aussi au compte d'accès (voir destroy).
        if ($request->has('actif') && $request->boolean('actif') !== (bool) $employe->actif) {
            $this->changerStatut($employe, $request->boolean('actif'));
        }

        $employe->fill($request->only(['numero', 'nom', 'prenom', 'email']));

        // Gérer l'accès application
        if ($request->has('role')) {
            if ($request->role === 'remove') {
                // Supprimer l'accès
                if ($employe->adminUser) {
                    $this->autoriserAcces($request, $employe->adminUser, 'remove');
                    // Sessions ouvertes fermées avec l'accès.
                    $employe->adminUser->tokens()->delete();
                    $employe->adminUser->delete();
                    $employe->admin_user_id = null;
                }
            } elseif ($request->filled('role')) {
                if ($employe->adminUser) {
                    // Le formulaire renvoie toujours le rôle : seuls un changement de rôle, de mot de passe ou
                    // d'adresse (qui sert à réinitialiser le mot de passe) touchent à l'accès.
                    $compte = $employe->adminUser;
                    if ($request->role !== $compte->role || $request->filled('password')
                        || ($request->filled('email') && $request->email !== $compte->email)) {
                        $this->autoriserAcces($request, $compte, $request->role);
                    }
                    if ($request->has('role_custom_id') && (int) $request->input('role_custom_id') !== (int) $compte->role_custom_id) {
                        $this->autoriserRolePersonnalise($request, $compte);
                    }
                    // Mettre à jour le rôle existant
                    $employe->adminUser->update([
                        'role' => $request->role,
                        'role_custom_id' => $request->has('role_custom_id') ? $request->input('role_custom_id') : $compte->role_custom_id,
                        'email' => $request->email ?? $employe->adminUser->email,
                        'nom' => trim($request->nom.' '.($request->prenom ?? $employe->prenom ?? '')),
                    ]);
                    if ($request->filled('password')) {
                        $employe->adminUser->update(['password' => Hash::make($request->password)]);
                    }
                } else {
                    // Chercher un AdminUser existant avec cet email
                    $email = $request->email ?? $employe->email;
                    if (! $email) {
                        throw ValidationException::withMessages(['email' => 'Un courriel est nécessaire pour donner l\'accès à l\'application.']);
                    }
                    $existing = AdminUser::where('email', $email)->where('tenant_id', $employe->tenant_id)->first();
                    $this->autoriserAcces($request, $existing, $request->role);
                    $this->autoriserRolePersonnalise($request, $existing);

                    if ($existing) {
                        $existing->update(['role' => $request->role, 'role_custom_id' => $request->input('role_custom_id')]);
                        $employe->admin_user_id = $existing->id;
                    } else {
                        $adminUser = AdminUser::create([
                            'tenant_id' => $employe->tenant_id,
                            'nom' => trim(($request->nom ?? $employe->nom).' '.($request->prenom ?? $employe->prenom ?? '')),
                            'email' => $email,
                            'password' => Hash::make($request->password ?? str()->random(12)),
                            'role' => $request->role,
                            'role_custom_id' => $request->input('role_custom_id'),
                            'actif' => (bool) $employe->actif,
                        ]);
                        $employe->admin_user_id = $adminUser->id;
                    }
                }
            }
        }

        $employe->save();

        return response()->json([
            'success' => true,
            'message' => 'Employé modifié avec succès',
            'employe' => $employe->load(self::COMPTE),
        ]);
    }

    /** Rôle personnalisé : l'un de ceux de l'entreprise, ou aucun (droits du rôle système). */
    private function regleRolePersonnalise(): array
    {
        return ['nullable', 'integer', Rule::exists('roles_custom', 'id')
            ->where(fn ($q) => $q->where('tenant_id', auth()->user()->tenant_id)->where('is_system', false))];
    }

    /** Seul un administrateur attribue un rôle personnalisé, qui peut accorder n'importe quel droit. */
    private function autoriserRolePersonnalise(Request $request, ?AdminUser $compte): void
    {
        $demande = $request->input('role_custom_id');
        if ($request->user()->role !== 'admin' && $demande !== null && (int) $demande !== (int) $compte?->role_custom_id) {
            abort(403, 'Seul un administrateur peut attribuer un rôle personnalisé.');
        }
    }

    /**
     * Désactive ou réactive l'employé et son compte d'accès. Désactivé, le compte ne se connecte plus et ses
     * sessions ouvertes sont fermées : seul l'employé était marqué inactif, son accès restait entier.
     */
    private function changerStatut(EmployeTenant $employe, bool $actif): void
    {
        $compte = $employe->adminUser;
        abort_if(! $actif && $compte?->estDernierAdministrateur(), 422, "L'entreprise doit garder au moins un administrateur actif.");

        DB::transaction(function () use ($employe, $compte, $actif) {
            $employe->actif = $actif;
            $employe->save();
            if ($compte) {
                $compte->update(['actif' => $actif]);
                if (! $actif) {
                    $compte->tokens()->delete();
                }
            }
        });
    }

    /**
     * Accès à l'application : un administrateur attribue tous les rôles ; un manager n'attribue que le
     * rôle « user » et ne touche pas aux comptes administrateur ou manager (ni rôle, ni mot de passe).
     * Le dernier administrateur actif de l'entreprise ne peut être ni rétrogradé ni privé d'accès.
     */
    private function autoriserAcces(Request $request, ?AdminUser $compte, string $role): void
    {
        if ($request->user()->role !== 'admin') {
            abort_unless(in_array($role, ['user', 'readonly', 'remove'], true), 403, 'Seul un administrateur peut attribuer ce rôle.');
            abort_if($compte && ! in_array($compte->role, ['user', 'readonly'], true), 403, "Seul un administrateur peut modifier l'accès d'un administrateur ou d'un manager.");
        }

        abort_if($role !== 'admin' && $compte?->estDernierAdministrateur(), 422, "L'entreprise doit garder au moins un administrateur actif.");
    }

    public function destroy($id): JsonResponse
    {
        $employe = EmployeTenant::findOrFail($id);
        $this->changerStatut($employe, false);

        return response()->json([
            'success' => true,
            'message' => 'Employé désactivé avec succès',
        ]);
    }

    public function uploadPhoto(Request $request, $id): JsonResponse
    {
        $employe = EmployeTenant::findOrFail($id);

        $request->validate([
            'photo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($employe->photo && Storage::disk('public')->exists($employe->photo)) {
            Storage::disk('public')->delete($employe->photo);
        }

        $path = $request->file('photo')->store('photos/employes', 'public');
        $employe->photo = $path;
        $employe->save();

        return response()->json([
            'success' => true,
            'photo_url' => Storage::url($path),
        ]);
    }
}
