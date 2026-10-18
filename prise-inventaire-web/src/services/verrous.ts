import { ApiError, apiFetch } from '@/services/http';

/**
 * Verrous des fiches en cours de modification (phase 6.3) : l'écran de modification verrouille la fiche, renouvelle le
 * verrou chaque minute et le lève à la fermeture ; un autre compte voit qui la modifie.
 */

export type EntiteVerrouillable = 'client' | 'fournisseur' | 'produit' | 'devis' | 'commande_client' | 'commande_fournisseur';

export interface InfoVerrou {
  entite: EntiteVerrouillable;
  entite_id: number;
  /** Faux : le verrou ne tient plus (fiche fermée, libérée ou abandonnée). */
  actif: boolean;
  /** Compte qui modifie la fiche (ou qui l'a modifiée en dernier). */
  par: string | null;
  par_id: number;
  /** Début de la modification. */
  depuis: string | null;
  expire_le: string | null;
  /** Responsable qui a libéré la fiche. */
  libere_par: string | null;
}

export interface FicheEnCours extends InfoVerrou {
  id: number;
  libelle: string;
  /** Écran de la fiche. */
  lien: string;
}

/** Durée entre deux renouvellements du verrou par l'écran (le serveur le garde trois minutes). */
export const RENOUVELLEMENT_MS = 60_000;

export async function poserVerrou(
  entite: EntiteVerrouillable,
  id: number,
  options: { prendreLaMain?: boolean; renouveler?: boolean } = {},
): Promise<InfoVerrou> {
  const reponse = await apiFetch<{ verrou: InfoVerrou }>('/verrous', {
    method: 'POST',
    body: JSON.stringify({ entite, entite_id: id, prendre_la_main: options.prendreLaMain ?? false, renouveler: options.renouveler ?? false }),
  });
  return reponse.verrou;
}

/** Lève le verrou du compte ; la requête part même si la page se ferme (keepalive). */
export function leverVerrou(entite: EntiteVerrouillable, id: number): Promise<void> {
  const requete = new URLSearchParams({ entite, entite_id: String(id) });
  return apiFetch<void>(`/verrous?${requete}`, { method: 'DELETE', keepalive: true, responseType: 'void' });
}

/** Fiches en cours de modification dans l'entreprise. */
export function getFichesEnCours(): Promise<FicheEnCours[]> {
  return apiFetch('/verrous');
}

/** Libère la fiche qu'un autre compte modifie : ses changements non enregistrés seront refusés. */
export function libererFiche(id: number): Promise<void> {
  return apiFetch<void>(`/verrous/${id}`, { method: 'DELETE', responseType: 'void' });
}

/** Verrou porté par un refus 409 (fiche en cours de modification par un autre compte, ou verrou perdu). */
export function verrouDuRefus(erreur: unknown): InfoVerrou | null {
  if (!(erreur instanceof ApiError) || erreur.status !== 409) return null;
  const donnees = erreur.data as { verrou?: InfoVerrou } | null;
  return donnees?.verrou ?? null;
}
