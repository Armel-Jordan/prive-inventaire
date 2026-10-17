import { useCallback, useEffect, useState } from 'react';
import { CheckCheck, RotateCcw, Trash2 } from 'lucide-react';
import BoutonPdf from '@/components/BoutonPdf';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { aujourdhui, formatDateDocument, formatDateHeure, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import {
  commencerRapprochement, getRapprochement, getRapprochements, modifierRapprochement, pointerMouvements, rouvrirRapprochement,
  supprimerRapprochement, terminerRapprochement, type CompteBanque, type DetailRapprochement, type RapprochementBancaire,
} from '@/services/banque';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/**
 * Rapprochement bancaire d'un compte relié : l'historique de ses relevés, un nouveau relevé (date, solde de fin), le
 * pointage des mouvements de son compte comptable jusqu'à la date du relevé, l'écart, la fin (écart nul), la
 * réouverture du dernier, la suppression d'un rapprochement en cours et le rapport PDF.
 */
export default function OngletRapprochement({ comptes }: { comptes: CompteBanque[] }) {
  const { t, language } = useLanguage();
  const tb = t.bank;
  const tr = t.bank.reconciliation;
  const { peut } = usePermissions();
  const peutRapprocher = peut('banque', 'rapprocher');
  const montant = useMontantDocument();
  const relies = comptes.filter(c => c.compte_comptable_id);
  const [compte, setCompte] = useState(String(relies[0]?.id ?? ''));
  const [historique, setHistorique] = useState<RapprochementBancaire[]>([]);
  const [ouvert, setOuvert] = useState<number | null>(null);
  const [detail, setDetail] = useState<DetailRapprochement | null>(null);
  const [releve, setReleve] = useState({ date: aujourdhui(), solde: '' });
  const [nonPointes, setNonPointes] = useState(false);
  const [erreur, setErreur] = useState('');
  const [enCours, setEnCours] = useState(false);

  const chargerHistorique = useCallback(async (choisir = false) => {
    if (!compte) return;
    const liste = await getRapprochements(Number(compte));
    setHistorique(liste);
    // Un rapprochement en cours s'ouvre de lui-même.
    if (choisir) setOuvert(liste.find(r => r.statut === 'en_cours')?.id ?? null);
  }, [compte]);

  useEffect(() => {
    setDetail(null);
    chargerHistorique(true).catch(e => setErreur(messageErreur(e, tb.error)));
  }, [chargerHistorique, tb.error]);

  useEffect(() => {
    if (ouvert === null) { setDetail(null); return; }
    let actif = true;
    getRapprochement(ouvert).then(d => {
      if (!actif) return;
      setDetail(d);
      setReleve({ date: d.rapprochement.date_releve.slice(0, 10), solde: String(Number(d.rapprochement.solde_releve)) });
    }, e => { if (actif) setErreur(messageErreur(e, tb.error)); });
    return () => { actif = false; };
  }, [ouvert, tb.error]);

  async function agir<T>(action: () => Promise<T>, apres?: (resultat: T) => void) {
    setEnCours(true);
    setErreur('');
    try {
      const resultat = await action();
      apres?.(resultat);
    } catch (e) {
      setErreur(messageErreur(e, tb.error));
    } finally {
      setEnCours(false);
    }
  }

  const enCoursOuvert = detail?.rapprochement.statut === 'en_cours';
  const modifiable = enCoursOuvert && peutRapprocher;
  const aucunEnCours = !historique.some(r => r.statut === 'en_cours');
  const dernierTermine = historique.find(r => r.statut === 'termine');
  const lignes = (detail?.lignes ?? []).filter(l => !nonPointes || !l.pointee);
  const r = detail?.resume;

  return (
    <div className="space-y-4">
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4 flex flex-wrap items-end gap-3">
        <label className="text-xs text-gray-500 dark:text-gray-400">{tb.account}
          <select value={compte} onChange={e => { setCompte(e.target.value); setOuvert(null); }} className={`${classeChamp} block`}>
            {relies.length === 0 && <option value="">{tb.noLinkedAccount}</option>}
            {relies.map(c => <option key={c.id} value={c.id}>{c.nom}</option>)}
          </select>
        </label>
        {peutRapprocher && compte && aucunEnCours && (
          <form className="flex flex-wrap items-end gap-3" aria-label={tr.newReconciliation} onSubmit={e => {
            e.preventDefault();
            agir(() => commencerRapprochement(Number(compte), { date_releve: releve.date, solde_releve: Number(releve.solde) }), async nouveau => {
              await chargerHistorique();
              setOuvert(nouveau.id);
            });
          }}>
            <label className="text-xs text-gray-500 dark:text-gray-400">{tr.statementDate}
              <input type="date" required value={releve.date} onChange={e => setReleve({ ...releve, date: e.target.value })} className={`${classeChamp} block`} />
            </label>
            <label className="text-xs text-gray-500 dark:text-gray-400">{tr.statementBalance}
              <input type="number" required step="0.01" value={releve.solde} onChange={e => setReleve({ ...releve, solde: e.target.value })} className={`${classeChamp} block w-40`} />
            </label>
            <button type="submit" disabled={enCours} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">{tr.start}</button>
          </form>
        )}
      </div>
      <p className="text-xs text-gray-500">{tr.help}</p>
      {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}

      {detail && r && (
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm">
          <div className="p-4 border-b dark:border-gray-700 flex flex-wrap items-center justify-between gap-3">
            <div className="flex flex-wrap items-center gap-2">
              <h3 className="font-semibold dark:text-white">{tr.statementOf.replace('{date}', formatDateDocument(detail.rapprochement.date_releve, language))}</h3>
              <span className={`px-2 py-0.5 rounded text-xs font-medium ${enCoursOuvert ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' : 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-200'}`}>
                {tr.statuses[detail.rapprochement.statut]}
              </span>
              <BoutonPdf chemin={`/banque/rapprochements/${detail.rapprochement.id}/pdf`} nomFichier={`rapprochement-${detail.rapprochement.date_releve.slice(0, 10)}.pdf`} />
            </div>
            <div className="flex flex-wrap gap-2">
              {modifiable && (
                <>
                  <button type="button" disabled={enCours || Math.abs(r.ecart) > 0.004} title={tr.finishHelp}
                    onClick={() => agir(() => terminerRapprochement(detail.rapprochement.id), async () => { await chargerHistorique(); setOuvert(detail.rapprochement.id); setDetail(await getRapprochement(detail.rapprochement.id)); })}
                    className="flex items-center gap-1 px-3 py-1.5 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-40"><CheckCheck size={14} /> {tr.finish}</button>
                  <button type="button" disabled={enCours}
                    onClick={() => confirm(tr.confirmDelete) && agir(() => supprimerRapprochement(detail.rapprochement.id), async () => { setOuvert(null); await chargerHistorique(); })}
                    className="flex items-center gap-1 px-3 py-1.5 text-sm border border-red-600 text-red-700 rounded-lg hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-900/30"><Trash2 size={14} /> {tr.delete}</button>
                </>
              )}
              {!enCoursOuvert && peutRapprocher && dernierTermine?.id === detail.rapprochement.id && aucunEnCours && (
                <button type="button" disabled={enCours}
                  onClick={() => agir(() => rouvrirRapprochement(detail.rapprochement.id), async () => { await chargerHistorique(); setDetail(await getRapprochement(detail.rapprochement.id)); })}
                  className="flex items-center gap-1 px-3 py-1.5 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700"><RotateCcw size={14} /> {tr.reopen}</button>
              )}
            </div>
          </div>

          {modifiable && (
            <form className="px-4 pt-4 flex flex-wrap items-end gap-3" aria-label={tr.editStatement} onSubmit={e => {
              e.preventDefault();
              agir(() => modifierRapprochement(detail.rapprochement.id, { date_releve: releve.date, solde_releve: Number(releve.solde) }), setDetail);
            }}>
              <label className="text-xs text-gray-500 dark:text-gray-400">{tr.statementDate}
                <input type="date" required value={releve.date} onChange={e => setReleve({ ...releve, date: e.target.value })} className={`${classeChamp} block`} />
              </label>
              <label className="text-xs text-gray-500 dark:text-gray-400">{tr.statementBalance}
                <input type="number" required step="0.01" value={releve.solde} onChange={e => setReleve({ ...releve, solde: e.target.value })} className={`${classeChamp} block w-40`} />
              </label>
              <button type="submit" disabled={enCours} className="px-3 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">{tr.saveStatement}</button>
            </form>
          )}

          <dl className="p-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm dark:text-gray-200">
            <div><dt className="text-xs text-gray-500">{tr.startBalance}</dt><dd>{montant(r.solde_depart)}</dd></div>
            <div><dt className="text-xs text-gray-500">{tr.ticked}</dt><dd>+ {montant(r.entrees_pointees)} / − {montant(r.sorties_pointees)}</dd></div>
            <div><dt className="text-xs text-gray-500">{tr.tickedBalance}</dt><dd className="font-semibold">{montant(r.solde_pointe)}</dd></div>
            <div><dt className="text-xs text-gray-500">{tr.statementBalance}</dt><dd className="font-semibold">{montant(r.solde_releve)}</dd></div>
            <div className={`col-span-2 sm:col-span-1 px-2 py-1 rounded ${Math.abs(r.ecart) > 0.004 ? 'bg-red-50 text-red-800 dark:bg-red-900/30 dark:text-red-200' : 'bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200'}`}>
              <dt className="text-xs">{tr.difference}</dt><dd className="font-semibold">{montant(r.ecart)}</dd>
            </div>
            <div><dt className="text-xs text-gray-500">{tr.outstanding}</dt><dd>+ {montant(r.entrees_en_circulation)} / − {montant(r.sorties_en_circulation)}</dd></div>
            <div><dt className="text-xs text-gray-500">{tr.bookBalance}</dt><dd>{montant(r.solde_comptable)}</dd></div>
          </dl>

          <div className="px-4 pb-2 flex flex-wrap items-center justify-between gap-2">
            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
              <input type="checkbox" checked={nonPointes} onChange={e => setNonPointes(e.target.checked)} /> {tr.untickedOnly}
            </label>
            {modifiable && lignes.length > 0 && (
              <div className="flex gap-2">
                <button type="button" disabled={enCours} onClick={() => agir(() => pointerMouvements(detail.rapprochement.id, lignes.filter(l => !l.pointee).map(l => l.id), true), setDetail)}
                  className="px-3 py-1 text-xs border rounded-lg dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-40">{tr.tickAll}</button>
                <button type="button" disabled={enCours} onClick={() => agir(() => pointerMouvements(detail.rapprochement.id, lignes.filter(l => l.pointee).map(l => l.id), false), setDetail)}
                  className="px-3 py-1 text-xs border rounded-lg dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-40">{tr.untickAll}</button>
              </div>
            )}
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[860px] text-sm" aria-label={tr.movements}>
              <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                <tr>
                  <th className="px-3 py-2 text-center">{tr.tick}</th>
                  <th className="px-3 py-2 text-left">{tb.date}</th>
                  <th className="px-3 py-2 text-left">{tb.entry}</th>
                  <th className="px-3 py-2 text-left">{tb.label}</th>
                  <th className="px-3 py-2 text-left">{tb.origin}</th>
                  <th className="px-3 py-2 text-right">{tb.moneyIn}</th>
                  <th className="px-3 py-2 text-right">{tb.moneyOut}</th>
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                {lignes.map(l => (
                  <tr key={l.id} className={l.pointee ? 'bg-green-50/50 dark:bg-green-900/10' : ''}>
                    <td className="px-3 py-2 text-center">
                      <input type="checkbox" checked={l.pointee} disabled={!modifiable || enCours} aria-label={`${tr.tick} ${l.journal} ${l.numero}`}
                        onChange={e => agir(() => pointerMouvements(detail.rapprochement.id, [l.id], e.target.checked), setDetail)} />
                    </td>
                    <td className="px-3 py-2 whitespace-nowrap">{formatDateDocument(l.date, language)}</td>
                    <td className="px-3 py-2 font-mono text-xs">{l.journal} {l.numero}</td>
                    <td className="px-3 py-2">{l.libelle}{l.reference ? <span className="block text-xs text-gray-500">{l.reference}</span> : null}</td>
                    <td className="px-3 py-2 text-xs">{t.accounting.journal.sources[l.source] ?? l.source}</td>
                    <td className="px-3 py-2 text-right">{l.entree ? montant(l.entree) : ''}</td>
                    <td className="px-3 py-2 text-right">{l.sortie ? montant(l.sortie) : ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {lignes.length === 0 && <p className="p-4 text-sm text-gray-500">{tr.noMovement}</p>}
          </div>
        </div>
      )}

      {historique.length > 0 && (
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
          <table className="w-full text-sm" aria-label={tr.history}>
            <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
              <tr>
                <th className="px-4 py-2 text-left">{tr.statementDate}</th>
                <th className="px-4 py-2 text-right">{tr.statementBalance}</th>
                <th className="px-4 py-2 text-left">{tr.status}</th>
                <th className="px-4 py-2 text-left">{tr.finishedBy}</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
              {historique.map(h => (
                <tr key={h.id} className={h.id === ouvert ? 'bg-blue-50/50 dark:bg-blue-900/10' : ''}>
                  <td className="px-4 py-2">{formatDateDocument(h.date_releve, language)}</td>
                  <td className="px-4 py-2 text-right">{montant(h.solde_releve)}</td>
                  <td className="px-4 py-2">{tr.statuses[h.statut]}</td>
                  <td className="px-4 py-2 text-xs text-gray-500">{h.termine_le ? `${formatDateHeure(h.termine_le, language)}${h.auteur_fin ? ` — ${h.auteur_fin.nom}` : ''}` : '—'}</td>
                  <td className="px-4 py-2 text-right">
                    <button type="button" onClick={() => setOuvert(h.id)} className="text-blue-700 dark:text-blue-300 hover:underline">{tr.open}</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
