import { useCallback, useEffect, useId, useState } from 'react';
import { ChevronDown, ChevronRight, Pencil, Plus, Trash2, X } from 'lucide-react';
import {
  enregistrerListePrix, getClientsActifs, getClientsListePrix, getListesPrix, rattacherClientListePrix, supprimerListePrix,
  type Client, type ListePrix,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';

type Saisie = { id?: number; nom: string; description: string; ajustement_pourcentage: string; precision: string; actif: boolean };
const vide: Saisie = { nom: '', description: '', ajustement_pourcentage: '0', precision: '2', actif: true };

/** Clients rattachés à une liste : ajout et retrait. */
function ClientsDeLaListe({ liste, modifiable, onChange }: { liste: ListePrix; modifiable: boolean; onChange: () => void }) {
  const { t } = useLanguage();
  const tl = t.pricing.lists;
  const id = useId();
  const [rattaches, setRattaches] = useState<{ id: number; code: string; raison_sociale: string }[]>([]);
  const [tous, setTous] = useState<Client[]>([]);
  const [version, setVersion] = useState(0);

  useEffect(() => {
    let actif = true;
    getClientsListePrix(liste.id).then(c => { if (actif) setRattaches(c); }).catch(() => {});
    return () => { actif = false; };
  }, [liste.id, version]);

  useEffect(() => {
    if (!modifiable) return;
    let actif = true;
    getClientsActifs().then(c => { if (actif) setTous(c); }).catch(() => {});
    return () => { actif = false; };
  }, [modifiable]);

  /** Rattache le client à cette liste, ou l'en détache (liste vide). */
  async function rattacher(clientId: number, rattache: boolean) {
    await rattacherClientListePrix(clientId, rattache ? liste.id : null);
    setVersion(v => v + 1);
    onChange();
  }

  const disponibles = tous.filter(c => !rattaches.some(r => r.id === c.id));

  return (
    <div className="mt-3 pl-6 space-y-2">
      {rattaches.length === 0 ? <p className="text-xs text-gray-500">{tl.noClients}</p> : (
        <ul className="flex flex-wrap gap-2">
          {rattaches.map(c => (
            <li key={c.id} className="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-gray-100 dark:bg-gray-700 text-xs text-gray-700 dark:text-gray-200">
              <span className="font-mono">{c.code}</span> {c.raison_sociale}
              {modifiable && (
                <button onClick={() => rattacher(c.id, false)} aria-label={`${tl.detach} — ${c.raison_sociale}`} title={tl.detach} className="text-gray-400 hover:text-red-600"><X size={12} /></button>
              )}
            </li>
          ))}
        </ul>
      )}
      {modifiable && disponibles.length > 0 && (
        <div>
          <label htmlFor={`${id}-client`} className="sr-only">{tl.addClient}</label>
          <select id={`${id}-client`} value="" onChange={e => { if (e.target.value) rattacher(Number(e.target.value), true); }}
            className="px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white">
            <option value="">{tl.chooseClient}</option>
            {disponibles.map(c => <option key={c.id} value={c.id}>{c.code} — {c.raison_sociale}</option>)}
          </select>
        </div>
      )}
    </div>
  );
}

/** Listes de prix : formule sur le prix de base, décimales, clients rattachés. */
export default function ListesPrix({ modifiable }: { modifiable: boolean }) {
  const { t } = useLanguage();
  const tp = t.pricing;
  const tl = tp.lists;
  const id = useId();
  const [listes, setListes] = useState<ListePrix[] | null>(null);
  const [saisie, setSaisie] = useState<Saisie | null>(null);
  const [ouverte, setOuverte] = useState<number | null>(null);
  const [erreur, setErreur] = useState('');

  const charger = useCallback(async () => setListes(await getListesPrix()), []);

  useEffect(() => {
    let actif = true;
    getListesPrix().then(l => { if (actif) setListes(l); }).catch(e => { if (actif) setErreur(e instanceof ApiError ? e.message : tp.error); });
    return () => { actif = false; };
  }, [tp.error]);

  async function executer(action: () => Promise<unknown>) {
    try {
      await action();
      setErreur('');
      await charger();
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : tp.error);
    }
  }

  const formule = (l: ListePrix) => tl.formula.replace('{signe}', l.ajustement_pourcentage >= 0 ? '+' : '').replace('{valeur}', String(Number(l.ajustement_pourcentage)));
  const champ = 'w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

  return (
    <div className="space-y-4">
      {erreur && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
      {listes?.length === 0 && !saisie && <p className="text-sm text-gray-500 dark:text-gray-400">{tl.empty}</p>}

      <ul className="space-y-2">
        {listes?.map(l => (
          <li key={l.id} className={`p-4 rounded-lg border dark:border-gray-700 ${l.actif ? '' : 'opacity-60'}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
              <button onClick={() => setOuverte(ouverte === l.id ? null : l.id)} aria-expanded={ouverte === l.id} className="flex items-start gap-2 text-left">
                {ouverte === l.id ? <ChevronDown size={18} className="mt-0.5 text-gray-400" /> : <ChevronRight size={18} className="mt-0.5 text-gray-400" />}
                <span>
                  <span className="font-medium text-gray-900 dark:text-white">{l.nom}</span>
                  {!l.actif && <span className="ml-2 px-1.5 py-0.5 rounded text-[11px] bg-gray-100 text-gray-600">{tl.inactive}</span>}
                  <span className="block text-sm text-gray-600 dark:text-gray-300">
                    {[formule(l), tl.typedPrices.replace('{n}', String(l.prix_produits_count ?? 0)), tl.clientsCount.replace('{n}', String(l.clients_count ?? 0))].join(' · ')}
                  </span>
                  {l.description && <span className="block text-xs text-gray-500">{l.description}</span>}
                </span>
              </button>
              {modifiable && (
                <div className="flex gap-1">
                  <button onClick={() => setSaisie({ id: l.id, nom: l.nom, description: l.description ?? '', ajustement_pourcentage: String(Number(l.ajustement_pourcentage)), precision: String(l.precision), actif: l.actif })}
                    aria-label={`${tp.edit} — ${l.nom}`} className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-gray-700 rounded"><Pencil size={16} /></button>
                  <button onClick={() => { if (confirm(tp.confirmDelete.replace('{nom}', l.nom))) executer(() => supprimerListePrix(l.id)); }}
                    aria-label={`${tp.delete} — ${l.nom}`} className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-gray-700 rounded"><Trash2 size={16} /></button>
                </div>
              )}
            </div>
            {ouverte === l.id && <ClientsDeLaListe liste={l} modifiable={modifiable} onChange={() => { charger().catch(() => {}); }} />}
          </li>
        ))}
      </ul>

      {modifiable && (saisie ? (
        <form className="p-4 rounded-lg border border-purple-300 dark:border-purple-700 space-y-3" onSubmit={e => {
          e.preventDefault();
          executer(async () => {
            await enregistrerListePrix({
              nom: saisie.nom, description: saisie.description || null, actif: saisie.actif,
              ajustement_pourcentage: Number(saisie.ajustement_pourcentage.replace(',', '.')), precision: Number(saisie.precision),
            }, saisie.id);
            setSaisie(null);
          });
        }}>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label htmlFor={`${id}-nom`} className="block text-sm font-medium mb-1 dark:text-gray-300">{tl.name} *</label>
              <input id={`${id}-nom`} required maxLength={100} placeholder={tl.nameHint} value={saisie.nom} onChange={e => setSaisie({ ...saisie, nom: e.target.value })} className={champ} />
            </div>
            <div>
              <label htmlFor={`${id}-description`} className="block text-sm font-medium mb-1 dark:text-gray-300">{tl.description}</label>
              <input id={`${id}-description`} maxLength={255} value={saisie.description} onChange={e => setSaisie({ ...saisie, description: e.target.value })} className={champ} />
            </div>
            <div>
              <label htmlFor={`${id}-ajustement`} className="block text-sm font-medium mb-1 dark:text-gray-300">{tl.adjustment} *</label>
              <input id={`${id}-ajustement`} required type="number" step="0.01" min="-100" max="1000" value={saisie.ajustement_pourcentage}
                onChange={e => setSaisie({ ...saisie, ajustement_pourcentage: e.target.value })} className={champ} aria-describedby={`${id}-ajustement-aide`} />
              <p id={`${id}-ajustement-aide`} className="text-xs text-gray-500 mt-1">{tl.adjustmentHint}</p>
            </div>
            <div>
              <label htmlFor={`${id}-precision`} className="block text-sm font-medium mb-1 dark:text-gray-300">{tl.precision}</label>
              <select id={`${id}-precision`} value={saisie.precision} onChange={e => setSaisie({ ...saisie, precision: e.target.value })} className={champ}>
                {[0, 1, 2, 3, 4].map(n => <option key={n} value={n}>{n}</option>)}
              </select>
            </div>
          </div>
          <label className="flex items-center gap-2 text-sm dark:text-gray-300">
            <input type="checkbox" checked={saisie.actif} onChange={e => setSaisie({ ...saisie, actif: e.target.checked })} /> {tl.active}
          </label>
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setSaisie(null)} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-white">{tp.cancel}</button>
            <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{tp.save}</button>
          </div>
        </form>
      ) : (
        <button onClick={() => setSaisie(vide)} className="flex items-center gap-2 px-3 py-2 text-sm rounded-lg border border-purple-600 text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-900/30">
          <Plus size={16} /> {tl.add}
        </button>
      ))}
    </div>
  );
}
