import { useEffect, useState } from 'react';
import { Download, Layers, X } from 'lucide-react';
import Pagination from '@/components/Pagination';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import {
  exporterLots, exporterRappelLot, getLot, getLots, getNumerosSerie, getRappelLot, modifierLot,
  type DetailLot, type LotRegistre, type NumeroSerieRegistre, type Page, type SortieLot,
} from '@/services/lots';

type Onglet = 'lots' | 'series';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeEntete = 'px-3 py-2 text-left text-xs font-medium text-gray-600 dark:text-gray-300';

/** Recherche lancée une fois la saisie posée. */
function useTerme(saisie: string): string {
  const [terme, setTerme] = useState('');
  useEffect(() => {
    const minuterie = setTimeout(() => setTerme(saisie.trim()), 300);
    return () => clearTimeout(minuterie);
  }, [saisie]);
  return terme;
}

/** Péremption d'un lot : la date, et l'état (périmé, à surveiller) en pastille. */
function Peremption({ lot }: { lot: LotRegistre }) {
  const { t, language } = useLanguage();
  const tl = t.lots;
  if (!lot.date_peremption) return <span className="text-gray-400">{tl.noDate}</span>;
  const n = lot.jours_restants ?? 0;
  const libelle = n < 0 ? tl.expired.replace('{n}', String(-n)) : n === 0 ? tl.expiresToday : tl.expiresIn.replace('{n}', String(n));
  const couleur = lot.etat === 'perime' ? 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'
    : lot.etat === 'a_surveiller' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'
      : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';
  return (
    <span className="whitespace-nowrap">
      {formatDateDocument(lot.date_peremption, language)}
      <span className={`ml-2 px-1.5 py-0.5 rounded text-xs font-medium ${couleur}`}>{libelle}</span>
    </span>
  );
}

