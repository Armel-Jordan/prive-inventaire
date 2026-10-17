import BoutonPiecesJointes from '@/components/BoutonPiecesJointes';
import { useState, useEffect } from 'react';
import BoutonChampsPersonnalises from '@/components/BoutonChampsPersonnalises';
import { Plus, Search, Edit, Trash2, Building2, Phone, Mail, MapPin } from 'lucide-react';
import BalisesFiche from '@/components/BalisesFiche';
import {
  getClients,
  createClient,
  updateClient,
  deleteClient,
  getConfiguration,
} from '../services/api';
import type { Client, FicheReferentiel } from '../services/api';
import { getReferentiel } from '../services/api';
import { CelluleCodeClient, FiltresClients, type FiltresListeClients } from '@/components/fiche-client/ListeClientsFiche';
import Pagination from '@/components/Pagination';
import FiltresMemorises from '@/components/FiltresMemorises';
import { texteFiltre } from '@/services/filtresMemorises';
import { PastilleClientDePassage } from '@/components/ClientDePassage';
import { signaler } from '@/lib/erreurs';
import { formatMontant, useCodeDevise, useDevise } from '@/lib/documents';
import { getFrais, type Frais } from '@/services/frais';
import { useLanguage } from '@/i18n/useLanguage';

interface ConfigNumero {
  auto_increment: boolean;
  prefixe: string;
  suffixe: string;
  longueur: number;
  separateur: string;
  prochain_numero: number;
}

