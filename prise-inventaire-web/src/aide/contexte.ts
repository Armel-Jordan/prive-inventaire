import { createContext, useContext } from 'react';

/** Panneau d'aide (phase 6.6) : ouvert sur l'écran affiché, ou sur un autre écran depuis la carte des écrans. */
export interface ContexteAide {
  /** Adresse dont l'aide est affichée, ou null quand le panneau est fermé. */
  chemin: string | null;
  /** Ouvre l'aide d'une adresse (par défaut, celle de l'écran affiché). */
  ouvrir: (chemin?: string) => void;
  fermer: () => void;
}

export const AideContext = createContext<ContexteAide>({ chemin: null, ouvrir: () => {}, fermer: () => {} });

export function useAide(): ContexteAide {
  return useContext(AideContext);
}
