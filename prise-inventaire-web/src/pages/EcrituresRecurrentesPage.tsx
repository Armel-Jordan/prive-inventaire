import { Fragment, useCallback, useEffect, useState } from 'react';
import { ChevronDown, ChevronRight, Pause, Play, Repeat, Trash2 } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { aujourdhui, formatDateDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import {
  activerRecurrence, convertirRecurrences, getRecurrences, montant, supprimerRecurrence,
  type ConversionRecurrences, type RecurrenceComptable,
} from '@/services/comptabilite';

/**
 * Écritures récurrentes (Finance) : récurrences planifiées depuis le journal, leurs dates (converties, échues, à venir) ;
 * conversion des dates échues en écritures, suspension, reprise, suppression des dates restantes.
 */
export default function EcrituresRecurrentesPage() {
  const { t, language } = useLanguage();
  const tr = t.recurringEntries;
  const { peut } = usePermissions();
  const ecrire = peut('comptabilite', 'ecrire');
  const locale = language === 'en' ? 'en-US' : 'fr-FR';
  const [recurrences, setRecurrences] = useState<RecurrenceComptable[] | null>(null);
  const [echues, setEchues] = useState(0);
  const [ouvertes, setOuvertes] = useState<Set<number>>(new Set());
  const [compteRendu, setCompteRendu] = useState<ConversionRecurrences | null>(null);
  const [erreur, setErreur] = useState('');
  const [enCours, setEnCours] = useState(false);
  const jour = aujourdhui();

  const charger = useCallback(() => getRecurrences().then(r => { setRecurrences(r.recurrences); setEchues(r.echues); }, e => setErreur(messageErreur(e, tr.error))), [tr.error]);
  useEffect(() => { charger(); }, [charger]);

  async function executer(action: () => Promise<unknown>) {
    setEnCours(true);
    setErreur('');
    try {
      const resultat = await action();
      if (resultat && typeof resultat === 'object' && 'converties' in resultat) setCompteRendu(resultat as ConversionRecurrences);
      await charger();
    } catch (e) {
      setErreur(messageErreur(e, tr.error));
    } finally {
      setEnCours(false);
    }
  }

  const basculer = (id: number) => setOuvertes(o => { const n = new Set(o); if (n.has(id)) n.delete(id); else n.add(id); return n; });
  const etat = (r: RecurrenceComptable) => (r.prochaine === null ? tr.finished : r.active ? tr.active : tr.suspended);
  const total = (r: RecurrenceComptable) => r.lignes.reduce((s, l) => s + Number(l.debit || 0), 0);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Repeat size={24} /> {tr.title}</h1>
          <p className="text-sm text-gray-500 max-w-3xl">{tr.subtitle}</p>
        </div>
        {ecrire && echues > 0 && (
          <button type="button" disabled={enCours} onClick={() => executer(() => convertirRecurrences())}
            className="flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">
            <Repeat size={16} /> {tr.convertDue} ({echues})
          </button>
        )}
      </div>

      {echues > 0 && <p className="text-sm text-amber-800 dark:text-amber-300">{tr.due.replace('{n}', String(echues))}</p>}
      {compteRendu && (
        <div className="px-3 py-2 rounded-lg text-sm bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200 space-y-1" role="status">
          <p>{compteRendu.message}</p>
          {compteRendu.erreurs.map(e => (
            <p key={`${e.recurrence}-${e.date}`} className="text-red-700 dark:text-red-300">
              {tr.refused.replace('{date}', formatDateDocument(e.date, language)).replace('{recurrence}', String(e.recurrence)).replace('{message}', e.message)}
            </p>
          ))}
        </div>
      )}
      {erreur && <div className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}

      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
        {recurrences === null ? <p className="p-4 text-sm text-gray-500">{tr.loading}</p> : recurrences.length === 0 ? <p className="p-4 text-sm text-gray-500">{tr.none}</p> : (
          <table className="w-full text-sm" aria-label={tr.title}>
            <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
              <tr>
                <th className="px-4 py-3 text-left">{tr.number}</th>
                <th className="px-4 py-3 text-left">{tr.label}</th>
                <th className="px-4 py-3 text-left">{tr.frequency}</th>
                <th className="px-4 py-3 text-right">{t.accounting.journal.total}</th>
                <th className="px-4 py-3 text-left">{tr.progress}</th>
                <th className="px-4 py-3 text-left">{tr.next}</th>
                <th className="px-4 py-3 text-left">{tr.state}</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
              {recurrences.map(r => (
                <Fragment key={r.id}>
                  <tr className="align-top">
                    <td className="px-4 py-3 font-mono">
                      <button type="button" onClick={() => basculer(r.id)} aria-expanded={ouvertes.has(r.id)} aria-label={`${tr.dates} ${r.numero}`} className="inline-flex items-center gap-1">
                        {ouvertes.has(r.id) ? <ChevronDown size={14} /> : <ChevronRight size={14} />} {r.numero}
                      </button>
                    </td>
                    <td className="px-4 py-3">
                      <div>{r.libelle}</div>
                      <div className="text-xs text-gray-500">{r.journal?.code}{r.modele ? ` · ${tr.template.replace('{numero}', r.modele.numero)}` : ''}</div>
                    </td>
                    <td className="px-4 py-3">{tr.frequencies[r.frequence] ?? r.frequence}</td>
                    <td className="px-4 py-3 text-right whitespace-nowrap">{montant(total(r), locale)}</td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      {r.converties} / {r.echeances.length}
                      {r.echues > 0 && <span className="ml-2 px-1.5 py-0.5 rounded text-xs bg-amber-100 text-amber-800">{r.echues} {tr.dueBadge}</span>}
                    </td>
                    <td className="px-4 py-3">{r.prochaine ? formatDateDocument(r.prochaine, language) : '—'}</td>
                    <td className="px-4 py-3">{etat(r)}</td>
                    <td className="px-4 py-3">
                      {ecrire && r.prochaine !== null && (
                        <div className="flex justify-end gap-1">
                          <button type="button" disabled={enCours} onClick={() => executer(() => activerRecurrence(r.id, !r.active))}
                            title={r.active ? tr.suspend : tr.resume} aria-label={`${r.active ? tr.suspend : tr.resume} ${r.numero}`}
                            className="p-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded">{r.active ? <Pause size={16} /> : <Play size={16} />}</button>
                          <button type="button" disabled={enCours} title={tr.delete} aria-label={`${tr.delete} ${r.numero}`}
                            onClick={() => confirm(tr.confirmDelete.replace('{numero}', String(r.numero))) && executer(() => supprimerRecurrence(r.id))}
                            className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><Trash2 size={16} /></button>
                        </div>
                      )}
                    </td>
                  </tr>
                  {ouvertes.has(r.id) && (
                    <tr className="bg-gray-50/70 dark:bg-gray-900/30">
                      <td />
                      <td colSpan={7} className="px-4 py-2">
                        <ul className="flex flex-wrap gap-2 text-xs" aria-label={`${tr.dates} ${r.numero}`}>
                          {r.echeances.map(e => {
                            const echue = !e.ecriture && e.date_prevue <= jour;
                            return (
                              <li key={e.id} className={`px-2 py-1 rounded border dark:border-gray-600 ${e.ecriture ? 'bg-green-50 dark:bg-green-900/20' : echue ? 'bg-amber-50 dark:bg-amber-900/20' : ''}`}>
                                {formatDateDocument(e.date_prevue, language)}
                                {e.ecriture && ` · ${tr.entry.replace('{numero}', e.ecriture.numero)}`}
                                {echue && ` · ${tr.dueBadge}`}
                                {echue && ecrire && r.active && (
                                  <button type="button" disabled={enCours} onClick={() => executer(() => convertirRecurrences([e.id]))}
                                    className="ml-2 text-purple-700 dark:text-purple-300 hover:underline">{tr.convert}</button>
                                )}
                              </li>
                            );
                          })}
                        </ul>
                      </td>
                    </tr>
                  )}
                </Fragment>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
