import { API_BASE_URL, ApiError, NetworkError } from '@/services/http';

/**
 * Appels de l'espace client à l'API. La session est propre à l'espace client (jeton du client, sans rapport
 * avec les comptes des employés) et désigne l'entreprise ; le panier est gardé par accès.
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
  /** Code de l'entreprise. */
  slug: string;
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
  /** Description longue (spécifications), dans la langue du visiteur quand la fiche est traduite. */
  description_longue: string | null;
  fabricant: string | null;
  reference_fabricant: string | null;
  /** Le produit a une image principale (lue par /espace-client/produits/{id}/image). */
  image: boolean;
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
  /** Devise de la commande (null : celle de l'entreprise). */
  devise?: string | null;
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

export type StatutFacture = 'emise' | 'partiellement_payee' | 'payee' | 'creditee';

export interface FacturePortail {
  id: number;
  numero: string;
  date_facture: string;
  date_echeance: string | null;
  statut: StatutFacture;
  montant_ttc: string;
  reste_a_payer: string;
  commande_id: number | null;
  /** Devise de la facture (null : celle de l'entreprise). */
  devise?: string | null;
}

// --- Session, dernière entreprise et panier, gardés dans le navigateur -----------------------------------
const CLE_SESSION = 'espace_client_session';
const CLE_ENTREPRISE = 'espace_client_entreprise';
const clePanier = (slug: string, compte: number) => `espace_client_panier_${slug}_${compte}`;

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

export function lireSession(): SessionPortail | null {
  const session = lire<SessionPortail>(CLE_SESSION);
  return session?.token && session.compte && session.slug ? session : null;
}

export const ecrireSession = (session: SessionPortail | null) => ecrire(CLE_SESSION, session);

/** Code de la dernière entreprise ouverte : le client retrouve sa page de connexion sans ressaisir le code. */
export function lireDerniereEntreprise(): string | null {
  const code = lire<string>(CLE_ENTREPRISE);
  return typeof code === 'string' && code !== '' ? code : null;
}

export const ecrireDerniereEntreprise = (code: string | null) => ecrire(CLE_ENTREPRISE, code);

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
  /** Document à télécharger (PDF), ou image. */
  fichier?: boolean;
  /** Type de fichier attendu (par défaut un PDF). */
  accepte?: string;
}

const attendre = (essai: number) => new Promise(resolve => setTimeout(resolve, Math.min(1000 * 2 ** (essai - 1), 4000)));

/** Langue des messages de l'API : celle choisie dans l'espace client (clé du LanguageProvider), sinon celle du navigateur. */
function langueEcran(): string {
  try {
    const choisie = localStorage.getItem('espace_client_langue');
    if (choisie === 'fr' || choisie === 'en') return choisie;
  } catch {
    // Stockage indisponible (navigation privée) : langue du navigateur.
  }
  return navigator.language.toLowerCase().startsWith('en') ? 'en' : 'fr';
}

/**
 * Requête vers l'API. Lève ApiError (réponse non 2xx, avec son corps) ou NetworkError (pas de réponse). Les
 * lectures sont retentées sur coupure réseau ou erreur serveur ; jamais une commande (pas de doublon).
 */
export async function appelPortail<T>(chemin: string, { methode = 'GET', corps, jeton, fichier = false, accepte = 'application/pdf' }: OptionsAppel = {}): Promise<T> {
  const entetes: Record<string, string> = { Accept: fichier ? accepte : 'application/json', 'Accept-Language': langueEcran() };
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
