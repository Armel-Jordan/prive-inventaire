/** Numéros de série saisis : séparés par des retours à la ligne, des espaces, des virgules ou des points-virgules. */
export const numerosSaisis = (texte: string) => texte.split(/[\s,;]+/).filter(Boolean);

/**
 * Répartition d'une sortie sur des lots déjà rangés dans l'ordre de sortie (le premier à périmer d'abord), sans
 * dépasser leur stock : quantité par lot. Ce que les lots ne couvrent pas sort sans lot.
 */
export function premiersSortis(lots: { id: number; solde: number }[], unites: number): Record<number, number> {
  const allocations: Record<number, number> = {};
  let reste = unites;
  for (const lot of lots) {
    if (reste <= 0) break;
    const quantite = Math.min(reste, lot.solde);
    if (quantite > 0) {
      allocations[lot.id] = quantite;
      reste -= quantite;
    }
  }
  return allocations;
}
