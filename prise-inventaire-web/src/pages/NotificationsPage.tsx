import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Bell, Check, ExternalLink, Trash2 } from 'lucide-react';
import { apiFetch } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { couleursTypeNotification, type NotificationApp } from '@/lib/notifications';

/**
 * Toutes les notifications de l'entreprise (la cloche n'en montre que dix). Le lien « Voir toutes les
 * notifications » de la cloche menait à une page qui n'existait pas.
 */
export default function NotificationsPage() {
  const { t, language } = useLanguage();
  const tn = t.notifications;
  const navigate = useNavigate();
  const [notifications, setNotifications] = useState<NotificationApp[]>([]);
  const [nonLuesSeulement, setNonLuesSeulement] = useState(false);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState('');

  const charger = useCallback(async () => {
    setChargement(true);
    setErreur('');
    try {
      setNotifications(await apiFetch<NotificationApp[]>(`/notifications?limit=100${nonLuesSeulement ? '&non_lues=1' : ''}`));
    } catch {
      setErreur(tn.error);
    } finally {
      setChargement(false);
    }
  }, [nonLuesSeulement, tn.error]);

  useEffect(() => {
    charger();
  }, [charger]);

  async function marquerLue(id: number) {
    await apiFetch(`/notifications/${id}/read`, { method: 'POST', responseType: 'void' }).catch(() => {});
    setNotifications(liste => liste.map(n => (n.id === id ? { ...n, lu: true } : n)));
  }

  async function toutMarquerLu() {
    await apiFetch('/notifications/read-all', { method: 'POST', responseType: 'void' }).catch(() => {});
    charger();
  }

  async function supprimer(id: number) {
    await apiFetch(`/notifications/${id}`, { method: 'DELETE', responseType: 'void' }).catch(() => {});
    setNotifications(liste => liste.filter(n => n.id !== id));
  }

  function ouvrir(notification: NotificationApp) {
    if (!notification.lu) marquerLue(notification.id);
    if (notification.lien) navigate(notification.lien);
  }

  const dateHeure = (valeur: string) =>
    new Date(valeur).toLocaleString(language === 'en' ? 'en-US' : 'fr-FR', { dateStyle: 'short', timeStyle: 'short' });

  return (
    <div className="p-6 max-w-4xl">
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white">{tn.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400">{tn.subtitle}</p>
        </div>
        <div className="flex items-center gap-3">
          <label className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300 cursor-pointer">
            <input type="checkbox" checked={nonLuesSeulement} onChange={e => setNonLuesSeulement(e.target.checked)} className="rounded" />
            {tn.unreadOnly}
          </label>
          <button
            type="button"
            onClick={toutMarquerLu}
            className="flex items-center gap-1 px-3 py-2 text-sm border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700"
          >
            <Check size={16} /> {tn.markAllRead}
          </button>
        </div>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md divide-y divide-gray-100 dark:divide-gray-700">
        {chargement ? (
          <p className="p-6 text-center text-gray-500">{tn.loading}</p>
        ) : erreur ? (
          <p className="p-6 text-center text-red-600">{erreur}</p>
        ) : notifications.length === 0 ? (
          <div className="p-8 text-center text-gray-400">
            <Bell className="mx-auto mb-2 opacity-50" size={28} />
            <p>{tn.empty}</p>
          </div>
        ) : (
          notifications.map(notification => (
            <div key={notification.id} className={`flex items-start gap-3 p-4 ${notification.lu ? '' : 'bg-blue-50 dark:bg-blue-900/20'}`}>
              <span className={`w-2.5 h-2.5 rounded-full mt-1.5 shrink-0 ${couleursTypeNotification[notification.type] || 'bg-gray-400'}`} />
              <div className="flex-1 min-w-0">
                <p className={`text-sm text-gray-900 dark:text-white ${notification.lu ? '' : 'font-semibold'}`}>{notification.titre}</p>
                <p className="text-sm text-gray-600 dark:text-gray-300">{notification.message}</p>
                <p className="text-xs text-gray-400 mt-1">{dateHeure(notification.created_at)}</p>
              </div>
              <div className="flex gap-1 shrink-0">
                {!notification.lu && (
                  <button type="button" onClick={() => marquerLue(notification.id)} title={tn.markRead} aria-label={tn.markRead}
                    className="p-1.5 text-gray-400 hover:text-green-600 rounded">
                    <Check size={16} />
                  </button>
                )}
                {notification.lien && (
                  <button type="button" onClick={() => ouvrir(notification)} title={tn.open} aria-label={tn.open}
                    className="p-1.5 text-gray-400 hover:text-blue-600 rounded">
                    <ExternalLink size={16} />
                  </button>
                )}
                <button type="button" onClick={() => supprimer(notification.id)} title={tn.delete} aria-label={tn.delete}
                  className="p-1.5 text-gray-400 hover:text-red-600 rounded">
                  <Trash2 size={16} />
                </button>
              </div>
            </div>
          ))
        )}
      </div>
    </div>
  );
}
