import { useEffect, useState } from 'react';
import { getReferentiel } from '@/services/api';
import { getAuthHeaders } from '@/services/http';

/** Représentant du référentiel, tel que proposé dans les fiches et les documents de vente. */
export interface Representant {
  id: number;
  nom: string;
  taux_commission: number | null;
  actif: boolean;
}

/** Une liste chargée sert à tous les champs d'un écran pendant une minute (une seule requête), pour la même connexion. */
const DUREE_CACHE_MS = 60_000;
let cache: { connexion: string; expire: number; liste: Promise<Representant[]> } | null = null;

function charger(): Promise<Representant[]> {
  const connexion = getAuthHeaders().Authorization ?? '';
  if (!cache || cache.connexion !== connexion || cache.expire < Date.now()) {
    const liste = getReferentiel('representants').then(fiches => fiches.map(f => ({
      id: f.id,
      nom: String(f.nom ?? ''),
      taux_commission: f.taux_commission !== null && f.taux_commission !== undefined ? Number(f.taux_commission) : null,
      actif: Boolean(f.actif),
    })));
    const entree = { connexion, expire: Date.now() + DUREE_CACHE_MS, liste };
    cache = entree;
    // Un échec n'est pas gardé : le prochain écran redemande la liste.
    liste.catch(() => { if (cache === entree) cache = null; });
  }
  return cache.liste;
}

/**
 * Représentants commerciaux (phase 1.11a), actifs et désactivés : un client ou un document garde le sien même désactivé,
 * et son nom s'affiche ; les listes de choix ne proposent que les actifs (voir ChoixRepresentant).
 */
export function useRepresentants(): Representant[] {
  const [representants, setRepresentants] = useState<Representant[]>([]);

  useEffect(() => {
    let actif = true;
    charger().then(liste => { if (actif) setRepresentants(liste); }, () => {});
    return () => { actif = false; };
  }, []);

  return representants;
}
