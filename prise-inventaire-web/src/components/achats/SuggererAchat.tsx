import { useState } from 'react';
import { ShoppingCart, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { ApiError } from '@/services/http';
import { messageErreur } from '@/lib/erreurs';
import { suggererAchat } from '@/services/suggestionsAchat';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/**
 * Signaler qu'un produit doit être commandé : la suggestion attend la décision de l'acheteur (Achats → Suggestions
 * d'achat). Une suggestion ouverte du même produit est signalée ; on peut tout de même en créer une seconde.
 */
export default function SuggererAchat({ produit, onClose, onFait }: {
  produit: { id: number; numero: string; description: string };
  onClose: () => void;
  onFait: () => void;
}) {
  const { t } = useLanguage();
  const ts = t.purchaseSuggestions;
  const [quantite, setQuantite] = useState('1');
  const [remarque, setRemarque] = useState('');
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  async function envoyer(forcer: boolean) {
    setOccupe(true);
    setErreur('');
    try {
      await suggererAchat({ produit_id: produit.id, quantite: Number(quantite), remarque: remarque.trim() || undefined, forcer });
      onFait();
    } catch (err) {
      const deja = err instanceof ApiError && (err.data as { errors?: Record<string, string[]> } | null)?.errors?.deja?.[0];
      if (deja && !forcer && confirm(`${deja} ${ts.createAnyway}`)) {
        await envoyer(true);
        return;
      }
      setErreur(messageErreur(err, ts.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <form onSubmit={e => { e.preventDefault(); void envoyer(false); }} role="dialog" aria-modal="true" aria-label={ts.suggestTitle}
        className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-sm space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 className="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2"><ShoppingCart size={20} className="text-amber-600" /> {ts.suggestTitle}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 dark:text-white" aria-label={ts.close}><X size={18} /></button>
        </div>
        <p className="text-sm text-gray-600 dark:text-gray-300"><span className="font-mono">{produit.numero}</span> — {produit.description}</p>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{ts.quantity}
          <input type="number" min="1" step="1" required autoFocus value={quantite} onChange={e => setQuantite(e.target.value)} className={classeChamp} />
        </label>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{ts.remark}
          <input value={remarque} maxLength={255} placeholder={ts.manualRemark} onChange={e => setRemarque(e.target.value)} className={classeChamp} />
        </label>
        <p className="text-xs text-gray-500 dark:text-gray-400">{ts.suggestHelp}</p>
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{ts.cancel}</button>
          <button type="submit" disabled={occupe} className="px-4 py-2 bg-amber-600 text-white rounded-lg text-sm hover:bg-amber-700 disabled:opacity-50">{ts.suggest}</button>
        </div>
      </form>
    </div>
  );
}
