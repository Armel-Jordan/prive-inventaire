import { useEffect, useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { ClipboardCheck, Plus } from 'lucide-react';
import { getSecteurs } from '@/services/api';
import type { Secteur } from '@/types';
import {
  creerPriseInventaire, getInventaireOfficiel, getPrisesInventaire,
  type LigneOfficielle, type Page, type PriseInventaire,
} from '@/services/prisesInventaire';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';
import { couleursStatutPrise, formatDateHeure, formatQuantite, peutGererPrises, versDateHeureLocale } from '@/components/prises-inventaire/format';

type Onglet = 'sessions' | 'officiel';

/** Nouvelle session : libellé, secteurs (ou tous), début du comptage, notes. */
function NouvellePrise({ secteurs, onCreee, onAnnuler }: { secteurs: Secteur[]; onCreee: () => void; onAnnuler: () => void }) {
  const { t } = useLanguage();
  const ts = t.stocktake;
  const id = useId();
  const [form, setForm] = useState({ libelle: '', tous: false, secteurIds: [] as number[], debut: versDateHeureLocale(new Date()), notes: '' });
  const [erreur, setErreur] = useState('');

  async function creer(e: React.FormEvent) {
    e.preventDefault();
    try {
      await creerPriseInventaire({
        libelle: form.libelle, tous_secteurs: form.tous, secteur_ids: form.tous ? [] : form.secteurIds,
        date_debut: new Date(form.debut).toISOString(), notes: form.notes || undefined,
      });
      onCreee();
    } catch (err) {
      setErreur(err instanceof ApiError ? err.message : ts.error);
    }
  }

  const basculer = (secteurId: number) => setForm(f => ({
    ...f, secteurIds: f.secteurIds.includes(secteurId) ? f.secteurIds.filter(i => i !== secteurId) : [...f.secteurIds, secteurId],
  }));
  const champ = 'w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

  return (
    <form onSubmit={creer} className="p-4 rounded-lg border border-purple-300 dark:border-purple-700 space-y-4">
      {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label htmlFor={`${id}-libelle`} className="block text-sm font-medium mb-1 dark:text-gray-300">{ts.label} *</label>
          <input id={`${id}-libelle`} required maxLength={150} placeholder={ts.labelHint} value={form.libelle} onChange={e => setForm({ ...form, libelle: e.target.value })} className={champ} />
        </div>
        <div>
          <label htmlFor={`${id}-debut`} className="block text-sm font-medium mb-1 dark:text-gray-300">{ts.start}</label>
          <input id={`${id}-debut`} type="datetime-local" required value={form.debut} onChange={e => setForm({ ...form, debut: e.target.value })} className={champ} aria-describedby={`${id}-debut-aide`} />
          <p id={`${id}-debut-aide`} className="text-xs text-gray-500 mt-1">{ts.startHelp}</p>
        </div>
      </div>
      <fieldset>
        <legend className="text-sm font-medium mb-2 dark:text-gray-300">{ts.sectors} *</legend>
        <label className="flex items-center gap-2 text-sm mb-2 dark:text-gray-300">
          <input type="checkbox" checked={form.tous} onChange={e => setForm({ ...form, tous: e.target.checked })} /> {ts.allSectors}
        </label>
        {!form.tous && (
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2 max-h-48 overflow-y-auto">
            {secteurs.map(s => (
              <label key={s.id} className="flex items-center gap-2 text-sm px-2 py-1 rounded border dark:border-gray-700 dark:text-gray-300">
                <input type="checkbox" checked={form.secteurIds.includes(s.id as number)} onChange={() => basculer(s.id as number)} />
                <span className="font-mono text-xs">{s.code}</span> {s.nom}
              </label>
            ))}
          </div>
        )}
      </fieldset>
      <div>
        <label htmlFor={`${id}-notes`} className="block text-sm font-medium mb-1 dark:text-gray-300">{ts.notes}</label>
        <textarea id={`${id}-notes`} rows={2} maxLength={2000} value={form.notes} onChange={e => setForm({ ...form, notes: e.target.value })} className={champ} />
      </div>
      <p className="text-xs text-gray-500 dark:text-gray-400">{ts.mobileNote}</p>
      <div className="flex justify-end gap-2">
        <button type="button" onClick={onAnnuler} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-white">{ts.cancel}</button>
        <button type="submit" disabled={!form.tous && form.secteurIds.length === 0} className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">{ts.create}</button>
      </div>
    </form>
  );
}

/** Inventaire officiel : dernière prise validée de chaque secteur. */
function InventaireOfficiel({ secteurs }: { secteurs: Secteur[] }) {
  const { t, language } = useLanguage();
  const ts = t.stocktake;
  const id = useId();
  const [filtres, setFiltres] = useState({ secteur_id: '', recherche: '' });
  const [page, setPage] = useState(1);
  const [resultat, setResultat] = useState<Page<LigneOfficielle> | null>(null);
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    let actif = true;
    const minuterie = setTimeout(() => {
      getInventaireOfficiel({ secteur_id: filtres.secteur_id ? Number(filtres.secteur_id) : undefined, recherche: filtres.recherche || undefined, page })
        .then(r => { if (actif) { setResultat(r); setErreur(''); } })
        .catch(e => { if (actif) setErreur(e instanceof ApiError ? e.message : ts.error); });
    }, 250);
    return () => { actif = false; clearTimeout(minuterie); };
  }, [filtres, page, ts.error]);

  const champ = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

  return (
    <div className="space-y-4">
      <p className="text-sm text-gray-500 dark:text-gray-400">{ts.officialHelp}</p>
      <div className="flex flex-wrap items-end gap-3">
        <div>
          <label htmlFor={`${id}-secteur`} className="block text-xs text-gray-500 mb-1">{ts.sector}</label>
          <select id={`${id}-secteur`} value={filtres.secteur_id} onChange={e => { setFiltres({ ...filtres, secteur_id: e.target.value }); setPage(1); }} className={champ}>
            <option value="">{ts.allSectorsFilter}</option>
            {secteurs.map(s => <option key={s.id} value={s.id}>{s.code} — {s.nom}</option>)}
          </select>
        </div>
        <div className="flex-1 min-w-[14rem]">
          <label htmlFor={`${id}-recherche`} className="block text-xs text-gray-500 mb-1">{ts.search}</label>
          <input id={`${id}-recherche`} type="search" value={filtres.recherche} onChange={e => { setFiltres({ ...filtres, recherche: e.target.value }); setPage(1); }} className={`${champ} w-full`} />
        </div>
      </div>
      {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
      {resultat && (resultat.total === 0 ? <p className="text-sm text-gray-500">{ts.officialEmpty}</p> : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                <tr>
                  <th className="px-3 py-2 text-left font-medium">{ts.sector}</th>
                  <th className="px-3 py-2 text-left font-medium">{ts.product}</th>
                  <th className="px-3 py-2 text-right font-medium">{ts.quantity}</th>
                  <th className="px-3 py-2 text-left font-medium">{ts.session}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {resultat.data.map(l => (
                  <tr key={`${l.secteur}|${l.produit_numero}`} className="text-gray-800 dark:text-gray-200">
                    <td className="px-3 py-2 font-mono text-xs">{l.secteur}</td>
                    <td className="px-3 py-2"><span className="font-mono text-xs text-gray-500 mr-2">{l.produit_numero}</span>{l.description}</td>
                    <td className="px-3 py-2 text-right font-medium">{formatQuantite(l.quantite, language)}</td>
                    <td className="px-3 py-2 text-xs">{l.prise && <Link to={`/prises-inventaire/${l.prise.id}`} className="text-purple-700 dark:text-purple-300 hover:underline">{l.prise.numero}</Link>} {formatDateHeure(l.prise?.date, language)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {resultat.last_page > 1 && (
            <div className="flex items-center justify-end gap-3 text-sm">
              <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="px-3 py-1 rounded border dark:border-gray-600 dark:text-white disabled:opacity-40">{ts.previous}</button>
              <span className="text-gray-600 dark:text-gray-300">{ts.page.replace('{page}', String(resultat.current_page)).replace('{pages}', String(resultat.last_page))}</span>
              <button disabled={page >= resultat.last_page} onClick={() => setPage(p => p + 1)} className="px-3 py-1 rounded border dark:border-gray-600 dark:text-white disabled:opacity-40">{ts.next}</button>
            </div>
          )}
        </>
      ))}
    </div>
  );
}

/** Prises d'inventaire : sessions de comptage et inventaire officiel. */
export default function PrisesInventairePage() {
  const { t, language } = useLanguage();
  const ts = t.stocktake;
  const id = useId();
  const { permissions } = usePermissions();
  const gestion = peutGererPrises(permissions?.role);
  const [onglet, setOnglet] = useState<Onglet>('sessions');
  const [prises, setPrises] = useState<PriseInventaire[] | null>(null);
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [creation, setCreation] = useState(false);
  const [version, setVersion] = useState(0);
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    let actif = true;
    getPrisesInventaire().then(p => { if (actif) setPrises(p); }).catch(e => { if (actif) setErreur(e instanceof ApiError ? e.message : ts.error); });
    return () => { actif = false; };
  }, [version, ts.error]);

  useEffect(() => {
    let actif = true;
    getSecteurs().then(s => { if (actif) setSecteurs(s); }).catch(() => {});
    return () => { actif = false; };
  }, []);

  const onglets: { cle: Onglet; libelle: string }[] = [{ cle: 'sessions', libelle: ts.tabs.sessions }, { cle: 'officiel', libelle: ts.tabs.official }];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><ClipboardCheck className="text-purple-600" /> {ts.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{ts.subtitle}</p>
        </div>
        {gestion && onglet === 'sessions' && !creation && (
          <button onClick={() => setCreation(true)} className="flex items-center gap-2 px-4 py-2 text-sm rounded-lg bg-purple-600 text-white hover:bg-purple-700"><Plus size={16} /> {ts.new}</button>
        )}
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md">
        <div role="tablist" aria-label={ts.title} className="flex flex-wrap gap-1 border-b dark:border-gray-700 px-4">
          {onglets.map(o => (
            <button key={o.cle} role="tab" id={`${id}-onglet-${o.cle}`} aria-selected={onglet === o.cle} aria-controls={`${id}-panneau`} onClick={() => setOnglet(o.cle)}
              className={`px-4 py-3 text-sm font-medium border-b-2 -mb-px ${onglet === o.cle ? 'border-purple-600 text-purple-700 dark:text-purple-300' : 'border-transparent text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white'}`}>
              {o.libelle}
            </button>
          ))}
        </div>
        <div role="tabpanel" id={`${id}-panneau`} aria-labelledby={`${id}-onglet-${onglet}`} className="p-6 space-y-4">
          {onglet === 'officiel' ? <InventaireOfficiel secteurs={secteurs} /> : (
            <>
              {creation && <NouvellePrise secteurs={secteurs} onAnnuler={() => setCreation(false)} onCreee={() => { setCreation(false); setVersion(v => v + 1); }} />}
              {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
              {prises?.length === 0 && !creation && <p className="text-sm text-gray-500 dark:text-gray-400">{ts.empty}</p>}
              {prises && prises.length > 0 && (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                      <tr>
                        <th className="px-3 py-2 text-left font-medium">{ts.number}</th>
                        <th className="px-3 py-2 text-left font-medium">{ts.label}</th>
                        <th className="px-3 py-2 text-left font-medium">{ts.sectors}</th>
                        <th className="px-3 py-2 text-left font-medium">{ts.period}</th>
                        <th className="px-3 py-2 text-center font-medium">{ts.status}</th>
                        <th className="px-3 py-2 text-right font-medium">{ts.lines}</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                      {prises.map(p => (
                        <tr key={p.id} className="text-gray-800 dark:text-gray-200">
                          <td className="px-3 py-2"><Link to={`/prises-inventaire/${p.id}`} className="font-mono text-purple-700 dark:text-purple-300 hover:underline">{p.numero}</Link></td>
                          <td className="px-3 py-2">{p.libelle}{p.createur && <span className="block text-xs text-gray-500">{ts.createdBy} {p.createur.nom}</span>}</td>
                          <td className="px-3 py-2 text-xs">{p.tous_secteurs ? ts.allSectors : p.secteurs.map(s => s.code).join(', ')}</td>
                          <td className="px-3 py-2 text-xs whitespace-nowrap">
                            {p.date_fin
                              ? ts.fromTo.replace('{debut}', formatDateHeure(p.date_debut, language)).replace('{fin}', formatDateHeure(p.date_fin, language))
                              : ts.ongoingSince.replace('{date}', formatDateHeure(p.date_debut, language))}
                          </td>
                          <td className="px-3 py-2 text-center"><span className={`px-2 py-0.5 rounded-full text-xs ${couleursStatutPrise[p.statut]}`}>{ts.statuses[p.statut]}</span></td>
                          <td className="px-3 py-2 text-right">{p.statut === 'validee' ? p.lignes_count : '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  );
}
