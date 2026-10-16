import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { Pin, PinOff, Pencil, Trash2 } from 'lucide-react';
import { enregistrerElementFiche, getListeFiche, supprimerElementFiche, type RemarqueFiche } from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { useAuth } from '@/contexts/AuthContext';
import { usePermissions } from '@/contexts/PermissionsContext';
import { formatDate } from './format';

/**
 * Remarques datées d'une fiche (route `chemin`, ex. `/clients/12/remarques`) ; leur auteur et les
 * administrateurs peuvent les modifier, épingler ou supprimer.
 */
export default function OngletRemarques({ chemin, onCompte }: { chemin: string; onCompte: (nombre: number) => void }) {
  const { t, language } = useLanguage();
  const tf = t.clientFile;
  const tn = tf.notes;
  const id = useId();
  const { user } = useAuth();
  const { permissions } = usePermissions();
  const responsable = permissions?.role === 'admin' || permissions?.role === 'manager';
  const [remarques, setRemarques] = useState<RemarqueFiche[] | null>(null);
  const [texte, setTexte] = useState('');
  const [edition, setEdition] = useState<{ id: number; texte: string } | null>(null);
  const [erreur, setErreur] = useState('');
  const rappelCompte = useRef(onCompte);
  useEffect(() => { rappelCompte.current = onCompte; });

  const charger = useCallback(async () => {
    const trouvees = await getListeFiche<RemarqueFiche>(chemin);
    setRemarques(trouvees);
    rappelCompte.current(trouvees.length);
  }, [chemin]);

  useEffect(() => {
    let actif = true;
    getListeFiche<RemarqueFiche>(chemin)
      .then(trouvees => { if (actif) { setRemarques(trouvees); rappelCompte.current(trouvees.length); } })
      .catch(e => { if (actif) setErreur(e instanceof ApiError ? e.message : tf.error); });
    return () => { actif = false; };
  }, [chemin, tf.error]);

  async function executer(action: () => Promise<unknown>) {
    try {
      await action();
      setErreur('');
      await charger();
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : tf.error);
    }
  }

  const modifiable = (r: RemarqueFiche) => responsable || (user !== null && r.auteur_id === user.id);

  return (
    <div className="space-y-4">
      <form className="space-y-2" onSubmit={e => {
        e.preventDefault();
        executer(async () => { await enregistrerElementFiche<RemarqueFiche>(chemin, { texte }); setTexte(''); });
      }}>
        <label htmlFor={`${id}-texte`} className="sr-only">{tn.placeholder}</label>
        <textarea id={`${id}-texte`} value={texte} onChange={e => setTexte(e.target.value)} rows={2} required maxLength={5000} placeholder={tn.placeholder}
          className="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
        <div className="flex justify-end">
          <button type="submit" disabled={!texte.trim()} className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">{tn.add}</button>
        </div>
      </form>

      {erreur && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
      {remarques?.length === 0 && <p className="text-sm text-gray-500 dark:text-gray-400">{tn.empty}</p>}

      <ul className="space-y-3">
        {remarques?.map(r => (
          <li key={r.id} className={`p-3 rounded-lg border ${r.epinglee ? 'border-amber-300 bg-amber-50/60 dark:bg-amber-900/10 dark:border-amber-700' : 'dark:border-gray-700'}`}>
            <div className="flex items-start justify-between gap-3">
              <p className="text-xs text-gray-500 dark:text-gray-400">
                {r.epinglee && <span className="mr-2 font-medium text-amber-700 dark:text-amber-400">{tn.pinned}</span>}
                {tn.by.replace('{auteur}', r.auteur?.nom ?? '—').replace('{date}', formatDate(r.created_at, language, true))}
              </p>
              {modifiable(r) && (
                <div className="flex gap-1 shrink-0">
                  <button onClick={() => executer(() => enregistrerElementFiche<RemarqueFiche>(chemin, { epinglee: !r.epinglee }, r.id))}
                    aria-label={r.epinglee ? tn.unpin : tn.pin} title={r.epinglee ? tn.unpin : tn.pin} className="p-1 text-amber-600 hover:bg-amber-50 dark:hover:bg-gray-700 rounded">
                    {r.epinglee ? <PinOff size={15} /> : <Pin size={15} />}
                  </button>
                  <button onClick={() => setEdition({ id: r.id, texte: r.texte })} aria-label={tf.edit} title={tf.edit} className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-gray-700 rounded"><Pencil size={15} /></button>
                  <button onClick={() => { if (confirm(tf.confirmDelete.replace('{nom}', r.texte.slice(0, 40)))) executer(() => supprimerElementFiche(chemin, r.id)); }}
                    aria-label={tf.delete} title={tf.delete} className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-gray-700 rounded"><Trash2 size={15} /></button>
                </div>
              )}
            </div>
            {edition?.id === r.id ? (
              <form className="mt-2 space-y-2" onSubmit={e => {
                e.preventDefault();
                executer(async () => { await enregistrerElementFiche<RemarqueFiche>(chemin, { texte: edition.texte }, r.id); setEdition(null); });
              }}>
                <textarea aria-label={tf.edit} value={edition.texte} onChange={e => setEdition({ ...edition, texte: e.target.value })} rows={2} required maxLength={5000}
                  className="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                <div className="flex justify-end gap-2">
                  <button type="button" onClick={() => setEdition(null)} className="px-3 py-1 text-sm border rounded-lg dark:border-gray-600 dark:text-white">{tf.cancel}</button>
                  <button type="submit" className="px-3 py-1 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{tf.save}</button>
                </div>
              </form>
            ) : (
              <p className="mt-1 text-sm text-gray-800 dark:text-gray-200 whitespace-pre-line">{r.texte}</p>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
