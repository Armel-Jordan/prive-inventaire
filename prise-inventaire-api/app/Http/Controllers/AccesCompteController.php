<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Support\DelegationDroits;
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
 * exceptions, permission par permission (accordée en plus, ou refusée même si un groupe l'accorde).
 *
 * Droits : voir (roles.voir), régler (roles.acces) ; un compte qui n'est pas administrateur suit les garde-fous de
 * App\Support\DelegationDroits (ni ses propres accès, ni un administrateur, seulement des permissions qu'il a).
 */
class AccesCompteController extends Controller
{
    public function __construct(private Droits $droits, private DelegationDroits $delegation) {}

    public function show(Request $request, int $id): JsonResponse
    {
        // Le contrôleur (et son Droits) peut servir plusieurs requêtes : droits relus.
        $this->droits->oublier();

        return response()->json($this->presenter($this->compte($id), $request->user()));
    }

    /**
     * Groupes supplémentaires (rôles personnalisés actifs de l'entreprise) et exceptions : code => true (accordée),
     * false (refusée) ; un code absent suit les groupes.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $compte = $this->compte($id);
        $acteur = $request->user();
        $this->delegation->exigerCompte($acteur, $compte);
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

        $this->droits->oublier();
        $avant = $this->droits->effectives($compte);
        $anciensGroupes = DB::table('admin_user_groupes')->where('admin_user_id', $compte->id)->pluck('role_id')->map(fn ($g) => (int) $g)->all();
        $anciennes = $this->droits->exceptions($compte);

        DB::transaction(function () use ($compte, $donnees, $exceptions, $acteur, $avant, $anciensGroupes, $anciennes) {
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

            // Ce que l'acteur accorde : les permissions des groupes ajoutés, les exceptions accordées en plus, et ce
            // que le compte gagne au bout du compte (un refus retiré, par exemple).
            $this->droits->oublier();
            $accordees = DelegationDroits::gagnees($avant, $this->droits->effectives($compte));
            foreach (array_diff(array_map('intval', $donnees['groupes']), $anciensGroupes, [(int) $compte->role_custom_id]) as $roleId) {
                $accordees = array_merge($accordees, DelegationDroits::accordees($this->delegation->droitsDuGroupe($roleId)));
            }
            foreach ($exceptions as $code => $accorde) {
                if ($accorde && ! ($anciennes[$code] ?? false)) {
                    $accordees[] = $code;
                }
            }
            $this->delegation->exigerDetenues($acteur, $accordees);
        });

        $this->droits->oublier();

        return response()->json($this->presenter($compte->fresh(), $acteur) + ['message' => __('Accès enregistrés.')]);
    }

    private function compte(int $id): AdminUser
    {
        return AdminUser::where('tenant_id', app(TenantContext::class)->getTenantId())->findOrFail($id);
    }

    private function presenter(AdminUser $compte, AdminUser $acteur): array
    {
        $role = $this->droits->role($compte);
        // Le compte connecté peut-il régler ces accès (droit, ni les siens, ni ceux d'un administrateur) ?
        $modifiable = $this->delegation->sansLimite($acteur)
            || ($this->droits->permet($acteur, 'roles', 'acces') && (int) $acteur->id !== (int) $compte->id && $compte->role !== 'admin');

        return [
            'compte' => $compte->only(['id', 'nom', 'email', 'role', 'role_custom_id', 'actif']),
            // Un administrateur a tous les droits : groupes et exceptions ne changent rien pour lui.
            'administrateur' => $compte->role === 'admin',
            'modifiable' => $modifiable,
            'role_principal' => $role ? ['id' => $role->id, 'nom' => $role->nom, 'systeme' => (bool) $role->is_system] : null,
            'groupes' => DB::table('admin_user_groupes')->where('admin_user_id', $compte->id)->pluck('role_id')->map(fn ($id) => (int) $id)->values(),
            'exceptions' => (object) $this->droits->exceptions($compte),
            'droits_groupes' => (object) $this->droits->droitsDesGroupes($compte),
            'effectives' => (object) $this->droits->effectives($compte),
        ];
    }
}
