<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Notification;
use App\Models\NotificationLecture;
use App\Support\Droits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Notifications vues par un compte (anomalie A4) : celles de toute l'entreprise et celles qui le visent, sauf celles
 * qu'il a masquées et celles d'un module qu'il ne peut pas voir (Notification::DROITS) ; lues ou non pour lui seul.
 * Une notification lue par au moins un compte garde `lu` (alertes non répétées, nettoyage) ; une notification déjà
 * lue avant les lectures par compte (aucune lecture enregistrée) compte comme lue pour tous.
 */
class NotificationsCompte
{
    public function __construct(private readonly Droits $droits) {}

    /** Notifications visibles par le compte, avec `lu_par_moi` (1 ou 0). */
    public function requete(AdminUser $compte): Builder
    {
        $types = array_keys(Notification::DROITS);
        $permis = array_values(array_filter($types, fn (string $type) => $this->droits->permet($compte, Notification::DROITS[$type], 'voir')));

        return Notification::query()
            ->select('notifications.*')
            ->selectRaw('CASE WHEN '.$this->conditionLue().' THEN 1 ELSE 0 END AS lu_par_moi', [$compte->id])
            ->where(fn (Builder $q) => $q->whereNull('notifications.admin_user_id')->orWhere('notifications.admin_user_id', $compte->id))
            ->where(fn (Builder $q) => $q->whereNotIn('notifications.type', $types)->orWhereIn('notifications.type', $permis))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('notification_lectures')
                ->whereColumn('notification_lectures.notification_id', 'notifications.id')
                ->where('notification_lectures.admin_user_id', $compte->id)
                ->where('notification_lectures.masquee', true));
    }

    /** Notifications visibles et non lues par le compte. */
    public function nonLues(AdminUser $compte): Builder
    {
        return $this->requete($compte)->whereRaw('NOT ('.$this->conditionLue().')', [$compte->id]);
    }

    public function marquerLue(Notification $notification, AdminUser $compte): void
    {
        $this->lecture($notification, $compte, []);
    }

    /** Toutes les notifications visibles et non lues du compte : lues pour lui. Renvoie leur nombre. */
    public function toutMarquer(AdminUser $compte): int
    {
        $notifications = $this->nonLues($compte)->get();
        foreach ($notifications as $notification) {
            $this->lecture($notification, $compte, []);
        }

        return $notifications->count();
    }

    /** Retire la notification de la cloche de ce compte seulement (elle compte aussi comme lue). */
    public function masquer(Notification $notification, AdminUser $compte): void
    {
        $this->lecture($notification, $compte, ['masquee' => true]);
    }

    private function lecture(Notification $notification, AdminUser $compte, array $valeurs): void
    {
        NotificationLecture::updateOrCreate(
            ['notification_id' => $notification->id, 'admin_user_id' => $compte->id],
            $valeurs + ['lu_le' => now()],
        );
        if (! $notification->lu) {
            $notification->forceFill(['lu' => true])->save();
        }
    }

    /** Lue par ce compte, ou lue avant les lectures par compte (`lu` sans aucune lecture enregistrée). Paramètre : l'id du compte. */
    private function conditionLue(): string
    {
        return '(EXISTS (SELECT 1 FROM notification_lectures nl WHERE nl.notification_id = notifications.id AND nl.admin_user_id = ?)'
            .' OR (notifications.lu = 1 AND NOT EXISTS (SELECT 1 FROM notification_lectures nl2 WHERE nl2.notification_id = notifications.id)))';
    }
}
