import { useRef, useState } from 'react';
import { Copy, ListChecks, SplitSquareHorizontal } from 'lucide-react';
import FenetreComptesBudget from '@/components/budgets/FenetreComptesBudget';
import FenetreCopierBudget from '@/components/budgets/FenetreCopierBudget';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { enregistrerCellulesBudget, repartirBudget, type ExerciceBudget, type FeuilleBudget, type LigneBudget, type PeriodeBudget } from '@/services/budgets';

const classeBouton = 'flex items-center gap-1 px-3 py-1.5 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-40';

/** Montant saisi : « 1 234,56 », « 1234.56 », « -50 » ; vide : null (cellule retirée) ; illisible : undefined. */
function lireMontant(texte: string): number | null | undefined {
  const brut = texte.replace(/\s/g, '').replace(',', '.');
  if (brut === '') return null;
  const nombre = Number(brut);
  return Number.isFinite(nombre) ? Math.round(nombre * 100) / 100 : undefined;
}

/** Libellé court d'une période : son mois (« janv. 26 »), ou ses mois de début et de fin. */
function moisPeriode(periode: PeriodeBudget, langue: string): string {
  const [ad, md] = periode.date_debut.split('-').map(Number);
  const [af, mf] = periode.date_fin.split('-').map(Number);
  const locale = langue === 'en' ? 'en-US' : 'fr-FR';
  const mois = (a: number, m: number, annee: boolean) => new Date(a, m - 1, 1).toLocaleDateString(locale, annee ? { month: 'short', year: '2-digit' } : { month: 'short' });
  return ad === af && md === mf ? mois(ad, md, true) : `${mois(ad, md, false)}–${mois(af, mf, true)}`;
}

/**
 * Feuille du budget d'un exercice : en lignes les comptes du budget, en colonnes ses périodes. Chaque cellule
 * s'enregistre en la quittant (Entrée passe à la suivante) ; un montant vide la retire. Total du compte, total de
 * l'exercice précédent pour repère, répartition d'un total, produits, charges et résultat par période.
 */
