import type { PrixCalcule } from '@/services/api';

/** Origine d'un prix proposé (liste, remise du client, règle), affichée à côté de la ligne. */
export interface InfoPrix {
  libelle: string;
  /** Remise par rapport au prix de la liste, en %. */
  remise: number;
}

/**
 * Ligne d'un devis, d'une commande ou d'une facture dont le prix peut être proposé : `auto` tant que l'utilisateur n'a
 * pas saisi son prix (une ligne d'un document existant ne l'est pas : son prix enregistré reste).
 */
export interface LignePrix {
  produit_id: number;
  quantite: number;
  /** Unité de vente de la ligne (la caisse de 12) ; vide : l'unité de stock du produit. */
  unite?: string | null;
  auto?: boolean;
  info_prix?: InfoPrix | null;
}

/** Clé d'une ligne parmi les prix calculés : produit, quantité et unité. */
export const clePrix = (ligne: { produit_id: number; quantite: number; unite?: string | null }) => `${ligne.produit_id}:${ligne.quantite}:${ligne.unite ?? ''}`;

/** Prix dans la devise du document (taux : valeur d'une unité de cette devise dans celle de l'entreprise). */
export function dansLaDevise(prix: number, devise: string, taux: number): number {
  return devise && taux > 0 ? Math.round((prix / taux) * 100) / 100 : prix;
}

/** Origine d'un prix calculé, ou null pour le prix de base. */
export function infoPrix(prix: PrixCalcule, origines: Record<string, string>): InfoPrix | null {
  if (prix.origine === 'base') return null;
  const candidat = prix.candidats.find(c => c.origine === prix.origine && c.prix === prix.prix);
  const libelle = (prix.origine === 'regle' ? prix.regle?.libelle : prix.origine === 'liste' ? prix.liste?.nom : candidat?.libelle) ?? '';

  return { libelle: (origines[prix.origine] ?? prix.origine).replace('{libelle}', libelle), remise: Number(prix.remise_pourcentage) || 0 };
}

/** Produit choisi sur une ligne : son prix de base est proposé en attendant celui du client, sauf prix saisi. */
export function choisirProduit<L extends LignePrix>(ligne: L, produitId: number, champ: keyof L & string, prixBase: number): L {
  const propose = ligne.auto || Number(ligne[champ]) === 0;

  return propose ? { ...ligne, produit_id: produitId, [champ]: prixBase, auto: true, info_prix: null } : { ...ligne, produit_id: produitId };
}

/** Unité choisie sur une ligne : le prix de base de l'unité est proposé en attendant celui du client, sauf prix saisi. */
export function changerUnite<L extends LignePrix>(ligne: L, unite: string | null, champ: keyof L & string, prixBase: number): L {
  const propose = ligne.auto || Number(ligne[champ]) === 0;

  return propose ? { ...ligne, unite, [champ]: prixBase, auto: true, info_prix: null } : { ...ligne, unite };
}

/** Prix saisi à la main : il n'est plus remplacé par les prix proposés. */
export function saisirPrix<L extends LignePrix>(ligne: L, champ: keyof L & string, prix: number): L {
  return { ...ligne, [champ]: prix, auto: false, info_prix: null };
}

/** Prix calculés appliqués aux lignes proposées, convertis dans la devise du document. */
export function appliquerPrix<L extends LignePrix>(lignes: L[], prix: Map<string, PrixCalcule>, champ: keyof L & string,
  conversion: (prix: number) => number, origines: Record<string, string>): L[] {
  return lignes.map(ligne => {
    const calcule = ligne.auto ? prix.get(clePrix(ligne)) : undefined;

    return calcule && calcule.prix !== null ? { ...ligne, [champ]: conversion(calcule.prix), info_prix: infoPrix(calcule, origines) } : ligne;
  });
}
