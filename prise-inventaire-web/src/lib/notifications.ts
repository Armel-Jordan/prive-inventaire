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
};