export default function GrilleBudget({ feuille, exercices, peutSaisir, onFeuille, onRecharger }: {
  feuille: FeuilleBudget & { exercice: ExerciceBudget };
  exercices: ExerciceBudget[];
  peutSaisir: boolean;
  onFeuille: (feuille: FeuilleBudget) => void;
  onRecharger: () => void;
}) {
  const { t, language } = useLanguage();
  const tb = t.budgets;
  const nombre = new Intl.NumberFormat(language === 'en' ? 'en-US' : 'fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const format = (valeur: number | null | undefined) => (valeur ? nombre.format(valeur) : '');
  const periodes = feuille.periodes ?? [];
  const comptes = feuille.comptes ?? [];
  const modifiable = peutSaisir && Boolean(feuille.modifiable);
  const [edition, setEdition] = useState<{ cle: string; texte: string } | null>(null);
  // Montants en cours d'enregistrement : la cellule les garde affichés jusqu'à la réponse.
  const [brouillons, setBrouillons] = useState<Record<string, string>>({});
  const [erreur, setErreur] = useState('');
  const [message, setMessage] = useState('');
  const [fenetre, setFenetre] = useState<'comptes' | 'copier' | null>(null);
  // Une réponse plus ancienne que la dernière appliquée est ignorée (cellules enregistrées à la suite).
  const numero = useRef(0);
  const applique = useRef(0);

  async function enregistrer(compte: LigneBudget, periode: PeriodeBudget, texte: string) {
    const cle = `${compte.id}-${periode.id}`;
    const montant = lireMontant(texte);
    if (montant === undefined) {
      setErreur(tb.invalidAmount.replace('{valeur}', texte));
      return;
    }
    if ((montant ?? 0) === (compte.montants[periode.id] ?? 0)) return;
    const ordre = ++numero.current;
    setBrouillons(b => ({ ...b, [cle]: texte }));
    setErreur('');
    try {
      const resultat = await enregistrerCellulesBudget(feuille.exercice.id, [{ compte_id: compte.id, periode_id: periode.id, montant }]);
      if (ordre >= applique.current) {
        applique.current = ordre;
        onFeuille(resultat);
      }
    } catch (e) {
      setErreur(messageErreur(e, tb.error));
    } finally {
      setBrouillons(b => {
        const suite = { ...b };
        delete suite[cle];
        return suite;
      });
    }
  }

  async function repartir(compte: LigneBudget) {
    const saisi = prompt(tb.spreadPrompt.replace('{compte}', `${compte.numero} — ${compte.libelle}`).replace('{n}', String(periodes.length)), String(compte.total || ''));
    if (saisi === null) return;
    const total = lireMontant(saisi);
    if (total === undefined) {
      setErreur(tb.invalidAmount.replace('{valeur}', saisi));
      return;
    }
    setErreur('');
    try {
      onFeuille(await repartirBudget(feuille.exercice.id, compte.id, total ?? 0));
    } catch (e) {
      setErreur(messageErreur(e, tb.error));
    }
  }

  /** Entrée valide la cellule et passe à la suivante de la ligne (puis à la ligne suivante). */
  function suivante(evenement: React.KeyboardEvent<HTMLInputElement>) {
    if (evenement.key !== 'Enter') return;
    evenement.preventDefault();
    const champs = Array.from(document.querySelectorAll<HTMLInputElement>('input[data-cellule-budget]'));
    const index = champs.indexOf(evenement.currentTarget);
    (champs[index + 1] ?? evenement.currentTarget).focus();
    if (!champs[index + 1]) evenement.currentTarget.blur();
  }

  const totaux = feuille.totaux;
  const piedDePage: { cle: 'produits' | 'charges' | 'resultat'; libelle: string }[] = [
    { cle: 'produits', libelle: tb.income },
    { cle: 'charges', libelle: tb.expenses },
    { cle: 'resultat', libelle: tb.result },
  ];

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        {peutSaisir && (
          <button type="button" onClick={() => setFenetre('comptes')} className={classeBouton}><ListChecks size={16} /> {tb.accountsButton}</button>
        )}
        {modifiable && exercices.length > 1 && (
          <button type="button" onClick={() => setFenetre('copier')} className={classeBouton}><Copy size={16} /> {tb.copyButton}</button>
        )}
        {!feuille.modifiable && (
          <span className="px-2 py-1 rounded text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{tb.closedNotice}</span>
        )}
      </div>
      <p className="text-xs text-gray-500 max-w-4xl">{tb.help}</p>
      {message && <div role="status" className="px-3 py-2 rounded-lg text-sm bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200">{message}</div>}
      {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}

      {comptes.length === 0 ? (
        <p className="p-4 text-sm text-gray-500 bg-white dark:bg-gray-800 rounded-xl shadow-sm">{peutSaisir ? tb.emptyEditable : tb.empty}</p>
      ) : (
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
          <table className="text-sm border-separate border-spacing-0" aria-label={tb.sheet}>
            <thead className="text-xs text-gray-600 dark:text-gray-300">
              <tr>
                <th className="sticky left-0 z-10 bg-gray-50 dark:bg-gray-700 px-3 py-2 text-left min-w-[240px] border-b dark:border-gray-600">{tb.account}</th>
                {periodes.map(p => (
                  <th key={p.id} className="bg-gray-50 dark:bg-gray-700/50 px-2 py-2 text-right whitespace-nowrap border-b dark:border-gray-600" title={`${p.date_debut} → ${p.date_fin}`}>
                    {tb.periodN.replace('{n}', String(p.numero))}<span className="block font-normal text-gray-500">{moisPeriode(p, language)}</span>
                  </th>
                ))}
                <th className="bg-gray-50 dark:bg-gray-700/50 px-3 py-2 text-right border-b dark:border-gray-600">{tb.total}</th>
                {feuille.precedent && <th className="bg-gray-50 dark:bg-gray-700/50 px-3 py-2 text-right whitespace-nowrap border-b dark:border-gray-600" title={tb.previousHelp}>{feuille.precedent.libelle}</th>}
                {modifiable && <th className="bg-gray-50 dark:bg-gray-700/50 border-b dark:border-gray-600" />}
              </tr>
            </thead>
            <tbody className="dark:text-gray-200">
              {comptes.map(c => (
                <tr key={c.id} className="hover:bg-gray-50/60 dark:hover:bg-gray-700/30">
                  <th scope="row" className="sticky left-0 z-10 bg-white dark:bg-gray-800 px-3 py-1 text-left font-normal border-b dark:border-gray-700">
                    <span className="font-mono text-xs">{c.numero}</span> {c.libelle}
                    <span className="block text-xs text-gray-500">{t.accounting.natures[c.nature]}{c.budgetable ? '' : ` · ${tb.removed}`}</span>
                  </th>
                  {periodes.map(p => {
                    const cle = `${c.id}-${p.id}`;
                    return (
                      <td key={p.id} className="px-1 py-1 border-b dark:border-gray-700">
                        {modifiable ? (
                          <input data-cellule-budget value={edition?.cle === cle ? edition.texte : (brouillons[cle] ?? format(c.montants[p.id]))} inputMode="decimal"
                            aria-label={`${c.numero} ${tb.periodN.replace('{n}', String(p.numero))}`}
                            onFocus={() => setEdition({ cle, texte: c.montants[p.id] ? String(c.montants[p.id]).replace('.', language === 'en' ? '.' : ',') : '' })}
                            onChange={e => setEdition({ cle, texte: e.target.value })}
                            onBlur={e => { setEdition(null); enregistrer(c, p, e.target.value); }}
                            onKeyDown={suivante}
                            className={`w-24 px-2 py-1 text-right border rounded dark:bg-gray-700 dark:border-gray-600 ${brouillons[cle] !== undefined ? 'opacity-50' : ''}`} />
                        ) : (
                          <span className="block w-24 px-2 py-1 text-right">{format(c.montants[p.id])}</span>
                        )}
                      </td>
                    );
                  })}
                  <td className="px-3 py-1 text-right font-medium whitespace-nowrap border-b dark:border-gray-700">{format(c.total)}</td>
                  {feuille.precedent && <td className="px-3 py-1 text-right text-gray-500 whitespace-nowrap border-b dark:border-gray-700">{format(c.total_precedent)}</td>}
                  {modifiable && (
                    <td className="px-2 py-1 border-b dark:border-gray-700">
                      <button type="button" onClick={() => repartir(c)} title={tb.spreadHelp} aria-label={`${tb.spread} ${c.numero}`}
                        className="p-1 rounded text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700"><SplitSquareHorizontal size={16} /></button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
            {totaux && (
              <tfoot className="font-semibold dark:text-white">
                {piedDePage.map(({ cle, libelle }) => (
                  <tr key={cle} className={cle === 'resultat' ? 'bg-gray-50 dark:bg-gray-700/40' : ''}>
                    <th scope="row" className="sticky left-0 z-10 bg-gray-50 dark:bg-gray-700 px-3 py-2 text-left">{libelle}</th>
                    {periodes.map(p => (
                      <td key={p.id} className={`px-3 py-2 text-right whitespace-nowrap ${cle === 'resultat' && (totaux.par_periode[p.id]?.resultat ?? 0) < 0 ? 'text-red-700 dark:text-red-300' : ''}`}>
                        {format(totaux.par_periode[p.id]?.[cle])}
                      </td>
                    ))}
                    <td className={`px-3 py-2 text-right whitespace-nowrap ${cle === 'resultat' && totaux.resultat < 0 ? 'text-red-700 dark:text-red-300' : ''}`}>{format(totaux[cle])}</td>
                    {feuille.precedent && <td />}
                    {modifiable && <td />}
                  </tr>
                ))}
              </tfoot>
            )}
          </table>
        </div>
      )}

      {fenetre === 'comptes' && (
        <FenetreComptesBudget onClose={() => setFenetre(null)} onEnregistre={texte => { setFenetre(null); setMessage(texte); onRecharger(); }} />
      )}
      {fenetre === 'copier' && (
        <FenetreCopierBudget exercice={feuille.exercice} exercices={exercices} precedentId={feuille.precedent?.id ?? null} onClose={() => setFenetre(null)}
          onCopie={resultat => { setFenetre(null); setMessage(resultat.message); onFeuille(resultat); }} />
      )}
    </div>
  );
}
