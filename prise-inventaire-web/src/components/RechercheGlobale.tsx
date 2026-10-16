import { useEffect, useId, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Search, X } from 'lucide-react';
import { getParametres } from '@/services/api';
import { MODULE_DU_TYPE, TYPES_RECHERCHE, rechercherGlobal, type GroupeRecherche, type ResultatRecherche } from '@/services/recherche';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';

const CHAMPS_DATE = new Set(['date', 'validite', 'echeance']);
const CHAMPS_MONTANT = new Set(['prix', 'montant', 'reste', 'encours']);

/**
 * Recherche globale de l'en-tête (Ctrl+K) : produits, clients, fournisseurs, documents, secteurs et
 * employés que l'utilisateur peut consulter. Un résultat ouvre un aperçu, avec un lien vers sa page.
 */
export default function RechercheGlobale() {
  const { t, language } = useLanguage();
  const tr = t.globalSearch;
  const navigate = useNavigate();
  const { canView } = usePermissions();
  const id = useId();
  const champ = useRef<HTMLInputElement>(null);
  const conteneur = useRef<HTMLDivElement>(null);
  const derniere = useRef(0);
  const [texte, setTexte] = useState('');
  const [groupes, setGroupes] = useState<GroupeRecherche[] | null>(null);
  const [ouvert, setOuvert] = useState(false);
  const [actif, setActif] = useState(0);
  const [apercu, setApercu] = useState<ResultatRecherche | null>(null);
  const [devise, setDevise] = useState('');

  const types = TYPES_RECHERCHE.filter(type => canView(MODULE_DU_TYPE[type]));
  const cleTypes = types.join(',');
  const resultats = (groupes ?? []).flatMap(g => g.resultats);

  // Raccourci clavier Ctrl+K (Cmd+K sur Mac).
  useEffect(() => {
    const raccourci = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        champ.current?.focus();
      }
    };
    window.addEventListener('keydown', raccourci);
    return () => window.removeEventListener('keydown', raccourci);
  }, []);

  // Fermeture au clic en dehors.
  useEffect(() => {
    const dehors = (e: MouseEvent) => { if (!conteneur.current?.contains(e.target as Node)) setOuvert(false); };
    document.addEventListener('mousedown', dehors);
    return () => document.removeEventListener('mousedown', dehors);
  }, []);

  // Recherche après une courte pause de saisie ; seule la dernière réponse compte.
  useEffect(() => {
    const q = texte.trim();
    if (q.length < 2 || !cleTypes) return;
    const numero = ++derniere.current;
    const minuterie = setTimeout(() => {
      rechercherGlobal(q, cleTypes.split(','))
        .then(r => { if (numero === derniere.current) { setGroupes(r.groupes); setActif(0); } })
        .catch(() => { if (numero === derniere.current) setGroupes([]); });
    }, 250);
    return () => clearTimeout(minuterie);
  }, [texte, cleTypes]);

  // Symbole de devise des montants de l'aperçu, chargé à la première ouverture de la recherche.
  const deviseDemandee = useRef(false);
  function ouvrir() {
    setOuvert(true);
    if (!deviseDemandee.current) {
      deviseDemandee.current = true;
      getParametres().then(p => setDevise(p.devise_symbole || '')).catch(() => { deviseDemandee.current = false; });
    }
  }

  function saisir(valeur: string) {
    setTexte(valeur);
    ouvrir();
    if (valeur.trim().length < 2) setGroupes(null);
  }

  function ouvrirApercu(resultat: ResultatRecherche) {
    setApercu(resultat);
    setOuvert(false);
  }

  function clavier(e: React.KeyboardEvent) {
    if (e.key === 'Escape') {
      // Sans preventDefault, Chrome vide le champ de recherche, ce qui rouvrirait la liste.
      e.preventDefault();
      setOuvert(false);
      champ.current?.blur();
    } else if (e.key === 'ArrowDown' && resultats.length) {
      e.preventDefault();
      setOuvert(true);
      setActif(i => (i + 1) % resultats.length);
    } else if (e.key === 'ArrowUp' && resultats.length) {
      e.preventDefault();
      setActif(i => (i - 1 + resultats.length) % resultats.length);
    } else if (e.key === 'Enter' && resultats[actif]) {
      e.preventDefault();
      ouvrirApercu(resultats[actif]);
    }
  }

  const locale = language === 'en' ? 'en-US' : 'fr-FR';
  function valeur(cle: string, v: string | number | boolean | null): string {
    if (typeof v === 'boolean') return v ? tr.active : tr.inactive;
    if (CHAMPS_DATE.has(cle) && typeof v === 'string') return new Date(`${v}T00:00:00`).toLocaleDateString(locale);
    if (CHAMPS_MONTANT.has(cle) && typeof v === 'number') {
      const montant = new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v);
      const symbole = typeof apercu?.details.devise === 'string' && apercu.details.devise ? apercu.details.devise : devise;
      return `${montant} ${symbole}`.trim();
    }
    if (cle === 'statut' && typeof v === 'string') return tr.statuses[v] ?? v.replace(/_/g, ' ');
    return String(v);
  }

  const q = texte.trim();
  const indexDe = new Map(resultats.map((r, i) => [`${r.type}-${r.id}`, i]));

  return (
    <div ref={conteneur} className="relative mr-auto w-full max-w-md">
      <label htmlFor={`${id}-champ`} className="sr-only">{tr.label}</label>
      <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" />
      <input
        ref={champ}
        id={`${id}-champ`}
        type="search"
        role="combobox"
        aria-expanded={ouvert}
        aria-controls={`${id}-liste`}
        aria-autocomplete="list"
        aria-activedescendant={ouvert && resultats[actif] ? `${id}-option-${actif}` : undefined}
        value={texte}
        onChange={e => saisir(e.target.value)}
        onFocus={ouvrir}
        onKeyDown={clavier}
        placeholder={tr.placeholder}
        className="w-full pl-9 pr-14 py-2 text-sm border rounded-lg bg-gray-50 dark:bg-gray-700 dark:border-gray-600 dark:text-white focus:bg-white dark:focus:bg-gray-700"
      />
      {!texte && <kbd className="hidden sm:block absolute right-3 top-1/2 -translate-y-1/2 px-1.5 rounded border text-[10px] text-gray-400 dark:border-gray-600">{tr.shortcut}</kbd>}

      {ouvert && (
        <div id={`${id}-liste`} role="listbox" aria-label={tr.label}
          className="absolute z-40 mt-2 w-[min(32rem,90vw)] max-h-[70vh] overflow-y-auto rounded-lg border bg-white dark:bg-gray-800 dark:border-gray-700 shadow-xl">
          {q.length < 2 ? (
            <p className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{tr.hint}</p>
          ) : groupes && groupes.length === 0 ? (
            <p className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{tr.noResults.replace('{q}', q)}</p>
          ) : groupes?.map(g => (
            <div key={g.type} role="group" aria-label={tr.types[g.type]} className="py-1 border-b last:border-b-0 dark:border-gray-700">
              <p className="px-4 pt-2 pb-1 text-[11px] uppercase tracking-wide text-gray-400">
                {tr.types[g.type]}
                {g.total > g.resultats.length && <span className="ml-2 normal-case tracking-normal">{tr.more.replace('{n}', String(g.total - g.resultats.length))}</span>}
              </p>
              {g.resultats.map(r => {
                const index = indexDe.get(`${r.type}-${r.id}`) ?? 0;
                return (
                  <div key={`${r.type}-${r.id}`} id={`${id}-option-${index}`} role="option" aria-selected={index === actif}
                    onMouseMove={() => { if (index !== actif) setActif(index); }} onMouseDown={e => e.preventDefault()} onClick={() => ouvrirApercu(r)}
                    className={`px-4 py-2 cursor-pointer ${index === actif ? 'bg-purple-50 dark:bg-purple-900/30' : ''}`}>
                    <p className="text-sm text-gray-900 dark:text-white truncate">{r.titre}</p>
                    {r.sous_titre && <p className="text-xs text-gray-500 dark:text-gray-400 truncate">{r.sous_titre}</p>}
                  </div>
                );
              })}
            </div>
          ))}
        </div>
      )}

      {apercu && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" role="dialog" aria-modal="true" aria-labelledby={`${id}-apercu`}>
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg">
            <div className="flex items-start justify-between gap-3 mb-4">
              <div>
                <p className="text-xs uppercase tracking-wide text-gray-400">{tr.types[apercu.type]}</p>
                <h2 id={`${id}-apercu`} className="text-lg font-bold text-gray-900 dark:text-white">{apercu.titre}</h2>
                {apercu.sous_titre && <p className="text-sm text-gray-500 dark:text-gray-400">{apercu.sous_titre}</p>}
              </div>
              <button onClick={() => setApercu(null)} aria-label={tr.close} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 dark:text-white"><X size={20} /></button>
            </div>
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
              {Object.entries(apercu.details).filter(([, v]) => v !== null && v !== '').map(([cle, v]) => (
                <div key={cle} className="contents">
                  <dt className="text-gray-500 dark:text-gray-400">{tr.fields[cle] ?? cle}</dt>
                  <dd className="text-gray-900 dark:text-white">{valeur(cle, v)}</dd>
                </div>
              ))}
            </dl>
            <div className="flex justify-end gap-2 mt-6">
              <button onClick={() => setApercu(null)} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-white">{tr.close}</button>
              <button onClick={() => { navigate(apercu.lien); setApercu(null); setTexte(''); setGroupes(null); }}
                className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{tr.open.replace('{page}', tr.types[apercu.type])}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
