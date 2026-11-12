import { useEffect, useState } from 'react';
import { getProduits } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';

/** Statut de livraison calculé d'une commande ou d'une facture (socle S4.7). */
export type StatutLivraison = 'non_livre' | 'en_attente' | 'en_livraison' | 'livre_partiel' | 'livre_complet' | 'rien_a_livrer';

/** Âge d'une facture due. */
export type AgeFacture = 'courant' | '1_30' | '31_60' | '61_90' | 'plus_90';

const LIVRAISONS: StatutLivraison[] = ['non_livre', 'en_attente', 'en_livraison', 'livre_partiel', 'livre_complet', 'rien_a_livrer'];

const AGES: AgeFacture[] = ['courant', '1_30', '31_60', '61_90', 'plus_90'];

const CHAMP = 'px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/** Filtre « documents qui contiennent ce produit » (socle S4.7). */
export function FiltreProduit({ value, onChange }: { value: number | null; onChange: (produitId: number | null) => void }) {
  const { t } = useLanguage();
  const [produits, setProduits] = useState<{ id: number; libelle: string }[]>([]);

  useEffect(() => {
    let actif = true;
    getProduits().then(liste => {
      if (!actif) return;
      setProduits(liste.flatMap(p => (p.id ? [{ id: p.id, libelle: [p.numero, p.description].filter(Boolean).join(' — ') }] : [])));
    }, () => {});
    return () => { actif = false; };
  }, []);

  return (
    <select value={value ?? ''} onChange={e => onChange(e.target.value ? Number(e.target.value) : null)} aria-label={t.listStatus.productLabel} className={`${CHAMP} max-w-xs`}>
      <option value="">{t.listStatus.product}</option>
      {produits.map(p => <option key={p.id} value={p.id}>{p.libelle}</option>)}
    </select>
  );
}

/** Filtre « statut de livraison » des commandes et des factures. */
export function FiltreStatutLivraison({ value, onChange }: { value: StatutLivraison | ''; onChange: (statut: StatutLivraison | '') => void }) {
  const { t } = useLanguage();

  return (
    <select value={value} onChange={e => onChange(e.target.value as StatutLivraison | '')} aria-label={t.listStatus.deliveryLabel} className={CHAMP}>
      <option value="">{t.listStatus.delivery}</option>
      {LIVRAISONS.map(s => <option key={s} value={s}>{t.listStatus.deliveryStatuses[s]}</option>)}
    </select>
  );
}

/** Filtre « âge » des factures dues. */
export function FiltreAgeFacture({ value, onChange }: { value: AgeFacture | ''; onChange: (age: AgeFacture | '') => void }) {
  const { t } = useLanguage();

  return (
    <select value={value} onChange={e => onChange(e.target.value as AgeFacture | '')} aria-label={t.listStatus.ageLabel} className={CHAMP}>
      <option value="">{t.listStatus.age}</option>
      {AGES.map(a => <option key={a} value={a}>{t.listStatus.ages[a]}</option>)}
    </select>
  );
}

/** Statut de livraison sous le statut d'une commande ou d'une facture dans la liste. */
export function PastilleLivraison({ statut }: { statut?: string | null }) {
  const { t } = useLanguage();
  if (!statut || !LIVRAISONS.includes(statut as StatutLivraison)) return null;
  const couleur = statut === 'livre_complet' ? 'text-green-700 dark:text-green-400'
    : statut === 'rien_a_livrer' ? 'text-gray-400' : statut === 'non_livre' ? 'text-gray-500 dark:text-gray-400' : 'text-blue-700 dark:text-blue-300';

  return <span className={`block mt-1 text-xs ${couleur}`}>{t.listStatus.deliveryStatuses[statut as StatutLivraison]}</span>;
}
