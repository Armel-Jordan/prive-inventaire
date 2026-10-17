import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Eye, FileSignature, FileText, Plus, RefreshCw, Trash2, Undo2, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, formatDateDocument, useMontantDocument } from '@/lib/documents';
import { getClientsActifs, getProduits, getReferentiel, ouvrirPdf, type Client, type FicheReferentiel, type PageResultats } from '@/services/api';
import { FREQUENCES } from '@/services/abonnementsClients';
import {
  creerContrat, facturerContrat, facturerLoyers, getContrat, getContrats, modifierContrat, renouvelerContrat, retourLigne, supprimerContrat,
  type BilanLoyers, type ContratLocation, type LigneContrat, type SaisieContrat,
} from '@/services/contratsLocation';
import Pagination from '@/components/Pagination';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';
import type { Produit } from '@/types';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeLibelle = 'block text-xs text-gray-500 dark:text-gray-400';
const ONGLETS = ['actifs', 'inactifs', 'tous'] as const;
const date10 = (valeur: string | null | undefined) => (valeur ? valeur.slice(0, 10) : '');

/**
 * Contrats de location (plan de parité fripv2, phase 1.8 : « Contrats de location ») : des produits loués à un client.
 * Le prix de contrat se facture une fois (« Facturer le contrat ») ; le loyer, à chaque période (« Facturer les loyers
 * dus » ou la tâche planifiée). Retours d'unités, renouvellement, impression.
 */
