import { useCallback, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { Plus, Pencil, Trash2, Star } from 'lucide-react';
import { enregistrerElementFicheClient, getListeFicheClient, supprimerElementFicheClient } from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';

export interface ChampElement {
  cle: string;
  libelle: string;
  type?: 'text' | 'email' | 'tel' | 'textarea' | 'case';
  requis?: boolean;
  aide?: string;
  defaut?: boolean;
}

type Valeurs = Record<string, string | boolean>;

interface Props<T> {
  clientId: number;
  liste: 'contacts' | 'adresses';
  champs: ChampElement[];
  colonnePrincipale: string;
  titre: (element: T) => string;
  details: (element: T) => ReactNode;
  libelles: { add: string; empty: string; main: string };
  /** Données déjà présentes sur la fiche, rappelées en tête (lecture seule). */
  entete?: ReactNode;
  onCompte: (nombre: number) => void;
}

/** Liste rattachée à la fiche (contacts, adresses) : cartes, formulaire en place, un seul élément principal. */
export default function ListeElementsFiche<T extends { id: number }>({ clientId, liste, champs, colonnePrincipale, titre, details, libelles, entete, onCompte }: Props<T>) {
  const { t } = useLanguage();
  const tf = t.clientFile;
  const id = useId();
  const [elements, setElements] = useState<T[] | null>(null);
  const [edition, setEdition] = useState<{ id?: number; valeurs: Valeurs } | null>(null);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);
  // Le parent peut fournir un nouveau rappel à chaque rendu : on garde le dernier sans recharger.
  const rappelCompte = useRef(onCompte);
  useEffect(() => { rappelCompte.current = onCompte; });

  const charger = useCallback(async () => {
    const trouves = await getListeFicheClient<T>(clientId, liste);
    setElements(trouves);
    rappelCompte.current(trouves.length);
  }, [clientId, liste]);

  useEffect(() => {
    let actif = true;
    getListeFicheClient<T>(clientId, liste)
      .then(trouves => { if (actif) { setElements(trouves); rappelCompte.current(trouves.length); } })
      .catch(e => { if (actif) setErreur(e instanceof ApiError ? e.message : tf.error); });
    return () => { actif = false; };
  }, [clientId, liste, tf.error]);

  const valeursDe = (element?: T): Valeurs => Object.fromEntries(champs.map(c => {
    const valeur = element ? (element as Record<string, unknown>)[c.cle] : undefined;
    return [c.cle, c.type === 'case' ? (element ? Boolean(valeur) : (c.defaut ?? false)) : String(valeur ?? '')];
  }));

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    if (!edition) return;
    // Champ texte vide : valeur absente (null) plutôt qu'une chaîne vide.
    const donnees = Object.fromEntries(Object.entries(edition.valeurs).map(([cle, v]) => [cle, typeof v === 'string' ? (v.trim() || null) : v]));
    setOccupe(true);
    try {
      await enregistrerElementFicheClient<T>(clientId, liste, donnees as Partial<T>, edition.id);
      setEdition(null);
      setErreur('');
      await charger();
    } catch (err) {
      setErreur(err instanceof ApiError ? err.message : tf.error);
    } finally {
      setOccupe(false);
    }
  }

  async function supprimer(element: T) {
    if (!confirm(tf.confirmDelete.replace('{nom}', titre(element)))) return;
    try {
      await supprimerElementFicheClient(clientId, liste, element.id);
      await charger();
    } catch (err) {
      setErreur(err instanceof ApiError ? err.message : tf.error);
    }
  }

  const champSaisie = 'w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

  return (
    <div className="space-y-4">
      {entete}
      {erreur && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      {elements && elements.length === 0 && !edition && <p className="text-sm text-gray-500 dark:text-gray-400">{libelles.empty}</p>}

      <ul className="grid grid-cols-1 lg:grid-cols-2 gap-3">
        {elements?.map(element => {
          const principal = Boolean((element as Record<string, unknown>)[colonnePrincipale]);
          return (
            <li key={element.id} className={`p-4 rounded-lg border ${principal ? 'border-purple-300 dark:border-purple-700' : 'dark:border-gray-700'}`}>
              <div className="flex items-start justify-between gap-2">
                <p className="font-medium text-gray-900 dark:text-white">
                  {titre(element)}
                  {principal && (
                    <span className="ml-2 inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] font-medium bg-purple-100 text-purple-700">
                      <Star size={11} fill="currentColor" /> {libelles.main}
                    </span>
                  )}
                </p>
                <div className="flex gap-1 shrink-0">
                  <button onClick={() => setEdition({ id: element.id, valeurs: valeursDe(element) })} aria-label={`${tf.edit} — ${titre(element)}`}
                    className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-gray-700 rounded"><Pencil size={16} /></button>
                  <button onClick={() => supprimer(element)} aria-label={`${tf.delete} — ${titre(element)}`}
                    className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-gray-700 rounded"><Trash2 size={16} /></button>
                </div>
              </div>
              <div className="mt-1 text-sm text-gray-600 dark:text-gray-300 space-y-0.5">{details(element)}</div>
            </li>
          );
        })}
      </ul>

      {edition ? (
        <form onSubmit={enregistrer} className="p-4 rounded-lg border border-purple-300 dark:border-purple-700 space-y-3">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {champs.filter(c => c.type !== 'case').map(c => (
              <div key={c.cle} className={c.type === 'textarea' ? 'sm:col-span-2' : ''}>
                <label htmlFor={`${id}-${c.cle}`} className="block text-sm font-medium mb-1 dark:text-gray-300">{c.libelle}{c.requis && ' *'}</label>
                {c.type === 'textarea' ? (
                  <textarea id={`${id}-${c.cle}`} rows={2} required={c.requis} value={String(edition.valeurs[c.cle])}
                    onChange={e => setEdition({ ...edition, valeurs: { ...edition.valeurs, [c.cle]: e.target.value } })} className={champSaisie} />
                ) : (
                  <input id={`${id}-${c.cle}`} type={c.type ?? 'text'} required={c.requis} value={String(edition.valeurs[c.cle])} placeholder={c.aide}
                    onChange={e => setEdition({ ...edition, valeurs: { ...edition.valeurs, [c.cle]: e.target.value } })} className={champSaisie} />
                )}
              </div>
            ))}
          </div>
          <div className="flex flex-wrap gap-x-5 gap-y-2">
            {champs.filter(c => c.type === 'case').map(c => (
              <label key={c.cle} className="flex items-center gap-2 text-sm dark:text-gray-300">
                <input type="checkbox" checked={Boolean(edition.valeurs[c.cle])}
                  onChange={e => setEdition({ ...edition, valeurs: { ...edition.valeurs, [c.cle]: e.target.checked } })} />
                {c.libelle}
              </label>
            ))}
          </div>
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setEdition(null)} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-700">{tf.cancel}</button>
            <button type="submit" disabled={occupe} className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">{tf.save}</button>
          </div>
        </form>
      ) : (
        <button onClick={() => setEdition({ valeurs: valeursDe() })} className="flex items-center gap-2 px-3 py-2 text-sm rounded-lg border border-purple-600 text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-900/30">
          <Plus size={16} /> {libelles.add}
        </button>
      )}
    </div>
  );
}
