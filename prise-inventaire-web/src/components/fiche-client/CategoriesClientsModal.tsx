import { useCallback, useEffect, useId, useState } from 'react';
import { Pencil, Trash2, X } from 'lucide-react';
import { enregistrerCategorieClient, getCategoriesClients, supprimerCategorieClient, type CategorieClient } from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';

/** Gestion des catégories de clients (administrateurs). Prévient le parent à chaque changement. */
export default function CategoriesClientsModal({ onFermer, onChange }: { onFermer: () => void; onChange: (categories: CategorieClient[]) => void }) {
  const { t } = useLanguage();
  const tf = t.clientFile;
  const tc = tf.categories;
  const id = useId();
  const [categories, setCategories] = useState<CategorieClient[]>([]);
  const [saisie, setSaisie] = useState<{ id?: number; nom: string; description: string }>({ nom: '', description: '' });
  const [erreur, setErreur] = useState('');

  const charger = useCallback(async () => {
    const liste = await getCategoriesClients();
    setCategories(liste);
    return liste;
  }, []);

  useEffect(() => {
    let actif = true;
    getCategoriesClients()
      .then(liste => { if (actif) setCategories(liste); })
      .catch(e => { if (actif) setErreur(e instanceof ApiError ? e.message : tf.error); });
    return () => { actif = false; };
  }, [tf.error]);

  async function executer(action: () => Promise<unknown>) {
    try {
      await action();
      setErreur('');
      onChange(await charger());
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : tf.error);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" role="dialog" aria-modal="true" aria-labelledby={`${id}-titre`}>
      <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-4">
          <h2 id={`${id}-titre`} className="text-xl font-bold dark:text-white">{tc.title}</h2>
          <button onClick={onFermer} aria-label={tc.close} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 dark:text-white"><X size={20} /></button>
        </div>

        <form className="space-y-2 mb-4" onSubmit={e => {
          e.preventDefault();
          executer(async () => {
            await enregistrerCategorieClient({ nom: saisie.nom, description: saisie.description || null }, saisie.id);
            setSaisie({ nom: '', description: '' });
          });
        }}>
          <div className="flex gap-2">
            <div className="flex-1">
              <label htmlFor={`${id}-nom`} className="sr-only">{tc.name}</label>
              <input id={`${id}-nom`} value={saisie.nom} onChange={e => setSaisie({ ...saisie, nom: e.target.value })} required maxLength={100} placeholder={tc.name}
                className="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
            </div>
            <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{saisie.id ? tf.save : tc.add}</button>
            {saisie.id && <button type="button" onClick={() => setSaisie({ nom: '', description: '' })} className="px-3 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-white">{tf.cancel}</button>}
          </div>
          <label htmlFor={`${id}-description`} className="sr-only">{tc.description}</label>
          <input id={`${id}-description`} value={saisie.description} onChange={e => setSaisie({ ...saisie, description: e.target.value })} maxLength={255} placeholder={tc.description}
            className="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
        </form>

        {erreur && <p className="mb-3 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        {categories.length === 0 ? <p className="text-sm text-gray-500">{tc.empty}</p> : (
          <ul className="divide-y dark:divide-gray-700">
            {categories.map(c => (
              <li key={c.id} className="flex items-center justify-between gap-3 py-2">
                <div>
                  <p className="text-sm font-medium text-gray-900 dark:text-white">{c.nom}</p>
                  <p className="text-xs text-gray-500">{[c.description, tc.clients.replace('{n}', String(c.clients_count ?? 0))].filter(Boolean).join(' · ')}</p>
                </div>
                <div className="flex gap-1">
                  <button onClick={() => setSaisie({ id: c.id, nom: c.nom, description: c.description ?? '' })} aria-label={`${tf.edit} — ${c.nom}`} className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-gray-700 rounded"><Pencil size={16} /></button>
                  <button onClick={() => { if (confirm(tc.confirmDelete.replace('{nom}', c.nom))) executer(() => supprimerCategorieClient(c.id)); }}
                    aria-label={`${tf.delete} — ${c.nom}`} className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-gray-700 rounded"><Trash2 size={16} /></button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
