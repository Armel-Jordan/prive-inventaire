import { apiFetch } from '@/services/http';
import { getEnvoisCourriel, type EnvoiCourriel, type LangueCourriel, type PreparationCourriel, type StatutCourriel } from '@/services/api';
import type { CourrielSaisi } from '@/components/ComposerCourriel';

/**
 * Bibliothèque des rapports : catalogue (selon les droits du compte), exécution, fichiers CSV, Excel et PDF, envoi par
 * courriel et envois programmés.
 */

export type TypeParametre = 'date' | 'entier' | 'choix' | 'client' | 'fournisseur' | 'categorie' | 'representant';
export type TypeColonne = 'texte' | 'date' | 'entier' | 'quantite' | 'montant' | 'pourcentage';
export type FormatRapport = 'csv' | 'xlsx' | 'pdf';

export const FORMATS_RAPPORT: FormatRapport[] = ['pdf', 'xlsx', 'csv'];

export interface ParametreRapport {
  cle: string;
  type: TypeParametre;
  libelle: string;
  defaut?: string | number;
  min?: number;
  max?: number;
  options?: { valeur: string; libelle: string }[];
}

export interface DefinitionRapport {
  code: string;
  categorie: string;
  titre: string;
  description: string;
  parametres: ParametreRapport[];
}

export interface CatalogueRapports {
  categories: { code: string; rapports: DefinitionRapport[] }[];
  /** Catégories de produits, pour les rapports filtrés par catégorie. */
  categories_produits: string[];
}

export interface ResultatRapport {
  rapport: DefinitionRapport;
  valeurs: Record<string, string | number | null>;
  colonnes: { cle: string; libelle: string; type: TypeColonne; total: boolean }[];
  lignes: Record<string, string | number | null>[];
  totaux: Record<string, number>;
  genere_le: string;
}

/** Fuseau de l'utilisateur : heure d'établissement des PDF. */
const fuseau = () => Intl.DateTimeFormat().resolvedOptions().timeZone;

/** Paramètres renseignés seulement (un champ vidé reprend la valeur par défaut). */
const renseignes = (parametres: Record<string, string>) => Object.fromEntries(Object.entries(parametres).filter(([, valeur]) => valeur !== ''));

const requete = (parametres: Record<string, string>, langue: string, format?: FormatRapport) => {
  const p = new URLSearchParams({ langue, ...renseignes(parametres) });
  if (format) {
    p.set('format', format);
    // Heure d'établissement du PDF dans le fuseau de l'utilisateur.
    p.set('fuseau', fuseau());
  }
  return p.toString();
};

export function getCatalogueRapports(langue: string): Promise<CatalogueRapports> {
  return apiFetch(`/rapports/bibliotheque?langue=${langue}`);
}

export function executerRapport(code: string, parametres: Record<string, string>, langue: string): Promise<ResultatRapport> {
  return apiFetch(`/rapports/bibliotheque/${code}?${requete(parametres, langue)}`, { timeoutMs: 120000 });
}

/** Le rapport en fichier, téléchargé avec l'en-tête d'authentification. */
export async function telechargerRapport(code: string, parametres: Record<string, string>, langue: string, format: FormatRapport): Promise<void> {
  const fichier = await apiFetch<Blob>(`/rapports/bibliotheque/${code}?${requete(parametres, langue, format)}`, { responseType: 'blob', timeoutMs: 120000 });
  const lien = document.createElement('a');
  lien.href = URL.createObjectURL(fichier);
  lien.download = `${code}-${new Date().toISOString().slice(0, 10)}.${format}`;
  lien.click();
  setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
}

/** Pré-remplissage du courriel d'un rapport (modèle « rapport » de l'entreprise). */
export function preparerCourrielRapport(code: string, parametres: Record<string, string>, langue: LangueCourriel): Promise<PreparationCourriel> {
  const p = new URLSearchParams({ langue });
  Object.entries(renseignes(parametres)).forEach(([cle, valeur]) => p.set(`parametres[${cle}]`, valeur));
  return apiFetch(`/rapports/bibliotheque/${code}/courriel?${p}`);
}

/** Envoie le rapport par courriel, dans le format choisi. */
export function envoyerCourrielRapport(code: string, courriel: CourrielSaisi & { parametres: Record<string, string>; format: FormatRapport }): Promise<EnvoiCourriel> {
  return apiFetch(`/rapports/bibliotheque/${code}/courriel`, {
    method: 'POST',
    body: JSON.stringify({ ...courriel, parametres: renseignes(courriel.parametres), fuseau: fuseau() }),
    timeoutMs: 120000,
  });
}

