import { useEffect, useState } from 'react';
import { X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, dateSaisie, useMontantDocument } from '@/lib/documents';
import { getSecteurs } from '@/services/api';
import { cloturerRetourFournisseur, type RetourFournisseur } from '@/services/retoursFournisseurs';
import type { Secteur } from '@/types';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/**
 * Clôture d'un retour expédié, quand le fournisseur a répondu : crédit obtenu et référence de son avoir
 * (remboursement), marchandise de remplacement entrée dans un secteur (remplacement), ou simple clôture (consigne).
 */
export default function CloturerRetourFournisseur({ retour, onClose, onClos }: {
  retour: RetourFournisseur;
  onClose: () => void;
  onClos: (retour: RetourFournisseur) => void;
}) {
  const { t } = useLanguage();
  const tr = t.supplierReturns;
  const montantDoc = useMontantDocument();
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [saisie, setSaisie] = useState({
    date_cloture: aujourdhui(),
    montant_credite: String(Number(retour.montant)),
    reference_avoir: '',
    secteur_remplacement_id: '',
  });
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    if (retour.type !== 'remplacement') return;
    let actif = true;
    getSecteurs('actifs').then(liste => { if (actif) setSecteurs(liste); }, () => {});
    return () => { actif = false; };
  }, [retour.type]);

  async function cloturer() {
    setOccupe(true);
    setErreur('');
    try {
      onClos(await cloturerRetourFournisseur(retour.id, {
        date_cloture: saisie.date_cloture,
        montant_credite: retour.type === 'remboursement' ? Number(saisie.montant_credite) : null,
        reference_avoir: retour.type === 'remboursement' ? saisie.reference_avoir.trim() || null : null,
        secteur_remplacement_id: retour.type === 'remplacement' && saisie.secteur_remplacement_id ? Number(saisie.secteur_remplacement_id) : null,
      }));
    } catch (e) {
      setErreur(messageErreur(e, tr.error));
    } finally {
      setOccupe(false);
    }
  }

  const montantInvalide = retour.type === 'remboursement' && (saisie.montant_credite === '' || Number(saisie.montant_credite) < 0);

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="cloture-retour-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="cloture-retour-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tr.closeTitle.replace('{numero}', retour.numero ?? '')}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tr.close}><X size={18} /></button>
        </div>
        <p className="text-sm text-gray-500 dark:text-gray-400">{tr.closeHelp[retour.type]}</p>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{tr.closeDate}
          <input type="date" value={saisie.date_cloture} min={retour.date_expedition ? dateSaisie(retour.date_expedition) : undefined} max={aujourdhui()}
            onChange={e => setSaisie({ ...saisie, date_cloture: e.target.value })} className={classeChamp} />
        </label>
        {retour.type === 'remboursement' && (
          <>
            <label className="block text-xs text-gray-500 dark:text-gray-400">
              {tr.creditObtained}{retour.devise ? ` (${retour.devise})` : ''}
              <input type="number" min={0} step="0.01" value={saisie.montant_credite} onChange={e => setSaisie({ ...saisie, montant_credite: e.target.value })}
                className={`${classeChamp} ${montantInvalide ? 'border-red-500' : ''}`} />
              <span className="block mt-1">{tr.expectedCredit} {montantDoc(retour.montant, retour.devise)}</span>
            </label>
            <label className="block text-xs text-gray-500 dark:text-gray-400">{tr.creditReference}
              <input value={saisie.reference_avoir} maxLength={50} placeholder={tr.creditReferenceHelp}
                onChange={e => setSaisie({ ...saisie, reference_avoir: e.target.value })} className={classeChamp} />
            </label>
          </>
        )}
        {retour.type === 'remplacement' && (
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tr.replacementSector}
            <select value={saisie.secteur_remplacement_id} onChange={e => setSaisie({ ...saisie, secteur_remplacement_id: e.target.value })} className={classeChamp}>
              <option value="">{tr.noReplacementStock}</option>
              {secteurs.map(s => <option key={s.id} value={s.id}>{s.code} — {s.nom}</option>)}
            </select>
          </label>
        )}
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tr.cancel}</button>
          <button type="button" onClick={() => { void cloturer(); }} disabled={occupe || montantInvalide}
            className="px-4 py-2 bg-orange-600 text-white rounded-lg text-sm hover:bg-orange-700 disabled:opacity-50">{tr.closeAction}</button>
        </div>
      </div>
    </div>
  );
}
