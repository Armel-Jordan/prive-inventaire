/**
 * Client HTTP central — résilience réseau (contexte connectivité instable).
 *
 * - Fusion correcte des headers (l'auth n'est JAMAIS écrasée par des headers custom).
 * - Timeout via AbortController (évite l'attente infinie).
 * - Retry + backoff/jitter UNIQUEMENT sur méthodes idempotentes (GET/HEAD) et
 *   erreurs réseau/timeout/5xx/429. JAMAIS sur POST/PUT/PATCH/DELETE (risque de
 *   doublon : facture/paiement — les clés d'idempotence viendront plus tard).
 *   JAMAIS de retry sur 4xx (erreur métier).
 * - Erreurs typées : NetworkError (pas de réponse/timeout) vs ApiError (statut HTTP).
 * - Bus d'événements réseau (découplé de React) pour afficher un toast global.
 */

export const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

const STORAGE_KEY = 'prise_auth';
const DEFAULT_TIMEOUT_MS = 15000;
const DEFAULT_RETRIES = 3;

/** Headers d'authentification (source unique — plus de copies éparpillées). */
export function getAuthHeaders(): Record<string, string> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  };

  const stored = localStorage.getItem(STORAGE_KEY);
  if (stored) {
    try {
      const data = JSON.parse(stored);
      if (data.token) {
        headers['Authorization'] = `Bearer ${data.token}`;
      }
      if (data.tenant?.slug) {
        headers['X-Tenant-Slug'] = data.tenant.slug;
      }
    } catch {
      // ignore
    }
  }

  return headers;
}

/** Erreur avec réponse HTTP du serveur (4xx/5xx). Porte le statut et le corps parsé. */
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

/** Erreur sans réponse : coupure réseau, timeout, serveur injoignable. */
export class NetworkError extends Error {
  constructor(message = 'Serveur injoignable. Vérifiez votre connexion.') {
    super(message);
    this.name = 'NetworkError';
  }
}

// --- Bus d'événements réseau (le Toaster s'y abonne) -------------------------
type NetErrListener = (message: string) => void;
const netErrListeners = new Set<NetErrListener>();

export function onNetworkError(cb: NetErrListener): () => void {
  netErrListeners.add(cb);
  return () => {
    netErrListeners.delete(cb);
  };
}

function emitNetworkError(message: string): void {
  netErrListeners.forEach((cb) => cb(message));
}

// --- Cœur --------------------------------------------------------------------
const IDEMPOTENT = new Set(['GET', 'HEAD', 'OPTIONS']);
const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

function backoff(attempt: number): number {
  const base = Math.min(1000 * 2 ** (attempt - 1), 8000);
  return base + Math.random() * 250; // jitter
}

async function safeParse(response: Response): Promise<unknown> {
  try {
    const text = await response.text();
    return text ? JSON.parse(text) : null;
  } catch {
    return null;
  }
}

export interface ApiOptions extends RequestInit {
  /** 'json' (défaut), 'blob' (téléchargements), 'void' (pas de corps attendu). */
  responseType?: 'json' | 'blob' | 'void';
  timeoutMs?: number;
  /** Force le nombre de tentatives (sinon : idempotent → 3, sinon → 0). */
  retries?: number;
}

/**
 * Requête résiliente. `endpoint` est relatif à API_BASE_URL (ex: '/factures').
 * Lève ApiError (réponse HTTP non-2xx) ou NetworkError (pas de réponse).
 */
export async function apiFetch<T = unknown>(endpoint: string, options: ApiOptions = {}): Promise<T> {
  const {
    responseType = 'json',
    timeoutMs = DEFAULT_TIMEOUT_MS,
    retries,
    headers,
    signal,
    ...rest
  } = options;

  const method = (rest.method || 'GET').toUpperCase();
  const maxAttempts = 1 + (retries ?? (IDEMPOTENT.has(method) ? DEFAULT_RETRIES : 0));

  const authHeaders = getAuthHeaders();
  // Ne pas forcer le Content-Type JSON si le corps est un FormData.
  if (rest.body instanceof FormData) {
    delete authHeaders['Content-Type'];
  }
  const finalHeaders = { ...authHeaders, ...(headers as Record<string, string> | undefined) };

  let lastApiError: ApiError | null = null;

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);

    try {
      const response = await fetch(`${API_BASE_URL}${endpoint}`, {
        ...rest,
        headers: finalHeaders,
        signal: signal ?? controller.signal,
      });
      clearTimeout(timer);

      if (!response.ok) {
        // Retry seulement 5xx/429, et seulement sur méthode idempotente.
        if (
          (response.status >= 500 || response.status === 429) &&
          IDEMPOTENT.has(method) &&
          attempt < maxAttempts
        ) {
          lastApiError = new ApiError(response.status, `Erreur serveur ${response.status}`);
          await sleep(backoff(attempt));
          continue;
        }

        const data = await safeParse(response);
        const message =
          (data && typeof data === 'object' && data !== null && 'message' in data
            ? String((data as { message: unknown }).message)
            : null) || `Erreur ${response.status}`;
        throw new ApiError(response.status, message, data);
      }

      if (responseType === 'blob') {
        return (await response.blob()) as T;
      }
      if (responseType === 'void' || response.status === 204) {
        return undefined as T;
      }
      const text = await response.text();
      return (text ? JSON.parse(text) : undefined) as T;
    } catch (err) {
      clearTimeout(timer);

      // Erreur métier (réponse reçue) : pas de retry, on propage.
      if (err instanceof ApiError) {
        throw err;
      }

      // Erreur réseau / timeout (AbortError) : retry si idempotent.
      if (IDEMPOTENT.has(method) && attempt < maxAttempts) {
        await sleep(backoff(attempt));
        continue;
      }

      const netMsg = !navigator.onLine
        ? 'Vous êtes hors ligne. Vérifiez votre connexion.'
        : 'Serveur injoignable. Nouvelle tentative recommandée.';
      emitNetworkError(netMsg);
      throw new NetworkError(netMsg);
    }
  }

  // Retries épuisés sur 5xx/429.
  if (lastApiError) {
    emitNetworkError('Le serveur est indisponible. Réessayez dans un instant.');
    throw lastApiError;
  }
  emitNetworkError('Serveur injoignable.');
  throw new NetworkError();
}
