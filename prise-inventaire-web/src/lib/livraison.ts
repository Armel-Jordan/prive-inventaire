import type { LivraisonDocument } from '@/services/api';

/**
 * Contact et « Livrer à » choisis dans un formulaire de document de vente (socle S4.1) ; non définis : pas encore
 * choisis (les principaux du client sont alors proposés).
 */
export interface ValeurLivraison {
  contact_id?: number | null;
  adresse_livraison_id?: number | null;
  /** Document modifié : l'adresse qu'il porte et ses instructions recopiées, montrées tant qu'elle reste choisie. */
  copie?: { adresse_livraison_id: number | null; instructions_livraison: string | null };
}

/** Valeur d'un document ouvert en modification : ce qu'il porte, sans rien proposer. */
export const livraisonDuDocument = (document: LivraisonDocument): ValeurLivraison => ({
  contact_id: document.contact_id ?? null,
  adresse_livraison_id: document.adresse_livraison_id ?? null,
  copie: { adresse_livraison_id: document.adresse_livraison_id ?? null, instructions_livraison: document.instructions_livraison ?? null },
});

/** Champs envoyés à l'API ; non choisis, ils sont omis et l'API prend les principaux du client. */
export const saisieLivraison = (valeur: ValeurLivraison) => ({ contact_id: valeur.contact_id, adresse_livraison_id: valeur.adresse_livraison_id });
