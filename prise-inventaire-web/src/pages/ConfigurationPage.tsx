import { useState, useEffect, useCallback } from 'react';
import { Settings, Save, RefreshCw, Hash, Eye, Building2, Bell, DollarSign, Clock } from 'lucide-react';
import { getConfigurations, updateConfiguration, getParametres, updateParametres, type ConfigurationFormat, type TenantParametres } from '@/services/api';

interface FormatConfig {
  entite: string;
  label: string;
  prefixe: string;
  suffixe: string;
  longueur: number;
  separateur: string;
  auto_increment: boolean;
  prochain_numero: number;
  format_exemple: string;
}

const ENTITY_LABELS: Record<string, string> = {
  produit:       'Produits',
  employe:       'Employés',
  secteur:       'Secteurs',
  fournisseur:   'Fournisseurs',
  client:        'Clients',
  commande:      'Commandes',
  facture:       'Factures',
  bon_livraison: 'Bons Livraison',
  tournee:       'Tournées',
};

const DEFAULT_CONFIGS: FormatConfig[] = [
  { entite: 'produit',       label: 'Produits',       prefixe: 'P',   suffixe: '', longueur: 5, separateur: '',  auto_increment: true,  prochain_numero: 1, format_exemple: 'P00001' },
  { entite: 'employe',       label: 'Employés',       prefixe: 'E',   suffixe: '', longueur: 4, separateur: '',  auto_increment: true,  prochain_numero: 1, format_exemple: 'E0001' },
  { entite: 'secteur',       label: 'Secteurs',       prefixe: '',    suffixe: '', longueur: 3, separateur: '-', auto_increment: false, prochain_numero: 1, format_exemple: 'A-01' },
  { entite: 'fournisseur',   label: 'Fournisseurs',   prefixe: 'F',   suffixe: '', longueur: 5, separateur: '',  auto_increment: true,  prochain_numero: 1, format_exemple: 'F00001' },
  { entite: 'client',        label: 'Clients',        prefixe: 'C',   suffixe: '', longueur: 5, separateur: '',  auto_increment: true,  prochain_numero: 1, format_exemple: 'C00001' },
  { entite: 'commande',      label: 'Commandes',      prefixe: 'CMD', suffixe: '', longueur: 5, separateur: '-', auto_increment: true,  prochain_numero: 1, format_exemple: 'CMD-00001' },
  { entite: 'facture',       label: 'Factures',       prefixe: 'FAC', suffixe: '', longueur: 5, separateur: '-', auto_increment: true,  prochain_numero: 1, format_exemple: 'FAC-00001' },
  { entite: 'bon_livraison', label: 'Bons Livraison', prefixe: 'BL',  suffixe: '', longueur: 5, separateur: '-', auto_increment: true,  prochain_numero: 1, format_exemple: 'BL-00001' },
  { entite: 'tournee',       label: 'Tournées',       prefixe: 'T',   suffixe: '', longueur: 4, separateur: '',  auto_increment: true,  prochain_numero: 1, format_exemple: 'T0001' },
];

type MainTab = 'numeros' | 'entreprise' | 'devise' | 'delais' | 'alertes';

const MAIN_TABS: { id: MainTab; label: string; icon: React.ReactNode }[] = [
  { id: 'numeros',    label: 'Numérotation',      icon: <Hash size={16} /> },
  { id: 'entreprise', label: 'Entreprise',         icon: <Building2 size={16} /> },
  { id: 'devise',     label: 'Devise & TVA',       icon: <DollarSign size={16} /> },
  { id: 'delais',     label: 'Délais par défaut',  icon: <Clock size={16} /> },
  { id: 'alertes',    label: 'Alertes stock',      icon: <Bell size={16} /> },
];

