import { Fragment, useCallback, useEffect, useMemo, useState } from 'react';
import { CalendarClock, Download, Wallet } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { aujourdhui, formatDateDocument, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { getFournisseursActifs, type Fournisseur } from '@/services/api';
import {
  exporterPaiementsDus, getPaiementsDus, MODES_DEBOURSE, payerSelection, type FiltresPaiementsDus, type ModeDebourse, type RapportPaiementsDus,
} from '@/services/debourses';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/** Date à N jours d'aujourd'hui (AAAA-MM-JJ, heure locale). */
function dansJours(n: number): string {
  const d = new Date();
  d.setDate(d.getDate() + n);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/**
 * Paiements dus (Achats → Paiements dus), le plan de décaissement de fripv2 : chaque facture fournisseur encore due,
 * son échéance, l'escompte encore accordé à la date où l'on compte payer et le net à sortir de la banque, par
 * fournisseur et par devise. La date du rapport arrête les soldes ; « Payer le » décide seulement des escomptes. Les
 * factures cochées se paient en un geste : un déboursé par fournisseur.
 */
export default function PaiementsDusPage() {
  const { t, language } = useLanguage();
  const tp = t.paymentsDue;
  const { peut } = usePermissions();
  const montant = useMontantDocument();
  const [filtres, setFiltres] = useState<FiltresPaiementsDus>({ date: aujourdhui(), payer_le: aujourdhui(), echeance_au: aujourdhui() });
  const [jours, setJours] = useState('');
  const [fournisseurs, setFournisseurs] = useState<Fournisseur[]>([]);
  const [rapport, setRapport] = useState<RapportPaiementsDus | null>(null);
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState('');
  const [message, setMessage] = useState('');
  const [selection, setSelection] = useState<number[]>([]);
  const [paiement, setPaiement] = useState<{ date: string; mode: ModeDebourse; reference: string; escompte: boolean } | null>(null);
  const [enCours, setEnCours] = useState(false);
  const peutPayer = peut('debourses', 'creer');

  useEffect(() => {
    let actif = true;
    getFournisseursActifs().then(f => { if (actif) setFournisseurs(f ?? []); }, () => {});
    return () => { actif = false; };
  }, []);

  const charger = useCallback(async () => {
    setChargement(true);
    try {
      setRapport(await getPaiementsDus(filtres));
      setErreur('');
    } catch (e) {
      setErreur(messageErreur(e, tp.error));
    } finally {
      setChargement(false);
    }
  }, [filtres, tp.error]);

  useEffect(() => { charger(); }, [charger]);

  const lignes = useMemo(() => (rapport?.groupes ?? []).flatMap(g => g.factures), [rapport]);
  const choisies = lignes.filter(l => selection.includes(l.id));
  // Net des factures choisies : dans leur devise si elles en partagent une, sinon dans celle de l'entreprise.
  const devisesChoisies = [...new Set(choisies.map(l => l.devise ?? ''))];
  const netChoisi = devisesChoisies.length === 1
    ? montant(choisies.reduce((s, l) => s + l.net, 0), devisesChoisies[0] || null)
    : montant(choisies.reduce((s, l) => s + l.net * l.taux_change, 0));
  const changer = (champ: keyof FiltresPaiementsDus, valeur: string | number | boolean | undefined) => {
    setFiltres(f => ({ ...f, [champ]: valeur }));
    setSelection([]);
  };
  const basculer = (id: number) => setSelection(s => (s.includes(id) ? s.filter(x => x !== id) : [...s, id]));

  async function payer(e: React.FormEvent) {
    e.preventDefault();
    if (!paiement) return;
    setEnCours(true);
    setErreur('');
    try {
      const resultat = await payerSelection({
        factures: selection, date_debourse: paiement.date, mode: paiement.mode, reference: paiement.reference.trim() || null, escompte: paiement.escompte,
      });
      setMessage(resultat.message);
      setPaiement(null);
      setSelection([]);
      await charger();
    } catch (err) {
      setErreur(messageErreur(err, tp.error));
    } finally {
      setEnCours(false);
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><CalendarClock size={24} /> {tp.title}</h1>
          <p className="text-sm text-gray-500 max-w-3xl">{tp.subtitle}</p>
        </div>
        <button type="button" onClick={() => exporterPaiementsDus(filtres).catch(err => setErreur(messageErreur(err, tp.error)))}
          className="flex items-center gap-2 px-3 py-2 text-sm border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
          <Download size={16} /> {tp.export}
        </button>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 flex flex-wrap items-end gap-3">
        <label className="text-xs text-gray-500 dark:text-gray-400">{tp.reportDate}
          <input type="date" value={filtres.date ?? ''} onChange={e => changer('date', e.target.value)} className={`${classeChamp} block`} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tp.payOn}
          <input type="date" value={filtres.payer_le ?? ''} onChange={e => { setJours(''); changer('payer_le', e.target.value); }} className={`${classeChamp} block`} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tp.inDays}
          <input type="number" min="0" max="365" value={jours} placeholder="0" onChange={e => {
            setJours(e.target.value);
            if (e.target.value !== '') changer('payer_le', dansJours(Number(e.target.value)));
          }} className={`${classeChamp} block w-24`} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tp.dueBy}
          <input type="date" value={filtres.echeance_au ?? ''} onChange={e => changer('echeance_au', e.target.value)} className={`${classeChamp} block`} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tp.supplier}
          <select value={filtres.fournisseur_id ?? ''} onChange={e => changer('fournisseur_id', e.target.value ? Number(e.target.value) : undefined)} className={`${classeChamp} block`}>
            <option value="">{tp.allSuppliers}</option>
            {fournisseurs.map(f => <option key={f.id} value={f.id}>{f.raison_sociale}</option>)}
          </select>
        </label>
        <div className="flex flex-col gap-1 text-sm text-gray-700 dark:text-gray-300">
          <label className="flex items-center gap-2"><input type="checkbox" checked={!!filtres.echus} onChange={e => changer('echus', e.target.checked)} /> {tp.overdueOnly}</label>
          <label className="flex items-center gap-2"><input type="checkbox" checked={!!filtres.escompte_seulement} onChange={e => changer('escompte_seulement', e.target.checked)} /> {tp.discountOnly}</label>
          <label className="flex items-center gap-2"><input type="checkbox" checked={!!filtres.inclure_soldees} onChange={e => changer('inclure_soldees', e.target.checked)} /> {tp.includeSettled}</label>
        </div>
      </div>
      <p className="text-xs text-gray-500">{tp.datesHelp}</p>

      {message && <div role="status" className="px-3 py-2 rounded-lg text-sm bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200">{message}</div>}
      {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}

      {peutPayer && selection.length > 0 && (
        <div className="bg-blue-50 dark:bg-blue-900/30 rounded-xl p-3 text-sm space-y-2">
          <div className="flex flex-wrap items-center justify-between gap-2 text-blue-900 dark:text-blue-100">
            <span>{tp.selected.replace('{n}', String(choisies.length)).replace('{net}', netChoisi)}</span>
            {!paiement && (
              <button type="button" onClick={() => setPaiement({ date: filtres.payer_le ?? aujourdhui(), mode: 'virement', reference: '', escompte: true })}
                className="flex items-center gap-1 px-3 py-1.5 bg-green-600 text-white rounded-lg hover:bg-green-700"><Wallet size={14} /> {tp.paySelection}</button>
            )}
          </div>
          {paiement && (
            <form onSubmit={payer} aria-label={tp.paySelection} className="flex flex-wrap items-end gap-3">
              <label className="text-xs text-gray-600 dark:text-gray-300">{tp.paymentDate}
                <input type="date" required value={paiement.date} onChange={e => setPaiement({ ...paiement, date: e.target.value })} className={`${classeChamp} block`} />
              </label>
              <label className="text-xs text-gray-600 dark:text-gray-300">{tp.mode}
                <select value={paiement.mode} onChange={e => setPaiement({ ...paiement, mode: e.target.value as ModeDebourse })} className={`${classeChamp} block`}>
                  {MODES_DEBOURSE.map(m => <option key={m} value={m}>{t.supplierPayments.modes[m]}</option>)}
                </select>
              </label>
              <label className="text-xs text-gray-600 dark:text-gray-300">{tp.reference}
                <input value={paiement.reference} maxLength={100} onChange={e => setPaiement({ ...paiement, reference: e.target.value })} className={`${classeChamp} block`} />
              </label>
              <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200 pb-2">
                <input type="checkbox" checked={paiement.escompte} onChange={e => setPaiement({ ...paiement, escompte: e.target.checked })} /> {tp.applyDiscounts}
              </label>
              <button type="button" onClick={() => setPaiement(null)} className="px-3 py-2 border rounded-lg dark:border-gray-600 dark:text-gray-200">{tp.cancel}</button>
              <button type="submit" disabled={enCours} className="px-3 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50">{tp.confirmPay}</button>
              <span className="basis-full text-xs text-gray-600 dark:text-gray-300">{tp.payHelp}</span>
            </form>
          )}
        </div>
      )}

      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
        {chargement && !rapport ? <p className="p-4 text-sm text-gray-500">{tp.loading}</p> : (rapport?.groupes ?? []).length === 0 ? (
          <p className="p-4 text-sm text-gray-500">{tp.none}</p>
        ) : (
          <table className="w-full min-w-[1000px] text-sm" aria-label={tp.title}>
            <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
              <tr>
                {peutPayer && <th className="px-3 py-2" />}
                <th className="px-3 py-2 text-left">{tp.document}</th>
                <th className="px-3 py-2 text-left">{tp.date}</th>
                <th className="px-3 py-2 text-left">{tp.dueDate}</th>
                <th className="px-3 py-2 text-right">{tp.days}</th>
                <th className="px-3 py-2 text-right">{tp.amount}</th>
                <th className="px-3 py-2 text-right">{tp.settled}</th>
                <th className="px-3 py-2 text-right">{tp.balance}</th>
                <th className="px-3 py-2 text-left">{tp.discountLimit}</th>
                <th className="px-3 py-2 text-right">{tp.discount}</th>
                <th className="px-3 py-2 text-right">{tp.net}</th>
              </tr>
            </thead>
            <tbody className="dark:text-gray-200">
              {(rapport?.groupes ?? []).map(g => (
                <Fragment key={`${g.fournisseur_id}-${g.devise ?? ''}`}>
                  <tr className="bg-gray-100 dark:bg-gray-700/60">
                    <td colSpan={peutPayer ? 11 : 10} className="px-3 py-2 font-semibold">{g.fournisseur}{g.devise ? ` · ${g.devise}` : ''}</td>
                  </tr>
                  {g.factures.map(f => (
                    <tr key={f.id} className="border-b dark:border-gray-700">
                      {peutPayer && (
                        <td className="px-3 py-2 text-center">
                          {f.solde > 0 && <input type="checkbox" aria-label={`${tp.select} ${f.numero}`} checked={selection.includes(f.id)} onChange={() => basculer(f.id)} />}
                        </td>
                      )}
                      <td className="px-3 py-2">
                        <span className="font-mono">{f.numero}</span>
                        {f.numero_fournisseur && <span className="block text-xs text-gray-500">{f.numero_fournisseur}</span>}
                      </td>
                      <td className="px-3 py-2">{formatDateDocument(f.date_facture, language)}</td>
                      <td className="px-3 py-2">{formatDateDocument(f.date_echeance, language)}</td>
                      <td className={`px-3 py-2 text-right ${f.jours > 0 ? 'text-red-600 font-medium' : 'text-gray-500'}`}>{f.jours}</td>
                      <td className="px-3 py-2 text-right">{montant(f.montant, f.devise)}</td>
                      <td className="px-3 py-2 text-right">{montant(f.regle, f.devise)}</td>
                      <td className="px-3 py-2 text-right">{montant(f.solde, f.devise)}</td>
                      <td className="px-3 py-2">{f.limite_escompte ? formatDateDocument(f.limite_escompte, language) : '—'}</td>
                      <td className="px-3 py-2 text-right text-green-700 dark:text-green-300">{f.escompte > 0 ? montant(f.escompte, f.devise) : '—'}</td>
                      <td className="px-3 py-2 text-right font-semibold">{montant(f.net, f.devise)}</td>
                    </tr>
                  ))}
                  <tr className="border-b-2 dark:border-gray-600 text-xs font-semibold">
                    <td colSpan={peutPayer ? 5 : 4} className="px-3 py-2 text-right text-gray-500">{tp.subtotal}</td>
                    <td className="px-3 py-2 text-right">{montant(g.totaux.montant, g.devise)}</td>
                    <td className="px-3 py-2 text-right">{montant(g.totaux.regle, g.devise)}</td>
                    <td className="px-3 py-2 text-right">{montant(g.totaux.solde, g.devise)}</td>
                    <td />
                    <td className="px-3 py-2 text-right">{montant(g.totaux.escompte, g.devise)}</td>
                    <td className="px-3 py-2 text-right">{montant(g.totaux.net, g.devise)}</td>
                  </tr>
                </Fragment>
              ))}
            </tbody>
            <tfoot className="text-sm font-semibold dark:text-white">
              {(rapport?.totaux_par_devise ?? []).map(tot => (
                <tr key={tot.devise ?? ''}>
                  <td colSpan={peutPayer ? 7 : 6} className="px-3 py-2 text-right">{tp.totalCurrency.replace('{devise}', tot.devise ?? tp.companyCurrency).replace('{n}', String(tot.nombre))}</td>
                  <td className="px-3 py-2 text-right">{montant(tot.solde, tot.devise)}</td>
                  <td />
                  <td className="px-3 py-2 text-right">{montant(tot.escompte, tot.devise)}</td>
                  <td className="px-3 py-2 text-right">{montant(tot.net, tot.devise)}</td>
                </tr>
              ))}
              {(rapport?.totaux_par_devise ?? []).length > 1 && rapport && (
                <tr className="text-gray-500">
                  <td colSpan={peutPayer ? 7 : 6} className="px-3 py-2 text-right">{tp.totalCompany}</td>
                  <td className="px-3 py-2 text-right">{montant(rapport.total_entreprise.solde)}</td>
                  <td />
                  <td className="px-3 py-2 text-right">{montant(rapport.total_entreprise.escompte)}</td>
                  <td className="px-3 py-2 text-right">{montant(rapport.total_entreprise.net)}</td>
                </tr>
              )}
            </tfoot>
          </table>
        )}
      </div>
    </div>
  );
}