/** Envois précédents d'un rapport (historique des courriels). */
export function getEnvoisRapport(code: string): Promise<EnvoiCourriel[]> {
  return getEnvoisCourriel({ type: 'rapport', rapport: code }).then(page => page.data);
}

export type FrequenceProgramme = 'quotidienne' | 'hebdomadaire' | 'mensuelle';

/** Envoi programmé d'un rapport : chaque jour, chaque semaine (jour 1 = lundi … 7) ou chaque mois (jour 1 à 31). */
export interface ProgrammeRapport {
  id: number;
  code: string;
  nom: string;
  parametres: Record<string, string | number> | null;
  /** Période relative (veille, mois précédent…), pour un rapport qui a une période. */
  periode: string | null;
  format: FormatRapport;
  langue: LangueCourriel;
  destinataires: string[];
  frequence: FrequenceProgramme;
  jour: number | null;
  actif: boolean;
  derniere_erreur: string | null;
  /** Titre du rapport. */
  rapport: string;
  /** Compte dont les droits s'appliquent (le dernier à avoir réglé l'envoi). */
  compte: string | null;
  prochain_envoi: string | null;
  dernier_envoi_le: string | null;
  dernier_envoi: { id: number; statut: StatutCourriel; erreur: string | null } | null;
  /** Question à l'IA : son analyse du rapport entre dans chaque courriel. */
  question_ia: string | null;
}

export type ProgrammeSaisi = Pick<ProgrammeRapport, 'code' | 'nom' | 'periode' | 'format' | 'langue' | 'destinataires' | 'frequence' | 'jour' | 'actif' | 'question_ia'>
  & { parametres: Record<string, string> };

export interface ListeProgrammes {
  programmes: ProgrammeRapport[];
  periodes: { code: string; libelle: string }[];
  /** La tâche planifiée qui fait partir les envois est-elle active ? */
  tache_active: boolean;
  /** Le compte peut-il ajouter une question à l'IA à un envoi (service, module de l'entreprise, droit) ? */
  ia_disponible: boolean;
}

export function getProgrammesRapports(langue: string): Promise<ListeProgrammes> {
  return apiFetch(`/rapports/programmes?langue=${langue}`);
}

export function enregistrerProgrammeRapport(id: number | null, programme: ProgrammeSaisi, langue: string): Promise<ProgrammeRapport> {
  return apiFetch(id ? `/rapports/programmes/${id}?langue=${langue}` : `/rapports/programmes?langue=${langue}`, {
    method: id ? 'PUT' : 'POST',
    body: JSON.stringify({ ...programme, parametres: renseignes(programme.parametres) }),
  });
}

export function supprimerProgrammeRapport(id: number): Promise<void> {
  return apiFetch(`/rapports/programmes/${id}`, { method: 'DELETE', responseType: 'void' });
}

/** Envoie tout de suite (la date du prochain envoi ne change pas). */
export function envoyerProgrammeRapport(id: number, langue: string): Promise<{ envoi: EnvoiCourriel; programme: ProgrammeRapport }> {
  return apiFetch(`/rapports/programmes/${id}/envoyer?langue=${langue}`, { method: 'POST', timeoutMs: 120000 });
}

/** Rapport cité par une réponse de l'IA ; « permis » à faux si le compte ne peut plus l'ouvrir. */
export interface RapportCite {
  code: string;
  titre: string;
  parametres: Record<string, string | number | null>;
  permis: boolean;
}

/** Question posée à l'IA sur les rapports, avec sa réponse. */
export interface QuestionIa {
  id: number;
  question: string;
  langue: 'fr' | 'en';
  statut: 'en_cours' | 'reussie' | 'echec';
  titre: string | null;
  reponse: string | null;
  erreur: string | null;
  modele: string | null;
  jetons_entree: number;
  jetons_sortie: number;
  duree_ms: number | null;
  /** Rapport à ouvrir pour le détail. */
  rapport: RapportCite | null;
  /** Rapports exécutés pour répondre. */
  rapports: RapportCite[];
  created_at: string | null;
}

export interface EtatQuestionsIa {
  /** null quand l'IA est disponible ; « cle » : serveur non configuré ; « module » : module de l'entreprise inactif. */
  indisponible: 'cle' | 'module' | null;
  restantes: number;
  questions: QuestionIa[];
}

export function getQuestionsIa(langue: string): Promise<EtatQuestionsIa> {
  return apiFetch(`/rapports/ia/questions?langue=${langue}`);
}

/** Pose une question ; l'IA peut exécuter plusieurs rapports avant de répondre (délai long). */
export function poserQuestionIa(question: string, langue: string): Promise<QuestionIa> {
  return apiFetch(`/rapports/ia/questions?langue=${langue}`, { method: 'POST', body: JSON.stringify({ question, langue }), timeoutMs: 300000 });
}