export default function ConfigurationPage() {
  const [mainTab, setMainTab] = useState<MainTab>('numeros');
  const [configs, setConfigs] = useState<FormatConfig[]>(DEFAULT_CONFIGS);
  const [activeEntity, setActiveEntity] = useState<string>('produit');
  const [saving, setSaving] = useState(false);
  const [successMsg, setSuccessMsg] = useState('');

  // Paramètres généraux
  const [parametres, setParametres] = useState<TenantParametres>({
    devise_symbole: '€',
    devise_code: 'EUR',
    tva_taux: 20,
    delai_paiement_jours: 30,
    delai_livraison_jours: 7,
    stock_seuil_defaut: 5,
  });

  const generateExemple = useCallback((config: FormatConfig): string => {
    const numero = config.prochain_numero.toString().padStart(config.longueur, '0');
    if (config.separateur) {
      return `${config.prefixe}${config.separateur}${numero}${config.suffixe ? config.separateur + config.suffixe : ''}`;
    }
    return `${config.prefixe}${numero}${config.suffixe}`;
  }, []);

  const loadAll = useCallback(async () => {
    try {
      const [configData, paramData] = await Promise.all([getConfigurations(), getParametres()]);
      const mapped: FormatConfig[] = configData.map((c: ConfigurationFormat) => ({
        entite: c.entite,
        label: ENTITY_LABELS[c.entite] || c.entite,
        prefixe: c.prefixe || '',
        suffixe: c.suffixe || '',
        longueur: c.longueur,
        separateur: c.separateur || '',
        auto_increment: c.auto_increment,
        prochain_numero: c.prochain_numero,
        format_exemple: '',
      }));
      mapped.forEach(m => { m.format_exemple = generateExemple(m); });
      setConfigs(mapped);
      setParametres(paramData);
    } catch (error) {
      console.error('Erreur chargement:', error);
    }
  }, [generateExemple]);

  useEffect(() => { loadAll(); }, [loadAll]);

  const showSuccess = (msg: string) => {
    setSuccessMsg(msg);
    setTimeout(() => setSuccessMsg(''), 3000);
  };

  // ── Numérotation ──────────────────────────────────────────────
  const updateConfig = (entite: string, field: keyof FormatConfig, value: string | number | boolean) => {
    setConfigs(configs.map(c => {
      if (c.entite === entite) {
        const updated = { ...c, [field]: value };
        updated.format_exemple = generateExemple(updated);
        return updated;
      }
      return c;
    }));
  };

  const handleSaveNumeros = async () => {
    setSaving(true);
    try {
      for (const config of configs) {
        await updateConfiguration(config.entite, {
          prefixe: config.prefixe, suffixe: config.suffixe,
          longueur: config.longueur, separateur: config.separateur,
          auto_increment: config.auto_increment, prochain_numero: config.prochain_numero,
        });
      }
      showSuccess('Numérotation sauvegardée !');
    } catch { alert('Erreur lors de la sauvegarde'); }
    finally { setSaving(false); }
  };

  const resetToDefault = (entite: string) => {
    const d = DEFAULT_CONFIGS.find(c => c.entite === entite);
    if (d) setConfigs(configs.map(c => c.entite === entite ? { ...d } : c));
  };

  // ── Paramètres généraux ───────────────────────────────────────
  const handleSaveParametres = async () => {
    setSaving(true);
    try {
      await updateParametres(parametres);
      showSuccess('Paramètres sauvegardés !');
    } catch { alert('Erreur lors de la sauvegarde'); }
    finally { setSaving(false); }
  };

  const setParam = (field: keyof TenantParametres, value: string | number) => {
    setParametres(p => ({ ...p, [field]: value }));
  };

  const activeConfig = configs.find(c => c.entite === activeEntity)!;

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2">
            <Settings className="text-purple-600" />
            Paramètres
          </h1>
          <p className="text-sm text-gray-500 dark:text-gray-400">Configurez votre espace de travail</p>
        </div>
        {successMsg && (
          <span className="px-4 py-2 bg-green-100 text-green-700 rounded-lg text-sm font-medium">{successMsg}</span>
        )}
      </div>

      {/* Onglets principaux */}
      <div className="flex gap-1 mb-6 bg-gray-100 dark:bg-gray-800 p-1 rounded-xl w-fit">
        {MAIN_TABS.map(tab => (
          <button
            key={tab.id}
            onClick={() => setMainTab(tab.id)}
            className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
              mainTab === tab.id
                ? 'bg-white dark:bg-gray-700 text-purple-600 shadow-sm'
                : 'text-gray-600 dark:text-gray-400 hover:text-gray-800'
            }`}
          >
            {tab.icon}
            {tab.label}
          </button>
        ))}
      </div>

      {/* ── Numérotation ── */}
      {mainTab === 'numeros' && (
        <div className="flex gap-6">
          <div className="w-48 shrink-0">
            <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
              {configs.map(config => (
                <button
                  key={config.entite}
                  onClick={() => setActiveEntity(config.entite)}
                  className={`w-full text-left px-4 py-3 text-sm font-medium border-b last:border-b-0 dark:border-gray-700 transition-colors ${
                    activeEntity === config.entite
                      ? 'bg-purple-600 text-white'
                      : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'
                  }`}
                >
                  {config.label}
                </button>
              ))}
            </div>
          </div>

          <div className="flex-1 space-y-4">
            <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
              <div className="flex justify-between items-center mb-6">
                <h2 className="text-lg font-semibold dark:text-white">Format — {activeConfig.label}</h2>
                <div className="flex gap-2">
                  <button onClick={() => resetToDefault(activeEntity)} className="flex items-center gap-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 text-sm">
                    <RefreshCw size={14} /> Réinitialiser
                  </button>
                  <button onClick={handleSaveNumeros} disabled={saving} className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 disabled:opacity-50 text-sm">
                    <Save size={14} /> {saving ? 'Sauvegarde...' : 'Sauvegarder'}
                  </button>
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Préfixe</label>
                  <input type="text" value={activeConfig.prefixe} onChange={(e) => updateConfig(activeEntity, 'prefixe', e.target.value.toUpperCase())} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="Ex: P, PROD" maxLength={10} />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Suffixe</label>
                  <input type="text" value={activeConfig.suffixe} onChange={(e) => updateConfig(activeEntity, 'suffixe', e.target.value.toUpperCase())} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="Ex: -A, -2026" maxLength={10} />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Longueur numérique</label>
                  <select value={activeConfig.longueur} onChange={(e) => updateConfig(activeEntity, 'longueur', parseInt(e.target.value))} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                    {[2,3,4,5,6,7,8].map(n => <option key={n} value={n}>{n} chiffres</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Séparateur</label>
                  <select value={activeConfig.separateur} onChange={(e) => updateConfig(activeEntity, 'separateur', e.target.value)} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                    <option value="">Aucun</option>
                    <option value="-">Tiret (-)</option>
                    <option value="_">Underscore (_)</option>
                    <option value=".">Point (.)</option>
                    <option value="/">Slash (/)</option>
                  </select>
                </div>
                <div className="md:col-span-2">
                  <label className="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" checked={activeConfig.auto_increment} onChange={(e) => updateConfig(activeEntity, 'auto_increment', e.target.checked)} className="w-5 h-5 rounded" />
                    <div>
                      <span className="font-medium text-gray-700 dark:text-gray-300">Numérotation automatique</span>
                      <p className="text-xs text-gray-500">Le système génère automatiquement le prochain numéro</p>
                    </div>
                  </label>
                </div>
                {activeConfig.auto_increment && (
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Prochain numéro</label>
                    <input type="number" value={activeConfig.prochain_numero} onChange={(e) => updateConfig(activeEntity, 'prochain_numero', parseInt(e.target.value) || 1)} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" min={1} />
                  </div>
                )}
              </div>

              <div className="mt-6 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                <div className="flex items-center gap-2 mb-2">
                  <Eye className="text-purple-600" size={16} />
                  <span className="font-medium dark:text-white text-sm">Aperçu</span>
                </div>
                <span className="text-2xl font-mono font-bold text-purple-600 dark:text-purple-400">{activeConfig.format_exemple}</span>
                <div className="flex flex-wrap gap-2 mt-3">
                  {[1, 2, 3, 10, 100].map(n => (
                    <span key={n} className="px-2 py-1 bg-gray-200 dark:bg-gray-600 rounded font-mono text-xs dark:text-white">
                      {generateExemple({ ...activeConfig, prochain_numero: n })}
                    </span>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ── Entreprise ── */}
      {mainTab === 'entreprise' && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 max-w-2xl">
          <h2 className="text-lg font-semibold dark:text-white mb-6 flex items-center gap-2">
            <Building2 size={20} className="text-purple-600" /> Informations de l'entreprise
          </h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="md:col-span-2">
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom de l'entreprise</label>
              <input type="text" value={parametres.nom_entreprise || ''} onChange={(e) => setParam('nom_entreprise', e.target.value)} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="Ma Société SARL" />
            </div>
            <div className="md:col-span-2">
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Adresse</label>
              <textarea value={parametres.adresse || ''} onChange={(e) => setParam('adresse', e.target.value)} rows={3} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="12 rue de la Paix, 75001 Paris" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Téléphone</label>
              <input type="text" value={parametres.telephone || ''} onChange={(e) => setParam('telephone', e.target.value)} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="+33 1 23 45 67 89" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
              <input type="email" value={parametres.email || ''} onChange={(e) => setParam('email', e.target.value)} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="contact@masociete.fr" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">SIRET</label>
              <input type="text" value={parametres.siret || ''} onChange={(e) => setParam('siret', e.target.value)} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="123 456 789 00012" maxLength={20} />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">N° TVA intracommunautaire</label>
              <input type="text" value={parametres.tva_numero || ''} onChange={(e) => setParam('tva_numero', e.target.value)} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="FR 12 345678901" maxLength={50} />
            </div>
          </div>
          <p className="text-xs text-gray-500 mt-4">Ces informations apparaîtront sur vos PDFs (bons de commande, factures).</p>
          <div className="mt-6">
            <button onClick={handleSaveParametres} disabled={saving} className="flex items-center gap-2 bg-purple-600 text-white px-6 py-2 rounded-lg hover:bg-purple-700 disabled:opacity-50">
              <Save size={16} /> {saving ? 'Sauvegarde...' : 'Sauvegarder'}
            </button>
          </div>
        </div>
      )}

      {/* ── Devise & TVA ── */}
      {mainTab === 'devise' && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 max-w-lg">
          <h2 className="text-lg font-semibold dark:text-white mb-6 flex items-center gap-2">
            <DollarSign size={20} className="text-purple-600" /> Devise & TVA
          </h2>
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Symbole</label>
                <input type="text" value={parametres.devise_symbole || '€'} onChange={(e) => setParam('devise_symbole', e.target.value)} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" maxLength={5} placeholder="€" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Code ISO</label>
                <input type="text" value={parametres.devise_code || 'EUR'} onChange={(e) => setParam('devise_code', e.target.value.toUpperCase())} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" maxLength={5} placeholder="EUR" />
              </div>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Taux de TVA par défaut (%)</label>
              <input type="number" value={parametres.tva_taux ?? 20} onChange={(e) => setParam('tva_taux', parseFloat(e.target.value))} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" min={0} max={100} step={0.1} />
              <p className="text-xs text-gray-500 mt-1">Utilisé comme valeur par défaut lors de la création de factures.</p>
            </div>
            <div className="p-3 bg-gray-50 dark:bg-gray-700 rounded-lg text-sm text-gray-600 dark:text-gray-300">
              Aperçu : <span className="font-bold text-purple-600">1 250,00 {parametres.devise_symbole || '€'}</span> ({parametres.devise_code || 'EUR'})
            </div>
          </div>
          <div className="mt-6">
            <button onClick={handleSaveParametres} disabled={saving} className="flex items-center gap-2 bg-purple-600 text-white px-6 py-2 rounded-lg hover:bg-purple-700 disabled:opacity-50">
              <Save size={16} /> {saving ? 'Sauvegarde...' : 'Sauvegarder'}
            </button>
          </div>
        </div>
      )}

      {/* ── Délais ── */}
      {mainTab === 'delais' && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 max-w-lg">
          <h2 className="text-lg font-semibold dark:text-white mb-6 flex items-center gap-2">
            <Clock size={20} className="text-purple-600" /> Délais par défaut
          </h2>
          <div className="space-y-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Délai de paiement factures (jours)</label>
              <input type="number" value={parametres.delai_paiement_jours ?? 30} onChange={(e) => setParam('delai_paiement_jours', parseInt(e.target.value))} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" min={0} max={365} />
              <p className="text-xs text-gray-500 mt-1">Date d'échéance = date facture + ce délai.</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Délai de livraison commandes fournisseur (jours)</label>
              <input type="number" value={parametres.delai_livraison_jours ?? 7} onChange={(e) => setParam('delai_livraison_jours', parseInt(e.target.value))} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" min={0} max={365} />
              <p className="text-xs text-gray-500 mt-1">Date de livraison prévue = date commande + ce délai.</p>
            </div>
          </div>
          <div className="mt-6">
            <button onClick={handleSaveParametres} disabled={saving} className="flex items-center gap-2 bg-purple-600 text-white px-6 py-2 rounded-lg hover:bg-purple-700 disabled:opacity-50">
              <Save size={16} /> {saving ? 'Sauvegarde...' : 'Sauvegarder'}
            </button>
          </div>
        </div>
      )}

      {/* ── Alertes stock ── */}
      {mainTab === 'alertes' && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 max-w-lg">
          <h2 className="text-lg font-semibold dark:text-white mb-6 flex items-center gap-2">
            <Bell size={20} className="text-purple-600" /> Alertes stock
          </h2>
          <div className="space-y-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Seuil de stock bas par défaut (unités)</label>
              <input type="number" value={parametres.stock_seuil_defaut ?? 5} onChange={(e) => setParam('stock_seuil_defaut', parseInt(e.target.value))} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" min={0} />
              <p className="text-xs text-gray-500 mt-1">Un produit est considéré en stock bas quand sa quantité est inférieure à ce seuil (si aucun seuil spécifique n'est défini sur le produit).</p>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email de notification</label>
              <input type="email" value={parametres.stock_alerte_email || ''} onChange={(e) => setParam('stock_alerte_email', e.target.value)} className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="alertes@masociete.fr" />
              <p className="text-xs text-gray-500 mt-1">Cet email recevra les notifications de stock bas.</p>
            </div>
          </div>
          <div className="mt-6">
            <button onClick={handleSaveParametres} disabled={saving} className="flex items-center gap-2 bg-purple-600 text-white px-6 py-2 rounded-lg hover:bg-purple-700 disabled:opacity-50">
              <Save size={16} /> {saving ? 'Sauvegarde...' : 'Sauvegarder'}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
