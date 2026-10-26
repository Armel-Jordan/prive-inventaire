<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationsCompte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Notifications du compte connecté (anomalie A4) : celles de l'entreprise et celles qui le visent, selon ses droits ;
 * `lu` et la suppression valent pour lui seul (voir NotificationsCompte).
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationsCompte $notifications) {}

    /**
     * Liste des notifications
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->boolean('non_lues')
            ? $this->notifications->nonLues($request->user())
            : $this->notifications->requete($request->user());

        if ($request->filled('type')) {
            $query->where('notifications.type', $request->type);
        }

        $limit = min((int) $request->get('limit', 50), 100);

        return response()->json($this->presenter($query->orderByDesc('notifications.created_at')->limit($limit)->get()));
    }

    /**
     * Nombre de notifications non lues
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->notifications->nonLues($request->user())->count()]);
    }

    /**
     * Marquer une notification comme lue (pour ce compte)
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $notification = $this->visible($request, $id);
        $this->notifications->marquerLue($notification, $request->user());

        return response()->json([
            'success' => true,
            'notification' => $notification->setAttribute('lu', true),
        ]);
    }

    /**
     * Marquer toutes les notifications comme lues (pour ce compte)
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $this->notifications->toutMarquer($request->user());

        return response()->json([
            'success' => true,
            'message' => __('Toutes les notifications ont été marquées comme lues'),
        ]);
    }

    /**
     * Retirer une notification (de la cloche de ce compte seulement)
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->notifications->masquer($this->visible($request, $id), $request->user());

        return response()->json([
            'success' => true,
            'message' => __('Notification supprimée'),
        ]);
    }

    /**
     * Supprimer les notifications lues de plus de 7 jours
     */
    public function cleanup(): JsonResponse
    {
        $deleted = Notification::where('lu', true)
            ->where('created_at', '<', now()->subDays(7))
            ->delete();

        return response()->json([
            'success' => true,
            'deleted' => $deleted,
        ]);
    }

    /** Notification que le compte peut voir (404 sinon : une autre entreprise, un autre compte, un module refusé). */
    private function visible(Request $request, int $id): Notification
    {
        return $this->notifications->requete($request->user())->where('notifications.id', $id)->firstOrFail();
    }

    /** `lu` du compte à la place de celui de l'entreprise. */
    private function presenter(Collection $notifications): Collection
    {
        return $notifications->map(function (Notification $notification) {
            $notification->setAttribute('lu', (bool) $notification->getAttribute('lu_par_moi'));
            $notification->offsetUnset('lu_par_moi');

            return $notification;
        });
    }
}
