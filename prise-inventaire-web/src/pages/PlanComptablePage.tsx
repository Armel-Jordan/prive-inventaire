import { useEffect, useMemo, useState } from 'react';
import { BookOpen, ChevronDown, ChevronRight, Download, FileUp, Pencil, Plus, Power, Search, Trash2, X } from 'lucide-react';
import {
  getComptes, creerCompte, modifierCompte, supprimerCompte, getPlansTypes, importerPlanType, importerFichierComptes, exporterComptes,
  NATURES, TYPES_COMPTE, type CompteComptable, type SaisieCompte, type PlanType, type NatureCompte,
} from '@/services/comptabilite';
import { ApiError } from '@/services/http';
import { useAuth } from '@/contexts/AuthContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeLibelle = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';
const COULEURS_NATURE: Record<NatureCompte, string> = {
  actif: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
  passif: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
  capitaux_propres: 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200',
  produit: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
  charge: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
};

interface Ligne {
  compte: CompteComptable;
  profondeur: number;
  aDesEnfants: boolean;
}

/** Comptes dans l'ordre de l'arborescence (parent puis ses sous-comptes, par numéro), avec leur profondeur. */
function arborescence(comptes: CompteComptable[]): Ligne[] {
  const parId = new Map(comptes.map(c => [c.id, c]));
  const enfants = new Map<number | null, CompteComptable[]>();
  for (const compte of comptes) {
    const parent = compte.parent_id !== null && parId.has(compte.parent_id) ? compte.parent_id : null;
    enfants.set(parent, [...(enfants.get(parent) ?? []), compte]);
  }
  const lignes: Ligne[] = [];
  const parcourir = (parent: number | null, profondeur: number) => {
    for (const compte of (enfants.get(parent) ?? []).sort((a, b) => a.numero.localeCompare(b.numero))) {
      lignes.push({ compte, profondeur, aDesEnfants: enfants.has(compte.id) });
      parcourir(compte.id, profondeur + 1);
    }
  };
  parcourir(null, 0);
  return lignes;
}

/** Le compte et tous ses sous-comptes (exclus des parents possibles : la hiérarchie bouclerait). */
function descendance(id: number, comptes: CompteComptable[]): Set<number> {
  const ids = new Set([id]);
  let ajoute = true;
  while (ajoute) {
    ajoute = false;
    for (const compte of comptes) {
      if (compte.parent_id !== null && ids.has(compte.parent_id) && !ids.has(compte.id)) {
        ids.add(compte.id);
        ajoute = true;
      }
    }
  }
  return ids;
}

