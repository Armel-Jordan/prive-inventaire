import { useEffect, useRef } from 'react';
import { calculerPrix, type PrixCalcule } from '@/services/api';
import { clePrix, type LignePrix } from '@/lib/prixDocuments';

/**
 * Prix du client proposés pendant la saisie d'un devis, d'une commande ou d'une facture : liste de prix, remise globale
 * et règles (quantité, période), calculés par l'API quand le client, la date, la devise ou une ligne proposée change.
 * Seules les lignes `auto` sont calculées ; un prix saisi à la main n'est jamais remplacé.
 */
export function usePrixAutomatiques({ clientId, date, taux = 1, lignes, actif = true, appliquer }: {
  clientId: number | null;
  /** Date du document : celle des règles en vigueur. */
  date: string;
  /** Taux de la devise du document : un changement reconvertit les prix proposés. */
  taux?: number;
  lignes: LignePrix[];
  actif?: boolean;
  /** Reçoit les prix calculés, par clé « produit:quantité » (voir `clePrix`). */
  appliquer: (prix: Map<string, PrixCalcule>) => void;
}): void {
  const rappel = useRef(appliquer);
  useEffect(() => {
    rappel.current = appliquer;
  });
  // Clé stable : le calcul ne se relance que si le client, la date, la devise ou une ligne proposée changent vraiment.
  const paires = [...new Set(lignes.filter(l => l.auto && l.produit_id).map(clePrix))].sort();
  const cle = JSON.stringify([clientId, date, taux, paires]);

  useEffect(() => {
    const [client, jour, , demandes] = JSON.parse(cle) as [number | null, string, number, string[]];
    if (!actif || demandes.length === 0) return;
    let vivant = true;
    const minuterie = setTimeout(() => {
      calculerPrix({
        client_id: client ?? undefined,
        date: jour || undefined,
        lignes: demandes.map(paire => {
          const [produit_id, quantite] = paire.split(':').map(Number);
          return { produit_id, quantite };
        }),
      }).then(resultats => {
        if (vivant) rappel.current(new Map(resultats.map(r => [clePrix(r), r])));
      }, () => {});
    }, 250);
    return () => { vivant = false; clearTimeout(minuterie); };
  }, [cle, actif]);
}
