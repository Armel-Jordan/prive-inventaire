import { useState, useEffect, useCallback } from 'react';
import { Eye, Package, CheckCircle, Truck } from 'lucide-react';
import {
  getBonsLivraison,
  getBonLivraison,
  demarrerPreparation,
  updateLignesBL,
  marquerBLPret,
  enregistrerLivraison,
} from '../services/api';
import type { BonLivraison, BonLivraisonLigne } from '../services/api';

export default function BonsLivraisonPage() {
  const [bons, setBons] = useState<BonLivraison[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatut, setFilterStatut] = useState('');
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [showPreparationModal, setShowPreparationModal] = useState(false);
  const [showLivraisonModal, setShowLivraisonModal] = useState(false);
  const [selectedBon, setSelectedBon] = useState<BonLivraison | null>(null);
  const [lignesPreparation, setLignesPreparation] = useState<{ id: number; quantite_preparee: number }[]>([]);
  const [lignesLivraison, setLignesLivraison] = useState<{ id: number; quantite_livree: number }[]>([]);
  const [notesLivraison, setNotesLivraison] = useState('');

  const loadBons = useCallback(async () => {
    try {
      setLoading(true);
      const response = await getBonsLivraison({ statut: filterStatut || undefined });
      setBons(response.data || []);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }, [filterStatut]);

  useEffect(() => {
    loadBons();
  }, [loadBons]);

  const handleVoirDetail = async (id: number) => {
    try {
      const bon = await getBonLivraison(id);
      setSelectedBon(bon);
      setShowDetailModal(true);
    } catch (error) {
      console.error('Erreur chargement détail:', error);
    }
  };

  const handleDemarrerPreparation = async (id: number) => {
    try {
      await demarrerPreparation(id);
      loadBons();
    } catch (error) {
      console.error('Erreur démarrage préparation:', error);
    }
  };

  const handleOuvrirPreparation = async (id: number) => {
    try {
      const bon = await getBonLivraison(id);
      setSelectedBon(bon);
      setLignesPreparation(
        bon.lignes?.map((l: BonLivraisonLigne) => ({
          id: l.id,
          quantite_preparee: l.quantite_preparee || 0,
        })) || []
      );
      setShowPreparationModal(true);
    } catch (error) {
      console.error('Erreur:', error);
    }
  };

  const handleSauvegarderPreparation = async () => {
    if (!selectedBon) return;
    try {
      await updateLignesBL(selectedBon.id, lignesPreparation);
      setShowPreparationModal(false);
      loadBons();
    } catch (error) {
      console.error('Erreur sauvegarde:', error);
    }
  };

  const handleMarquerPret = async (id: number) => {
    try {
      await marquerBLPret(id);
      loadBons();
    } catch (error) {
      console.error('Erreur:', error);
    }
  };

  const handleOuvrirLivraison = async (id: number) => {
    try {
      const bon = await getBonLivraison(id);
      setSelectedBon(bon);
      setLignesLivraison(
        bon.lignes?.map((l: BonLivraisonLigne) => ({
          id: l.id,
          quantite_livree: l.quantite_preparee || l.quantite_a_livrer,
        })) || []
      );
      setNotesLivraison('');
      setShowLivraisonModal(true);
    } catch (error) {
      console.error('Erreur:', error);
    }
  };

  const handleEnregistrerLivraison = async () => {
    if (!selectedBon) return;
    try {
      await enregistrerLivraison(selectedBon.id, {
        lignes: lignesLivraison,
        notes_livraison: notesLivraison || undefined,
      });
      setShowLivraisonModal(false);
      loadBons();
    } catch (error) {
      console.error('Erreur livraison:', error);
    }
  };

  const getStatutBadge = (statut: string) => {
    const styles: Record<string, string> = {
      cree: 'bg-gray-100 text-gray-800',
      en_preparation: 'bg-yellow-100 text-yellow-800',
      pret: 'bg-blue-100 text-blue-800',
      en_livraison: 'bg-purple-100 text-purple-800',
      livre_complet: 'bg-green-100 text-green-800',
      livre_partiel: 'bg-orange-100 text-orange-800',
      annule: 'bg-red-100 text-red-800',
    };
    const labels: Record<string, string> = {
      cree: 'Créé',
      en_preparation: 'En préparation',
      pret: 'Prêt',
      en_livraison: 'En livraison',
      livre_complet: 'Livré',
      livre_partiel: 'Partiel',
      annule: 'Annulé',
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
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Bons de Livraison</h1>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 mb-6">
        <div className="flex gap-4">
          <select
            value={filterStatut}
            onChange={(e) => setFilterStatut(e.target.value)}
            className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          >
            <option value="">Tous les statuts</option>
            <option value="cree">Créé</option>
            <option value="en_preparation">En préparation</option>
            <option value="pret">Prêt</option>
            <option value="en_livraison">En livraison</option>
            <option value="livre_complet">Livré complet</option>
            <option value="livre_partiel">Livré partiel</option>
          </select>
        </div>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
        {loading ? (
          <div className="p-8 text-center text-gray-500">Chargement...</div>
        ) : bons.length === 0 ? (
          <div className="p-8 text-center text-gray-500">Aucun bon de livraison trouvé</div>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">N°</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Facture</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Mode</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Statut</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {bons.map((bon) => (
                <tr key={bon.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                  <td className="px-4 py-3 font-mono text-sm text-gray-900 dark:text-white">{bon.numero}</td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{bon.facture?.numero}</td>
                  <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                    {bon.mode_livraison === 'entreprise' ? 'Livraison' : 'Retrait client'}
                  </td>
                  <td className="px-4 py-3 text-center">{getStatutBadge(bon.statut)}</td>
                  <td className="px-4 py-3">
                    <div className="flex justify-center gap-1">
                      <button
                        onClick={() => handleVoirDetail(bon.id)}
                        className="p-1 text-gray-600 hover:bg-gray-100 rounded"
                        title="Voir détails"
                      >
                        <Eye size={18} />
                      </button>
                      {bon.statut === 'cree' && (
                        <button
                          onClick={() => handleDemarrerPreparation(bon.id)}
                          className="p-1 text-yellow-600 hover:bg-yellow-50 rounded"
                          title="Démarrer préparation"
                        >
                          <Package size={18} />
                        </button>
                      )}
                      {bon.statut === 'en_preparation' && (
                        <>
                          <button
                            onClick={() => handleOuvrirPreparation(bon.id)}
                            className="p-1 text-yellow-600 hover:bg-yellow-50 rounded"
                            title="Modifier préparation"
                          >
                            <Package size={18} />
                          </button>
                          <button
                            onClick={() => handleMarquerPret(bon.id)}
                            className="p-1 text-blue-600 hover:bg-blue-50 rounded"
                            title="Marquer prêt"
                          >
                            <CheckCircle size={18} />
                          </button>
                        </>
                      )}
                      {(bon.statut === 'pret' || bon.statut === 'en_livraison') && (
                        <button
                          onClick={() => handleOuvrirLivraison(bon.id)}
                          className="p-1 text-green-600 hover:bg-green-50 rounded"
                          title="Enregistrer livraison"
                        >
                          <Truck size={18} />
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

      {/* Modal détail */}
      {showDetailModal && selectedBon && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl">
            <h2 className="text-xl font-bold mb-4 dark:text-white">
              Bon de livraison {selectedBon.numero}
            </h2>
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <span className="text-sm text-gray-500">Facture:</span>
                  <p className="font-medium dark:text-white">{selectedBon.facture?.numero}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Statut:</span>
                  <p>{getStatutBadge(selectedBon.statut)}</p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Mode:</span>
                  <p className="dark:text-white">
                    {selectedBon.mode_livraison === 'entreprise' ? 'Livraison entreprise' : 'Retrait client'}
                  </p>
                </div>
                <div>
                  <span className="text-sm text-gray-500">Date livraison:</span>
                  <p className="dark:text-white">{selectedBon.date_livraison || '-'}</p>
                </div>
              </div>

              {selectedBon.lignes && selectedBon.lignes.length > 0 && (
                <div className="border-t pt-4 dark:border-gray-700">
                  <h3 className="font-medium mb-2 dark:text-white">Lignes</h3>
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-gray-500">
                        <th className="py-1">Produit</th>
                        <th className="py-1 text-right">À livrer</th>
                        <th className="py-1 text-right">Préparé</th>
                        <th className="py-1 text-right">Livré</th>
                      </tr>
                    </thead>
                    <tbody>
                      {selectedBon.lignes.map((ligne, idx) => (
                        <tr key={idx} className="dark:text-gray-300">
                          <td className="py-1">Produit #{ligne.produit_id}</td>
                          <td className="py-1 text-right">{ligne.quantite_a_livrer}</td>
                          <td className="py-1 text-right">{ligne.quantite_preparee}</td>
                          <td className="py-1 text-right">{ligne.quantite_livree}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
            <div className="flex justify-end mt-6">
              <button
                onClick={() => { setShowDetailModal(false); setSelectedBon(null); }}
                className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
              >
                Fermer
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal préparation */}
      {showPreparationModal && selectedBon && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg">
            <h2 className="text-xl font-bold mb-4 dark:text-white">Préparation - {selectedBon.numero}</h2>
            <div className="space-y-3">
              {selectedBon.lignes?.map((ligne) => {
                const prep = lignesPreparation.find(l => l.id === ligne.id);
                return (
                  <div key={ligne.id} className="flex items-center justify-between gap-4 p-3 bg-gray-50 dark:bg-gray-700 rounded">
                    <div className="flex-1">
                      <p className="font-medium dark:text-white">Produit #{ligne.produit_id}</p>
                      <p className="text-sm text-gray-500">À préparer: {ligne.quantite_a_livrer}</p>
                    </div>
                    <input
                      type="number"
                      min="0"
                      max={ligne.quantite_a_livrer}
                      value={prep?.quantite_preparee || 0}
                      onChange={(e) => {
                        const newLignes = [...lignesPreparation];
                        const index = newLignes.findIndex(l => l.id === ligne.id);
                        if (index >= 0) {
                          newLignes[index].quantite_preparee = parseInt(e.target.value) || 0;
                          setLignesPreparation(newLignes);
                        }
                      }}
                      className="w-20 px-2 py-1 border rounded text-center dark:bg-gray-600 dark:border-gray-500 dark:text-white"
                    />
                  </div>
                );
              })}
            </div>
            <div className="flex justify-end gap-3 mt-6">
              <button
                onClick={() => setShowPreparationModal(false)}
                className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
              >
                Annuler
              </button>
              <button
                onClick={handleSauvegarderPreparation}
                className="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700"
              >
                Sauvegarder
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal livraison */}
      {showLivraisonModal && selectedBon && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg">
            <h2 className="text-xl font-bold mb-4 dark:text-white">Livraison - {selectedBon.numero}</h2>
            <div className="space-y-3">
              {selectedBon.lignes?.map((ligne) => {
                const liv = lignesLivraison.find(l => l.id === ligne.id);
                return (
                  <div key={ligne.id} className="flex items-center justify-between gap-4 p-3 bg-gray-50 dark:bg-gray-700 rounded">
                    <div className="flex-1">
                      <p className="font-medium dark:text-white">Produit #{ligne.produit_id}</p>
                      <p className="text-sm text-gray-500">Préparé: {ligne.quantite_preparee}</p>
                    </div>
                    <input
                      type="number"
                      min="0"
                      max={ligne.quantite_preparee}
                      value={liv?.quantite_livree || 0}
                      onChange={(e) => {
                        const newLignes = [...lignesLivraison];
                        const index = newLignes.findIndex(l => l.id === ligne.id);
                        if (index >= 0) {
                          newLignes[index].quantite_livree = parseInt(e.target.value) || 0;
                          setLignesLivraison(newLignes);
                        }
                      }}
                      className="w-20 px-2 py-1 border rounded text-center dark:bg-gray-600 dark:border-gray-500 dark:text-white"
                    />
                  </div>
                );
              })}
              <div className="pt-3">
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Notes livraison</label>
                <textarea
                  value={notesLivraison}
                  onChange={(e) => setNotesLivraison(e.target.value)}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  rows={2}
                  placeholder="Remarques, problèmes..."
                />
              </div>
            </div>
            <div className="flex justify-end gap-3 mt-6">
              <button
                onClick={() => setShowLivraisonModal(false)}
                className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
              >
                Annuler
              </button>
              <button
                onClick={handleEnregistrerLivraison}
                className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
              >
                Valider livraison
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
