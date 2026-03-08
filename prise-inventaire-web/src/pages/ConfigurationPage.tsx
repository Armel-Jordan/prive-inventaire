import { useState, useEffect, useCallback } from 'react';
import { Settings, Save, RefreshCw, Hash, Eye } from 'lucide-react';
import { getConfigurations, updateConfiguration, type ConfigurationFormat } from '@/services/api';

interface FormatConfig {
  entite: 'produit' | 'employe' | 'secteur';
  label: string;
  prefixe: string;
  suffixe: string;
  longueur: number;
  separateur: string;
  auto_increment: boolean;
  prochain_numero: number;
  format_exemple: string;
}

const DEFAULT_CONFIGS: FormatConfig[] = [
  {
    entite: 'produit',
    label: 'Produits',
    prefixe: 'P',
    suffixe: '',
    longueur: 5,
    separateur: '',
    auto_increment: true,
    prochain_numero: 1,
    format_exemple: 'P00001',
  },
  {
    entite: 'employe',
    label: 'Employés',
    prefixe: 'E',
    suffixe: '',
    longueur: 4,
    separateur: '',
    auto_increment: true,
    prochain_numero: 1,
    format_exemple: 'E0001',
  },
  {
    entite: 'secteur',
    label: 'Secteurs',
    prefixe: '',
    suffixe: '',
    longueur: 3,
    separateur: '-',
    auto_increment: false,
    prochain_numero: 1,
    format_exemple: 'A-01',
  },
];

