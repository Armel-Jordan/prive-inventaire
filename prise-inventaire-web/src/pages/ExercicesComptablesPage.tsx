import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Archive, ArchiveRestore, CalendarRange, ChevronDown, ChevronRight, Landmark, Lock, LockOpen, Pencil, Plus, Trash2, X } from 'lucide-react';
import {
  getExercices, creerExercice, modifierExercice, supprimerExercice, fermerPeriode, rouvrirPeriode,
  getApercuCloture, cloturerExercice, rouvrirExercice, montant,
  DECOUPAGES, type ApercuCloture, type ExerciceComptable, type PeriodeComptable, type SaisieExercice, type Decoupage,
} from '@/services/comptabilite';
import { useAuth } from '@/contexts/AuthContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm disabled:opacity-60';
const classeLibelle = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';

/** « AAAA-MM-JJ » d'une date locale. */
function versIso(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** Date locale d'un « AAAA-MM-JJ » (sans passer par UTC, qui décalerait d'un jour à l'ouest de Greenwich). */
function depuisIso(iso: string): Date {
  const [annee, mois, jour] = iso.slice(0, 10).split('-').map(Number);
  return new Date(annee, mois - 1, jour);
}

/** Exercice qui suit le dernier (ou l'année civile en cours) : douze mois. */
function exerciceSuivant(exercices: ExerciceComptable[]): SaisieExercice {
  const dernier = exercices.reduce<ExerciceComptable | null>((max, e) => (!max || e.date_fin > max.date_fin ? e : max), null);
  const debut = dernier ? depuisIso(dernier.date_fin) : new Date(new Date().getFullYear(), 0, 0);
  debut.setDate(debut.getDate() + 1);
  const fin = new Date(debut.getFullYear() + 1, debut.getMonth(), debut.getDate() - 1);
  return { libelle: '', date_debut: versIso(debut), date_fin: versIso(fin), decoupage: 'mensuel' };
}

/** Exercices comptables et leurs périodes : création, découpage, fermeture et réouverture des périodes. */
export default function ExercicesComptablesPage() {
  const { t, language } = useLanguage();
  const ty = t.accounting.years;
  const { user } = useAuth();
  const peutModifier = user?.role === 'admin' || user?.role === 'manager';
  const estAdmin = user?.role === 'admin';
  const locale = language === 'en' ? 'en-US' : 'fr-FR';

  const [exercices, setExercices] = useState<ExerciceComptable[] | null>(null);
  const [version, setVersion] = useState(0);
  const [message, setMessage] = useState<{ erreur: boolean; texte: string } | null>(null);
  const [deplies, setDeplies] = useState<Set<number> | null>(null);
  const [edition, setEdition] = useState<{ exercice: ExerciceComptable | null; saisie: SaisieExercice } | null>(null);
  const [erreurEdition, setErreurEdition] = useState('');
  const [cloture, setCloture] = useState<{ exercice: ExerciceComptable; apercu: ApercuCloture } | null>(null);
  const [enCours, setEnCours] = useState(false);

  useEffect(() => {
    let actif = true;
    getExercices()
      .then(({ exercices: liste }) => {
        if (!actif) return;
        setExercices(liste);
        // Au premier affichage : l'exercice en cours (sinon le plus récent) est déplié.
        setDeplies(d => d ?? new Set([(liste.find(e => e.periodes.some(p => p.courante)) ?? liste[0])?.id].filter((id): id is number => id !== undefined)));
      })
      .catch(e => { if (actif) setMessage({ erreur: true, texte: messageErreur(e, ty.error) }); });
    return () => { actif = false; };
  }, [version, ty.error]);

  const date = (iso: string) => depuisIso(iso).toLocaleDateString(locale);
  const recharger = (texte: string) => { setMessage({ erreur: false, texte }); setVersion(v => v + 1); };
  const echec = (e: unknown) => setMessage({ erreur: true, texte: messageErreur(e, ty.error) });
  const basculer = (id: number) => setDeplies(d => { const s = new Set(d); if (s.has(id)) s.delete(id); else s.add(id); return s; });

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    if (!edition) return;
    setErreurEdition('');
    try {
      const exercice = edition.exercice ? await modifierExercice(edition.exercice.id, edition.saisie) : await creerExercice(edition.saisie);
      setDeplies(d => new Set([...(d ?? []), exercice.id]));
      setEdition(null);
      recharger(ty.saved);
    } catch (erreur) {
      setErreurEdition(messageErreur(erreur, ty.error));
    }
  }

  async function supprimer(exercice: ExerciceComptable) {
    if (!confirm(ty.confirmDelete.replace('{libelle}', exercice.libelle))) return;
    try {
      await supprimerExercice(exercice.id);
      recharger(ty.deleted);
    } catch (e) {
      echec(e);
    }
  }

  async function changerPeriode(periode: PeriodeComptable) {
    if (!periode.fermee && !confirm(ty.confirmClose.replace('{numero}', String(periode.numero)).replace('{debut}', date(periode.date_debut)).replace('{fin}', date(periode.date_fin)))) return;
    try {
      if (periode.fermee) await rouvrirPeriode(periode.id);
      else await fermerPeriode(periode.id);
      recharger(periode.fermee ? ty.periodReopened : ty.periodClosed);
    } catch (e) {
      echec(e);
    }
  }

  async function ouvrirCloture(exercice: ExerciceComptable) {
    try {
      setCloture({ exercice, apercu: await getApercuCloture(exercice.id) });
    } catch (e) {
      echec(e);
    }
  }

  async function cloturer() {
    if (!cloture) return;
    setEnCours(true);
    try {
      await cloturerExercice(cloture.exercice.id);
      recharger(ty.yearClosedMsg.replace('{libelle}', cloture.exercice.libelle));
      setCloture(null);
    } catch (e) {
      setCloture(null);
      echec(e);
    } finally {
      setEnCours(false);
    }
  }

  async function rouvrir(exercice: ExerciceComptable) {
    if (!confirm(ty.confirmReopenYear.replace('{libelle}', exercice.libelle))) return;
    try {
      await rouvrirExercice(exercice.id);
      recharger(ty.yearReopenedMsg.replace('{libelle}', exercice.libelle));
    } catch (e) {
      echec(e);
    }
  }

  const modifierSaisie = (valeurs: Partial<SaisieExercice>) => setEdition(ed => (ed ? { ...ed, saisie: { ...ed.saisie, ...valeurs } } : ed));
  const verrouille = !!edition?.exercice?.periodes.some(p => p.fermee);
  // Seul le dernier exercice clôturé peut être rouvert.
  const dernierCloture = exercices?.filter(e => e.statut === 'cloture').sort((a, b) => b.date_debut.localeCompare(a.date_debut))[0]?.id;

  return (
    <div className="p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><CalendarRange className="text-purple-600" /> {ty.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{ty.subtitle}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {exercices && exercices.length > 0 && (
            <Link to="/soldes-ouverture" className="flex items-center gap-2 px-3 py-2 text-sm border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
              <Landmark size={16} /> {ty.openingLink}
            </Link>
          )}
          {peutModifier && exercices && (
            <button onClick={() => { setErreurEdition(''); setEdition({ exercice: null, saisie: exerciceSuivant(exercices) }); }}
              className="flex items-center gap-2 px-3 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">
              <Plus size={16} /> {ty.add}
            </button>
          )}
        </div>
      </div>

      {message && <p role="status" className={`mb-4 px-3 py-2 rounded-lg text-sm max-w-5xl ${message.erreur ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}>{message.texte}</p>}
      {exercices && exercices.length === 0 && <p className="text-sm text-gray-500 dark:text-gray-400 max-w-5xl">{ty.empty}</p>}

      <div className="space-y-4 max-w-5xl">
        {exercices?.map(exercice => {
          const deplie = deplies?.has(exercice.id) ?? false;
          const fermees = exercice.periodes.filter(p => p.fermee).length;
          return (
            <section key={exercice.id} aria-labelledby={`exercice-${exercice.id}`} className="bg-white dark:bg-gray-800 rounded-lg shadow-md">
              <div className="flex flex-wrap items-center justify-between gap-3 p-4">
                <button onClick={() => basculer(exercice.id)} aria-expanded={deplie} className="flex items-center gap-2 text-left">
                  {deplie ? <ChevronDown size={18} className="text-gray-500" /> : <ChevronRight size={18} className="text-gray-500" />}
                  <span>
                    <span id={`exercice-${exercice.id}`} className="block font-semibold text-gray-900 dark:text-white">{exercice.libelle}</span>
                    <span className="block text-sm text-gray-500 dark:text-gray-400">
                      {date(exercice.date_debut)} – {date(exercice.date_fin)} · {ty.splittings[exercice.decoupage]} · {ty.closedCount.replace('{fermees}', String(fermees)).replace('{total}', String(exercice.periodes.length))}
                    </span>
                    {exercice.statut === 'cloture' && exercice.cloture_le && (
                      <span className="block text-xs text-gray-500 dark:text-gray-400">
                        {ty.closedOn.replace('{date}', new Date(exercice.cloture_le).toLocaleDateString(locale)).replace('{nom}', exercice.cloture_par ?? '—')}
                      </span>
                    )}
                  </span>
                </button>
                <div className="flex items-center gap-2">
                  <span className={`px-2 py-0.5 rounded text-xs font-medium ${exercice.statut === 'cloture' ? 'bg-gray-100 text-gray-700' : 'bg-green-100 text-green-800'}`}>
                    {exercice.statut === 'cloture' ? ty.yearClosed : ty.yearOpen}
                  </span>
                  {peutModifier && exercice.statut === 'ouvert' && (
                    <>
                      <button onClick={() => { setErreurEdition(''); setEdition({ exercice, saisie: { libelle: exercice.libelle, date_debut: exercice.date_debut, date_fin: exercice.date_fin, decoupage: exercice.decoupage } }); }}
                        title={ty.edit} aria-label={`${ty.edit} — ${exercice.libelle}`} className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded"><Pencil size={16} /></button>
                      {fermees === 0 && (
                        <button onClick={() => supprimer(exercice)} title={ty.delete} aria-label={`${ty.delete} — ${exercice.libelle}`} className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><Trash2 size={16} /></button>
                      )}
                    </>
                  )}
                  {estAdmin && exercice.statut === 'ouvert' && (
                    <button onClick={() => ouvrirCloture(exercice)} className="flex items-center gap-1 px-2 py-1 text-xs border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                      <Archive size={14} /> {ty.closeYear}
                    </button>
                  )}
                  {estAdmin && exercice.id === dernierCloture && (
                    <button onClick={() => rouvrir(exercice)} className="flex items-center gap-1 px-2 py-1 text-xs border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                      <ArchiveRestore size={14} /> {ty.reopenYear}
                    </button>
                  )}
                </div>
              </div>
              {deplie && (
                <div className="overflow-x-auto border-t dark:border-gray-700">
                  <table className="w-full text-sm">
                    <thead className="bg-gray-50 dark:bg-gray-700">
                      <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                        <th className="px-4 py-2 font-medium">{ty.period}</th>
                        <th className="px-4 py-2 font-medium">{ty.start}</th>
                        <th className="px-4 py-2 font-medium">{ty.end}</th>
                        <th className="px-4 py-2 font-medium">{ty.status}</th>
                        {peutModifier && <th className="px-4 py-2" />}
                      </tr>
                    </thead>
                    <tbody>
                      {exercice.periodes.map(periode => (
                        <tr key={periode.id} className={`border-t dark:border-gray-700 ${periode.courante ? 'bg-purple-50/60 dark:bg-purple-900/20' : ''}`}>
                          <td className="px-4 py-2 text-gray-900 dark:text-white">{periode.numero}</td>
                          <td className="px-4 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">{date(periode.date_debut)}</td>
                          <td className="px-4 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">{date(periode.date_fin)}</td>
                          <td className="px-4 py-2">
                            <div className="flex flex-wrap items-center gap-2">
                              <span className={`px-2 py-0.5 rounded text-xs font-medium ${periode.fermee ? 'bg-gray-100 text-gray-700' : 'bg-green-100 text-green-800'}`}>{periode.fermee ? ty.closed : ty.open}</span>
                              {periode.courante && <span className="px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">{ty.current}</span>}
                              {periode.fermee && periode.fermee_le && (
                                <span className="text-xs text-gray-500 dark:text-gray-400">
                                  {ty.closedBy.replace('{date}', new Date(periode.fermee_le).toLocaleDateString(locale)).replace('{nom}', periode.fermee_par ?? '—')}
                                </span>
                              )}
                            </div>
                          </td>
                          {peutModifier && (
                            <td className="px-4 py-2 text-right">
                              {exercice.statut === 'ouvert' && (!periode.fermee || estAdmin) && (
                                <button onClick={() => changerPeriode(periode)} className="inline-flex items-center gap-1 px-2 py-1 text-xs border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                  {periode.fermee ? <LockOpen size={14} /> : <Lock size={14} />} {periode.fermee ? ty.reopen : ty.close}
                                </button>
                              )}
                            </td>
                          )}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>
          );
        })}
      </div>

      {edition && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <form onSubmit={enregistrer} className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md max-h-[90vh] overflow-y-auto space-y-4">
            <div className="flex items-start justify-between gap-3">
              <h2 className="text-lg font-bold dark:text-white">{edition.exercice ? ty.editTitle.replace('{libelle}', edition.exercice.libelle) : ty.newTitle}</h2>
              <button type="button" onClick={() => setEdition(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label={ty.closeDialog}><X size={18} /></button>
            </div>
            <div>
              <label htmlFor="exercice-libelle" className={classeLibelle}>{ty.label}</label>
              <input id="exercice-libelle" maxLength={50} className={classeChamp} value={edition.saisie.libelle} onChange={e => modifierSaisie({ libelle: e.target.value })} />
              <p className="text-xs text-gray-500 mt-1">{ty.labelHelp}</p>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label htmlFor="exercice-debut" className={classeLibelle}>{ty.start}</label>
                <input id="exercice-debut" type="date" required disabled={verrouille} className={classeChamp} value={edition.saisie.date_debut} onChange={e => modifierSaisie({ date_debut: e.target.value })} />
              </div>
              <div>
                <label htmlFor="exercice-fin" className={classeLibelle}>{ty.end}</label>
                <input id="exercice-fin" type="date" required disabled={verrouille} className={classeChamp} value={edition.saisie.date_fin} onChange={e => modifierSaisie({ date_fin: e.target.value })} />
              </div>
            </div>
            <div>
              <label htmlFor="exercice-decoupage" className={classeLibelle}>{ty.splitting}</label>
              <select id="exercice-decoupage" disabled={verrouille} className={classeChamp} value={edition.saisie.decoupage} onChange={e => modifierSaisie({ decoupage: e.target.value as Decoupage })}>
                {DECOUPAGES.map(d => <option key={d} value={d}>{ty.splittings[d]}</option>)}
              </select>
              <p className="text-xs text-gray-500 mt-1">{verrouille ? ty.lockedHelp : ty.splittingHelp}</p>
            </div>
            {erreurEdition && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreurEdition}</p>}
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setEdition(null)} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{ty.cancel}</button>
              <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{ty.save}</button>
            </div>
          </form>
        </div>
      )}

      {cloture && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div role="dialog" aria-labelledby="cloture-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto space-y-4">
            <div className="flex items-start justify-between gap-3">
              <h2 id="cloture-titre" className="text-lg font-bold dark:text-white">{ty.closeTitle.replace('{libelle}', cloture.exercice.libelle)}</h2>
              <button type="button" onClick={() => setCloture(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label={ty.closeDialog}><X size={18} /></button>
            </div>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
              <dt className="text-gray-500 dark:text-gray-400">{ty.revenue}</dt>
              <dd className="text-right text-gray-900 dark:text-white">{montant(cloture.apercu.produits, locale)}</dd>
              <dt className="text-gray-500 dark:text-gray-400">{ty.expenses}</dt>
              <dd className="text-right text-gray-900 dark:text-white">{montant(cloture.apercu.charges, locale)}</dd>
              <dt className="font-semibold text-gray-800 dark:text-gray-200">{cloture.apercu.resultat >= 0 ? ty.profit : ty.loss}</dt>
              <dd className={`text-right font-semibold ${cloture.apercu.resultat >= 0 ? 'text-green-700' : 'text-red-700'}`}>{montant(Math.abs(cloture.apercu.resultat), locale)}</dd>
            </dl>
            {cloture.apercu.compte && (
              <p className="text-sm text-gray-600 dark:text-gray-300">{ty.resultAccount.replace('{compte}', `${cloture.apercu.compte.numero} — ${cloture.apercu.compte.libelle}`)}</p>
            )}
            <ul className="text-sm text-gray-600 dark:text-gray-300 list-disc pl-5 space-y-0.5">
              {ty.closeSteps.map(etape => <li key={etape}>{etape}</li>)}
            </ul>
            {cloture.apercu.bloquant && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{cloture.apercu.bloquant}</p>}
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setCloture(null)} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{ty.cancel}</button>
              <button type="button" disabled={enCours || !!cloture.apercu.bloquant} onClick={cloturer} className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">{ty.closeConfirm}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
