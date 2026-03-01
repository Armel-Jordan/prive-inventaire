import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Package, Users, MapPin, ClipboardList, ArrowRightLeft, Truck } from 'lucide-react';
import { getScans, getProduits, getSecteurs, getEmployes } from '@/services/api';

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

export default function Dashboard() {
  const navigate = useNavigate();
  const [stats, setStats] = useState<Stats>({ scans: 0, produits: 0, secteurs: 0, employes: 0 });
  const [relocStats, setRelocStats] = useState<RelocStats>({ total: 0, today: 0, by_type: {} });
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function loadStats() {
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
        try {
          const relocRes = await fetch(`${API_BASE_URL}/relocalisation/stats`, {
            headers: getAuthHeaders(),
          });
          if (relocRes.ok) {
            const relocData = await relocRes.json();
            setRelocStats(relocData);
          }
        } catch { /* ignore */ }
      } catch (error) {
        console.error('Erreur chargement stats:', error);
      } finally {
        setLoading(false);
      }
    }
    loadStats();
  }, []);

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

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-800 mb-6">Tableau de bord</h1>
      
      {loading ? (
        <div className="text-gray-500">Chargement...</div>
      ) : (
        <>
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
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
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
        </>
      )}
    </div>
  );
}
