import { useEffect, useMemo, useState } from 'react';
import { BookText, ChevronDown, ChevronRight, Download, Pencil, Plus, Repeat, Settings2, Trash2, Undo2, X } from 'lucide-react';
import {
  getComptes, getExercices, getJournaux, getEcritures, enregistrerEcriture, supprimerEcriture, contrePasserEcriture, exporterEcritures,
  enregistrerJournal, supprimerJournal, montant, planifierRecurrence, TYPES_JOURNAL, FREQUENCES_RECURRENCE,
  type CompteComptable, type EcritureComptable, type FrequenceRecurrence, type JournalComptable, type PageEcritures, type SourceEcriture, type TypeJournal,
} from '@/services/comptabilite';
import { ApiError } from '@/services/http';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeLibelle = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';
const classeBouton = 'flex items-center gap-2 px-3 py-2 text-sm border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700';

interface LigneSaisie { compte_id: string; libelle: string; debit: string; credit: string }
interface Saisie { journal_id: string; date: string; libelle: string; reference: string; lignes: LigneSaisie[] }

const ligneVide = (): LigneSaisie => ({ compte_id: '', libelle: '', debit: '', credit: '' });
const nombre = (texte: string) => Math.round((Number(texte.replace(',', '.')) || 0) * 100) / 100;
const aujourdhui = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

/** Toutes les erreurs renvoyées par l'API (une par ligne fautive), sinon le message général. */
function erreursDe(e: unknown, defaut: string): string[] {
  if (e instanceof ApiError) {
    const donnees = e.data as { errors?: Record<string, string[]> } | null;
    const erreurs = Object.values(donnees?.errors ?? {}).flat();
    if (erreurs.length) return erreurs;
  }
  return [messageErreur(e, defaut)];
}

