import { useEffect, useState } from 'react';
import { Package, Users, MapPin, ClipboardList } from 'lucide-react';
import { getScans, getProduits, getSecteurs, getEmployes } from '@/services/api';

interface Stats {
  scans: number;
  produits: number;
  secteurs: number;
  employes: number;
}

export default function Dashboard() {
  const [stats, setStats] = useState<Stats>({ scans: 0, produits: 0, secteurs: 0, employes: 0 });
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
      } catch (error) {
        console.error('Erreur chargement stats:', error);
      } finally {
        setLoading(false);
      }
    }
    loadStats();
  }, []);

  const cards = [
    { label: 'Scans', value: stats.scans, icon: ClipboardList, color: 'bg-blue-500' },
    { label: 'Produits', value: stats.produits, icon: Package, color: 'bg-green-500' },
    { label: 'Secteurs', value: stats.secteurs, icon: MapPin, color: 'bg-purple-500' },
    { label: 'Employés', value: stats.employes, icon: Users, color: 'bg-orange-500' },
  ];

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-800 mb-6">Tableau de bord</h1>
      
      {loading ? (
        <div className="text-gray-500">Chargement...</div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          {cards.map((card) => {
            const Icon = card.icon;
            return (
              <div key={card.label} className="bg-white rounded-xl shadow-sm p-6">
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
      )}
    </div>
  );
}
