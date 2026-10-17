import { useEffect, useMemo, useState } from 'react';
import { ArrowDown, ArrowUp, CalendarPlus, FileSpreadsheet, FileText, LibraryBig, Mail, Play, Table } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';
import { formatDateDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { getClientsActifs, getFournisseursActifs } from '@/services/api';
import ChampParametre, { type ListesParametres } from '@/components/rapports/ChampParametre';
import CourrielRapport from '@/components/rapports/CourrielRapport';
import EnvoisProgrammes from '@/components/rapports/EnvoisProgrammes';
import FenetreProgramme from '@/components/rapports/FenetreProgramme';
import {
  executerRapport, getCatalogueRapports, getProgrammesRapports, telechargerRapport,
  type CatalogueRapports, type DefinitionRapport, type FormatRapport, type ListeProgrammes, type ProgrammeRapport, type ResultatRapport, type TypeColonne,
} from '@/services/bibliothequeRapports';

/** Valeurs de départ des paramètres d'un rapport : leurs valeurs par défaut. */
const valeursParDefaut = (rapport: DefinitionRapport) =>
  Object.fromEntries(rapport.parametres.map(p => [p.cle, p.defaut !== undefined && p.defaut !== null ? String(p.defaut) : '']));

type Onglet = 'rapports' | 'programmes';

/**
 * Bibliothèque des rapports (plan de parité fripv2, phases 5.1 et 5.2) : les rapports par catégorie (ceux que les
 * droits du compte permettent), leurs paramètres, le résultat à l'écran (trié au clic sur un titre de colonne, avec les
 * totaux), en fichier CSV, Excel ou PDF, ou par courriel ; et les envois programmés (chaque jour, semaine ou mois).
 */
export default function BibliothequeRapportsPage() {
  const { t, language } = useLanguage();
  const { peut, isAdmin } = usePermissions();
  const tr = t.reportsLibrary;
  const locale = language === 'en' ? 'en-US' : 'fr-FR';
  const [onglet, setOnglet] = useState<Onglet>('rapports');
  const [categories, setCategories] = useState<CatalogueRapports['categories'] | null>(null);
  const [categoriesProduits, setCategoriesProduits] = useState<string[]>([]);
  const [choisi, setChoisi] = useState<DefinitionRapport | null>(null);
  const [valeurs, setValeurs] = useState<Record<string, string>>({});
  const [resultat, setResultat] = useState<ResultatRapport | null>(null);
  const [tri, setTri] = useState<{ cle: string; sens: 1 | -1 } | null>(null);
  const [erreur, setErreur] = useState('');
  const [annonce, setAnnonce] = useState('');
  const [enCours, setEnCours] = useState(false);
  const [tiers, setTiers] = useState<{ clients: { id: number; nom: string }[]; fournisseurs: { id: number; nom: string }[] }>({ clients: [], fournisseurs: [] });
  const [programmes, setProgrammes] = useState<ListeProgrammes | null>(null);
  const [versionProgrammes, setVersionProgrammes] = useState(0);
  const [courriel, setCourriel] = useState(false);
  const [fenetre, setFenetre] = useState<{ programme: ProgrammeRapport | null; initial?: { code: string; parametres: Record<string, string> } } | null>(null);

  useEffect(() => {
    let actif = true;
    getCatalogueRapports(language).then(
      r => {
        if (!actif) return;
        setCategories(r.categories);
        setCategoriesProduits(r.categories_produits ?? []);
        // Rapport ouvert : sa définition dans la nouvelle langue (titres, options), ses valeurs gardées.
        setChoisi(c => (c ? r.categories.flatMap(x => x.rapports).find(x => x.code === c.code) ?? null : null));
      },
      e => { if (actif) setErreur(messageErreur(e, tr.error)); },
    );
    return () => { actif = false; };
  }, [language, tr.error]);

  // Envois programmés (et périodes relatives proposées), rechargés après chaque changement.
  useEffect(() => {
    let actif = true;
    getProgrammesRapports(language).then(
      liste => { if (actif) setProgrammes(liste); },
      e => { if (actif) setErreur(messageErreur(e, tr.error)); },
    );
    return () => { actif = false; };
  }, [language, versionProgrammes, tr.error]);
  const rechargerProgrammes = () => setVersionProgrammes(v => v + 1);

  // Clients et fournisseurs proposés aux rapports qui en ont un paramètre, chargés une fois au besoin.
  const types = new Set((categories ?? []).flatMap(c => c.rapports.flatMap(r => r.parametres.map(p => p.type))));
  const [besoinClients, besoinFournisseurs] = [types.has('client'), types.has('fournisseur')];
  useEffect(() => {
    if (!besoinClients && !besoinFournisseurs) return;
    let actif = true;
    Promise.all([
      besoinClients ? getClientsActifs().catch(() => []) : Promise.resolve([]),
      besoinFournisseurs ? getFournisseursActifs().catch(() => []) : Promise.resolve([]),
    ]).then(([clients, fournisseurs]) => {
      if (!actif) return;
      setTiers({
        clients: clients.map(c => ({ id: c.id, nom: c.raison_sociale })),
        fournisseurs: fournisseurs.map(f => ({ id: f.id, nom: f.raison_sociale })),
      });
    });
    return () => { actif = false; };
  }, [besoinClients, besoinFournisseurs]);
  const listes = useMemo<ListesParametres>(() => ({ ...tiers, categories: categoriesProduits }), [tiers, categoriesProduits]);

  function choisir(rapport: DefinitionRapport) {
    setChoisi(rapport);
    setValeurs(valeursParDefaut(rapport));
    setResultat(null);
    setTri(null);
    setErreur('');
    setAnnonce('');
  }

  async function afficher() {
    if (!choisi) return;
    setEnCours(true);
    setErreur('');
    try {
      setResultat(await executerRapport(choisi.code, valeurs, language));
      setTri(null);
    } catch (e) {
      setErreur(messageErreur(e, tr.error));
    } finally {
      setEnCours(false);
    }
  }

  async function telecharger(format: FormatRapport) {
    if (!choisi) return;
    setErreur('');
    try {
      await telechargerRapport(choisi.code, valeurs, language, format);
    } catch (e) {
      setErreur(messageErreur(e, tr.error));
    }
  }

  function programmeEnregistre(programme: ProgrammeRapport) {
    setFenetre(null);
    setAnnonce(tr.schedules.saved.replace('{date}', programme.prochain_envoi ? formatDateDocument(programme.prochain_envoi, language) : '—'));
    rechargerProgrammes();
  }

  const cellule = (valeur: string | number | null | undefined, type: TypeColonne) => {
    if (valeur === null || valeur === undefined || valeur === '') return '';
    const n = Number(valeur);
    switch (type) {
      case 'montant': return n.toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      case 'quantite': return n.toLocaleString(locale, { maximumFractionDigits: 4 });
      case 'pourcentage': return `${n.toLocaleString(locale, { maximumFractionDigits: 1 })} %`;
      case 'entier': return n.toLocaleString(locale, { maximumFractionDigits: 0 });
      case 'date': return formatDateDocument(String(valeur), language);
      default: return String(valeur);
    }
  };
  const aDroite = (type: TypeColonne) => ['montant', 'quantite', 'pourcentage', 'entier'].includes(type);

  const lignes = useMemo(() => {
    if (!resultat || !tri) return resultat?.lignes ?? [];
    const colonne = resultat.colonnes.find(c => c.cle === tri.cle);
    return [...resultat.lignes].sort((a, b) => {
      const [x, y] = [a[tri.cle], b[tri.cle]];
      if (x === y) return 0;
      if (x === null || x === undefined || x === '') return 1;
      if (y === null || y === undefined || y === '') return -1;
      const ordre = colonne && aDroite(colonne.type) ? Number(x) - Number(y) : String(x).localeCompare(String(y), locale);
      return ordre * tri.sens;
    });
  }, [resultat, tri, locale]);

  const classeBouton = 'inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700';

  return (
    <div className="p-4 sm:p-6 space-y-4">
      <div>
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><LibraryBig className="text-indigo-600" /> {tr.title}</h1>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tr.subtitle}</p>
      </div>

      <div role="tablist" aria-label={tr.title} className="flex gap-1 border-b dark:border-gray-700">
        {(['rapports', 'programmes'] as const).map(o => (
          <button key={o} type="button" role="tab" id={`onglet-${o}`} aria-selected={onglet === o} aria-controls={`panneau-${o}`}
            onClick={() => { setOnglet(o); setAnnonce(''); }}
            className={`px-4 py-2 -mb-px text-sm border-b-2 ${onglet === o ? 'border-indigo-600 text-indigo-700 font-medium dark:text-indigo-300' : 'border-transparent text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white'}`}>
            {o === 'rapports' ? tr.tabReports : `${tr.tabSchedules}${programmes ? ` (${programmes.programmes.length})` : ''}`}
          </button>
        ))}
      </div>

      {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</p>}
      {annonce && <p role="status" className="px-3 py-2 rounded-lg text-sm bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-200">{annonce}</p>}

      <div role="tabpanel" id={`panneau-${onglet}`} aria-labelledby={`onglet-${onglet}`}>
        {onglet === 'programmes' ? (
          <EnvoisProgrammes liste={programmes} peutProgrammer={peut('rapports', 'programmer')} estAdmin={isAdmin()}
            onNouveau={() => setFenetre({ programme: null })} onModifier={programme => setFenetre({ programme })} onRecharger={rechargerProgrammes} />
        ) : (
          <div className="grid grid-cols-1 lg:grid-cols-4 gap-4">
            <nav aria-label={tr.title} className="space-y-4">
              {categories === null && !erreur && <p className="text-sm text-gray-500">{tr.loading}</p>}
              {categories?.length === 0 && <p className="text-sm text-gray-500">{tr.none}</p>}
              {categories?.map(c => (
                <div key={c.code}>
                  <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{tr.categories[c.code] ?? c.code}</h2>
                  <ul className="space-y-1">
                    {c.rapports.map(r => (
                      <li key={r.code}>
                        <button type="button" onClick={() => choisir(r)} aria-current={choisi?.code === r.code}
                          className={`w-full text-left px-3 py-2 rounded-lg text-sm ${choisi?.code === r.code ? 'bg-indigo-50 text-indigo-800 font-medium dark:bg-indigo-900/40 dark:text-indigo-200' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'}`}>
                          {r.titre}
                        </button>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </nav>

            <section className="lg:col-span-3 space-y-3">
              {!choisi ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">{tr.choose}</p>
              ) : (
                <>
                  <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 space-y-3">
                    <div>
                      <h2 className="text-lg font-semibold text-gray-900 dark:text-white">{choisi.titre}</h2>
                      <p className="text-sm text-gray-500 dark:text-gray-400">{choisi.description}</p>
                    </div>
                    {choisi.parametres.length > 0 && (
                      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        {choisi.parametres.map(p => (
                          <div key={p.cle}>
                            <label htmlFor={`parametre-${p.cle}`} className="block mb-1 text-xs text-gray-500 dark:text-gray-400">{p.libelle}</label>
                            <ChampParametre id={`parametre-${p.cle}`} parametre={p} valeur={valeurs[p.cle] ?? ''} listes={listes} sansFiltre={tr.all}
                              onChange={valeur => setValeurs(vs => ({ ...vs, [p.cle]: valeur }))} />
                          </div>
                        ))}
                      </div>
                    )}
                    <div className="flex flex-wrap gap-2">
                      <button type="button" onClick={() => { void afficher(); }} disabled={enCours}
                        className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm hover:bg-indigo-700 disabled:opacity-50">
                        <Play size={16} /> {enCours ? tr.loading : tr.run}
                      </button>
                      {([['csv', Table, tr.csv], ['xlsx', FileSpreadsheet, tr.excel], ['pdf', FileText, tr.pdf]] as const).map(([format, Icone, libelle]) => (
                        <button key={format} type="button" onClick={() => { void telecharger(format); }} className={classeBouton}>
                          <Icone size={16} /> {libelle}
                        </button>
                      ))}
                      {peut('rapports', 'envoyer') && (
                        <button type="button" onClick={() => setCourriel(true)} className={classeBouton}>
                          <Mail size={16} /> {tr.sendByEmail}
                        </button>
                      )}
                      {peut('rapports', 'programmer') && (
                        <button type="button" onClick={() => setFenetre({ programme: null, initial: { code: choisi.code, parametres: valeurs } })} className={classeBouton}>
                          <CalendarPlus size={16} /> {tr.schedule}
                        </button>
                      )}
                    </div>
                  </div>

                  {resultat && resultat.rapport.code === choisi.code && (
                    resultat.lignes.length === 0 ? (
                      <p className="text-sm text-gray-500 dark:text-gray-400">{tr.empty}</p>
                    ) : (
                      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
                        <p className="px-4 pt-3 text-xs text-gray-500 dark:text-gray-400">
                          {tr.count.replace('{n}', String(resultat.lignes.length))} · {tr.generatedAt.replace('{date}', new Date(resultat.genere_le).toLocaleString(locale))}
                        </p>
                        <table className="w-full text-sm">
                          <thead className="bg-gray-50 dark:bg-gray-700 border-b dark:border-gray-600">
                            <tr>
                              {resultat.colonnes.map(c => (
                                <th key={c.cle} className={`px-3 py-2 text-xs font-medium text-gray-600 dark:text-gray-300 ${aDroite(c.type) ? 'text-right' : 'text-left'}`}>
                                  <button type="button" onClick={() => setTri(tr2 => ({ cle: c.cle, sens: tr2?.cle === c.cle ? (-tr2.sens as 1 | -1) : (aDroite(c.type) ? -1 : 1) }))}
                                    className="inline-flex items-center gap-1 hover:text-gray-900 dark:hover:text-white">
                                    {c.libelle}
                                    {tri?.cle === c.cle && (tri.sens === 1 ? <ArrowUp size={12} /> : <ArrowDown size={12} />)}
                                  </button>
                                </th>
                              ))}
                            </tr>
                          </thead>
                          <tbody className="divide-y dark:divide-gray-700">
                            {lignes.map((l, index) => (
                              <tr key={index} className="dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                {resultat.colonnes.map(c => (
                                  <td key={c.cle} className={`px-3 py-2 ${aDroite(c.type) ? 'text-right tabular-nums' : ''}`}>{cellule(l[c.cle], c.type)}</td>
                                ))}
                              </tr>
                            ))}
                          </tbody>
                          {Object.keys(resultat.totaux).length > 0 && (
                            <tfoot className="bg-gray-50 dark:bg-gray-700 border-t dark:border-gray-600 font-semibold dark:text-white">
                              <tr>
                                {resultat.colonnes.map((c, index) => (
                                  <td key={c.cle} className={`px-3 py-2 ${aDroite(c.type) ? 'text-right tabular-nums' : ''}`}>
                                    {c.cle in resultat.totaux ? cellule(resultat.totaux[c.cle], c.type) : index === 0 ? tr.total : ''}
                                  </td>
                                ))}
                              </tr>
                            </tfoot>
                          )}
                        </table>
                      </div>
                    )
                  )}
                </>
              )}
            </section>
          </div>
        )}
      </div>

      {courriel && choisi && <CourrielRapport code={choisi.code} titre={choisi.titre} parametres={valeurs} onClose={() => setCourriel(false)} />}
      {fenetre && categories && (
        <FenetreProgramme programme={fenetre.programme} initial={fenetre.initial} categories={categories} periodes={programmes?.periodes ?? []} listes={listes}
          onClose={() => setFenetre(null)} onEnregistre={programmeEnregistre} />
      )}
    </div>
  );
}
