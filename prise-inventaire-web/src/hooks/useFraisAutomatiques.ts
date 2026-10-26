import { useEffect, useState } from 'react';
import { calculerFrais, type DocumentFrais, type FraisPropose } from '@/services/frais';

export interface LigneDocument { produit_id: number; quantite: number; prix_unitaire_ht: number; remise_ligne?: number }

export interface FraisAutomatiques {
  /** Lignes de frais proposées (sans celles que l'utilisateur a retirées). */
  lignes: FraisPropose[];
  /** Total HT des frais proposés. */
  total: number;
  /** Retire un frais du document en cours (il n'est plus proposé jusqu'à la remise à zéro). */
  retirer: (fraisId: number) => void;
  /** Nouveau document : les frais retirés sont de nouveau proposés. */
  reinitialiser: () => void;
}

/**
 * Frais additionnels et de livraison proposés pendant la saisie d'un devis, d'une commande ou d'une facture : recalculés
 * par l'API quand le client, le transporteur ou les lignes changent. Les lignes de frais s'ajoutent à celles du document à
 * l'enregistrement ; l'utilisateur peut en retirer.
 */
export function useFraisAutomatiques({ document, clientId, transporteurId = null, lignes, taux = 1, actif = true }: {
  document: DocumentFrais;
  clientId: number | null;
  /** Transporteur du document (phase 1.11b) : sans livraison facturée, pas de frais de livraison. */
  transporteurId?: number | null;
  lignes: LigneDocument[];
  /** Valeur d'une unité de la devise du document dans celle de l'entreprise. */
  taux?: number;
  actif?: boolean;
}): FraisAutomatiques {
  const [proposes, setProposes] = useState<FraisPropose[]>([]);
  const [retires, setRetires] = useState<number[]>([]);
  // Clé stable : le calcul ne se relance que si le client, le transporteur, la devise ou les lignes changent vraiment.
  const cle = JSON.stringify([document, clientId, transporteurId, taux, lignes.filter(l => l.produit_id).map(l => [l.produit_id, l.quantite, l.prix_unitaire_ht, l.remise_ligne ?? 0])]);

  useEffect(() => {
    const [doc, client, transporteur, tauxDocument, saisies] = JSON.parse(cle) as [DocumentFrais, number | null, number | null, number, number[][]];
    if (!actif || saisies.length === 0) return;
    let vivant = true;
    const minuterie = setTimeout(() => {
      calculerFrais({
        document: doc,
        client_id: client,
        transporteur_id: transporteur,
        taux_change: tauxDocument > 0 ? tauxDocument : 1,
        lignes: saisies.map(([produit_id, quantite, prix_unitaire_ht, remise_ligne]) => ({ produit_id, quantite, prix_unitaire_ht, remise_ligne })),
      }).then(r => { if (vivant) setProposes(r); }, () => { if (vivant) setProposes([]); });
    }, 300);
    return () => { vivant = false; clearTimeout(minuterie); };
  }, [cle, actif]);

  // Formulaire fermé ou sans ligne de produit : rien de proposé (les propositions précédentes sont ignorées).
  const sansLigne = !actif || !lignes.some(l => l.produit_id);
  const gardes = sansLigne ? [] : proposes.filter(p => !retires.includes(p.frais_id));

  return {
    lignes: gardes,
    total: Math.round(gardes.reduce((somme, l) => somme + l.montant_ht, 0) * 100) / 100,
    retirer: fraisId => setRetires(r => [...r, fraisId]),
    reinitialiser: () => setRetires([]),
  };
}
