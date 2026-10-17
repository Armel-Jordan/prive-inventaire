import { apiFetch } from '@/services/http';

/**
 * Disponibilité des produits : stock compté (somme des scans, comme les alertes de stock), réservé par les commandes
 * client acceptées, disponible, en commande chez les fournisseurs, attendu en reliquat.
 */

export interface Disponibilite {
  produit_id: number;
  numero: string;
  description: string;
  mesure: string | null;
  stock: number;
  reserve: number;
  disponible: number;
  en_commande: number;
  reliquats: number;
  manque: number;
}

/** Manque signalé à l'acceptation d'une commande. */
export interface ManqueCommande {
  produit_id: number;
  numero: string;
  description: string;
  commande: number;
  disponible: number;
  manque: number;
}

export async function getDisponibilites(params: { recherche?: string; manques?: boolean; tous?: boolean } = {}): Promise<{ data: Disponibilite[]; resume: { produits: number; en_manque: number } }> {
  const query = new URLSearchParams();
  if (params.recherche) query.set('recherche', params.recherche);
  if (params.manques) query.set('manques', '1');
  if (params.tous) query.set('tous', '1');
  return apiFetch(`/disponibilites?${query.toString()}`);
}
