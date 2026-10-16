import { API_BASE_URL, ApiError, NetworkError } from '@/services/http';

/**
 * Appels du portail client. La session est propre au portail (un jeton par entreprise, gardé sous sa propre
 * clé) : aucun en-tête du back-office (jeton d'employé, code d'entreprise) n'est envoyé d'ici.
 */

export interface InfosPortail {
  entreprise: string;
  logo_url: string | null;
  telephone: string | null;
  email: string | null;
  message_accueil: string | null;
}

export interface ComptePortail {
  id: number;
  nom: string;
  email: string;
  telephone: string | null;
  client_id: number;
}

export interface SessionPortail {
  token: string;
  compte: ComptePortail;
}

export interface AdressePortail {
  id: number;
  libelle: string | null;
  adresse: string;
  code_postal: string | null;
  ville: string | null;
  pays: string | null;
  livraison: boolean;
  principale: boolean;
}

export interface MoiPortail {
  compte: ComptePortail;
  client: {
    id: number;
    code: string;
    raison_sociale: string;
    email: string | null;
    telephone: string | null;
    adresse_facturation: string | null;
    code_postal: string | null;
    ville: string | null;
    adresses: AdressePortail[];
  };
  entreprise: { nom: string; devise: string | null; telephone: string | null; email: string | null };
}

export interface ProduitCatalogue {
  id: number;
  numero: string;
  description: string;
  mesure: string | null;
  categorie: string | null;
  /** Prix du client à l'unité ; null : prix sur demande (pas commandable en ligne). */
  prix: number | null;
  prix_liste: number | null;
}

export interface Page<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface PageCatalogue extends Page<ProduitCatalogue> {
  categories: string[];
}

export interface LignePanier {
  produit_id: number;
  quantite: number;
}

export interface LignePrix extends LignePanier {
  numero: string | null;
  description: string | null;
  mesure: string | null;
  prix: number | null;
  montant: number | null;
  indisponible: boolean;
}

export type StatutCommande = 'en_attente' | 'acceptee' | 'refusee' | 'facturee' | 'annulee';

/** Les montants arrivent en texte (décimaux de l'API) : Number() avant de compter. */
export interface CommandeResume {
  id: number;
  numero: string;
  date_commande: string;
  date_livraison_souhaitee: string | null;
  statut: StatutCommande;
  montant_ht: string;
  montant_ttc: string;
  portail_compte_id: number | null;
  lignes_count: number;
}

export interface DetailCommande {
  commande: Omit<CommandeResume, 'lignes_count'> & {
    motif_refus: string | null;
    notes: string | null;
    montant_tva: string;
    annulable: boolean;
    lignes: {
      produit_id: number;
      produit: { numero: string; description: string; mesure: string | null } | null;
      quantite: number;
      prix_unitaire_ht: number;
      montant_ht: number;
      montant_ttc: number;
    }[];
  };
  facture: { id: number; numero: string; statut: string } | null;
}

export type StatutFacture = 'emise' | 'partiellement_payee' | 'payee';

export interface FacturePortail {
  id: number;
  numero: string;
  date_facture: string;
  date_echeance: string | null;
  statut: StatutFacture;
  montant_ttc: string;
  reste_a_payer: string;
  commande_id: number | null;
}

// --- Session et panier, gardés dans le navigateur, par entreprise -------------------------------------------
const cleSession = (slug: string) => `portail_session_${slug}`;
const clePanier = (slug: string, compte: number) => `portail_panier_${slug}_${compte}`;

function lire<T>(cle: string): T | null {
  try {
    const brut = localStorage.getItem(cle);
    return brut ? (JSON.parse(brut) as T) : null;
  } catch {
    return null;
  }
}

function ecrire(cle: string, valeur: unknown): void {
  try {
    if (valeur === null) localStorage.removeItem(cle);
    else localStorage.setItem(cle, JSON.stringify(valeur));
  } catch {
    // Stockage plein ou interdit (navigation privée) : la session tient le temps de l'onglet.
  }
}

export function lireSession(slug: string): SessionPortail | null {
  const session = lire<SessionPortail>(cleSession(slug));
  return session?.token && session.compte ? session : null;
}

export const ecrireSession = (slug: string, session: SessionPortail | null) => ecrire(cleSession(slug), session);

export interface ArticlePanier extends LignePanier {
  numero: string;
  description: string;
  mesure: string | null;
  prix: number | null;
}

export function lirePanier(slug: string, compte: number): ArticlePanier[] {
  const panier = lire<ArticlePanier[]>(clePanier(slug, compte));
  return Array.isArray(panier) ? panier.filter(a => a && a.produit_id > 0 && a.quantite > 0) : [];
}

export const ecrirePanier = (slug: string, compte: number, panier: ArticlePanier[]) =>
  ecrire(clePanier(slug, compte), panier.length ? panier : null);

// --- Requêtes --------------------------------------------------------------------------------------------
export interface OptionsAppel {
  methode?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  corps?: unknown;
  jeton?: string | null;
  /** Document à télécharger (PDF). */
  fichier?: boolean;
}

const attendre = (essai: number) => new Promise(resolve => setTimeout(resolve, Math.min(1000 * 2 ** (essai - 1), 4000)));

/**
 * Requête vers l'API. Lève ApiError (réponse non 2xx, avec son corps) ou NetworkError (pas de réponse). Les
 * lectures sont retentées sur coupure réseau ou erreur serveur ; jamais une commande (pas de doublon).
 */
export async function appelPortail<T>(chemin: string, { methode = 'GET', corps, jeton, fichier = false }: OptionsAppel = {}): Promise<T> {
  const entetes: Record<string, string> = { Accept: fichier ? 'application/pdf' : 'application/json' };
  if (corps !== undefined) entetes['Content-Type'] = 'application/json';
  if (jeton) entetes.Authorization = `Bearer ${jeton}`;
  const tentatives = methode === 'GET' ? 3 : 1;

  for (let essai = 1; ; essai++) {
    const controleur = new AbortController();
    const minuterie = setTimeout(() => controleur.abort(), 20000);
    let reponse: Response;
    try {
      reponse = await fetch(`${API_BASE_URL}${chemin}`, {
        method: methode,
        headers: entetes,
        body: corps !== undefined ? JSON.stringify(corps) : undefined,
        signal: controleur.signal,
      });
    } catch {
      if (essai < tentatives) {
        await attendre(essai);
        continue;
      }
      throw new NetworkError();
    } finally {
      clearTimeout(minuterie);
    }

    if ((reponse.status >= 500 || reponse.status === 429) && essai < tentatives) {
      await attendre(essai);
      continue;
    }
    if (!reponse.ok) {
      const donnees = await reponse.json().catch(() => null);
      throw new ApiError(reponse.status, (donnees as { message?: string } | null)?.message || `Erreur ${reponse.status}`, donnees);
    }
    if (fichier) return (await reponse.blob()) as T;
    const texte = await reponse.text();
    return (texte ? JSON.parse(texte) : undefined) as T;
  }
}

/** Enregistre un document reçu (PDF) sous le nom donné. */
export function telecharger(document: Blob, nom: string): void {
  const url = URL.createObjectURL(document);
  const lien = window.document.createElement('a');
  lien.href = url;
  lien.download = nom;
  window.document.body.appendChild(lien);
  lien.click();
  lien.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
}
