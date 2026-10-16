import { apiFetch } from '@/services/http';

/** Recherche globale : fiches et documents de l'entreprise, regroupés par type. */

export const TYPES_RECHERCHE = [
  'produits', 'clients', 'fournisseurs', 'devis', 'commandes_client', 'factures',
  'bons_livraison', 'commandes_fournisseur', 'secteurs', 'employes',
] as const;

export type TypeRecherche = typeof TYPES_RECHERCHE[number];

/** Module de permission de la page correspondante (mêmes noms que le menu). */
export const MODULE_DU_TYPE: Record<TypeRecherche, string> = {
  produits: 'produits',
  clients: 'clients',
  fournisseurs: 'fournisseurs',
  devis: 'devis',
  commandes_client: 'commandes_client',
  factures: 'factures',
  bons_livraison: 'bons_livraison',
  commandes_fournisseur: 'commandes_fournisseur',
  secteurs: 'secteurs',
  employes: 'employes',
};

export interface ResultatRecherche {
  type: TypeRecherche;
  id: number;
  titre: string;
  sous_titre: string | null;
  details: Record<string, string | number | boolean | null>;
  lien: string;
}

export interface GroupeRecherche {
  type: TypeRecherche;
  total: number;
  resultats: ResultatRecherche[];
}

export async function rechercherGlobal(texte: string, types: string[]): Promise<{ q: string; groupes: GroupeRecherche[] }> {
  const query = new URLSearchParams({ q: texte, types: types.join(',') });
  return apiFetch(`/recherche?${query.toString()}`, { retries: 0 });
}
