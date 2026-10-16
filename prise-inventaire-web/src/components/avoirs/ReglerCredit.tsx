import { useEffect, useState } from 'react';
import { X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, dateSaisie, formatDateDocument, useMontantDocument } from '@/lib/documents';
import {
  getFacturesImputables, imputerAvoir, MODES_REMBOURSEMENT, rembourserAvoir,
  type Avoir, type FactureImputable, type ModeRemboursement,
} from '@/services/avoirs';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/**
 * Utiliser le crédit disponible d'une note : l'imputer sur une facture due du même client, ou le rembourser au
 * client (montant, date, mode et référence).
 */
export default function ReglerCredit({ avoir, mode, onClose, onRegle }: {
  avoir: Avoir;
  mode: 'imputer' | 'rembourser';
  onClose: () => void;
  onRegle: (avoir: Avoir, message: string) => void;
}) {
  const { t, language } = useLanguage();
  const tc = t.creditNotes;
  const montantDoc = useMontantDocument();
  // Dans la devise de la note (seules les factures de cette devise peuvent recevoir son crédit).
  const montant = (v: number | string | null | undefined) => montantDoc(v, avoir.devise);
  const disponible = Number(avoir.credit_disponible);
  const [factures, setFactures] = useState<FactureImputable[] | null>(mode === 'imputer' ? null : []);
  const [factureId, setFactureId] = useState<number | null>(null);
  const [saisie, setSaisie] = useState({ montant: String(disponible), date: aujourdhui(), mode: 'virement' as ModeRemboursement, reference: '' });
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    if (mode !== 'imputer') return;
    let actif = true;
    getFacturesImputables(avoir.id).then(liste => {
      if (!actif) return;
      setFactures(liste);
      // Première facture due proposée, pour le montant qu'elle peut recevoir.
      if (liste[0]) {
        setFactureId(liste[0].id);
        setSaisie(s => ({ ...s, montant: String(Math.min(disponible, Number(liste[0].reste_a_payer))) }));
      }
    }, e => { if (actif) setErreur(messageErreur(e, tc.error)); });
    return () => { actif = false; };
  }, [avoir.id, mode, disponible, tc.error]);

  const choisirFacture = (id: number) => {
    setFactureId(id);
    const facture = factures?.find(f => f.id === id);
    if (facture) setSaisie(s => ({ ...s, montant: String(Math.min(disponible, Number(facture.reste_a_payer))) }));
  };

  async function valider(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    try {
      if (mode === 'imputer') {
        if (!factureId) return;
        const resultat = await imputerAvoir(avoir.id, { facture_id: factureId, montant: Number(saisie.montant), date: saisie.date });
        onRegle(resultat, tc.applyDone.replace('{numero}', factures?.find(f => f.id === factureId)?.numero ?? ''));
      } else {
        const resultat = await rembourserAvoir(avoir.id, {
          montant: Number(saisie.montant), date_remboursement: saisie.date, mode: saisie.mode, reference: saisie.reference.trim() || undefined,
        });
        onRegle(resultat, tc.refundDone);
      }
    } catch (err) {
      setErreur(messageErreur(err, tc.error));
    } finally {
      setOccupe(false);
    }
  }

  const titre = (mode === 'imputer' ? tc.applyTitle : tc.refundTitle).replace('{numero}', avoir.numero ?? '');

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <form onSubmit={valider} role="dialog" aria-modal="true" aria-labelledby="regler-credit-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md space-y-4">
        <div className="flex items-start justify-between gap-3">
          <h2 id="regler-credit-titre" className="text-lg font-bold text-gray-900 dark:text-white">{titre}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tc.close}><X size={18} /></button>
        </div>
        <p className="text-sm text-gray-600 dark:text-gray-300">
          {tc.availableCredit.replace('{montant}', montant(disponible))}
          {mode === 'imputer' && <span className="block text-xs text-gray-500 dark:text-gray-400">{tc.applyHelp}</span>}
        </p>

        {mode === 'imputer' && factures === null && !erreur && <p className="text-sm text-gray-500">{tc.loading}</p>}
        {mode === 'imputer' && factures !== null && factures.length === 0 && <p className="px-3 py-2 rounded-lg text-sm bg-amber-50 text-amber-800">{tc.noInvoiceToApply}</p>}
        {mode === 'imputer' && factures !== null && factures.length > 0 && (
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tc.invoice}
            <select value={factureId ?? ''} onChange={e => choisirFacture(Number(e.target.value))} className={classeChamp}>
              {factures.map(f => (
                <option key={f.id} value={f.id}>{f.numero} · {formatDateDocument(f.date_facture, language)} · {tc.invoiceDueShort.replace('{montant}', montant(f.reste_a_payer))}</option>
              ))}
            </select>
          </label>
        )}

        {(mode === 'rembourser' || (factures?.length ?? 0) > 0) && (
          <>
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-xs text-gray-500 dark:text-gray-400">{tc.amount}
                <input type="number" required min="0.01" step="0.01" max={disponible} value={saisie.montant}
                  onChange={e => setSaisie({ ...saisie, montant: e.target.value })} className={classeChamp} />
              </label>
              <label className="block text-xs text-gray-500 dark:text-gray-400">{tc.date}
                <input type="date" required value={saisie.date} min={dateSaisie(avoir.date_avoir)}
                  onChange={e => setSaisie({ ...saisie, date: e.target.value })} className={classeChamp} />
              </label>
            </div>
            {mode === 'rembourser' && (
              <div className="grid grid-cols-2 gap-3">
                <label className="block text-xs text-gray-500 dark:text-gray-400">{tc.mode}
                  <select value={saisie.mode} onChange={e => setSaisie({ ...saisie, mode: e.target.value as ModeRemboursement })} className={classeChamp}>
                    {MODES_REMBOURSEMENT.map(m => <option key={m} value={m}>{tc.modes[m]}</option>)}
                  </select>
                </label>
                <label className="block text-xs text-gray-500 dark:text-gray-400">{tc.reference}
                  <input value={saisie.reference} maxLength={100} placeholder={tc.referenceHelp}
                    onChange={e => setSaisie({ ...saisie, reference: e.target.value })} className={classeChamp} />
                </label>
              </div>
            )}
          </>
        )}

        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tc.cancel}</button>
          <button type="submit" disabled={occupe || (mode === 'imputer' && !factureId)}
            className="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700 disabled:opacity-50">
            {mode === 'imputer' ? tc.apply : tc.refund}
          </button>
        </div>
      </form>
    </div>
  );
}
