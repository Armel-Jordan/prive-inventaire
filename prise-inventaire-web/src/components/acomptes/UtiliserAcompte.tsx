import { useEffect, useState } from 'react';
import { X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, dateSaisie, formatDateDocument, formatMontant, useDevise } from '@/lib/documents';
import { getFactures, type Facture } from '@/services/api';
import { appliquerAcompte, MODES_ACOMPTE, rembourserAcompte, type Acompte, type ModeAcompte } from '@/services/acomptes';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/**
 * Utiliser ce qui reste d'un acompte : l'appliquer au paiement d'une facture due du même client, ou le rembourser
 * (par défaut, par le mode de paiement de l'acompte).
 */
export default function UtiliserAcompte({ acompte, mode, onClose, onFait }: {
  acompte: Acompte;
  mode: 'appliquer' | 'rembourser';
  onClose: () => void;
  onFait: (acompte: Acompte, message: string) => void;
}) {
  const { t, language } = useLanguage();
  const td = t.deposits;
  const devise = useDevise();
  const montant = (v: number | string | null | undefined) => formatMontant(v, devise, language);
  const disponible = Number(acompte.disponible);
  const [factures, setFactures] = useState<Facture[] | null>(mode === 'appliquer' ? null : []);
  const [factureId, setFactureId] = useState<number | null>(null);
  const [saisie, setSaisie] = useState({ montant: String(disponible), date: aujourdhui(), mode: acompte.mode as ModeAcompte, reference: '' });
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    if (mode !== 'appliquer') return;
    let actif = true;
    getFactures({ client_id: acompte.client_id, statuts: 'emise,partiellement_payee', per_page: 50 }).then(r => {
      if (!actif) return;
      const dues = (r.data ?? []).filter(f => Number(f.reste_a_payer) > 0);
      setFactures(dues);
      if (dues[0]) {
        setFactureId(dues[0].id);
        setSaisie(s => ({ ...s, montant: String(Math.min(disponible, Number(dues[0].reste_a_payer))) }));
      }
    }, e => { if (actif) setErreur(messageErreur(e, td.error)); });
    return () => { actif = false; };
  }, [acompte.client_id, mode, disponible, td.error]);

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
      if (mode === 'appliquer') {
        if (!factureId) return;
        const resultat = await appliquerAcompte(acompte.id, { facture_id: factureId, montant: Number(saisie.montant), date: saisie.date });
        onFait(resultat, td.applyDone.replace('{numero}', factures?.find(f => f.id === factureId)?.numero ?? ''));
      } else {
        const resultat = await rembourserAcompte(acompte.id, {
          montant: Number(saisie.montant), date_remboursement: saisie.date, mode: saisie.mode, reference: saisie.reference.trim() || undefined,
        });
        onFait(resultat, td.refundDone);
      }
    } catch (err) {
      setErreur(messageErreur(err, td.error));
    } finally {
      setOccupe(false);
    }
  }

  const date = formatDateDocument(acompte.date_acompte, language);
  const titre = (mode === 'appliquer' ? td.applyTitle : td.refundTitle).replace('{date}', date);

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <form onSubmit={valider} role="dialog" aria-modal="true" aria-labelledby="utiliser-acompte-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md space-y-4">
        <div className="flex items-start justify-between gap-3">
          <h2 id="utiliser-acompte-titre" className="text-lg font-bold text-gray-900 dark:text-white">{titre}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={td.close}><X size={18} /></button>
        </div>
        <p className="text-sm text-gray-600 dark:text-gray-300">
          {acompte.client?.raison_sociale && <span className="block font-medium">{acompte.client.raison_sociale}</span>}
          {td.availableAmount.replace('{montant}', montant(disponible))}
          <span className="block text-xs text-gray-500 dark:text-gray-400">{mode === 'appliquer' ? td.applyHelp : td.refundHelp}</span>
        </p>

        {mode === 'appliquer' && factures === null && !erreur && <p className="text-sm text-gray-500">{td.loading}</p>}
        {mode === 'appliquer' && factures !== null && factures.length === 0 && <p className="px-3 py-2 rounded-lg text-sm bg-amber-50 text-amber-800">{td.noInvoice}</p>}
        {mode === 'appliquer' && factures !== null && factures.length > 0 && (
          <label className="block text-xs text-gray-500 dark:text-gray-400">{td.invoice}
            <select value={factureId ?? ''} onChange={e => choisirFacture(Number(e.target.value))} className={classeChamp}>
              {factures.map(f => (
                <option key={f.id} value={f.id}>{f.numero} · {formatDateDocument(f.date_facture, language)} · {td.invoiceDue.replace('{montant}', montant(f.reste_a_payer))}</option>
              ))}
            </select>
          </label>
        )}

        {(mode === 'rembourser' || (factures?.length ?? 0) > 0) && (
          <>
            <div className="grid grid-cols-2 gap-3">
              <label className="block text-xs text-gray-500 dark:text-gray-400">{td.amount}
                <input type="number" required min="0.01" step="0.01" max={disponible} value={saisie.montant}
                  onChange={e => setSaisie({ ...saisie, montant: e.target.value })} className={classeChamp} />
              </label>
              <label className="block text-xs text-gray-500 dark:text-gray-400">{td.date}
                <input type="date" required value={saisie.date} min={dateSaisie(acompte.date_acompte)}
                  onChange={e => setSaisie({ ...saisie, date: e.target.value })} className={classeChamp} />
              </label>
            </div>
            {mode === 'rembourser' && (
              <div className="grid grid-cols-2 gap-3">
                <label className="block text-xs text-gray-500 dark:text-gray-400">{td.mode}
                  <select value={saisie.mode} onChange={e => setSaisie({ ...saisie, mode: e.target.value as ModeAcompte })} className={classeChamp}>
                    {MODES_ACOMPTE.map(m => <option key={m} value={m}>{td.modes[m]}</option>)}
                  </select>
                </label>
                <label className="block text-xs text-gray-500 dark:text-gray-400">{td.reference}
                  <input value={saisie.reference} maxLength={100} placeholder={td.referenceHelp}
                    onChange={e => setSaisie({ ...saisie, reference: e.target.value })} className={classeChamp} />
                </label>
              </div>
            )}
          </>
        )}

        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{td.cancel}</button>
          <button type="submit" disabled={occupe || (mode === 'appliquer' && !factureId)}
            className="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700 disabled:opacity-50">
            {mode === 'appliquer' ? td.apply : td.refund}
          </button>
        </div>
      </form>
    </div>
  );
}
