import { createContext, useContext, useEffect, useState } from 'react';
import type { ArticlePanier, InfosPortail, MoiPortail, OptionsAppel, SessionPortail } from './api';

/** État partagé par les écrans du portail d'une entreprise : session, client connecté, panier. */
export interface EtatPortail {
  slug: string;
  /** Adresse de base des écrans : /portail/<code de l'entreprise>. */
  base: string;
  infos: InfosPortail;
  session: SessionPortail | null;
  moi: MoiPortail | null;
  panier: ArticlePanier[];
  /** Message à montrer sur l'écran de connexion (session expirée…). */
  avis: string;
  connecter: (session: SessionPortail) => void;
  deconnecter: () => Promise<void>;
  /** Requête de l'espace client, avec la session ; une session expirée renvoie à la connexion. */
  appel: <T>(chemin: string, options?: Omit<OptionsAppel, 'jeton'>) => Promise<T>;
  /** Le compte n'a pas pu être chargé (réseau) : l'écran propose de réessayer. */
  erreurMoi: boolean;
  rechargerMoi: () => void;
  /** Ajoute la quantité (cumulée si l'article est déjà au panier). */
  ajouterAuPanier: (article: Omit<ArticlePanier, 'quantite'>, quantite: number) => void;
  changerQuantite: (produitId: number, quantite: number) => void;
  retirerDuPanier: (produitId: number) => void;
  viderPanier: () => void;
}

export const ContextePortail = createContext<EtatPortail | null>(null);

export function usePortail(): EtatPortail {
  const contexte = useContext(ContextePortail);
  if (!contexte) throw new Error('usePortail : hors du portail client');
  return contexte;
}

/**
 * Données d'un écran : rechargées quand la fonction de chargement change (la mémoriser avec useCallback).
 * Les données précédentes restent affichées pendant le chargement suivant (changement de page).
 */
export function useDonnees<T>(charger: () => Promise<T>) {
  const [version, setVersion] = useState(0);
  const [resultat, setResultat] = useState<{ source: () => Promise<T>; version: number; donnees?: T; erreur?: unknown } | null>(null);

  useEffect(() => {
    let actif = true;
    charger().then(
      donnees => { if (actif) setResultat({ source: charger, version, donnees }); },
      erreur => { if (actif) setResultat(r => ({ source: charger, version, donnees: r?.donnees, erreur })); },
    );
    return () => { actif = false; };
  }, [charger, version]);

  return {
    donnees: resultat?.donnees,
    erreur: resultat?.source === charger ? resultat.erreur : undefined,
    chargement: !resultat || resultat.source !== charger || resultat.version !== version,
    recharger: () => setVersion(v => v + 1),
  };
}
