import { useState, useEffect, useCallback } from 'react';
import { Plus, Edit, Trash2, Tag, Percent } from 'lucide-react';
import { getClientsActifs, getProduits } from '../services/api';
import type { Client } from '../services/api';
import type { Produit } from '../types';

interface TarifClient {
  id: number;
  client_id: number;
  client?: Client;
  produit_id: number;
  produit?: Produit;
  prix_special: number;
  date_debut?: string;
  date_fin?: string;
  actif: boolean;
}

interface Promotion {
  id: number;
  nom: string;
  type: 'pourcentage' | 'montant_fixe';
  valeur: number;
  produit_id?: number;
  produit?: Produit;
  date_debut: string;
  date_fin: string;
  actif: boolean;
}

export default function GestionPrixPage() {
  const [activeTab, setActiveTab] = useState<'tarifs' | 'promotions'>('tarifs');
  const [clients, setClients] = useState<Client[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [tarifs, setTarifs] = useState<TarifClient[]>([]);
  const [promotions, setPromotions] = useState<Promotion[]>([]);
  const [loading, setLoading] = useState(true);
  const [showTarifModal, setShowTarifModal] = useState(false);
  const [showPromoModal, setShowPromoModal] = useState(false);
  const [editingTarif, setEditingTarif] = useState<TarifClient | null>(null);
  const [editingPromo, setEditingPromo] = useState<Promotion | null>(null);

  const [tarifForm, setTarifForm] = useState({
    client_id: '',
    produit_id: '',
    prix_special: '',
    date_debut: '',
    date_fin: '',
  });

  const [promoForm, setPromoForm] = useState({
    nom: '',
    type: 'pourcentage' as 'pourcentage' | 'montant_fixe',
    valeur: '',
    produit_id: '',
    date_debut: new Date().toISOString().split('T')[0],
    date_fin: '',
  });

  const loadData = useCallback(async () => {
    try {
      setLoading(true);
      const [clientsRes, produitsRes] = await Promise.all([
        getClientsActifs(),
        getProduits(),
      ]);
      setClients(clientsRes || []);
      setProduits(produitsRes || []);

      // Données de démo
      if (clientsRes?.length && produitsRes?.length) {
        setTarifs([
          {
            id: 1,
            client_id: clientsRes[0]?.id,
            client: clientsRes[0],
            produit_id: produitsRes[0]?.id || 1,
            produit: produitsRes[0],
            prix_special: 45.00,
            actif: true,
          },
        ]);
        setPromotions([
          {
            id: 1,
            nom: 'Promo Mars',
            type: 'pourcentage',
            valeur: 10,
            produit_id: produitsRes[0]?.id,
            produit: produitsRes[0],
            date_debut: '2026-03-01',
            date_fin: '2026-03-31',
            actif: true,
          },
        ]);
      }
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const handleSaveTarif = (e: React.FormEvent) => {
    e.preventDefault();
    const client = clients.find(c => c.id === parseInt(tarifForm.client_id));
    const produit = produits.find(p => p.id === parseInt(tarifForm.produit_id));
    
    if (editingTarif) {
      setTarifs(tarifs.map(t => t.id === editingTarif.id ? {
        ...t,
        client_id: parseInt(tarifForm.client_id),
        client,
        produit_id: parseInt(tarifForm.produit_id),
        produit,
        prix_special: parseFloat(tarifForm.prix_special),
        date_debut: tarifForm.date_debut || undefined,
        date_fin: tarifForm.date_fin || undefined,
      } : t));
    } else {
      setTarifs([...tarifs, {
        id: tarifs.length + 1,
        client_id: parseInt(tarifForm.client_id),
        client,
        produit_id: parseInt(tarifForm.produit_id),
        produit,
        prix_special: parseFloat(tarifForm.prix_special),
        date_debut: tarifForm.date_debut || undefined,
        date_fin: tarifForm.date_fin || undefined,
        actif: true,
      }]);
    }
    setShowTarifModal(false);
    resetTarifForm();
  };

  const handleSavePromo = (e: React.FormEvent) => {
    e.preventDefault();
    const produit = promoForm.produit_id ? produits.find(p => p.id === parseInt(promoForm.produit_id)) : undefined;
    
    if (editingPromo) {
      setPromotions(promotions.map(p => p.id === editingPromo.id ? {
        ...p,
        nom: promoForm.nom,
        type: promoForm.type,
        valeur: parseFloat(promoForm.valeur),
        produit_id: promoForm.produit_id ? parseInt(promoForm.produit_id) : undefined,
        produit,
        date_debut: promoForm.date_debut,
        date_fin: promoForm.date_fin,
      } : p));
    } else {
      setPromotions([...promotions, {
        id: promotions.length + 1,
        nom: promoForm.nom,
        type: promoForm.type,
        valeur: parseFloat(promoForm.valeur),
        produit_id: promoForm.produit_id ? parseInt(promoForm.produit_id) : undefined,
        produit,
        date_debut: promoForm.date_debut,
        date_fin: promoForm.date_fin,
        actif: true,
      }]);
    }
    setShowPromoModal(false);
    resetPromoForm();
  };

  const handleEditTarif = (tarif: TarifClient) => {
    setEditingTarif(tarif);
    setTarifForm({
      client_id: tarif.client_id.toString(),
      produit_id: tarif.produit_id.toString(),
      prix_special: tarif.prix_special.toString(),
      date_debut: tarif.date_debut || '',
      date_fin: tarif.date_fin || '',
    });
    setShowTarifModal(true);
  };

  const handleEditPromo = (promo: Promotion) => {
    setEditingPromo(promo);
    setPromoForm({
      nom: promo.nom,
      type: promo.type,
      valeur: promo.valeur.toString(),
      produit_id: promo.produit_id?.toString() || '',
      date_debut: promo.date_debut,
      date_fin: promo.date_fin,
    });
    setShowPromoModal(true);
  };

  const handleDeleteTarif = (id: number) => {
    if (confirm('Supprimer ce tarif ?')) {
      setTarifs(tarifs.filter(t => t.id !== id));
    }
  };

  const handleDeletePromo = (id: number) => {
    if (confirm('Supprimer cette promotion ?')) {
      setPromotions(promotions.filter(p => p.id !== id));
    }
  };

  const resetTarifForm = () => {
    setEditingTarif(null);
    setTarifForm({ client_id: '', produit_id: '', prix_special: '', date_debut: '', date_fin: '' });
  };

  const resetPromoForm = () => {
    setEditingPromo(null);
    setPromoForm({ nom: '', type: 'pourcentage', valeur: '', produit_id: '', date_debut: new Date().toISOString().split('T')[0], date_fin: '' });
  };

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Gestion des Prix</h1>
        <button
          onClick={() => activeTab === 'tarifs' ? (resetTarifForm(), setShowTarifModal(true)) : (resetPromoForm(), setShowPromoModal(true))}
          className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700"
        >
          <Plus size={20} />
          {activeTab === 'tarifs' ? 'Nouveau tarif' : 'Nouvelle promo'}
        </button>
      </div>

      {/* Tabs */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 mb-6">
        <div className="flex gap-2">
          <button
            onClick={() => setActiveTab('tarifs')}
            className={`flex items-center gap-2 px-4 py-2 rounded-lg font-medium ${
              activeTab === 'tarifs'
                ? 'bg-purple-600 text-white'
                : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'
            }`}
          >
            <Tag size={18} />
            Tarifs clients
          </button>
          <button
            onClick={() => setActiveTab('promotions')}
            className={`flex items-center gap-2 px-4 py-2 rounded-lg font-medium ${
              activeTab === 'promotions'
                ? 'bg-purple-600 text-white'
                : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'
            }`}
          >
            <Percent size={18} />
            Promotions
          </button>
        </div>
      </div>

      {/* Tableau */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
        {loading ? (
          <div className="p-8 text-center text-gray-500">Chargement...</div>
        ) : activeTab === 'tarifs' ? (
          tarifs.length === 0 ? (
            <div className="p-8 text-center text-gray-500">Aucun tarif spécial</div>
          ) : (
            <table className="w-full">
              <thead className="bg-gray-50 dark:bg-gray-700">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Client</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Produit</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Prix spécial</th>
                  <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Période</th>
                  <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {tarifs.map((tarif) => (
                  <tr key={tarif.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td className="px-4 py-3 text-sm text-gray-900 dark:text-white">{tarif.client?.raison_sociale}</td>
                    <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{tarif.produit?.description}</td>
                    <td className="px-4 py-3 text-right text-sm font-medium text-purple-600">
                      {tarif.prix_special.toFixed(2)} €
                    </td>
                    <td className="px-4 py-3 text-center text-sm text-gray-500">
                      {tarif.date_debut && tarif.date_fin 
                        ? `${tarif.date_debut} → ${tarif.date_fin}`
                        : 'Permanent'}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex justify-center gap-1">
                        <button onClick={() => handleEditTarif(tarif)} className="p-1 text-blue-600 hover:bg-blue-50 rounded">
                          <Edit size={18} />
                        </button>
                        <button onClick={() => handleDeleteTarif(tarif.id)} className="p-1 text-red-600 hover:bg-red-50 rounded">
                          <Trash2 size={18} />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )
        ) : (
          promotions.length === 0 ? (
            <div className="p-8 text-center text-gray-500">Aucune promotion</div>
          ) : (
            <table className="w-full">
              <thead className="bg-gray-50 dark:bg-gray-700">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Nom</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Produit</th>
                  <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Réduction</th>
                  <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Période</th>
                  <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Statut</th>
                  <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {promotions.map((promo) => {
                  const now = new Date();
                  const debut = new Date(promo.date_debut);
                  const fin = new Date(promo.date_fin);
                  const isActive = now >= debut && now <= fin;
                  return (
                    <tr key={promo.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                      <td className="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{promo.nom}</td>
                      <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                        {promo.produit?.description || 'Tous les produits'}
                      </td>
                      <td className="px-4 py-3 text-center">
                        <span className="px-2 py-1 bg-green-100 text-green-800 rounded-full text-sm font-medium">
                          {promo.type === 'pourcentage' ? `-${promo.valeur}%` : `-${promo.valeur}€`}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-center text-sm text-gray-500">
                        {promo.date_debut} → {promo.date_fin}
                      </td>
                      <td className="px-4 py-3 text-center">
                        <span className={`px-2 py-1 text-xs rounded-full ${
                          isActive ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'
                        }`}>
                          {isActive ? 'Active' : 'Inactive'}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex justify-center gap-1">
                          <button onClick={() => handleEditPromo(promo)} className="p-1 text-blue-600 hover:bg-blue-50 rounded">
                            <Edit size={18} />
                          </button>
                          <button onClick={() => handleDeletePromo(promo.id)} className="p-1 text-red-600 hover:bg-red-50 rounded">
                            <Trash2 size={18} />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )
        )}
      </div>

      {/* Modal Tarif */}
      {showTarifModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h2 className="text-xl font-bold mb-4 dark:text-white">
              {editingTarif ? 'Modifier le tarif' : 'Nouveau tarif client'}
            </h2>
            <form onSubmit={handleSaveTarif} className="space-y-4">
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Client *</label>
                <select
                  required
                  value={tarifForm.client_id}
                  onChange={(e) => setTarifForm({ ...tarifForm, client_id: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                >
                  <option value="">Sélectionner...</option>
                  {clients.map(c => (
                    <option key={c.id} value={c.id}>{c.raison_sociale}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Produit *</label>
                <select
                  required
                  value={tarifForm.produit_id}
                  onChange={(e) => setTarifForm({ ...tarifForm, produit_id: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                >
                  <option value="">Sélectionner...</option>
                  {produits.map(p => (
                    <option key={p.id} value={p.id}>{p.description}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Prix spécial (€) *</label>
                <input
                  type="number"
                  step="0.01"
                  required
                  value={tarifForm.prix_special}
                  onChange={(e) => setTarifForm({ ...tarifForm, prix_special: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Date début</label>
                  <input
                    type="date"
                    value={tarifForm.date_debut}
                    onChange={(e) => setTarifForm({ ...tarifForm, date_debut: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Date fin</label>
                  <input
                    type="date"
                    value={tarifForm.date_fin}
                    onChange={(e) => setTarifForm({ ...tarifForm, date_fin: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
              </div>
              <div className="flex justify-end gap-3 mt-6">
                <button type="button" onClick={() => setShowTarifModal(false)} className="px-4 py-2 border rounded-lg dark:border-gray-600 dark:text-white">
                  Annuler
                </button>
                <button type="submit" className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                  {editingTarif ? 'Modifier' : 'Créer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal Promotion */}
      {showPromoModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h2 className="text-xl font-bold mb-4 dark:text-white">
              {editingPromo ? 'Modifier la promotion' : 'Nouvelle promotion'}
            </h2>
            <form onSubmit={handleSavePromo} className="space-y-4">
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Nom *</label>
                <input
                  type="text"
                  required
                  value={promoForm.nom}
                  onChange={(e) => setPromoForm({ ...promoForm, nom: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  placeholder="Ex: Promo été 2026"
                />
              </div>
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Produit</label>
                <select
                  value={promoForm.produit_id}
                  onChange={(e) => setPromoForm({ ...promoForm, produit_id: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                >
                  <option value="">Tous les produits</option>
                  {produits.map(p => (
                    <option key={p.id} value={p.id}>{p.description}</option>
                  ))}
                </select>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Type *</label>
                  <select
                    value={promoForm.type}
                    onChange={(e) => setPromoForm({ ...promoForm, type: e.target.value as 'pourcentage' | 'montant_fixe' })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  >
                    <option value="pourcentage">Pourcentage</option>
                    <option value="montant_fixe">Montant fixe</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">
                    Valeur {promoForm.type === 'pourcentage' ? '(%)' : '(€)'} *
                  </label>
                  <input
                    type="number"
                    step="0.01"
                    required
                    value={promoForm.valeur}
                    onChange={(e) => setPromoForm({ ...promoForm, valeur: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Date début *</label>
                  <input
                    type="date"
                    required
                    value={promoForm.date_debut}
                    onChange={(e) => setPromoForm({ ...promoForm, date_debut: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Date fin *</label>
                  <input
                    type="date"
                    required
                    value={promoForm.date_fin}
                    onChange={(e) => setPromoForm({ ...promoForm, date_fin: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
              </div>
              <div className="flex justify-end gap-3 mt-6">
                <button type="button" onClick={() => setShowPromoModal(false)} className="px-4 py-2 border rounded-lg dark:border-gray-600 dark:text-white">
                  Annuler
                </button>
                <button type="submit" className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                  {editingPromo ? 'Modifier' : 'Créer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
