import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Download, FileText, HandCoins, X } from 'lucide-react';
import {
  getAnalyseComptesClients, exporterAnalyseComptesClients, getReleveClient, exporterReleveClient, TRANCHES,
  type AnalyseComptesClients, type CompteClient, type ReleveClient, type Tranche,
} from '@/services/comptesClients';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
// Plus le retard est ancien, plus la case est marquée.
const COULEURS: Record<Tranche, string> = {
  non_echu: '',
  jours_1_30: 'bg-amber-50 text-amber-900',
  jours_31_60: 'bg-orange-100 text-orange-900',
  jours_61_90: 'bg-red-100 text-red-900',
  plus_90: 'bg-red-200 text-red-950 font-semibold',
};
const aujourdhui = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

/** Comptes clients : sommes dues par client à une date, par ancienneté du retard, et relevé de compte. */
export default function ComptesClientsPage() {
  const { t, language } = useLanguage();
  const tr = t.receivables;
  const locale = language === 'en' ? 'en-US' : 'fr-FR';

  const [date, setDate] = useState(aujourdhui());
  const [recherche, setRecherche] = useState('');
  const [analyse, setAnalyse] = useState<AnalyseComptesClients | null>(null);
  const [erreur, setErreur] = useState('');
  const [releve, setReleve] = useState<{ client: CompteClient; du: string; au: string; donnees: ReleveClient | null } | null>(null);

  useEffect(() => {
    if (!date) return;
    let actif = true;
    getAnalyseComptesClients(date).then(a => { if (actif) { setAnalyse(a); setErreur(''); } }).catch(e => { if (actif) setErreur(messageErreur(e, tr.error)); });
    return () => { actif = false; };
  }, [date, tr.error]);

  const clientReleve = releve?.client.client_id;
  const releveDu = releve?.du;
  const releveAu = releve?.au;
  useEffect(() => {
    if (!clientReleve || !releveDu || !releveAu) return;
    let actif = true;
    getReleveClient(clientReleve, releveDu, releveAu)
      .then(d => { if (actif) setReleve(r => (r ? { ...r, donnees: d } : r)); })
      .catch(e => { if (actif) setErreur(messageErreur(e, tr.error)); });
    return () => { actif = false; };
  }, [clientReleve, releveDu, releveAu, tr.error]);

  const solde = (valeur: number) => valeur.toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  // Montant d'une case : vide quand il est nul, pour que le tableau se lise.
  const montant = (valeur: number) => (valeur ? solde(valeur) : '');
  const jour = (iso: string) => { const [a, m, j] = iso.split('-').map(Number); return new Date(a, m - 1, j).toLocaleDateString(locale); };
  const clients = useMemo(() => {
    const terme = recherche.trim().toLowerCase();
    return (analyse?.clients ?? []).filter(c => !terme || c.code.toLowerCase().includes(terme) || c.raison_sociale.toLowerCase().includes(terme));
  }, [analyse, recherche]);
  const enRetard = analyse ? analyse.totaux.total - analyse.totaux.non_echu : 0;
  // Colonnes des crédits (notes de crédit non utilisées) seulement quand il y en a.
  const avecCredits = (analyse?.totaux.credits ?? 0) > 0;
  const ouvrirReleve = (client: CompteClient) => setReleve({ client, du: `${date.slice(0, 4)}-01-01`, au: date, donnees: null });

  return (
    <div className="p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><HandCoins className="text-purple-600" /> {tr.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tr.subtitle}</p>
        </div>
        {analyse && analyse.clients.length > 0 && (
          <button onClick={() => exporterAnalyseComptesClients(date).catch(e => setErreur(messageErreur(e, tr.error)))}
            className="flex items-center gap-2 px-3 py-2 text-sm border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
            <Download size={16} /> {tr.export}
          </button>
        )}
      </div>

      <div className="flex flex-wrap items-end gap-2 mb-4 max-w-6xl">
        <label className="text-xs text-gray-500 dark:text-gray-400 w-44">{tr.asOf}
          <input type="date" value={date} onChange={e => setDate(e.target.value)} className={classeChamp} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400 flex-1 min-w-[12rem]">{tr.search}
          <input value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={tr.searchHelp} className={classeChamp} />
        </label>
      </div>

      {erreur && <p role="status" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 max-w-6xl">{erreur}</p>}

      {analyse && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 max-w-6xl">
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
            <p className="text-xs uppercase text-gray-500 dark:text-gray-400">{tr.totalDue}</p>
            <p className="text-2xl font-bold text-gray-900 dark:text-white">{solde(analyse.totaux.total)}</p>
          </div>
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
            <p className="text-xs uppercase text-gray-500 dark:text-gray-400">{tr.overdue}</p>
            <p className="text-2xl font-bold text-orange-700">{solde(enRetard)}</p>
          </div>
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
            <p className="text-xs uppercase text-gray-500 dark:text-gray-400">{tr.over90}</p>
            <p className="text-2xl font-bold text-red-700">{solde(analyse.totaux.plus_90)}</p>
          </div>
        </div>
      )}

      {analyse && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto max-w-6xl">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                <th className="px-3 py-2 font-medium">{tr.client}</th>
                <th className="px-3 py-2 font-medium text-right">{tr.total}</th>
                {TRANCHES.map(tranche => <th key={tranche} className="px-3 py-2 font-medium text-right whitespace-nowrap">{tr.buckets[tranche]}</th>)}
                {avecCredits && (
                  <>
                    <th className="px-3 py-2 font-medium text-right" title={tr.creditsHelp}>{tr.credits}</th>
                    <th className="px-3 py-2 font-medium text-right whitespace-nowrap">{tr.netBalance}</th>
                  </>
                )}
                <th className="px-3 py-2" />
              </tr>
            </thead>
            <tbody>
              {clients.length === 0 && <tr><td colSpan={avecCredits ? 10 : 8} className="px-4 py-6 text-center text-gray-500">{analyse.clients.length ? tr.noResult : tr.empty}</td></tr>}
              {clients.map(client => (
                <tr key={client.client_id} className="border-t dark:border-gray-700">
                  <td className="px-3 py-2">
                    <Link to={`/clients/${client.client_id}`} className="hover:underline">
                      <span className="font-mono text-gray-500 dark:text-gray-400">{client.code}</span> <span className="text-gray-900 dark:text-white">{client.raison_sociale}</span>
                    </Link>
                    <span className="block text-xs text-gray-500">{tr.invoices.replace('{n}', String(client.nb_factures))}{client.retard_max > 0 && ` · ${tr.maxDelay.replace('{n}', String(client.retard_max))}`}</span>
                  </td>
                  <td className="px-3 py-2 text-right whitespace-nowrap font-semibold text-gray-900 dark:text-white">{montant(client.total)}</td>
                  {TRANCHES.map(tranche => (
                    <td key={tranche} className={`px-3 py-2 text-right whitespace-nowrap ${client[tranche] ? COULEURS[tranche] : 'text-gray-400'}`}>{montant(client[tranche])}</td>
                  ))}
                  {avecCredits && (
                    <>
                      <td className="px-3 py-2 text-right whitespace-nowrap text-indigo-700 dark:text-indigo-300">{montant(client.credits)}</td>
                      <td className="px-3 py-2 text-right whitespace-nowrap font-semibold text-gray-900 dark:text-white">{solde(client.solde)}</td>
                    </>
                  )}
                  <td className="px-3 py-2 text-right">
                    <button onClick={() => ouvrirReleve(client)} aria-label={`${tr.statement} — ${client.raison_sociale}`} title={tr.statement}
                      className="p-1 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/30 rounded"><FileText size={16} /></button>
                  </td>
                </tr>
              ))}
            </tbody>
            {analyse.clients.length > 0 && (
              <tfoot>
                <tr className="border-t-2 dark:border-gray-600 font-semibold text-gray-900 dark:text-white">
                  <td className="px-3 py-2">{tr.totals}</td>
                  <td className="px-3 py-2 text-right whitespace-nowrap">{montant(analyse.totaux.total)}</td>
                  {TRANCHES.map(tranche => <td key={tranche} className="px-3 py-2 text-right whitespace-nowrap">{montant(analyse.totaux[tranche])}</td>)}
                  {avecCredits && (
                    <>
                      <td className="px-3 py-2 text-right whitespace-nowrap">{montant(analyse.totaux.credits)}</td>
                      <td className="px-3 py-2 text-right whitespace-nowrap">{solde(analyse.totaux.solde)}</td>
                    </>
                  )}
                  <td />
                </tr>
              </tfoot>
            )}
          </table>
        </div>
      )}

      {releve && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div role="dialog" aria-labelledby="releve-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-4xl max-h-[92vh] overflow-y-auto space-y-4">
            <div className="flex items-start justify-between gap-3">
              <h2 id="releve-titre" className="text-lg font-bold dark:text-white">{tr.statementTitle.replace('{client}', releve.client.raison_sociale)}</h2>
              <button type="button" onClick={() => setReleve(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label={tr.close}><X size={18} /></button>
            </div>
            <div className="flex flex-wrap items-end gap-2">
              <label className="text-xs text-gray-500 dark:text-gray-400 w-44">{tr.from}
                <input type="date" value={releve.du} onChange={e => setReleve({ ...releve, du: e.target.value })} className={classeChamp} />
              </label>
              <label className="text-xs text-gray-500 dark:text-gray-400 w-44">{tr.to}
                <input type="date" value={releve.au} onChange={e => setReleve({ ...releve, au: e.target.value })} className={classeChamp} />
              </label>
              <button onClick={() => exporterReleveClient(releve.client.client_id, releve.client.code, releve.du, releve.au).catch(e => setErreur(messageErreur(e, tr.error)))}
                className="ml-auto flex items-center gap-2 px-3 py-2 text-sm border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                <Download size={16} /> {tr.export}
              </button>
            </div>
            {releve.donnees && (
              <>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300 border-b dark:border-gray-700">
                        <th className="py-2 pr-3 font-medium">{tr.date}</th>
                        <th className="py-2 pr-3 font-medium">{tr.label}</th>
                        <th className="py-2 pr-3 font-medium text-right">{tr.debit}</th>
                        <th className="py-2 pr-3 font-medium text-right">{tr.credit}</th>
                        <th className="py-2 font-medium text-right">{tr.balance}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr className="bg-gray-50/70 dark:bg-gray-900/30 font-medium">
                        <td className="py-1.5 pr-3 whitespace-nowrap text-gray-700 dark:text-gray-300">{jour(releve.donnees.du)}</td>
                        <td className="py-1.5 pr-3 text-gray-800 dark:text-gray-200" colSpan={3}>{tr.opening}</td>
                        <td className="py-1.5 text-right whitespace-nowrap text-gray-900 dark:text-white">{solde(releve.donnees.solde_ouverture)}</td>
                      </tr>
                      {releve.donnees.lignes.length === 0 && <tr><td colSpan={5} className="py-4 text-center text-gray-500">{tr.noMovement}</td></tr>}
                      {releve.donnees.lignes.map((ligne, index) => (
                        <tr key={index} className="border-t dark:border-gray-700">
                          <td className="py-1.5 pr-3 whitespace-nowrap text-gray-700 dark:text-gray-300">{jour(ligne.date)}</td>
                          <td className="py-1.5 pr-3 text-gray-800 dark:text-gray-200">{ligne.libelle}{(ligne.type === 'paiement' || ligne.type === 'remboursement' || ligne.type === 'acompte') && ligne.piece && <span className="text-xs text-gray-500"> · {ligne.piece}</span>}</td>
                          <td className="py-1.5 pr-3 text-right whitespace-nowrap text-gray-900 dark:text-white">{montant(ligne.debit)}</td>
                          <td className="py-1.5 pr-3 text-right whitespace-nowrap text-green-700">{montant(ligne.credit)}</td>
                          <td className="py-1.5 text-right whitespace-nowrap text-gray-700 dark:text-gray-300">{solde(ligne.solde)}</td>
                        </tr>
                      ))}
                    </tbody>
                    <tfoot>
                      <tr className="border-t-2 dark:border-gray-600 font-semibold text-gray-900 dark:text-white">
                        <td className="py-2 pr-3" colSpan={2}>{tr.closing}</td>
                        <td className="py-2 pr-3 text-right whitespace-nowrap">{montant(releve.donnees.total_debit)}</td>
                        <td className="py-2 pr-3 text-right whitespace-nowrap">{montant(releve.donnees.total_credit)}</td>
                        <td className="py-2 text-right whitespace-nowrap">{solde(releve.donnees.solde_final)}</td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
                <div>
                  <h3 className="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-2">{tr.openInvoices.replace('{date}', jour(releve.donnees.au))}</h3>
                  {releve.donnees.factures_dues.length === 0 ? <p className="text-sm text-gray-500">{tr.nothingDue}</p> : (
                    <ul className="text-sm divide-y dark:divide-gray-700">
                      {releve.donnees.factures_dues.map(f => (
                        <li key={f.facture_id} className="flex flex-wrap justify-between gap-2 py-1.5">
                          <span className="text-gray-800 dark:text-gray-200">{f.numero} · {jour(f.date_facture)}{f.date_echeance && ` · ${tr.due} ${jour(f.date_echeance)}`}</span>
                          <span className="flex items-center gap-2">
                            <span className={`px-2 py-0.5 rounded text-xs ${COULEURS[f.tranche] || 'bg-gray-100 text-gray-700'}`}>{f.retard > 0 ? tr.daysLate.replace('{n}', String(f.retard)) : tr.buckets.non_echu}</span>
                            <span className="font-medium text-gray-900 dark:text-white whitespace-nowrap">{montant(f.reste)}</span>
                          </span>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
                {releve.donnees.acomptes_disponibles.length > 0 && (
                  <div>
                    <h3 className="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-1">{tr.availableDeposits.replace('{date}', jour(releve.donnees.au))}</h3>
                    <ul className="text-sm divide-y dark:divide-gray-700">
                      {releve.donnees.acomptes_disponibles.map(a => (
                        <li key={a.acompte_id} className="flex flex-wrap justify-between gap-2 py-1.5">
                          <Link to={`/acomptes?recherche=${encodeURIComponent(a.reference ?? '')}`} className="text-indigo-700 dark:text-indigo-300 hover:underline">
                            {tr.deposit} · {jour(a.date_acompte)}{a.reference && ` · ${a.reference}`}
                          </Link>
                          <span className="font-medium text-gray-900 dark:text-white whitespace-nowrap">{montant(a.disponible)}</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
                {releve.donnees.credits_disponibles.length > 0 && (
                  <div>
                    <h3 className="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-1">{tr.availableCredits.replace('{date}', jour(releve.donnees.au))}</h3>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">{tr.creditsHelp}</p>
                    <ul className="text-sm divide-y dark:divide-gray-700">
                      {releve.donnees.credits_disponibles.map(c => (
                        <li key={c.avoir_id} className="flex flex-wrap justify-between gap-2 py-1.5">
                          <Link to={`/notes-credit?recherche=${encodeURIComponent(c.numero)}`} className="text-indigo-700 dark:text-indigo-300 hover:underline">{tr.creditNote} {c.numero} · {jour(c.date_avoir)}</Link>
                          <span className="font-medium text-gray-900 dark:text-white whitespace-nowrap">{montant(c.disponible)}</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
