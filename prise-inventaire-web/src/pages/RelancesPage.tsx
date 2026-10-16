import { useEffect, useState } from 'react';
import { BellRing, History, PhoneCall, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, formatDateDocument, formatMontant, useDevise } from '@/lib/documents';
import {
  getFacturesEnRetard, getRelancesFacture, MODES_RELANCE, noterRelance,
  type FactureEnRetard, type ListeRelances, type ModeRelance, type Relance,
} from '@/services/relances';
import BoutonCourriel from '@/components/BoutonCourriel';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
/** Retard minimal proposé dans le filtre, en jours. */
const RETARDS = [0, 30, 60, 90];
/** Couleur du retard, comme les tranches des comptes clients. */
const couleurRetard = (jours: number) => (jours > 90 ? 'bg-red-200 text-red-950' : jours > 60 ? 'bg-red-100 text-red-900' : jours > 30 ? 'bg-orange-100 text-orange-900' : 'bg-amber-50 text-amber-900');

/**
 * Relances de paiement (plan de parité fripv2, phase 1.5) : les factures en retard, du plus ancien retard au plus
 * récent, avec leurs relances. Relancer par courriel (modèle « relance », facture jointe), noter une relance faite
 * autrement (téléphone, courrier), consulter l'historique. Les relances automatiques se règlent dans les tâches
 * planifiées.
 */
