import { useMemo, useState, type ReactNode } from 'react';
import { Search, X } from 'lucide-react';
import type { ModuleCatalogue, OngletCatalogue, PermissionCatalogue } from '@/services/permissions';

/** Sans accents ni majuscules : « Facture émettre » trouve « Émettre une facture ». */
const normaliser = (texte: string) => texte.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();

interface Props {
  catalogue: OngletCatalogue[];
  langue: 'fr' | 'en';
  textes: { search: string; helpEmpty: string; noMatch: string };
  /** Contenu à droite d'une permission : case à cocher, bouton à trois états… */
  rendreCase: (permission: PermissionCatalogue) => ReactNode;
  /** Colonne de gauche facultative (droits venant des groupes). */
  rendreGauche?: (permission: PermissionCatalogue) => ReactNode;
  /** En-tête d'un module (case « Tous »…), avec ses permissions visibles. */
  rendreEnTete?: (module: ModuleCatalogue, visibles: PermissionCatalogue[]) => ReactNode;
  /** Filtre supplémentaire (exceptions seulement…). */
  filtre?: (permission: PermissionCatalogue) => boolean;
  barreOutils?: ReactNode;
}

/**
 * Permissions par onglet du menu, puis par écran (comme dans fripv2) : recherche sans accents ni majuscules
 * (chaque mot dans le nom de l'écran ou de la permission), nombre de permissions par onglet, panneau d'aide de
 * la permission survolée ou sélectionnée au clavier.
 */
export default function MatricePermissions({ catalogue, langue, textes, rendreCase, rendreGauche, rendreEnTete, filtre, barreOutils }: Props) {
  const [recherche, setRecherche] = useState('');
  const [ongletChoisi, setOngletChoisi] = useState(catalogue[0]?.cle ?? '');
  const [aide, setAide] = useState<PermissionCatalogue | null>(null);

  const visibles = useMemo(() => {
    const mots = normaliser(recherche).split(/\s+/).filter(Boolean);
    const parModule = new Map<string, PermissionCatalogue[]>();
    for (const onglet of catalogue) {
      for (const module of onglet.modules) {
        parModule.set(module.cle, module.permissions.filter(permission => {
          if (filtre && !filtre(permission)) return false;
          const texte = normaliser(`${module[langue]} ${permission[langue]}`);
          return mots.every(mot => texte.includes(mot));
        }));
      }
    }
    return parModule;
  }, [catalogue, filtre, langue, recherche]);

  const nombre = (onglet: OngletCatalogue) => onglet.modules.reduce((n, m) => n + (visibles.get(m.cle)?.length ?? 0), 0);
  // L'onglet choisi s'il a des permissions visibles, sinon le premier qui en a.
  const onglet = catalogue.find(o => o.cle === ongletChoisi && nombre(o) > 0) ?? catalogue.find(o => nombre(o) > 0);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <label className="relative flex-1 min-w-[14rem]">
          <span className="sr-only">{textes.search}</span>
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input
            value={recherche}
            onChange={e => setRecherche(e.target.value)}
            onKeyDown={e => { if (e.key === 'Escape') setRecherche(''); }}
            placeholder={textes.search}
            className="w-full pl-9 pr-8 py-2 border border-gray-300 rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
          {recherche && (
            <button type="button" onClick={() => setRecherche('')} className="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600" aria-label="×">
              <X size={14} />
            </button>
          )}
        </label>
        {barreOutils}
      </div>

      <div className="flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-700" role="tablist">
        {catalogue.map(o => {
          const n = nombre(o);
          const actif = o.cle === onglet?.cle;
          return (
            <button
              key={o.cle}
              type="button"
              role="tab"
              aria-selected={actif}
              disabled={n === 0}
              onClick={() => setOngletChoisi(o.cle)}
              className={`px-3 py-2 text-sm border-b-2 -mb-px ${actif ? 'border-blue-600 text-blue-700 font-medium dark:text-blue-400' : 'border-transparent text-gray-600 hover:text-gray-900 dark:text-gray-300'} disabled:opacity-40`}
            >
              {o[langue]} <span className="text-xs text-gray-400">({n})</span>
            </button>
          );
        })}
      </div>

      {!onglet ? (
        <p className="py-8 text-center text-sm text-gray-500 dark:text-gray-400">{textes.noMatch}</p>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-2 2xl:grid-cols-3 gap-3">
          {onglet.modules.filter(m => (visibles.get(m.cle)?.length ?? 0) > 0).map(module => {
            const permissions = visibles.get(module.cle) ?? [];
            return (
              <section key={module.cle} className="border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800">
                <header className="flex items-center justify-between gap-2 px-3 py-2 border-b border-gray-100 dark:border-gray-700">
                  <h3 className="text-sm font-semibold text-gray-800 dark:text-gray-100">{module[langue]}</h3>
                  {rendreEnTete?.(module, permissions)}
                </header>
                <ul className="divide-y divide-gray-50 dark:divide-gray-700/50">
                  {permissions.map(permission => (
                    <li
                      key={permission.code}
                      className={`flex items-center gap-2 px-3 py-1.5 text-sm ${aide?.code === permission.code ? 'bg-blue-50/60 dark:bg-blue-900/20' : ''}`}
                      onMouseEnter={() => setAide(permission)}
                      onFocus={() => setAide(permission)}
                      title={langue === 'en' ? permission.aide_en : permission.aide_fr}
                    >
                      {rendreGauche && <span className="w-14 shrink-0 text-center">{rendreGauche(permission)}</span>}
                      <span className={`flex-1 min-w-0 ${permission.base === null ? 'text-gray-700 dark:text-gray-200' : 'text-gray-900 dark:text-white'}`}>{permission[langue]}</span>
                      <span className="shrink-0">{rendreCase(permission)}</span>
                    </li>
                  ))}
                </ul>
              </section>
            );
          })}
        </div>
      )}

      <div className="sticky bottom-0 z-10 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900" aria-live="polite">
        {aide ? (
          <>
            <p className="font-medium text-gray-900 dark:text-white">{aide[langue]}</p>
            <p className="text-gray-600 dark:text-gray-300">{langue === 'en' ? aide.aide_en : aide.aide_fr}</p>
          </>
        ) : (
          <p className="text-gray-500 dark:text-gray-400">{textes.helpEmpty}</p>
        )}
      </div>
    </div>
  );
}
