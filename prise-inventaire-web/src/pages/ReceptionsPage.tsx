import { useEffect, useState, useCallback } from 'react';
import { Package, Truck, Calendar, CheckCircle, History } from 'lucide-react';
import {
  getCommandesEnAttente,
  getLignesEnAttente,
  getSecteurs
} from '@/services/api';
import type { ComFourEntete, ComFourLigne } from '@/services/api';
import type { Secteur } from '@/types';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import FenetreReception from '@/components/receptions/FenetreReception';
import HistoriqueReceptions from '@/components/receptions/HistoriqueReceptions';

export default function ReceptionsPage() {
  const { t } = useLanguage();
  const tr = t.receipts;
  const { peut } = usePermissions();
  const [onglet, setOnglet] = useState<'attente' | 'historique'>('attente');
  const [commandesEnAttente, setCommandesEnAttente] = useState<ComFourEntete[]>([]);
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedCommande, setSelectedCommande] = useState<ComFourEntete | null>(null);
  const [lignesEnAttente, setLignesEnAttente] = useState<ComFourLigne[]>([]);
  const [showReceptionModal, setShowReceptionModal] = useState(false);
  const [message, setMessage] = useState('');

  const loadData = useCallback(async () => {
    try {
      const [commandesRes, secteursRes] = await Promise.all([
        getCommandesEnAttente(),
        getSecteurs(),
      ]);
      setCommandesEnAttente(commandesRes || []);
      setSecteurs(secteursRes || []);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  async function selectCommande(commande: ComFourEntete) {
    setSelectedCommande(commande);
    try {
      const lignes = await getLignesEnAttente(commande.id);
      setLignesEnAttente(lignes || []);
    } catch (error) {
      console.error('Erreur:', error);
    }
  }

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Réception des arrivages</h1>
        <div className="flex rounded-lg border dark:border-gray-600 overflow-hidden text-sm" role="tablist">
          {([['attente', tr.tabPending, Truck], ['historique', tr.tabHistory, History]] as const).map(([cle, libelle, Icone]) => (
            <button key={cle} type="button" role="tab" aria-selected={onglet === cle} onClick={() => setOnglet(cle)}
              className={`flex items-center gap-1.5 px-4 py-2 ${onglet === cle ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'}`}>
              <Icone size={16} /> {libelle}
            </button>
          ))}
        </div>
      </div>

      {message && (
        <div className="mb-4 px-4 py-2 rounded-lg text-sm bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200" role="status">{message}</div>
      )}

      {onglet === 'historique' ? <HistoriqueReceptions /> : (
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Liste des commandes en attente */}
        <div className="lg:col-span-1">
          <div className="bg-white rounded-xl shadow-sm">
            <div className="p-4 border-b">
              <h2 className="font-semibold text-gray-700 flex items-center gap-2">
                <Truck size={18} />
                Commandes en attente
              </h2>
            </div>
            {loading ? (
              <div className="p-4 text-gray-500">Chargement...</div>
            ) : commandesEnAttente.length === 0 ? (
              <div className="p-4 text-gray-500">Aucune commande en attente</div>
            ) : (
              <div className="divide-y max-h-[60vh] overflow-y-auto">
                {commandesEnAttente.map((commande) => (
                  <button
                    key={commande.id}
                    onClick={() => selectCommande(commande)}
                    className={`w-full p-4 text-left hover:bg-gray-50 transition-colors ${
                      selectedCommande?.id === commande.id ? 'bg-blue-50 border-l-4 border-blue-500' : ''
                    }`}
                  >
                    <div className="font-mono font-medium text-sm">{commande.numero}</div>
                    <div className="text-sm text-gray-600 mt-1">{commande.fournisseur?.raison_sociale}</div>
                    <div className="flex items-center gap-2 mt-2 text-xs text-gray-500">
                      <Calendar size={12} />
                      {commande.date_livraison_prevue
                        ? new Date(commande.date_livraison_prevue).toLocaleDateString('fr-FR')
                        : 'Non définie'}
                    </div>
                    <span className={`inline-block mt-2 px-2 py-0.5 rounded text-xs font-medium ${
                      commande.statut === 'partielle'
                        ? 'bg-yellow-100 text-yellow-700'
                        : 'bg-blue-100 text-blue-700'
                    }`}>
                      {commande.statut === 'partielle' ? 'Partielle' : 'En attente'}
                    </span>
                  </button>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Détail de la commande sélectionnée */}
        <div className="lg:col-span-2">
          {selectedCommande ? (
            <div className="bg-white rounded-xl shadow-sm">
              <div className="p-4 border-b flex items-center justify-between">
                <div>
                  <h2 className="font-semibold text-gray-700">
                    Commande {selectedCommande.numero}
                  </h2>
                  <p className="text-sm text-gray-500">{selectedCommande.fournisseur?.raison_sociale}</p>
                </div>
                {peut('receptions', 'creer') && (
                  <button
                    onClick={() => { setMessage(''); setShowReceptionModal(true); }}
                    disabled={lignesEnAttente.length === 0}
                    className="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors disabled:opacity-50"
                  >
                    <CheckCircle size={18} />
                    {tr.receive}
                  </button>
                )}
              </div>

              {lignesEnAttente.length === 0 ? (
                <div className="p-4 text-gray-500">Chargement des lignes...</div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full">
                    <thead className="bg-gray-50 border-b">
                      <tr>
                        <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Produit</th>
                        <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">Commandé</th>
                        <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">Reçu</th>
                        <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">Restant</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {lignesEnAttente.map((ligne) => {
                        const hasCond = ligne.unite_achat && (ligne.qte_par_unite_achat ?? 1) > 1;
                        const restant = ligne.quantite_commandee - ligne.quantite_recue;
                        return (
                        <tr key={ligne.id} className="hover:bg-gray-50">
                          <td className="px-4 py-3">
                            <div className="flex items-center gap-2">
                              <Package size={16} className="text-gray-400" />
                              <span className="text-sm">{ligne.produit?.description || `Produit #${ligne.produit_id}`}</span>
                            </div>
                          </td>
                          <td className="px-4 py-3 text-sm text-right">
                            {ligne.quantite_commandee}
                            {hasCond && <div className="text-xs text-orange-500">{ligne.unite_achat}</div>}
                          </td>
                          <td className="px-4 py-3 text-sm text-right text-green-600">{ligne.quantite_recue}</td>
                          <td className="px-4 py-3 text-right font-medium text-orange-600">
                            <div className="text-sm">{restant}</div>
                            {hasCond && (
                              <div className="text-xs text-orange-400">
                                = {restant * (ligne.qte_par_unite_achat ?? 1)} {ligne.produit?.mesure ?? 'UN'}
                              </div>
                            )}
                          </td>
                        </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          ) : (
            <div className="bg-white rounded-xl shadow-sm p-8 text-center text-gray-500">
              <Truck size={48} className="mx-auto mb-4 text-gray-300" />
              <p>Sélectionnez une commande pour voir les détails</p>
            </div>
          )}
        </div>
      </div>
      )}

      {/* Fenêtre de réception : commande choisie, et d'autres du même fournisseur au besoin */}
      {showReceptionModal && selectedCommande && (
        <FenetreReception
          commande={selectedCommande}
          lignes={lignesEnAttente}
          secteurs={secteurs}
          commandesEnAttente={commandesEnAttente}
          onClose={() => setShowReceptionModal(false)}
          onEnregistree={(resultat) => {
            setShowReceptionModal(false);
            setSelectedCommande(null);
            setLignesEnAttente([]);
            setMessage(resultat.message);
            loadData();
          }}
        />
      )}
    </div>
  );
}
