import { useMemo, useSyncExternalStore } from 'react';

/**
 * Caisse du poste de travail, comme la configuration de démarrage d'un poste de caisse dans fripv2 : les paiements,
 * acomptes et remboursements saisis sur ce navigateur entrent dans la session ouverte de cette caisse. Réglage propre
 * au navigateur et à l'entreprise connectée.
 */
export interface CaissePoste {
  id: number;
  code: string;
  nom: string;
}

const CLE = 'prise_caisse_poste';
const EVENEMENT = 'caisse-poste';

/** Clé du réglage pour l'entreprise connectée. */
function cle(): string {
  try {
    return `${CLE}:${JSON.parse(localStorage.getItem('prise_auth') ?? '{}')?.tenant?.slug ?? ''}`;
  } catch {
    return CLE;
  }
}

function lireBrut(): string | null {
  try {
    return localStorage.getItem(cle());
  } catch {
    return null;
  }
}

function abonner(rappel: () => void): () => void {
  window.addEventListener(EVENEMENT, rappel);
  window.addEventListener('storage', rappel);
  return () => {
    window.removeEventListener(EVENEMENT, rappel);
    window.removeEventListener('storage', rappel);
  };
}

function analyser(brut: string | null): CaissePoste | null {
  if (!brut) return null;
  try {
    const valeur = JSON.parse(brut);
    return valeur && typeof valeur.id === 'number' ? { id: valeur.id, code: String(valeur.code ?? ''), nom: String(valeur.nom ?? '') } : null;
  } catch {
    return null;
  }
}

export function getCaissePoste(): CaissePoste | null {
  return analyser(lireBrut());
}

/** Fait de ce poste une caisse (ou plus une caisse, avec null). */
export function setCaissePoste(caisse: CaissePoste | null): void {
  try {
    if (caisse) localStorage.setItem(cle(), JSON.stringify({ id: caisse.id, code: caisse.code, nom: caisse.nom }));
    else localStorage.removeItem(cle());
  } catch {
    // Stockage indisponible : le poste n'est simplement pas une caisse.
  }
  window.dispatchEvent(new Event(EVENEMENT));
}

/** Caisse de ce poste, mise à jour quand le réglage change (ici ou dans un autre onglet). */
export function useCaissePoste(): CaissePoste | null {
  const brut = useSyncExternalStore(abonner, lireBrut, () => null);
  return useMemo(() => analyser(brut), [brut]);
}
