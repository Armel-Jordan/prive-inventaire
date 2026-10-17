import { useEffect, useMemo, useState } from 'react';
import { ArrowDownCircle, ArrowUpCircle, Banknote, FileText, Lock, RefreshCw, Unlock, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, formatDateHeure, useMontantDocument } from '@/lib/documents';
import { setCaissePoste, useCaissePoste } from '@/hooks/useCaissePoste';
import {
  fermerCaisse, getCaisses, getSessionCaisse, getSessionsCaisse, imprimerSommaireCaisse, mouvementCaisse, MODES_CAISSE, ouvrirCaisse,
  type CaisseEtat, type ModeCaisse, type OperationCaisse, type SessionCaisse, type SommaireCaisse, type TotauxCaisse,
} from '@/services/caisses';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeLibelle = 'block text-xs text-gray-500 dark:text-gray-400';
const classeCarte = 'bg-white dark:bg-gray-800 rounded-xl shadow p-4';
const COLONNES = ['fond', 'encaissements', 'acomptes', 'remboursements', 'entrees', 'sorties', 'attendu', 'compte', 'ecart'] as const;
type Toast = ReturnType<typeof useToast>['toast'];
type Montant = (valeur: number | string | null | undefined, devise?: string | null) => string;

/** Date du jour moins `jours`, au format AAAA-MM-JJ dans le fuseau de l'utilisateur. */
function ilYa(jours: number): string {
  const d = new Date();
  d.setDate(d.getDate() - jours);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const nonNul = (valeur: number | string | null | undefined) => valeur !== null && valeur !== undefined && Math.abs(Number(valeur)) >= 0.005;
const classeEcart = (valeur: number | string | null | undefined) => (valeur === null || valeur === undefined ? '' : nonNul(valeur) ? 'text-red-600 font-semibold' : 'text-emerald-600');

/**
 * Gestion de caisse (plan de parité fripv2, phase 1.10a) : la caisse s'ouvre avec un fond ; les paiements, acomptes et
 * remboursements saisis sur un poste de caisse entrent dans sa session ; entrées et sorties d'argent ; la fermeture fige
 * l'attendu, le compté et l'écart de chaque mode de paiement ; sommaire imprimable.
 */
export default function CaissesPage() {
  const { t } = useLanguage();
  const tc = t.cashRegisters;
  const { peut } = usePermissions();
  const gerer = peut('caisses', 'gerer');
  const montant = useMontantDocument();
  const { toasts, toast, dismiss } = useToast();
  const poste = useCaissePoste();
  const [caisses, setCaisses] = useState<CaisseEtat[] | null>(null);
  const [caisseChoisie, setCaisseChoisie] = useState<number | null>(null);
  const [periode, setPeriode] = useState({ du: ilYa(6), au: aujourdhui() });
  const [sessions, setSessions] = useState<SessionCaisse[]>([]);
  const [sessionChoisie, setSessionChoisie] = useState<number | null>(null);
  const [detail, setDetail] = useState<SommaireCaisse | null>(null);
  const [version, setVersion] = useState(0);
  const [erreur, setErreur] = useState('');
  const [dialogue, setDialogue] = useState<null | 'ouvrir' | 'entree' | 'sortie' | 'fermer'>(null);

  useEffect(() => {
    let actif = true;
    getCaisses().then(c => { if (actif) setCaisses(c); }, e => { if (actif) setErreur(messageErreur(e, tc.error)); });
    return () => { actif = false; };
  }, [version, tc.error]);

  // Caisse affichée : celle choisie, sinon celle du poste, sinon la première.
  const caisse = caisses?.find(c => c.id === caisseChoisie) ?? caisses?.find(c => c.id === poste?.id) ?? caisses?.[0] ?? null;
  const idCaisse = caisse?.id ?? null;

  useEffect(() => {
    if (!idCaisse) return;
    let actif = true;
    getSessionsCaisse({ caisse_id: idCaisse, du: periode.du, au: periode.au })
      .then(r => { if (actif) setSessions(r.sessions); }, e => { if (actif) setErreur(messageErreur(e, tc.error)); });
    return () => { actif = false; };
  }, [idCaisse, periode.du, periode.au, version, tc.error]);

  // Session détaillée : celle choisie, sinon celle en cours, sinon la plus récente.
  const idSession = sessionChoisie ?? caisse?.session?.id ?? (sessions[0]?.caisse_id === idCaisse ? sessions[0]?.id : null) ?? null;
  useEffect(() => {
    if (!idSession) return;
    let actif = true;
    getSessionCaisse(idSession).then(d => { if (actif) setDetail(d); }, e => { if (actif) setErreur(messageErreur(e, tc.error)); });
    return () => { actif = false; };
  }, [idSession, version, tc.error]);
  const detailAffiche = detail && detail.id === idSession ? detail : null;
  // Session en cours, une fois son détail chargé : les entrées, sorties et la fermeture portent sur elle.
  const sessionOuverte = detailAffiche && caisse?.session && detailAffiche.id === caisse.session.id ? detailAffiche : null;
  const sessionsAffichees = sessions.filter(s => s.caisse_id === idCaisse);

  function choisirCaisse(id: number) {
    setCaisseChoisie(id);
    setSessionChoisie(null);
    setErreur('');
  }

  function reglerPoste(valeur: string) {
    const choisie = caisses?.find(c => c.id === Number(valeur)) ?? null;
    setCaissePoste(choisie ? { id: choisie.id, code: choisie.code, nom: choisie.nom } : null);
    // La caisse affichée suit celle du poste tant qu'aucune autre n'a été choisie.
    if (caisseChoisie === null) setSessionChoisie(null);
    toast(choisie ? tc.workstationSet.replace('{code}', choisie.code) : tc.workstationCleared, 'info');
  }

  // Entrée, sortie ou fermeture : sur la session en cours, détaillée au besoin avant d'ouvrir la fenêtre.
  function agirSurSession(action: 'entree' | 'sortie' | 'fermer') {
    setSessionChoisie(caisse?.session?.id ?? null);
    setDialogue(action);
  }

  const apres = (session?: SommaireCaisse) => {
    if (session) setSessionChoisie(session.id);
    setDialogue(null);
    setVersion(v => v + 1);
  };

  const enEntreprise = (totaux: TotauxCaisse[] = []) => totaux.find(x => x.devise === null);
  const autresDevises = (totaux: TotauxCaisse[] = []) => totaux.filter(x => x.devise !== null);

  return (
    <div className="p-4 sm:p-6 space-y-4">
      <div>
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Banknote className="text-emerald-600" /> {tc.title}</h1>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tc.subtitle}</p>
      </div>
      {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      <section className={`${classeCarte} space-y-3`} aria-label={tc.register}>
        {caisses !== null && caisses.length === 0 && <p className="text-sm text-amber-800 bg-amber-50 rounded-lg px-3 py-2">{tc.noRegister}</p>}
        <div className="flex flex-wrap items-end gap-3">
          {caisses !== null && caisses.length > 0 && (
            <label className={`${classeLibelle} min-w-[14rem]`}>{tc.register}
              <select value={caisse?.id ?? ''} onChange={e => choisirCaisse(Number(e.target.value))} className={classeChamp}>
                {caisses.map(c => <option key={c.id} value={c.id}>{c.code} – {c.nom} ({c.session ? tc.open : tc.closed})</option>)}
              </select>
            </label>
          )}
          <label className={`${classeLibelle} min-w-[16rem]`}>{tc.workstation}
            <select value={poste?.id ?? ''} onChange={e => reglerPoste(e.target.value)} className={classeChamp}>
              <option value="">{tc.workstationNone}</option>
              {poste && caisses !== null && !caisses.some(c => c.id === poste.id) && <option value={poste.id}>{poste.code} – {poste.nom} ({tc.unavailable})</option>}
              {(caisses ?? []).map(c => <option key={c.id} value={c.id}>{c.code} – {c.nom}</option>)}
            </select>
          </label>
          <button type="button" onClick={() => setVersion(v => v + 1)} className="p-2 rounded-lg border hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700 dark:text-white" title={tc.refresh} aria-label={tc.refresh}>
            <RefreshCw size={16} />
          </button>
        </div>
        <p className="text-xs text-gray-500 dark:text-gray-400">{tc.workstationHelp}</p>

        {caisse && caisse.session && (
          <div className="flex flex-wrap items-center gap-3 border-t pt-3 dark:border-gray-700">
            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs bg-emerald-100 text-emerald-800"><Unlock size={12} /> {tc.open}</span>
            <span className="text-sm text-gray-600 dark:text-gray-300">
              {tc.openSince.replace('{date}', formatDateHeure(caisse.session.ouverte_le)).replace('{nom}', caisse.session.ouvreur?.nom ?? '—')}
            </span>
            <span className="text-sm font-medium text-gray-800 dark:text-white">
              {tc.expectedNow} : {montant(enEntreprise(caisse.session.totaux)?.attendu ?? 0)}
              {autresDevises(caisse.session.totaux).map(x => <span key={x.devise} className="ml-2">+ {montant(x.attendu, x.devise)}</span>)}
            </span>
            {gerer && (
              <div className="flex flex-wrap gap-2 ml-auto">
                <button type="button" onClick={() => agirSurSession('entree')} className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm border hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700"><ArrowDownCircle size={16} className="text-emerald-600" /> {tc.cashIn}</button>
                <button type="button" onClick={() => agirSurSession('sortie')} className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm border hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700"><ArrowUpCircle size={16} className="text-red-600" /> {tc.cashOut}</button>
                <button type="button" onClick={() => agirSurSession('fermer')} className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm bg-gray-800 text-white hover:bg-gray-900 disabled:opacity-50"><Lock size={16} /> {tc.closeRegister}</button>
              </div>
            )}
          </div>
        )}
        {caisse && !caisse.session && (
          <div className="flex flex-wrap items-center gap-3 border-t pt-3 dark:border-gray-700">
            <span className="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-700"><Lock size={12} /> {tc.closed}</span>
            <span className="text-sm text-gray-600 dark:text-gray-300">{tc.notOpen}</span>
            {gerer && (
              <button type="button" onClick={() => setDialogue('ouvrir')} className="ml-auto inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm bg-emerald-600 text-white hover:bg-emerald-700"><Unlock size={16} /> {tc.openRegister}</button>
            )}
          </div>
        )}
      </section>

      {caisse && (
        <section className={classeCarte} aria-label={tc.sessions}>
          <div className="flex flex-wrap items-end gap-3 mb-3">
            <h2 className="text-lg font-semibold text-gray-800 dark:text-white mr-auto">{tc.sessions}</h2>
            <label className={classeLibelle}>{tc.from}
              <input type="date" value={periode.du} max={periode.au} onChange={e => e.target.value && setPeriode(p => ({ ...p, du: e.target.value }))} className={classeChamp} />
            </label>
            <label className={classeLibelle}>{tc.to}
              <input type="date" value={periode.au} min={periode.du} onChange={e => e.target.value && setPeriode(p => ({ ...p, au: e.target.value }))} className={classeChamp} />
            </label>
          </div>
          <div className="overflow-x-auto max-h-72 overflow-y-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-xs uppercase text-gray-500 dark:text-gray-400 border-b dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800">
                <tr>
                  <th className="py-2 pr-3">{tc.openedAt}</th>
                  <th className="py-2 pr-3">{tc.closedAt}</th>
                  <th className="py-2 pr-3 text-right">{tc.columns.attendu}</th>
                  <th className="py-2 pr-3 text-right">{tc.columns.compte}</th>
                  <th className="py-2 pr-3 text-right">{tc.columns.ecart}</th>
                  <th className="py-2">{tc.status}</th>
                </tr>
              </thead>
              <tbody>
                {sessionsAffichees.length === 0 && <tr><td colSpan={6} className="py-3 text-gray-500">{tc.noSessions}</td></tr>}
                {sessionsAffichees.map(s => {
                  const total = enEntreprise(s.totaux);
                  return (
                    <tr key={s.id} onClick={() => setSessionChoisie(s.id)} aria-selected={s.id === idSession}
                      className={`border-b dark:border-gray-700 cursor-pointer ${s.id === idSession ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'hover:bg-gray-50 dark:hover:bg-gray-700/40'}`}>
                      <td className="py-2 pr-3 dark:text-gray-200">{formatDateHeure(s.ouverte_le)}<span className="block text-xs text-gray-500">{s.ouvreur?.nom}</span></td>
                      <td className="py-2 pr-3 dark:text-gray-200">{s.fermee_le ? formatDateHeure(s.fermee_le) : '—'}<span className="block text-xs text-gray-500">{s.fermeur?.nom}</span></td>
                      <td className="py-2 pr-3 text-right dark:text-gray-200">
                        {montant(total?.attendu ?? 0)}
                        {autresDevises(s.totaux).map(x => <span key={x.devise} className="block text-xs">{montant(x.attendu, x.devise)}</span>)}
                      </td>
                      <td className="py-2 pr-3 text-right dark:text-gray-200">{total?.compte === null || total?.compte === undefined ? '—' : montant(total.compte)}</td>
                      <td className={`py-2 pr-3 text-right ${classeEcart(total?.ecart)}`}>
                        {total?.ecart === null || total?.ecart === undefined ? '—' : montant(total.ecart)}
                        {autresDevises(s.totaux).filter(x => nonNul(x.ecart)).map(x => <span key={x.devise} className="block text-xs">{montant(x.ecart, x.devise)}</span>)}
                      </td>
                      <td className="py-2">
                        <span className={`px-2 py-0.5 rounded-full text-xs ${s.statut === 'ouverte' ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-700'}`}>{s.statut === 'ouverte' ? tc.open : tc.closed}</span>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </section>
      )}

      {detailAffiche && <DetailSession key={detailAffiche.id} detail={detailAffiche} montant={montant} />}

      {dialogue === 'ouvrir' && caisse && <DialogueOuvrir caisse={caisse} onClose={() => setDialogue(null)} onFait={s => { toast(tc.opened.replace('{code}', caisse.code)); apres(s); }} />}
      {(dialogue === 'entree' || dialogue === 'sortie') && sessionOuverte && (
        <DialogueMouvement type={dialogue} session={sessionOuverte} onClose={() => setDialogue(null)} onFait={s => { toast(tc.movementSaved); apres(s); }} />
      )}
      {dialogue === 'fermer' && sessionOuverte && <DialogueFermer session={sessionOuverte} montant={montant} toast={toast} onClose={() => setDialogue(null)} onFait={apres} />}
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}

/** Sommaire d'une session : chiffres par mode, opérations, encaissements par employé et hors caisse. */
function DetailSession({ detail, montant }: { detail: SommaireCaisse; montant: Montant }) {
  const { t } = useLanguage();
  const tc = t.cashRegisters;
  const [filtre, setFiltre] = useState('');
  const [tri, setTri] = useState<'date' | 'desc' | 'asc'>('date');
  const [erreur, setErreur] = useState('');
  const ouverte = detail.statut === 'ouverte';

  const operations = useMemo(() => {
    const motif = filtre.trim().toLowerCase();
    const retenues = motif
      ? detail.operations.filter(o => [o.document, o.client, o.note].some(v => (v ?? '').toLowerCase().includes(motif)))
      : detail.operations;
    if (tri === 'date') return retenues;
    return [...retenues].sort((a, b) => (tri === 'desc' ? b.montant - a.montant : a.montant - b.montant));
  }, [detail.operations, filtre, tri]);

  async function imprimer() {
    setErreur('');
    try {
      await imprimerSommaireCaisse(detail);
    } catch (e) {
      setErreur(messageErreur(e, tc.error));
    }
  }

  return (
    <section className={`${classeCarte} space-y-4`} aria-label={tc.sessionTitle.replace('{date}', formatDateHeure(detail.ouverte_le))}>
      <div className="flex flex-wrap items-center gap-3">
        <h2 className="text-lg font-semibold text-gray-800 dark:text-white mr-auto">
          {tc.sessionTitle.replace('{date}', formatDateHeure(detail.ouverte_le))} — {detail.caisse?.code}
          {ouverte && <span className="ml-2 text-xs font-normal text-emerald-700">({tc.inProgress})</span>}
        </h2>
        <button type="button" onClick={() => { void imprimer(); }} disabled={ouverte} title={ouverte ? tc.printOnlyClosed : undefined}
          className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm border hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700 disabled:opacity-50">
          <FileText size={16} /> {tc.print}
        </button>
      </div>
      {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
      {detail.note_fermeture && <p className="text-sm text-gray-700 dark:text-gray-300"><span className="font-medium">{tc.closingNote} :</span> {detail.note_fermeture}</p>}

      <div>
        <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-1">{tc.summary}</h3>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-xs uppercase text-gray-500 dark:text-gray-400 border-b dark:border-gray-700">
              <tr>
                <th className="py-2 pr-3 text-left">{tc.mode}</th>
                {COLONNES.map(c => <th key={c} className="py-2 pr-3 text-right">{tc.columns[c]}</th>)}
              </tr>
            </thead>
            <tbody>
              {detail.lignes.map(l => (
                <tr key={`${l.mode}|${l.devise ?? ''}`} className="border-b dark:border-gray-700">
                  <td className="py-1.5 pr-3 dark:text-gray-200">{tc.modes[l.mode] ?? l.mode}{l.devise && <span className="text-xs text-gray-500"> ({l.devise})</span>}</td>
                  {COLONNES.map(c => (
                    <td key={c} className={`py-1.5 pr-3 text-right dark:text-gray-200 ${c === 'ecart' ? classeEcart(l.ecart) : ''} ${c === 'attendu' ? 'font-medium' : ''}`}>
                      {l[c] === null ? '—' : montant(c === 'remboursements' || c === 'sorties' ? -Number(l[c]) || 0 : l[c], l.devise)}
                    </td>
                  ))}
                </tr>
              ))}
              {detail.totaux.map(x => (
                <tr key={`total|${x.devise ?? ''}`} className="font-semibold border-t-2 dark:border-gray-600">
                  <td className="py-1.5 pr-3 dark:text-white">{tc.total}{x.devise && ` ${x.devise}`}</td>
                  {COLONNES.map(c => (
                    <td key={c} className={`py-1.5 pr-3 text-right dark:text-white ${c === 'ecart' ? classeEcart(x.ecart) : ''}`}>
                      {x[c] === null ? '—' : montant(c === 'remboursements' || c === 'sorties' ? -Number(x[c]) || 0 : x[c], x.devise)}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div>
        <div className="flex flex-wrap items-end gap-3 mb-1">
          <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200 mr-auto">{tc.operations}</h3>
          <input value={filtre} onChange={e => setFiltre(e.target.value)} placeholder={tc.filter} aria-label={tc.filter} className={`${classeChamp} max-w-xs`} />
        </div>
        <TableOperations operations={operations} montant={montant} tri={tri} onTri={() => setTri(x => (x === 'date' ? 'desc' : x === 'desc' ? 'asc' : 'date'))} />
      </div>

      <div>
        <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-1">{tc.byEmployee}</h3>
        {detail.par_usager.length === 0 ? <p className="text-sm text-gray-500">{tc.noOperations}</p> : (
          <table className="w-full max-w-xl text-sm">
            <thead className="text-xs uppercase text-gray-500 dark:text-gray-400 border-b dark:border-gray-700">
              <tr><th className="py-2 pr-3 text-left">{tc.operationColumns.employee}</th><th className="py-2 pr-3 text-left">{tc.mode}</th><th className="py-2 pr-3 text-right">{tc.count}</th><th className="py-2 text-right">{tc.amount}</th></tr>
            </thead>
            <tbody>
              {detail.par_usager.map(g => (
                <tr key={`${g.commis}|${g.mode}|${g.devise ?? ''}`} className="border-b dark:border-gray-700 dark:text-gray-200">
                  <td className="py-1.5 pr-3">{g.commis ?? '—'}</td>
                  <td className="py-1.5 pr-3">{tc.modes[g.mode] ?? g.mode}{g.devise && ` (${g.devise})`}</td>
                  <td className="py-1.5 pr-3 text-right">{g.nombre}</td>
                  <td className="py-1.5 text-right">{montant(g.montant, g.devise)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {detail.sans_caisse.operations.length > 0 && (
        <div>
          <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200">{tc.withoutRegister}</h3>
          <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">{tc.withoutRegisterHelp}</p>
          <TableOperations operations={detail.sans_caisse.operations} montant={montant} />
          {detail.sans_caisse.tronque && <p className="text-xs text-gray-500 mt-1">{tc.truncated}</p>}
        </div>
      )}
    </section>
  );
}

function TableOperations({ operations, montant, tri, onTri }: { operations: OperationCaisse[]; montant: Montant; tri?: 'date' | 'desc' | 'asc'; onTri?: () => void }) {
  const { t } = useLanguage();
  const tc = t.cashRegisters;
  const colonnes = tc.operationColumns;
  return (
    <div className="overflow-x-auto max-h-[28rem] overflow-y-auto">
      <table className="w-full text-sm">
        <thead className="text-xs uppercase text-gray-500 dark:text-gray-400 border-b dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800">
          <tr>
            <th className="py-2 pr-3 text-left">{colonnes.date}</th>
            <th className="py-2 pr-3 text-left">{colonnes.type}</th>
            <th className="py-2 pr-3 text-left">{colonnes.document}</th>
            <th className="py-2 pr-3 text-left">{colonnes.client}</th>
            <th className="py-2 pr-3 text-left">{tc.mode}</th>
            <th className="py-2 pr-3 text-right">
              {onTri ? (
                <button type="button" onClick={onTri} className="uppercase hover:underline" title={tc.sortAmount}>
                  {tc.amount}{tri === 'desc' ? ' ↓' : tri === 'asc' ? ' ↑' : ''}
                </button>
              ) : tc.amount}
            </th>
            <th className="py-2 pr-3 text-left">{colonnes.employee}</th>
            <th className="py-2 text-left">{colonnes.note}</th>
          </tr>
        </thead>
        <tbody>
          {operations.length === 0 && <tr><td colSpan={8} className="py-3 text-gray-500">{tc.noOperations}</td></tr>}
          {operations.map((o, i) => (
            <tr key={`${o.type}|${o.facture_id ?? o.acompte_id ?? o.mouvement_id ?? ''}|${i}`} className="border-b dark:border-gray-700 dark:text-gray-200 align-top">
              <td className="py-1.5 pr-3 whitespace-nowrap">{formatDateHeure(o.date)}</td>
              <td className="py-1.5 pr-3">{tc.types[o.type] ?? o.type}</td>
              <td className="py-1.5 pr-3">{o.document ?? ''}</td>
              <td className="py-1.5 pr-3">{o.client ?? ''}</td>
              <td className="py-1.5 pr-3">{tc.modes[o.mode] ?? o.mode}{o.devise && ` (${o.devise})`}</td>
              <td className={`py-1.5 pr-3 text-right whitespace-nowrap ${o.montant < 0 ? 'text-red-600' : ''}`}>{montant(o.montant, o.devise)}</td>
              <td className="py-1.5 pr-3">{o.commis ?? ''}</td>
              <td className="py-1.5">{o.note ?? ''}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** Ouvrir la caisse : fond initial (peut être nul), son mode, une note. */
function DialogueOuvrir({ caisse, onClose, onFait }: { caisse: CaisseEtat; onClose: () => void; onFait: (session: SommaireCaisse) => void }) {
  const { t } = useLanguage();
  const tc = t.cashRegisters;
  const [saisie, setSaisie] = useState({ fond: '0', mode: 'especes' as ModeCaisse, note: '' });
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  async function valider(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    try {
      onFait(await ouvrirCaisse(caisse.id, { fond: Number(saisie.fond) || 0, mode: saisie.mode, note: saisie.note.trim() || undefined }));
    } catch (err) {
      setErreur(messageErreur(err, tc.error));
      setOccupe(false);
    }
  }

  return (
    <Fenetre titre={tc.openTitle.replace('{code}', caisse.code)} onClose={onClose} onSubmit={valider}>
      <div className="grid grid-cols-2 gap-3">
        <label className={classeLibelle}>{tc.float}
          <input type="number" min="0" step="0.01" required value={saisie.fond} onChange={e => setSaisie({ ...saisie, fond: e.target.value })} className={classeChamp} autoFocus />
        </label>
        <ChoixMode valeur={saisie.mode} onChange={mode => setSaisie({ ...saisie, mode })} />
      </div>
      <p className="text-xs text-gray-500 dark:text-gray-400">{tc.floatHelp}</p>
      <label className={classeLibelle}>{tc.note}
        <input value={saisie.note} maxLength={255} onChange={e => setSaisie({ ...saisie, note: e.target.value })} className={classeChamp} />
      </label>
      <Erreur message={erreur} />
      <Boutons onClose={onClose} occupe={occupe} libelle={tc.openRegister} />
    </Fenetre>
  );
}

/** Entrée ou sortie d'argent : mode, montant, devise s'il y a de l'argent étranger, note (raison obligatoire en sortie). */
function DialogueMouvement({ type, session, onClose, onFait }: { type: 'entree' | 'sortie'; session: SommaireCaisse; onClose: () => void; onFait: (session: SommaireCaisse) => void }) {
  const { t } = useLanguage();
  const tc = t.cashRegisters;
  const [saisie, setSaisie] = useState({ mode: 'especes' as ModeCaisse, devise: '', montant: '', note: '' });
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);
  const devises = [...new Set(session.lignes.map(l => l.devise).filter((d): d is string => !!d))];

  async function valider(e: React.FormEvent) {
    e.preventDefault();
    if (type === 'sortie' && !saisie.note.trim()) { setErreur(tc.reasonRequired); return; }
    setOccupe(true);
    setErreur('');
    try {
      onFait(await mouvementCaisse(session.id, {
        type, mode: saisie.mode, devise: saisie.devise || undefined, montant: Number(saisie.montant), note: saisie.note.trim() || undefined,
      }));
    } catch (err) {
      setErreur(messageErreur(err, tc.error));
      setOccupe(false);
    }
  }

  return (
    <Fenetre titre={type === 'entree' ? tc.cashIn : tc.cashOut} onClose={onClose} onSubmit={valider}>
      <p className="text-xs text-gray-500 dark:text-gray-400">{type === 'entree' ? tc.cashInHelp : tc.cashOutHelp}</p>
      <div className="grid grid-cols-2 gap-3">
        <label className={classeLibelle}>{tc.amount}
          <input type="number" min="0.01" step="0.01" required value={saisie.montant} onChange={e => setSaisie({ ...saisie, montant: e.target.value })} className={classeChamp} autoFocus />
        </label>
        <ChoixMode valeur={saisie.mode} onChange={mode => setSaisie({ ...saisie, mode })} />
      </div>
      {devises.length > 0 && (
        <label className={classeLibelle}>{tc.currency}
          <select value={saisie.devise} onChange={e => setSaisie({ ...saisie, devise: e.target.value })} className={classeChamp}>
            <option value="">{tc.companyCurrency}</option>
            {devises.map(d => <option key={d} value={d}>{d}</option>)}
          </select>
        </label>
      )}
      <label className={classeLibelle}>{tc.reason}{type === 'sortie' && ' *'}
        <input value={saisie.note} maxLength={255} required={type === 'sortie'} onChange={e => setSaisie({ ...saisie, note: e.target.value })} className={classeChamp} />
      </label>
      <Erreur message={erreur} />
      <Boutons onClose={onClose} occupe={occupe} libelle={tc.save} />
    </Fenetre>
  );
}

/** Fermer la caisse : montant compté par mode, écart en direct, note ; fermer (et imprimer le sommaire). */
function DialogueFermer({ session, montant, toast, onClose, onFait }: { session: SommaireCaisse; montant: Montant; toast: Toast; onClose: () => void; onFait: (session: SommaireCaisse) => void }) {
  const { t } = useLanguage();
  const tc = t.cashRegisters;
  const cle = (mode: string, devise: string | null) => `${mode}|${devise ?? ''}`;
  const [comptes, setComptes] = useState<Record<string, string>>({});
  const [note, setNote] = useState('');
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  const lignes = session.lignes.map(l => {
    const compte = Number(comptes[cle(l.mode, l.devise)] || 0);
    return { ...l, compte, ecart: Math.round((compte - l.attendu) * 100) / 100 };
  });
  const totaux = [...new Set(lignes.map(l => l.devise))].map(devise => {
    const groupe = lignes.filter(l => l.devise === devise);
    const somme = (f: (l: typeof lignes[number]) => number) => Math.round(groupe.reduce((s, l) => s + f(l), 0) * 100) / 100;
    return { devise, attendu: somme(l => l.attendu), compte: somme(l => l.compte), ecart: somme(l => l.ecart) };
  });

  async function fermer(imprimer: boolean) {
    if (!confirm(tc.confirmClose)) return;
    setOccupe(true);
    setErreur('');
    try {
      const fermee = await fermerCaisse(session.id, {
        comptes: lignes.map(l => ({ mode: l.mode, devise: l.devise, montant: l.compte })), note: note.trim() || undefined,
      });
      const ecarts = fermee.totaux.filter(x => nonNul(x.ecart));
      toast(ecarts.length === 0 ? tc.closedBalanced : tc.closedGap.replace('{ecart}', ecarts.map(x => montant(x.ecart, x.devise)).join(' ; ')), ecarts.length === 0 ? 'success' : 'info');
      if (imprimer) await imprimerSommaireCaisse(fermee).catch(() => {});
      onFait(fermee);
    } catch (err) {
      setErreur(messageErreur(err, tc.error));
      setOccupe(false);
    }
  }

  return (
    <Fenetre titre={tc.closeTitle.replace('{code}', session.caisse?.code ?? '')} onClose={onClose} onSubmit={e => { e.preventDefault(); void fermer(false); }} large>
      <p className="text-xs text-gray-500 dark:text-gray-400">{tc.closeHelp}</p>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="text-xs uppercase text-gray-500 dark:text-gray-400 border-b dark:border-gray-700">
            <tr>
              <th className="py-2 pr-3 text-left">{tc.mode}</th>
              <th className="py-2 pr-3 text-right">{tc.columns.attendu}</th>
              <th className="py-2 pr-3 text-right">{tc.columns.compte}</th>
              <th className="py-2 text-right">{tc.columns.ecart}</th>
            </tr>
          </thead>
          <tbody>
            {lignes.map(l => (
              <tr key={cle(l.mode, l.devise)} className="border-b dark:border-gray-700 dark:text-gray-200">
                <td className="py-1.5 pr-3">{tc.modes[l.mode] ?? l.mode}{l.devise && ` (${l.devise})`}</td>
                <td className="py-1.5 pr-3 text-right">{montant(l.attendu, l.devise)}</td>
                <td className="py-1.5 pr-3 text-right">
                  <input type="number" min="0" step="0.01" value={comptes[cle(l.mode, l.devise)] ?? ''} placeholder="0"
                    aria-label={`${tc.columns.compte} — ${tc.modes[l.mode] ?? l.mode}${l.devise ? ` (${l.devise})` : ''}`}
                    onChange={e => setComptes(c => ({ ...c, [cle(l.mode, l.devise)]: e.target.value }))}
                    className="w-32 px-2 py-1 border rounded text-right dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                </td>
                <td className={`py-1.5 text-right ${classeEcart(l.ecart)}`}>{montant(l.ecart, l.devise)}</td>
              </tr>
            ))}
            {totaux.map(x => (
              <tr key={`total|${x.devise ?? ''}`} className="font-semibold border-t-2 dark:border-gray-600 dark:text-white">
                <td className="py-1.5 pr-3">{tc.total}{x.devise && ` ${x.devise}`}</td>
                <td className="py-1.5 pr-3 text-right">{montant(x.attendu, x.devise)}</td>
                <td className="py-1.5 pr-3 text-right">{montant(x.compte, x.devise)}</td>
                <td className={`py-1.5 text-right ${classeEcart(x.ecart)}`}>{montant(x.ecart, x.devise)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <label className={classeLibelle}>{tc.closingNote}
        <textarea rows={2} maxLength={1000} value={note} onChange={e => setNote(e.target.value)} className={classeChamp} />
        <span className="block mt-0.5">{tc.closingNoteHelp}</span>
      </label>
      <Erreur message={erreur} />
      <div className="flex flex-wrap justify-end gap-2">
        <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tc.cancel}</button>
        <button type="submit" disabled={occupe} className="px-4 py-2 bg-gray-800 text-white rounded-lg text-sm hover:bg-gray-900 disabled:opacity-50">{tc.closeRegister}</button>
        <button type="button" disabled={occupe} onClick={() => { void fermer(true); }} className="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm hover:bg-emerald-700 disabled:opacity-50">{tc.closeAndPrint}</button>
      </div>
    </Fenetre>
  );
}

function ChoixMode({ valeur, onChange }: { valeur: ModeCaisse; onChange: (mode: ModeCaisse) => void }) {
  const { t } = useLanguage();
  const tc = t.cashRegisters;
  return (
    <label className={classeLibelle}>{tc.mode}
      <select value={valeur} onChange={e => onChange(e.target.value as ModeCaisse)} className={classeChamp}>
        {MODES_CAISSE.map(m => <option key={m} value={m}>{tc.modes[m]}</option>)}
      </select>
    </label>
  );
}

function Fenetre({ titre, onClose, onSubmit, large, children }: { titre: string; onClose: () => void; onSubmit: (e: React.FormEvent) => void; large?: boolean; children: React.ReactNode }) {
  const { t } = useLanguage();
  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <form onSubmit={onSubmit} role="dialog" aria-modal="true" aria-label={titre}
        className={`bg-white dark:bg-gray-800 rounded-xl p-6 w-full ${large ? 'max-w-2xl' : 'max-w-md'} max-h-[94vh] overflow-y-auto space-y-3`}>
        <div className="flex items-start justify-between gap-3">
          <h2 className="text-lg font-bold text-gray-900 dark:text-white">{titre}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 dark:text-white" aria-label={t.cashRegisters.close}><X size={18} /></button>
        </div>
        {children}
      </form>
    </div>
  );
}

function Erreur({ message }: { message: string }) {
  return message ? <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{message}</p> : null;
}

function Boutons({ onClose, occupe, libelle }: { onClose: () => void; occupe: boolean; libelle: string }) {
  const { t } = useLanguage();
  return (
    <div className="flex justify-end gap-2">
      <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{t.cashRegisters.cancel}</button>
      <button type="submit" disabled={occupe} className="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm hover:bg-emerald-700 disabled:opacity-50">{libelle}</button>
    </div>
  );
}
