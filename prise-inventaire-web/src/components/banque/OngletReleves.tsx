import { useCallback, useEffect, useMemo, useState } from 'react';
import { Ban, FilePlus2, Link2, Search, Sparkles, Trash2, Undo2, Unlink, Upload, X } from 'lucide-react';
import FenetreCandidatsReleve from '@/components/banque/FenetreCandidatsReleve';
import FenetreClasserOperation from '@/components/banque/FenetreClasserOperation';
import FenetreImportReleve from '@/components/banque/FenetreImportReleve';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { aujourdhui, formatDateDocument, formatDateHeure, useCodeDevise, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import {
  delierOperation, exclureOperation, getImportsReleve, getReleve, relierOperation, relierSuggestionsReleve, retablirOperation,
  STATUTS_OPERATION_RELEVE, supprimerImportReleve, type CompteBanque, type ImportReleve, type OperationReleve, type ReleveCompte,
  type StatutOperationReleve,
} from '@/services/banque';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
const classeAction = 'inline-flex items-center gap-1 px-2 py-1 text-xs border rounded-lg dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-40';
const COULEURS_STATUT_RELEVE: Record<StatutOperationReleve, string> = {
  non_reliee: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
  partielle: 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-200',
  reliee: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-200',
  exclue: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
};

/** Date d'il y a quelques jours, au format AAAA-MM-JJ, dans le fuseau de l'utilisateur. */
function joursAvant(jours: number): string {
  const d = new Date(`${aujourdhui()}T12:00:00`);
  d.setDate(d.getDate() - jours);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/**
 * Relevés importés d'un compte bancaire relié (dans la devise de l'entreprise, celle du journal) : l'import d'un fichier
 * CSV ou OFX, les opérations de la période avec leur statut, leurs mouvements reliés et la meilleure correspondance
 * proposée ; relier (une proposition, toutes, ou des mouvements cherchés), délier, exclure, rétablir, et créer la
 * transaction d'une opération sans mouvement (frais, intérêts) ; les fichiers importés, dont un import sans opération
 * reliée se supprime.
 */
export default function OngletReleves({ comptes, onComptesModifies }: { comptes: CompteBanque[]; onComptesModifies: () => void }) {
  const { t, language } = useLanguage();
  const tb = t.bank;
  const ts = t.bank.statements;
  const { peut } = usePermissions();
  const peutRapprocher = peut('banque', 'rapprocher');
  const peutSaisir = peut('banque', 'saisir');
  const montant = useMontantDocument();
  const deviseEntreprise = useCodeDevise();
  const eligibles = useMemo(() => comptes.filter(c => c.compte_comptable_id && (!c.devise_code || !deviseEntreprise || c.devise_code.toUpperCase() === deviseEntreprise.toUpperCase())), [comptes, deviseEntreprise]);
  const [choix, setChoix] = useState('');
  const compteId = choix || String(eligibles[0]?.id ?? '');
  const compte = eligibles.find(c => String(c.id) === compteId);
  const [periode, setPeriode] = useState(() => ({ du: joursAvant(90), au: aujourdhui() }));
  const [statut, setStatut] = useState<StatutOperationReleve | ''>('');
  const [releve, setReleve] = useState<ReleveCompte | null>(null);
  const [imports, setImports] = useState<ImportReleve[]>([]);
  const [erreur, setErreur] = useState('');
  const [message, setMessage] = useState('');
  const [enCours, setEnCours] = useState(false);
  const [importer, setImporter] = useState(false);
  const [chercher, setChercher] = useState<OperationReleve | null>(null);
  const [classer, setClasser] = useState<OperationReleve | null>(null);

  const charger = useCallback(async () => {
    if (!compteId) return;
    try {
      const [operations, fichiers] = await Promise.all([getReleve(Number(compteId), { du: periode.du, au: periode.au, statut }), getImportsReleve(Number(compteId))]);
      setReleve(operations);
      setImports(fichiers);
    } catch (e) {
      setErreur(messageErreur(e, tb.error));
    }
  }, [compteId, periode, statut, tb.error]);
  useEffect(() => { charger(); }, [charger]);

  async function agir<T>(action: () => Promise<T>, apres?: (resultat: T) => void) {
    setEnCours(true);
    setErreur('');
    setMessage('');
    try {
      const resultat = await action();
      apres?.(resultat);
      await charger();
    } catch (e) {
      setErreur(messageErreur(e, tb.error));
    } finally {
      setEnCours(false);
    }
  }

  function relierSuggestion(operation: OperationReleve) {
    const mouvement = operation.suggestion?.id;
    if (mouvement) agir(() => relierOperation(operation.id, [mouvement]));
  }

  function exclure(operation: OperationReleve) {
    const motif = prompt(ts.excludeReason);
    if (motif !== null) agir(() => exclureOperation(operation.id, motif.trim() || null));
  }

  const resume = releve?.resume;

  return (
    <div className="space-y-4">
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 flex flex-wrap items-end gap-3">
        <label className="text-xs text-gray-500 dark:text-gray-400">{tb.account}
          <select value={compteId} onChange={e => { setChoix(e.target.value); setReleve(null); setImports([]); }} className={`${classeChamp} block`}>
            {eligibles.length === 0 && <option value="">{tb.noLinkedAccount}</option>}
            {eligibles.map(c => <option key={c.id} value={c.id}>{c.nom}</option>)}
          </select>
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tb.from}
          <input type="date" value={periode.du} onChange={e => setPeriode({ ...periode, du: e.target.value })} className={`${classeChamp} block`} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tb.to}
          <input type="date" value={periode.au} onChange={e => setPeriode({ ...periode, au: e.target.value })} className={`${classeChamp} block`} />
        </label>
        {peutRapprocher && compte && (
          <button type="button" onClick={() => setImporter(true)} className="ml-auto flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">
            <Upload size={16} /> {ts.import}
          </button>
        )}
      </div>
      <p className="text-xs text-gray-500 max-w-4xl">{ts.help}</p>
      {eligibles.length === 0 && <p className="text-sm text-gray-500">{ts.noEligibleAccount}</p>}
      {message && <div role="status" className="px-3 py-2 rounded-lg text-sm bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200">{message}</div>}
      {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}

      {releve && resume && (
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm">
          <div className="p-4 border-b dark:border-gray-700 flex flex-wrap items-center gap-2">
            {STATUTS_OPERATION_RELEVE.map(s => (
              <button key={s} type="button" aria-pressed={statut === s} title={ts.filterStatus.replace('{statut}', ts.statuses[s])}
                onClick={() => setStatut(statut === s ? '' : s)}
                className={`px-2 py-1 rounded text-xs font-medium ${COULEURS_STATUT_RELEVE[s]} ${statut === s ? 'ring-2 ring-blue-500' : ''}`}>
                {ts.statuses[s]} : {resume[s]}
              </button>
            ))}
            {peutRapprocher && resume.suggestions > 0 && (
              <button type="button" disabled={enCours}
                onClick={() => agir(() => relierSuggestionsReleve(Number(compteId), periode.du, periode.au), r => setMessage(r.message))}
                className="ml-auto flex items-center gap-1 px-3 py-1.5 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
                <Sparkles size={14} /> {ts.linkSuggestions.replace('{n}', String(resume.suggestions))}
              </button>
            )}
          </div>
          {releve.lignes.length === 0 ? <p className="p-4 text-sm text-gray-500">{ts.noOperation}</p> : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[980px] text-sm" aria-label={tb.tabs.releves}>
                <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                  <tr>
                    <th className="px-3 py-2 text-left">{tb.date}</th>
                    <th className="px-3 py-2 text-left">{ts.operation}</th>
                    <th className="px-3 py-2 text-right">{tb.amount}</th>
                    <th className="px-3 py-2 text-left">{tb.status}</th>
                    <th className="px-3 py-2 text-left">{ts.movements}</th>
                    {(peutRapprocher || peutSaisir) && <th className="px-3 py-2 text-right">{ts.actions}</th>}
                  </tr>
                </thead>
                <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                  {releve.lignes.map(o => (
                    <tr key={o.id} className={o.statut === 'exclue' ? 'text-gray-400' : ''}>
                      <td className="px-3 py-2 whitespace-nowrap align-top">{formatDateDocument(o.date_operation, language)}</td>
                      <td className="px-3 py-2 align-top">{o.libelle}{o.reference ? <span className="block text-xs text-gray-500">{o.reference}</span> : null}</td>
                      <td className={`px-3 py-2 text-right font-medium whitespace-nowrap align-top ${o.montant < 0 ? 'text-red-700 dark:text-red-300' : 'text-green-700 dark:text-green-300'}`}>{montant(o.montant)}</td>
                      <td className="px-3 py-2 align-top">
                        <span className={`px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap ${COULEURS_STATUT_RELEVE[o.statut]}`}>{ts.statuses[o.statut]}</span>
                      </td>
                      <td className="px-3 py-2 align-top space-y-1">
                        {o.mouvements.map(m => (
                          <div key={m.id} className="text-xs">
                            <div className="flex items-center gap-1">
                              <span className="font-mono">{m.journal} {m.numero}</span>
                              {peutRapprocher && (
                                <button type="button" disabled={enCours} title={ts.unlink} aria-label={ts.unlinkOne.replace('{numero}', m.numero)}
                                  onClick={() => agir(() => delierOperation(o.id, m.id))} className="p-0.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700"><X size={12} /></button>
                              )}
                            </div>
                            <span className="text-gray-500">{formatDateDocument(m.date, language)} · {m.libelle} · {montant(m.montant)}</span>
                          </div>
                        ))}
                        {o.statut === 'partielle' && (
                          <div className="text-xs text-orange-700 dark:text-orange-300">{ts.remaining.replace('{montant}', montant(Math.abs(o.montant - o.montant_relie)))}</div>
                        )}
                        {o.statut === 'exclue' && o.motif_exclusion && <div className="text-xs">{o.motif_exclusion}</div>}
                        {o.suggestion && (
                          <div className="flex flex-wrap items-center gap-2 text-xs">
                            <span className="text-blue-700 dark:text-blue-300">
                              {ts.suggested} : <span className="font-mono">{o.suggestion.journal} {o.suggestion.numero}</span> · {formatDateDocument(o.suggestion.date, language)} · {o.suggestion.libelle}
                            </span>
                            {peutRapprocher && (
                              <button type="button" disabled={enCours} onClick={() => relierSuggestion(o)}
                                className={`${classeAction} border-blue-600 text-blue-700 dark:text-blue-300`}><Link2 size={12} /> {ts.link}</button>
                            )}
                          </div>
                        )}
                      </td>
                      {(peutRapprocher || peutSaisir) && (
                        <td className="px-3 py-2 align-top">
                          <div className="flex flex-wrap justify-end gap-1">
                            {peutRapprocher && (o.statut === 'non_reliee' || o.statut === 'partielle') && (
                              <button type="button" disabled={enCours} onClick={() => setChercher(o)} className={classeAction}><Search size={12} /> {ts.find}</button>
                            )}
                            {peutSaisir && o.statut === 'non_reliee' && (
                              <button type="button" disabled={enCours} onClick={() => setClasser(o)} className={classeAction}><FilePlus2 size={12} /> {ts.classify}</button>
                            )}
                            {peutRapprocher && o.statut === 'non_reliee' && (
                              <button type="button" disabled={enCours} onClick={() => exclure(o)} className={classeAction}><Ban size={12} /> {ts.exclude}</button>
                            )}
                            {peutRapprocher && (o.statut === 'reliee' || o.statut === 'partielle') && (
                              <button type="button" disabled={enCours} onClick={() => agir(() => delierOperation(o.id))} className={classeAction}><Unlink size={12} /> {ts.unlink}</button>
                            )}
                            {peutRapprocher && o.statut === 'exclue' && (
                              <button type="button" disabled={enCours} onClick={() => agir(() => retablirOperation(o.id))} className={classeAction}><Undo2 size={12} /> {ts.restore}</button>
                            )}
                          </div>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {imports.length > 0 && (
        <details className="bg-white dark:bg-gray-800 rounded-xl shadow-sm">
          <summary className="px-4 py-3 cursor-pointer text-sm font-medium dark:text-white">{ts.imports.replace('{n}', String(imports.length))}</summary>
          <p className="px-4 pb-2 text-xs text-gray-500">{ts.deleteImportHelp}</p>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-sm" aria-label={ts.imports.replace('{n}', String(imports.length))}>
              <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                <tr>
                  <th className="px-3 py-2 text-left">{ts.fileName}</th>
                  <th className="px-3 py-2 text-left">{ts.period}</th>
                  <th className="px-3 py-2 text-right">{ts.operations}</th>
                  <th className="px-3 py-2 text-right">{ts.duplicates}</th>
                  <th className="px-3 py-2 text-right">{ts.linkedCount}</th>
                  <th className="px-3 py-2 text-left">{ts.importedOn}</th>
                  {peutRapprocher && <th className="px-3 py-2" />}
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                {imports.map(i => (
                  <tr key={i.id}>
                    <td className="px-3 py-2">{i.fichier} <span className="text-xs text-gray-500 uppercase">{i.format}</span></td>
                    <td className="px-3 py-2 whitespace-nowrap">{i.du ? `${formatDateDocument(i.du, language)} – ${formatDateDocument(i.au, language)}` : '—'}</td>
                    <td className="px-3 py-2 text-right">{i.nombre_lignes}</td>
                    <td className="px-3 py-2 text-right">{i.doublons}</td>
                    <td className="px-3 py-2 text-right">{i.reliees}</td>
                    <td className="px-3 py-2 text-xs text-gray-500">{i.importe_le ? formatDateHeure(i.importe_le, language) : ''}{i.auteur ? ` — ${i.auteur}` : ''}</td>
                    {peutRapprocher && (
                      <td className="px-3 py-2 text-right">
                        <button type="button" disabled={enCours || i.reliees > 0} title={i.reliees > 0 ? ts.deleteImportLinked : undefined}
                          onClick={() => confirm(ts.confirmDeleteImport.replace('{fichier}', i.fichier).replace('{n}', String(i.nombre_lignes))) && agir(() => supprimerImportReleve(i.id), r => setMessage(r.message))}
                          className={`${classeAction} border-red-600 text-red-700 dark:text-red-300`}><Trash2 size={12} /> {ts.deleteImport}</button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </details>
      )}

      {importer && compte && (
        <FenetreImportReleve compte={compte} reglages={releve?.import_reglages ?? null} onClose={() => setImporter(false)} onImporte={rapport => {
          setImporter(false);
          setMessage(rapport.nouvelles > 0 ? ts.imported.replace('{n}', String(rapport.nouvelles)) : ts.nothingNew);
          charger();
        }} />
      )}
      {chercher && (
        <FenetreCandidatsReleve operation={chercher} onClose={() => setChercher(null)} onReliee={() => {
          setChercher(null);
          charger();
        }} />
      )}
      {classer && compte && (
        <FenetreClasserOperation operation={classer} compteBanqueComptable={compte.compte_comptable_id} onClose={() => setClasser(null)} onClassee={r => {
          setClasser(null);
          setMessage(r.message);
          charger();
          onComptesModifies();
        }} />
      )}
    </div>
  );
}
