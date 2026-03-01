import { useEffect, useState } from 'react';
import { Search } from 'lucide-react';
import { getEmployes } from '@/services/api';
import type { Employe } from '@/types';

export default function EmployesPage() {
  const [employes, setEmployes] = useState<Employe[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');

  useEffect(() => {
    async function load() {
      try {
        const data = await getEmployes();
        setEmployes(data);
      } catch (error) {
        console.error('Erreur chargement:', error);
      } finally {
        setLoading(false);
      }
    }
    load();
  }, []);

  const filtered = employes.filter(e => 
    e.numero.toLowerCase().includes(search.toLowerCase()) ||
    e.nom.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h1 className="text-2xl font-bold text-gray-800">Employés</h1>
        <p className="text-sm text-gray-500">
          (Lecture seule - les employés viennent de la base Oracle)
        </p>
      </div>

      {/* Recherche */}
      <div className="bg-white rounded-xl shadow-sm p-4 mb-6">
        <div className="relative">
          <Search size={20} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            type="text"
            placeholder="Rechercher par numéro ou nom..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full pl-10 pr-4 py-2 border rounded-lg"
          />
        </div>
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-6 text-gray-500">Chargement...</div>
        ) : filtered.length === 0 ? (
          <div className="p-6 text-gray-500">Aucun employé trouvé</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Numéro</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Nom</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {filtered.map((employe) => (
                  <tr key={employe.numero} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-sm font-mono font-medium">{employe.numero}</td>
                    <td className="px-4 py-3 text-sm">{employe.nom}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
