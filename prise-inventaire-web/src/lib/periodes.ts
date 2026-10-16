/**
 * Périodes de dates choisies par l'utilisateur, transmises à l'API en instants : un jour « 15/10 » commence à
 * minuit dans le fuseau du navigateur, et non à minuit UTC (les filtres « du 15 au 15 » manquaient les scans du
 * soir à l'ouest de Greenwich et prenaient ceux de la veille à l'est).
 */

/** Instant du début d'un jour local (« 2026-10-15 »), éventuellement décalé de quelques jours. */
function debutDuJour(date: string, decalageJours = 0): string {
  const [annee, mois, jour] = date.split('-').map(Number);
  return new Date(annee, mois - 1, jour + decalageJours).toISOString();
}

/** Bornes d'une période de dates locales : du début du premier jour au début du lendemain du dernier. */
export function bornesPeriode(debut?: string, fin?: string): { depuis?: string; jusqu_a?: string } {
  return {
    depuis: debut ? debutDuJour(debut) : undefined,
    jusqu_a: fin ? debutDuJour(fin, 1) : undefined,
  };
}

/** Bornes des N derniers jours, aujourd'hui compris. */
export function derniersJours(nombre: number): { depuis: string; jusqu_a: string } {
  const aujourdhui = dateLocale(new Date().toISOString());
  return { depuis: debutDuJour(aujourdhui, 1 - nombre), jusqu_a: debutDuJour(aujourdhui, 1) };
}

/** Date locale (AAAA-MM-JJ) d'un instant ISO. */
export function dateLocale(instant: string): string {
  const d = new Date(instant);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** Jour local (« 2026-10-15 ») en date JavaScript, sans passer par minuit UTC qui reculait d'un jour. */
export function jourLocal(date: string): Date {
  const [annee, mois, jour] = date.slice(0, 10).split('-').map(Number);
  return new Date(annee, mois - 1, jour);
}

/** Valeur d'un champ datetime-local (« 2026-10-20T09:00 ») pour un instant, dans le fuseau du navigateur. */
export function versChampDateHeure(instant: Date | string): string {
  const d = new Date(instant);
  const deux = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${deux(d.getMonth() + 1)}-${deux(d.getDate())}T${deux(d.getHours())}:${deux(d.getMinutes())}`;
}

/** Instant ISO d'une saisie datetime-local, lue comme une heure locale (et non comme une heure UTC). */
export function depuisChampDateHeure(valeur: string): string {
  return new Date(valeur).toISOString();
}

/** Fuseau du navigateur (« America/Toronto »), pour que l'API regroupe par jour local. */
export function fuseauNavigateur(): string {
  return Intl.DateTimeFormat().resolvedOptions().timeZone;
}