/** Journal général : écritures en partie double, filtres, saisie équilibrée, contre-passation, journaux. */
export default function JournalComptablePage() {
  const { t, language } = useLanguage();
  const ta = t.accounting;
  const tj = ta.journal;
  // Permissions de la comptabilité (rôles et permissions).
  const { peut } = usePermissions();
  const peutModifier = peut('comptabilite', 'ecrire');
  const locale = language === 'en' ? 'en-US' : 'fr-FR';

  const [comptes, setComptes] = useState<CompteComptable[]>([]);
  const [journaux, setJournaux] = useState<JournalComptable[]>([]);
  const [filtres, setFiltres] = useState<{ du: string; au: string; journal_id: string; compte_id: string; source: string; recherche: string }>({ du: '', au: '', journal_id: '', compte_id: '', source: '', recherche: '' });
  const [page, setPage] = useState(1);
  const [resultat, setResultat] = useState<PageEcritures | null>(null);
  const [version, setVersion] = useState(0);
  const [message, setMessage] = useState<{ erreur: boolean; texte: string } | null>(null);
  const [deplies, setDeplies] = useState<Set<number>>(new Set());
  const [edition, setEdition] = useState<{ ecriture: EcritureComptable | null; saisie: Saisie } | null>(null);
  const [erreursEdition, setErreursEdition] = useState<string[]>([]);
  const [inversion, setInversion] = useState<{ ecriture: EcritureComptable; date: string } | null>(null);
  const [gestionJournaux, setGestionJournaux] = useState(false);
  const [journalEdite, setJournalEdite] = useState<{ id?: number; code: string; libelle: string; type: TypeJournal; actif: boolean } | null>(null);
  const [enCours, setEnCours] = useState(false);

  const echec = (e: unknown) => setMessage({ erreur: true, texte: messageErreur(e, tj.error) });

  // Comptes, et dates de l'exercice en cours comme filtre initial.
  useEffect(() => {
    getComptes().then(setComptes).catch(e => setMessage({ erreur: true, texte: messageErreur(e, tj.error) }));
    getExercices().then(({ exercices }) => {
      const exercice = exercices.find(e => e.periodes.some(p => p.courante)) ?? exercices[0];
      if (exercice) setFiltres(f => (f.du || f.au ? f : { ...f, du: exercice.date_debut, au: exercice.date_fin }));
    }).catch(() => {});
  }, [tj.error]);

  useEffect(() => {
    getJournaux().then(setJournaux).catch(e => setMessage({ erreur: true, texte: messageErreur(e, tj.error) }));
  }, [version, tj.error]);

  useEffect(() => {
    let actif = true;
    const delai = setTimeout(() => {
      getEcritures({
        du: filtres.du || undefined, au: filtres.au || undefined, recherche: filtres.recherche.trim() || undefined,
        journal_id: filtres.journal_id ? Number(filtres.journal_id) : undefined, compte_id: filtres.compte_id ? Number(filtres.compte_id) : undefined,
        source: (filtres.source || undefined) as SourceEcriture | undefined, page,
      }).then(r => { if (actif) setResultat(r); }).catch(e => { if (actif) setMessage({ erreur: true, texte: messageErreur(e, tj.error) }); });
    }, 250);
    return () => { actif = false; clearTimeout(delai); };
  }, [filtres, page, version, tj.error]);

  const imputables = useMemo(() => comptes.filter(c => c.type !== 'en_tete' && c.actif), [comptes]);
  const date = (iso: string) => { const [a, m, j] = iso.split('-').map(Number); return new Date(a, m - 1, j).toLocaleDateString(locale); };
  const filtrer = (valeurs: Partial<typeof filtres>) => { setFiltres(f => ({ ...f, ...valeurs })); setPage(1); };
  const recharger = (texte: string) => { setMessage({ erreur: false, texte }); setVersion(v => v + 1); };

  function ouvrirSaisie(ecriture: EcritureComptable | null) {
    setErreursEdition([]);
    const journalParDefaut = journaux.find(j => j.type === 'general' && j.actif) ?? journaux.find(j => j.actif);
    setEdition({
      ecriture,
      saisie: ecriture
        ? {
          journal_id: String(ecriture.journal?.id ?? ''), date: ecriture.date, libelle: ecriture.libelle, reference: ecriture.reference ?? '',
          lignes: ecriture.lignes.map(l => ({ compte_id: String(l.compte_id), libelle: l.libelle ?? '', debit: l.debit ? String(l.debit) : '', credit: l.credit ? String(l.credit) : '' })),
        }
        : { journal_id: String(journalParDefaut?.id ?? ''), date: aujourdhui(), libelle: '', reference: '', lignes: [ligneVide(), ligneVide()] },
    });
  }

  const modifierSaisie = (valeurs: Partial<Saisie>) => setEdition(ed => (ed ? { ...ed, saisie: { ...ed.saisie, ...valeurs } } : ed));
  const modifierLigne = (index: number, valeurs: Partial<LigneSaisie>) => setEdition(ed => (ed ? {
    ...ed, saisie: { ...ed.saisie, lignes: ed.saisie.lignes.map((l, i) => (i === index ? { ...l, ...valeurs } : l)) },
  } : ed));

  const totaux = edition ? edition.saisie.lignes.reduce((t, l) => ({ debit: t.debit + nombre(l.debit), credit: t.credit + nombre(l.credit) }), { debit: 0, credit: 0 }) : { debit: 0, credit: 0 };
  const ecart = Math.round((totaux.debit - totaux.credit) * 100) / 100;

  /** Porte l'écart sur la dernière ligne sans montant (ou en ajoute une). */
  function equilibrer() {
    if (!edition || ecart === 0) return;
    const lignes = [...edition.saisie.lignes];
    let index = lignes.findIndex(l => !nombre(l.debit) && !nombre(l.credit));
    if (index === -1) { lignes.push(ligneVide()); index = lignes.length - 1; }
    lignes[index] = { ...lignes[index], debit: ecart < 0 ? String(-ecart) : '', credit: ecart > 0 ? String(ecart) : '' };
    modifierSaisie({ lignes });
  }

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    if (!edition) return;
    setErreursEdition([]);
    const s = edition.saisie;
    try {
      const ecriture = await enregistrerEcriture({
        journal_id: Number(s.journal_id), date: s.date, libelle: s.libelle, reference: s.reference.trim() || null,
        lignes: s.lignes.filter(l => l.compte_id || nombre(l.debit) || nombre(l.credit))
          .map(l => ({ compte_id: Number(l.compte_id), libelle: l.libelle.trim() || null, debit: nombre(l.debit), credit: nombre(l.credit) })),
      }, edition.ecriture?.id);
      setEdition(null);
      recharger(tj.saved.replace('{numero}', ecriture.numero));
    } catch (erreur) {
      setErreursEdition(erreursDe(erreur, tj.error));
    }
  }

  async function supprimer(ecriture: EcritureComptable) {
    if (!confirm(tj.confirmDelete.replace('{numero}', ecriture.numero))) return;
    try {
      await supprimerEcriture(ecriture.id);
      recharger(tj.deleted.replace('{numero}', ecriture.numero));
    } catch (e) {
      echec(e);
    }
  }

  // Répétition d'une écriture manuelle : première date, nombre de répétitions, fréquence.
  const [repetition, setRepetition] = useState<{ ecriture: EcritureComptable; date_debut: string; nombre: string; frequence: FrequenceRecurrence } | null>(null);

  async function repeter(e: React.FormEvent) {
    e.preventDefault();
    if (!repetition) return;
    try {
      const recurrence = await planifierRecurrence(repetition.ecriture.id, {
        date_debut: repetition.date_debut, nombre: Number(repetition.nombre), frequence: repetition.frequence,
      });
      setRepetition(null);
      recharger(tj.repeatSaved.replace('{numero}', String(recurrence.numero)).replace('{n}', String(recurrence.echeances.length)));
    } catch (erreur) {
      setRepetition(null);
      echec(erreur);
    }
  }

  async function contrePasser(e: React.FormEvent) {
    e.preventDefault();
    if (!inversion) return;
    try {
      const inverse = await contrePasserEcriture(inversion.ecriture.id, inversion.date);
      setInversion(null);
      recharger(tj.reversed.replace('{numero}', inverse.numero));
    } catch (erreur) {
      setInversion(null);
      echec(erreur);
    }
  }

  async function enregistrerJournalEdite(e: React.FormEvent) {
    e.preventDefault();
    if (!journalEdite) return;
    const { id, ...journal } = journalEdite;
    try {
      await enregistrerJournal(journal, id);
      setJournalEdite(null);
      recharger(tj.journalSaved);
    } catch (erreur) {
      echec(erreur);
    }
  }

  async function retirerJournal(journal: JournalComptable) {
    if (!confirm(tj.confirmDeleteJournal.replace('{code}', journal.code))) return;
    try {
      await supprimerJournal(journal.id);
      recharger(tj.journalDeleted);
    } catch (e) {
      echec(e);
    }
  }

  async function exporter() {
    if (!filtres.du || !filtres.au) { setMessage({ erreur: true, texte: tj.exportDates }); return; }
    setEnCours(true);
    try { await exporterEcritures(filtres.du, filtres.au); } catch (e) { echec(e); } finally { setEnCours(false); }
  }

  const basculer = (id: number) => setDeplies(d => { const s = new Set(d); if (s.has(id)) s.delete(id); else s.add(id); return s; });
  const libelleCompte = (c: CompteComptable) => `${c.numero} — ${c.libelle}`;

  return (
    <div className="p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><BookText className="text-purple-600" /> {tj.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tj.subtitle}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <button onClick={exporter} disabled={enCours} className={classeBouton}><Download size={16} /> {tj.export}</button>
          {peutModifier && <button onClick={() => setGestionJournaux(true)} className={classeBouton}><Settings2 size={16} /> {tj.journals}</button>}
          {peutModifier && (
            <button onClick={() => ouvrirSaisie(null)} disabled={!journaux.length} className="flex items-center gap-2 px-3 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">
              <Plus size={16} /> {tj.add}
            </button>
          )}
        </div>
      </div>

      {message && <p role="status" className={`mb-4 px-3 py-2 rounded-lg text-sm max-w-6xl ${message.erreur ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}>{message.texte}</p>}

      <div className="grid grid-cols-2 md:grid-cols-6 gap-2 mb-4 max-w-6xl">
        <label className="text-xs text-gray-500 dark:text-gray-400">{tj.from}
          <input type="date" value={filtres.du} onChange={e => filtrer({ du: e.target.value })} className={classeChamp} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tj.to}
          <input type="date" value={filtres.au} onChange={e => filtrer({ au: e.target.value })} className={classeChamp} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tj.journal}
          <select value={filtres.journal_id} onChange={e => filtrer({ journal_id: e.target.value })} className={classeChamp}>
            <option value="">{tj.allJournals}</option>
            {journaux.map(j => <option key={j.id} value={j.id}>{j.code} — {j.libelle}</option>)}
          </select>
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tj.account}
          <select value={filtres.compte_id} onChange={e => filtrer({ compte_id: e.target.value })} className={classeChamp}>
            <option value="">{tj.allAccounts}</option>
            {comptes.filter(c => c.type !== 'en_tete').map(c => <option key={c.id} value={c.id}>{libelleCompte(c)}</option>)}
          </select>
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tj.source}
          <select value={filtres.source} onChange={e => filtrer({ source: e.target.value })} className={classeChamp}>
            <option value="">{tj.allSources}</option>
            {(['manuelle', 'ouverture', 'cloture', 'recurrente'] as const).map(s => <option key={s} value={s}>{tj.sources[s]}</option>)}
          </select>
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tj.search}
          <input value={filtres.recherche} onChange={e => filtrer({ recherche: e.target.value })} placeholder={tj.searchHelp} className={classeChamp} />
        </label>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto max-w-6xl">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-4 py-3 font-medium">{tj.date}</th>
              <th className="px-4 py-3 font-medium">{tj.number}</th>
              <th className="px-4 py-3 font-medium">{tj.label}</th>
              <th className="px-4 py-3 font-medium text-right">{tj.total}</th>
              <th className="px-4 py-3" />
            </tr>
          </thead>
          <tbody>
            {resultat && resultat.data.length === 0 && <tr><td colSpan={5} className="px-4 py-6 text-center text-gray-500">{tj.empty}</td></tr>}
            {resultat?.data.map(ecriture => {
              const deplie = deplies.has(ecriture.id);
              return (
                <EcritureLignes key={ecriture.id} ecriture={ecriture} deplie={deplie} onBasculer={() => basculer(ecriture.id)} date={date} locale={locale}
                  textes={tj} peutModifier={peutModifier}
                  onModifier={() => ouvrirSaisie(ecriture)} onSupprimer={() => supprimer(ecriture)}
                  onContrePasser={() => setInversion({ ecriture, date: aujourdhui() })}
                  onRepeter={() => setRepetition({ ecriture, date_debut: ecriture.date, nombre: '12', frequence: 'mensuelle' })} />
              );
            })}
          </tbody>
        </table>
      </div>
      {resultat && resultat.last_page > 1 && (
        <div className="flex items-center justify-end gap-3 mt-3 text-sm max-w-6xl">
          <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="px-3 py-1.5 border rounded-lg dark:border-gray-600 dark:text-white disabled:opacity-40">{tj.previous}</button>
          <span className="text-gray-600 dark:text-gray-300">{tj.pageOf.replace('{page}', String(resultat.current_page)).replace('{total}', String(resultat.last_page))}</span>
          <button disabled={page >= resultat.last_page} onClick={() => setPage(p => p + 1)} className="px-3 py-1.5 border rounded-lg dark:border-gray-600 dark:text-white disabled:opacity-40">{tj.next}</button>
        </div>
      )}

      {edition && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <form onSubmit={enregistrer} className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-4xl max-h-[92vh] overflow-y-auto space-y-4">
            <div className="flex items-start justify-between gap-3">
              <h2 className="text-lg font-bold dark:text-white">{edition.ecriture ? tj.editTitle.replace('{numero}', edition.ecriture.numero) : tj.newTitle}</h2>
              <button type="button" onClick={() => setEdition(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label={tj.closeDialog}><X size={18} /></button>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
              <div>
                <label htmlFor="ecriture-journal" className={classeLibelle}>{tj.journal}</label>
                <select id="ecriture-journal" required className={classeChamp} value={edition.saisie.journal_id} onChange={e => modifierSaisie({ journal_id: e.target.value })}>
                  {journaux.filter(j => j.actif || String(j.id) === edition.saisie.journal_id).map(j => <option key={j.id} value={j.id}>{j.code} — {j.libelle}</option>)}
                </select>
              </div>
              <div>
                <label htmlFor="ecriture-date" className={classeLibelle}>{tj.date}</label>
                <input id="ecriture-date" type="date" required className={classeChamp} value={edition.saisie.date} onChange={e => modifierSaisie({ date: e.target.value })} />
              </div>
              <div className="sm:col-span-2">
                <label htmlFor="ecriture-reference" className={classeLibelle}>{tj.reference}</label>
                <input id="ecriture-reference" maxLength={100} className={classeChamp} value={edition.saisie.reference} onChange={e => modifierSaisie({ reference: e.target.value })} placeholder={tj.referenceHelp} />
              </div>
            </div>
            <div>
              <label htmlFor="ecriture-libelle" className={classeLibelle}>{tj.label}</label>
              <input id="ecriture-libelle" required maxLength={255} className={classeChamp} value={edition.saisie.libelle} onChange={e => modifierSaisie({ libelle: e.target.value })} />
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-sm min-w-[40rem]">
                <thead>
                  <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                    <th className="py-2 pr-2 font-medium w-[38%]">{tj.account}</th>
                    <th className="py-2 pr-2 font-medium">{tj.lineLabel}</th>
                    <th className="py-2 pr-2 font-medium text-right w-28">{tj.debit}</th>
                    <th className="py-2 pr-2 font-medium text-right w-28">{tj.credit}</th>
                    <th className="w-8" />
                  </tr>
                </thead>
                <tbody>
                  {edition.saisie.lignes.map((ligne, index) => (
                    <tr key={index}>
                      <td className="py-1 pr-2">
                        <select aria-label={`${tj.account} ${index + 1}`} className={classeChamp} value={ligne.compte_id} onChange={e => modifierLigne(index, { compte_id: e.target.value })}>
                          <option value="">—</option>
                          {imputables.map(c => <option key={c.id} value={c.id}>{libelleCompte(c)}</option>)}
                          {ligne.compte_id && !imputables.some(c => String(c.id) === ligne.compte_id) && (
                            <option value={ligne.compte_id}>{comptes.find(c => String(c.id) === ligne.compte_id)?.numero ?? ligne.compte_id}</option>
                          )}
                        </select>
                      </td>
                      <td className="py-1 pr-2">
                        <input aria-label={`${tj.lineLabel} ${index + 1}`} maxLength={255} className={classeChamp} value={ligne.libelle} placeholder={edition.saisie.libelle} onChange={e => modifierLigne(index, { libelle: e.target.value })} />
                      </td>
                      <td className="py-1 pr-2">
                        <input aria-label={`${tj.debit} ${index + 1}`} inputMode="decimal" className={`${classeChamp} text-right`} value={ligne.debit}
                          onChange={e => modifierLigne(index, { debit: e.target.value, ...(e.target.value ? { credit: '' } : {}) })} />
                      </td>
                      <td className="py-1 pr-2">
                        <input aria-label={`${tj.credit} ${index + 1}`} inputMode="decimal" className={`${classeChamp} text-right`} value={ligne.credit}
                          onChange={e => modifierLigne(index, { credit: e.target.value, ...(e.target.value ? { debit: '' } : {}) })} />
                      </td>
                      <td className="py-1">
                        <button type="button" disabled={edition.saisie.lignes.length <= 2} onClick={() => modifierSaisie({ lignes: edition.saisie.lignes.filter((_, i) => i !== index) })}
                          aria-label={`${tj.removeLine} ${index + 1}`} className="p-1 text-red-600 hover:bg-red-50 rounded disabled:opacity-30"><Trash2 size={16} /></button>
                      </td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="border-t dark:border-gray-700 font-medium text-gray-800 dark:text-gray-200">
                    <td className="py-2 pr-2">
                      <button type="button" onClick={() => modifierSaisie({ lignes: [...edition.saisie.lignes, ligneVide()] })} className="flex items-center gap-1 text-purple-600 hover:underline text-sm dark:text-purple-400">
                        <Plus size={14} /> {tj.addLine}
                      </button>
                    </td>
                    <td className="py-2 pr-2 text-right text-xs uppercase text-gray-500">{tj.totals}</td>
                    <td className="py-2 pr-2 text-right">{montant(totaux.debit, locale)}</td>
                    <td className="py-2 pr-2 text-right">{montant(totaux.credit, locale)}</td>
                    <td />
                  </tr>
                </tfoot>
              </table>
            </div>
            <div className={`flex flex-wrap items-center justify-between gap-2 px-3 py-2 rounded-lg text-sm ${ecart === 0 && totaux.debit > 0 ? 'bg-green-50 text-green-800' : 'bg-amber-50 text-amber-900'}`}>
              <span>{ecart === 0 ? (totaux.debit > 0 ? tj.balanced : tj.enterAmounts) : tj.difference.replace('{ecart}', montant(Math.abs(ecart), locale))}</span>
              {ecart !== 0 && <button type="button" onClick={equilibrer} className="px-2 py-1 border border-current rounded-lg">{tj.balance}</button>}
            </div>
            {erreursEdition.length > 0 && (
              <ul className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 list-disc pl-6 space-y-0.5">{erreursEdition.map(erreur => <li key={erreur}>{erreur}</li>)}</ul>
            )}
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setEdition(null)} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{tj.cancel}</button>
              <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{tj.save}</button>
            </div>
          </form>
        </div>
      )}

      {inversion && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <form onSubmit={contrePasser} className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md space-y-4">
            <h2 className="text-lg font-bold dark:text-white">{tj.reverseTitle.replace('{numero}', inversion.ecriture.numero)}</h2>
            <p className="text-sm text-gray-600 dark:text-gray-300">{tj.reverseHelp}</p>
            <div>
              <label htmlFor="inversion-date" className={classeLibelle}>{tj.date}</label>
              <input id="inversion-date" type="date" required className={classeChamp} value={inversion.date} onChange={e => setInversion({ ...inversion, date: e.target.value })} />
            </div>
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setInversion(null)} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{tj.cancel}</button>
              <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{tj.reverse}</button>
            </div>
          </form>
        </div>
      )}

      {repetition && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <form onSubmit={repeter} className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md space-y-4" aria-label={tj.repeatTitle.replace('{numero}', repetition.ecriture.numero)}>
            <h2 className="text-lg font-bold dark:text-white">{tj.repeatTitle.replace('{numero}', repetition.ecriture.numero)}</h2>
            <p className="text-sm text-gray-600 dark:text-gray-300">{tj.repeatHelp}</p>
            <div>
              <label htmlFor="repetition-debut" className={classeLibelle}>{tj.startDate}</label>
              <input id="repetition-debut" type="date" required className={classeChamp} value={repetition.date_debut} onChange={e => setRepetition({ ...repetition, date_debut: e.target.value })} />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label htmlFor="repetition-nombre" className={classeLibelle}>{tj.occurrences}</label>
                <input id="repetition-nombre" type="number" min={1} max={120} required className={classeChamp} value={repetition.nombre} onChange={e => setRepetition({ ...repetition, nombre: e.target.value })} />
              </div>
              <div>
                <label htmlFor="repetition-frequence" className={classeLibelle}>{tj.frequency}</label>
                <select id="repetition-frequence" className={classeChamp} value={repetition.frequence} onChange={e => setRepetition({ ...repetition, frequence: e.target.value as FrequenceRecurrence })}>
                  {FREQUENCES_RECURRENCE.map(f => <option key={f} value={f}>{t.recurringEntries.frequencies[f]}</option>)}
                </select>
              </div>
            </div>
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setRepetition(null)} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{tj.cancel}</button>
              <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{tj.repeat}</button>
            </div>
          </form>
        </div>
      )}

      {gestionJournaux && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto space-y-4">
            <div className="flex items-start justify-between gap-3">
              <h2 className="text-lg font-bold dark:text-white">{tj.journals}</h2>
              <button type="button" onClick={() => { setGestionJournaux(false); setJournalEdite(null); }} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label={tj.closeDialog}><X size={18} /></button>
            </div>
            <p className="text-sm text-gray-600 dark:text-gray-300">{tj.journalsHelp}</p>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                    <th className="py-2 pr-2 font-medium">{tj.code}</th>
                    <th className="py-2 pr-2 font-medium">{tj.label}</th>
                    <th className="py-2 pr-2 font-medium">{tj.type}</th>
                    <th className="py-2 pr-2 font-medium text-right">{tj.entries}</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {journaux.map(j => (
                    <tr key={j.id} className={`border-t dark:border-gray-700 ${j.actif ? '' : 'opacity-60'}`}>
                      <td className="py-2 pr-2 font-mono text-gray-900 dark:text-white">{j.code}</td>
                      <td className="py-2 pr-2 text-gray-800 dark:text-gray-200">{j.libelle}{!j.actif && ` (${tj.inactive})`}</td>
                      <td className="py-2 pr-2 text-gray-600 dark:text-gray-300">{tj.journalTypes[j.type]}</td>
                      <td className="py-2 pr-2 text-right text-gray-600 dark:text-gray-300">{j.ecritures_count ?? 0}</td>
                      <td className="py-2 text-right whitespace-nowrap">
                        <button onClick={() => setJournalEdite({ id: j.id, code: j.code, libelle: j.libelle, type: j.type, actif: j.actif })} aria-label={`${tj.edit} — ${j.code}`} className="p-1 text-blue-600 hover:bg-blue-50 rounded"><Pencil size={16} /></button>
                        {!j.ecritures_count && <button onClick={() => retirerJournal(j)} aria-label={`${tj.delete} — ${j.code}`} className="p-1 text-red-600 hover:bg-red-50 rounded"><Trash2 size={16} /></button>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {journalEdite ? (
              <form onSubmit={enregistrerJournalEdite} className="grid grid-cols-1 sm:grid-cols-4 gap-2 items-end border-t pt-4 dark:border-gray-700">
                <label className="text-xs text-gray-500 dark:text-gray-400">{tj.code}
                  <input required maxLength={10} className={`${classeChamp} font-mono uppercase`} value={journalEdite.code} onChange={e => setJournalEdite({ ...journalEdite, code: e.target.value })} />
                </label>
                <label className="text-xs text-gray-500 dark:text-gray-400 sm:col-span-2">{tj.label}
                  <input required maxLength={100} className={classeChamp} value={journalEdite.libelle} onChange={e => setJournalEdite({ ...journalEdite, libelle: e.target.value })} />
                </label>
                <label className="text-xs text-gray-500 dark:text-gray-400">{tj.type}
                  <select className={classeChamp} value={journalEdite.type} onChange={e => setJournalEdite({ ...journalEdite, type: e.target.value as TypeJournal })}>
                    {TYPES_JOURNAL.map(type => <option key={type} value={type}>{tj.journalTypes[type]}</option>)}
                  </select>
                </label>
                <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                  <input type="checkbox" className="rounded" checked={journalEdite.actif} onChange={e => setJournalEdite({ ...journalEdite, actif: e.target.checked })} /> {tj.active}
                </label>
                <div className="flex justify-end gap-2 sm:col-span-3">
                  <button type="button" onClick={() => setJournalEdite(null)} className="px-3 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{tj.cancel}</button>
                  <button type="submit" className="px-3 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{tj.save}</button>
                </div>
              </form>
            ) : (
              <button onClick={() => setJournalEdite({ code: '', libelle: '', type: 'general', actif: true })} className="flex items-center gap-1 text-purple-600 hover:underline text-sm dark:text-purple-400"><Plus size={14} /> {tj.addJournal}</button>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

interface PropsLignes {
  ecriture: EcritureComptable;
  deplie: boolean;
  onBasculer: () => void;
  date: (iso: string) => string;
  locale: string;
  textes: ReturnType<typeof useLanguage>['t']['accounting']['journal'];
  peutModifier: boolean;
  onModifier: () => void;
  onSupprimer: () => void;
  onContrePasser: () => void;
  onRepeter: () => void;
}

/** Une écriture du journal et, dépliée, ses lignes. */
function EcritureLignes({ ecriture, deplie, onBasculer, date, locale, textes: tj, peutModifier, onModifier, onSupprimer, onContrePasser, onRepeter }: PropsLignes) {
  return (
    <>
      <tr className="border-t dark:border-gray-700 align-top">
        <td className="px-4 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">
          <button onClick={onBasculer} aria-expanded={deplie} aria-label={`${deplie ? tj.collapse : tj.expand} ${ecriture.numero}`} className="inline-flex items-center gap-1">
            {deplie ? <ChevronDown size={14} /> : <ChevronRight size={14} />} {date(ecriture.date)}
          </button>
        </td>
        <td className="px-4 py-2 whitespace-nowrap font-mono text-gray-900 dark:text-white">{ecriture.numero}</td>
        <td className="px-4 py-2 text-gray-800 dark:text-gray-200">
          <span>{ecriture.libelle}</span>
          {ecriture.reference && <span className="ml-2 text-xs text-gray-500">{tj.ref} {ecriture.reference}</span>}
          <span className="flex flex-wrap gap-1 mt-0.5">
            {ecriture.source !== 'manuelle' && <span className="px-1.5 py-0.5 rounded text-xs bg-blue-100 text-blue-800">{tj.sources[ecriture.source]}</span>}
            {ecriture.contrepassation_de && <span className="px-1.5 py-0.5 rounded text-xs bg-gray-100 text-gray-700">{tj.reversalOf.replace('{numero}', ecriture.contrepassation_de)}</span>}
            {ecriture.contrepassee_par && <span className="px-1.5 py-0.5 rounded text-xs bg-amber-100 text-amber-800">{tj.reversedBy.replace('{numero}', ecriture.contrepassee_par)}</span>}
            {ecriture.recurrence && <span className="px-1.5 py-0.5 rounded text-xs bg-purple-100 text-purple-800">{tj.recurrence.replace('{numero}', String(ecriture.recurrence))}</span>}
          </span>
        </td>
        <td className="px-4 py-2 text-right whitespace-nowrap text-gray-900 dark:text-white">{montant(ecriture.total, locale)}</td>
        <td className="px-4 py-2">
          {peutModifier && (
            <div className="flex justify-end gap-1">
              {ecriture.modifiable && <button onClick={onModifier} title={tj.edit} aria-label={`${tj.edit} — ${ecriture.numero}`} className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded"><Pencil size={16} /></button>}
              {ecriture.source === 'manuelle' && !ecriture.contrepassation_de && <button onClick={onRepeter} title={tj.repeat} aria-label={`${tj.repeat} — ${ecriture.numero}`} className="p-1 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/30 rounded"><Repeat size={16} /></button>}
              {!ecriture.contrepassee_par && <button onClick={onContrePasser} title={tj.reverse} aria-label={`${tj.reverse} — ${ecriture.numero}`} className="p-1 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/30 rounded"><Undo2 size={16} /></button>}
              {ecriture.modifiable && !ecriture.contrepassee_par && <button onClick={onSupprimer} title={tj.delete} aria-label={`${tj.delete} — ${ecriture.numero}`} className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><Trash2 size={16} /></button>}
            </div>
          )}
        </td>
      </tr>
      {deplie && ecriture.lignes.map(ligne => (
        <tr key={ligne.id} className="bg-gray-50/70 dark:bg-gray-900/30 text-xs">
          <td />
          <td className="px-4 py-1 font-mono text-gray-700 dark:text-gray-300">{ligne.compte}</td>
          <td className="px-4 py-1 text-gray-600 dark:text-gray-300">{ligne.compte_libelle}{ligne.libelle && ` · ${ligne.libelle}`}</td>
          <td className="px-4 py-1 text-right whitespace-nowrap text-gray-700 dark:text-gray-300">
            {ligne.debit ? `${tj.debitShort} ${montant(ligne.debit, locale)}` : `${tj.creditShort} ${montant(ligne.credit, locale)}`}
          </td>
          <td />
        </tr>
      ))}
    </>
  );
}
