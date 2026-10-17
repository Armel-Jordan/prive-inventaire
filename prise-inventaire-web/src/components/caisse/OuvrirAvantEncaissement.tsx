import { useState } from 'react';
import { Banknote } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import type { CaissePoste } from '@/hooks/useCaissePoste';
import { ouvrirCaisse } from '@/services/caisses';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/** La caisse du poste est fermée : l'ouvrir avec son fond, puis l'encaissement reprend. */
export default function OuvrirAvantEncaissement({ caisse, onAnnuler, onOuverte }: { caisse: CaissePoste; onAnnuler: () => void; onOuverte: () => Promise<void> }) {
  const { t } = useLanguage();
  const tc = t.cashRegisters;
  const [fond, setFond] = useState('0');
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  async function ouvrir(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    try {
      await ouvrirCaisse(caisse.id, { fond: Number(fond) || 0 });
    } catch (err) {
      setErreur(messageErreur(err, tc.error));
      setOccupe(false);
      return;
    }
    await onOuverte();
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-[60] p-4">
      <form onSubmit={ouvrir} role="dialog" aria-modal="true" aria-labelledby="ouvrir-caisse-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-sm space-y-3">
        <h2 id="ouvrir-caisse-titre" className="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
          <Banknote className="text-emerald-600" size={20} /> {tc.openTitle.replace('{code}', caisse.code)}
        </h2>
        <p className="text-sm text-gray-600 dark:text-gray-300">{tc.closedPrompt.replace('{code}', caisse.code)}</p>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{tc.float}
          <input type="number" min="0" step="0.01" required value={fond} onChange={e => setFond(e.target.value)} className={classeChamp} autoFocus />
          <span className="block mt-0.5">{tc.floatHelp}</span>
        </label>
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onAnnuler} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tc.cancel}</button>
          <button type="submit" disabled={occupe} className="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm hover:bg-emerald-700 disabled:opacity-50">{tc.openAndContinue}</button>
        </div>
      </form>
    </div>
  );
}