/** Plan comptable : arborescence des comptes, plans types, import et export CSV, création et modification. */
export default function PlanComptablePage() {
  const { t } = useLanguage();
  const ta = t.accounting;
  const tc = ta.chart;
  const { user } = useAuth();
  const peutModifier = user?.role === 'admin' || user?.role === 'manager';

  const [comptes, setComptes] = useState<CompteComptable[] | null>(null);
  const [plans, setPlans] = useState<PlanType[]>([]);
  const [version, setVersion] = useState(0);
  const [message, setMessage] = useState<{ erreur: boolean; texte: string } | null>(null);
  const [recherche, setRecherche] = useState('');
  const [nature, setNature] = useState<NatureCompte | ''>('');
  const [statut, setStatut] = useState<'tous' | 'actifs' | 'inactifs'>('tous');
  const [replies, setReplies] = useState<Set<number>>(new Set());
  const [edition, setEdition] = useState<{ compte: CompteComptable | null; saisie: SaisieCompte } | null>(null);
  const [erreurEdition, setErreurEdition] = useState('');
  const [importation, setImportation] = useState<'plan' | 'fichier' | null>(null);
  const [planChoisi, setPlanChoisi] = useState('');
  const [fichier, setFichier] = useState<File | null>(null);
  const [erreursImport, setErreursImport] = useState<string[]>([]);
  const [enCours, setEnCours] = useState(false);

  useEffect(() => {
    let actif = true;
    getComptes().then(c => { if (actif) setComptes(c); }).catch(e => { if (actif) setMessage({ erreur: true, texte: messageErreur(e, tc.error) }); });
    return () => { actif = false; };
  }, [version, tc.error]);

  useEffect(() => {
    getPlansTypes().then(p => { setPlans(p); setPlanChoisi(courant => courant || p[0]?.code || ''); }).catch(() => {});
  }, []);

  const lignes = useMemo(() => arborescence(comptes ?? []), [comptes]);
  const filtre = recherche.trim() !== '' || nature !== '' || statut !== 'tous';
  const visibles = useMemo(() => {
    const terme = recherche.trim().toLowerCase();
    if (filtre) {
      return lignes.filter(({ compte }) => (terme === '' || compte.numero.toLowerCase().includes(terme) || compte.libelle.toLowerCase().includes(terme))
        && (nature === '' || compte.nature === nature)
        && (statut === 'tous' || compte.actif === (statut === 'actifs')));
    }
    // Sans filtre : arborescence, sans les sous-comptes des comptes repliés.
    const masques = new Set<number>();
    return lignes.filter(({ compte }) => {
      if (compte.parent_id !== null && (masques.has(compte.parent_id) || replies.has(compte.parent_id))) {
        masques.add(compte.id);
        return false;
      }
      return true;
    });
  }, [lignes, filtre, recherche, nature, statut, replies]);

  const recharger = (texte: string) => { setMessage({ erreur: false, texte }); setVersion(v => v + 1); };
  const echec = (e: unknown) => setMessage({ erreur: true, texte: messageErreur(e, tc.error) });

  function ouvrirEdition(compte: CompteComptable | null, parent?: CompteComptable) {
    setErreurEdition('');
    setEdition({
      compte,
      saisie: compte
        ? { numero: compte.numero, libelle: compte.libelle, parent_id: compte.parent_id, nature: compte.nature, type: compte.type, saisie_manuelle: compte.saisie_manuelle, actif: compte.actif, notes: compte.notes }
        : { numero: parent?.numero ?? '', libelle: '', parent_id: parent?.id ?? null, nature: parent?.nature ?? 'actif', type: 'standard', saisie_manuelle: true, actif: true, notes: null },
    });
  }

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    if (!edition) return;
    setErreurEdition('');
    try {
      if (edition.compte) await modifierCompte(edition.compte.id, edition.saisie);
      else await creerCompte(edition.saisie);
      setEdition(null);
      recharger(tc.saved);
    } catch (erreur) {
      setErreurEdition(messageErreur(erreur, tc.error));
    }
  }

  async function basculer(compte: CompteComptable) {
    try {
      await modifierCompte(compte.id, { ...compte, actif: !compte.actif });
      recharger(tc.saved);
    } catch (e) {
      echec(e);
    }
  }

  async function supprimer(compte: CompteComptable) {
    if (!confirm(tc.confirmDelete.replace('{numero}', compte.numero))) return;
    try {
      await supprimerCompte(compte.id);
      recharger(tc.deleted);
    } catch (e) {
      echec(e);
    }
  }

  async function importerPlan(code: string, completer: boolean) {
    setEnCours(true);
    try {
      const { message: texte } = await importerPlanType(code, completer);
      setImportation(null);
      recharger(texte);
    } catch (e) {
      echec(e);
    } finally {
      setEnCours(false);
    }
  }

  async function importerFichier(e: React.FormEvent) {
    e.preventDefault();
    if (!fichier) return;
    setEnCours(true);
    setErreursImport([]);
    try {
      const { message: texte } = await importerFichierComptes(fichier);
      setImportation(null);
      setFichier(null);
      recharger(texte);
    } catch (erreur) {
      const erreurs = erreur instanceof ApiError ? (erreur.data as { erreurs?: string[] } | null)?.erreurs : undefined;
      setErreursImport(erreurs?.length ? erreurs : [messageErreur(erreur, tc.error)]);
    } finally {
      setEnCours(false);
    }
  }

  const modifierSaisie = (valeurs: Partial<SaisieCompte>) => setEdition(ed => (ed ? { ...ed, saisie: { ...ed.saisie, ...valeurs } } : ed));
  const exclus = edition?.compte ? descendance(edition.compte.id, comptes ?? []) : new Set<number>();
  const parents = lignes.filter(({ compte }) => !exclus.has(compte.id));

  return (
    <div className="p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><BookOpen className="text-purple-600" /> {tc.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tc.subtitle}</p>
        </div>
        {comptes && comptes.length > 0 && (
          <div className="flex flex-wrap gap-2">
            <button onClick={() => exporterComptes().catch(echec)} className="flex items-center gap-2 px-3 py-2 text-sm border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
              <Download size={16} /> {tc.export}
            </button>
            {peutModifier && (
              <>
                <button onClick={() => { setErreursImport([]); setImportation('plan'); }} className="flex items-center gap-2 px-3 py-2 text-sm border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                  <BookOpen size={16} /> {tc.importTemplate}
                </button>
                <button onClick={() => { setErreursImport([]); setImportation('fichier'); }} className="flex items-center gap-2 px-3 py-2 text-sm border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                  <FileUp size={16} /> {tc.importFile}
                </button>
                <button onClick={() => ouvrirEdition(null)} className="flex items-center gap-2 px-3 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                  <Plus size={16} /> {tc.add}
                </button>
              </>
            )}
          </div>
        )}
      </div>

      {message && <p role="status" className={`mb-4 px-3 py-2 rounded-lg text-sm max-w-6xl ${message.erreur ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}>{message.texte}</p>}
      {!peutModifier && <p className="mb-4 px-3 py-2 rounded-lg text-sm max-w-6xl bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">{tc.readOnly}</p>}

      {comptes && comptes.length === 0 && (
        <div className="max-w-5xl">
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-4">
            <h2 className="text-lg font-semibold dark:text-white">{tc.empty}</h2>
            <p className="text-sm text-gray-500 dark:text-gray-400">{tc.emptyHelp}</p>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            {plans.map(plan => (
              <section key={plan.code} className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 flex flex-col gap-2">
                <h3 className="font-semibold text-gray-900 dark:text-white">{plan.libelle}</h3>
                <p className="text-sm text-gray-500 dark:text-gray-400 flex-1">{plan.description}</p>
                <p className="text-xs text-gray-500 dark:text-gray-400">{tc.accounts.replace('{n}', String(plan.nombre_comptes))}</p>
                {peutModifier && (
                  <button disabled={enCours} onClick={() => importerPlan(plan.code, false)} className="px-3 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">{tc.startFrom}</button>
                )}
              </section>
            ))}
          </div>
          {peutModifier && (
            <div className="flex flex-wrap gap-2 mt-4">
              <button onClick={() => { setErreursImport([]); setImportation('fichier'); }} className="flex items-center gap-2 px-3 py-2 text-sm border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                <FileUp size={16} /> {tc.importFile}
              </button>
              <button onClick={() => ouvrirEdition(null)} className="flex items-center gap-2 px-3 py-2 text-sm border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                <Plus size={16} /> {tc.add}
              </button>
            </div>
          )}
        </div>
      )}

      {comptes && comptes.length > 0 && (
        <>
          <div className="flex flex-wrap items-center gap-2 mb-4 max-w-6xl">
            <div className="relative flex-1 min-w-[12rem]">
              <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
              <input value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={tc.search} aria-label={tc.search}
                className="w-full pl-9 pr-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm" />
            </div>
            <select value={nature} onChange={e => setNature(e.target.value as NatureCompte | '')} aria-label={tc.nature}
              className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm">
              <option value="">{tc.allNatures}</option>
              {NATURES.map(n => <option key={n} value={n}>{ta.natures[n]}</option>)}
            </select>
            <select value={statut} onChange={e => setStatut(e.target.value as 'tous' | 'actifs' | 'inactifs')} aria-label={tc.statusLabel}
              className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm">
              {(['tous', 'actifs', 'inactifs'] as const).map(s => <option key={s} value={s}>{tc.status[s]}</option>)}
            </select>
            {!filtre && (
              <div className="flex gap-1 text-sm">
                <button onClick={() => setReplies(new Set(lignes.filter(l => l.aDesEnfants && l.profondeur === 0).map(l => l.compte.id)))} className="px-2 py-1 text-purple-600 hover:underline dark:text-purple-400">{tc.collapseAll}</button>
                <button onClick={() => setReplies(new Set())} className="px-2 py-1 text-purple-600 hover:underline dark:text-purple-400">{tc.expandAll}</button>
              </div>
            )}
          </div>

          <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto max-w-6xl">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 dark:bg-gray-700">
                <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                  <th className="px-4 py-3 font-medium">{tc.number}</th>
                  <th className="px-4 py-3 font-medium">{tc.label}</th>
                  <th className="px-4 py-3 font-medium">{tc.nature}</th>
                  <th className="px-4 py-3 font-medium">{tc.type}</th>
                  <th className="px-4 py-3 font-medium">{tc.manualEntry}</th>
                  <th className="px-4 py-3 font-medium">{tc.statusLabel}</th>
                  {peutModifier && <th className="px-4 py-3" />}
                </tr>
              </thead>
              <tbody>
                {visibles.length === 0 && <tr><td colSpan={7} className="px-4 py-6 text-center text-gray-500">{tc.noResult}</td></tr>}
                {visibles.map(({ compte, profondeur, aDesEnfants }) => {
                  const replie = replies.has(compte.id);
                  const enTete = compte.type === 'en_tete';
                  return (
                    <tr key={compte.id} className={`border-t dark:border-gray-700 ${compte.actif ? '' : 'opacity-60'} ${enTete ? 'bg-gray-50/60 dark:bg-gray-900/30' : ''}`}>
                      <td className="px-4 py-2 whitespace-nowrap">
                        <div className="flex items-center gap-1" style={{ paddingLeft: filtre ? 0 : profondeur * 16 }}>
                          {!filtre && aDesEnfants ? (
                            <button onClick={() => setReplies(r => { const s = new Set(r); if (s.has(compte.id)) s.delete(compte.id); else s.add(compte.id); return s; })}
                              aria-label={`${replie ? tc.expand : tc.collapse} ${compte.numero}`} aria-expanded={!replie}
                              className="p-0.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700 rounded">
                              {replie ? <ChevronRight size={14} /> : <ChevronDown size={14} />}
                            </button>
                          ) : !filtre && <span className="w-[18px]" />}
                          <span className={`font-mono ${enTete ? 'font-semibold' : ''} text-gray-900 dark:text-white`}>{compte.numero}</span>
                        </div>
                      </td>
                      <td className={`px-4 py-2 ${enTete ? 'font-semibold' : ''} text-gray-800 dark:text-gray-200`}>{compte.libelle}</td>
                      <td className="px-4 py-2 whitespace-nowrap"><span className={`px-2 py-0.5 rounded text-xs font-medium ${COULEURS_NATURE[compte.nature]}`}>{ta.natures[compte.nature]}</span></td>
                      <td className="px-4 py-2 text-gray-600 dark:text-gray-300 whitespace-nowrap">{compte.type === 'standard' ? '' : ta.types[compte.type]}</td>
                      <td className="px-4 py-2 text-gray-600 dark:text-gray-300">{compte.saisie_manuelle ? '✓' : '—'}</td>
                      <td className="px-4 py-2 whitespace-nowrap">
                        <span className={`px-2 py-0.5 rounded text-xs font-medium ${compte.actif ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'}`}>{compte.actif ? tc.active : tc.inactive}</span>
                      </td>
                      {peutModifier && (
                        <td className="px-4 py-2">
                          <div className="flex justify-end gap-1">
                            <button onClick={() => ouvrirEdition(null, compte)} title={tc.addChild} aria-label={`${tc.addChild} — ${compte.numero}`} className="p-1 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/30 rounded"><Plus size={16} /></button>
                            <button onClick={() => ouvrirEdition(compte)} title={tc.edit} aria-label={`${tc.edit} — ${compte.numero}`} className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded"><Pencil size={16} /></button>
                            <button onClick={() => basculer(compte)} title={compte.actif ? tc.deactivate : tc.reactivate} aria-label={`${compte.actif ? tc.deactivate : tc.reactivate} — ${compte.numero}`} className="p-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded"><Power size={16} /></button>
                            <button onClick={() => supprimer(compte)} title={tc.delete} aria-label={`${tc.delete} — ${compte.numero}`} className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><Trash2 size={16} /></button>
                          </div>
                        </td>
                      )}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </>
      )}

      {edition && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <form onSubmit={enregistrer} className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto space-y-4">
            <div className="flex items-start justify-between gap-3">
              <h2 className="text-lg font-bold dark:text-white">{edition.compte ? tc.editAccount.replace('{numero}', edition.compte.numero) : tc.newAccount}</h2>
              <button type="button" onClick={() => setEdition(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label={tc.closeDialog}><X size={18} /></button>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label htmlFor="compte-numero" className={classeLibelle}>{tc.number}</label>
                <input id="compte-numero" required maxLength={20} className={`${classeChamp} font-mono`} value={edition.saisie.numero} onChange={e => modifierSaisie({ numero: e.target.value })} />
              </div>
              <div className="sm:col-span-2">
                <label htmlFor="compte-libelle" className={classeLibelle}>{tc.label}</label>
                <input id="compte-libelle" required maxLength={150} className={classeChamp} value={edition.saisie.libelle} onChange={e => modifierSaisie({ libelle: e.target.value })} />
              </div>
            </div>
            <div>
              <label htmlFor="compte-parent" className={classeLibelle}>{tc.parent}</label>
              <select id="compte-parent" className={classeChamp} value={edition.saisie.parent_id ?? ''}
                onChange={e => {
                  const parent = (comptes ?? []).find(c => c.id === Number(e.target.value));
                  modifierSaisie({ parent_id: parent?.id ?? null, ...(edition.compte ? {} : { nature: parent?.nature ?? edition.saisie.nature }) });
                }}>
                <option value="">{tc.noParent}</option>
                {parents.map(({ compte, profondeur }) => (
                  <option key={compte.id} value={compte.id}>{`${'\u00a0\u00a0'.repeat(profondeur)}${compte.numero} — ${compte.libelle}`}</option>
                ))}
              </select>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label htmlFor="compte-nature" className={classeLibelle}>{tc.nature}</label>
                <select id="compte-nature" className={classeChamp} value={edition.saisie.nature} onChange={e => modifierSaisie({ nature: e.target.value as NatureCompte })}>
                  {NATURES.map(n => <option key={n} value={n}>{ta.natures[n]}</option>)}
                </select>
              </div>
              <div>
                <label htmlFor="compte-type" className={classeLibelle}>{tc.type}</label>
                <select id="compte-type" className={classeChamp} value={edition.saisie.type}
                  onChange={e => modifierSaisie({ type: e.target.value as SaisieCompte['type'], ...(e.target.value === 'en_tete' ? { saisie_manuelle: false } : {}) })}>
                  {TYPES_COMPTE.map(type => <option key={type} value={type}>{ta.types[type]}</option>)}
                </select>
              </div>
            </div>
            <p className="text-xs text-gray-500 dark:text-gray-400">{ta.typeHelp[edition.saisie.type]}</p>
            <div className="flex flex-wrap gap-x-6 gap-y-2">
              <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300" title={tc.manualEntryHelp}>
                <input type="checkbox" className="rounded" disabled={edition.saisie.type === 'en_tete'} checked={edition.saisie.saisie_manuelle} onChange={e => modifierSaisie({ saisie_manuelle: e.target.checked })} />
                {tc.manualEntry}
              </label>
              <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" className="rounded" checked={edition.saisie.actif} onChange={e => modifierSaisie({ actif: e.target.checked })} /> {tc.active}
              </label>
            </div>
            <div>
              <label htmlFor="compte-notes" className={classeLibelle}>{tc.notes}</label>
              <textarea id="compte-notes" rows={2} maxLength={2000} className={classeChamp} value={edition.saisie.notes ?? ''} onChange={e => modifierSaisie({ notes: e.target.value || null })} />
            </div>
            {erreurEdition && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreurEdition}</p>}
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setEdition(null)} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{tc.cancel}</button>
              <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{tc.save}</button>
            </div>
          </form>
        </div>
      )}

      {importation && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto space-y-4">
            <div className="flex items-start justify-between gap-3">
              <h2 className="text-lg font-bold dark:text-white">{importation === 'plan' ? tc.templateTitle : tc.fileTitle}</h2>
              <button type="button" onClick={() => setImportation(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label={tc.closeDialog}><X size={18} /></button>
            </div>
            {importation === 'plan' ? (
              <>
                <p className="text-sm text-gray-600 dark:text-gray-300">{tc.templateHelp}</p>
                <fieldset className="space-y-2">
                  <legend className="sr-only">{tc.templateTitle}</legend>
                  {plans.map(plan => (
                    <label key={plan.code} className={`flex gap-3 p-3 border rounded-lg cursor-pointer dark:border-gray-600 ${planChoisi === plan.code ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20' : ''}`}>
                      <input type="radio" name="plan-type" className="mt-1" checked={planChoisi === plan.code} onChange={() => setPlanChoisi(plan.code)} />
                      <span>
                        <span className="block font-medium text-gray-900 dark:text-white">{plan.libelle}</span>
                        <span className="block text-xs text-gray-500 dark:text-gray-400">{plan.description} {tc.accounts.replace('{n}', String(plan.nombre_comptes))}</span>
                      </span>
                    </label>
                  ))}
                </fieldset>
                <div className="flex justify-end gap-2">
                  <button type="button" onClick={() => setImportation(null)} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{tc.cancel}</button>
                  <button type="button" disabled={enCours || !planChoisi} onClick={() => importerPlan(planChoisi, true)} className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">{tc.complete}</button>
                </div>
              </>
            ) : (
              <form onSubmit={importerFichier} className="space-y-4">
                <p className="text-sm text-gray-600 dark:text-gray-300">{tc.fileHelp}</p>
                <pre className="text-xs bg-gray-50 dark:bg-gray-900 dark:text-gray-200 rounded p-2 overflow-x-auto">{'numero;libelle;nature;type;parent\n40;Fournisseurs;passif;en_tete;\n401;Fournisseurs locaux;passif;fournisseurs;40'}</pre>
                <input type="file" accept=".csv,.txt,text/csv" required aria-label={tc.fileTitle} onChange={e => setFichier(e.target.files?.[0] ?? null)}
                  className="block w-full text-sm text-gray-700 dark:text-gray-300" />
                {erreursImport.length > 0 && (
                  <div className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">
                    <p className="font-medium">{tc.fileErrors}</p>
                    <ul className="list-disc pl-5 mt-1 space-y-0.5">{erreursImport.map(erreur => <li key={erreur}>{erreur}</li>)}</ul>
                  </div>
                )}
                <div className="flex justify-end gap-2">
                  <button type="button" onClick={() => setImportation(null)} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{tc.cancel}</button>
                  <button type="submit" disabled={enCours || !fichier} className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">{tc.import}</button>
                </div>
              </form>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
