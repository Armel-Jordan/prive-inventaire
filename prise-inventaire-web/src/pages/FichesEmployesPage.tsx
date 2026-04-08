import { useEffect, useState } from 'react';
import { Search, User, Phone, MapPin, Briefcase, Calendar } from 'lucide-react';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const STORAGE_KEY = 'prise_auth';

function getAuthHeaders(): Record<string, string> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  };
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

interface FicheEmploye {
  id: number;
  numero: string;
  nom: string;
  prenom?: string;
  email?: string;
  telephone?: string;
  adresse?: string;
  ville?: string;
  code_postal?: string;
  pays?: string;
  date_naissance?: string;
  sexe?: string;
  poste?: string;
  departement?: string;
  actif?: boolean;
  admin_user?: {
    id: number;
    role: string;
    actif: boolean;
    profil_complete?: boolean;
  };
}

const roleLabels: Record<string, { label: string; color: string }> = {
  admin:   { label: 'Admin',       color: 'bg-red-100 text-red-700' },
  manager: { label: 'Manager',     color: 'bg-orange-100 text-orange-700' },
  user:    { label: 'Utilisateur', color: 'bg-blue-100 text-blue-700' },
};

export default function FichesEmployesPage() {
  const [employes, setEmployes] = useState<FicheEmploye[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<FicheEmploye | null>(null);

  useEffect(() => {
    loadData();
  }, []);

  async function loadData() {
    try {
      const response = await fetch(`${API_BASE_URL}/employes`, {
        headers: getAuthHeaders(),
      });
      if (response.ok) {
        const data = await response.json();
        setEmployes(data);
      }
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }

  const filtered = employes.filter(e => {
    const q = search.toLowerCase();
    return (
      e.nom.toLowerCase().includes(q) ||
      (e.prenom ?? '').toLowerCase().includes(q) ||
      (e.numero ?? '').toLowerCase().includes(q) ||
      (e.email ?? '').toLowerCase().includes(q) ||
      (e.poste ?? '').toLowerCase().includes(q) ||
      (e.departement ?? '').toLowerCase().includes(q)
    );
  });

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="text-gray-500">Chargement...</div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Fiches Employés</h1>
        <p className="text-gray-500 dark:text-gray-400 mt-1">Profils de tous les employés de l'entreprise</p>
      </div>

      <div className="relative max-w-sm">
        <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
        <input
          type="text"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Rechercher..."
          className="w-full pl-9 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm bg-white dark:bg-gray-800 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
        />
      </div>

      {filtered.length === 0 ? (
        <div className="text-center py-12 text-gray-400">Aucun employé trouvé</div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {filtered.map((emp) => (
            <div
              key={emp.id}
              onClick={() => setSelected(emp)}
              className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 cursor-pointer hover:shadow-md hover:border-blue-300 dark:hover:border-blue-600 transition-all"
            >
              <div className="flex items-start justify-between mb-3">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center">
                    <User size={20} className="text-blue-600 dark:text-blue-400" />
                  </div>
                  <div>
                    <p className="font-semibold text-gray-800 dark:text-white">
                      {emp.nom} {emp.prenom ?? ''}
                    </p>
                    <p className="text-xs text-gray-400">{emp.numero}</p>
                  </div>
                </div>
                {emp.admin_user && (
                  <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${roleLabels[emp.admin_user.role]?.color ?? 'bg-gray-100 text-gray-600'}`}>
                    {roleLabels[emp.admin_user.role]?.label ?? emp.admin_user.role}
                  </span>
                )}
              </div>

              <div className="space-y-1.5 text-sm">
                {emp.poste && (
                  <div className="flex items-center gap-2 text-gray-600 dark:text-gray-300">
                    <Briefcase size={14} className="text-gray-400 flex-shrink-0" />
                    <span>{emp.poste}{emp.departement ? ` — ${emp.departement}` : ''}</span>
                  </div>
                )}
                {emp.email && (
                  <div className="flex items-center gap-2 text-gray-600 dark:text-gray-300">
                    <span className="text-gray-400 text-xs">@</span>
                    <span className="truncate">{emp.email}</span>
                  </div>
                )}
                {emp.telephone && (
                  <div className="flex items-center gap-2 text-gray-600 dark:text-gray-300">
                    <Phone size={14} className="text-gray-400 flex-shrink-0" />
                    <span>{emp.telephone}</span>
                  </div>
                )}
                {emp.ville && (
                  <div className="flex items-center gap-2 text-gray-600 dark:text-gray-300">
                    <MapPin size={14} className="text-gray-400 flex-shrink-0" />
                    <span>{emp.ville}{emp.pays ? `, ${emp.pays}` : ''}</span>
                  </div>
                )}
              </div>

              {!emp.poste && !emp.telephone && !emp.email && (
                <p className="text-xs text-gray-400 italic mt-2">Profil non complété</p>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Detail modal */}
      {selected && (
        <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" onClick={() => setSelected(null)}>
          <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-w-md p-6" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center gap-4 mb-6">
              <div className="w-14 h-14 rounded-full bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center">
                <User size={28} className="text-blue-600 dark:text-blue-400" />
              </div>
              <div>
                <h2 className="text-xl font-bold text-gray-800 dark:text-white">
                  {selected.nom} {selected.prenom ?? ''}
                </h2>
                <p className="text-sm text-gray-400">{selected.numero}</p>
                {selected.admin_user && (
                  <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${roleLabels[selected.admin_user.role]?.color ?? 'bg-gray-100 text-gray-600'}`}>
                    {roleLabels[selected.admin_user.role]?.label ?? selected.admin_user.role}
                  </span>
                )}
              </div>
            </div>

            <div className="space-y-3 text-sm">
              {(selected.poste || selected.departement) && (
                <div className="flex gap-3">
                  <Briefcase size={16} className="text-gray-400 mt-0.5 flex-shrink-0" />
                  <div>
                    <p className="font-medium text-gray-700 dark:text-gray-300">{selected.poste ?? '—'}</p>
                    {selected.departement && <p className="text-gray-500">{selected.departement}</p>}
                  </div>
                </div>
              )}
              {selected.email && (
                <div className="flex gap-3">
                  <span className="text-gray-400 text-xs mt-0.5">@</span>
                  <p className="text-gray-700 dark:text-gray-300">{selected.email}</p>
                </div>
              )}
              {selected.telephone && (
                <div className="flex gap-3">
                  <Phone size={16} className="text-gray-400 mt-0.5 flex-shrink-0" />
                  <p className="text-gray-700 dark:text-gray-300">{selected.telephone}</p>
                </div>
              )}
              {(selected.adresse || selected.ville) && (
                <div className="flex gap-3">
                  <MapPin size={16} className="text-gray-400 mt-0.5 flex-shrink-0" />
                  <div>
                    {selected.adresse && <p className="text-gray-700 dark:text-gray-300">{selected.adresse}</p>}
                    <p className="text-gray-500">
                      {[selected.ville, selected.code_postal, selected.pays].filter(Boolean).join(', ')}
                    </p>
                  </div>
                </div>
              )}
              {selected.date_naissance && (
                <div className="flex gap-3">
                  <Calendar size={16} className="text-gray-400 mt-0.5 flex-shrink-0" />
                  <p className="text-gray-700 dark:text-gray-300">
                    {new Date(selected.date_naissance).toLocaleDateString('fr-CA')}
                    {selected.sexe && ` · ${selected.sexe === 'M' ? 'Masculin' : selected.sexe === 'F' ? 'Féminin' : 'Autre'}`}
                  </p>
                </div>
              )}
            </div>

            <button
              onClick={() => setSelected(null)}
              className="mt-6 w-full py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
            >
              Fermer
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
