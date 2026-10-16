import { useEffect, useId, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, CheckCircle2, Download, RefreshCw, XCircle } from 'lucide-react';
import {
  annulerPriseInventaire, corrigerLignePrise, exporterPriseInventaire, getLignesPrise, getPriseInventaire, validerPriseInventaire,
  type DetailPrise, type LignePrise, type Page,
} from '@/services/prisesInventaire';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';
import {
  couleursStatutLigne, couleursStatutPrise, formatDateHeure, formatEcart, formatQuantite,
} from '@/components/prises-inventaire/format';

type Onglet = 'lignes' | 'avancement';
type Filtres = { statut: string; secteur: string; recherche: string };

/** Ligne des écarts, avec correction en place (quantité retenue et raison) pour les responsables. */
function LigneEcart({ ligne, modifiable, onCorriger }: { ligne: LignePrise; modifiable: boolean; onCorriger: (quantite: number | null, raison: string) => Promise<void> }) {
  const { t, language } = useLanguage();
  const ts = t.stocktake;
  const [edition, setEdition] = useState<{ quantite: string; raison: string } | null>(null);
  const q = (v: number | null) => formatQuantite(v, language);
  const couleurEcart = ligne.ecart === null || ligne.ecart === 0 ? 'text-gray-700 dark:text-gray-300' : ligne.ecart < 0 ? 'text-red-700 dark:text-red-400' : 'text-amber-700 dark:text-amber-400';

  return (
    <tr className="text-gray-800 dark:text-gray-200 align-top">
      <td className="px-3 py-2 font-mono text-xs">{ligne.secteur}</td>
      <td className="px-3 py-2"><span className="font-mono text-xs text-gray-500 mr-2">{ligne.produit_numero}</span>{ligne.description}</td>
      <td className="px-3 py-2 text-right whitespace-nowrap">{q(ligne.quantite_comptee)}<span className="block text-xs text-gray-500">{ts.scans.replace('{n}', String(ligne.nb_scans))}</span></td>
      <td className="px-3 py-2 text-right">{q(ligne.quantite_reference)}</td>
      <td className="px-3 py-2 text-right text-xs whitespace-nowrap text-gray-600 dark:text-gray-300">
        {ligne.entrees_depuis === null ? '—' : <>+{q(ligne.entrees_depuis)} / −{q(ligne.sorties_depuis)}</>}
      </td>
      <td className="px-3 py-2 text-right">
        {edition ? (
          <form className="flex flex-col items-end gap-1" onSubmit={async e => {
            e.preventDefault();
            await onCorriger(edition.quantite.trim() === '' ? null : Number(edition.quantite.replace(',', '.')), edition.raison);
            setEdition(null);
          }}>
            <input aria-label={`${ts.retained} — ${ligne.produit_numero}`} inputMode="decimal" required value={edition.quantite} autoFocus
              onChange={e => setEdition({ ...edition, quantite: e.target.value })} className="w-24 px-2 py-1 border rounded text-sm text-right dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
            <input aria-label={`${ts.reason} — ${ligne.produit_numero}`} placeholder={ts.reason} maxLength={255} value={edition.raison}
              onChange={e => setEdition({ ...edition, raison: e.target.value })} className="w-48 px-2 py-1 border rounded text-xs dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
            <span className="flex gap-1">
              <button type="button" onClick={() => setEdition(null)} className="px-2 py-0.5 text-xs border rounded dark:border-gray-600 dark:text-white">{ts.cancel}</button>
              <button type="submit" className="px-2 py-0.5 text-xs bg-purple-600 text-white rounded">{ts.save}</button>
            </span>
          </form>
        ) : (
          <>
            <span className={ligne.corrigee ? 'font-semibold text-purple-700 dark:text-purple-300' : 'font-medium'}>{q(ligne.quantite_retenue)}</span>
            {ligne.raison && <span className="block text-xs text-gray-500 italic">{ligne.raison}</span>}
            {modifiable && (
              <span className="flex justify-end gap-2 mt-0.5">
                <button onClick={() => setEdition({ quantite: String(ligne.quantite_retenue), raison: ligne.raison ?? '' })} className="text-xs text-purple-700 dark:text-purple-300 hover:underline">{ts.correct}</button>
                {ligne.corrigee && <button onClick={() => onCorriger(null, '')} className="text-xs text-gray-500 hover:underline">{ts.removeCorrection}</button>}
              </span>
            )}
          </>
        )}
      </td>
      <td className={`px-3 py-2 text-right font-semibold ${couleurEcart}`}>{formatEcart(ligne.ecart, language)}</td>
      <td className="px-3 py-2 text-center">
        <span title={ts.lineStatusHelp[ligne.statut]} className={`px-2 py-0.5 rounded-full text-xs whitespace-nowrap ${couleursStatutLigne[ligne.statut]}`}>{ts.lineStatuses[ligne.statut]}</span>
        {ligne.corrigee && <span className="block mt-1 text-[11px] text-purple-700 dark:text-purple-300">{ts.corrected}</span>}
      </td>
    </tr>
  );
}

