import { useEffect, useState } from 'react';
import { getStatutsDocuments, type StatutsParDocument } from '@/services/statutsDocuments';
import { getAuthHeaders } from '@/services/http';

/** Une liste chargée sert à tous les écrans pendant une minute (une seule requête), pour la même connexion. */
const DUREE_CACHE_MS = 60_000;
let cache: { connexion: string; expire: number; liste: Promise<StatutsParDocument> } | null = null;
const abonnes = new Set<(liste: StatutsParDocument) => void>();

function charger(): Promise<StatutsParDocument> {
  const connexion = getAuthHeaders().Authorization ?? '';
  if (!cache || cache.connexion !== connexion || cache.expire < Date.now()) {
    const liste = getStatutsDocuments();
    const entree = { connexion, expire: Date.now() + DUREE_CACHE_MS, liste };
    cache = entree;
    liste.catch(() => { if (cache === entree) cache = null; });
  }
  return cache.liste;
}

/** Réglages enregistrés : les écrans ouverts reprennent la nouvelle liste. */
export function statutsDocumentsModifies(liste: StatutsParDocument): void {
  cache = { connexion: getAuthHeaders().Authorization ?? '', expire: Date.now() + DUREE_CACHE_MS, liste: Promise.resolve(liste) };
  abonnes.forEach(abonne => abonne(liste));
}

/** Statuts des documents de l'entreprise (libellés et couleurs) ; null tant qu'ils ne sont pas chargés. */
export function useStatutsDocuments(): StatutsParDocument | null {
  const [liste, setListe] = useState<StatutsParDocument | null>(null);

  useEffect(() => {
    let actif = true;
    const abonne = (nouvelle: StatutsParDocument) => { if (actif) setListe(nouvelle); };
    abonnes.add(abonne);
    charger().then(abonne, () => {});
    return () => { actif = false; abonnes.delete(abonne); };
  }, []);

  return liste;
}
