import { suitLeModele } from '@/navigation';
import type { AideEcran, AidesEcrans } from './types';

export type { AideEcran, AidesEcrans } from './types';

let chargement: Promise<AidesEcrans> | null = null;

/**
 * Aide de tous les écrans (phase 6.6), chargée à la première demande : panneau d'aide, carte des écrans, recherche.
 * Le texte reste ainsi hors du code chargé à l'ouverture de l'application.
 */
export function chargerAides(): Promise<AidesEcrans> {
  chargement ??= import('./contenu').then(
    module => module.AIDES,
    erreur => {
      // Un échec (réseau) laisse retenter à la demande suivante.
      chargement = null;
      throw erreur;
    },
  );
  return chargement;
}

/** Clé de l'aide d'une adresse : la même, sinon le modèle qui la décrit (« /clients/12 » relève de « /clients/:id »). */
export function cleAide(aides: AidesEcrans, chemin: string): string | null {
  if (aides[chemin]) return chemin;
  return Object.keys(aides).find(cle => cle.includes(':') && suitLeModele(cle, chemin)) ?? null;
}

/** Aide d'une adresse dans la langue de l'écran. */
export function aidePour(aides: AidesEcrans, chemin: string, langue: string): AideEcran | null {
  const cle = cleAide(aides, chemin);
  return cle ? aides[cle][langue === 'en' ? 'en' : 'fr'] : null;
}

/** Texte sans accents ni majuscules, pour la recherche. */
export function normaliser(texte: string): string {
  return texte.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
}

/** Mots d'un texte, sans accents ni majuscules (« l'aide » donne « l » et « aide »). */
function mots(texte: string): string[] {
  return normaliser(texte).split(/[^\p{L}\p{N}]+/u).filter(Boolean);
}

/** Chaque mot cherché commence-t-il un mot du texte ? */
function couvre(cherches: string[], texte: string): boolean {
  const disponibles = mots(texte);
  return cherches.every(cherche => disponibles.some(mot => mot.startsWith(cherche)));
}

/**
 * Pertinence d'un écran pour une recherche : 2 quand son titre répond, 1 quand son aide (résumé, mots-clés) répond,
 * 0 sinon. Chaque mot cherché doit commencer un mot : « caisse » trouve « caisses », pas « décaissement ».
 */
export function pertinence(recherche: string, titre: string, aide: AideEcran | null): number {
  const cherches = mots(recherche);
  if (cherches.length === 0) return 1;
  if (couvre(cherches, titre)) return 2;
  return couvre(cherches, [titre, aide?.resume ?? '', ...(aide?.motsCles ?? [])].join(' ')) ? 1 : 0;
}

/** Un écran répond-il à la recherche (tous les mots, dans son titre, son résumé ou ses mots-clés) ? */
export function correspond(recherche: string, titre: string, aide: AideEcran | null): boolean {
  return pertinence(recherche, titre, aide) > 0;
}
