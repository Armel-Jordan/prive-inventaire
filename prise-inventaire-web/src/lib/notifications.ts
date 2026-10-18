/** Notification de l'entreprise, telle que renvoyée par GET /notifications. */
export interface NotificationApp {
  id: number;
  type: string;
  titre: string;
  message: string;
  lu: boolean;
  lien: string | null;
  created_at: string;
}

/** Pastille de couleur par type de notification (gris pour un type inconnu). */
export const couleursTypeNotification: Record<string, string> = {
  transfert_complete: 'bg-green-500',
  approbation_requise: 'bg-orange-500',
  alerte_stock: 'bg-red-500',
  planification_imminente: 'bg-blue-500',
  // Réponse d'un client à un devis, donnée en ligne.
  devis_accepte: 'bg-green-500',
  devis_refuse: 'bg-red-500',
  // Révision de prix appliquée à sa date par la tâche planifiée.
  revision_prix: 'bg-purple-500',
};
