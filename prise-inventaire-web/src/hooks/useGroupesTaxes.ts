import { useEffect, useState } from 'react';
import { getGroupesTaxes } from '@/services/api';
import { getAuthHeaders } from '@/services/http';

/** Groupe de taxes, tel que proposé sur la fiche client et les documents de vente. */
export interface GroupeTaxeChoix {
  id: number;
  nom: string;
  actif: boolean;
  /** Taux cumulé du groupe (0 pour un groupe vide, qui exonère). */
  taux_effectif: number;
}

/** Une liste chargée sert à tous les champs d'un écran pendant une minute (une seule requête), pour la même connexion. */
const DUREE_CACHE_MS = 60_000;
let cache: { connexion: string; expire: number; liste: Promise<GroupeTaxeChoix[]> } | null = null;

function charger(): Promise<GroupeTaxeChoix[]> {
  const connexion = getAuthHeaders().Authorization ?? '';
  if (!cache || cache.connexion !== connexion || cache.expire < Date.now()) {
    const liste = getGroupesTaxes().then(groupes => groupes.map(g => ({
      id: g.id,
      nom: g.nom,
      actif: Boolean(g.actif),
      taux_effectif: Number(g.taux_effectif ?? 0),
    })));
    const entree = { connexion, expire: Date.now() + DUREE_CACHE_MS, liste };
    cache = entree;
    // Un échec n'est pas gardé : le prochain écran redemande la liste.
    liste.catch(() => { if (cache === entree) cache = null; });
  }
  return cache.liste;
}

/**
 * Groupes de taxes (socle S4.1), actifs et désactivés : une fiche ou un document garde le sien même désactivé ; les
 * listes de choix ne proposent que les actifs (voir ChoixGroupeTaxe).
 */
export function useGroupesTaxes(): GroupeTaxeChoix[] {
  const [groupes, setGroupes] = useState<GroupeTaxeChoix[]>([]);

  useEffect(() => {
    let actif = true;
    charger().then(liste => { if (actif) setGroupes(liste); }, () => {});
    return () => { actif = false; };
  }, []);

  return groupes;
}

/** Vrai quand l'entreprise a au moins un groupe actif : sinon les champs « Groupe de taxes » restent masqués. */
export function groupesTaxesActifs(groupes: GroupeTaxeChoix[]): boolean {
  return groupes.some(g => g.actif);
}
