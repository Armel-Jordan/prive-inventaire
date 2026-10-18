import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { CircleHelp, Info, Search, Sparkles } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { useEcransGeneraux, useMenu } from '@/hooks/useMenu';
import { useAide } from '@/aide/contexte';
import { aidePour, chargerAides, correspond, type AidesEcrans } from '@/aide';
import { NOUVEAUTES } from '@/aide/nouveautes';
import { DATE_CONSTRUCTION, NOM_APPLICATION, VERSION_APPLICATION } from '@/config/application';

/**
 * Aide (plan de parité fripv2, phase 6.6) : carte des écrans ouverts au compte, par menu, avec leur résumé et leur
 * aide ; recherche d'un écran par son nom ou par la tâche ; raccourcis clavier ; à propos et nouveautés.
 */
export default function AidePage() {
  const { t, language } = useLanguage();
  const th = t.help;
  const locale = language === 'en' ? 'en-US' : 'fr-FR';
  const menu = useMenu();
  const general = useEcransGeneraux();
  const { ouvrir } = useAide();
  const [aides, setAides] = useState<AidesEcrans | null>(null);
  const [erreur, setErreur] = useState(false);
  const [recherche, setRecherche] = useState('');

  useEffect(() => {
    let actif = true;
    chargerAides().then(
      contenu => { if (actif) setAides(contenu); },
      () => { if (actif) setErreur(true); },
    );
    return () => { actif = false; };
  }, []);

  const aide = (chemin: string) => (aides ? aidePour(aides, chemin, language) : null);
  const categories = [general, ...menu]
    .map(categorie => ({ ...categorie, items: categorie.items.filter(item => correspond(recherche, item.label, aide(item.path))) }))
    .filter(categorie => categorie.items.length > 0);
  const date = (iso: string, options: Intl.DateTimeFormatOptions) => new Date(iso).toLocaleDateString(locale, options);

  return (
    <div className="space-y-8">
      <div>
        <h1 className="flex items-center gap-2 text-2xl font-bold text-gray-900 dark:text-white">
          <CircleHelp className="h-6 w-6" aria-hidden="true" /> {th.title} — {th.map}
        </h1>
        <p className="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-400">{th.mapSubtitle}</p>
      </div>

      <div className="max-w-xl">
        <label htmlFor="recherche-aide" className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{th.search}</label>
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" aria-hidden="true" />
          <input id="recherche-aide" type="search" value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={th.searchPlaceholder}
            className="w-full rounded-lg border py-2 pl-9 pr-3 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white" />
        </div>
      </div>

      {erreur && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{th.loadError}</p>}
      {categories.length === 0 && <p className="text-sm text-gray-500 dark:text-gray-400">{th.noResult.replace('{recherche}', recherche)}</p>}

      {categories.map(categorie => {
        const IconeCategorie = categorie.icon;
        return (
          <section key={categorie.id} aria-labelledby={`aide-${categorie.id}`}>
            <h2 id={`aide-${categorie.id}`} className="mb-3 flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-gray-100">
              <IconeCategorie size={20} aria-hidden="true" /> {categorie.label}
            </h2>
            <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
              {categorie.items.map(item => {
                const Icone = item.icon;
                return (
                  <li key={item.path} className="rounded-lg border bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                    <div className="flex items-start justify-between gap-2">
                      <Link to={item.path} className="flex items-center gap-2 font-medium text-blue-700 hover:underline dark:text-blue-400">
                        <Icone size={16} aria-hidden="true" /> {item.label}
                      </Link>
                      <button type="button" onClick={() => ouvrir(item.path)} title={th.showHelp.replace('{ecran}', item.label)}
                        aria-label={th.showHelp.replace('{ecran}', item.label)}
                        className="rounded p-1 text-gray-500 hover:bg-gray-100 hover:text-blue-600 dark:text-gray-400 dark:hover:bg-gray-700">
                        <CircleHelp className="h-4 w-4" aria-hidden="true" />
                      </button>
                    </div>
                    <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{aide(item.path)?.resume ?? ''}</p>
                  </li>
                );
              })}
            </ul>
          </section>
        );
      })}

      <section aria-labelledby="aide-raccourcis" className="rounded-lg border bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <h2 id="aide-raccourcis" className="mb-2 font-semibold text-gray-800 dark:text-gray-100">{th.shortcuts}</h2>
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm text-gray-700 dark:text-gray-300">
          <dt><kbd className="rounded border px-1.5 font-mono dark:border-gray-600">F1</kbd></dt><dd>{th.shortcutHelp}</dd>
          <dt><kbd className="rounded border px-1.5 font-mono dark:border-gray-600">Ctrl</kbd> + <kbd className="rounded border px-1.5 font-mono dark:border-gray-600">K</kbd></dt><dd>{th.shortcutSearch}</dd>
          <dt><kbd className="rounded border px-1.5 font-mono dark:border-gray-600">{th.escKey}</kbd></dt><dd>{th.shortcutClose}</dd>
        </dl>
      </section>

      <section id="a-propos" aria-labelledby="aide-a-propos" className="rounded-lg border bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <h2 id="aide-a-propos" className="mb-2 flex items-center gap-2 font-semibold text-gray-800 dark:text-gray-100">
          <Info className="h-5 w-5" aria-hidden="true" /> {th.about}
        </h2>
        <p className="text-sm text-gray-700 dark:text-gray-300">
          <span className="font-medium">{NOM_APPLICATION}</span>
          {VERSION_APPLICATION && <> — {th.version.replace('{version}', VERSION_APPLICATION)}</>}
          {DATE_CONSTRUCTION && <>, {th.builtOn.replace('{date}', date(DATE_CONSTRUCTION, { dateStyle: 'long' }))}</>}
        </p>
        <h3 className="mb-2 mt-4 flex items-center gap-2 font-semibold text-gray-800 dark:text-gray-100">
          <Sparkles className="h-4 w-4" aria-hidden="true" /> {th.whatsNew}
        </h3>
        <ul className="space-y-2 text-sm text-gray-700 dark:text-gray-300">
          {NOUVEAUTES.map(nouveaute => (
            <li key={nouveaute.fr} className="flex gap-3">
              <span className="whitespace-nowrap text-gray-500 dark:text-gray-400">{date(nouveaute.date, { dateStyle: 'short' })}</span>
              <span>
                {nouveaute.lien ? <Link to={nouveaute.lien} className="hover:underline">{nouveaute[language === 'en' ? 'en' : 'fr']}</Link> : nouveaute[language === 'en' ? 'en' : 'fr']}
              </span>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}