/** Détail d'un lot : ses mouvements ; correction de la péremption et des notes (droit de modifier les lots). */
function FenetreLot({ id, modifiable, onClose, onModifie }: { id: number; modifiable: boolean; onClose: () => void; onModifie: () => void }) {
  const { t, language } = useLanguage();
  const tl = t.lots;
  const [lot, setLot] = useState<DetailLot | null>(null);
  // Rappel : les clients qui ont reçu le lot.
  const [sorties, setSorties] = useState<SortieLot[]>([]);
  const [saisie, setSaisie] = useState({ date_peremption: '', notes: '' });
  const [erreur, setErreur] = useState('');
  const [message, setMessage] = useState('');
  const quantite = (v: number) => v.toLocaleString(language === 'en' ? 'en-US' : 'fr-FR', { maximumFractionDigits: 4 });

  useEffect(() => {
    let actif = true;
    getLot(id).then(
      l => { if (actif) { setLot(l); setSaisie({ date_peremption: l.date_peremption ?? '', notes: l.notes ?? '' }); } },
      e => { if (actif) setErreur(messageErreur(e, tl.error)); },
    );
    getRappelLot(id).then(r => { if (actif) setSorties(r.sorties); }, () => {});
    return () => { actif = false; };
  }, [id, tl.error]);

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    setErreur('');
    try {
      const l = await modifierLot(id, { date_peremption: saisie.date_peremption || null, notes: saisie.notes.trim() || null });
      setLot(l);
      setMessage(tl.saved);
      onModifie();
    } catch (erreurApi) {
      setErreur(messageErreur(erreurApi, tl.error));
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div role="dialog" aria-modal="true" aria-label={tl.details} className="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
        <div className="flex items-center justify-between p-4 border-b dark:border-gray-700">
          <h2 className="text-lg font-semibold dark:text-white">{tl.details}{lot ? ` — ${lot.numero}` : ''}</h2>
          <button type="button" onClick={onClose} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label={tl.close}><X size={20} /></button>
        </div>
        <div className="p-4 space-y-4">
          {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</p>}
          {message && <p role="status" className="px-3 py-2 rounded-lg text-sm bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200">{message}</p>}
          {lot && (
            <>
              <dl className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                <div><dt className="text-xs text-gray-500">{tl.product}</dt><dd className="dark:text-white">{lot.produit?.numero} <span className="text-gray-500">{lot.produit?.description}</span></dd></div>
                <div><dt className="text-xs text-gray-500">{tl.supplier}</dt><dd className="dark:text-white">{lot.fournisseur ?? '—'}</dd></div>
                <div><dt className="text-xs text-gray-500">{tl.received}</dt><dd className="dark:text-white">{quantite(lot.recu)} {lot.produit?.mesure ?? ''}</dd></div>
                <div><dt className="text-xs text-gray-500">{tl.balance}</dt><dd className="font-semibold dark:text-white">{quantite(lot.solde)} {lot.produit?.mesure ?? ''}</dd></div>
              </dl>
              <form onSubmit={enregistrer} className="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                <label className="text-xs text-gray-500 dark:text-gray-400">{tl.expiry}
                  <input type="date" value={saisie.date_peremption} disabled={!modifiable} onChange={e => setSaisie({ ...saisie, date_peremption: e.target.value })} className={classeChamp} />
                </label>
                <label className="text-xs text-gray-500 dark:text-gray-400">{tl.notes}
                  <input value={saisie.notes} maxLength={2000} disabled={!modifiable} onChange={e => setSaisie({ ...saisie, notes: e.target.value })} className={classeChamp} />
                </label>
                {modifiable && <button type="submit" className="px-4 py-2 text-sm rounded-lg bg-purple-600 text-white hover:bg-purple-700">{tl.save}</button>}
              </form>
              <div>
                <h3 className="text-sm font-semibold mb-2 dark:text-white">{tl.movements}</h3>
                <div className="border dark:border-gray-700 rounded-lg overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="bg-gray-50 dark:bg-gray-700/50">
                      <tr>
                        <th className={classeEntete}>{tl.date}</th>
                        <th className={classeEntete}>{tl.movements}</th>
                        <th className={`${classeEntete} text-right`}>{tl.quantity}</th>
                        <th className={classeEntete}>{tl.reception}</th>
                        <th className={classeEntete}>{tl.order}</th>
                        <th className={classeEntete}>{tl.sector}</th>
                        <th className={classeEntete}>{tl.document}</th>
                        <th className={classeEntete}>{tl.client}</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y dark:divide-gray-700">
                      {lot.mouvements.map(m => (
                        <tr key={m.id} className="dark:text-gray-200">
                          <td className="px-3 py-2">{m.date ? formatDateDocument(m.date, language) : '—'}</td>
                          <td className="px-3 py-2">{tl.movementTypes[m.type] ?? m.type}</td>
                          <td className={`px-3 py-2 text-right tabular-nums ${m.quantite < 0 ? 'text-red-600' : ''}`}>{quantite(m.quantite)}</td>
                          <td className="px-3 py-2 font-mono text-xs">{m.reception?.numero ?? '—'}</td>
                          <td className="px-3 py-2 font-mono text-xs">{m.commande ?? '—'}</td>
                          <td className="px-3 py-2">{m.secteur ?? '—'}</td>
                          <td className="px-3 py-2 font-mono text-xs">{m.document ?? '—'}</td>
                          <td className="px-3 py-2">{m.client ?? '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
              <div>
                <div className="flex flex-wrap items-center justify-between gap-2 mb-1">
                  <h3 className="text-sm font-semibold dark:text-white">{tl.recall}</h3>
                  {sorties.length > 0 && (
                    <button type="button" onClick={() => exporterRappelLot(id, lot.numero).catch(e => setErreur(messageErreur(e, tl.error)))}
                      className="inline-flex items-center gap-1 px-2 py-1 border rounded text-xs text-gray-700 dark:border-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                      <Download size={14} /> {tl.recallExport}
                    </button>
                  )}
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">{tl.recallHelp}</p>
                {sorties.length === 0 ? (
                  <p className="text-sm text-gray-500 dark:text-gray-400">{tl.recallEmpty}</p>
                ) : (
                  <div className="border dark:border-gray-700 rounded-lg overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead className="bg-gray-50 dark:bg-gray-700/50">
                        <tr>
                          <th className={classeEntete}>{tl.date}</th>
                          <th className={classeEntete}>{tl.document}</th>
                          <th className={classeEntete}>{tl.client}</th>
                          <th className={classeEntete}>{tl.phone}</th>
                          <th className={`${classeEntete} text-right`}>{tl.quantity}</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y dark:divide-gray-700">
                        {sorties.map((s, index) => (
                          <tr key={index} className="dark:text-gray-200">
                            <td className="px-3 py-2">{s.date ? formatDateDocument(s.date, language) : '—'}</td>
                            <td className="px-3 py-2 font-mono text-xs">{s.document ?? '—'} <span className="font-sans text-gray-500">{tl.movementTypes[s.type] ?? s.type}</span></td>
                            <td className="px-3 py-2">{s.client ? `${s.client.code} — ${s.client.raison_sociale}` : '—'}</td>
                            <td className="px-3 py-2">{[s.client?.telephone, s.client?.email].filter(Boolean).join(' · ') || '—'}</td>
                            <td className="px-3 py-2 text-right tabular-nums">{quantite(s.quantite)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

function OngletLots() {
  const { t, language } = useLanguage();
  const tl = t.lots;
  const { canEdit } = usePermissions();
  const [saisie, setSaisie] = useState('');
  const recherche = useTerme(saisie);
  const [etat, setEtat] = useState('');
  const [avecStock, setAvecStock] = useState(false);
  const [page, setPage] = useState(1);
  const [version, setVersion] = useState(0);
  const [resultat, setResultat] = useState<(Page<LotRegistre> & { jours_alerte: number }) | null>(null);
  const [erreur, setErreur] = useState('');
  const [ouvert, setOuvert] = useState<number | null>(null);
  const quantite = (v: number) => v.toLocaleString(language === 'en' ? 'en-US' : 'fr-FR', { maximumFractionDigits: 4 });
  const filtres = { recherche: recherche || undefined, etat: etat || undefined, avec_stock: avecStock };

  useEffect(() => {
    let actif = true;
    getLots({ recherche: recherche || undefined, etat: etat || undefined, avec_stock: avecStock, page }).then(
      r => { if (actif) { setResultat(r); setErreur(''); } },
      e => { if (actif) setErreur(messageErreur(e, tl.error)); },
    );
    return () => { actif = false; };
  }, [recherche, etat, avecStock, page, version, tl.error]);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-end gap-2">
        <label className="text-xs text-gray-500 dark:text-gray-400 flex-1 min-w-[12rem]">{tl.search}
          <input type="search" value={saisie} onChange={e => { setSaisie(e.target.value); setPage(1); }} placeholder={tl.searchHelp} className={classeChamp} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400 w-48">{tl.state}
          <select value={etat} onChange={e => { setEtat(e.target.value); setPage(1); }} className={classeChamp}>
            {Object.entries(tl.states).map(([valeur, libelle]) => <option key={valeur} value={valeur}>{libelle}</option>)}
          </select>
        </label>
        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 pb-2">
          <input type="checkbox" checked={avecStock} onChange={e => { setAvecStock(e.target.checked); setPage(1); }} /> {tl.withStock}
        </label>
        <button type="button" onClick={() => exporterLots(filtres).catch(e => setErreur(messageErreur(e, tl.error)))} disabled={!resultat?.total}
          className="inline-flex items-center gap-2 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50">
          <Download size={16} /> {tl.export}
        </button>
      </div>
      {resultat && <p className="text-xs text-gray-500 dark:text-gray-400">{tl.watchHelp.replace('{n}', String(resultat.jours_alerte))}</p>}
      {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</p>}

      {resultat && (resultat.data.length === 0 ? (
        <p className="text-sm text-gray-500 dark:text-gray-400">{tl.empty}</p>
      ) : (
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-700 border-b dark:border-gray-600">
              <tr>
                <th className={classeEntete}>{tl.lot}</th>
                <th className={classeEntete}>{tl.product}</th>
                <th className={classeEntete}>{tl.expiry}</th>
                <th className={classeEntete}>{tl.supplier}</th>
                <th className={`${classeEntete} text-right`}>{tl.received}</th>
                <th className={`${classeEntete} text-right`}>{tl.balance}</th>
                <th className={classeEntete}>{tl.firstReceipt}</th>
              </tr>
            </thead>
            <tbody className="divide-y dark:divide-gray-700">
              {resultat.data.map(lot => (
                <tr key={lot.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50 dark:text-gray-200">
                  <td className="px-3 py-2">
                    <button type="button" onClick={() => setOuvert(lot.id)} className="font-mono font-medium text-purple-700 hover:underline dark:text-purple-300">{lot.numero}</button>
                  </td>
                  <td className="px-3 py-2">
                    <span className="font-mono">{lot.produit?.numero}</span>
                    <span className="block text-xs text-gray-500 dark:text-gray-400">{lot.produit?.description}</span>
                  </td>
                  <td className="px-3 py-2"><Peremption lot={lot} /></td>
                  <td className="px-3 py-2">{lot.fournisseur ?? '—'}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{quantite(lot.recu)}</td>
                  <td className={`px-3 py-2 text-right tabular-nums font-semibold ${lot.solde > 0 ? '' : 'text-gray-400'}`}>{quantite(lot.solde)} <span className="font-normal text-xs text-gray-500">{lot.produit?.mesure ?? ''}</span></td>
                  <td className="px-3 py-2">{lot.premiere_reception ? formatDateDocument(lot.premiere_reception, language) : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <Pagination page={resultat.current_page} dernierePage={resultat.last_page} total={resultat.total} onPage={setPage} />
        </div>
      ))}
      {ouvert !== null && <FenetreLot id={ouvert} modifiable={canEdit('lots')} onClose={() => setOuvert(null)} onModifie={() => setVersion(v => v + 1)} />}
    </div>
  );
}

function OngletSeries() {
  const { t, language } = useLanguage();
  const tl = t.lots;
  const [saisie, setSaisie] = useState('');
  const recherche = useTerme(saisie);
  const [statut, setStatut] = useState('');
  const [page, setPage] = useState(1);
  const [resultat, setResultat] = useState<Page<NumeroSerieRegistre> | null>(null);
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    let actif = true;
    getNumerosSerie({ recherche: recherche || undefined, statut: statut || undefined, page }).then(
      r => { if (actif) { setResultat(r); setErreur(''); } },
      e => { if (actif) setErreur(messageErreur(e, tl.error)); },
    );
    return () => { actif = false; };
  }, [recherche, statut, page, tl.error]);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-end gap-2">
        <label className="text-xs text-gray-500 dark:text-gray-400 flex-1 min-w-[12rem]">{tl.search}
          <input type="search" value={saisie} onChange={e => { setSaisie(e.target.value); setPage(1); }} placeholder={tl.searchSerial} className={classeChamp} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400 w-48">{tl.status}
          <select value={statut} onChange={e => { setStatut(e.target.value); setPage(1); }} className={classeChamp}>
            {Object.entries(tl.statuses).map(([valeur, libelle]) => <option key={valeur} value={valeur}>{libelle}</option>)}
          </select>
        </label>
      </div>
      {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</p>}
      {resultat && (resultat.data.length === 0 ? (
        <p className="text-sm text-gray-500 dark:text-gray-400">{tl.emptySerials}</p>
      ) : (
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-700 border-b dark:border-gray-600">
              <tr>
                <th className={classeEntete}>{tl.tabs.series}</th>
                <th className={classeEntete}>{tl.product}</th>
                <th className={classeEntete}>{tl.status}</th>
                <th className={classeEntete}>{tl.supplier}</th>
                <th className={classeEntete}>{tl.receivedOn}</th>
                <th className={classeEntete}>{tl.reception}</th>
                <th className={classeEntete}>{tl.client}</th>
                <th className={classeEntete}>{tl.deliveredOn}</th>
              </tr>
            </thead>
            <tbody className="divide-y dark:divide-gray-700">
              {resultat.data.map(s => (
                <tr key={s.id} className="dark:text-gray-200">
                  <td className="px-3 py-2 font-mono font-medium">{s.numero}</td>
                  <td className="px-3 py-2"><span className="font-mono">{s.produit?.numero}</span> <span className="text-gray-500 dark:text-gray-400">{s.produit?.description}</span></td>
                  <td className="px-3 py-2">{tl.statuses[s.statut] ?? s.statut}</td>
                  <td className="px-3 py-2">{s.fournisseur ?? '—'}</td>
                  <td className="px-3 py-2">{s.recu_le ? formatDateDocument(s.recu_le, language) : '—'}</td>
                  <td className="px-3 py-2 font-mono text-xs">{s.reception?.numero ?? '—'}</td>
                  <td className="px-3 py-2">{s.client ?? '—'}</td>
                  <td className="px-3 py-2">{s.livre_le ? formatDateDocument(s.livre_le, language) : '—'}{s.bon_livraison && <span className="block font-mono text-xs text-gray-500">{s.bon_livraison}</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <Pagination page={resultat.current_page} dernierePage={resultat.last_page} total={resultat.total} onPage={setPage} />
        </div>
      ))}
    </div>
  );
}

/**
 * Lots et numéros de série (plan de parité fripv2, phase 2.8a) : le registre des lots reçus (péremption, reçu,
 * reste) et celui des numéros de série des produits suivis.
 */
export default function LotsPage() {
  const { t } = useLanguage();
  const tl = t.lots;
  const [onglet, setOnglet] = useState<Onglet>('lots');

  return (
    <div className="p-4 sm:p-6 space-y-4">
      <div>
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Layers className="text-purple-600" /> {tl.title}</h1>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tl.subtitle}</p>
      </div>
      <div role="tablist" className="flex gap-1 border-b dark:border-gray-700">
        {(['lots', 'series'] as Onglet[]).map(o => (
          <button key={o} role="tab" type="button" aria-selected={onglet === o} onClick={() => setOnglet(o)}
            className={`px-4 py-2 text-sm border-b-2 -mb-px ${onglet === o ? 'border-purple-600 text-purple-700 dark:text-purple-300 font-medium' : 'border-transparent text-gray-600 dark:text-gray-300 hover:text-gray-900'}`}>
            {tl.tabs[o]}
          </button>
        ))}
      </div>
      {onglet === 'lots' ? <OngletLots /> : <OngletSeries />}
    </div>
  );
}
