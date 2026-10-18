/**
 * Nom affiché de l'application. Le nom actuel est provisoire : il se règle par VITE_APP_NAME au
 * build (valeur par défaut dans vite.config.ts), sans toucher au code.
 */
export const NOM_APPLICATION: string = import.meta.env.VITE_APP_NAME;

/** Version construite (commit) et date de construction, affichées dans l'aide (« À propos »). */
export const VERSION_APPLICATION: string = import.meta.env.VITE_VERSION_APPLICATION ?? '';
export const DATE_CONSTRUCTION: string = import.meta.env.VITE_DATE_CONSTRUCTION ?? '';
