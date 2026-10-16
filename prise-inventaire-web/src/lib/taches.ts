import type { ReglageTache, TachePlanifiee } from '@/services/api';
import type { TranslationKeys } from '@/i18n/translations';

type TextesTaches = TranslationKeys['scheduledTasks'];

/** Fuseau reconnu par le navigateur, sinon celui du navigateur. */
export function fuseauValide(fuseau: string | undefined): string | undefined {
  try {
    if (fuseau) new Intl.DateTimeFormat('fr-FR', { timeZone: fuseau });
    return fuseau;
  } catch {
    return undefined;
  }
}

/** « AAAA-MM-JJTHH:MM » dans le fuseau des tâches, pour un champ date et heure. */
export function versSaisie(iso: string | null, fuseau: string | undefined): string {
  if (!iso) return '';
  return new Intl.DateTimeFormat('sv-SE', {
    timeZone: fuseau, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false,
  }).format(new Date(iso)).replace(' ', 'T');
}

/** Réglage actuel d'une tâche, à renvoyer en entier à l'API quand un écran n'en change qu'une partie. */
export function reglageDe(tache: TachePlanifiee, fuseau: string | undefined): ReglageTache {
  return {
    actif: tache.actif, recurrence: tache.recurrence, heure: tache.heure, jours_semaine: tache.jours_semaine, jour_du_mois: tache.jour_du_mois,
    prochaine_execution: tache.recurrence === 'une_fois' ? versSaisie(tache.prochaine_execution, fuseau) : null,
  };
}

/** Fréquence d'une tâche en clair (« Chaque jour à 07:00 »). */
export function resumeTache(tache: TachePlanifiee, ts: TextesTaches, date: (iso: string | null) => string): string {
  const s = ts.summary;
  switch (tache.recurrence) {
    case 'horaire': return s.horaire.replace('{minute}', tache.heure.slice(3));
    case 'quotidienne': return s.quotidienne.replace('{heure}', tache.heure);
    case 'hebdomadaire': return s.hebdomadaire.replace('{jours}', (tache.jours_semaine ?? []).map(j => ts.weekdays[j - 1]).join(', ')).replace('{heure}', tache.heure);
    case 'mensuelle': return s.mensuelle.replace('{jour}', String(tache.jour_du_mois ?? 1)).replace('{heure}', tache.heure);
    default: return s.une_fois.replace('{date}', date(tache.prochaine_execution));
  }
}
