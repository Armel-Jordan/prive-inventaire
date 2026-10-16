import { ApiError } from '@/services/http';

/**
 * Message à montrer après un échec d'appel : la règle métier ou l'erreur de validation renvoyée par l'API
 * (« encours dépassé », « montant supérieur au reste dû »…), sinon le message prévu par l'écran.
 */
export function messageErreur(e: unknown, defaut: string): string {
  if (e instanceof ApiError) {
    const data = e.data as { message?: string; errors?: Record<string, string[]> } | null;
    const premiereErreur = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
    return premiereErreur || data?.message || defaut;
  }
  return defaut;
}

/** Affiche le message d'échec (les écrans de documents se contentaient d'un console.error invisible). */
export function signaler(e: unknown, defaut: string): void {
  alert(messageErreur(e, defaut));
}
