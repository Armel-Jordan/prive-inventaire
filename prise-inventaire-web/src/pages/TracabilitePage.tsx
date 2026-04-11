import { useState } from 'react';
import { Search, MapPin, Package, Truck, ClipboardList, ArrowRight, Clock } from 'lucide-react';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';

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

interface HistoryItem {
  id: number;
  type: 'mouvement' | 'inventaire';
  action: string;
  secteur_source: string | null;
  secteur_destination: string | null;
  quantite: number;
  unite_mesure: string | null;
  employe: string;
  motif: string | null;
  date: string;
}

interface Stats {
  total_mouvements: number;
  total_scans: number;
  secteurs_visites: string[];
  derniere_localisation: string | null;
  quantite_totale_entree: number;
  quantite_totale_sortie: number;
}

interface SearchResult {
  produit_numero: string;
  produit_nom: string | null;
}

const actionLabels: Record<string, { label: string; color: string; icon: typeof Truck }> = {
  arrivage: { label: 'Arrivage', color: 'bg-green-100 text-green-700', icon: Truck },
  transfert: { label: 'Transfert', color: 'bg-blue-100 text-blue-700', icon: ArrowRight },
  sortie: { label: 'Sortie', color: 'bg-red-100 text-red-700', icon: Package },
  ajustement: { label: 'Ajustement', color: 'bg-yellow-100 text-yellow-700', icon: ClipboardList },
  inventaire: { label: 'Inventaire', color: 'bg-purple-100 text-purple-700', icon: ClipboardList },
  scan: { label: 'Scan', color: 'bg-purple-100 text-purple-700', icon: ClipboardList },
};

