/**
 * Adresse de l'API et erreurs typées. En production, le site de l'espace client est servi par le même nginx
 * que l'API : `/api` (même origine, pas de CORS).
 */
export const API_BASE_URL: string = import.meta.env.VITE_API_URL || '/api';

/** Erreur avec réponse HTTP du serveur (4xx/5xx). Porte le statut et le corps reçu. */
export class ApiError extends Error {
  readonly status: number;
  readonly data: unknown;

  constructor(status: number, message: string, data?: unknown) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.data = data;
  }
}

/** Erreur sans réponse : coupure réseau, délai dépassé, serveur injoignable. */
export class NetworkError extends Error {
  constructor(message = 'Serveur injoignable. Vérifiez votre connexion.') {
    super(message);
    this.name = 'NetworkError';
  }
}
