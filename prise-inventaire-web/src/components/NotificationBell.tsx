import { useEffect, useState } from 'react';
import { Bell, Check, Trash2, ExternalLink } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const STORAGE_KEY = 'prise_auth';

function getAuthHeaders(): Record<string, string> {
  const headers: Record<string, string> = { 'Accept': 'application/json' };
  const stored = localStorage.getItem(STORAGE_KEY);
  if (stored) {
    try {
      const data = JSON.parse(stored);
      if (data.token) headers['Authorization'] = `Bearer ${data.token}`;
      if (data.tenant?.slug) headers['X-Tenant-Slug'] = data.tenant.slug;
    } catch { /* ignore */ }
  }
  return headers;
}

interface Notification {
  id: number;
  type: string;
  titre: string;
  message: string;
  lu: boolean;
  lien: string | null;
  created_at: string;
}

const typeColors: Record<string, string> = {
  transfert_complete: 'bg-green-500',
  approbation_requise: 'bg-orange-500',
  alerte_stock: 'bg-red-500',
  planification_imminente: 'bg-blue-500',
};

export default function NotificationBell() {
  const navigate = useNavigate();
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [showDropdown, setShowDropdown] = useState(false);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    loadUnreadCount();
    const interval = setInterval(loadUnreadCount, 30000); // Refresh every 30s
    return () => clearInterval(interval);
  }, []);

  async function loadUnreadCount() {
    try {
      const response = await fetch(`${API_BASE_URL}/notifications/unread-count`, {
        headers: getAuthHeaders(),
      });
      if (response.ok) {
        const data = await response.json();
        setUnreadCount(data.count);
      }
    } catch (error) {
      console.error('Erreur chargement notifications:', error);
    }
  }

  async function loadNotifications() {
    setLoading(true);
    try {
      const response = await fetch(`${API_BASE_URL}/notifications?limit=10`, {
        headers: getAuthHeaders(),
      });
      if (response.ok) {
        setNotifications(await response.json());
      }
    } catch (error) {
      console.error('Erreur:', error);
    } finally {
      setLoading(false);
    }
  }

  function toggleDropdown() {
    if (!showDropdown) {
      loadNotifications();
    }
    setShowDropdown(!showDropdown);
  }

  async function markAsRead(id: number) {
    try {
      await fetch(`${API_BASE_URL}/notifications/${id}/read`, {
        method: 'POST',
        headers: getAuthHeaders(),
      });
      setNotifications(notifications.map(n => 
        n.id === id ? { ...n, lu: true } : n
      ));
      setUnreadCount(Math.max(0, unreadCount - 1));
    } catch (error) {
      console.error('Erreur:', error);
    }
  }

  async function markAllAsRead() {
    try {
      await fetch(`${API_BASE_URL}/notifications/read-all`, {
        method: 'POST',
        headers: getAuthHeaders(),
      });
      setNotifications(notifications.map(n => ({ ...n, lu: true })));
      setUnreadCount(0);
    } catch (error) {
      console.error('Erreur:', error);
    }
  }

  async function deleteNotification(id: number) {
    try {
      await fetch(`${API_BASE_URL}/notifications/${id}`, {
        method: 'DELETE',
        headers: getAuthHeaders(),
      });
      const notif = notifications.find(n => n.id === id);
      setNotifications(notifications.filter(n => n.id !== id));
      if (notif && !notif.lu) {
        setUnreadCount(Math.max(0, unreadCount - 1));
      }
    } catch (error) {
      console.error('Erreur:', error);
    }
  }

  function handleNotificationClick(notif: Notification) {
    if (!notif.lu) {
      markAsRead(notif.id);
    }
    if (notif.lien) {
      navigate(notif.lien);
      setShowDropdown(false);
    }
  }

  return (
    <div className="relative">
      <button
        onClick={toggleDropdown}
        className="relative p-2 text-gray-600 hover:bg-gray-100 rounded-lg"
      >
        <Bell size={20} />
        {unreadCount > 0 && (
          <span className="absolute -top-1 -right-1 bg-red-500 text-white text-xs w-5 h-5 flex items-center justify-center rounded-full">
            {unreadCount > 9 ? '9+' : unreadCount}
          </span>
        )}
      </button>

      {showDropdown && (
        <>
          <div 
            className="fixed inset-0 z-40" 
            onClick={() => setShowDropdown(false)} 
          />
          <div className="absolute right-0 top-full mt-2 w-80 bg-white rounded-xl shadow-xl border z-50 max-h-96 overflow-hidden">
            <div className="flex items-center justify-between p-3 border-b">
              <h3 className="font-semibold text-gray-800">Notifications</h3>
              {unreadCount > 0 && (
                <button
                  onClick={markAllAsRead}
                  className="text-xs text-blue-600 hover:text-blue-700 flex items-center gap-1"
                >
                  <Check size={12} />
                  Tout marquer lu
                </button>
              )}
            </div>

            <div className="overflow-y-auto max-h-72">
              {loading ? (
                <div className="p-4 text-center text-gray-500 text-sm">Chargement...</div>
              ) : notifications.length === 0 ? (
                <div className="p-6 text-center text-gray-400">
                  <Bell className="mx-auto mb-2 opacity-50" size={24} />
                  <p className="text-sm">Aucune notification</p>
                </div>
              ) : (
                notifications.map((notif) => (
                  <div
                    key={notif.id}
                    className={`p-3 border-b hover:bg-gray-50 cursor-pointer ${!notif.lu ? 'bg-blue-50' : ''}`}
                  >
                    <div className="flex items-start gap-3">
                      <div className={`w-2 h-2 rounded-full mt-2 ${typeColors[notif.type] || 'bg-gray-400'}`} />
                      <div className="flex-1 min-w-0" onClick={() => handleNotificationClick(notif)}>
                        <p className={`text-sm ${!notif.lu ? 'font-medium' : ''}`}>{notif.titre}</p>
                        <p className="text-xs text-gray-500 truncate">{notif.message}</p>
                        <p className="text-xs text-gray-400 mt-1">
                          {new Date(notif.created_at).toLocaleString('fr-FR', {
                            day: '2-digit',
                            month: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit',
                          })}
                        </p>
                      </div>
                      <div className="flex gap-1">
                        {notif.lien && (
                          <button
                            onClick={() => handleNotificationClick(notif)}
                            className="p-1 text-gray-400 hover:text-blue-600 rounded"
                          >
                            <ExternalLink size={14} />
                          </button>
                        )}
                        <button
                          onClick={() => deleteNotification(notif.id)}
                          className="p-1 text-gray-400 hover:text-red-600 rounded"
                        >
                          <Trash2 size={14} />
                        </button>
                      </div>
                    </div>
                  </div>
                ))
              )}
            </div>

            {notifications.length > 0 && (
              <div className="p-2 border-t">
                <button
                  onClick={() => {
                    navigate('/notifications');
                    setShowDropdown(false);
                  }}
                  className="w-full text-center text-sm text-blue-600 hover:text-blue-700 py-1"
                >
                  Voir toutes les notifications
                </button>
              </div>
            )}
          </div>
        </>
      )}
    </div>
  );
}
