import { Lock, LockOpen } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';
import type { VerrouFiche } from '@/hooks/useVerrou';

/**
 * Bandeau d'une fiche ouverte en modification (phase 6.3) : un autre compte la modifie (réessayer, ou prendre la main
 * avec le droit de libérer), ou la modification a été interrompue (fiche libérée, ou modifiée entre-temps).
 */
export default function BandeauVerrou({ verrou }: { verrou: VerrouFiche }) {
  const { t, language } = useLanguage();
  const tv = t.locks;
  const { peut } = usePermissions();
  const info = verrou.verrou;
  if (!info || (verrou.etat !== 'occupe' && verrou.etat !== 'perdu')) return null;

  const nom = info.par || tv.someone;
  const depuis = info.depuis ? new Date(info.depuis) : null;
  const memeJour = depuis?.toDateString() === new Date().toDateString();
  const heure = depuis
    ? depuis.toLocaleString(language === 'en' ? 'en-US' : 'fr-FR', memeJour ? { hour: '2-digit', minute: '2-digit' } : { dateStyle: 'short', timeStyle: 'short' })
    : '';

  if (verrou.etat === 'perdu') {
    return (
      <div role="alert" className="mb-4 flex gap-3 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/30 dark:text-red-200">
        <LockOpen className="mt-0.5 h-5 w-5 flex-shrink-0" aria-hidden="true" />
        <div>
          <p className="font-medium">
            {info.libere_par !== null || !info.par
              ? tv.released.replace('{nom}', info.libere_par || tv.manager)
              : tv.changed.replace('{nom}', nom)}
          </p>
          <p>{tv.reopen}</p>
        </div>
      </div>
    );
  }

  const prendreLaMain = () => {
    if (confirm(tv.confirmTakeOver.replace('{nom}', nom))) void verrou.prendreLaMain();
  };

  return (
    <div role="alert" className="mb-4 flex gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-100">
      <Lock className="mt-0.5 h-5 w-5 flex-shrink-0" aria-hidden="true" />
      <div className="flex-1">
        <p className="font-medium">{tv.busy.replace('{nom}', nom).replace('{heure}', heure)}</p>
        <p>{tv.busyHelp}</p>
        {verrou.erreur !== null && <p className="mt-1 text-red-700 dark:text-red-300">{verrou.erreur || tv.takeOverError}</p>}
        <div className="mt-2 flex flex-wrap gap-2">
          <button type="button" onClick={verrou.reessayer}
            className="rounded-lg border border-amber-400 px-3 py-1 hover:bg-amber-100 dark:border-amber-700 dark:hover:bg-amber-900/50">
            {tv.retry}
          </button>
          {peut('verrous', 'liberer') && (
            <button type="button" onClick={prendreLaMain}
              className="rounded-lg bg-amber-600 px-3 py-1 text-white hover:bg-amber-700">
              {tv.takeOver}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
