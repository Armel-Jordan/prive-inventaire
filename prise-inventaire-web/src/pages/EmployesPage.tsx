import { useEffect, useState, useRef } from 'react';
import { Search, Plus, X, Edit2, Trash2, Download, Upload } from 'lucide-react';
import { getEmployes, createEmploye, updateEmploye, deleteEmploye, getConfiguration } from '@/services/api';
import type { Employe } from '@/types';

interface EmployeForm {
  numero: string;
  nom: string;
  prenom: string;
  email: string;
}

const emptyForm: EmployeForm = { numero: '', nom: '', prenom: '', email: '' };

interface ConfigNumero {
  auto_increment: boolean;
  prefixe: string;
  suffixe: string;
  longueur: number;
  separateur: string;
  prochain_numero: number;
}

export default function EmployesPage() {
  const [employes, setEmployes] = useState<Employe[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<EmployeForm>(emptyForm);
  const [importing, setImporting] = useState(false);
  const [configNumero, setConfigNumero] = useState<ConfigNumero | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  async function loadData() {
    try {
      const data = await getEmployes();
      setEmployes(data);
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
      const config = await getConfiguration('employe');
      setConfigNumero(config);
    } catch (error) {
      console.error('Erreur chargement config:', error);
    }
  }

  const filtered = employes.filter(e => 
    e.numero.toLowerCase().includes(search.toLowerCase()) ||
    e.nom.toLowerCase().includes(search.toLowerCase())
  );

  function openCreate() {
    setForm(emptyForm);
    setEditingId(null);
    setShowModal(true);
  }

  function openEdit(employe: Employe) {
    setForm({
      numero: employe.numero,
      nom: employe.nom,
      prenom: employe.prenom || '',
      email: employe.email || '',
    });
    setEditingId(employe.id);
    setShowModal(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    try {
      if (editingId) {
        await updateEmploye(editingId, form);
      } else {
        await createEmploye(form);
      }
      setShowModal(false);
      loadData();
    } catch (error) {
      console.error('Erreur:', error);
      alert('Erreur lors de la sauvegarde');
    }
  }

  async function handleDelete(id: number) {
    if (!confirm('Voulez-vous vraiment supprimer cet employé ?')) return;
    try {
      await deleteEmploye(id);
      loadData();
    } catch (error) {
      console.error('Erreur:', error);
      alert('Erreur lors de la suppression');
    }
  }

  function exportToCSV() {
    const headers = ['numero', 'nom', 'prenom', 'email'];
    const csvContent = [
      headers.join(';'),
      ...employes.map(e => [e.numero, e.nom, e.prenom || '', e.email || ''].join(';'))
    ].join('\n');
    
    const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `employes_${new Date().toISOString().split('T')[0]}.csv`;
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
        
        if (row.numero && row.nom) {
          try {
            await createEmploye({
              numero: row.numero,
              nom: row.nom,
              prenom: row.prenom || '',
              email: row.email || '',
            });
            imported++;
          } catch {
            errors++;
          }
        }
      }
      
      alert(`Import terminé: ${imported} employés importés, ${errors} erreurs`);
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
        <h1 className="text-2xl font-bold text-gray-800">Employés</h1>
        <div className="flex gap-2">
          <button
            onClick={exportToCSV}
            className="flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
            disabled={employes.length === 0}
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
            className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
          >
            <Plus size={20} />
            Ajouter
          </button>
        </div>
      </div>

      {/* Recherche */}
      <div className="bg-white rounded-xl shadow-sm p-4 mb-6">
        <div className="relative">
          <Search size={20} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            type="text"
            placeholder="Rechercher par numéro ou nom..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full pl-10 pr-4 py-2 border rounded-lg"
          />
        </div>
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-6 text-gray-500">Chargement...</div>
        ) : filtered.length === 0 ? (
          <div className="p-6 text-gray-500">Aucun employé trouvé</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Numéro</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Nom</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Prénom</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600">Email</th>
                  <th className="px-4 py-3 text-right text-sm font-medium text-gray-600">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {filtered.map((employe) => (
                  <tr key={employe.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-sm font-mono font-medium">{employe.numero}</td>
                    <td className="px-4 py-3 text-sm">{employe.nom}</td>
                    <td className="px-4 py-3 text-sm">{employe.prenom || '-'}</td>
                    <td className="px-4 py-3 text-sm">{employe.email || '-'}</td>
                    <td className="px-4 py-3 text-right">
                      <button
                        onClick={() => openEdit(employe)}
                        className="p-1 text-blue-600 hover:bg-blue-50 rounded mr-2"
                      >
                        <Edit2 size={16} />
                      </button>
                      <button
                        onClick={() => handleDelete(employe.id)}
                        className="p-1 text-red-600 hover:bg-red-50 rounded"
                      >
                        <Trash2 size={16} />
                      </button>
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
          <div className="bg-white rounded-xl shadow-xl w-full max-w-md mx-4">
            <div className="flex items-center justify-between p-4 border-b">
              <h2 className="text-lg font-semibold">
                {editingId ? 'Modifier l\'employé' : 'Nouvel employé'}
              </h2>
              <button onClick={() => setShowModal(false)} className="p-1 hover:bg-gray-100 rounded">
                <X size={20} />
              </button>
            </div>
            <form onSubmit={handleSubmit} className="p-4 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Numéro {!editingId && configNumero?.auto_increment ? '(auto-généré)' : '*'}
                </label>
                <input
                  type="text"
                  value={form.numero}
                  onChange={(e) => setForm({ ...form, numero: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg disabled:bg-gray-100"
                  required={!!editingId || !configNumero?.auto_increment}
                  disabled={!!editingId}
                  placeholder={!editingId && configNumero?.auto_increment ? 'Généré automatiquement' : ''}
                />
                {!editingId && configNumero?.auto_increment && (
                  <p className="text-xs text-gray-500 mt-1">
                    Format: {configNumero.prefixe}{configNumero.separateur || ''}{String(configNumero.prochain_numero).padStart(configNumero.longueur, '0')}{configNumero.suffixe ? (configNumero.separateur || '') + configNumero.suffixe : ''}
                  </p>
                )}
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                <input
                  type="text"
                  value={form.nom}
                  onChange={(e) => setForm({ ...form, nom: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Prénom</label>
                <input
                  type="text"
                  value={form.prenom}
                  onChange={(e) => setForm({ ...form, prenom: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input
                  type="email"
                  value={form.email}
                  onChange={(e) => setForm({ ...form, email: e.target.value })}
                  className="w-full px-3 py-2 border rounded-lg"
                />
              </div>
              <div className="flex gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50"
                >
                  Annuler
                </button>
                <button
                  type="submit"
                  className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                >
                  {editingId ? 'Modifier' : 'Créer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
