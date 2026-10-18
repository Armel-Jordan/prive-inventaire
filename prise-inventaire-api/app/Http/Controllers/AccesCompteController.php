<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Support\Droits;
use App\Support\Permissions;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Accès d'un compte, sur le modèle de fripv2 : ses groupes supplémentaires (en plus de son rôle) et ses
 * exceptions, permission par permission (accordée en plus, ou refusée même si un groupe l'accorde). Réservé aux
 * administrateurs (routes).
 */
class AccesCompteController extends Controller
{
    public function __construct(private Droits $droits) {}

    public function show(int $id): JsonResponse
    {
        return response()->json($this->presenter($this->compte($id)));
    }

    /**
     * Groupes supplémentaires (rôles personnalisés actifs de l'entreprise) et exceptions : code => true (accordée),
     * false (refusée) ; un code absent suit les groupes.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $compte = $this->compte($id);
        $tenantId = app(TenantContext::class)->getTenantId();
        $donnees = $request->validate([
            'groupes' => ['present', 'array', 'max:50'],
            'groupes.*' => ['integer', 'distinct', Rule::exists('roles_custom', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenantId)->where('is_system', false))],
            'exceptions' => ['present', 'array'],
        ]);
        $exceptions = [];
        foreach ($donnees['exceptions'] as $code => $accorde) {
            if (! Permissions::existe((string) $code) || ! is_bool($accorde)) {
                throw ValidationException::withMessages(['exceptions' => __('Permission inconnue : :code.', ['code' => $code])]);
            }
            $exceptions[$code] = $accorde;
        }

        DB::transaction(function () use ($compte, $donnees, $exceptions) {
            DB::table('admin_user_groupes')->where('admin_user_id', $compte->id)->delete();
            foreach (array_unique($donnees['groupes']) as $roleId) {
                if ((int) $roleId === (int) $compte->role_custom_id) {
                    continue; // Déjà son rôle principal.
                }
                DB::table('admin_user_groupes')->insert(['admin_user_id' => $compte->id, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('admin_user_permissions')->where('admin_user_id', $compte->id)->delete();
            foreach ($exceptions as $code => $accorde) {
                DB::table('admin_user_permissions')->insert(['admin_user_id' => $compte->id, 'permission' => $code, 'accorde' => $accorde, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->droits->oublier();

        return response()->json($this->presenter($compte->fresh()) + ['message' => __('Accès enregistrés.')]);
    }

    private function compte(int $id): AdminUser
    {
        return AdminUser::where('tenant_id', app(TenantContext::class)->getTenantId())->findOrFail($id);
    }

    private function presenter(AdminUser $compte): array
    {
        $role = $this->droits->role($compte);

        return [
            'compte' => $compte->only(['id', 'nom', 'email', 'role', 'role_custom_id', 'actif']),
            // Un administrateur a tous les droits : groupes et exceptions ne changent rien pour lui.
            'administrateur' => $compte->role === 'admin',
            'role_principal' => $role ? ['id' => $role->id, 'nom' => $role->nom, 'systeme' => (bool) $role->is_system] : null,
            'groupes' => DB::table('admin_user_groupes')->where('admin_user_id', $compte->id)->pluck('role_id')->map(fn ($id) => (int) $id)->values(),
            'exceptions' => (object) $this->droits->exceptions($compte),
            'droits_groupes' => (object) $this->droits->droitsDesGroupes($compte),
            'effectives' => (object) $this->droits->effectives($compte),
        ];
    }
}