export default function ConfigurationPage() {
  const [configs, setConfigs] = useState<FormatConfig[]>(DEFAULT_CONFIGS);
  const [saving, setSaving] = useState(false);
  const [activeTab, setActiveTab] = useState<'produit' | 'employe' | 'secteur'>('produit');

  const generateExemple = useCallback((config: FormatConfig): string => {
    const numero = config.prochain_numero.toString().padStart(config.longueur, '0');
    if (config.separateur) {
      return `${config.prefixe}${config.separateur}${numero}${config.suffixe ? config.separateur + config.suffixe : ''}`;
    }
    return `${config.prefixe}${numero}${config.suffixe}`;
  }, []);

  const loadConfigurations = useCallback(async () => {
    try {
      const data = await getConfigurations();
      const mapped: FormatConfig[] = data.map((c: ConfigurationFormat) => ({
        entite: c.entite as 'produit' | 'employe' | 'secteur',
        label: c.entite === 'produit' ? 'Produits' : c.entite === 'employe' ? 'Employés' : 'Secteurs',
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
    } catch (error) {
      console.error('Erreur chargement configurations:', error);
    }
  }, [generateExemple]);

  useEffect(() => {
    loadConfigurations();
  }, [loadConfigurations]);

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

  const handleSave = async () => {
    setSaving(true);
    try {
      // Sauvegarder toutes les configurations modifiées
      for (const config of configs) {
        await updateConfiguration(config.entite, {
          prefixe: config.prefixe,
          suffixe: config.suffixe,
          longueur: config.longueur,
          separateur: config.separateur,
          auto_increment: config.auto_increment,
          prochain_numero: config.prochain_numero,
        });
      }
      alert('Configuration sauvegardée avec succès !');
    } catch (error) {
      console.error('Erreur:', error);
      alert('Erreur lors de la sauvegarde');
    } finally {
      setSaving(false);
    }
  };

  const resetToDefault = (entite: string) => {
    const defaultConfig = DEFAULT_CONFIGS.find(c => c.entite === entite);
    if (defaultConfig) {
      setConfigs(configs.map(c => c.entite === entite ? { ...defaultConfig } : c));
    }
  };

  const activeConfig = configs.find(c => c.entite === activeTab)!;

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2">
            <Settings className="text-purple-600" />
            Configuration des Numéros
          </h1>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Définissez le format des numéros pour chaque entité
          </p>
        </div>
        <button
          onClick={handleSave}
          disabled={saving}
          className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 disabled:opacity-50"
        >
          <Save size={20} />
          {saving ? 'Sauvegarde...' : 'Sauvegarder'}
        </button>
      </div>

      {/* Tabs */}
      <div className="flex gap-2 mb-6">
        {configs.map(config => (
          <button
            key={config.entite}
            onClick={() => setActiveTab(config.entite)}
            className={`px-4 py-2 rounded-lg font-medium transition-colors ${
              activeTab === config.entite
                ? 'bg-purple-600 text-white'
                : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'
            }`}
          >
            {config.label}
          </button>
        ))}
      </div>

      {/* Configuration Panel */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
        <div className="flex justify-between items-center mb-6">
          <h2 className="text-lg font-semibold dark:text-white">
            Format des numéros - {activeConfig.label}
          </h2>
          <button
            onClick={() => resetToDefault(activeTab)}
            className="flex items-center gap-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-white"
          >
            <RefreshCw size={16} />
            Réinitialiser
          </button>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {/* Préfixe */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              Préfixe
            </label>
            <input
              type="text"
              value={activeConfig.prefixe}
              onChange={(e) => updateConfig(activeTab, 'prefixe', e.target.value.toUpperCase())}
              className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
              placeholder="Ex: P, PROD, ART"
              maxLength={10}
            />
            <p className="text-xs text-gray-500 mt-1">Texte au début du numéro</p>
          </div>

          {/* Suffixe */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              Suffixe
            </label>
            <input
              type="text"
              value={activeConfig.suffixe}
              onChange={(e) => updateConfig(activeTab, 'suffixe', e.target.value.toUpperCase())}
              className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
              placeholder="Ex: -A, -2026"
              maxLength={10}
            />
            <p className="text-xs text-gray-500 mt-1">Texte à la fin du numéro</p>
          </div>

          {/* Longueur */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              Longueur de la partie numérique
            </label>
            <select
              value={activeConfig.longueur}
              onChange={(e) => updateConfig(activeTab, 'longueur', parseInt(e.target.value))}
              className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
            >
              {[2, 3, 4, 5, 6, 7, 8].map(n => (
                <option key={n} value={n}>{n} chiffres</option>
              ))}
            </select>
            <p className="text-xs text-gray-500 mt-1">Nombre de chiffres (avec zéros devant)</p>
          </div>

          {/* Séparateur */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
              Séparateur
            </label>
            <select
              value={activeConfig.separateur}
              onChange={(e) => updateConfig(activeTab, 'separateur', e.target.value)}
              className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
            >
              <option value="">Aucun</option>
              <option value="-">Tiret (-)</option>
              <option value="_">Underscore (_)</option>
              <option value=".">Point (.)</option>
              <option value="/">Slash (/)</option>
            </select>
            <p className="text-xs text-gray-500 mt-1">Caractère entre préfixe et numéro</p>
          </div>

          {/* Auto-incrément */}
          <div className="md:col-span-2">
            <label className="flex items-center gap-3 cursor-pointer">
              <input
                type="checkbox"
                checked={activeConfig.auto_increment}
                onChange={(e) => updateConfig(activeTab, 'auto_increment', e.target.checked)}
                className="w-5 h-5 rounded"
              />
              <div>
                <span className="font-medium text-gray-700 dark:text-gray-300">
                  Numérotation automatique
                </span>
                <p className="text-xs text-gray-500">
                  Le système génère automatiquement le prochain numéro disponible
                </p>
              </div>
            </label>
          </div>

          {/* Prochain numéro (si auto-incrément) */}
          {activeConfig.auto_increment && (
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Prochain numéro
              </label>
              <input
                type="number"
                value={activeConfig.prochain_numero}
                onChange={(e) => updateConfig(activeTab, 'prochain_numero', parseInt(e.target.value) || 1)}
                className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                min={1}
              />
              <p className="text-xs text-gray-500 mt-1">Numéro qui sera attribué au prochain élément créé</p>
            </div>
          )}
        </div>

        {/* Aperçu */}
        <div className="mt-8 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
          <div className="flex items-center gap-2 mb-3">
            <Eye className="text-purple-600" size={20} />
            <span className="font-medium dark:text-white">Aperçu du format</span>
          </div>
          <div className="flex items-center gap-4">
            <div className="flex items-center gap-2">
              <Hash className="text-gray-400" size={16} />
              <span className="text-2xl font-mono font-bold text-purple-600 dark:text-purple-400">
                {activeConfig.format_exemple}
              </span>
            </div>
            <div className="text-sm text-gray-500">
              → Exemple: {activeConfig.format_exemple}
            </div>
          </div>
          <div className="mt-3 text-sm text-gray-600 dark:text-gray-400">
            <strong>Structure:</strong> {activeConfig.prefixe || '(préfixe)'}{activeConfig.separateur || ''}{'{numéro}'}{activeConfig.separateur || ''}{activeConfig.suffixe || ''}
          </div>
        </div>

        {/* Exemples multiples */}
        <div className="mt-4 p-4 border border-gray-200 dark:border-gray-600 rounded-lg">
          <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Exemples de numéros générés:</span>
          <div className="flex flex-wrap gap-2 mt-2">
            {[1, 2, 3, 10, 100, 999].map(n => {
              const config = { ...activeConfig, prochain_numero: n };
              return (
                <span
                  key={n}
                  className="px-3 py-1 bg-gray-100 dark:bg-gray-600 rounded font-mono text-sm dark:text-white"
                >
                  {generateExemple(config)}
                </span>
              );
            })}
          </div>
        </div>
      </div>

      {/* Info */}
      <div className="mt-6 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
        <h3 className="font-medium text-blue-800 dark:text-blue-300 mb-2">💡 Conseils</h3>
        <ul className="text-sm text-blue-700 dark:text-blue-400 space-y-1">
          <li>• Utilisez un préfixe court pour identifier rapidement le type d'élément</li>
          <li>• Une longueur de 5-6 chiffres permet de gérer des milliers d'éléments</li>
          <li>• L'auto-incrément évite les doublons et simplifie la création</li>
          <li>• Les modifications n'affectent pas les numéros existants</li>
        </ul>
      </div>
    </div>
  );
}
