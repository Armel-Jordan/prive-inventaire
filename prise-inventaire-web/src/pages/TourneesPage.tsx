import { useState, useEffect, useCallback } from 'react';
import { Plus, Eye, Play, Square, PackagePlus, X, Truck } from 'lucide-react';
import {
  getTournees,
  getTournee,
  createTournee,
  getCamionsDisponibles,
  demarrerTournee,
  terminerTournee,
  ajouterBonATournee,
  getBonsLivraison,
  getConfiguration,
} from '../services/api';
import type { Tournee, Camion, BonLivraison } from '../services/api';

export default function TourneesPage() {
  const [tournees, setTournees] = useState<Tournee[]>([]);
  const [camions, setCamions] = useState<Camion[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatut, setFilterStatut] = useState('');
  const [filterDate, setFilterDate] = useState(new Date().toISOString().split('T')[0]);
  const [showModal, setShowModal] = useState(false);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [showAjouterBLModal, setShowAjouterBLModal] = useState(false);
  const [selectedTournee, setSelectedTournee] = useState<Tournee | null>(null);
  const [bonsDisponibles, setBonsDisponibles] = useState<BonLivraison[]>([]);

  const [formData, setFormData] = useState({
    date_tournee: new Date().toISOString().split('T')[0],
    camion_id: '',
    zone: '',
  });
  const [configNumero, setConfigNumero] = useState<{ prefixe: string; separateur: string; longueur: number; prochain_numero: number; suffixe: string } | null>(null);

  const loadTournees = useCallback(async () => {
    try {
      setLoading(true);
      const response = await getTournees({
        statut: filterStatut || undefined,
        date: filterDate || undefined,
      });
      setTournees(response.data || []);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }, [filterStatut, filterDate]);

  useEffect(() => {
    loadTournees();
    getConfiguration('tournee').then(setConfigNumero).catch(() => {});
  }, [loadTournees]);

  const loadCamionsDisponibles = async (date: string) => {
    try {
      const data = await getCamionsDisponibles(date);
      setCamions(data || []);
    } catch (error) {
      console.error('Erreur chargement camions:', error);
    }
  };

  const handleOpenModal = () => {
    setFormData({
      date_tournee: new Date().toISOString().split('T')[0],
      camion_id: '',
      zone: '',
    });
    loadCamionsDisponibles(new Date().toISOString().split('T')[0]);
    setShowModal(true);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await createTournee({
        date_tournee: formData.date_tournee,
        camion_id: parseInt(formData.camion_id),
        zone: formData.zone || undefined,
      });
      setShowModal(false);
      loadTournees();
    } catch (error) {
      console.error('Erreur création:', error);
    }
  };

  const handleVoirDetail = async (id: number) => {
    try {
      const tournee = await getTournee(id);
      setSelectedTournee(tournee);
      setShowDetailModal(true);
    } catch (error) {
      console.error('Erreur chargement détail:', error);
    }
  };

  const handleOuvrirAjouterBL = async (tournee: Tournee) => {
    try {
      const response = await getBonsLivraison({ statut: 'pret' });
      setBonsDisponibles(response.data || []);
      setSelectedTournee(tournee);
      setShowAjouterBLModal(true);
    } catch {
      alert('Erreur chargement des bons de livraison');
    }
  };

  const handleAjouterBL = async (bonId: number) => {
    if (!selectedTournee) return;
    try {
      await ajouterBonATournee(selectedTournee.id, bonId);
      const updated = await getTournee(selectedTournee.id);
      setSelectedTournee(updated);
      const response = await getBonsLivraison({ statut: 'pret' });
      setBonsDisponibles(response.data || []);
    } catch {
      alert('Erreur lors de l\'ajout du bon');
    }
  };

  const handleDemarrer = async (id: number) => {
    try {
      await demarrerTournee(id);
      loadTournees();
    } catch (error: any) {
      alert(error?.message || 'Erreur démarrage — ajoutez au moins un bon de livraison.');
    }
  };

  const handleTerminer = async (id: number) => {
    try {
      await terminerTournee(id);
      loadTournees();
    } catch (error) {
      console.error('Erreur terminaison:', error);
    }
  };

  const getStatutBadge = (statut: string) => {
    const styles: Record<string, string> = {
      planifiee: 'bg-blue-100 text-blue-800',
      en_cours: 'bg-yellow-100 text-yellow-800',
      terminee: 'bg-green-100 text-green-800',
      annulee: 'bg-red-100 text-red-800',
    };
    const labels: Record<string, string> = {
      planifiee: 'Planifiée',
      en_cours: 'En cours',
      terminee: 'Terminée',
      annulee: 'Annulée',
    };
    return (
      <span className={`px-2 py-1 text-xs rounded-full ${styles[statut] || 'bg-gray-100'}`}>
        {labels[statut] || statut}
      </span>
    );
  };

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Tournées de livraison</h1>
        <button
          onClick={handleOpenModal}
          className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700"
        >
          <Plus size={20} />
          Nouvelle tournée
        </button>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 mb-6">
        <div className="flex gap-4">
          <input
            type="date"
            value={filterDate}
            onChange={(e) => setFilterDate(e.target.value)}
            className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
          <select
            value={filterStatut}
            onChange={(e) => setFilterStatut(e.target.value)}
            className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          >
            <option value="">Tous les statuts</option>
            <option value="planifiee">Planifiée</option>
            <option value="en_cours">En cours</option>
            <option value="terminee">Terminée</option>
          </select>
        </div>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
        {loading ? (
          <div className="p-8 text-center text-gray-500">Chargement...</div>
        ) : tournees.length === 0 ? (
          <div className="p-8 text-center text-gray-500">Aucune tournée trouvée</div>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">N°</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Date</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Camion</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Zone</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">BL</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Statut</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {tournees.map((tournee) => (
                <tr key={tournee.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                  <td className="px-4 py-3 font-mono text-sm text-gray-900 dark:text-white">{tournee.numero}</td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{new Date(tournee.date_tournee).toLocaleDateString('fr-FR')}</td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                    <div className="flex items-center gap-2">
                      <Truck size={16} className="text-gray-400" />
                      {tournee.camion?.immatriculation || '-'}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{tournee.zone || '-'}</td>
                  <td className="px-4 py-3 text-center text-sm text-gray-600 dark:text-gray-300">
                    {tournee.tournee_bons?.length || 0}
                  </td>
                  <td className="px-4 py-3 text-center">{getStatutBadge(tournee.statut)}</td>
                  <td className="px-4 py-3">
                    <div className="flex justify-center gap-1">
                      <button
                        onClick={() => handleVoirDetail(tournee.id)}
                        className="p-1 text-gray-600 hover:bg-gray-100 rounded"
                        title="Voir détails"
                      >
                        <Eye size={18} />
                      </button>
                      {tournee.statut === 'planifiee' && (
                        <>
                          <button
                            onClick={() => handleOuvrirAjouterBL(tournee)}
                            className="p-1 text-blue-600 hover:bg-blue-50 rounded"
                            title="Ajouter un bon de livraison"
                          >
                            <PackagePlus size={18} />
                          </button>
                          <button
                            onClick={() => handleDemarrer(tournee.id)}
                            className="p-1 text-green-600 hover:bg-green-50 rounded"
                            title="Démarrer"
                          >
                            <Play size={18} />
                          </button>
                        </>
                      )}
                      {tournee.statut === 'en_cours' && (
                        <button
                          onClick={() => handleTerminer(tournee.id)}
                          className="p-1 text-red-600 hover:bg-red-50 rounded"
                          title="Terminer"
                        >
                          <Square size={18} />
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* Modal création */}
      {showModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <div className="mb-4">
              <h2 className="text-xl font-bold dark:text-white">Nouvelle tournée</h2>
              {configNumero && (
                <p className="text-xs text-gray-500 mt-1">
                  Numéro auto: {configNumero.prefixe}{configNumero.separateur || ''}{String(configNumero.prochain_numero).padStart(configNumero.longueur, '0')}{configNumero.suffixe ? (configNumero.separateur || '') + configNumero.suffixe : ''}
                </p>
              )}
            </div>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Date *</label>
                <input
                  type="date"
                  required
                  value={formData.date_tournee}
                  onChange={(e) => {
                    setFormData({ ...formData, date_tournee: e.target.value });
                    loadCamionsDisponibles(e.target.value);
                  }}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Camion *</label>
                <select
                  required
                  value={formData.camion_id}
                  onChange={(e) => setFormData({ ...formData, camion_id: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                >
                  <option value="">Sélectionner...</option>
                  {camions.map(c => (
                    <option key={c.id} value={c.id}>
                      {c.immatriculation} - {c.marque} {c.modele}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Zone</label>
                <input
                  type="text"
                  value={formData.zone}
                  onChange={(e) => setFormData({ ...formData, zone: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  placeholder="Ex: Nord, Centre-ville..."
                />
              </div>
              <div className="flex justify-end gap-3 mt-6">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
                >
                  Annuler
                </button>
                <button type="submit" className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                  Créer
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal ajouter BL */}
      {showAjouterBLModal && selectedTournee && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg">
            <div className="flex justify-between items-center mb-4">
              <h2 className="text-xl font-bold dark:text-white">
                Ajouter un BL — Tournée {selectedTournee.numero}
              </h2>
              <button
                onClick={() => { setShowAjouterBLModal(false); }}
                className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"
              >
                <X size={20} className="text-gray-500" />
              </button>
            </div>
            {bonsDisponibles.length === 0 ? (
              <p className="text-gray-500 text-sm py-4 text-center">
                Aucun bon de livraison prêt disponible.<br />
                Les bons doivent être à l'état "Prêt" pour être ajoutés à une tournée.
              </p>
            ) : (
              <div className="space-y-2 max-h-80 overflow-y-auto">
                {bonsDisponibles.map((bon) => (
                  <div
                    key={bon.id}
                    className="flex justify-between items-center p-3 border rounded-lg dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700"
                  >
                    <div>
                      <p className="font-mono font-medium dark:text-white">{bon.numero}</p>
                      <p className="text-sm text-gray-500">
                        {bon.facture?.client?.nom || 'Client inconnu'} — {bon.mode_livraison}
                      </p>
                    </div>
                    <button
                      onClick={() => handleAjouterBL(bon.id)}
                      className="px-3 py-1 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700"
                    >
                      Ajouter
                    </button>
                  </div>
                ))}
              </div>
            )}
            <div className="flex justify-end mt-4">
              <button
                onClick={() => setShowAjouterBLModal(false)}
                className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
              >
                Fermer
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal détail */}
      {showDetailModal && selectedTournee && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl">
            <h2 className="text-xl font-bold mb-4 dark:text-white">
              Tournée {selectedTournee.numero}
            </h2>
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <span className="text-sm text-gray-500">Date:</span>
                  <p className="font-medium dark:text-white">{new Date(selectedTournee.date_tournee).toLocaleDateString('fr-FR')}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Statut:</span>
                  <p>{getStatutBadge(selectedTournee.statut)}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Camion:</span>
                  <p className="dark:text-white">{selectedTournee.camion?.immatriculation || '-'}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Zone:</span>
                  <p className="dark:text-white">{selectedTournee.zone || '-'}</p>
                </div>
                {selectedTournee.heure_depart && (
                  <div>
                    <span className="text-sm text-gray-500">Heure départ:</span>
                    <p className="dark:text-white">{selectedTournee.heure_depart}</p>
                  </div>
                )}
                {selectedTournee.heure_retour && (
                  <div>
                    <span className="text-sm text-gray-500">Heure retour:</span>
                    <p className="dark:text-white">{selectedTournee.heure_retour}</p>
                  </div>
                )}
              </div>

              {selectedTournee.tournee_bons && selectedTournee.tournee_bons.length > 0 && (
                <div className="border-t pt-4 dark:border-gray-700">
                  <h3 className="font-medium mb-2 dark:text-white">Bons de livraison ({selectedTournee.tournee_bons.length})</h3>
                  <div className="space-y-2">
                    {selectedTournee.tournee_bons.map((tb) => (
                      <div key={tb.id} className="flex justify-between items-center p-2 bg-gray-50 dark:bg-gray-700 rounded">
                        <div>
                          <span className="font-mono text-sm dark:text-white">
                            #{tb.ordre_livraison} - {tb.bon_livraison?.numero}
                          </span>
                        </div>
                        <span className={`text-xs px-2 py-1 rounded ${
                          tb.statut === 'livre' ? 'bg-green-100 text-green-800' :
                          tb.statut === 'echec' ? 'bg-red-100 text-red-800' :
                          'bg-gray-100 text-gray-800'
                        }`}>
                          {tb.statut === 'livre' ? 'Livré' : tb.statut === 'echec' ? 'Échec' : 'En attente'}
                        </span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
            <div className="flex justify-end mt-6">
              <button
                onClick={() => { setShowDetailModal(false); setSelectedTournee(null); }}
                className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
              >
                Fermer
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
