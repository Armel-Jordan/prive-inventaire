import { apiFetch } from '@/services/http';
import { ouvrirPdf } from '@/services/api';
import { fuseauUtilisateur, type ModeCaisse } from '@/services/caisses';

/**
 * Point de vente : recherche des produits, aperçu de la vente (prix du client, taxes), vente au comptoir (facture créée,
 * émise et encaissée d'un coup) et reçu (module Ventes).
 */

export interface ProduitPdv {
  id: number;
  numero: string;
  description: string;
  mesure: string | null;
  prix_unitaire: string | number | null;
}

export interface LigneVente {
  produit_id: number;
  quantite: number;
  /** Prix saisi ; absent : le prix du client. */
  prix_unitaire_ht?: number;
}

export interface ApercuVente {
  client: { id: number; code: string; raison_sociale: string; de_passage: boolean };
  devise: string | null;
  lignes: {
    produit_id: number;
    quantite: number;
    prix_unitaire_ht: number | null;
    origine: string | null;
    produit: { id: number; numero: string; description: string; mesure: string | null } | null;
    sans_prix: boolean;
    montant_ht: number | null;
    montant_tva: number | null;
    montant_ttc: number | null;
  }[];
  montant_ht: number;
  montant_tva: number;
  montant_ttc: number;
}

export interface PaiementVente {
  mode: ModeCaisse;
  montant: number;
  reference?: string;
}

export interface VenteEnregistree {
  message: string;
  monnaie: number;
  facture: {
    id: number;
    numero: string;
    statut: string;
    devise: string | null;
    montant_ttc: string | number;
    reste_a_payer: string | number;
    client: { id: number; code: string; raison_sociale: string; de_passage: boolean } | null;
  };
}

export function chercherProduitsPdv(recherche: string): Promise<ProduitPdv[]> {
  return apiFetch(`/point-de-vente/produits?${new URLSearchParams({ recherche })}`);
}

export function calculerVente(donnees: { client_id?: number; lignes: LigneVente[]; date: string }): Promise<ApercuVente> {
  return apiFetch('/point-de-vente/calculer', { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

export function enregistrerVente(donnees: {
  client_id?: number;
  date: string;
  lignes: LigneVente[];
  paiements: PaiementVente[];
  caisse_id?: number;
  passage_nom?: string;
}): Promise<VenteEnregistree> {
  return apiFetch('/point-de-vente/ventes', { method: 'POST', body: JSON.stringify(donnees), retries: 0 });
}

/** Reçu de la vente (ticket de 80 mm), heure dans le fuseau de l'utilisateur. */
export function imprimerRecu(facture: { id: number; numero: string }): Promise<void> {
  return ouvrirPdf(`/point-de-vente/recus/${facture.id}?fuseau=${encodeURIComponent(fuseauUtilisateur())}`, `recu-${facture.numero}.pdf`);
}
