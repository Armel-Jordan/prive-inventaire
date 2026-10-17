import { aDesUnites } from '@/lib/unitesVente';
import type { Produit } from '@/types';

/**
 * Choix de l'unité d'une ligne de vente : l'unité de stock du produit ou une de ses unités de vente (avec son facteur).
 * Rien n'est affiché pour un produit qui ne se vend que dans son unité de stock : la ligne reste comme avant.
 */
export default function ChoixUnite({ produit, valeur, onChange, libelle, className = '' }: {
  produit?: Produit | null;
  /** Code de l'unité ; vide : l'unité de stock. */
  valeur?: string | null;
  onChange: (unite: string | null) => void;
  /** Nom accessible du champ (« Unité — BIERE »). */
  libelle: string;
  className?: string;
}) {
  if (!produit || !aDesUnites(produit)) return null;

  return (
    <select value={valeur ?? ''} onChange={e => onChange(e.target.value || null)} aria-label={libelle} title={libelle}
      className={`px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white ${className}`}>
      <option value="">{produit.mesure || '—'}</option>
      {(produit.unites ?? []).map(u => (
        <option key={u.code} value={u.code}>{u.code} (×{Number(u.facteur)})</option>
      ))}
    </select>
  );
}