export default function ClientsPage() {
  const devise = useDevise();
  const montant = (v: number | string | null | undefined) => formatMontant(v, devise);
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({ dernierePage: 1, total: 0 });
  const [clients, setClients] = useState<Client[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [editingClient, setEditingClient] = useState<Client | null>(null);
  const [configNumero, setConfigNumero] = useState<ConfigNumero | null>(null);
  const [filtres, setFiltres] = useState<FiltresListeClients>({});
  const [formData, setFormData] = useState({
    code: '',
    raison_sociale: '',
    adresse_facturation: '',
    adresse_livraison: '',
    ville: '',
    code_postal: '',
    telephone: '',
    email: '',
    contact_nom: '',
    contact_telephone: '',
    encours_max: '',
    taux_remise_global: '0',
    condition_paiement_id: '',
    devise: '',
    frais_livraison_id: '',
    itineraire_id: '',
    sequence_livraison: '',
  });
  // Conditions de paiement et devises du référentiel, frais de livraison.
  const [fraisLivraison, setFraisLivraison] = useState<Frais[]>([]);
  // Routes de livraison (référentiel des itinéraires).
  const [itineraires, setItineraires] = useState<FicheReferentiel[]>([]);
  const [conditions, setConditions] = useState<FicheReferentiel[]>([]);
  const [devises, setDevises] = useState<string[]>([]);
  const deviseEntreprise = useCodeDevise();
  const { t } = useLanguage();

  async function loadConfig() {
    try {
      const config = await getConfiguration('client');
      setConfigNumero(config);
    } catch (error) {
      console.error('Erreur chargement config:', error);
    }
  }

  useEffect(() => {
    loadClients();
    loadConfig();
    getReferentiel('conditions_paiement', true).then(setConditions, () => {});
    getReferentiel('devises', true).then(liste => setDevises(liste.map(d => String(d.code ?? '').toUpperCase()).filter(Boolean)), () => {});
    getFrais({ application: 'livraison' }).then(setFraisLivraison, () => {});
    getReferentiel('itineraires', true).then(setItineraires, () => {});
  }, []);

  const loadClients = async () => {
    try {
      setLoading(true);
      const response = await getClients({ search: search || undefined, ...filtres, page });
      setClients(response.data || []);
      setPagination({ dernierePage: response.last_page ?? 1, total: response.total ?? 0 });
    } catch (error) {
      console.error('Erreur chargement clients:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    const timer = setTimeout(() => {
      loadClients();
    }, 300);
    return () => clearTimeout(timer);
  }, [search, filtres, page]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const data = {
        ...formData,
        encours_max: formData.encours_max ? parseFloat(formData.encours_max) : undefined,
        taux_remise_global: parseFloat(formData.taux_remise_global) || 0,
        condition_paiement_id: formData.condition_paiement_id ? Number(formData.condition_paiement_id) : null,
        // Vide : la devise de l'entreprise.
        devise: formData.devise || null,
        frais_livraison_id: formData.frais_livraison_id ? Number(formData.frais_livraison_id) : null,
        itineraire_id: formData.itineraire_id ? Number(formData.itineraire_id) : null,
        sequence_livraison: formData.sequence_livraison === '' ? null : Number(formData.sequence_livraison),
      };

      if (editingClient) {
        await updateClient(editingClient.id, data);
      } else {
        await createClient(data);
      }
      setShowModal(false);
      resetForm();
      loadClients();
    } catch (error) {
      signaler(error, 'Erreur lors de l\'enregistrement du client');
    }
  };

  const handleEdit = (client: Client) => {
    setEditingClient(client);
    setFormData({
      code: client.code,
      raison_sociale: client.raison_sociale,
      adresse_facturation: client.adresse_facturation,
      adresse_livraison: client.adresse_livraison || '',
      ville: client.ville,
      code_postal: client.code_postal,
      telephone: client.telephone || '',
      email: client.email || '',
      contact_nom: client.contact_nom || '',
      contact_telephone: client.contact_telephone || '',
      encours_max: client.encours_max?.toString() || '',
      taux_remise_global: client.taux_remise_global?.toString() || '0',
      condition_paiement_id: client.condition_paiement_id ? String(client.condition_paiement_id) : '',
      devise: client.devise ?? '',
      frais_livraison_id: client.frais_livraison_id ? String(client.frais_livraison_id) : '',
      itineraire_id: client.itineraire_id ? String(client.itineraire_id) : '',
      sequence_livraison: client.sequence_livraison != null ? String(client.sequence_livraison) : '',
    });
    setShowModal(true);
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Supprimer ce client ?')) return;
    try {
      await deleteClient(id);
      loadClients();
    } catch (error) {
      signaler(error, 'Impossible de supprimer ce client');
    }
  };

  const resetForm = () => {
    setEditingClient(null);
    setFormData({
      code: '',
      raison_sociale: '',
      adresse_facturation: '',
      adresse_livraison: '',
      ville: '',
      code_postal: '',
      telephone: '',
      email: '',
      contact_nom: '',
      contact_telephone: '',
      encours_max: '',
      taux_remise_global: '0',
      condition_paiement_id: '',
      devise: '',
      frais_livraison_id: '',
      itineraire_id: '',
      sequence_livraison: '',
    });
  };

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Clients</h1>
        <button
          onClick={() => { resetForm(); setShowModal(true); }}
          className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700"
        >
          <Plus size={20} />
          Ajouter
        </button>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
        <div className="relative">
          <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400" size={20} />
          <input
            type="text"
            placeholder="Rechercher par nom, code ou ville..."
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            className="w-full pl-10 pr-4 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
        </div>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <FiltresClients valeur={filtres} onChange={f => { setFiltres(f); setPage(1); }} />
          {/* Filtres mémorisés : recherche, catégorie, favoris. */}
          <div className="mt-3">
            <FiltresMemorises ecran="clients" valeurs={{ recherche: search, categorie_id: filtres.categorie_id ?? null, favoris: filtres.favoris ?? null }}
              onAppliquer={v => {
                setSearch(texteFiltre(v.recherche));
                setFiltres({ categorie_id: typeof v.categorie_id === 'number' ? v.categorie_id : undefined, favoris: v.favoris === true ? true : undefined });
                setPage(1);
              }} />
          </div>
        </div>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
        {loading ? (
          <div className="p-8 text-center text-gray-500">Chargement...</div>
        ) : clients.length === 0 ? (
          <div className="p-8 text-center text-gray-500">Aucun client trouvé</div>
        ) : (
          <table className="w-full">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Code</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Raison sociale</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Ville</th>
                <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Contact</th>
                <th className="px-4 py-3 text-right text-sm font-medium text-gray-600 dark:text-gray-300">Encours</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Statut</th>
                <th className="px-4 py-3 text-center text-sm font-medium text-gray-600 dark:text-gray-300">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {clients.map((client) => (
                <tr key={client.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                  <td className="px-4 py-3 text-sm text-gray-900 dark:text-white">
                    <CelluleCodeClient client={client} onFavori={() => { if (filtres.favoris) loadClients(); }} />
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <Building2 size={16} className="text-gray-400" />
                      <span className="text-sm text-gray-900 dark:text-white">{client.raison_sociale}<PastilleClientDePassage client={client} /></span>
                    </div>
                    <BalisesFiche entite="client" entiteId={client.id} />
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <MapPin size={16} className="text-gray-400" />
                      <span className="text-sm text-gray-600 dark:text-gray-300">{client.ville}</span>
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    <div className="text-sm">
                      {client.telephone && (
                        <div className="flex items-center gap-1 text-gray-600 dark:text-gray-300">
                          <Phone size={12} /> {client.telephone}
                        </div>
                      )}
                      {client.email && (
                        <div className="flex items-center gap-1 text-gray-500 dark:text-gray-400">
                          <Mail size={12} /> {client.email}
                        </div>
                      )}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="text-sm">
                      <span className={`font-medium ${
                        client.encours_max && Number(client.encours_actuel) > Number(client.encours_max) * 0.8
                          ? 'text-red-600'
                          : 'text-gray-900 dark:text-white'
                      }`}>
                        {montant(Number(client.encours_actuel))}
                      </span>
                      {client.encours_max && (
                        <div className="text-xs text-gray-500">/ {montant(Number(client.encours_max))}</div>
                      )}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-center">
                    <span className={`px-2 py-1 text-xs rounded-full ${
                      client.actif
                        ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
                        : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                    }`}>
                      {client.actif ? 'Actif' : 'Inactif'}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex justify-center gap-2">
                      <BoutonPiecesJointes entite="client" entiteId={client.id} titre={client.raison_sociale} />
                      <button
                        onClick={() => handleEdit(client)}
                        className="p-1 text-blue-600 hover:bg-blue-50 rounded"
                        title="Modifier"
                        aria-label={`Modifier ${client.raison_sociale}`}
                      >
                        <Edit size={18} />
                      </button>
                      <button
                        onClick={() => handleDelete(client.id)}
                        className="p-1 text-red-600 hover:bg-red-50 rounded"
                        title="Supprimer"
                        aria-label={`Supprimer ${client.raison_sociale}`}
                      >
                        <Trash2 size={18} />
                      </button>
                      <BoutonChampsPersonnalises entite="client" entiteId={client.id} titre={client.raison_sociale} />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Pagination page={page} dernierePage={pagination.dernierePage} total={pagination.total} onPage={setPage} />
      </div>

      {showModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <h2 className="text-xl font-bold mb-4 dark:text-white">
              {editingClient ? 'Modifier le client' : 'Nouveau client'}
            </h2>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">
                    Code {!editingClient && configNumero?.auto_increment ? '(auto-généré)' : '*'}
                  </label>
                  <input
                    type="text"
                    value={formData.code}
                    onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white disabled:bg-gray-100 dark:disabled:bg-gray-600"
                    required={!!editingClient || !configNumero?.auto_increment}
                    disabled={!!editingClient}
                    placeholder={!editingClient && configNumero?.auto_increment ? 'Généré automatiquement' : ''}
                  />
                  {!editingClient && configNumero?.auto_increment && (
                    <p className="text-xs text-gray-500 mt-1">
                      Format: {configNumero.prefixe}{configNumero.separateur || ''}{String(configNumero.prochain_numero).padStart(configNumero.longueur, '0')}{configNumero.suffixe ? (configNumero.separateur || '') + configNumero.suffixe : ''}
                    </p>
                  )}
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Raison sociale *</label>
                  <input
                    type="text"
                    required
                    value={formData.raison_sociale}
                    onChange={(e) => setFormData({ ...formData, raison_sociale: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div className="col-span-2">
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Adresse facturation *</label>
                  <textarea
                    required
                    value={formData.adresse_facturation}
                    onChange={(e) => setFormData({ ...formData, adresse_facturation: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                    rows={2}
                  />
                </div>
                <div className="col-span-2">
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Adresse livraison</label>
                  <textarea
                    value={formData.adresse_livraison}
                    onChange={(e) => setFormData({ ...formData, adresse_livraison: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                    rows={2}
                    placeholder="Laisser vide si identique à l'adresse de facturation"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Ville *</label>
                  <input
                    type="text"
                    required
                    value={formData.ville}
                    onChange={(e) => setFormData({ ...formData, ville: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Code postal *</label>
                  <input
                    type="text"
                    required
                    value={formData.code_postal}
                    onChange={(e) => setFormData({ ...formData, code_postal: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Téléphone</label>
                  <input
                    type="tel"
                    value={formData.telephone}
                    onChange={(e) => setFormData({ ...formData, telephone: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Email</label>
                  <input
                    type="email"
                    value={formData.email}
                    onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Nom du contact</label>
                  <input
                    type="text"
                    value={formData.contact_nom}
                    onChange={(e) => setFormData({ ...formData, contact_nom: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Tél. contact</label>
                  <input
                    type="tel"
                    value={formData.contact_telephone}
                    onChange={(e) => setFormData({ ...formData, contact_telephone: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Encours max{devise ? ` (${devise})` : ''}</label>
                  <input
                    type="number"
                    step="0.01"
                    value={formData.encours_max}
                    onChange={(e) => setFormData({ ...formData, encours_max: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                    placeholder="Illimité si vide"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Remise globale (%)</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    max="100"
                    value={formData.taux_remise_global}
                    onChange={(e) => setFormData({ ...formData, taux_remise_global: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Condition de paiement</label>
                  <select
                    value={formData.condition_paiement_id}
                    onChange={(e) => setFormData({ ...formData, condition_paiement_id: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  >
                    <option value="">Condition par défaut de l'entreprise</option>
                    {conditions.map(c => <option key={c.id} value={c.id}>{String(c.libelle)}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">Devise du client</label>
                  <select
                    value={formData.devise}
                    onChange={(e) => setFormData({ ...formData, devise: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  >
                    <option value="">{deviseEntreprise} (devise de l'entreprise)</option>
                    {[...new Set([...devises, formData.devise].filter(c => c && c !== deviseEntreprise))].map(c => <option key={c} value={c}>{c}</option>)}
                  </select>
                  <span className="text-xs text-gray-500">Reprise par ses devis, commandes et factures.</span>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1 dark:text-gray-300">{t.fees.clientDeliveryFee}</label>
                  <select
                    value={formData.frais_livraison_id}
                    onChange={(e) => setFormData({ ...formData, frais_livraison_id: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  >
                    <option value="">{t.fees.noDeliveryFee}</option>
                    {fraisLivraison.filter(f => f.actif || String(f.id) === formData.frais_livraison_id).map(f => <option key={f.id} value={f.id}>{f.libelle}</option>)}
                  </select>
                  <span className="text-xs text-gray-500">{t.fees.clientDeliveryFeeHelp}</span>
                </div>
                <div>
                  <label htmlFor="client-route" className="block text-sm font-medium mb-1 dark:text-gray-300">{t.sequences.route}</label>
                  <select id="client-route" value={formData.itineraire_id} onChange={(e) => setFormData({ ...formData, itineraire_id: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                    <option value="">{t.sequences.noRoute}</option>
                    {itineraires.map(i => <option key={String(i.id)} value={String(i.id)}>{String(i.code)} — {String(i.nom)}</option>)}
                  </select>
                </div>
                <div>
                  <label htmlFor="client-sequence" className="block text-sm font-medium mb-1 dark:text-gray-300">{t.sequences.sequence}</label>
                  <input id="client-sequence" type="number" min={0} max={999999} value={formData.sequence_livraison}
                    onChange={(e) => setFormData({ ...formData, sequence_livraison: e.target.value })}
                    className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                  <span className="text-xs text-gray-500">{t.sequences.sequenceHelp}</span>
                </div>
              </div>
              <div className="flex justify-end gap-3 mt-6">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700"
                >
                  {editingClient ? 'Modifier' : 'Créer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
