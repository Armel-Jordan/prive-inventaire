import { useEffect, useMemo, useState } from 'react';
import { Eraser, X } from 'lucide-react';
import ChampCompteBancaire from '@/components/banque/ChampCompteBancaire';
import { useLanguage } from '@/i18n/useLanguage';
import { aujourdhui, formatDateDocument, useCodeDevise, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { getFournisseursActifs, getReferentiel, type Fournisseur } from '@/services/api';
import {
  creerDebourse, getAPayer, MODES_DEBOURSE, type APayer, type Debourse, type FactureAPayer, type ModeDebourse,
} from '@/services/debourses';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeCellule = 'w-24 px-2 py-1 border rounded text-right text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
const centimes = (valeur: string | number) => Math.round((Number(valeur) || 0) * 100);
const texte = (c: number) => (c > 0 ? (c / 100).toFixed(2) : '');

/** Part saisie sur une facture : l'argent versé et l'escompte obtenu (en texte, comme les champs). */
interface Part { argent: string; escompte: string }

/** La facture entière : son reste, moins l'escompte encore offert. */
function partEntiere(f: FactureAPayer): Part {
  const reste = centimes(f.reste_a_payer);
  const escompte = f.escompte?.applicable ? Math.min(centimes(f.escompte.escompte), reste) : 0;
  return { argent: texte(reste - escompte), escompte: texte(escompte) };
}

/**
 * Saisie d'un déboursé : fournisseur, date, mode, référence (n° de chèque, de virement), devise ; répartition sur ses
 * factures à payer (l'échéance la plus ancienne d'abord) avec l'escompte offert pour un paiement à cette date, que la
 * case « Tout » applique et qui se corrige. Le montant du déboursé est l'argent versé.
 */
export default function FenetreDebourse({ fournisseurId, onClose, onEnregistre }: {
  fournisseurId?: number;
  onClose: () => void;
  onEnregistre: (debourse: Debourse) => void;
}) {
  const { t, language } = useLanguage();
  const td = t.supplierPayments;
  const montantTexte = useMontantDocument();
  const deviseEntreprise = useCodeDevise();
  const [fournisseurs, setFournisseurs] = useState<Fournisseur[]>([]);
  const [saisie, setSaisie] = useState({
    fournisseur_id: fournisseurId ? String(fournisseurId) : '',
    date: aujourdhui(),
    mode: 'cheque' as ModeDebourse,
    reference: '',
    compteBancaire: '',
    notes: '',
    // Vide : la devise de l'entreprise.
    devise: '',
  });
  const [codes, setCodes] = useState<string[]>([]);
  const [aPayer, setAPayer] = useState<APayer | null>(null);
  const [parts, setParts] = useState<Record<number, Part>>({});
  const [chargement, setChargement] = useState(false);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    getFournisseursActifs().then(liste => {
      if (!actif) return;
      setFournisseurs(liste ?? []);
      // Fournisseur fixé : sa devise est proposée.
      const fixe = (liste ?? []).find(f => f.id === fournisseurId);
      if (fixe?.devise) setSaisie(s => ({ ...s, devise: fixe.devise ?? '' }));
    }, () => {});
    // Devises actives du référentiel.
    getReferentiel('devises', true).then(liste => {
      if (actif) setCodes(liste.map(d => String(d.code ?? '').toUpperCase()).filter(Boolean));
    }, () => {});
    return () => { actif = false; };
  }, [fournisseurId]);

  // Factures à payer du fournisseur dans la devise, avec l'escompte offert à la date du déboursé.
  useEffect(() => {
    if (!saisie.fournisseur_id || !saisie.date) return;
    let actif = true;
    setChargement(true);
    getAPayer(Number(saisie.fournisseur_id), saisie.date, saisie.devise || deviseEntreprise || undefined).then(resultat => {
      if (!actif) return;
      setAPayer(resultat);
      // Les parts déjà saisies restent, sur les factures encore proposées.
      setParts(p => Object.fromEntries(Object.entries(p).filter(([id]) => resultat.factures.some(f => f.id === Number(id)))));
    }, e => { if (actif) setErreur(messageErreur(e, td.error)); }).finally(() => { if (actif) setChargement(false); });
    return () => { actif = false; };
  }, [saisie.fournisseur_id, saisie.date, saisie.devise, deviseEntreprise, td.error]);

  const factures = useMemo(() => (saisie.fournisseur_id ? aPayer?.factures ?? [] : []), [aPayer, saisie.fournisseur_id]);
  const devise = saisie.devise && saisie.devise !== deviseEntreprise ? saisie.devise : null;
  const fmt = (c: number) => montantTexte(c / 100, devise);
  const verse = factures.reduce((s, f) => s + centimes(parts[f.id]?.argent ?? ''), 0);
  const escomptes = factures.reduce((s, f) => s + centimes(parts[f.id]?.escompte ?? ''), 0);
  const depasse = factures.some(f => centimes(parts[f.id]?.argent ?? '') + centimes(parts[f.id]?.escompte ?? '') > centimes(f.reste_a_payer));
  const changer = (id: number, champ: keyof Part, valeur: string) => setParts(p => ({ ...p, [id]: { argent: p[id]?.argent ?? '', escompte: p[id]?.escompte ?? '', [champ]: valeur } }));

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    try {
      onEnregistre(await creerDebourse({
        fournisseur_id: Number(saisie.fournisseur_id),
        date_debourse: saisie.date,
        mode: saisie.mode,
        reference: saisie.reference.trim() || null,
        devise: saisie.devise || deviseEntreprise,
        compte_bancaire_id: saisie.mode !== 'especes' && saisie.compteBancaire ? Number(saisie.compteBancaire) : undefined,
        notes: saisie.notes.trim() || null,
        lignes: factures
          .filter(f => centimes(parts[f.id]?.argent ?? '') + centimes(parts[f.id]?.escompte ?? '') > 0)
          .map(f => ({ facture_fournisseur_id: f.id, montant: centimes(parts[f.id]?.argent ?? '') / 100, escompte: centimes(parts[f.id]?.escompte ?? '') / 100 })),
      }));
    } catch (err) {
      setErreur(messageErreur(err, td.error));
    } finally {
      setOccupe(false);
    }
  }

  const fournisseurFixe = fournisseurId !== undefined;
  const nomFournisseur = fournisseurs.find(f => f.id === Number(saisie.fournisseur_id))?.raison_sociale;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <form onSubmit={enregistrer} role="dialog" aria-modal="true" aria-labelledby="debourse-titre"
        className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-5xl max-h-[94vh] overflow-y-auto space-y-3">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 id="debourse-titre" className="text-lg font-bold text-gray-900 dark:text-white">{td.newPayment}</h2>
            {fournisseurFixe && nomFournisseur && <p className="text-sm text-gray-500">{nomFournisseur}</p>}
          </div>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={td.close}><X size={18} /></button>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          {!fournisseurFixe && (
            <label className="block text-xs text-gray-500 dark:text-gray-400 sm:col-span-2">{td.supplier}
              <select required value={saisie.fournisseur_id} onChange={e => {
                // Devise du fournisseur proposée.
                const deviseFournisseur = fournisseurs.find(f => f.id === Number(e.target.value))?.devise ?? '';
                setSaisie({ ...saisie, fournisseur_id: e.target.value, devise: deviseFournisseur });
                setParts({});
              }} className={classeChamp}>
                <option value="">{td.chooseSupplier}</option>
                {fournisseurs.map(f => <option key={f.id} value={f.id}>{f.code} · {f.raison_sociale}</option>)}
              </select>
            </label>
          )}
          <label className="block text-xs text-gray-500 dark:text-gray-400">{td.date}
            <input type="date" required value={saisie.date} onChange={e => setSaisie({ ...saisie, date: e.target.value })} className={classeChamp} />
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{td.mode}
            <select value={saisie.mode} onChange={e => setSaisie({ ...saisie, mode: e.target.value as ModeDebourse })} className={classeChamp}>
              {MODES_DEBOURSE.map(m => <option key={m} value={m}>{td.modes[m]}</option>)}
            </select>
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{td.reference}
            <input value={saisie.reference} maxLength={100} placeholder={td.referenceHelp} onChange={e => setSaisie({ ...saisie, reference: e.target.value })} className={classeChamp} />
          </label>
          {saisie.mode !== 'especes' && (
            <ChampCompteBancaire valeur={saisie.compteBancaire} onChange={v => setSaisie(s => ({ ...s, compteBancaire: v }))} />
          )}
          {saisie.fournisseur_id && (
            // Pas de taux à saisir : chaque facture se règle à son propre taux de change.
            <label className="block text-xs text-gray-500 dark:text-gray-400 sm:col-span-2">{td.currency}
              <select value={(saisie.devise || deviseEntreprise).toUpperCase()} onChange={e => { setSaisie({ ...saisie, devise: e.target.value === deviseEntreprise ? '' : e.target.value }); setParts({}); }} className={classeChamp}>
                {[...new Set([deviseEntreprise, saisie.devise.toUpperCase(), ...codes].filter(Boolean))].map(code => (
                  <option key={code} value={code}>{code === deviseEntreprise ? td.companyCurrency.replace('{code}', code) : code}</option>
                ))}
              </select>
              <span className="block mt-0.5">{td.currencyHelp}</span>
            </label>
          )}
        </div>

        {saisie.fournisseur_id && (
          <div className="border dark:border-gray-700 rounded-lg">
            <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-b dark:border-gray-700">
              <h3 className="text-sm font-semibold dark:text-white">{td.invoicesToPay}</h3>
              <button type="button" disabled={verse + escomptes === 0} onClick={() => setParts({})}
                className="flex items-center gap-1 px-3 py-1 text-xs rounded-lg border dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-40">
                <Eraser size={14} /> {td.clear}
              </button>
            </div>
            {chargement ? <p className="p-3 text-sm text-gray-500">{td.loading}</p> : factures.length === 0 ? (
              <p className="p-3 text-sm text-gray-500">{td.nothingToPay}</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[860px] text-sm" aria-label={td.invoicesToPay}>
                  <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                      <th className="px-3 py-2 text-left">{td.invoice}</th>
                      <th className="px-3 py-2 text-left">{td.dueDate}</th>
                      <th className="px-3 py-2 text-right">{td.invoiceTotal}</th>
                      <th className="px-3 py-2 text-right">{td.remaining}</th>
                      <th className="px-3 py-2 text-left">{td.discountOffered}</th>
                      <th className="px-3 py-2 text-center">{td.all}</th>
                      <th className="px-3 py-2 text-right">{td.paid}</th>
                      <th className="px-3 py-2 text-right">{td.discount}</th>
                      <th className="px-3 py-2 text-right">{td.newBalance}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                    {factures.map(f => {
                      const part = parts[f.id];
                      const reste = centimes(f.reste_a_payer);
                      const porte = centimes(part?.argent ?? '') + centimes(part?.escompte ?? '');
                      const entiere = partEntiere(f);
                      const tout = porte > 0 && part?.argent === entiere.argent && (part?.escompte ?? '') === entiere.escompte;
                      return (
                        <tr key={f.id}>
                          <td className="px-3 py-2">
                            <span className="font-mono">{f.numero}</span>
                            {f.numero_fournisseur && <span className="block text-xs text-gray-500">{td.theirNumber.replace('{numero}', f.numero_fournisseur)}</span>}
                          </td>
                          <td className="px-3 py-2">
                            {formatDateDocument(f.date_echeance ?? f.date_facture, language)}
                            {f.jours_retard > 0 && <span className="block text-xs text-red-600">{td.lateDays.replace('{n}', String(f.jours_retard))}</span>}
                          </td>
                          <td className="px-3 py-2 text-right">{fmt(centimes(f.montant_ttc))}</td>
                          <td className="px-3 py-2 text-right">{fmt(reste)}</td>
                          <td className="px-3 py-2 text-xs">
                            {!f.escompte ? '—' : f.escompte.applicable
                              ? <span className="text-green-700 dark:text-green-300">{td.discountUntil.replace('{montant}', fmt(centimes(f.escompte.escompte))).replace('{date}', formatDateDocument(f.escompte.date_limite, language))}</span>
                              : <span className="text-gray-400">{td.discountExpired.replace('{date}', formatDateDocument(f.escompte.date_limite, language))}</span>}
                          </td>
                          <td className="px-3 py-2 text-center">
                            <input type="checkbox" aria-label={`${td.all} ${f.numero}`} checked={tout}
                              onChange={e => setParts(p => ({ ...p, [f.id]: e.target.checked ? entiere : { argent: '', escompte: '' } }))} />
                          </td>
                          <td className="px-3 py-2 text-right">
                            <input type="number" min="0" step="0.01" value={part?.argent ?? ''} aria-label={`${td.paid} ${f.numero}`}
                              onChange={e => changer(f.id, 'argent', e.target.value)} className={`${classeCellule} ${porte > reste ? 'border-red-500' : ''}`} />
                          </td>
                          <td className="px-3 py-2 text-right">
                            <input type="number" min="0" step="0.01" value={part?.escompte ?? ''} aria-label={`${td.discount} ${f.numero}`}
                              onChange={e => changer(f.id, 'escompte', e.target.value)} className={`${classeCellule} ${porte > reste ? 'border-red-500' : ''}`} />
                          </td>
                          <td className={`px-3 py-2 text-right ${porte > reste ? 'text-red-600' : ''}`}>{fmt(reste - porte)}</td>
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
          <div className="text-sm flex-1 min-w-[240px]">
            {depasse && <p className="text-red-600">{td.overRemaining}</p>}
          </div>
          <dl className="text-sm dark:text-gray-200 grid grid-cols-[auto_auto] gap-x-4 gap-y-0.5 text-right">
            <dt className="text-gray-500">{td.settled}</dt><dd>{fmt(verse + escomptes)}</dd>
            <dt className="text-gray-500">{td.discountObtained}</dt><dd>{escomptes > 0 ? `- ${fmt(escomptes)}` : fmt(0)}</dd>
            <dt className="text-gray-500">{td.amountPaid}</dt><dd className="font-semibold">{fmt(verse)}</dd>
          </dl>
        </div>

        <label className="block text-xs text-gray-500 dark:text-gray-400">{td.notes}
          <textarea rows={2} maxLength={2000} value={saisie.notes} onChange={e => setSaisie({ ...saisie, notes: e.target.value })} className={classeChamp} />
        </label>

        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{td.cancel}</button>
          <button type="submit" disabled={occupe || !saisie.fournisseur_id || verse <= 0 || depasse}
            className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 disabled:opacity-50">{td.save}</button>
        </div>
      </form>
    </div>
  );
}
