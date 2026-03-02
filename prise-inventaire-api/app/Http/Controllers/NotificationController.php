<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Liste des notifications
     */
    public function index(Request $request): JsonResponse
    {
        $query = Notification::query()->orderByDesc('created_at');

        if ($request->has('non_lues') && $request->non_lues) {
            $query->where('lu', false);
        }

        if ($request->has('type') && $request->type) {
            $query->where('type', $request->type);
        }

        $limit = min($request->get('limit', 50), 100);
        $notifications = $query->limit($limit)->get();

        return response()->json($notifications);
    }

    /**
     * Nombre de notifications non lues
     */
    public function unreadCount(): JsonResponse
    {
        $count = Notification::where('lu', false)->count();
        return response()->json(['count' => $count]);
    }

    /**
     * Marquer une notification comme lue
     */
    public function markAsRead(int $id): JsonResponse
    {
        $notification = Notification::findOrFail($id);
        $notification->lu = true;
        $notification->save();

        return response()->json([
            'success' => true,
            'notification' => $notification,
        ]);
    }

    /**
     * Marquer toutes les notifications comme lues
     */
    public function markAllAsRead(): JsonResponse
    {
        Notification::where('lu', false)->update(['lu' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Toutes les notifications ont été marquées comme lues',
        ]);
    }

    /**
     * Supprimer une notification
     */
    public function destroy(int $id): JsonResponse
    {
        $notification = Notification::findOrFail($id);
        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification supprimée',
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
}
