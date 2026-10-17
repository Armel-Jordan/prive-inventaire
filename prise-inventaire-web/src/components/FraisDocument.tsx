import { X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import type { FraisAutomatiques } from '@/hooks/useFraisAutomatiques';

/**
 * Frais additionnels et de livraison proposés pour le document en cours : ajoutés à ses lignes à l'enregistrement,
 * chacun peut être retiré.
 */
export default function FraisDocument({ frais, montant }: { frais: FraisAutomatiques; montant: (valeur: number) => string }) {
  const { t } = useLanguage();
  const tf = t.fees;
  if (frais.lignes.length === 0) return null;

  return (
    <div className="mt-2 rounded-lg border border-dashed border-amber-300 bg-amber-50/60 p-2 space-y-1 dark:border-amber-700 dark:bg-amber-900/10">
      <p className="text-xs font-medium text-amber-800 dark:text-amber-300">{tf.addedToDocument}</p>
      {frais.lignes.map(l => (
        <div key={l.frais_id} className="flex flex-wrap items-center gap-2 text-sm">
          <span className="flex-1 min-w-[10rem] text-gray-800 dark:text-gray-200">
            {l.libelle}
            {l.produit && l.produit.description !== l.libelle && <span className="ml-1 text-xs text-gray-500">({l.produit.description})</span>}
          </span>
          <span className="text-gray-600 dark:text-gray-400 whitespace-nowrap">{l.quantite} × {montant(l.prix_unitaire_ht)}</span>
          <span className="w-24 text-right font-medium text-gray-900 dark:text-white whitespace-nowrap">{montant(l.montant_ht)}</span>
          <button type="button" onClick={() => frais.retirer(l.frais_id)} title={tf.removeFromDocument} aria-label={`${tf.removeFromDocument} — ${l.libelle}`}
            className="text-red-500 hover:text-red-700"><X size={16} /></button>
        </div>
      ))}
    </div>
  );
}