export default function RelancesPage() {
  const { t, language } = useLanguage();
  const tr = t.reminders;
  const devise = useDevise();
  const montant = (v: number | string | null | undefined) => formatMontant(v, devise, language);
  const { peut } = usePermissions();
  const { toasts, toast, dismiss } = useToast();
  const [recherche, setRecherche] = useState('');
  const [terme, setTerme] = useState('');
  const [retardMin, setRetardMin] = useState(0);
  const [jamais, setJamais] = useState(false);
  const [liste, setListe] = useState<ListeRelances | null>(null);
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);
  const [aNoter, setANoter] = useState<FactureEnRetard | null>(null);
  const [historique, setHistorique] = useState<{ facture: FactureEnRetard; relances: Relance[] } | null>(null);

  useEffect(() => {
    const minuterie = setTimeout(() => setTerme(recherche.trim()), 300);
    return () => clearTimeout(minuterie);
  }, [recherche]);

  useEffect(() => {
    let actif = true;
    getFacturesEnRetard({ retard_min: retardMin || undefined, jamais, recherche: terme || undefined }).then(
      l => { if (actif) { setListe(l); setErreur(''); } },
      e => { if (actif) setErreur(messageErreur(e, tr.error)); },
    );
    return () => { actif = false; };
  }, [retardMin, jamais, terme, version, tr.error]);

  const recharger = () => setVersion(v => v + 1);

  async function voirHistorique(facture: FactureEnRetard) {
    try {
      setHistorique({ facture, relances: await getRelancesFacture(facture.facture_id) });
    } catch (e) {
      toast(messageErreur(e, tr.error), 'error');
    }
  }

  const derniere = (f: FactureEnRetard) => f.derniere_relance
    ? tr.lastReminder
      .replace('{niveau}', String(f.derniere_relance.niveau))
      .replace('{date}', formatDateDocument(f.derniere_relance.date, language))
      .replace('{mode}', tr.modes[f.derniere_relance.mode] ?? f.derniere_relance.mode)
      + (f.derniere_relance.automatique ? ` · ${tr.automatic}` : '')
    : tr.never;

  return (
    <div className="p-4 sm:p-6">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><BellRing className="text-purple-600" /> {tr.title}</h1>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tr.subtitle}</p>
      </div>

      <div className="flex flex-wrap items-end gap-3 mb-4">
        <label className="text-xs text-gray-500 dark:text-gray-400 flex-1 min-w-[12rem]">{tr.search}
          <input type="search" value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={tr.searchHelp} className={classeChamp} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400 w-44">{tr.minDelay}
          <select value={retardMin} onChange={e => setRetardMin(Number(e.target.value))} className={classeChamp}>
            {RETARDS.map(r => <option key={r} value={r}>{r === 0 ? tr.allDelays : tr.overDays.replace('{n}', String(r))}</option>)}
          </select>
        </label>
        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 pb-2">
          <input type="checkbox" checked={jamais} onChange={e => setJamais(e.target.checked)} /> {tr.neverOnly}
        </label>
      </div>

      {erreur && <p role="alert" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      {liste && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
          {[
            [tr.invoices, String(liste.totaux.factures)],
            [tr.customers, String(liste.totaux.clients)],
            [tr.due, montant(liste.totaux.reste_a_payer)],
            [tr.neverReminded, String(liste.totaux.jamais_relancees)],
          ].map(([libelle, valeur]) => (
            <div key={libelle} className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-3">
              <p className="text-xs uppercase text-gray-500 dark:text-gray-400">{libelle}</p>
              <p className="text-xl font-bold text-gray-900 dark:text-white">{valeur}</p>
            </div>
          ))}
        </div>
      )}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
        <table className="w-full text-sm min-w-[52rem]">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-3 py-2 font-medium">{tr.customer}</th>
              <th className="px-3 py-2 font-medium">{tr.invoice}</th>
              <th className="px-3 py-2 font-medium">{tr.dueDate}</th>
              <th className="px-3 py-2 font-medium text-center">{tr.delay}</th>
              <th className="px-3 py-2 font-medium text-right">{tr.remaining}</th>
              <th className="px-3 py-2 font-medium">{tr.reminders}</th>
              <th className="px-3 py-2 font-medium text-center">{tr.actions}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
            {liste === null && !erreur && <tr><td colSpan={7} className="px-4 py-6 text-center text-gray-500">{tr.loading}</td></tr>}
            {liste !== null && liste.factures.length === 0 && <tr><td colSpan={7} className="px-4 py-6 text-center text-gray-500">{tr.empty}</td></tr>}
            {liste?.factures.map(f => (
              <tr key={f.facture_id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                <td className="px-3 py-2">
                  <span className="text-gray-900 dark:text-white">{f.client?.raison_sociale}</span>
                  <span className="block text-xs text-gray-500">{f.client?.email || tr.noEmail}</span>
                </td>
                <td className="px-3 py-2 font-mono text-gray-700 dark:text-gray-300">{f.numero}</td>
                <td className="px-3 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">{formatDateDocument(f.date_echeance ?? f.date_facture, language)}</td>
                <td className="px-3 py-2 text-center"><span className={`px-2 py-0.5 rounded text-xs whitespace-nowrap ${couleurRetard(f.retard)}`}>{tr.daysLate.replace('{n}', String(f.retard))}</span></td>
                <td className="px-3 py-2 text-right whitespace-nowrap font-medium text-gray-900 dark:text-white">{montant(f.reste_a_payer)}</td>
                <td className="px-3 py-2 text-gray-700 dark:text-gray-300">
                  {f.nb_relances > 0 && <span className="font-medium">{tr.count.replace('{n}', String(f.nb_relances))} · </span>}
                  <span className={f.nb_relances > 0 ? '' : 'text-gray-400'}>{derniere(f)}</span>
                </td>
                <td className="px-3 py-2">
                  <div className="flex justify-center gap-1">
                    {peut('relances', 'creer') && (
                      <>
                        <BoutonCourriel type="relance" documentId={f.facture_id} titre={`${tr.byEmail} — ${f.numero}`} onFerme={recharger} />
                        <button type="button" onClick={() => setANoter(f)} title={tr.note} aria-label={`${tr.note} — ${f.numero}`}
                          className="p-1 text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 rounded"><PhoneCall size={18} /></button>
                      </>
                    )}
                    <button type="button" onClick={() => { void voirHistorique(f); }} title={tr.history} aria-label={`${tr.history} — ${f.numero}`}
                      className="p-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded"><History size={18} /></button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">{tr.automaticHelp}</p>

      {aNoter && (
        <NoterRelance facture={aNoter} onClose={() => setANoter(null)} onNote={() => { setANoter(null); toast(tr.noted); recharger(); }} />
      )}

      {historique && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div role="dialog" aria-modal="true" aria-labelledby="historique-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto space-y-3 text-sm">
            <div className="flex items-start justify-between gap-3">
              <h2 id="historique-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tr.historyTitle.replace('{numero}', historique.facture.numero)}</h2>
              <button type="button" onClick={() => setHistorique(null)} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tr.close}><X size={18} /></button>
            </div>
            {historique.relances.length === 0 ? <p className="text-gray-500">{tr.never}</p> : (
              <ol className="divide-y dark:divide-gray-700">
                {historique.relances.map(r => (
                  <li key={r.id} className="py-2">
                    <p className="text-gray-900 dark:text-white">
                      <span className="font-medium">{tr.level.replace('{n}', String(r.niveau))}</span> · {formatDateDocument(r.date_relance, language)} · {tr.modes[r.mode] ?? r.mode}
                      {r.automatique && <span className="text-xs text-gray-500"> · {tr.automatic}</span>}
                      {r.auteur && <span className="text-xs text-gray-500"> · {r.auteur.nom}</span>}
                    </p>
                    {r.notes && <p className="text-gray-600 dark:text-gray-300 whitespace-pre-line">{r.notes}</p>}
                  </li>
                ))}
              </ol>
            )}
          </div>
        </div>
      )}

      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}

/** Note une relance faite autrement que par courriel depuis l'application (téléphone, courrier…). */
function NoterRelance({ facture, onClose, onNote }: { facture: FactureEnRetard; onClose: () => void; onNote: () => void }) {
  const { t } = useLanguage();
  const tr = t.reminders;
  const [saisie, setSaisie] = useState({ mode: 'telephone' as ModeRelance, date: aujourdhui(), notes: '' });
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  async function noter(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    try {
      await noterRelance(facture.facture_id, { mode: saisie.mode, date: saisie.date, notes: saisie.notes.trim() || undefined });
      onNote();
    } catch (err) {
      setErreur(messageErreur(err, tr.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <form onSubmit={noter} role="dialog" aria-modal="true" aria-labelledby="noter-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="noter-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tr.noteTitle.replace('{numero}', facture.numero)}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tr.close}><X size={18} /></button>
        </div>
        <p className="text-sm text-gray-500 dark:text-gray-400">{facture.client?.raison_sociale} · {tr.level.replace('{n}', String(facture.nb_relances + 1))}</p>
        <div className="grid grid-cols-2 gap-3">
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tr.mode}
            <select value={saisie.mode} onChange={e => setSaisie({ ...saisie, mode: e.target.value as ModeRelance })} className={classeChamp}>
              {MODES_RELANCE.map(m => <option key={m} value={m}>{tr.modes[m]}</option>)}
            </select>
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tr.date}
            <input type="date" required max={aujourdhui()} value={saisie.date} onChange={e => setSaisie({ ...saisie, date: e.target.value })} className={classeChamp} />
          </label>
        </div>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{tr.notes}
          <textarea rows={3} maxLength={2000} value={saisie.notes} placeholder={tr.notesHelp} onChange={e => setSaisie({ ...saisie, notes: e.target.value })} className={classeChamp} />
        </label>
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tr.cancel}</button>
          <button type="submit" disabled={occupe} className="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700 disabled:opacity-50">{tr.save}</button>
        </div>
      </form>
    </div>
  );
}
