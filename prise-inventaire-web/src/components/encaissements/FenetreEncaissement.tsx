import { useEffect, useMemo, useState } from 'react';
import { Eraser, Wand2, X } from 'lucide-react';
import ChampDevise from '@/components/ChampDevise';
import ChampCompteBancaire from '@/components/banque/ChampCompteBancaire';
import CaisseDuPoste from '@/components/caisse/CaisseDuPoste';
import { useEncaissementCaisse } from '@/hooks/useEncaissementCaisse';
import { useLanguage } from '@/i18n/useLanguage';
import { aujourdhui, formatDateDocument, tauxPropose, useCodeDevise, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { getClientsActifs, type Client } from '@/services/api';
import {
  creerEncaissement, getARegler, MODES_ENCAISSEMENT, repartirMontant, type ARegler, type Encaissement, type ModeEncaissement,
} from '@/services/encaissements';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const centimes = (valeur: string | number) => Math.round((Number(valeur) || 0) * 100);

/**
 * Saisie d'un encaissement : client, montant reçu, mode, référence, date ; répartition sur ses factures dues (l'échéance
 * la plus ancienne d'abord), à la main ou d'un clic ; le surplus reste à son crédit (acompte), une répartition qui
 * dépasse le montant reçu s'accorde en escompte. Sur un poste de caisse, l'argent entre dans la session ouverte.
 */
export default function FenetreEncaissement({ clientId, onClose, onEnregistre }: {
  clientId?: number;
  onClose: () => void;
  onEnregistre: (encaissement: Encaissement) => void;
}) {
  const { t, language } = useLanguage();
  const te = t.customerPayments;
  const montantTexte = useMontantDocument();
  const deviseEntreprise = useCodeDevise();
  const encaissement = useEncaissementCaisse();
  const [clients, setClients] = useState<Client[]>([]);
  const [saisie, setSaisie] = useState({
    client_id: clientId ? String(clientId) : '',
    date: aujourdhui(),
    // Sur un poste de caisse, l'argent comptant est proposé.
    mode: (encaissement.caisse ? 'especes' : 'cheque') as ModeEncaissement,
    reference: '',
    compteBancaire: '',
    montant: '',
    notes: '',
    devise: '',
    taux: '1',
  });
  const [aRegler, setARegler] = useState<ARegler | null>(null);
  const [parts, setParts] = useState<Record<number, string>>({});
  const [escompte, setEscompte] = useState(false);
  const [chargement, setChargement] = useState(false);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    getClientsActifs().then(liste => {
      if (!actif) return;
      setClients(liste ?? []);
      // Client fixé : sa devise est proposée.
      const fixe = (liste ?? []).find(c => c.id === clientId);
      if (fixe?.devise) setSaisie(s => ({ ...s, devise: fixe.devise ?? '', taux: '' }));
    }, () => {});
    return () => { actif = false; };
  }, [clientId]);

  // Taux proposé pour une devise étrangère choisie avec le client.
  useEffect(() => {
    if (!saisie.devise || saisie.taux !== '') return;
    let actif = true;
    tauxPropose(saisie.devise, deviseEntreprise).then(taux => { if (actif) setSaisie(s => (s.devise === saisie.devise && s.taux === '' ? { ...s, taux } : s)); });
    return () => { actif = false; };
  }, [saisie.devise, saisie.taux, deviseEntreprise]);

  // Factures dues du client dans la devise choisie.
  useEffect(() => {
    if (!saisie.client_id) return;
    let actif = true;
    setChargement(true);
    getARegler(Number(saisie.client_id), saisie.devise || deviseEntreprise || undefined).then(resultat => {
      if (!actif) return;
      setARegler(resultat);
      setParts({});
      setEscompte(false);
    }, e => { if (actif) setErreur(messageErreur(e, te.error)); }).finally(() => { if (actif) setChargement(false); });
    return () => { actif = false; };
  }, [saisie.client_id, saisie.devise, deviseEntreprise, te.error]);

  const factures = useMemo(() => (saisie.client_id ? aRegler?.factures ?? [] : []), [aRegler, saisie.client_id]);
  const devise = saisie.devise && saisie.devise !== deviseEntreprise ? saisie.devise : null;
  const recu = centimes(saisie.montant);
  const reparti = factures.reduce((s, f) => s + centimes(parts[f.id] ?? ''), 0);
  const ecart = recu - reparti;
  const depasse = factures.some(f => centimes(parts[f.id] ?? '') > centimes(f.reste_a_payer));
  const fmt = (c: number) => montantTexte(c / 100, devise);
  const credits = aRegler && saisie.client_id ? aRegler.credits : null;

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    try {
      const resultat = await encaissement.encaisser(caisse_id => creerEncaissement({
        client_id: Number(saisie.client_id),
        date_encaissement: saisie.date,
        mode: saisie.mode,
        reference: saisie.reference.trim() || null,
        montant: recu / 100,
        devise: saisie.devise || deviseEntreprise,
        taux_change: saisie.devise ? Number(saisie.taux) : undefined,
        notes: saisie.notes.trim() || null,
        escompte: ecart < 0 && escompte,
        caisse_id,
        compte_bancaire_id: saisie.mode !== 'especes' && saisie.compteBancaire ? Number(saisie.compteBancaire) : undefined,
        lignes: factures.filter(f => centimes(parts[f.id] ?? '') > 0).map(f => ({ facture_id: f.id, montant: centimes(parts[f.id]) / 100 })),
      }));
      onEnregistre(resultat);
    } catch (err) {
      setErreur(messageErreur(err, te.error));
    } finally {
      setOccupe(false);
    }
  }

  const clientFixe = clientId !== undefined;
  const nomClient = clients.find(c => c.id === Number(saisie.client_id))?.raison_sociale;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <form onSubmit={enregistrer} role="dialog" aria-modal="true" aria-labelledby="encaissement-titre"
        className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-4xl max-h-[94vh] overflow-y-auto space-y-3">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 id="encaissement-titre" className="text-lg font-bold text-gray-900 dark:text-white">{te.newPayment}</h2>
            {clientFixe && nomClient && <p className="text-sm text-gray-500">{nomClient}</p>}
          </div>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={te.close}><X size={18} /></button>
        </div>
        <CaisseDuPoste caisse={encaissement.caisse} />

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          {!clientFixe && (
            <label className="block text-xs text-gray-500 dark:text-gray-400 sm:col-span-2">{te.customer}
              <select required value={saisie.client_id} onChange={e => {
                // Devise du client proposée, son taux ensuite.
                const deviseClient = clients.find(c => c.id === Number(e.target.value))?.devise ?? '';
                setSaisie({ ...saisie, client_id: e.target.value, devise: deviseClient, taux: deviseClient ? '' : '1' });
              }} className={classeChamp}>
                <option value="">{te.chooseCustomer}</option>
                {clients.map(c => <option key={c.id} value={c.id}>{c.code} · {c.raison_sociale}</option>)}
              </select>
            </label>
          )}
          <label className="block text-xs text-gray-500 dark:text-gray-400">{te.amountReceived}
            <input type="number" required min="0.01" step="0.01" value={saisie.montant} onChange={e => setSaisie({ ...saisie, montant: e.target.value })} className={classeChamp} />
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{te.date}
            <input type="date" required value={saisie.date} onChange={e => setSaisie({ ...saisie, date: e.target.value })} className={classeChamp} />
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{te.mode}
            <select value={saisie.mode} onChange={e => setSaisie({ ...saisie, mode: e.target.value as ModeEncaissement })} className={classeChamp}>
              {MODES_ENCAISSEMENT.map(m => <option key={m} value={m}>{te.modes[m]}</option>)}
            </select>
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{te.reference}
            <input value={saisie.reference} maxLength={100} placeholder={te.referenceHelp} onChange={e => setSaisie({ ...saisie, reference: e.target.value })} className={classeChamp} />
          </label>
          {saisie.mode !== 'especes' && (
            <ChampCompteBancaire valeur={saisie.compteBancaire} onChange={v => setSaisie(s => ({ ...s, compteBancaire: v }))} />
          )}
          {saisie.client_id && (
            <div className="sm:col-span-2">
              <ChampDevise valeur={{ devise: saisie.devise, taux: saisie.taux }} onChange={v => setSaisie(s => ({ ...s, devise: v.devise, taux: v.taux }))} />
            </div>
          )}
        </div>

        {credits && (credits.acomptes > 0 || credits.avoirs > 0) && (
          <p className="px-3 py-2 rounded-lg text-sm bg-blue-50 text-blue-800 dark:bg-blue-900/30 dark:text-blue-200">
            {te.creditsAvailable.replace('{acomptes}', fmt(centimes(credits.acomptes))).replace('{avoirs}', fmt(centimes(credits.avoirs)))}
          </p>
        )}

        {saisie.client_id && (
          <div className="border dark:border-gray-700 rounded-lg">
            <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-b dark:border-gray-700">
              <h3 className="text-sm font-semibold dark:text-white">{te.dueInvoices}</h3>
              <div className="flex gap-2">
                <button type="button" disabled={recu <= 0 || factures.length === 0} onClick={() => { setParts(repartirMontant(recu / 100, factures)); setEscompte(false); }}
                  title={te.distributeHelp}
                  className="flex items-center gap-1 px-3 py-1 text-xs rounded-lg border border-blue-600 text-blue-700 hover:bg-blue-50 dark:text-blue-300 dark:hover:bg-blue-900/30 disabled:opacity-40">
                  <Wand2 size={14} /> {te.distribute}
                </button>
                <button type="button" disabled={reparti === 0} onClick={() => { setParts({}); setEscompte(false); }}
                  className="flex items-center gap-1 px-3 py-1 text-xs rounded-lg border dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-40">
                  <Eraser size={14} /> {te.clear}
                </button>
              </div>
            </div>
            {chargement ? <p className="p-3 text-sm text-gray-500">{te.loading}</p> : factures.length === 0 ? (
              <p className="p-3 text-sm text-gray-500">{te.noDueInvoice}</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[720px] text-sm" aria-label={te.dueInvoices}>
                  <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                      <th className="px-3 py-2 text-left">{te.invoice}</th>
                      <th className="px-3 py-2 text-left">{te.dueDate}</th>
                      <th className="px-3 py-2 text-right">{te.invoiceTotal}</th>
                      <th className="px-3 py-2 text-right">{te.remaining}</th>
                      <th className="px-3 py-2 text-center">{te.all}</th>
                      <th className="px-3 py-2 text-right">{te.applied}</th>
                      <th className="px-3 py-2 text-right">{te.newBalance}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                    {factures.map(f => {
                      const part = centimes(parts[f.id] ?? '');
                      const reste = centimes(f.reste_a_payer);
                      return (
                        <tr key={f.id}>
                          <td className="px-3 py-2">
                            <span className="font-mono">{f.numero}</span>
                            <span className="block text-xs text-gray-500">{formatDateDocument(f.date_facture, language)}</span>
                          </td>
                          <td className="px-3 py-2">
                            {formatDateDocument(f.date_echeance, language)}
                            {f.jours_retard > 0 && <span className="block text-xs text-red-600">{te.lateDays.replace('{n}', String(f.jours_retard))}</span>}
                          </td>
                          <td className="px-3 py-2 text-right">{fmt(centimes(f.montant_ttc))}</td>
                          <td className="px-3 py-2 text-right">{fmt(reste)}</td>
                          <td className="px-3 py-2 text-center">
                            <input type="checkbox" aria-label={`${te.all} ${f.numero}`} checked={part > 0 && part === reste}
                              onChange={e => setParts(p => ({ ...p, [f.id]: e.target.checked ? (reste / 100).toFixed(2) : '' }))} />
                          </td>
                          <td className="px-3 py-2 text-right">
                            <input type="number" min="0" step="0.01" max={reste / 100} value={parts[f.id] ?? ''} aria-label={`${te.applied} ${f.numero}`}
                              onChange={e => setParts(p => ({ ...p, [f.id]: e.target.value }))}
                              className={`w-28 px-2 py-1 border rounded text-right text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white ${part > reste ? 'border-red-500' : ''}`} />
                          </td>
                          <td className={`px-3 py-2 text-right ${part > reste ? 'text-red-600' : ''}`}>{fmt(reste - part)}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}

        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="text-sm space-y-2 flex-1 min-w-[240px]">
            {recu > 0 && ecart > 0 && (
              <p className="px-3 py-2 rounded-lg bg-blue-50 text-blue-800 dark:bg-blue-900/30 dark:text-blue-200">
                {(reparti === 0 ? te.advanceHelp : te.surplusHelp).replace('{montant}', fmt(ecart))}
              </p>
            )}
            {ecart < 0 && (
              <label className="flex items-start gap-2 px-3 py-2 rounded-lg bg-amber-50 text-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
                <input type="checkbox" className="mt-0.5" checked={escompte} onChange={e => setEscompte(e.target.checked)} />
                <span>{te.discountHelp.replace('{montant}', fmt(-ecart))}</span>
              </label>
            )}
            {depasse && <p className="text-red-600">{te.overRemaining}</p>}
          </div>
          <dl className="text-sm dark:text-gray-200 grid grid-cols-[auto_auto] gap-x-4 gap-y-0.5 text-right">
            <dt className="text-gray-500">{te.amountReceived}</dt><dd>{fmt(recu)}</dd>
            <dt className="text-gray-500">{te.distributed}</dt><dd>{fmt(reparti)}</dd>
            <dt className="text-gray-500">{ecart < 0 ? te.discount : te.surplus}</dt><dd className="font-semibold">{fmt(Math.abs(ecart))}</dd>
          </dl>
        </div>

        <label className="block text-xs text-gray-500 dark:text-gray-400">{te.notes}
          <textarea rows={2} maxLength={2000} value={saisie.notes} onChange={e => setSaisie({ ...saisie, notes: e.target.value })} className={classeChamp} />
        </label>

        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{te.cancel}</button>
          <button type="submit" disabled={occupe || !saisie.client_id || recu <= 0 || depasse || (ecart < 0 && !escompte)}
            className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 disabled:opacity-50">{te.save}</button>
        </div>
      </form>
      {encaissement.dialogue}
    </div>
  );
}
