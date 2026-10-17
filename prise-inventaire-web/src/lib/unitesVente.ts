import type { Produit } from '@/types';

/**
 * Unités de vente d'un produit sur les lignes des documents (phase 2.2) : son unité de stock (`mesure`, facteur 1) et
 * ses unités de vente (la caisse de 12), chacune avec son facteur et, au besoin, son propre prix. Une ligne sans unité
 * est dans l'unité de stock.
 */

/** Le produit se vend-il dans d'autres unités que son unité de stock ? */
export function aDesUnites(produit?: Produit | null): boolean {
  return (produit?.unites?.length ?? 0) > 0;
}

/** Unité proposée d'office sur une nouvelle ligne : celle par défaut du produit, sinon son unité de stock (null). */
export function uniteParDefaut(produit?: Produit | null): string | null {
  return produit?.unites?.find(u => u.par_defaut)?.code ?? null;
}

/** Prix de base d'une unité, dans la devise de l'entreprise : son prix propre, sinon le prix du produit × facteur. */
export function prixUnite(produit: Produit | undefined | null, unite: string | null | undefined): number {
  const base = Number(produit?.prix_unitaire) || 0;
  const choisie = unite ? produit?.unites?.find(u => u.code === unite) : undefined;
  if (!choisie) return base;
  return choisie.prix !== null && choisie.prix !== undefined ? Number(choisie.prix) : Math.round(base * Number(choisie.facteur) * 100) / 100;
}

/** Libellé d'une quantité avec son unité (« 2 CS ») ; sans unité de ligne, l'unité de stock si on la connaît. */
export function quantiteAvecUnite(quantite: number | string, unite?: string | null, mesure?: string | null): string {
  const code = unite || mesure;
  return code ? `${quantite} ${code}` : String(quantite);
}
