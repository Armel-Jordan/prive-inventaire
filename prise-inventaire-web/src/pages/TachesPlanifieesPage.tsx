import { useEffect, useState } from 'react';
import { Timer, Play, Settings2, History, X } from 'lucide-react';
import {
  getTachesPlanifiees, modifierTachePlanifiee, executerTachePlanifiee, getHistoriqueTaches,
  type EtatTachesPlanifiees, type TachePlanifiee, type ReglageTache, type RecurrenceTache, type ExecutionTache,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { fuseauValide, reglageDe as reglageTache, resumeTache } from '@/lib/taches';

const RECURRENCES: RecurrenceTache[] = ['horaire', 'quotidienne', 'hebdomadaire', 'mensuelle', 'une_fois'];
const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeLibelle = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';

/** Traitements automatiques : activation, réglage de la fréquence, exécution manuelle, historique. */
export default function TachesPlanifieesPage() {
  const { t, language } = useLanguage();
  const ts = t.scheduledTasks;
  const locale = language === 'en' ? 'en-US' : 'fr-FR';

  const [etat, setEtat] = useState<EtatTachesPlanifiees | null>(null);
  const [version, setVersion] = useState(0);
  const [message, setMessage] = useState<{ erreur: boolean; texte: string } | null>(null);
  const [edition, setEdition] = useState<{ tache: TachePlanifiee; reglage: ReglageTache } | null>(null);
  const [erreurEdition, setErreurEdition] = useState('');
  const [enCours, setEnCours] = useState<number | null>(null);
  const [filtre, setFiltre] = useState<number | ''>('');
  const [page, setPage] = useState(1);
  const [historique, setHistorique] = useState<{ data: ExecutionTache[]; current_page: number; last_page: number } | null>(null);

  const fuseau = fuseauValide(etat?.fuseau);
  const date = (iso: string | null) => (iso ? new Date(iso).toLocaleString(locale, { timeZone: fuseau, dateStyle: 'short', timeStyle: 'short' }) : ts.never);
  const messageErreur = (e: unknown) => (e instanceof ApiError ? (e.status === 409 ? ts.alreadyRunning : e.message) : ts.error);
  const nom = (code: string) => ts.tasks[code]?.name ?? code;

  useEffect(() => {
    let actif = true;
    getTachesPlanifiees().then(e => { if (actif) setEtat(e); }).catch(e => { if (actif) setMessage({ erreur: true, texte: e instanceof ApiError ? e.message : ts.error }); });
    return () => { actif = false; };
  }, [version, ts.error]);

  useEffect(() => {
    let actif = true;
    getHistoriqueTaches({ tache_id: filtre === '' ? undefined : filtre, page })
      .then(h => { if (actif) setHistorique(h); })
      .catch(() => {});
    return () => { actif = false; };
  }, [filtre, page, version]);

  const resume = (tache: TachePlanifiee) => resumeTache(tache, ts, date);
  const reglageDe = (tache: TachePlanifiee): ReglageTache => reglageTache(tache, fuseau);

  async function enregistrer(tache: TachePlanifiee, reglage: ReglageTache) {
    try {
      await modifierTachePlanifiee(tache.id, reglage);
      setMessage({ erreur: false, texte: ts.saved });
      setVersion(v => v + 1);
      return true;
    } catch (e) {
      setMessage({ erreur: true, texte: messageErreur(e) });
      return false;
    }
  }

  async function executer(tache: TachePlanifiee) {
    setEnCours(tache.id);
    setMessage(null);
    try {
      const { execution } = await executerTachePlanifiee(tache.id);
      setMessage({ erreur: execution.resultat === 'erreur', texte: `${nom(tache.code)} : ${execution.message ?? ''}` });
    } catch (e) {
      setMessage({ erreur: true, texte: messageErreur(e) });
    } finally {
      setEnCours(null);
      setVersion(v => v + 1);
    }
  }

  async function validerEdition(e: React.FormEvent) {
    e.preventDefault();
    if (!edition) return;
    setErreurEdition('');
    try {
      await modifierTachePlanifiee(edition.tache.id, edition.reglage);
      setEdition(null);
      setMessage({ erreur: false, texte: ts.saved });
      setVersion(v => v + 1);
    } catch (erreur) {
      setErreurEdition(messageErreur(erreur));
    }
  }

  const modifierReglage = (valeurs: Partial<ReglageTache>) => setEdition(ed => (ed ? { ...ed, reglage: { ...ed.reglage, ...valeurs } } : ed));
  const badge = (resultat: 'succes' | 'erreur' | null) => resultat && (
    <span className={`px-2 py-0.5 rounded text-xs font-medium ${resultat === 'succes' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>
      {resultat === 'succes' ? ts.success : ts.failure}
    </span>
  );

  return (
    <div className="p-6">
      <div className="mb-4">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Timer className="text-purple-600" /> {ts.title}</h1>
        <p className="text-sm text-gray-500 dark:text-gray-400">{ts.subtitle}</p>
      </div>

      {etat && (
        <div className={`mb-4 px-4 py-3 rounded-lg text-sm max-w-5xl ${etat.dernier_passage ? 'bg-blue-50 text-blue-900 dark:bg-blue-900/30 dark:text-blue-100' : 'bg-amber-50 text-amber-900'}`}>
          <p>
            {etat.dernier_passage
              ? `${ts.lastPass.replace('{date}', date(etat.dernier_passage.date))} (${ts.sources[etat.dernier_passage.source]}).`
              : ts.noPass}{' '}
            {ts.timezone.replace('{fuseau}', etat.fuseau)}
          </p>
          {etat.dernier_passage?.source !== 'planificateur' && <p className="text-xs mt-1 opacity-80">{ts.cronHelp}</p>}
        </div>
      )}

      {message && <p className={`mb-4 px-3 py-2 rounded-lg text-sm max-w-5xl ${message.erreur ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}>{message.texte}</p>}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto max-w-5xl">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-4 py-3 font-medium">{ts.task}</th>
              <th className="px-4 py-3 font-medium">{ts.active}</th>
              <th className="px-4 py-3 font-medium">{ts.frequency}</th>
              <th className="px-4 py-3 font-medium">{ts.next}</th>
              <th className="px-4 py-3 font-medium">{ts.last}</th>
              <th className="px-4 py-3" />
            </tr>
          </thead>
          <tbody>
            {etat?.taches.map(tache => (
              <tr key={tache.id} className="border-t dark:border-gray-700 align-top">
                <td className="px-4 py-3 max-w-sm">
                  <p className="font-medium text-gray-900 dark:text-white">{nom(tache.code)}</p>
                  <p className="text-xs text-gray-500 dark:text-gray-400">{ts.tasks[tache.code]?.description}</p>
                  {tache.parametres?.jours !== undefined && (
                    <p className="text-xs text-gray-600 dark:text-gray-300 mt-0.5">
                      {ts.delayValue.replace('{libelle}', ts.tasks[tache.code]?.delay ?? ts.delay).replace('{jours}', String(tache.parametres.jours))}
                    </p>
                  )}
                  {tache.parametres?.emettre !== undefined && (
                    <p className="text-xs text-gray-600 dark:text-gray-300 mt-0.5">{tache.parametres.emettre ? ts.invoicesIssued : ts.invoicesDraft}</p>
                  )}
                </td>
                <td className="px-4 py-3">
                  <label className="inline-flex items-center cursor-pointer" title={ts.active}>
                    <input type="checkbox" className="sr-only peer" checked={tache.actif} aria-label={`${ts.active} — ${nom(tache.code)}`}
                      onChange={e => enregistrer(tache, { ...reglageDe(tache), actif: e.target.checked })} />
                    <span className="w-9 h-5 bg-gray-300 rounded-full relative transition-colors peer-checked:bg-purple-600 after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:w-4 after:h-4 after:bg-white after:rounded-full after:transition-transform peer-checked:after:translate-x-4" />
                  </label>
                </td>
                <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{resume(tache)}</td>
                <td className="px-4 py-3 whitespace-nowrap text-gray-700 dark:text-gray-300">{tache.actif ? date(tache.prochaine_execution) : ts.inactive}</td>
                <td className="px-4 py-3 whitespace-nowrap">
                  <span className="text-gray-700 dark:text-gray-300 mr-2">{date(tache.derniere_execution)}</span>
                  {tache.statut === 'en_cours' ? <span className="px-2 py-0.5 rounded text-xs bg-blue-100 text-blue-800">{ts.running}</span> : badge(tache.dernier_resultat)}
                </td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-1">
                    <button onClick={() => { setErreurEdition(''); setEdition({ tache, reglage: reglageDe(tache) }); }} title={ts.settings} aria-label={`${ts.settings} — ${nom(tache.code)}`}
                      className="p-1 text-blue-600 hover:bg-blue-50 rounded"><Settings2 size={16} /></button>
                    <button onClick={() => executer(tache)} disabled={enCours !== null} title={ts.runNow} aria-label={`${ts.runNow} — ${nom(tache.code)}`}
                      className="p-1 text-green-600 hover:bg-green-50 rounded disabled:opacity-40"><Play size={16} className={enCours === tache.id ? 'animate-pulse' : ''} /></button>
                    <button onClick={() => { setFiltre(tache.id); setPage(1); }} title={ts.history} aria-label={`${ts.history} — ${nom(tache.code)}`}
                      className="p-1 text-gray-600 hover:bg-gray-100 rounded"><History size={16} /></button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-8 max-w-5xl">
        <div className="flex flex-wrap items-center justify-between gap-3 mb-3">
          <h2 className="text-lg font-semibold dark:text-white flex items-center gap-2"><History size={18} /> {ts.history}</h2>
          <select value={filtre} onChange={e => { setFiltre(e.target.value === '' ? '' : Number(e.target.value)); setPage(1); }} aria-label={ts.task}
            className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm">
            <option value="">{ts.allTasks}</option>
            {etat?.taches.map(tache => <option key={tache.id} value={tache.id}>{nom(tache.code)}</option>)}
          </select>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                <th className="px-4 py-3 font-medium">{ts.date}</th>
                <th className="px-4 py-3 font-medium">{ts.task}</th>
                <th className="px-4 py-3 font-medium">{ts.mode}</th>
                <th className="px-4 py-3 font-medium">{ts.duration}</th>
                <th className="px-4 py-3 font-medium">{ts.result}</th>
                <th className="px-4 py-3 font-medium">{ts.message}</th>
              </tr>
            </thead>
            <tbody>
              {historique && historique.data.length === 0 && <tr><td colSpan={6} className="px-4 py-6 text-center text-gray-500">{ts.noHistory}</td></tr>}
              {historique?.data.map(execution => (
                <tr key={execution.id} className="border-t dark:border-gray-700 align-top">
                  <td className="px-4 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">{date(execution.debut)}</td>
                  <td className="px-4 py-2 dark:text-white">{nom(execution.code)}</td>
                  <td className="px-4 py-2 whitespace-nowrap text-gray-600 dark:text-gray-300">
                    {execution.mode === 'manuel' ? `${ts.manual}${execution.auteur ? ` ${ts.by} ${execution.auteur}` : ''}` : ts.planned}
                  </td>
                  <td className="px-4 py-2 whitespace-nowrap text-gray-600 dark:text-gray-300">
                    {execution.duree_ms !== null ? `${(execution.duree_ms / 1000).toLocaleString(locale, { maximumFractionDigits: 1 })} s` : '—'}
                  </td>
                  <td className="px-4 py-2">{badge(execution.resultat)}</td>
                  <td className="px-4 py-2 text-gray-700 dark:text-gray-300 max-w-md break-words">{execution.message}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {historique && historique.last_page > 1 && (
          <div className="flex items-center justify-end gap-3 mt-3 text-sm">
            <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="px-3 py-1.5 border rounded-lg dark:border-gray-600 dark:text-white disabled:opacity-40">{ts.previous}</button>
            <span className="text-gray-600 dark:text-gray-300">{ts.pageOf.replace('{page}', String(historique.current_page)).replace('{total}', String(historique.last_page))}</span>
            <button disabled={page >= historique.last_page} onClick={() => setPage(p => p + 1)} className="px-3 py-1.5 border rounded-lg dark:border-gray-600 dark:text-white disabled:opacity-40">{ts.next_}</button>
          </div>
        )}
      </div>

      {edition && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <form onSubmit={validerEdition} className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md max-h-[90vh] overflow-y-auto space-y-4">
            <div className="flex items-start justify-between gap-3">
              <h3 className="text-lg font-bold dark:text-white">{nom(edition.tache.code)}</h3>
              <button type="button" onClick={() => setEdition(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" title={ts.close}><X size={18} /></button>
            </div>
            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
              <input type="checkbox" className="rounded" checked={edition.reglage.actif} onChange={e => modifierReglage({ actif: e.target.checked })} /> {ts.active}
            </label>
            <div>
              <label htmlFor="tache-recurrence" className={classeLibelle}>{ts.recurrence}</label>
              <select id="tache-recurrence" value={edition.reglage.recurrence} className={classeChamp}
                onChange={e => modifierReglage({
                  recurrence: e.target.value as RecurrenceTache,
                  jours_semaine: e.target.value === 'hebdomadaire' ? (edition.reglage.jours_semaine ?? [1, 2, 3, 4, 5]) : null,
                  jour_du_mois: e.target.value === 'mensuelle' ? (edition.reglage.jour_du_mois ?? 1) : null,
                })}>
                {RECURRENCES.map(r => <option key={r} value={r}>{ts.recurrences[r]}</option>)}
              </select>
            </div>

            {edition.reglage.recurrence === 'horaire' && (
              <div>
                <label htmlFor="tache-minute" className={classeLibelle}>{ts.minute}</label>
                <input id="tache-minute" type="number" min={0} max={59} className={classeChamp} value={Number(edition.reglage.heure.slice(3))}
                  onChange={e => modifierReglage({ heure: `00:${String(Math.min(59, Math.max(0, Number(e.target.value) || 0))).padStart(2, '0')}` })} />
              </div>
            )}
            {['quotidienne', 'hebdomadaire', 'mensuelle'].includes(edition.reglage.recurrence) && (
              <div>
                <label htmlFor="tache-heure" className={classeLibelle}>{ts.time}</label>
                <input id="tache-heure" type="time" required className={classeChamp} value={edition.reglage.heure} onChange={e => modifierReglage({ heure: e.target.value })} />
              </div>
            )}
            {edition.reglage.recurrence === 'hebdomadaire' && (
              <fieldset>
                <legend className={classeLibelle}>{ts.days}</legend>
                <div className="flex flex-wrap gap-x-4 gap-y-1.5">
                  {ts.weekdays.map((jour, index) => (
                    <label key={jour} className="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                      <input type="checkbox" className="rounded" checked={(edition.reglage.jours_semaine ?? []).includes(index + 1)}
                        onChange={e => {
                          const jours = edition.reglage.jours_semaine ?? [];
                          modifierReglage({ jours_semaine: e.target.checked ? [...jours, index + 1].sort() : jours.filter(j => j !== index + 1) });
                        }} />
                      {jour}
                    </label>
                  ))}
                </div>
              </fieldset>
            )}
            {edition.reglage.recurrence === 'mensuelle' && (
              <div>
                <label htmlFor="tache-jour" className={classeLibelle}>{ts.dayOfMonth}</label>
                <input id="tache-jour" type="number" min={1} max={31} required className={classeChamp} value={edition.reglage.jour_du_mois ?? 1}
                  onChange={e => modifierReglage({ jour_du_mois: Number(e.target.value) || 1 })} />
                <p className="text-xs text-gray-500 mt-1">{ts.dayOfMonthHelp}</p>
              </div>
            )}
            {edition.reglage.recurrence === 'une_fois' && (
              <div>
                <label htmlFor="tache-date" className={classeLibelle}>{ts.date_}</label>
                <input id="tache-date" type="datetime-local" required className={classeChamp} value={edition.reglage.prochaine_execution ?? ''}
                  onChange={e => modifierReglage({ prochaine_execution: e.target.value })} />
              </div>
            )}
            {edition.tache.parametres?.jours !== undefined && (
              <div>
                <label htmlFor="tache-delai" className={classeLibelle}>{ts.tasks[edition.tache.code]?.delay ?? ts.delay} ({ts.daysUnit})</label>
                <input id="tache-delai" type="number" min={0} max={365} required className={classeChamp}
                  value={edition.reglage.parametres?.jours ?? edition.tache.parametres.jours}
                  onChange={e => modifierReglage({ parametres: { ...edition.reglage.parametres, jours: Number(e.target.value) } })} />
              </div>
            )}
            {edition.tache.parametres?.emettre !== undefined && (
              <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" checked={Boolean(edition.reglage.parametres?.emettre ?? edition.tache.parametres.emettre)}
                  onChange={e => modifierReglage({ parametres: { ...edition.reglage.parametres, emettre: e.target.checked } })} />
                {ts.issueInvoices}
              </label>
            )}
            {etat && <p className="text-xs text-gray-500">{ts.timezone.replace('{fuseau}', etat.fuseau)}</p>}

            {erreurEdition && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreurEdition}</p>}
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setEdition(null)} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{ts.cancel}</button>
              <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{ts.save}</button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}
