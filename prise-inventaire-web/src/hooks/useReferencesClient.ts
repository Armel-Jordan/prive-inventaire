import { useEffect, useState } from 'react';
import { getReferencesProduitsClient } from '@/services/api';

const AUCUNE = new Map<number, string>();

/**
 * Références des produits chez le client choisi (phase 1.2c) : id du produit => référence du client. Montrées dans la
 * saisie des devis, commandes et factures (le PDF les reprend aussi).
 */
export function useReferencesClient(clientId: number | null): Map<number, string> {
  const [charge, setCharge] = useState<{ client: number; references: Map<number, string> } | null>(null);

  useEffect(() => {
    if (!clientId) return;
    let actif = true;
    getReferencesProduitsClient(clientId).then(
      liste => { if (actif) setCharge({ client: clientId, references: new Map(liste.map(r => [r.produit_id, r.reference])) }); },
      () => {},
    );
    return () => { actif = false; };
  }, [clientId]);

  return charge && charge.client === clientId ? charge.references : AUCUNE;
}