export default function ContratsLocationPage() {
  const { t, language } = useLanguage();
  const tr = t.rentals;
  const { peut } = usePermissions();
  const montantDoc = useMontantDocument();
  const { toasts, toast, dismiss } = useToast();
  const [recherche, setRecherche] = useState('');
  const [terme, setTerme] = useState('');
  const [onglet, setOnglet] = useState<typeof ONGLETS[number]>('actifs');
  const [finAvant, setFinAvant] = useState('');
  const [page, setPage] = useState(1);
  const [resultat, setResultat] = useState<PageResultats<ContratLocation> | null>(null);
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);
  const [formulaire, setFormulaire] = useState<{ contrat?: ContratLocation } | null>(null);
  const [detail, setDetail] = useState<number | null>(null);
  const [loyers, setLoyers] = useState(false);

  useEffect(() => {
    const minuterie = setTimeout(() => { setTerme(recherche.trim()); setPage(1); }, 300);
    return () => clearTimeout(minuterie);
  }, [recherche]);

  useEffect(() => {
    let actif = true;
    getContrats({ statut: onglet, recherche: terme || undefined, fin_avant: finAvant || undefined, page }).then(
      r => { if (actif) { setResultat(r); setErreur(''); } },
      e => { if (actif) setErreur(messageErreur(e, tr.error)); },
    );
    return () => { actif = false; };
  }, [onglet, terme, finAvant, page, version, tr.error]);

  const recharger = () => setVersion(v => v + 1);
  const contrats = resultat?.data ?? [];

  return (
    <div className="p-4 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><FileSignature className="text-amber-600" /> {tr.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tr.subtitle}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {peut('contrats_location', 'facturer') && (
            <button type="button" onClick={() => setLoyers(true)} className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm border border-purple-300 text-purple-700 hover:bg-purple-50 dark:border-purple-500 dark:text-purple-300 dark:hover:bg-purple-900/30">
              <FileText size={18} /> {tr.invoiceRents}
            </button>
          )}
          {peut('contrats_location', 'creer') && (
            <button type="button" onClick={() => setFormulaire({})} className="flex items-center gap-2 bg-amber-600 text-white px-4 py-2 rounded-lg hover:bg-amber-700 text-sm">
              <Plus size={18} /> {tr.newContract}
            </button>
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-end gap-2 mb-4">
        <div className="flex flex-wrap gap-1" role="tablist" aria-label={tr.status}>
          {ONGLETS.map(o => (
            <button key={o} type="button" role="tab" aria-selected={onglet === o} onClick={() => { setOnglet(o); setPage(1); }}
              className={`px-3 py-1.5 rounded-lg text-sm ${onglet === o ? 'bg-amber-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'}`}>
              {tr.tabs[o]}
            </button>
          ))}
        </div>
        <label className={`${classeLibelle} flex-1 min-w-[12rem]`}>{tr.search}
          <input type="search" value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={tr.searchHelp} className={classeChamp} />
        </label>
        <label className={classeLibelle}>{tr.endingBefore}
          <input type="date" value={finAvant} onChange={e => { setFinAvant(e.target.value); setPage(1); }} className={classeChamp} />
        </label>
      </div>

      {erreur && <p role="alert" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
        <table className="w-full text-sm min-w-[60rem]">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-3 py-2 font-medium">{tr.number}</th>
              <th className="px-3 py-2 font-medium">{tr.customer}</th>
              <th className="px-3 py-2 font-medium">{tr.period}</th>
              <th className="px-3 py-2 font-medium text-right">{tr.units}</th>
              <th className="px-3 py-2 font-medium text-right">{tr.rentPerPeriod}</th>
              <th className="px-3 py-2 font-medium">{tr.nextBilling}</th>
              <th className="px-3 py-2 font-medium text-center">{tr.status}</th>
              <th className="px-3 py-2 font-medium text-center">{tr.actions}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
            {resultat === null && !erreur && <tr><td colSpan={8} className="px-4 py-6 text-center text-gray-500">{tr.loading}</td></tr>}
            {resultat !== null && contrats.length === 0 && <tr><td colSpan={8} className="px-4 py-6 text-center text-gray-500">{tr.empty}</td></tr>}
            {contrats.map(c => (
              <tr key={c.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50 align-top">
                <td className="px-3 py-2 font-mono text-gray-900 dark:text-white">
                  {c.numero}
                  {c.type && <span className="block text-xs font-sans text-gray-500">{c.type.code} — {c.type.nom}</span>}
                </td>
                <td className="px-3 py-2 text-gray-900 dark:text-white">{c.client?.raison_sociale}</td>
                <td className="px-3 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">
                  {formatDateDocument(c.date_debut, language)} – {formatDateDocument(c.date_fin, language)}
                  <span className="block text-xs text-gray-500">{tr.frequencies[c.frequence]} · {c.a_echoir ? tr.inAdvance : tr.inArrears}</span>
                </td>
                <td className="px-3 py-2 text-right text-gray-900 dark:text-white">{c.unites_louees}</td>
                <td className="px-3 py-2 text-right whitespace-nowrap text-gray-700 dark:text-gray-300">{montantDoc(c.loyer_periode, c.client?.devise ?? null)}</td>
                <td className="px-3 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">
                  {c.prochaine_facturation && date10(c.prochaine_facturation) <= date10(c.date_fin) ? formatDateDocument(c.prochaine_facturation, language) : '—'}
                  {c.a_facturer > 0 && <span className="block text-xs text-amber-700">{tr.contractToInvoice}</span>}
                </td>
                <td className="px-3 py-2 text-center">
                  <span className={`px-2 py-0.5 text-xs rounded-full whitespace-nowrap ${!c.actif ? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' : c.echu ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800'}`}>
                    {!c.actif ? tr.inactive : c.echu ? tr.ended : tr.active}
                  </span>
                  {c.renouvele_par_id && <span className="block text-xs text-gray-500 mt-0.5">{tr.renewed}</span>}
                </td>
                <td className="px-3 py-2">
                  <div className="flex justify-center gap-1">
                    <button type="button" onClick={() => setDetail(c.id)} title={tr.open} aria-label={`${tr.open} — ${c.numero}`}
                      className="p-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded"><Eye size={16} /></button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {resultat && <Pagination page={page} dernierePage={resultat.last_page ?? 1} total={resultat.total ?? 0} onPage={setPage} />}
      </div>

      {formulaire && (
        <FormulaireContrat contrat={formulaire.contrat} onClose={() => setFormulaire(null)}
          onEnregistre={c => { toast(formulaire.contrat ? tr.saved : tr.created.replace('{numero}', c.numero)); setFormulaire(null); setDetail(c.id); recharger(); }} />
      )}
      {detail !== null && (
        <DetailContrat id={detail} onClose={() => setDetail(null)} onModifier={c => { setDetail(null); setFormulaire({ contrat: c }); }}
          onOuvrir={id => setDetail(id)} onChange={recharger} toast={toast} />
      )}
      {loyers && <FacturerLoyers onClose={() => setLoyers(false)} onFacture={recharger} />}
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}

type Toast = ReturnType<typeof useToast>['toast'];

/** Fiche d'un contrat : lignes (retours), factures, et actions (facturer le contrat, renouveler, imprimer, modifier). */
function DetailContrat({ id, onClose, onModifier, onOuvrir, onChange, toast }: {
  id: number; onClose: () => void; onModifier: (c: ContratLocation) => void; onOuvrir: (id: number) => void; onChange: () => void; toast: Toast;
}) {
  const { t, language } = useLanguage();
  const tr = t.rentals;
  const { peut } = usePermissions();
  const montantDoc = useMontantDocument();
  const [contrat, setContrat] = useState<ContratLocation | null>(null);
  const [retour, setRetour] = useState<{ ligne: LigneContrat; quantite: string; date: string } | null>(null);
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    let actif = true;
    getContrat(id).then(c => { if (actif) setContrat(c); }, e => { if (actif) setErreur(messageErreur(e, tr.error)); });
    return () => { actif = false; };
  }, [id, tr.error]);

  async function agir(action: () => Promise<void>) {
    setErreur('');
    try { await action(); onChange(); } catch (e) { setErreur(messageErreur(e, tr.error)); }
  }

  if (!contrat) {
    return (
      <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div className="bg-white dark:bg-gray-800 rounded-xl p-6 text-sm text-gray-500">{erreur || tr.loading}</div>
      </div>
    );
  }
  const devise = contrat.client?.devise ?? null;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="contrat-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-4xl max-h-[92vh] overflow-y-auto space-y-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 id="contrat-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tr.contractTitle.replace('{numero}', contrat.numero)}</h2>
            <p className="text-sm text-gray-600 dark:text-gray-300">
              {contrat.client?.raison_sociale} · {formatDateDocument(contrat.date_debut, language)} – {formatDateDocument(contrat.date_fin, language)}
              {contrat.type && ` · ${contrat.type.code}`}
            </p>
            <p className="text-xs text-gray-500">
              {tr.frequencies[contrat.frequence]}, {tr.dayShort} {contrat.jour_facturation}, {contrat.a_echoir ? tr.inAdvance : tr.inArrears}
              {contrat.renouvellement_auto && ` · ${tr.autoRenewalShort.replace('{contrat}', String(Number(contrat.taux_contrat))).replace('{loyer}', String(Number(contrat.taux_location)))}`}
            </p>
            <p className="text-xs text-gray-500">
              {contrat.precedent && <>{tr.renewalOf} <button type="button" className="font-mono text-sky-700 hover:underline" onClick={() => onOuvrir(contrat.precedent!.id)}>{contrat.precedent.numero}</button> · </>}
              {contrat.renouvele_par && <>{tr.renewedBy} <button type="button" className="font-mono text-sky-700 hover:underline" onClick={() => onOuvrir(contrat.renouvele_par!.id)}>{contrat.renouvele_par.numero}</button></>}
            </p>
          </div>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tr.close}><X size={18} /></button>
        </div>

        <div className="flex flex-wrap gap-2">
          {peut('contrats_location', 'modifier') && (
            <button type="button" onClick={() => onModifier(contrat)} className="px-3 py-1.5 border rounded-lg text-sm dark:border-gray-600 dark:text-white">{tr.edit}</button>
          )}
          {peut('contrats_location', 'facturer') && contrat.a_facturer > 0 && (
            <button type="button" onClick={() => { void agir(async () => { const r = await facturerContrat(contrat.id); toast(r.message); setContrat(await getContrat(contrat.id)); }); }}
              className="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-sm bg-purple-600 text-white hover:bg-purple-700"><FileText size={14} /> {tr.invoiceContract}</button>
          )}
          {peut('contrats_location', 'renouveler') && !contrat.renouvele_par_id && (
            <button type="button" onClick={() => { if (confirm(tr.confirmRenew)) void agir(async () => { const suivant = await renouvelerContrat(contrat.id); toast(tr.renewedToast.replace('{numero}', suivant.numero)); onOuvrir(suivant.id); }); }}
              className="inline-flex items-center gap-1 px-3 py-1.5 border rounded-lg text-sm border-teal-300 text-teal-700 hover:bg-teal-50 dark:border-teal-600 dark:text-teal-300"><RefreshCw size={14} /> {tr.renew}</button>
          )}
          <button type="button" onClick={() => { ouvrirPdf(`/contrats-location/${contrat.id}/pdf`, `contrat-location-${contrat.numero}.pdf`).catch(e => setErreur(messageErreur(e, tr.error))); }}
            className="inline-flex items-center gap-1 px-3 py-1.5 border rounded-lg text-sm dark:border-gray-600 dark:text-white"><FileText size={14} /> {tr.print}</button>
          {peut('contrats_location', 'supprimer') && (contrat.factures?.length ?? 0) === 0 && contrat.lignes.every(l => !l.facture_id) && (
            <button type="button" onClick={() => { if (confirm(tr.confirmDelete.replace('{numero}', contrat.numero))) void agir(async () => { await supprimerContrat(contrat.id); toast(tr.deleted); onClose(); }); }}
              className="inline-flex items-center gap-1 px-3 py-1.5 border rounded-lg text-sm border-red-300 text-red-700 hover:bg-red-50"><Trash2 size={14} /> {tr.delete}</button>
          )}
        </div>
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

        <div className="overflow-x-auto">
          <table className="w-full text-sm min-w-[44rem]">
            <thead>
              <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                <th className="py-1">{tr.product}</th>
                <th className="py-1 text-right">{tr.quantity}</th>
                <th className="py-1 text-right">{tr.exempt}</th>
                <th className="py-1 text-right">{tr.returned}</th>
                <th className="py-1 text-right">{tr.contractPrice}</th>
                <th className="py-1 text-right">{tr.rent}</th>
                <th className="py-1 pl-4">{tr.invoice}</th>
                <th className="py-1" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
              {contrat.lignes.map(l => (
                <tr key={l.id} className="text-gray-800 dark:text-gray-200">
                  <td className="py-1.5">{l.produit?.description}<span className="block text-xs font-mono text-gray-500">{l.produit?.numero}</span></td>
                  <td className="py-1.5 text-right">{l.quantite}</td>
                  <td className="py-1.5 text-right">{l.quantite_exemptee || ''}</td>
                  <td className="py-1.5 text-right">{l.quantite_retournee ? `${l.quantite_retournee}${l.date_retour ? ` (${formatDateDocument(l.date_retour, language)})` : ''}` : ''}</td>
                  <td className="py-1.5 text-right whitespace-nowrap">{montantDoc(Number(l.prix_contrat), devise)}</td>
                  <td className="py-1.5 text-right whitespace-nowrap">{montantDoc(Number(l.prix_location), devise)}</td>
                  <td className="py-1.5 pl-4">{l.facture ? <Link to={`/factures?recherche=${encodeURIComponent(l.facture.numero)}`} className="font-mono text-sky-700 hover:underline">{l.facture.numero}</Link> : ''}</td>
                  <td className="py-1.5 text-right">
                    {peut('contrats_location', 'modifier') && l.quantite - l.quantite_retournee > 0 && (
                      <button type="button" onClick={() => setRetour({ ligne: l, quantite: String(l.quantite - l.quantite_retournee), date: aujourdhui() })}
                        className="inline-flex items-center gap-1 text-xs text-sky-700 hover:underline" aria-label={`${tr.returnUnits} — ${l.produit?.numero ?? ''}`}><Undo2 size={12} /> {tr.returnUnits}</button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="text-sm text-gray-700 dark:text-gray-300">{tr.rentLine.replace('{loyer}', montantDoc(contrat.loyer_periode, devise)).replace('{frequence}', tr.frequencies[contrat.frequence].toLowerCase())}
          {contrat.prochaine_facturation && date10(contrat.prochaine_facturation) <= date10(contrat.date_fin) && ` · ${tr.nextBilling} : ${formatDateDocument(contrat.prochaine_facturation, language)}`}</p>

        {retour && (
          <form onSubmit={e => { e.preventDefault(); void agir(async () => { setContrat(await retourLigne(contrat.id, retour.ligne.id, Number(retour.quantite), retour.date)); setRetour(null); toast(tr.returnedToast); }); }}
            className="flex flex-wrap items-end gap-2 rounded-lg bg-sky-50 dark:bg-sky-900/30 p-3">
            <p className="w-full text-sm text-sky-900 dark:text-sky-100">{tr.returnTitle.replace('{produit}', retour.ligne.produit?.description ?? '')}</p>
            <label className={classeLibelle}>{tr.quantity}
              <input type="number" min={1} max={retour.ligne.quantite - retour.ligne.quantite_retournee} required value={retour.quantite}
                onChange={e => setRetour({ ...retour, quantite: e.target.value })} className={`${classeChamp} w-28`} />
            </label>
            <label className={classeLibelle}>{tr.returnDate}
              <input type="date" required value={retour.date} onChange={e => setRetour({ ...retour, date: e.target.value })} className={classeChamp} />
            </label>
            <button type="submit" className="px-3 py-2 bg-sky-600 text-white rounded-lg text-sm hover:bg-sky-700">{tr.save}</button>
            <button type="button" onClick={() => setRetour(null)} className="px-3 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-white">{tr.cancel}</button>
          </form>
        )}

        <div>
          <h3 className="font-medium text-gray-900 dark:text-white mb-1">{tr.invoices}</h3>
          {(contrat.factures?.length ?? 0) === 0 ? <p className="text-sm text-gray-500">{tr.noInvoices}</p> : (
            <ul className="text-sm space-y-0.5">
              {contrat.factures!.map(f => (
                <li key={f.id} className="flex justify-between gap-2">
                  <Link to={`/factures?recherche=${encodeURIComponent(f.numero)}`} className="font-mono text-sky-700 hover:underline">{f.numero}</Link>
                  <span className="text-gray-600 dark:text-gray-300">{formatDateDocument(f.date_facture, language)} · {tr.invoiceStatuses[f.statut] ?? f.statut} · {montantDoc(Number(f.montant_ttc), f.devise ?? devise)}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
        {contrat.notes && <p className="text-sm text-gray-600 dark:text-gray-300 italic">{contrat.notes}</p>}
      </div>
    </div>
  );
}

type LigneSaisie = { id?: number; produit_id: string; quantite: string; quantite_exemptee: string; prix_contrat: string; prix_location: string; facturee: boolean };

/** Saisie d'un contrat (nouveau ou modifié) : en-tête, type (préremplit le rythme et la fin), lignes (facturées figées). */
function FormulaireContrat({ contrat, onClose, onEnregistre }: { contrat?: ContratLocation; onClose: () => void; onEnregistre: (c: ContratLocation) => void }) {
  const { t, language } = useLanguage();
  const tr = t.rentals;
  const [clients, setClients] = useState<Client[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [types, setTypes] = useState<FicheReferentiel[]>([]);
  const finParDefaut = (debut: string, mois: number) => { const d = new Date(`${debut}T12:00:00`); d.setMonth(d.getMonth() + mois); d.setDate(d.getDate() - 1); return d.toLocaleDateString('sv-SE'); };
  const [saisie, setSaisie] = useState({
    client_id: contrat ? String(contrat.client_id) : '',
    type_location_id: contrat?.type_location_id ? String(contrat.type_location_id) : '',
    date_debut: contrat ? date10(contrat.date_debut) : aujourdhui(),
    date_fin: contrat ? date10(contrat.date_fin) : finParDefaut(aujourdhui(), 12),
    frequence: contrat?.frequence ?? 'mensuelle',
    jour_facturation: contrat ? String(contrat.jour_facturation) : '1',
    a_echoir: contrat?.a_echoir ?? true,
    prochaine_facturation: contrat ? date10(contrat.prochaine_facturation) : '',
    actif: contrat?.actif ?? true,
    renouvellement_auto: contrat?.renouvellement_auto ?? false,
    taux_contrat: contrat ? String(Number(contrat.taux_contrat)) : '0',
    taux_location: contrat ? String(Number(contrat.taux_location)) : '0',
    notes: contrat?.notes ?? '',
  });
  const [lignes, setLignes] = useState<LigneSaisie[]>(contrat
    ? contrat.lignes.map(l => ({ id: l.id, produit_id: String(l.produit_id), quantite: String(l.quantite), quantite_exemptee: String(l.quantite_exemptee),
      prix_contrat: String(Number(l.prix_contrat)), prix_location: String(Number(l.prix_location)), facturee: !!l.facture_id }))
    : [{ produit_id: '', quantite: '1', quantite_exemptee: '0', prix_contrat: '0', prix_location: '0', facturee: false }]);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    Promise.all([getClientsActifs(), getProduits('actifs'), getReferentiel('types_location', true)]).then(([c, p, ty]) => {
      if (actif) { setClients(c.filter(x => !x.de_passage)); setProduits(p); setTypes(ty); }
    }, () => {});
    return () => { actif = false; };
  }, []);

  // Le type préremplit le rythme, le jour, le mode de facturation et la fin (début + durée).
  function choisirType(valeur: string) {
    const type = types.find(x => String(x.id) === valeur);
    setSaisie(s => !type ? { ...s, type_location_id: valeur } : {
      ...s, type_location_id: valeur, frequence: type.frequence as SaisieContrat['frequence'], jour_facturation: String(type.jour_facturation),
      a_echoir: Boolean(type.a_echoir), date_fin: finParDefaut(s.date_debut, Number(type.duree_mois) || 12),
    });
  }

  const modifierLigne = (i: number, changements: Partial<LigneSaisie>) => setLignes(ls => ls.map((l, j) => (j === i ? { ...l, ...changements } : l)));

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    const corps: SaisieContrat = {
      client_id: Number(saisie.client_id), type_location_id: saisie.type_location_id ? Number(saisie.type_location_id) : null,
      date_debut: saisie.date_debut, date_fin: saisie.date_fin, frequence: saisie.frequence, jour_facturation: Number(saisie.jour_facturation),
      a_echoir: saisie.a_echoir, prochaine_facturation: saisie.prochaine_facturation || null, actif: saisie.actif, renouvellement_auto: saisie.renouvellement_auto,
      taux_contrat: Number(saisie.taux_contrat) || 0, taux_location: Number(saisie.taux_location) || 0, notes: saisie.notes.trim() || null,
      lignes: lignes.map(l => ({ id: l.id, produit_id: Number(l.produit_id), quantite: Number(l.quantite), quantite_exemptee: Number(l.quantite_exemptee) || 0,
        prix_contrat: Number(l.prix_contrat) || 0, prix_location: Number(l.prix_location) || 0 })),
    };
    try {
      onEnregistre(contrat ? await modifierContrat(contrat.id, corps) : await creerContrat(corps));
    } catch (err) {
      setErreur(messageErreur(err, tr.error));
    } finally {
      setOccupe(false);
    }
  }

  const deja = !!contrat && ((contrat.factures?.length ?? 0) > 0 || contrat.lignes.some(l => l.facture_id));

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <form onSubmit={e => { void enregistrer(e); }} role="dialog" aria-modal="true" aria-labelledby="formulaire-contrat-titre"
        className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-4xl max-h-[92vh] overflow-y-auto space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="formulaire-contrat-titre" className="text-lg font-bold text-gray-900 dark:text-white">{contrat ? tr.editTitle.replace('{numero}', contrat.numero) : tr.newContract}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tr.close}><X size={18} /></button>
        </div>
        <div className="grid sm:grid-cols-3 gap-3">
          <label className={`${classeLibelle} sm:col-span-2`}>{tr.customer}
            <select required disabled={deja} value={saisie.client_id} onChange={e => setSaisie({ ...saisie, client_id: e.target.value })} className={classeChamp}>
              <option value="">{tr.chooseCustomer}</option>
              {clients.map(c => <option key={c.id} value={c.id}>{c.raison_sociale} ({c.code})</option>)}
            </select>
          </label>
          <label className={classeLibelle}>{tr.type}
            <select value={saisie.type_location_id} onChange={e => choisirType(e.target.value)} className={classeChamp}>
              <option value="">{tr.noType}</option>
              {types.map(ty => <option key={ty.id} value={ty.id}>{String(ty.code)} — {String(ty.nom)}</option>)}
            </select>
          </label>
          <label className={classeLibelle}>{tr.startDate}
            <input type="date" required value={saisie.date_debut} onChange={e => setSaisie({ ...saisie, date_debut: e.target.value })} className={classeChamp} />
          </label>
          <label className={classeLibelle}>{tr.endDate}
            <input type="date" required value={saisie.date_fin} onChange={e => setSaisie({ ...saisie, date_fin: e.target.value })} className={classeChamp} />
          </label>
          <label className={classeLibelle}>{tr.nextBilling}
            <input type="date" value={saisie.prochaine_facturation} onChange={e => setSaisie({ ...saisie, prochaine_facturation: e.target.value })} className={classeChamp} title={tr.nextBillingHelp} />
          </label>
          <label className={classeLibelle}>{tr.frequency}
            <select value={saisie.frequence} onChange={e => setSaisie({ ...saisie, frequence: e.target.value as SaisieContrat['frequence'] })} className={classeChamp}>
              {FREQUENCES.map(f => <option key={f} value={f}>{tr.frequencies[f]}</option>)}
            </select>
          </label>
          <label className={classeLibelle}>{tr.billingDay}
            <input type="number" min={1} max={28} required value={saisie.jour_facturation} onChange={e => setSaisie({ ...saisie, jour_facturation: e.target.value })} className={classeChamp} />
          </label>
          <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200 self-end pb-2">
            <input type="checkbox" checked={saisie.a_echoir} onChange={e => setSaisie({ ...saisie, a_echoir: e.target.checked })} /> {tr.inAdvanceLabel}
          </label>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-sm min-w-[44rem]">
            <thead>
              <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                <th className="py-1">{tr.product}</th>
                <th className="py-1 w-20">{tr.quantity}</th>
                <th className="py-1 w-20">{tr.exempt}</th>
                <th className="py-1 w-32">{tr.contractPrice}</th>
                <th className="py-1 w-32">{tr.rent}</th>
                <th className="py-1 w-8" />
              </tr>
            </thead>
            <tbody>
              {lignes.map((l, i) => (
                <tr key={l.id ?? `n${i}`} className={l.facturee ? 'opacity-70' : ''} title={l.facturee ? tr.invoicedLine : undefined}>
                  <td className="py-1 pr-2">
                    <select required disabled={l.facturee} value={l.produit_id} aria-label={`${tr.product} ${i + 1}`} className={classeChamp} onChange={e => {
                      const produit = produits.find(p => String(p.id) === e.target.value);
                      modifierLigne(i, { produit_id: e.target.value, prix_contrat: l.prix_contrat !== '0' ? l.prix_contrat : String(Number(produit?.prix_unitaire ?? 0)) });
                    }}>
                      <option value="">{tr.chooseProduct}</option>
                      {produits.map(p => <option key={p.id} value={p.id}>{p.numero} — {p.description}</option>)}
                    </select>
                  </td>
                  <td className="py-1 pr-2"><input type="number" min={1} required disabled={l.facturee} value={l.quantite} aria-label={`${tr.quantity} ${i + 1}`} onChange={e => modifierLigne(i, { quantite: e.target.value })} className={classeChamp} /></td>
                  <td className="py-1 pr-2"><input type="number" min={0} disabled={l.facturee} value={l.quantite_exemptee} aria-label={`${tr.exempt} ${i + 1}`} onChange={e => modifierLigne(i, { quantite_exemptee: e.target.value })} className={classeChamp} /></td>
                  <td className="py-1 pr-2"><input type="number" min={0} step="0.01" disabled={l.facturee} value={l.prix_contrat} aria-label={`${tr.contractPrice} ${i + 1}`} onChange={e => modifierLigne(i, { prix_contrat: e.target.value })} className={classeChamp} /></td>
                  <td className="py-1 pr-2"><input type="number" min={0} step="0.01" disabled={l.facturee} value={l.prix_location} aria-label={`${tr.rent} ${i + 1}`} onChange={e => modifierLigne(i, { prix_location: e.target.value })} className={classeChamp} /></td>
                  <td className="py-1">
                    {!l.facturee && lignes.length > 1 && (
                      <button type="button" onClick={() => setLignes(ls => ls.filter((_, j) => j !== i))} className="p-1 text-red-600 hover:bg-red-50 rounded" aria-label={`${tr.removeLine} ${i + 1}`}><Trash2 size={14} /></button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <button type="button" onClick={() => setLignes(ls => [...ls, { produit_id: '', quantite: '1', quantite_exemptee: '0', prix_contrat: '0', prix_location: '0', facturee: false }])}
            className="mt-1 inline-flex items-center gap-1 text-sm text-amber-700 hover:underline"><Plus size={14} /> {tr.addLine}</button>
          <p className="text-xs text-gray-500 mt-1">{tr.linesHelp}</p>
        </div>

        <div className="grid sm:grid-cols-4 gap-3 items-end">
          <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
            <input type="checkbox" checked={saisie.actif} onChange={e => setSaisie({ ...saisie, actif: e.target.checked })} /> {tr.active}
          </label>
          <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
            <input type="checkbox" checked={saisie.renouvellement_auto} onChange={e => setSaisie({ ...saisie, renouvellement_auto: e.target.checked })} /> {tr.autoRenewal}
          </label>
          {saisie.renouvellement_auto && (
            <>
              <label className={classeLibelle}>{tr.contractRate}
                <input type="number" min={0} max={100} step="0.01" value={saisie.taux_contrat} onChange={e => setSaisie({ ...saisie, taux_contrat: e.target.value })} className={classeChamp} />
              </label>
              <label className={classeLibelle}>{tr.rentRate}
                <input type="number" min={0} max={100} step="0.01" value={saisie.taux_location} onChange={e => setSaisie({ ...saisie, taux_location: e.target.value })} className={classeChamp} />
              </label>
            </>
          )}
        </div>
        <label className={classeLibelle}>{tr.notes}
          <textarea maxLength={1000} rows={2} value={saisie.notes} onChange={e => setSaisie({ ...saisie, notes: e.target.value })} className={classeChamp} />
        </label>
        {saisie.date_debut && <p className="text-xs text-gray-500">{tr.periodSummary.replace('{debut}', formatDateDocument(saisie.date_debut, language)).replace('{fin}', saisie.date_fin ? formatDateDocument(saisie.date_fin, language) : '—')}</p>}
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tr.cancel}</button>
          <button type="submit" disabled={occupe} className="px-4 py-2 bg-amber-600 text-white rounded-lg text-sm hover:bg-amber-700 disabled:opacity-50">{tr.save}</button>
        </div>
      </form>
    </div>
  );
}

/** Facturation des loyers dus jusqu'à une date : une facture par contrat et par période ; bilan. */
function FacturerLoyers({ onClose, onFacture }: { onClose: () => void; onFacture: () => void }) {
  const { t, language } = useLanguage();
  const tr = t.rentals;
  const { peut } = usePermissions();
  const montantDoc = useMontantDocument();
  const [date, setDate] = useState(aujourdhui());
  const [emettre, setEmettre] = useState(false);
  const [bilan, setBilan] = useState<BilanLoyers | null>(null);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  async function facturer() {
    setOccupe(true);
    setErreur('');
    try {
      setBilan(await facturerLoyers(date, null, emettre));
      onFacture();
    } catch (e) {
      setErreur(messageErreur(e, tr.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="facturer-loyers-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg space-y-3 max-h-[92vh] overflow-y-auto">
        <div className="flex items-start justify-between gap-3">
          <h2 id="facturer-loyers-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tr.invoiceRents}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tr.close}><X size={18} /></button>
        </div>
        {!bilan ? (
          <>
            <p className="text-sm text-gray-500 dark:text-gray-400">{tr.invoiceRentsHelp}</p>
            <label className={classeLibelle}>{tr.upTo}
              <input type="date" value={date} onChange={e => setDate(e.target.value)} className={classeChamp} />
            </label>
            {peut('factures', 'emettre') && (
              <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" checked={emettre} onChange={e => setEmettre(e.target.checked)} /> {tr.issueDirectly}
              </label>
            )}
            {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
            <div className="flex justify-end gap-2">
              <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tr.cancel}</button>
              <button type="button" disabled={occupe || !date} onClick={() => { void facturer(); }} className="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700 disabled:opacity-50">{tr.createInvoices}</button>
            </div>
          </>
        ) : (
          <>
            <p role="status" className="text-sm font-medium text-gray-900 dark:text-white">{bilan.message}</p>
            {bilan.factures.length > 0 && (
              <ul className="text-sm space-y-1">
                {bilan.factures.map(f => (
                  <li key={f.id} className="flex justify-between gap-2">
                    <Link to={`/factures?recherche=${encodeURIComponent(f.numero)}`} className="font-mono text-sky-700 hover:underline">{f.numero}</Link>
                    <span className="text-gray-600 dark:text-gray-300">{formatDateDocument(f.date_facture, language)} · {tr.invoiceStatuses[f.statut] ?? f.statut} · {montantDoc(Number(f.montant_ttc), f.devise)}</span>
                  </li>
                ))}
              </ul>
            )}
            {bilan.erreurs.length > 0 && (
              <ul role="alert" className="text-sm space-y-1 rounded-lg bg-red-50 text-red-700 px-3 py-2">
                {bilan.erreurs.map(e => <li key={e.contrat}>{e.contrat} ({e.client}) : {e.message}</li>)}
              </ul>
            )}
            <div className="flex justify-end">
              <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tr.close}</button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
