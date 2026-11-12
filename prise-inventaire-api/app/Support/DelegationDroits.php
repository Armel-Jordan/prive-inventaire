<?php

namespace App\Support;

use App\Models\AdminUser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Garde-fous de la délégation des groupes et des accès (actions « roles.groupes » et « roles.acces »), comme dans
 * fripv2. Un compte qui n'est pas administrateur :
 * - n'accorde que des permissions qu'il a lui-même : celles qu'un groupe gagne, que donne un groupe ou un rôle
 *   ajouté à un compte, qu'une exception accorde, ou que le compte gagne au bout du compte (un refus retiré) ;
 * - ne touche ni à ses propres accès, ni à ses groupes, ni à un compte administrateur ;
 * - peut toujours retirer ou refuser une permission : le refus l'emporte.
 * Un administrateur a tous les droits : rien ne le limite.
 */
class DelegationDroits
{
    public function __construct(private Droits $droits) {}

    public function sansLimite(AdminUser $acteur): bool
    {
        return $acteur->role === 'admin';
    }

    /** Refuse (403) de régler ses propres accès, ou ceux d'un administrateur. */
    public function exigerCompte(AdminUser $acteur, AdminUser $compte): void
    {
        if ($this->sansLimite($acteur)) {
            return;
        }
        if ((int) $compte->id === (int) $acteur->id) {
            $this->refuser(__('Vous ne pouvez pas modifier vos propres accès.'));
        }
        if ($compte->role === 'admin') {
            $this->refuser(__("Seul un administrateur peut modifier les accès d'un administrateur."));
        }
    }

    /** Refuse (403) de modifier l'un de ses propres groupes : son rôle personnalisé ou un groupe supplémentaire. */
    public function exigerGroupe(AdminUser $acteur, int $roleId): void
    {
        if ($this->sansLimite($acteur)) {
            return;
        }
        $siens = DB::table('admin_user_groupes')->where('admin_user_id', $acteur->id)->pluck('role_id')
            ->push($acteur->role_custom_id)->filter()->map(fn ($id) => (int) $id);
        if ($siens->contains($roleId)) {
            $this->refuser(__('Vous ne pouvez pas modifier un groupe dont vous faites partie.'));
        }
    }

    /** Permissions qu'un groupe donne à ses membres s'il est actif (droits de base et actions nommées). */
    public function droitsDuGroupe(int $roleId): array
    {
        return $this->droits->droitsDesRoles([$roleId]);
    }

    /** Codes accordés. */
    public static function accordees(array $droits): array
    {
        return array_keys(array_filter($droits));
    }

    /** Codes refusés avant et accordés après. */
    public static function gagnees(array $avant, array $apres): array
    {
        return array_keys(array_filter($apres, fn (bool $accorde, string $code) => $accorde && ! ($avant[$code] ?? false), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Refuse (403) si l'acteur n'a pas lui-même chacune de ces permissions. Seuls comptent les codes réglables dans
     * les écrans (voir Permissions::reglable()).
     *
     * @param  iterable<int, string>  $codes
     */
    public function exigerDetenues(AdminUser $acteur, iterable $codes): void
    {
        if ($this->sansLimite($acteur)) {
            return;
        }
        // Droits relus : une instance gardée d'une requête à l'autre (contrôleur mis en cache) ne doit pas servir.
        $this->droits->oublier();
        $siennes = $this->droits->effectives($acteur);
        $manquantes = collect($codes)->unique()
            ->filter(fn (string $code) => Permissions::reglable($code) && ! ($siennes[$code] ?? false))
            ->sort()->values();
        if ($manquantes->isEmpty()) {
            return;
        }
        $langue = app()->getLocale() === 'en' ? 'en' : 'fr';
        $liste = $manquantes->take(5)->map(fn (string $code) => Permissions::libelleCode($code, $langue))->implode(', ');
        if ($manquantes->count() > 5) {
            $liste .= ' '.__('(et :nombre autres)', ['nombre' => $manquantes->count() - 5]);
        }

        $this->refuser(__('Vous ne pouvez accorder que des permissions que vous avez vous-même : :permissions.', ['permissions' => $liste]),
            ['permissions' => $manquantes->all()]);
    }

    private function refuser(string $message, array $details = []): never
    {
        throw new HttpResponseException(response()->json(['message' => $message] + $details, 403));
    }
}
