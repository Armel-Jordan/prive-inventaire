import { useEffect, useState, useRef } from 'react';
import { Plus, Pencil, Trash2, Download, Upload } from 'lucide-react';
import { getSecteurs, createSecteur, updateSecteur, deleteSecteur, getConfiguration } from '@/services/api';
import type { Secteur } from '@/types';

export default function SecteursPage() {
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [editingSecteur, setEditingSecteur] = useState<Secteur | null>(null);
  const [form, setForm] = useState({ code: '', nom: '', description: '' });
  const [importing, setImporting] = useState(false);
  const [configNumero, setConfigNumero] = useState<{
    auto_increment: boolean;
    prefixe: string;
    suffixe: string;
    longueur: number;
    separateur: string;
    prochain_numero: number;
  } | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  async function loadData() {
    try {
      const data = await getSecteurs();
      setSecteurs(data);
    } catch (error) {
      console.error('Erreur chargement:', error);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadData();
    loadConfig();
  }, []);

  async function loadConfig() {
    try {
      const config = await getConfiguration('secteur');
      setConfigNumero(config);
    } catch (error) {
      console.error('Erreur chargement config:', error);
    }
  }

  function openCreate() {
    setEditingSecteur(null);
    setForm({ code: '', nom: '', description: '' });
    setShowModal(true);
  }

  function openEdit(secteur: Secteur) {
    setEditingSecteur(secteur);
    setForm({ code: secteur.code, nom: secteur.nom, description: secteur.description || '' });
    setShowModal(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    try {
      if (editingSecteur) {
        await updateSecteur(editingSecteur.id!, form);
      } else {
        await createSecteur(form);
      }
      setShowModal(false);
      loadData();
    } catch (error) {
      console.error('Erreur sauvegarde:', error);
    }
  }

  async function handleDelete(id: number) {
    if (!confirm('Supprimer ce secteur ?')) return;
    try {
      await deleteSecteur(id);
      loadData();
    } catch (error) {
      console.error('Erreur suppression:', error);
    }
  }

  function exportToCSV() {
    const headers = ['code', 'nom', 'description'];
    const csvContent = [
      headers.join(';'),
      ...secteurs.map(s => [s.code, s.nom, s.description || ''].join(';'))
    ].join('\n');
    
    const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `secteurs_${new Date().toISOString().split('T')[0]}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  }

  async function handleImport(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) return;

    setImporting(true);
    try {
      const text = await file.text();
      const lines = text.split('\n').filter(line => line.trim());
      const headers = lines[0].split(';').map(h => h.trim().toLowerCase());
      
      let imported = 0;
      let errors = 0;
      
      for (let i = 1; i < lines.length; i++) {
        const values = lines[i].split(';').map(v => v.trim());
        const row: Record<string, string> = {};
        headers.forEach((h, idx) => { row[h] = values[idx] || ''; });
        
        if (row.code && row.nom) {
          try {
            await createSecteur({
              code: row.code,
              nom: row.nom,
              description: row.description || '',
            });
            imported++;
          } catch {
            errors++;
          }
        }
      }
      
      alert(`Import terminé: ${imported} secteurs importés, ${errors} erreurs`);
      loadData();
    } catch (error) {
      console.error('Erreur import:', error);
      alert('Erreur lors de l\'import du fichier');
    } finally {
      setImporting(false);
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  }

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h1 className="text-2xl font-bold text-gray-800">Secteurs</h1>
        <div className="flex gap-2">
          <button
            onClick={exportToCSV}
            className="flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
            disabled={secteurs.length === 0}
          >
            <Download size={18} />
            Exporter
          </button>
          <label className="flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors cursor-pointer">
            <Upload size={18} />
            {importing ? 'Import...' : 'Importer'}
            <input
              ref={fileInputRef}
              type="file"
              accept=".csv"
              onChange={handleImport}
              className="hidden"
              disabled={importing}
            />
          </label>
          <button
            onClick={openCreate}
            className="flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors"
          >
            <Plus size={20} />
            Ajouter
          </button>
        </div>
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-6 text-gray-500">Chargement...</div>
        ) : secteurs.length === 0 ? (
          <div className="p-6 text-gray-500">Aucun secteur</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Code</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Nom</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Description</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {secteurs.map((secteur) => (
                  <tr key={secteur.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3">
                      <span className="bg-purple-100 text-purple-700 px-2 py-1 rounded text-sm font-medium">
                        {secteur.code}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm font-medium">{secteur.nom}</td>
                    <td className="px-4 py-3 text-sm text-gray-500">{secteur.description || '-'}</td>
                    <td className="px-4 py-3">
                      <div className="flex gap-2">
                        <button
                          onClick={() => openEdit(secteur)}
                          className="p-1.5 text-blue-600 hover:bg-blue-50 rounded"
                          title="Modifier"
                        >
                          <Pencil size={16} />
                        </button>
                        <button
                          onClick={() => handleDelete(secteur.id!)}
                          className="p-1.5 text-red-600 hover:bg-red-50 rounded"
                          title="Supprimer"
                        >
                          <Trash2 size={16} />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Modal */}
      {showModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-xl p-6 w-full max-w-md mx-4">
            <h2 className="text-lg font-bold mb-4">
              {editingSecteur ? 'Modifier le secteur' : 'Nouveau secteur'}
            </h2>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Code {!editingSecteur && configNumero?.auto_increment ? '(auto-généré)' : '*'}
                </label>
                <input
                  type="text"
                  value={form.code}
                  onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })}
                  className="w-full border rounded-lg px-3 py-2 disabled:bg-gray-100"
                  placeholder={!editingSecteur && configNumero?.auto_increment ? 'Généré automatiquement' : 'Ex: A1, B2...'}
                  required={!!editingSecteur || !configNumero?.auto_increment}
                  disabled={!!editingSecteur}
                />
                {!editingSecteur && configNumero?.auto_increment && (
                  <p className="text-xs text-gray-500 mt-1">
                    Format: {configNumero.prefixe}{configNumero.separateur || ''}{String(configNumero.prochain_numero).padStart(configNumero.longueur, '0')}{configNumero.suffixe ? (configNumero.separateur || '') + configNumero.suffixe : ''}
                  </p>
                )}
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                <input
                  type="text"
                  value={form.nom}
                  onChange={(e) => setForm({ ...form, nom: e.target.value })}
                  className="w-full border rounded-lg px-3 py-2"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea
                  value={form.description}
                  onChange={(e) => setForm({ ...form, description: e.target.value })}
                  className="w-full border rounded-lg px-3 py-2"
                  rows={3}
                />
              </div>
              <div className="flex gap-3 justify-end pt-2">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                >
                  {editingSecteur ? 'Enregistrer' : 'Créer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
