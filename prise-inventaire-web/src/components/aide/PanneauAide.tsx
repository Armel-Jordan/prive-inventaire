import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { CircleHelp, LayoutGrid, LifeBuoy, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';
import { aidePour, chargerAides, type AidesEcrans } from '@/aide';
import { titreEcran } from '@/navigation';

/**
 * Panneau d'aide d'un écran (plan de parité fripv2, phase 6.6) : à quoi sert l'écran, comment faire, bon à savoir.
 * Il reste ouvert à côté de l'écran (on peut travailler en le lisant) et suit l'écran affiché.
 */
export default function PanneauAide({ chemin, onFermer }: { chemin: string; onFermer: () => void }) {
  const { t, language } = useLanguage();
  const th = t.help;
  const { peut, canView } = usePermissions();
  const [aides, setAides] = useState<AidesEcrans | null>(null);
  const [erreur, setErreur] = useState(false);
  const titre = useRef<HTMLHeadingElement>(null);

  useEffect(() => {
    let actif = true;
    chargerAides().then(
      contenu => { if (actif) setAides(contenu); },
      () => { if (actif) setErreur(true); },
    );
    return () => { actif = false; };
  }, []);

  // Le titre prend le focus à l'ouverture et à chaque changement d'écran : la lecture d'écran commence par lui.
  useEffect(() => {
    titre.current?.focus();
  }, [chemin]);

  useEffect(() => {
    const touche = (evenement: KeyboardEvent) => {
      if (evenement.key === 'Escape') onFermer();
    };
    window.addEventListener('keydown', touche);
    return () => window.removeEventListener('keydown', touche);
  }, [onFermer]);

  const aide = aides ? aidePour(aides, chemin, language) : null;
  const support = peut('support', 'creer') || canView('support');

  return (
    <aside aria-labelledby="aide-titre"
      className="fixed inset-y-0 right-0 z-[60] flex w-full flex-col border-l bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-800 sm:w-96">
      <div className="flex items-start justify-between gap-3 border-b p-4 dark:border-gray-700">
        <h2 id="aide-titre" ref={titre} tabIndex={-1} className="flex items-center gap-2 text-lg font-semibold text-gray-900 outline-none dark:text-white">
          <CircleHelp className="h-5 w-5 flex-shrink-0 text-blue-600 dark:text-blue-400" aria-hidden="true" />
          {th.panelTitle.replace('{ecran}', titreEcran(t, chemin))}
        </h2>
        <button type="button" onClick={onFermer} aria-label={th.close} title={th.close}
          className="rounded p-1 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700">
          <X className="h-5 w-5" aria-hidden="true" />
        </button>
      </div>

      <div className="flex-1 space-y-5 overflow-y-auto p-4 text-sm text-gray-700 dark:text-gray-300">
        {erreur ? (
          <p role="alert" className="text-red-700 dark:text-red-300">{th.loadError}</p>
        ) : !aides ? (
          <p className="text-gray-500 dark:text-gray-400">{th.loading}</p>
        ) : !aide ? (
          <p>{th.none}</p>
        ) : (
          <>
            <section>
              <h3 className="mb-1 font-semibold text-gray-900 dark:text-white">{th.purpose}</h3>
              <p>{aide.resume}</p>
            </section>
            {aide.etapes && aide.etapes.length > 0 && (
              <section>
                <h3 className="mb-1 font-semibold text-gray-900 dark:text-white">{th.steps}</h3>
                <ol className="list-decimal space-y-1 pl-5">
                  {aide.etapes.map(etape => <li key={etape}>{etape}</li>)}
                </ol>
              </section>
            )}
            {aide.astuces && aide.astuces.length > 0 && (
              <section>
                <h3 className="mb-1 font-semibold text-gray-900 dark:text-white">{th.tips}</h3>
                <ul className="list-disc space-y-1 pl-5">
                  {aide.astuces.map(astuce => <li key={astuce}>{astuce}</li>)}
                </ul>
              </section>
            )}
          </>
        )}
      </div>

      <div className="space-y-2 border-t p-4 text-sm dark:border-gray-700">
        <Link to="/aide" className="flex items-center gap-2 text-blue-600 hover:underline dark:text-blue-400">
          <LayoutGrid className="h-4 w-4" aria-hidden="true" /> {th.allHelp}
        </Link>
        {support && (
          <Link to={`/support?depuis=${encodeURIComponent(chemin)}`} className="flex items-center gap-2 text-blue-600 hover:underline dark:text-blue-400">
            <LifeBuoy className="h-4 w-4" aria-hidden="true" /> {th.contactSupport}
          </Link>
        )}
        <p className="text-xs text-gray-500 dark:text-gray-400">
          <kbd className="rounded border px-1 font-mono dark:border-gray-600">F1</kbd> {th.shortcutHelp}
          {' · '}
          <kbd className="rounded border px-1 font-mono dark:border-gray-600">{th.escKey}</kbd> {th.close}
        </p>
      </div>
    </aside>
  );
}
