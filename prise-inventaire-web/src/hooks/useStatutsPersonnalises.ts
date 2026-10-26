import { useEffect, useState } from 'react';
import { getReferentiel, type DocumentStatut, type StatutPersonnaliseDocument } from '@/services/api';
import { getAuthHeaders } from '@/services/http';

/** Statut du référentiel, tel que proposé sur les documents. */
export interface StatutChoix extends StatutPersonnaliseDocument {
  document: string;
}

/** Une liste chargée sert à tous les écrans pendant une minute (une seule requête), pour la même connexion. */
const DUREE_CACHE_MS = 60_000;
let cache: { connexion: string; expire: number; liste: Promise<StatutChoix[]> } | null = null;

function charger(): Promise<StatutChoix[]> {
  const connexion = getAuthHeaders().Authorization ?? '';
  if (!cache || cache.connexion !== connexion || cache.expire < Date.now()) {
    const liste = getReferentiel('statuts_personnalises').then(fiches => fiches.map(f => ({
      id: f.id,
      libelle: String(f.libelle ?? ''),
      couleur: f.couleur ? String(f.couleur) : null,
      couleur_texte: f.couleur_texte ? String(f.couleur_texte) : null,
      actif: Boolean(f.actif),
      document: String(f.document ?? ''),
    })));
    const entree = { connexion, expire: Date.now() + DUREE_CACHE_MS, liste };
    cache = entree;
    liste.catch(() => { if (cache === entree) cache = null; });
  }
  return cache.liste;
}

/**
 * Statuts personnalisés d'un type de document (phase 1.11c) : les siens et ceux « autre » (tous les documents), actifs
 * et désactivés (un document garde le sien, qui reste affiché).
 */
export function useStatutsPersonnalises(document: DocumentStatut): StatutChoix[] {
  const [statuts, setStatuts] = useState<StatutChoix[]>([]);

  useEffect(() => {
    let actif = true;
    charger().then(liste => { if (actif) setStatuts(liste.filter(s => s.document === document || s.document === 'autre')); }, () => {});
    return () => { actif = false; };
  }, [document]);

  return statuts;
}
