import { useState } from 'react';
import { Bell, AlertTriangle, Clock, CreditCard, Truck, Save, Plus, Trash2 } from 'lucide-react';

interface AlerteConfig {
  id: number;
  type: 'stock_bas' | 'commande_retard' | 'facture_impayee' | 'livraison_retard' | 'ct_expire';
  nom: string;
  seuil?: number;
  jours?: number;
  actif: boolean;
  email: boolean;
  push: boolean;
}

const TYPES_ALERTES = [
  { key: 'stock_bas', label: 'Stock bas', icon: AlertTriangle, color: 'text-red-600', description: 'Quand le stock descend sous le seuil minimum' },
  { key: 'commande_retard', label: 'Commande en retard', icon: Clock, color: 'text-yellow-600', description: 'Commandes non livrées après X jours' },
  { key: 'facture_impayee', label: 'Facture impayée', icon: CreditCard, color: 'text-orange-600', description: 'Factures non payées après X jours' },
  { key: 'livraison_retard', label: 'Livraison en retard', icon: Truck, color: 'text-purple-600', description: 'Bons de livraison non livrés' },
  { key: 'ct_expire', label: 'CT à renouveler', icon: Truck, color: 'text-blue-600', description: 'Contrôle technique camion expirant bientôt' },
];

export default function NotificationsConfigPage() {
  const [alertes, setAlertes] = useState<AlerteConfig[]>([
    { id: 1, type: 'stock_bas', nom: 'Alerte stock critique', seuil: 10, actif: true, email: true, push: true },
    { id: 2, type: 'facture_impayee', nom: 'Factures impayées > 30j', jours: 30, actif: true, email: true, push: false },
    { id: 3, type: 'commande_retard', nom: 'Commandes en retard', jours: 7, actif: true, email: false, push: true },
    { id: 4, type: 'ct_expire', nom: 'CT camions', jours: 30, actif: true, email: true, push: true },
  ]);
  const [loading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [showAddModal, setShowAddModal] = useState(false);
  const [newAlerte, setNewAlerte] = useState({
    type: 'stock_bas' as AlerteConfig['type'],
    seuil: '',
    jours: '',
  });

  
  const handleToggle = (id: number, field: 'actif' | 'email' | 'push') => {
    setAlertes(alertes.map(a => 
      a.id === id ? { ...a, [field]: !a[field] } : a
    ));
  };

  const handleUpdateSeuil = (id: number, value: number) => {
    setAlertes(alertes.map(a => 
      a.id === id ? { ...a, seuil: value, jours: value } : a
    ));
  };

  const handleDelete = (id: number) => {
    if (confirm('Supprimer cette alerte ?')) {
      setAlertes(alertes.filter(a => a.id !== id));
    }
  };

  const handleAddAlerte = () => {
    const typeInfo = TYPES_ALERTES.find(t => t.key === newAlerte.type);
    const newConfig: AlerteConfig = {
      id: alertes.length + 1,
      type: newAlerte.type,
      nom: typeInfo?.label || 'Nouvelle alerte',
      seuil: newAlerte.seuil ? parseInt(newAlerte.seuil) : undefined,
      jours: newAlerte.jours ? parseInt(newAlerte.jours) : undefined,
      actif: true,
      email: true,
      push: true,
    };
    setAlertes([...alertes, newConfig]);
    setShowAddModal(false);
    setNewAlerte({ type: 'stock_bas', seuil: '', jours: '' });
  };

  const handleSave = async () => {
    setSaving(true);
    // Simuler sauvegarde
    await new Promise(resolve => setTimeout(resolve, 500));
    setSaving(false);
    alert('Configuration sauvegardée !');
  };

  const getTypeInfo = (type: string) => {
    return TYPES_ALERTES.find(t => t.key === type) || TYPES_ALERTES[0];
  };

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Configuration des Alertes</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400">Gérez les notifications automatiques</p>
        </div>
        <div className="flex gap-2">
          <button
            onClick={() => setShowAddModal(true)}
            className="flex items-center gap-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-white px-4 py-2 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600"
          >
            <Plus size={20} />
            Ajouter
          </button>
          <button
            onClick={handleSave}
            disabled={saving}
            className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 disabled:opacity-50"
          >
            <Save size={20} />
            {saving ? 'Sauvegarde...' : 'Sauvegarder'}
          </button>
        </div>
      </div>

      {/* Résumé */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
          <div className="flex items-center gap-3">
            <Bell className="text-purple-600" size={24} />
            <div>
              <p className="text-sm text-gray-500">Alertes actives</p>
              <p className="text-xl font-bold dark:text-white">{alertes.filter(a => a.actif).length}</p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-blue-100 dark:bg-blue-900 rounded">📧</div>
            <div>
              <p className="text-sm text-gray-500">Notifications email</p>
              <p className="text-xl font-bold dark:text-white">{alertes.filter(a => a.email && a.actif).length}</p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-green-100 dark:bg-green-900 rounded">🔔</div>
            <div>
              <p className="text-sm text-gray-500">Notifications push</p>
              <p className="text-xl font-bold dark:text-white">{alertes.filter(a => a.push && a.actif).length}</p>
            </div>
          </div>
        </div>
      </div>

      {/* Liste des alertes */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
        {loading ? (
          <div className="p-8 text-center text-gray-500">Chargement...</div>
        ) : alertes.length === 0 ? (
          <div className="p-8 text-center text-gray-500">Aucune alerte configurée</div>
        ) : (
          <div className="divide-y divide-gray-200 dark:divide-gray-700">
            {alertes.map((alerte) => {
              const typeInfo = getTypeInfo(alerte.type);
              const Icon = typeInfo.icon;
              return (
                <div key={alerte.id} className="p-4 hover:bg-gray-50 dark:hover:bg-gray-700">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                      <div className={`p-2 rounded-lg bg-gray-100 dark:bg-gray-700 ${typeInfo.color}`}>
                        <Icon size={24} />
                      </div>
                      <div>
                        <h3 className="font-medium text-gray-900 dark:text-white">{alerte.nom}</h3>
                        <p className="text-sm text-gray-500">{typeInfo.description}</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-6">
                      {/* Seuil/Jours */}
                      <div className="flex items-center gap-2">
                        <span className="text-sm text-gray-500">
                          {alerte.type === 'stock_bas' ? 'Seuil:' : 'Jours:'}
                        </span>
                        <input
                          type="number"
                          value={alerte.seuil || alerte.jours || ''}
                          onChange={(e) => handleUpdateSeuil(alerte.id, parseInt(e.target.value) || 0)}
                          className="w-16 px-2 py-1 border rounded text-center text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                        />
                      </div>

                      {/* Toggles */}
                      <div className="flex items-center gap-4">
                        <label className="flex items-center gap-2 cursor-pointer">
                          <input
                            type="checkbox"
                            checked={alerte.email}
                            onChange={() => handleToggle(alerte.id, 'email')}
                            className="w-4 h-4 rounded"
                          />
                          <span className="text-sm text-gray-600 dark:text-gray-300">Email</span>
                        </label>
                        <label className="flex items-center gap-2 cursor-pointer">
                          <input
                            type="checkbox"
                            checked={alerte.push}
                            onChange={() => handleToggle(alerte.id, 'push')}
                            className="w-4 h-4 rounded"
                          />
                          <span className="text-sm text-gray-600 dark:text-gray-300">Push</span>
                        </label>
                      </div>

                      {/* Actif toggle */}
                      <button
                        onClick={() => handleToggle(alerte.id, 'actif')}
                        className={`px-3 py-1 rounded-full text-sm font-medium ${
                          alerte.actif
                            ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
                            : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'
                        }`}
                      >
                        {alerte.actif ? 'Actif' : 'Inactif'}
                      </button>

                      {/* Delete */}
                      <button
                        onClick={() => handleDelete(alerte.id)}
                        className="p-1 text-red-600 hover:bg-red-50 rounded"
                      >
                        <Trash2 size={18} />
                      </button>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>

      {/* Modal ajout */}
      {showAddModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h2 className="text-xl font-bold mb-4 dark:text-white">Nouvelle alerte</h2>
            <div className="space-y-4">
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">Type d'alerte</label>
                <select
                  value={newAlerte.type}
                  onChange={(e) => setNewAlerte({ ...newAlerte, type: e.target.value as AlerteConfig['type'] })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                >
                  {TYPES_ALERTES.map(t => (
                    <option key={t.key} value={t.key}>{t.label}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-1 dark:text-gray-300">
                  {newAlerte.type === 'stock_bas' ? 'Seuil minimum' : 'Nombre de jours'}
                </label>
                <input
                  type="number"
                  value={newAlerte.type === 'stock_bas' ? newAlerte.seuil : newAlerte.jours}
                  onChange={(e) => setNewAlerte({ 
                    ...newAlerte, 
                    [newAlerte.type === 'stock_bas' ? 'seuil' : 'jours']: e.target.value 
                  })}
                  className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                  placeholder={newAlerte.type === 'stock_bas' ? 'Ex: 10' : 'Ex: 30'}
                />
              </div>
            </div>
            <div className="flex justify-end gap-3 mt-6">
              <button
                onClick={() => setShowAddModal(false)}
                className="px-4 py-2 border rounded-lg dark:border-gray-600 dark:text-white"
              >
                Annuler
              </button>
              <button
                onClick={handleAddAlerte}
                className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700"
              >
                Ajouter
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