export default function TracabilitePage() {
  const { toasts, toast, dismiss } = useToast();
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState<SearchResult[]>([]);
  const [selectedProduit, setSelectedProduit] = useState<string | null>(null);
  const [historique, setHistorique] = useState<HistoryItem[]>([]);
  const [stats, setStats] = useState<Stats | null>(null);
  const [loading, setLoading] = useState(false);
  const [showResults, setShowResults] = useState(false);

  async function handleSearch(query: string) {
    setSearchQuery(query);
    if (query.length < 2) {
      setSearchResults([]);
      setShowResults(false);
      return;
    }

    try {
      const response = await fetch(`${API_BASE_URL}/tracabilite/search?q=${encodeURIComponent(query)}`, {
        headers: getAuthHeaders(),
      });
      if (response.ok) {
        const results = await response.json();
        setSearchResults(results);
        setShowResults(true);
      }
    } catch (error) {
      toast('Erreur lors de la recherche', 'error');
    }
  }

  async function selectProduit(numero: string) {
    setSelectedProduit(numero);
    setSearchQuery(numero);
    setShowResults(false);
    setLoading(true);

    try {
      const response = await fetch(`${API_BASE_URL}/tracabilite/produit/${encodeURIComponent(numero)}`, {
        headers: getAuthHeaders(),
      });
      if (response.ok) {
        const data = await response.json();
        setHistorique(data.historique);
        setStats(data.stats);
      }
    } catch (error) {
      toast('Erreur de chargement de l\'historique', 'error');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-800">Traçabilité Produit</h1>
        <p className="text-gray-500">Suivez l'historique complet d'un produit</p>
      </div>

      {/* Recherche */}
      <div className="bg-white rounded-xl shadow-sm p-6">
        <div className="relative max-w-xl">
          <Search size={20} className="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => handleSearch(e.target.value)}
            onFocus={() => searchResults.length > 0 && setShowResults(true)}
            placeholder="Rechercher un produit par numéro ou nom..."
            className="w-full pl-12 pr-4 py-3 border rounded-xl text-lg"
          />
          
          {/* Résultats de recherche */}
          {showResults && searchResults.length > 0 && (
            <div className="absolute top-full left-0 right-0 mt-2 bg-white border rounded-xl shadow-lg z-10 max-h-64 overflow-y-auto">
              {searchResults.map((result) => (
                <button
                  key={result.produit_numero}
                  onClick={() => selectProduit(result.produit_numero)}
                  className="w-full px-4 py-3 text-left hover:bg-gray-50 flex items-center gap-3 border-b last:border-b-0"
                >
                  <Package size={18} className="text-gray-400" />
                  <div>
                    <p className="font-medium">{result.produit_numero}</p>
                    {result.produit_nom && <p className="text-sm text-gray-500">{result.produit_nom}</p>}
                  </div>
                </button>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Statistiques */}
      {stats && selectedProduit && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-blue-100 rounded-lg">
                <MapPin className="text-blue-600" size={24} />
              </div>
              <div>
                <p className="text-lg font-bold text-gray-800">{stats.derniere_localisation || 'Inconnue'}</p>
                <p className="text-sm text-gray-500">Dernière localisation</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-green-100 rounded-lg">
                <Truck className="text-green-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-gray-800">{stats.total_mouvements}</p>
                <p className="text-sm text-gray-500">Mouvements</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-purple-100 rounded-lg">
                <ClipboardList className="text-purple-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-gray-800">{stats.total_scans}</p>
                <p className="text-sm text-gray-500">Inventaires</p>
              </div>
            </div>
          </div>
          <div className="bg-white rounded-xl shadow-sm p-5">
            <div className="flex items-center gap-3">
              <div className="p-3 bg-orange-100 rounded-lg">
                <MapPin className="text-orange-600" size={24} />
              </div>
              <div>
                <p className="text-2xl font-bold text-gray-800">{stats.secteurs_visites.length}</p>
                <p className="text-sm text-gray-500">Secteurs visités</p>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Secteurs visités */}
      {stats && stats.secteurs_visites.length > 0 && (
        <div className="bg-white rounded-xl shadow-sm p-4">
          <h3 className="text-sm font-medium text-gray-700 mb-3">Secteurs visités</h3>
          <div className="flex flex-wrap gap-2">
            {stats.secteurs_visites.map((secteur) => (
              <span
                key={secteur}
                className={`px-3 py-1.5 rounded-full text-sm font-medium ${
                  secteur === stats.derniere_localisation
                    ? 'bg-blue-600 text-white'
                    : 'bg-gray-100 text-gray-700'
                }`}
              >
                <MapPin size={12} className="inline mr-1" />
                {secteur}
              </span>
            ))}
          </div>
        </div>
      )}

      {/* Timeline */}
      {selectedProduit && (
        <div className="bg-white rounded-xl shadow-sm overflow-hidden">
          <div className="p-4 border-b">
            <h2 className="text-lg font-semibold flex items-center gap-2">
              <Clock size={20} />
              Historique de {selectedProduit}
            </h2>
          </div>

          {loading ? (
            <div className="p-8 text-center text-gray-500">Chargement...</div>
          ) : historique.length === 0 ? (
            <div className="p-8 text-center text-gray-500">
              <Package className="mx-auto text-gray-300 mb-4" size={48} />
              <p>Aucun historique trouvé pour ce produit</p>
            </div>
          ) : (
            <div className="relative">
              {/* Ligne verticale */}
              <div className="absolute left-8 top-0 bottom-0 w-0.5 bg-gray-200" />

              <div className="divide-y">
                {historique.map((item) => {
                  const actionInfo = actionLabels[item.action] || actionLabels[item.type] || actionLabels.inventaire;
                  const Icon = actionInfo.icon;

                  return (
                    <div key={`${item.type}-${item.id}`} className="relative pl-16 pr-4 py-4 hover:bg-gray-50">
                      {/* Point sur la timeline */}
                      <div className={`absolute left-6 w-5 h-5 rounded-full border-2 border-white ${actionInfo.color.split(' ')[0]} flex items-center justify-center`}>
                        <Icon size={10} className={actionInfo.color.split(' ')[1]} />
                      </div>

                      <div className="flex items-start justify-between">
                        <div>
                          <div className="flex items-center gap-2 mb-1">
                            <span className={`px-2 py-0.5 rounded text-xs font-medium ${actionInfo.color}`}>
                              {actionInfo.label}
                            </span>
                            {item.type === 'mouvement' && (
                              <span className="text-sm text-gray-500">
                                {item.secteur_source && <span>{item.secteur_source}</span>}
                                {item.secteur_source && item.secteur_destination && (
                                  <ArrowRight size={12} className="inline mx-1" />
                                )}
                                {item.secteur_destination && <span>{item.secteur_destination}</span>}
                              </span>
                            )}
                            {item.type === 'inventaire' && item.secteur_destination && (
                              <span className="text-sm text-gray-500">
                                <MapPin size={12} className="inline mr-1" />
                                {item.secteur_destination}
                              </span>
                            )}
                          </div>
                          <div className="text-sm text-gray-600">
                            <span className="font-medium">{item.quantite}</span>
                            {item.unite_mesure && <span> {item.unite_mesure}</span>}
                            <span className="mx-2">•</span>
                            <span>Par {item.employe}</span>
                            {item.motif && (
                              <>
                                <span className="mx-2">•</span>
                                <span className="text-gray-400">{item.motif}</span>
                              </>
                            )}
                          </div>
                        </div>
                        <div className="text-sm text-gray-400 whitespace-nowrap">
                          {new Date(item.date).toLocaleString('fr-FR', {
                            day: '2-digit',
                            month: '2-digit',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit',
                          })}
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </div>
      )}

      {/* Message initial */}
      {!selectedProduit && (
        <div className="bg-white rounded-xl shadow-sm p-12 text-center">
          <Package className="mx-auto text-gray-300 mb-4" size={64} />
          <h3 className="text-lg font-medium text-gray-600 mb-2">Recherchez un produit</h3>
          <p className="text-gray-400">Entrez un numéro ou nom de produit pour voir son historique complet</p>
        </div>
      )}
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}
