import { useEffect, useState, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Package, Users, MapPin, ClipboardList, ArrowRightLeft, Truck, RefreshCw, Bell, AlertTriangle, Calendar } from 'lucide-react';
import { getScans, getProduits, getSecteurs, getEmployes } from '@/services/api';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const STORAGE_KEY = 'prise_auth';
const REFRESH_INTERVAL = 30000; // 30 secondes

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

interface Stats {
  scans: number;
  produits: number;
  secteurs: number;
  employes: number;
}

interface RelocStats {
  total: number;
  today: number;
  by_type: Record<string, number>;
}

interface AlerteInfo {
  count: number;
}

interface NotificationInfo {
  count: number;
}

interface PlanificationInfo {
  upcoming: number;
}

interface ApprobationInfo {
  en_attente: number;
}

export default function Dashboard() {
  const navigate = useNavigate();
  const [stats, setStats] = useState<Stats>({ scans: 0, produits: 0, secteurs: 0, employes: 0 });
  const [relocStats, setRelocStats] = useState<RelocStats>({ total: 0, today: 0, by_type: {} });
  const [alertes, setAlertes] = useState<AlerteInfo>({ count: 0 });
  const [notifications, setNotifications] = useState<NotificationInfo>({ count: 0 });
  const [planifications, setPlanifications] = useState<PlanificationInfo>({ upcoming: 0 });
  const [approbations, setApprobations] = useState<ApprobationInfo>({ en_attente: 0 });
  const [loading, setLoading] = useState(true);
  const [lastUpdate, setLastUpdate] = useState<Date>(new Date());
  const [autoRefresh, setAutoRefresh] = useState(true);

  const loadStats = useCallback(async () => {
    try {
      const [scans, produits, secteurs, employes] = await Promise.all([
        getScans(),
        getProduits(),
        getSecteurs(),
        getEmployes(),
      ]);
      setStats({
        scans: scans.length,
        produits: produits.length,
        secteurs: secteurs.length,
        employes: employes.length,
      });

      // Charger stats relocalisation
      const relocRes = await fetch(`${API_BASE_URL}/relocalisation/stats`, {
        headers: getAuthHeaders(),
      });
      if (relocRes.ok) {
        setRelocStats(await relocRes.json());
      }

      // Charger alertes stock
      const alertesRes = await fetch(`${API_BASE_URL}/alertes/stats`, {
        headers: getAuthHeaders(),
      });
      if (alertesRes.ok) {
        const data = await alertesRes.json();
        setAlertes({ count: data.en_alerte || 0 });
      }

      // Charger notifications non lues
      const notifRes = await fetch(`${API_BASE_URL}/notifications/unread-count`, {
        headers: getAuthHeaders(),
      });
      if (notifRes.ok) {
        const data = await notifRes.json();
        setNotifications({ count: data.count || 0 });
      }

      // Charger transferts planifiés à venir
      const planifRes = await fetch(`${API_BASE_URL}/transferts-planifies/stats`, {
        headers: getAuthHeaders(),
      });
      if (planifRes.ok) {
        const data = await planifRes.json();
        setPlanifications({ upcoming: data.a_venir_7_jours || 0 });
      }

      // Charger approbations en attente
      const approbRes = await fetch(`${API_BASE_URL}/approbations/stats`, {
        headers: getAuthHeaders(),
      });
      if (approbRes.ok) {
        const data = await approbRes.json();
        setApprobations({ en_attente: data.en_attente || 0 });
      }

      setLastUpdate(new Date());
    } catch (error) {
      console.error('Erreur chargement stats:', error);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadStats();
  }, [loadStats]);

  // Auto-refresh
  useEffect(() => {
    if (!autoRefresh) return;
    const interval = setInterval(loadStats, REFRESH_INTERVAL);
    return () => clearInterval(interval);
  }, [autoRefresh, loadStats]);

  const cards = [
    { label: 'Scans', value: stats.scans, icon: ClipboardList, color: 'bg-blue-500', path: '/scans' },
    { label: 'Produits', value: stats.produits, icon: Package, color: 'bg-green-500', path: '/produits' },
    { label: 'Secteurs', value: stats.secteurs, icon: MapPin, color: 'bg-purple-500', path: '/secteurs' },
    { label: 'Employés', value: stats.employes, icon: Users, color: 'bg-orange-500', path: '/employes' },
  ];

  const relocCards = [
    { label: 'Mouvements', value: relocStats.total, icon: ArrowRightLeft, color: 'bg-indigo-500', path: '/relocalisation' },
    { label: 'Arrivages', value: relocStats.by_type?.arrivage || 0, icon: Truck, color: 'bg-emerald-500', path: '/relocalisation?type=arrivage' },
    { label: 'Transferts', value: relocStats.by_type?.transfert || 0, icon: ArrowRightLeft, color: 'bg-sky-500', path: '/relocalisation?type=transfert' },
    { label: "Aujourd'hui", value: relocStats.today, icon: ClipboardList, color: 'bg-amber-500', path: '/relocalisation' },
  ];

  const actionCards = [
    { label: 'Alertes Stock', value: alertes.count, icon: AlertTriangle, color: alertes.count > 0 ? 'bg-red-500' : 'bg-gray-400', path: '/alertes', highlight: alertes.count > 0 },
    { label: 'Notifications', value: notifications.count, icon: Bell, color: notifications.count > 0 ? 'bg-blue-500' : 'bg-gray-400', path: '/notifications', highlight: notifications.count > 0 },
    { label: 'Planifiés (7j)', value: planifications.upcoming, icon: Calendar, color: 'bg-purple-500', path: '/planification' },
    { label: 'Approbations', value: approbations.en_attente, icon: RefreshCw, color: approbations.en_attente > 0 ? 'bg-orange-500' : 'bg-gray-400', path: '/approbations', highlight: approbations.en_attente > 0 },
  ];

  return (
    <div>
      {/* Header avec refresh */}
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-gray-800">Tableau de bord</h1>
        <div className="flex items-center gap-4">
          <span className="text-sm text-gray-400">
            Mis à jour: {lastUpdate.toLocaleTimeString('fr-FR')}
          </span>
          <button
            onClick={() => setAutoRefresh(!autoRefresh)}
            className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm ${
              autoRefresh ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'
            }`}
          >
            <RefreshCw size={14} className={autoRefresh ? 'animate-spin' : ''} style={{ animationDuration: '3s' }} />
            {autoRefresh ? 'Auto' : 'Manuel'}
          </button>
          <button
            onClick={loadStats}
            className="p-2 bg-blue-100 text-blue-600 rounded-lg hover:bg-blue-200"
          >
            <RefreshCw size={18} />
          </button>
        </div>
      </div>
      
      {loading ? (
        <div className="text-gray-500">Chargement...</div>
      ) : (
        <>
          {/* Actions requises */}
          {(alertes.count > 0 || approbations.en_attente > 0 || notifications.count > 0) && (
            <>
              <h2 className="text-lg font-semibold text-gray-700 mb-4 flex items-center gap-2">
                <AlertTriangle className="text-orange-500" size={20} />
                Actions requises
              </h2>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                {actionCards.filter(c => c.highlight).map((card) => {
                  const Icon = card.icon;
                  return (
                    <div 
                      key={card.label} 
                      onClick={() => navigate(card.path)}
                      className="bg-white rounded-xl shadow-sm p-6 cursor-pointer hover:shadow-md hover:scale-[1.02] transition-all border-l-4 border-orange-500"
                    >
                      <div className="flex items-center gap-4">
                        <div className={`${card.color} p-3 rounded-lg`}>
                          <Icon className="text-white" size={24} />
                        </div>
                        <div>
                          <p className="text-sm text-gray-500">{card.label}</p>
                          <p className="text-2xl font-bold text-gray-800">{card.value}</p>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </>
          )}

          {/* Stats Inventaire */}
          <h2 className="text-lg font-semibold text-gray-700 mb-4">Inventaire</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            {cards.map((card) => {
              const Icon = card.icon;
              return (
                <div 
                  key={card.label} 
                  onClick={() => navigate(card.path)}
                  className="bg-white rounded-xl shadow-sm p-6 cursor-pointer hover:shadow-md hover:scale-[1.02] transition-all"
                >
                  <div className="flex items-center gap-4">
                    <div className={`${card.color} p-3 rounded-lg`}>
                      <Icon className="text-white" size={24} />
                    </div>
                    <div>
                      <p className="text-sm text-gray-500">{card.label}</p>
                      <p className="text-2xl font-bold text-gray-800">{card.value}</p>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>

          {/* Stats Relocalisation */}
          <h2 className="text-lg font-semibold text-gray-700 mb-4">Relocalisation</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            {relocCards.map((card) => {
              const Icon = card.icon;
              return (
                <div 
                  key={card.label} 
                  onClick={() => navigate(card.path)}
                  className="bg-white rounded-xl shadow-sm p-6 cursor-pointer hover:shadow-md hover:scale-[1.02] transition-all"
                >
                  <div className="flex items-center gap-4">
                    <div className={`${card.color} p-3 rounded-lg`}>
                      <Icon className="text-white" size={24} />
                    </div>
                    <div>
                      <p className="text-sm text-gray-500">{card.label}</p>
                      <p className="text-2xl font-bold text-gray-800">{card.value}</p>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>

          {/* Aperçu rapide */}
          <h2 className="text-lg font-semibold text-gray-700 mb-4">Aperçu</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            {actionCards.map((card) => {
              const Icon = card.icon;
              return (
                <div 
                  key={card.label} 
                  onClick={() => navigate(card.path)}
                  className="bg-white rounded-xl shadow-sm p-6 cursor-pointer hover:shadow-md hover:scale-[1.02] transition-all"
                >
                  <div className="flex items-center gap-4">
                    <div className={`${card.color} p-3 rounded-lg`}>
                      <Icon className="text-white" size={24} />
                    </div>
                    <div>
                      <p className="text-sm text-gray-500">{card.label}</p>
                      <p className="text-2xl font-bold text-gray-800">{card.value}</p>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        </>
      )}
    </div>
  );
}
