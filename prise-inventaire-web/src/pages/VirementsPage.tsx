import { useEffect, useState } from 'react';
import { AlertTriangle, Ban, Download, Send } from 'lucide-react';
import FenetreLotVirements from '@/components/virements/FenetreLotVirements';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument, formatDateHeure, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import {
  getLotsVirements, getVirements, ibanLisible, refuserLotVirements, STATUTS_VIREMENT, telechargerLotVirements,
  type CompteVirement, type DebourseVirement, type LotVirements, type StatutVirement,
} from '@/services/virements';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
type Onglet = StatutVirement | 'lots';

/**
 * Virements aux fournisseurs (Achats → Virements) : les déboursés payés par virement à transmettre (avec le compte du
 * fournisseur à créditer et les alertes : pas de compte, IBAN invalide, coordonnées récemment modifiées), transmis ou
 * refusés ; un lot (fichier SEPA ou CSV) part d'un compte bancaire dans une devise ; l'historique des lots, leur
 * fichier à télécharger de nouveau, le refus de la banque.
 */
export default function VirementsPage() {
  const { t, language } = useLanguage();
  const tv = t.transfers;
  const { peut } = usePermissions();
  const peutGenerer = peut('debourses', 'virements');
  const montant = useMontantDocument();
  const [onglet, setOnglet] = useState<Onglet>('a_transmettre');
  const [compteId, setCompteId] = useState('');
  const [debourses, setDebourses] = useState<DebourseVirement[]>([]);
  const [comptes, setComptes] = useState<CompteVirement[]>([]);
  const [lots, setLots] = useState<LotVirements[]>([]);
  const [choisis, setChoisis] = useState<number[]>([]);
  const [fenetre, setFenetre] = useState(false);
  const [message, setMessage] = useState('');
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);

  useEffect(() => {
    let actif = true;
    const compte = Number(compteId) || undefined;
    const chargement = onglet === 'lots'
      ? getLotsVirements(compte).then(l => { if (actif) setLots(l); })
      : getVirements(onglet, compte).then(r => { if (actif) { setDebourses(r.debourses); setComptes(r.comptes); } });
    chargement.then(() => { if (actif) setErreur(''); }, e => { if (actif) setErreur(messageErreur(e, tv.error)); });
    return () => { actif = false; };
  }, [onglet, compteId, version, tv.error]);

  const recharger = () => setVersion(v => v + 1);
  const selection = debourses.filter(d => choisis.includes(d.id));
  const totalSelection = selection.reduce((s, d) => s + d.montant, 0);
  // Un lot part d'un seul compte bancaire, dans une seule devise.
  const comptesSelection = [...new Set(selection.map(d => d.compte_bancaire?.id ?? 0))];
  const devisesSelection = [...new Set(selection.map(d => d.devise ?? ''))];
  const compteLot = comptesSelection.length === 1 ? comptes.find(c => c.id === comptesSelection[0]) : undefined;
  const motifBlocage = selection.length === 0 ? '' : !compteLot ? tv.oneAccount : devisesSelection.length > 1 ? tv.oneCurrency : '';

  async function refuser(lot: LotVirements) {
    const motif = prompt(tv.refuseReason.replace('{numero}', String(lot.numero)));
    if (motif === null) return;
    try {
      await refuserLotVirements(lot.id, motif.trim() || null);
      setMessage(tv.refused.replace('{numero}', String(lot.numero)));
      recharger();
    } catch (e) {
      setErreur(messageErreur(e, tv.error));
    }
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Send size={24} /> {tv.title}</h1>
        <p className="text-sm text-gray-500 max-w-3xl">{tv.subtitle}</p>
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <div role="tablist" className="flex gap-1 border-b dark:border-gray-700">
          {([...STATUTS_VIREMENT, 'lots'] as Onglet[]).map(o => (
            <button key={o} role="tab" type="button" aria-selected={onglet === o} onClick={() => { setOnglet(o); setChoisis([]); }}
              className={`px-4 py-2 text-sm border-b-2 -mb-px ${onglet === o ? 'border-blue-600 text-blue-700 dark:text-blue-300 font-medium' : 'border-transparent text-gray-600 dark:text-gray-300 hover:text-gray-900'}`}>
              {tv.tabs[o]}
            </button>
          ))}
        </div>
        <label className="ml-auto text-xs text-gray-500 dark:text-gray-400">{tv.account}
          <select value={compteId} onChange={e => { setCompteId(e.target.value); setChoisis([]); }} className={`${classeChamp} block`}>
            <option value="">{tv.allAccounts}</option>
            {comptes.map(c => <option key={c.id} value={c.id}>{c.nom}</option>)}
          </select>
        </label>
      </div>
      <p className="text-xs text-gray-500 max-w-4xl">{tv.help}</p>
      {message && <div role="status" className="px-3 py-2 rounded-lg text-sm bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200">{message}</div>}
      {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}

      {onglet !== 'lots' ? (
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm">
          {onglet === 'a_transmettre' && peutGenerer && debourses.length > 0 && (
            <div className="p-4 border-b dark:border-gray-700 flex flex-wrap items-center gap-3">
              <span className="text-sm dark:text-gray-200">{tv.selection.replace('{n}', String(selection.length)).replace('{total}', montant(totalSelection, devisesSelection.length === 1 ? devisesSelection[0] || null : null))}</span>
              {motifBlocage && <span className="text-xs text-amber-700 dark:text-amber-300">{motifBlocage}</span>}
              <button type="button" disabled={selection.length === 0 || Boolean(motifBlocage)} onClick={() => setFenetre(true)}
                className="ml-auto flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-40">
                <Send size={16} /> {tv.generate}
              </button>
            </div>
          )}
          {debourses.length === 0 ? <p className="p-4 text-sm text-gray-500">{tv.none}</p> : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[960px] text-sm" aria-label={tv.tabs[onglet]}>
                <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                  <tr>
                    {onglet === 'a_transmettre' && peutGenerer && (
                      <th className="px-3 py-2">
                        <input type="checkbox" aria-label={tv.selectAll} checked={debourses.every(d => choisis.includes(d.id))}
                          onChange={e => setChoisis(e.target.checked ? debourses.map(d => d.id) : [])} />
                      </th>
                    )}
                    <th className="px-3 py-2 text-left">{tv.disbursement}</th>
                    <th className="px-3 py-2 text-left">{tv.supplier}</th>
                    <th className="px-3 py-2 text-left">{tv.beneficiary}</th>
                    <th className="px-3 py-2 text-left">{tv.account}</th>
                    <th className="px-3 py-2 text-right">{tv.amount}</th>
                    <th className="px-3 py-2 text-left">{onglet === 'a_transmettre' ? tv.alerts : tv.batch}</th>
                  </tr>
                </thead>
                <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                  {debourses.map(d => (
                    <tr key={d.id} className="align-top">
                      {onglet === 'a_transmettre' && peutGenerer && (
                        <td className="px-3 py-2 text-center">
                          <input type="checkbox" aria-label={`${tv.select} ${d.numero}`} checked={choisis.includes(d.id)}
                            onChange={e => setChoisis(e.target.checked ? [...choisis, d.id] : choisis.filter(id => id !== d.id))} />
                        </td>
                      )}
                      <td className="px-3 py-2 whitespace-nowrap">
                        <span className="font-mono">{d.numero}</span>
                        <span className="block text-xs text-gray-500">{formatDateDocument(d.date_debourse, language)}{d.factures ? ` · ${d.factures}` : ''}</span>
                      </td>
                      <td className="px-3 py-2">{d.fournisseur?.raison_sociale}</td>
                      <td className="px-3 py-2">
                        {d.beneficiaire ? (
                          <>
                            <span>{d.beneficiaire.titulaire || d.beneficiaire.banque}</span>
                            <span className={`block text-xs font-mono ${d.beneficiaire.iban && !d.beneficiaire.iban_valide ? 'text-red-700 dark:text-red-300' : 'text-gray-500'}`}>
                              {d.beneficiaire.iban ? ibanLisible(d.beneficiaire.iban) : d.beneficiaire.numero_compte}
                            </span>
                            <span className="block text-xs text-gray-500">{[d.beneficiaire.banque, d.beneficiaire.bic].filter(Boolean).join(' · ')}</span>
                          </>
                        ) : <span className="text-gray-400">—</span>}
                      </td>
                      <td className="px-3 py-2">{d.compte_bancaire?.nom ?? '—'}</td>
                      <td className="px-3 py-2 text-right font-medium whitespace-nowrap">{montant(d.montant, d.devise)}</td>
                      <td className="px-3 py-2 text-xs">
                        {onglet === 'a_transmettre' ? (
                          <>
                            {d.virement_statut === 'refuse' && <span className="block text-red-700 dark:text-red-300">{tv.wasRefused.replace('{numero}', String(d.lot?.numero ?? ''))}</span>}
                            {d.alertes.map(a => <span key={a} className="flex items-start gap-1 text-amber-700 dark:text-amber-300"><AlertTriangle size={12} className="mt-0.5 shrink-0" /> {a}</span>)}
                          </>
                        ) : (d.lot ? tv.batchN.replace('{numero}', String(d.lot.numero)) : '—')}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      ) : (
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
          {lots.length === 0 ? <p className="p-4 text-sm text-gray-500">{tv.noBatch}</p> : (
            <table className="w-full min-w-[900px] text-sm" aria-label={tv.tabs.lots}>
              <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                <tr>
                  <th className="px-3 py-2 text-left">{tv.batch}</th>
                  <th className="px-3 py-2 text-left">{tv.account}</th>
                  <th className="px-3 py-2 text-left">{tv.format}</th>
                  <th className="px-3 py-2 text-left">{tv.executionDate}</th>
                  <th className="px-3 py-2 text-right">{tv.count}</th>
                  <th className="px-3 py-2 text-right">{tv.amount}</th>
                  <th className="px-3 py-2 text-left">{tv.status}</th>
                  <th className="px-3 py-2" />
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                {lots.map(l => (
                  <tr key={l.id} className={l.statut === 'refuse' ? 'text-gray-400' : ''}>
                    <td className="px-3 py-2">
                      <span className="font-medium">{tv.batchN.replace('{numero}', String(l.numero))}</span>
                      <span className="block text-xs text-gray-500">{formatDateHeure(l.created_at, language)}{l.auteur ? ` — ${l.auteur.nom}` : ''}</span>
                    </td>
                    <td className="px-3 py-2">{l.compte_bancaire?.nom}</td>
                    <td className="px-3 py-2">{tv.formats[l.format]}</td>
                    <td className="px-3 py-2 whitespace-nowrap">{formatDateDocument(l.date_execution, language)}</td>
                    <td className="px-3 py-2 text-right">{l.nombre}</td>
                    <td className="px-3 py-2 text-right whitespace-nowrap">{montant(l.total, l.devise)}</td>
                    <td className="px-3 py-2 text-xs">
                      {tv.statuses[l.statut]}
                      {l.statut === 'refuse' && <span className="block">{[l.refuse_le ? formatDateHeure(l.refuse_le, language) : '', l.auteur_refus?.nom, l.motif_refus].filter(Boolean).join(' — ')}</span>}
                    </td>
                    <td className="px-3 py-2 text-right whitespace-nowrap">
                      {peutGenerer && (
                        <button type="button" onClick={() => telechargerLotVirements(l).catch(e => setErreur(messageErreur(e, tv.error)))} title={l.fichier}
                          className="inline-flex items-center gap-1 px-2 py-1 text-xs border rounded-lg dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700"><Download size={12} /> {tv.download}</button>
                      )}
                      {peutGenerer && l.statut === 'emis' && (
                        <button type="button" onClick={() => refuser(l)}
                          className="ml-1 inline-flex items-center gap-1 px-2 py-1 text-xs border border-red-600 text-red-700 dark:text-red-300 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/30"><Ban size={12} /> {tv.refuse}</button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {fenetre && compteLot && (
        <FenetreLotVirements compte={compteLot} debourses={choisis} total={montant(totalSelection, devisesSelection[0] || null)} onClose={() => setFenetre(false)}
          onCree={lot => {
            setFenetre(false);
            setChoisis([]);
            setMessage(tv.created.replace('{numero}', String(lot.numero)).replace('{n}', String(lot.nombre)).replace('{fichier}', lot.fichier));
            telechargerLotVirements(lot).catch(e => setErreur(messageErreur(e, tv.error)));
            recharger();
          }} />
      )}
    </div>
  );
}
