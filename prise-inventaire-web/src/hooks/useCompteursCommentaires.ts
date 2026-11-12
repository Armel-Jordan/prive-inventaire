import { useCallback, useEffect, useState } from 'react';
import { getCompteursCommentaires, type CompteurCommentaires, type EntiteHistorique } from '@/services/api';

/** Au plus ce nombre de lignes par demande (une page de liste). */
const LIGNES_MAX = 200;

/**
 * Nombre de commentaires et de non-lus des lignes d'une liste (socle S4.6), en une seule demande ; `rafraichir` après
 * la lecture ou l'ajout d'un commentaire.
 */
export function useCompteursCommentaires(entite: EntiteHistorique, ids: number[]) {
  const cle = ids.slice(0, LIGNES_MAX).join(',');
  const [compteurs, setCompteurs] = useState<Record<number, CompteurCommentaires>>({});
  const [version, setVersion] = useState(0);

  useEffect(() => {
    if (!cle) return;
    let actif = true;
    getCompteursCommentaires(entite, cle).then(c => { if (actif) setCompteurs(c); }, () => {});
    return () => { actif = false; };
  }, [entite, cle, version]);

  const rafraichir = useCallback(() => setVersion(v => v + 1), []);

  return { compteurs: cle ? compteurs : {}, rafraichir };
}
