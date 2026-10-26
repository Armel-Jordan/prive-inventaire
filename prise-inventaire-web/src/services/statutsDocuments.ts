import { apiFetch } from '@/services/http';

/** Types de document dont les statuts système se règlent (libellés et couleurs). */
export type DocumentAStatuts = 'devis' | 'commande_client' | 'facture' | 'bon_livraison' | 'commande_fournisseur';

/** Statut système d'un type de document, avec les libellés et couleurs de l'entreprise. */
export interface StatutDocument {
  code: string;
  libelle_fr: string;
  libelle_en: string;
  couleur_fond: string;
  couleur_texte: string;
  /** Vrai si l'entreprise a changé ce statut. */
  regle: boolean;
  defaut: { libelle_fr: string; libelle_en: string; couleur_fond: string; couleur_texte: string };
}

export type StatutsParDocument = Record<DocumentAStatuts, StatutDocument[]>;

export interface ReglageStatut {
  libelle_fr: string | null;
  libelle_en: string | null;
  couleur_fond: string | null;
  couleur_texte: string | null;
}

export function getStatutsDocuments(): Promise<StatutsParDocument> {
  return apiFetch('/statuts-documents');
}

export function reglerStatutDocument(document: DocumentAStatuts, code: string, reglage: ReglageStatut): Promise<StatutDocument[]> {
  return apiFetch(`/statuts-documents/${document}/${code}`, { method: 'PUT', body: JSON.stringify(reglage), retries: 0 });
}

/** Retour aux libellés et couleurs par défaut. */
export function statutDocumentParDefaut(document: DocumentAStatuts, code: string): Promise<StatutDocument[]> {
  return apiFetch(`/statuts-documents/${document}/${code}`, { method: 'DELETE', retries: 0 });
}
