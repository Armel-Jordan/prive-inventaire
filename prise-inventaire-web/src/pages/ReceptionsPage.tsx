import { useEffect, useState, useCallback } from 'react';
import { Package, Truck, Calendar, CheckCircle, X } from 'lucide-react';
import { 
  getCommandesEnAttente, 
  getLignesEnAttente,
  createReceptionMultiple,
  getSecteurs
} from '@/services/api';
import type { ComFourEntete, ComFourLigne } from '@/services/api';
import type { Secteur } from '@/types';

interface ReceptionForm {
  com_four_ligne_id: number;
  quantite_recue: number;
  quantite_restante: number;
  produit_description: string;
  secteur_id: number | null;
  lot_numero: string;
  date_peremption: string;
  notes: string;
}

export default function ReceptionsPage() {
  const [commandesEnAttente, setCommandesEnAttente] = useState<ComFourEntete[]>([]);
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedCommande, setSelectedCommande] = useState<ComFourEntete | null>(null);
  const [lignesEnAttente, setLignesEnAttente] = useState<ComFourLigne[]>([]);
  const [showReceptionModal, setShowReceptionModal] = useState(false);
  const [dateReception, setDateReception] = useState(new Date().toISOString().split('T')[0]);
  const [receptionForms, setReceptionForms] = useState<ReceptionForm[]>([]);
  const [submitting, setSubmitting] = useState(false);

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

  function openReceptionModal() {
    const forms: ReceptionForm[] = lignesEnAttente.map(ligne => ({
      com_four_ligne_id: ligne.id!,
      quantite_recue: ligne.quantite_commandee - ligne.quantite_recue,
      quantite_restante: ligne.quantite_commandee - ligne.quantite_recue,
      produit_description: ligne.produit?.description || `Produit #${ligne.produit_id}`,
      secteur_id: null,
      lot_numero: '',
      date_peremption: '',
      notes: '',
    }));
    setReceptionForms(forms);
    setDateReception(new Date().toISOString().split('T')[0]);
    setShowReceptionModal(true);
  }

  function updateReceptionForm(index: number, field: keyof ReceptionForm, value: string | number | null) {
    const newForms = [...receptionForms];
    newForms[index] = { ...newForms[index], [field]: value };
    setReceptionForms(newForms);
  }

  async function handleSubmitReception(e: React.FormEvent) {
    e.preventDefault();
    if (!selectedCommande) return;

    const receptions = receptionForms
      .filter(f => f.quantite_recue > 0)
      .map(f => ({
        com_four_ligne_id: f.com_four_ligne_id,
        quantite_recue: f.quantite_recue,
        secteur_id: f.secteur_id || undefined,
        lot_numero: f.lot_numero || undefined,
        date_peremption: f.date_peremption || undefined,
        notes: f.notes || undefined,
      }));

    if (receptions.length === 0) {
      alert('Veuillez saisir au moins une quantité reçue');
      return;
    }

    setSubmitting(true);
    try {
      await createReceptionMultiple({
        commande_id: selectedCommande.id,
        date_reception: dateReception,
        receptions,
      });
      setShowReceptionModal(false);
      setSelectedCommande(null);
      setLignesEnAttente([]);
      loadData();
      alert('Réception enregistrée avec succès');
    } catch (error) {
      console.error('Erreur:', error);
      alert('Erreur lors de l\'enregistrement de la réception');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h1 className="text-2xl font-bold text-gray-800">Réception des arrivages</h1>
      </div>

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
                <button
                  onClick={openReceptionModal}
                  disabled={lignesEnAttente.length === 0}
                  className="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors disabled:opacity-50"
                >
                  <CheckCircle size={18} />
                  Réceptionner
                </button>
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
                      {lignesEnAttente.map((ligne) => (
                        <tr key={ligne.id} className="hover:bg-gray-50">
                          <td className="px-4 py-3">
                            <div className="flex items-center gap-2">
                              <Package size={16} className="text-gray-400" />
                              <span className="text-sm">{ligne.produit?.description || `Produit #${ligne.produit_id}`}</span>
                            </div>
                          </td>
                          <td className="px-4 py-3 text-sm text-right">{ligne.quantite_commandee}</td>
                          <td className="px-4 py-3 text-sm text-right text-green-600">{ligne.quantite_recue}</td>
                          <td className="px-4 py-3 text-sm text-right font-medium text-orange-600">
                            {ligne.quantite_commandee - ligne.quantite_recue}
                          </td>
                        </tr>
                      ))}
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

      {/* Modal de réception */}
      {showReceptionModal && selectedCommande && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-4xl mx-4 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between p-4 border-b sticky top-0 bg-white">
              <div>
                <h2 className="text-lg font-semibold">Réception - {selectedCommande.numero}</h2>
                <p className="text-sm text-gray-500">{selectedCommande.fournisseur?.raison_sociale}</p>
              </div>
              <button onClick={() => setShowReceptionModal(false)} className="p-1 hover:bg-gray-100 rounded">
                <X size={20} />
              </button>
            </div>
            <form onSubmit={handleSubmitReception} className="p-4 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Date de réception</label>
                <input
                  type="date"
                  value={dateReception}
                  onChange={(e) => setDateReception(e.target.value)}
                  className="px-3 py-2 border rounded-lg"
                  required
                />
              </div>

              <div className="border rounded-lg overflow-hidden">
                <table className="w-full">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-3 py-2 text-left text-xs font-medium text-gray-600">Produit</th>
                      <th className="px-3 py-2 text-center text-xs font-medium text-gray-600 w-20">Restant</th>
                      <th className="px-3 py-2 text-center text-xs font-medium text-gray-600 w-24">Qté reçue</th>
                      <th className="px-3 py-2 text-left text-xs font-medium text-gray-600 w-40">Secteur</th>
                      <th className="px-3 py-2 text-left text-xs font-medium text-gray-600 w-28">N° Lot</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {receptionForms.map((form, index) => (
                      <tr key={form.com_four_ligne_id}>
                        <td className="px-3 py-2 text-sm">{form.produit_description}</td>
                        <td className="px-3 py-2 text-center text-sm font-medium text-orange-600">
                          {form.quantite_restante}
                        </td>
                        <td className="px-3 py-2">
                          <input
                            type="number"
                            value={form.quantite_recue}
                            onChange={(e) => updateReceptionForm(index, 'quantite_recue', Number(e.target.value))}
                            className="w-full px-2 py-1 border rounded text-sm text-center"
                            min={0}
                            max={form.quantite_restante}
                          />
                        </td>
                        <td className="px-3 py-2">
                          <select
                            value={form.secteur_id || ''}
                            onChange={(e) => updateReceptionForm(index, 'secteur_id', e.target.value ? Number(e.target.value) : null)}
                            className="w-full px-2 py-1 border rounded text-sm"
                          >
                            <option value="">Par défaut</option>
                            {secteurs.map(s => (
                              <option key={s.id} value={s.id}>{s.code} - {s.nom}</option>
                            ))}
                          </select>
                        </td>
                        <td className="px-3 py-2">
                          <input
                            type="text"
                            value={form.lot_numero}
                            onChange={(e) => updateReceptionForm(index, 'lot_numero', e.target.value)}
                            className="w-full px-2 py-1 border rounded text-sm"
                            placeholder="Optionnel"
                          />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <div className="flex gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setShowReceptionModal(false)}
                  className="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50"
                  disabled={submitting}
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  className="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
                  disabled={submitting}
                >
                  {submitting ? 'Enregistrement...' : 'Enregistrer la réception'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
