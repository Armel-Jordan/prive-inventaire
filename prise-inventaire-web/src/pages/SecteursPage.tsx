import { useEffect, useRef, useState } from 'react';
import { Plus, Pencil, Trash2, Download, Upload, QrCode, Printer, X } from 'lucide-react';
import { getSecteurs, createSecteur, updateSecteur, deleteSecteur, getConfiguration } from '@/services/api';
import type { Secteur } from '@/types';
import QRCode from 'qrcode';
import { useToast } from '@/hooks/useToast';
import Toasts from '@/components/Toasts';
import ConfirmModal from '@/components/ConfirmModal';

interface ConfigNumero {
  auto_increment: boolean;
  prefixe: string;
  suffixe: string;
  longueur: number;
  separateur: string;
  prochain_numero: number;
}

export default function SecteursPage() {
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [editingSecteur, setEditingSecteur] = useState<Secteur | null>(null);
  const [form, setForm] = useState({ code: '', nom: '', description: '' });
  const [importing, setImporting] = useState(false);
  const [configNumero, setConfigNumero] = useState<ConfigNumero | null>(null);
  const [confirmId, setConfirmId] = useState<number | null>(null);
  const [qrSecteur, setQrSecteur] = useState<Secteur | null>(null);
  const [qrDataUrl, setQrDataUrl] = useState<string>('');
  const fileInputRef = useRef<HTMLInputElement>(null);
  const { toasts, toast, dismiss } = useToast();

  async function loadData() {
    try {
      const data = await getSecteurs();
      setSecteurs(data);
    } catch {
      toast('Erreur lors du chargement', 'error');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadData();
    getConfiguration('secteur').then(setConfigNumero).catch(() => {});
  }, []);

  async function openQr(secteur: Secteur) {
    setQrSecteur(secteur);
    const qrData = JSON.stringify({ code: secteur.code, nom: secteur.nom, id: secteur.id });
    const url = await QRCode.toDataURL(qrData, { width: 280, margin: 2, color: { dark: '#1e293b', light: '#ffffff' } });
    setQrDataUrl(url);
  }

  function printQr() {
    if (!qrSecteur || !qrDataUrl) return;
    const win = window.open('', '_blank');
    if (!win) return;
    win.document.write(`
      <html><head><title>QR - ${qrSecteur.code}</title>
      <style>
        body { font-family: sans-serif; display:flex; flex-direction:column; align-items:center; justify-content:center; height:100vh; margin:0; }
        img { width:220px; height:220px; }
        .code { font-size:28px; font-weight:bold; margin-top:12px; letter-spacing:2px; }
        .nom { font-size:14px; color:#64748b; margin-top:4px; }
      </style></head>
      <body onload="window.print()">
        <img src="${qrDataUrl}" />
        <div class="code">${qrSecteur.code}</div>
        <div class="nom">${qrSecteur.nom}</div>
      </body></html>
    `);
    win.document.close();
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
        toast('Secteur modifié avec succès');
      } else {
        await createSecteur(form);
        toast('Secteur créé avec succès');
      }
      setShowModal(false);
      loadData();
    } catch {
      toast('Erreur lors de la sauvegarde', 'error');
    }
  }

  async function handleDelete(id: number) {
    try {
      await deleteSecteur(id);
      toast('Secteur supprimé');
      loadData();
    } catch {
      toast('Erreur lors de la suppression', 'error');
    } finally {
      setConfirmId(null);
    }
  }

  function exportToCSV() {
    const csv = [
      'code;nom;description',
      ...secteurs.map(s => [s.code, s.nom, s.description || ''].join(';'))
    ].join('\n');
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `secteurs_${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast('Export téléchargé');
  }

  async function handleImport(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) return;
    setImporting(true);
    try {
      const text = await file.text();
      const lines = text.split('\n').filter(l => l.trim());
      const headers = lines[0].split(';').map(h => h.trim().toLowerCase());
      let imported = 0, errors = 0;
      for (let i = 1; i < lines.length; i++) {
        const values = lines[i].split(';').map(v => v.trim());
        const row: Record<string, string> = {};
        headers.forEach((h, idx) => { row[h] = values[idx] || ''; });
        if (row.code && row.nom) {
          try {
            await createSecteur({ code: row.code, nom: row.nom, description: row.description || '' });
            imported++;
          } catch { errors++; }
        }
      }
      toast(`${imported} secteurs importés${errors ? `, ${errors} erreurs` : ''}`, errors ? 'info' : 'success');
      loadData();
    } catch {
      toast('Erreur lors de l\'import', 'error');
    } finally {
      setImporting(false);
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  }

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Secteurs</h1>
          {!loading && (
            <p className="text-sm text-gray-500 mt-0.5">
              {secteurs.length} secteur{secteurs.length !== 1 ? 's' : ''}
            </p>
          )}
        </div>
        <div className="flex gap-2">
          <button onClick={exportToCSV} disabled={secteurs.length === 0}
            className="flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
            <Download size={18} /> Exporter
          </button>
          <label className="flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors cursor-pointer">
            <Upload size={18} />
            {importing ? 'Import...' : 'Importer'}
            <input ref={fileInputRef} type="file" accept=".csv" onChange={handleImport} className="hidden" disabled={importing} />
          </label>
          <button onClick={openCreate}
            className="flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
            <Plus size={20} /> Ajouter
          </button>
        </div>
      </div>

      {/* Table */}
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-6 text-gray-500">Chargement...</div>
        ) : secteurs.length === 0 ? (
          <div className="p-6 text-gray-500">Aucun secteur</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 dark:bg-gray-700 border-b dark:border-gray-600">
                <tr>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Code</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Nom</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Description</th>
                  <th className="px-4 py-3 text-left text-sm font-medium text-gray-600 dark:text-gray-300">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700">
                {secteurs.map((secteur) => (
                  <tr key={secteur.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                    <td className="px-4 py-3">
                      <span className="bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 px-2 py-1 rounded text-sm font-medium">
                        {secteur.code}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-sm font-medium dark:text-gray-200">{secteur.nom}</td>
                    <td className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{secteur.description || '-'}</td>
                    <td className="px-4 py-3">
                      <div className="flex gap-1">
                        <button onClick={() => openQr(secteur)}
                          className="p-1.5 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/30 rounded" title="QR Code">
                          <QrCode size={16} />
                        </button>
                        <button onClick={() => openEdit(secteur)}
                          className="p-1.5 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded" title="Modifier">
                          <Pencil size={16} />
                        </button>
                        <button onClick={() => setConfirmId(secteur.id!)}
                          className="p-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded" title="Supprimer">
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

      {/* Modal QR Code */}
      {qrSecteur && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" onClick={() => setQrSecteur(null)}>
          <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 w-full max-w-xs text-center" onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-base font-semibold dark:text-white">QR Code — {qrSecteur.code}</h3>
              <button onClick={() => setQrSecteur(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded">
                <X size={18} />
              </button>
            </div>
            {qrDataUrl && (
              <img src={qrDataUrl} alt={`QR ${qrSecteur.code}`} className="mx-auto rounded-lg mb-3" style={{ width: 200, height: 200 }} />
            )}
            <p className="text-lg font-bold tracking-widest text-gray-800 dark:text-white mb-1">{qrSecteur.code}</p>
            <p className="text-sm text-gray-500 dark:text-gray-400 mb-5">{qrSecteur.nom}</p>
            <button onClick={printQr}
              className="w-full flex items-center justify-center gap-2 py-2.5 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors text-sm font-medium">
              <Printer size={16} /> Imprimer
            </button>
          </div>
        </div>
      )}

      {/* Modal créer/modifier */}
      {showModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md mx-4">
            <h2 className="text-lg font-bold mb-4 dark:text-white">
              {editingSecteur ? 'Modifier le secteur' : 'Nouveau secteur'}
            </h2>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                  Code {!editingSecteur && configNumero?.auto_increment ? '(auto-généré)' : '*'}
                </label>
                <input type="text" value={form.code} onChange={e => setForm({ ...form, code: e.target.value.toUpperCase() })}
                  className="w-full border dark:border-gray-600 rounded-lg px-3 py-2 dark:bg-gray-700 dark:text-white disabled:bg-gray-100 dark:disabled:bg-gray-600"
                  placeholder={!editingSecteur && configNumero?.auto_increment ? 'Généré automatiquement' : 'Ex: A1, B2...'}
                  required={!!editingSecteur || !configNumero?.auto_increment} disabled={!!editingSecteur} />
                {!editingSecteur && configNumero?.auto_increment && (
                  <p className="text-xs text-gray-500 mt-1">Format: {configNumero.prefixe}{configNumero.separateur || ''}{String(configNumero.prochain_numero).padStart(configNumero.longueur, '0')}{configNumero.suffixe ? (configNumero.separateur || '') + configNumero.suffixe : ''}</p>
                )}
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom *</label>
                <input type="text" value={form.nom} onChange={e => setForm({ ...form, nom: e.target.value })}
                  className="w-full border dark:border-gray-600 rounded-lg px-3 py-2 dark:bg-gray-700 dark:text-white" required />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                <textarea value={form.description} onChange={e => setForm({ ...form, description: e.target.value })}
                  className="w-full border dark:border-gray-600 rounded-lg px-3 py-2 dark:bg-gray-700 dark:text-white" rows={3} />
              </div>
              <div className="flex gap-3 justify-end pt-2">
                <button type="button" onClick={() => setShowModal(false)}
                  className="px-4 py-2 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">Annuler</button>
                <button type="submit" className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                  {editingSecteur ? 'Enregistrer' : 'Créer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      <ConfirmModal
        open={confirmId !== null}
        title="Supprimer le secteur"
        message="Cette action est irréversible. Les produits liés à ce secteur ne seront plus associés."
        onConfirm={() => confirmId !== null && handleDelete(confirmId)}
        onCancel={() => setConfirmId(null)}
      />

      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}