/** Prise d'inventaire : écarts avec le dernier inventaire validé, avancement, corrections, validation. */
export default function PriseInventairePage() {
  const { id: idParam } = useParams();
  const priseId = Number(idParam);
  const { t, language } = useLanguage();
  const ts = t.stocktake;
  const id = useId();
  const { peut } = usePermissions();
  const [detail, setDetail] = useState<DetailPrise | null>(null);
  const [onglet, setOnglet] = useState<Onglet>('lignes');
  const [filtres, setFiltres] = useState<Filtres>({ statut: '', secteur: '', recherche: '' });
  const [page, setPage] = useState(1);
  const [lignes, setLignes] = useState<Page<LignePrise> | null>(null);
  const [version, setVersion] = useState(0);
  const [confirmation, setConfirmation] = useState(false);
  const [message, setMessage] = useState<{ ok: boolean; texte: string } | null>(null);

  useEffect(() => {
    let actif = true;
    getPriseInventaire(priseId)
      .then(d => { if (actif) setDetail(d); })
      .catch(e => { if (actif) setMessage({ ok: false, texte: e instanceof ApiError ? e.message : ts.error }); });
    return () => { actif = false; };
  }, [priseId, version, ts.error]);

  useEffect(() => {
    let actif = true;
    const minuterie = setTimeout(() => {
      getLignesPrise(priseId, { ...filtres, page })
        .then(l => { if (actif) setLignes(l); })
        .catch(e => { if (actif) setMessage({ ok: false, texte: e instanceof ApiError ? e.message : ts.error }); });
    }, 250);
    return () => { actif = false; clearTimeout(minuterie); };
  }, [priseId, filtres, page, version, ts.error]);

  if (!detail) {
    return (
      <div className="space-y-4">
        <Link to="/prises-inventaire" className="inline-flex items-center gap-1 text-sm text-purple-700 dark:text-purple-300 hover:underline"><ArrowLeft size={14} /> {ts.back}</Link>
        {message && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{message.texte}</p>}
      </div>
    );
  }

  const { prise, avancement, resume } = detail;
  const enCours = prise.statut === 'en_cours';
  const gestion = peut('inventaires', 'prises') && enCours;

  async function executer(action: () => Promise<unknown>, succes?: string) {
    try {
      await action();
      setMessage(succes ? { ok: true, texte: succes } : null);
      setVersion(v => v + 1);
    } catch (e) {
      setMessage({ ok: false, texte: e instanceof ApiError ? e.message : ts.error });
    }
  }

  const changerFiltre = (cle: keyof Filtres, valeur: string) => { setFiltres(f => ({ ...f, [cle]: valeur })); setPage(1); };
  const champ = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
  const onglets: { cle: Onglet; libelle: string }[] = [{ cle: 'lignes', libelle: ts.tabs.lines }, { cle: 'avancement', libelle: ts.tabs.progress }];

  return (
    <div className="space-y-6">
      <Link to="/prises-inventaire" className="inline-flex items-center gap-1 text-sm text-purple-700 dark:text-purple-300 hover:underline"><ArrowLeft size={14} /> {ts.back}</Link>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 space-y-4">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold text-gray-800 dark:text-white">
              <span className="font-mono">{prise.numero}</span> — {prise.libelle}
              <span className={`ml-3 align-middle px-2 py-0.5 rounded-full text-xs font-medium ${couleursStatutPrise[prise.statut]}`}>{ts.statuses[prise.statut]}</span>
            </h1>
            <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">
              {prise.date_fin
                ? ts.fromTo.replace('{debut}', formatDateHeure(prise.date_debut, language)).replace('{fin}', formatDateHeure(prise.date_fin, language))
                : ts.ongoingSince.replace('{date}', formatDateHeure(prise.date_debut, language))}
              {' · '}{prise.tous_secteurs ? ts.allSectors : prise.secteurs.map(s => `${s.code} ${s.nom}`).join(', ')}
            </p>
            {prise.cloture_par && prise.cloturee_le && (
              <p className="text-xs text-gray-500">{ts.closedBy.replace('{nom}', prise.cloture_par.nom).replace('{date}', formatDateHeure(prise.cloturee_le, language))}</p>
            )}
            {prise.notes && <p className="mt-1 text-sm text-gray-500 whitespace-pre-line">{prise.notes}</p>}
          </div>
          <div className="flex flex-wrap gap-2">
            {enCours && (
              <button onClick={() => setVersion(v => v + 1)} className="flex items-center gap-1 px-3 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-700"><RefreshCw size={14} /> {ts.refresh}</button>
            )}
            <button onClick={() => executer(() => exporterPriseInventaire(prise.id, prise.numero, filtres))} className="flex items-center gap-1 px-3 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-700"><Download size={14} /> {ts.export}</button>
            {gestion && !confirmation && (
              <>
                <button onClick={() => { if (confirm(ts.confirmCancel.replace('{numero}', prise.numero))) executer(() => annulerPriseInventaire(prise.id)); }}
                  className="flex items-center gap-1 px-3 py-2 text-sm border border-red-300 text-red-700 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20"><XCircle size={14} /> {ts.cancelSession}</button>
                <button onClick={() => setConfirmation(true)} className="flex items-center gap-1 px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700"><CheckCircle2 size={14} /> {ts.validate}</button>
              </>
            )}
          </div>
        </div>

        {confirmation && (
          <div role="alertdialog" aria-labelledby={`${id}-confirmation`} className="p-4 rounded-lg border-2 border-purple-300 dark:border-purple-700 space-y-2">
            <p id={`${id}-confirmation`} className="font-semibold text-gray-900 dark:text-white">{ts.validateTitle}</p>
            <p className="text-sm text-gray-700 dark:text-gray-300">{ts.validateText.replace('{lignes}', String(resume.lignes))}</p>
            {resume.non_compte > 0 && <p className="text-sm text-amber-700 dark:text-amber-400">{ts.validateUncounted.replace('{n}', String(resume.non_compte))}</p>}
            <div className="flex justify-end gap-2">
              <button onClick={() => setConfirmation(false)} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-white">{ts.cancel}</button>
              <button onClick={() => { setConfirmation(false); executer(() => validerPriseInventaire(prise.id)); }} className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{ts.confirmValidate}</button>
            </div>
          </div>
        )}

        {message && <p role={message.ok ? 'status' : 'alert'} className={`px-3 py-2 rounded-lg text-sm ${message.ok ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>{message.texte}</p>}

        <dl className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
          {(['lignes', 'ecart', 'non_compte', 'nouveau', 'conforme', 'corrigees'] as const).map(cle => (
            <div key={cle} className="p-3 rounded-lg border dark:border-gray-700">
              <dt className="text-xs text-gray-500 dark:text-gray-400">{ts.summary[cle]}</dt>
              <dd className="text-xl font-semibold text-gray-900 dark:text-white">{resume[cle]}</dd>
            </div>
          ))}
        </dl>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md">
        <div role="tablist" aria-label={prise.numero} className="flex flex-wrap gap-1 border-b dark:border-gray-700 px-4">
          {onglets.map(o => (
            <button key={o.cle} role="tab" id={`${id}-onglet-${o.cle}`} aria-selected={onglet === o.cle} aria-controls={`${id}-panneau`} onClick={() => setOnglet(o.cle)}
              className={`px-4 py-3 text-sm font-medium border-b-2 -mb-px ${onglet === o.cle ? 'border-purple-600 text-purple-700 dark:text-purple-300' : 'border-transparent text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white'}`}>
              {o.libelle}
            </button>
          ))}
        </div>
        <div role="tabpanel" id={`${id}-panneau`} aria-labelledby={`${id}-onglet-${onglet}`} className="p-6 space-y-4">
          {onglet === 'avancement' ? (
            <ul className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
              {avancement.map(s => (
                <li key={`${s.secteur_id ?? s.code}`} className={`p-4 rounded-lg border ${s.nb_scans > 0 ? 'border-green-300 dark:border-green-800' : 'dark:border-gray-700'}`}>
                  <p className="font-medium text-gray-900 dark:text-white"><span className="font-mono">{s.code}</span> {s.nom ?? <span className="text-xs text-amber-700">{ts.unknownSector}</span>}</p>
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    {s.nb_scans > 0
                      ? [ts.progressScans.replace('{n}', String(s.nb_scans)), ts.progressProducts.replace('{n}', String(s.nb_produits)), ts.progressEmployees.replace('{n}', String(s.nb_employes))].join(' · ')
                      : ts.notStarted}
                  </p>
                  {s.dernier_scan && <p className="text-xs text-gray-500">{ts.lastScan.replace('{date}', formatDateHeure(s.dernier_scan, language))}</p>}
                  <p className="text-xs text-gray-500 mt-1">
                    {s.reference ? ts.lastInventory.replace('{numero}', s.reference.numero).replace('{date}', formatDateHeure(s.reference.date, language)) : ts.neverCounted}
                  </p>
                </li>
              ))}
            </ul>
          ) : (
            <>
              <div className="flex flex-wrap items-end gap-3">
                <div>
                  <label htmlFor={`${id}-statut`} className="block text-xs text-gray-500 mb-1">{ts.status}</label>
                  <select id={`${id}-statut`} value={filtres.statut} onChange={e => changerFiltre('statut', e.target.value)} className={champ}>
                    <option value="">{ts.allLines}</option>
                    {Object.entries(ts.lineStatuses).map(([cle, libelle]) => <option key={cle} value={cle}>{libelle}</option>)}
                    <option value="corrigee">{ts.corrected}</option>
                  </select>
                </div>
                <div>
                  <label htmlFor={`${id}-secteur`} className="block text-xs text-gray-500 mb-1">{ts.sector}</label>
                  <select id={`${id}-secteur`} value={filtres.secteur} onChange={e => changerFiltre('secteur', e.target.value)} className={champ}>
                    <option value="">{ts.allSectorsFilter}</option>
                    {avancement.map(s => <option key={`${s.secteur_id ?? s.code}`} value={s.code}>{s.code}{s.nom ? ` — ${s.nom}` : ''}</option>)}
                  </select>
                </div>
                <div className="flex-1 min-w-[14rem]">
                  <label htmlFor={`${id}-recherche`} className="block text-xs text-gray-500 mb-1">{ts.search}</label>
                  <input id={`${id}-recherche`} type="search" value={filtres.recherche} onChange={e => changerFiltre('recherche', e.target.value)} className={`${champ} w-full`} />
                </div>
              </div>
              <p className="text-xs text-gray-500 dark:text-gray-400">{ts.movementsHelp}</p>
              {lignes && (lignes.total === 0 ? <p className="text-sm text-gray-500">{ts.noLines}</p> : (
                <>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead className="bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                        <tr>
                          <th className="px-3 py-2 text-left font-medium">{ts.sector}</th>
                          <th className="px-3 py-2 text-left font-medium">{ts.product}</th>
                          <th className="px-3 py-2 text-right font-medium">{ts.counted}</th>
                          <th className="px-3 py-2 text-right font-medium">{ts.reference}</th>
                          <th className="px-3 py-2 text-right font-medium">{ts.movements}</th>
                          <th className="px-3 py-2 text-right font-medium">{ts.retained}</th>
                          <th className="px-3 py-2 text-right font-medium">{ts.variance}</th>
                          <th className="px-3 py-2 text-center font-medium">{ts.status}</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                        {lignes.data.map(l => (
                          <LigneEcart key={`${l.secteur}|${l.produit_numero}`} ligne={l} modifiable={gestion}
                            onCorriger={(quantite, raison) => executer(() => corrigerLignePrise(prise.id, { secteur: l.secteur, produit_numero: l.produit_numero, quantite_retenue: quantite, raison: raison || null }))} />
                        ))}
                      </tbody>
                    </table>
                  </div>
                  {lignes.last_page > 1 && (
                    <div className="flex items-center justify-end gap-3 text-sm">
                      <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="px-3 py-1 rounded border dark:border-gray-600 dark:text-white disabled:opacity-40">{ts.previous}</button>
                      <span className="text-gray-600 dark:text-gray-300">{ts.page.replace('{page}', String(lignes.current_page)).replace('{pages}', String(lignes.last_page))}</span>
                      <button disabled={page >= lignes.last_page} onClick={() => setPage(p => p + 1)} className="px-3 py-1 rounded border dark:border-gray-600 dark:text-white disabled:opacity-40">{ts.next}</button>
                    </div>
                  )}
                </>
              ))}
            </>
          )}
        </div>
      </div>
    </div>
  );
}
