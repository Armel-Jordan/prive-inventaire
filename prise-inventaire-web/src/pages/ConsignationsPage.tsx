import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { FileText, PackageOpen, Plus, Trash2, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, formatDateDocument, useMontantDocument } from '@/lib/documents';
import { getClientsActifs, getProduits, getSecteurs, ouvrirPdf, type Client, type PageResultats } from '@/services/api';
import {
  cheminRapportPdf, creerBonDepot, facturerConsommation, getBonsDepot, getHistoriqueConsignation, getRapportConsignations, getReleveConsignation,
  reprendreConsignation, type BonDepot, type MouvementConsignation, type RapportConsignations, type ReleveConsignation,
} from '@/services/consignations';
import Pagination from '@/components/Pagination';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';
import type { Produit, Secteur } from '@/types';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeLibelle = 'block text-xs text-gray-500 dark:text-gray-400';
const ONGLETS = ['releve', 'depots', 'historique', 'rapport'] as const;
type Toast = ReturnType<typeof useToast>['toast'];

/**
 * Consignation chez le client (plan de parité fripv2, phase 1.9) : de la marchandise déposée chez un client reste à
 * l'entreprise. Le relevé montre ce qu'il détient ; on reprend ce qu'il rend, on facture ce qu'il a consommé. Bons de
 * dépôt, historique et rapport des consignations chez les clients.
 */
export default function ConsignationsPage() {
  const { t } = useLanguage();
  const tc = t.consignment;
  const { toasts, toast, dismiss } = useToast();
  const [onglet, setOnglet] = useState<typeof ONGLETS[number]>('releve');
  const [clients, setClients] = useState<Client[]>([]);

  useEffect(() => {
    let actif = true;
    getClientsActifs().then(c => { if (actif) setClients(c.filter(x => !x.de_passage)); }, () => {});
    return () => { actif = false; };
  }, []);

  return (
    <div className="p-4 sm:p-6">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><PackageOpen className="text-orange-600" /> {tc.title}</h1>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tc.subtitle}</p>
      </div>
      <div className="flex flex-wrap gap-1 mb-4" role="tablist" aria-label={tc.title}>
        {ONGLETS.map(o => (
          <button key={o} type="button" role="tab" aria-selected={onglet === o} onClick={() => setOnglet(o)}
            className={`px-3 py-1.5 rounded-lg text-sm ${onglet === o ? 'bg-orange-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'}`}>
            {tc.tabs[o]}
          </button>
        ))}
      </div>
      {onglet === 'releve' && <Releve clients={clients} toast={toast} />}
      {onglet === 'depots' && <Depots clients={clients} toast={toast} />}
      {onglet === 'historique' && <Historique clients={clients} />}
      {onglet === 'rapport' && <Rapport clients={clients} />}
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}

function ChoixClient({ clients, valeur, onChange, tous }: { clients: Client[]; valeur: string; onChange: (v: string) => void; tous?: boolean }) {
  const { t } = useLanguage();
  const tc = t.consignment;
  return (
    <label className={`${classeLibelle} min-w-[16rem]`}>{tc.customer}
      <select value={valeur} onChange={e => onChange(e.target.value)} className={classeChamp}>
        <option value="">{tous ? tc.allCustomers : tc.chooseCustomer}</option>
        {clients.map(c => <option key={c.id} value={c.id}>{c.code} - {c.raison_sociale}</option>)}
      </select>
    </label>
  );
}

/** Relevé d'un client : ce qu'il détient ; saisie du consommé (facturé) ou du retourné (repris), un geste à la fois. */
function Releve({ clients, toast }: { clients: Client[]; toast: Toast }) {
  const { t, language } = useLanguage();
  const tc = t.consignment;
  const { peut } = usePermissions();
  const montantDoc = useMontantDocument();
  const [clientId, setClientId] = useState('');
  const [releve, setReleve] = useState<ReleveConsignation | null>(null);
  const [consomme, setConsomme] = useState<Record<number, string>>({});
  const [retourne, setRetourne] = useState<Record<number, string>>({});
  const [lieu, setLieu] = useState('');
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  const [emettre, setEmettre] = useState(false);
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);

  useEffect(() => {
    let actif = true;
    getSecteurs().then(s => { if (actif) setSecteurs(s); }, () => {});
    return () => { actif = false; };
  }, []);

  useEffect(() => {
    let actif = true;
    if (!clientId) return;
    getReleveConsignation(Number(clientId)).then(r => { if (actif) setReleve(r); }, e => { if (actif) setErreur(messageErreur(e, tc.error)); });
    return () => { actif = false; };
  }, [clientId, version, tc.error]);

  // Changer de client efface les saisies : elles portent sur le solde d'un client précis.
  function choisirClient(valeur: string) {
    setClientId(valeur);
    setConsomme({});
    setRetourne({});
    setErreur('');
    setReleve(null);
  }

  const recharger = () => {
    setConsomme({});
    setRetourne({});
    setVersion(v => v + 1);
  };

  const lignes = (saisies: Record<number, string>) => Object.entries(saisies).map(([p, q]) => ({ produit_id: Number(p), quantite: Number(q) || 0 })).filter(l => l.quantite > 0);
  const devise = releve?.client?.devise ?? null;

  async function facturer() {
    if (lignes(retourne).length) { setErreur(tc.returnsPending); return; }
    if (!lignes(consomme).length) { setErreur(tc.nothingEntered); return; }
    setErreur('');
    try {
      const r = await facturerConsommation(Number(clientId), lignes(consomme), emettre);
      toast(r.message);
      recharger();
    } catch (e) { setErreur(messageErreur(e, tc.error)); }
  }

  async function reprendre() {
    if (lignes(consomme).length) { setErreur(tc.consumptionPending); return; }
    if (!lignes(retourne).length) { setErreur(tc.nothingEntered); return; }
    if (!lieu.trim()) { setErreur(tc.locationRequired); return; }
    setErreur('');
    try {
      const r = await reprendreConsignation(Number(clientId), lieu.trim(), lignes(retourne));
      toast(r.message);
      setLieu('');
      recharger();
    } catch (e) { setErreur(messageErreur(e, tc.error)); }
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-end gap-3">
        <ChoixClient clients={clients} valeur={clientId} onChange={choisirClient} />
        {releve && (
          <p className="text-sm text-gray-700 dark:text-gray-300">{tc.totalValue.replace('{n}', String(releve.quantite)).replace('{valeur}', montantDoc(releve.valeur, null))}</p>
        )}
      </div>
      {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
      {releve && (
        <>
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
            <table className="w-full text-sm min-w-[52rem]">
              <thead className="bg-gray-50 dark:bg-gray-700">
                <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                  <th className="px-3 py-2 font-medium">{tc.product}</th>
                  <th className="px-3 py-2 font-medium text-right">{tc.held}</th>
                  <th className="px-3 py-2 font-medium w-28">{tc.consumed}</th>
                  <th className="px-3 py-2 font-medium w-28">{tc.returned}</th>
                  <th className="px-3 py-2 font-medium text-right">{tc.unitCost}</th>
                  <th className="px-3 py-2 font-medium text-right">{tc.value}</th>
                  <th className="px-3 py-2 font-medium">{tc.lastDeposit}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {releve.lignes.length === 0 && <tr><td colSpan={7} className="px-4 py-6 text-center text-gray-500">{tc.nothingHeld}</td></tr>}
                {releve.lignes.map(l => (
                  <tr key={l.produit_id} className="text-gray-800 dark:text-gray-200">
                    <td className="px-3 py-2">{l.produit?.description}<span className="block text-xs font-mono text-gray-500">{l.produit?.numero}</span></td>
                    <td className="px-3 py-2 text-right font-medium">{l.quantite}</td>
                    <td className="px-3 py-2">
                      <input type="number" min={0} max={l.quantite} value={consomme[l.produit_id] ?? ''} disabled={!peut('consignations', 'facturer')}
                        onChange={e => setConsomme({ ...consomme, [l.produit_id]: e.target.value })} aria-label={`${tc.consumed} — ${l.produit?.numero ?? ''}`} className={classeChamp} />
                    </td>
                    <td className="px-3 py-2">
                      <input type="number" min={0} max={l.quantite} value={retourne[l.produit_id] ?? ''} disabled={!peut('consignations', 'modifier')}
                        onChange={e => setRetourne({ ...retourne, [l.produit_id]: e.target.value })} aria-label={`${tc.returned} — ${l.produit?.numero ?? ''}`} className={classeChamp} />
                    </td>
                    <td className="px-3 py-2 text-right whitespace-nowrap">{l.cout_unitaire !== null ? montantDoc(l.cout_unitaire, null) : '—'}</td>
                    <td className="px-3 py-2 text-right whitespace-nowrap">{l.valeur !== null ? montantDoc(l.valeur, null) : '—'}</td>
                    <td className="px-3 py-2 whitespace-nowrap">{l.dernier_depot ? formatDateDocument(l.dernier_depot, language) : ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="flex flex-wrap items-end gap-3">
            {peut('consignations', 'facturer') && (
              <div className="flex items-end gap-2">
                {peut('factures', 'emettre') && (
                  <label className="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-200"><input type="checkbox" checked={emettre} onChange={e => setEmettre(e.target.checked)} /> {tc.issue}</label>
                )}
                <button type="button" onClick={() => { void facturer(); }} className="inline-flex items-center gap-1 px-3 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700">
                  <FileText size={14} /> {tc.invoiceConsumption}
                </button>
              </div>
            )}
            {peut('consignations', 'modifier') && (
              <div className="flex items-end gap-2">
                <label className={classeLibelle}>{tc.returnLocation}
                  <input list="consignation-lieux" value={lieu} onChange={e => setLieu(e.target.value)} placeholder={tc.returnLocationHelp} className={classeChamp} />
                  <datalist id="consignation-lieux">{secteurs.map(s => <option key={s.code} value={`${s.code} — ${s.nom}`} />)}</datalist>
                </label>
                <button type="button" onClick={() => { void reprendre(); }} className="px-3 py-2 border rounded-lg text-sm border-orange-300 text-orange-700 hover:bg-orange-50 dark:border-orange-600 dark:text-orange-300">{tc.saveReturn}</button>
              </div>
            )}
          </div>
          <p className="text-xs text-gray-500">{tc.howTo}{devise ? ` ${tc.invoiceCurrency.replace('{devise}', devise)}` : ''}</p>
        </>
      )}
    </div>
  );
}

/** Bons de dépôt : liste, impression, nouveau dépôt. */
function Depots({ clients, toast }: { clients: Client[]; toast: Toast }) {
  const { t, language } = useLanguage();
  const tc = t.consignment;
  const { peut } = usePermissions();
  const [page, setPage] = useState(1);
  const [resultat, setResultat] = useState<PageResultats<BonDepot> | null>(null);
  const [nouveau, setNouveau] = useState(false);
  const [version, setVersion] = useState(0);
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    let actif = true;
    getBonsDepot({ page }).then(r => { if (actif) setResultat(r); }, e => { if (actif) setErreur(messageErreur(e, tc.error)); });
    return () => { actif = false; };
  }, [page, version, tc.error]);

  return (
    <div className="space-y-3">
      {peut('consignations', 'creer') && (
        <button type="button" onClick={() => setNouveau(true)} className="inline-flex items-center gap-2 bg-orange-600 text-white px-4 py-2 rounded-lg hover:bg-orange-700 text-sm">
          <Plus size={16} /> {tc.newDeposit}
        </button>
      )}
      {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-3 py-2 font-medium">{tc.number}</th>
              <th className="px-3 py-2 font-medium">{tc.date}</th>
              <th className="px-3 py-2 font-medium">{tc.customer}</th>
              <th className="px-3 py-2 font-medium text-right">{tc.units}</th>
              <th className="px-3 py-2" />
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
            {resultat?.data.length === 0 && <tr><td colSpan={5} className="px-4 py-6 text-center text-gray-500">{tc.noDeposits}</td></tr>}
            {resultat?.data.map(b => (
              <tr key={b.id} className="text-gray-800 dark:text-gray-200">
                <td className="px-3 py-2 font-mono">{b.numero}</td>
                <td className="px-3 py-2">{formatDateDocument(b.date_depot, language)}</td>
                <td className="px-3 py-2">{b.client?.raison_sociale}</td>
                <td className="px-3 py-2 text-right">{Number(b.lignes_sum_quantite ?? 0)}</td>
                <td className="px-3 py-2 text-right">
                  <button type="button" onClick={() => { ouvrirPdf(`/consignations/${b.id}/pdf`, `consignation-${b.numero}.pdf`).catch(e => setErreur(messageErreur(e, tc.error))); }}
                    className="inline-flex items-center gap-1 text-sky-700 hover:underline" aria-label={`${tc.print} — ${b.numero}`}><FileText size={14} /> {tc.print}</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {resultat && <Pagination page={page} dernierePage={resultat.last_page ?? 1} total={resultat.total ?? 0} onPage={setPage} />}
      </div>
      {nouveau && <NouveauDepot clients={clients} onClose={() => setNouveau(false)} onCree={b => { setNouveau(false); toast(tc.depositCreated.replace('{numero}', b.numero)); setVersion(v => v + 1); }} />}
    </div>
  );
}

function NouveauDepot({ clients, onClose, onCree }: { clients: Client[]; onClose: () => void; onCree: (b: BonDepot) => void }) {
  const { t } = useLanguage();
  const tc = t.consignment;
  const [produits, setProduits] = useState<Produit[]>([]);
  const [saisie, setSaisie] = useState({ client_id: '', date_depot: aujourdhui(), notes: '' });
  const [lignes, setLignes] = useState([{ produit_id: '', quantite: '1' }]);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    getProduits('actifs').then(p => { if (actif) setProduits(p); }, () => {});
    return () => { actif = false; };
  }, []);

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    try {
      onCree(await creerBonDepot({ client_id: Number(saisie.client_id), date_depot: saisie.date_depot, notes: saisie.notes.trim() || null,
        lignes: lignes.map(l => ({ produit_id: Number(l.produit_id), quantite: Number(l.quantite) })) }));
    } catch (err) {
      setErreur(messageErreur(err, tc.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <form onSubmit={e => { void enregistrer(e); }} role="dialog" aria-modal="true" aria-labelledby="nouveau-depot-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-2xl max-h-[92vh] overflow-y-auto space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="nouveau-depot-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tc.newDeposit}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tc.close}><X size={18} /></button>
        </div>
        <p className="text-sm text-gray-500 dark:text-gray-400">{tc.depositHelp}</p>
        <div className="grid sm:grid-cols-2 gap-3">
          <label className={classeLibelle}>{tc.customer}
            <select required value={saisie.client_id} onChange={e => setSaisie({ ...saisie, client_id: e.target.value })} className={classeChamp}>
              <option value="">{tc.chooseCustomer}</option>
              {clients.map(c => <option key={c.id} value={c.id}>{c.code} - {c.raison_sociale}</option>)}
            </select>
          </label>
          <label className={classeLibelle}>{tc.date}
            <input type="date" required value={saisie.date_depot} onChange={e => setSaisie({ ...saisie, date_depot: e.target.value })} className={classeChamp} />
          </label>
        </div>
        {lignes.map((l, i) => (
          <div key={i} className="flex gap-2 items-end">
            <label className={`${classeLibelle} flex-1`}>{tc.product} {i + 1}
              <select required value={l.produit_id} onChange={e => setLignes(ls => ls.map((x, j) => (j === i ? { ...x, produit_id: e.target.value } : x)))} className={classeChamp}>
                <option value="">{tc.chooseProduct}</option>
                {produits.map(p => <option key={p.id} value={p.id}>{p.numero} — {p.description}</option>)}
              </select>
            </label>
            <label className={`${classeLibelle} w-28`}>{tc.quantity} {i + 1}
              <input type="number" required min={1} value={l.quantite} onChange={e => setLignes(ls => ls.map((x, j) => (j === i ? { ...x, quantite: e.target.value } : x)))} className={classeChamp} />
            </label>
            {lignes.length > 1 && (
              <button type="button" onClick={() => setLignes(ls => ls.filter((_, j) => j !== i))} className="p-2 text-red-600 hover:bg-red-50 rounded" aria-label={`${tc.removeLine} ${i + 1}`}><Trash2 size={14} /></button>
            )}
          </div>
        ))}
        <button type="button" onClick={() => setLignes(ls => [...ls, { produit_id: '', quantite: '1' }])} className="inline-flex items-center gap-1 text-sm text-orange-700 hover:underline"><Plus size={14} /> {tc.addLine}</button>
        <label className={classeLibelle}>{tc.notes}
          <textarea rows={2} maxLength={1000} value={saisie.notes} onChange={e => setSaisie({ ...saisie, notes: e.target.value })} className={classeChamp} />
        </label>
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-white">{tc.cancel}</button>
          <button type="submit" disabled={occupe} className="px-4 py-2 bg-orange-600 text-white rounded-lg text-sm hover:bg-orange-700 disabled:opacity-50">{tc.save}</button>
        </div>
      </form>
    </div>
  );
}

/** Historique d'un client : dépôts, retours et consommations sur une période (12 derniers mois par défaut). */
function Historique({ clients }: { clients: Client[] }) {
  const { t, language } = useLanguage();
  const tc = t.consignment;
  const ilYaUnAn = () => { const d = new Date(); d.setFullYear(d.getFullYear() - 1); return d.toLocaleDateString('sv-SE'); };
  const [clientId, setClientId] = useState('');
  const [du, setDu] = useState(ilYaUnAn());
  const [au, setAu] = useState(aujourdhui());
  const [resultat, setResultat] = useState<{ cle: string; mouvements: MouvementConsignation[] } | null>(null);
  const [erreur, setErreur] = useState<{ cle: string; message: string } | null>(null);
  const periodeInvalide = du > au;
  // Résultat et erreur valent pour une recherche (client et période) : une autre recherche les ignore.
  const cle = `${clientId}|${du}|${au}`;

  useEffect(() => {
    let actif = true;
    if (!clientId || periodeInvalide) return;
    getHistoriqueConsignation(Number(clientId), du, au).then(
      m => { if (actif) setResultat({ cle, mouvements: m }); },
      e => { if (actif) setErreur({ cle, message: messageErreur(e, tc.error) }); },
    );
    return () => { actif = false; };
  }, [clientId, du, au, cle, periodeInvalide, tc.error]);

  const mouvements = clientId && !periodeInvalide && resultat?.cle === cle ? resultat.mouvements : null;
  const message = periodeInvalide ? tc.periodError : erreur?.cle === cle ? erreur.message : '';

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-end gap-3">
        <ChoixClient clients={clients} valeur={clientId} onChange={setClientId} />
        <label className={classeLibelle}>{tc.from}<input type="date" value={du} onChange={e => setDu(e.target.value)} className={classeChamp} /></label>
        <label className={classeLibelle}>{tc.to}<input type="date" value={au} onChange={e => setAu(e.target.value)} className={classeChamp} /></label>
      </div>
      {message && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{message}</p>}
      {mouvements && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                <th className="px-3 py-2 font-medium">{tc.date}</th>
                <th className="px-3 py-2 font-medium">{tc.movement}</th>
                <th className="px-3 py-2 font-medium">{tc.product}</th>
                <th className="px-3 py-2 font-medium text-right">{tc.quantity}</th>
                <th className="px-3 py-2 font-medium">{tc.document}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {mouvements.length === 0 && <tr><td colSpan={5} className="px-4 py-6 text-center text-gray-500">{tc.noMovements}</td></tr>}
              {mouvements.map(m => (
                <tr key={m.id} className="text-gray-800 dark:text-gray-200">
                  <td className="px-3 py-2 whitespace-nowrap">{formatDateDocument(m.date_mouvement, language)}</td>
                  <td className="px-3 py-2">{tc.movements[m.type]}{m.lieu_retour && <span className="block text-xs text-gray-500">{m.lieu_retour}</span>}</td>
                  <td className="px-3 py-2">{m.produit?.numero} — {m.produit?.description}</td>
                  <td className={`px-3 py-2 text-right font-medium ${m.quantite < 0 ? 'text-red-700' : 'text-green-700'}`}>{m.quantite > 0 ? `+${m.quantite}` : m.quantite}</td>
                  <td className="px-3 py-2 font-mono">
                    {m.facture ? <Link to={`/factures?recherche=${encodeURIComponent(m.facture.numero)}`} className="text-sky-700 hover:underline">{m.facture.numero}</Link> : m.consignation?.numero}
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

/** Consignations chez les clients : tous ou un seul, valeur au coût, sous-totaux, PDF. */
function Rapport({ clients }: { clients: Client[] }) {
  const { t, language } = useLanguage();
  const tc = t.consignment;
  const montantDoc = useMontantDocument();
  const [clientId, setClientId] = useState('');
  const [nuls, setNuls] = useState(false);
  const [rapport, setRapport] = useState<RapportConsignations | null>(null);
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    let actif = true;
    getRapportConsignations(clientId ? Number(clientId) : null, nuls).then(r => { if (actif) setRapport(r); }, e => { if (actif) setErreur(messageErreur(e, tc.error)); });
    return () => { actif = false; };
  }, [clientId, nuls, tc.error]);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-end gap-3">
        <ChoixClient clients={clients} valeur={clientId} onChange={setClientId} tous />
        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200 pb-2"><input type="checkbox" checked={nuls} onChange={e => setNuls(e.target.checked)} /> {tc.includeZero}</label>
        <button type="button" onClick={() => { ouvrirPdf(cheminRapportPdf(clientId ? Number(clientId) : null, nuls), 'consignations-chez-les-clients.pdf').catch(e => setErreur(messageErreur(e, tc.error))); }}
          className="inline-flex items-center gap-1 px-3 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-white"><FileText size={14} /> {tc.pdf}</button>
      </div>
      {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
      {rapport && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-700">
              <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                <th className="px-3 py-2 font-medium">{tc.product}</th>
                <th className="px-3 py-2 font-medium text-right">{tc.held}</th>
                <th className="px-3 py-2 font-medium text-right">{tc.unitCost}</th>
                <th className="px-3 py-2 font-medium text-right">{tc.value}</th>
                <th className="px-3 py-2 font-medium">{tc.lastDeposit}</th>
              </tr>
            </thead>
            <tbody>
              {rapport.clients.length === 0 && <tr><td colSpan={5} className="px-4 py-6 text-center text-gray-500">{tc.nothingHeld}</td></tr>}
              {rapport.clients.map(b => [
                <tr key={`c${b.client?.id}`} className="bg-orange-50 dark:bg-orange-900/20 font-medium text-gray-900 dark:text-white"><td colSpan={5} className="px-3 py-1.5">{b.client?.code} - {b.client?.raison_sociale}</td></tr>,
                ...b.lignes.map(l => (
                  <tr key={`${b.client?.id}-${l.produit_id}`} className="text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700">
                    <td className="px-3 py-1.5">{l.produit?.numero} — {l.produit?.description}</td>
                    <td className="px-3 py-1.5 text-right">{l.quantite}</td>
                    <td className="px-3 py-1.5 text-right">{l.cout_unitaire !== null ? montantDoc(l.cout_unitaire, null) : '—'}</td>
                    <td className="px-3 py-1.5 text-right">{l.valeur !== null ? montantDoc(l.valeur, null) : '—'}</td>
                    <td className="px-3 py-1.5">{l.dernier_depot ? formatDateDocument(l.dernier_depot, language) : ''}</td>
                  </tr>
                )),
                <tr key={`t${b.client?.id}`} className="font-medium text-gray-900 dark:text-white"><td className="px-3 py-1.5">{tc.subtotal}</td><td className="px-3 py-1.5 text-right">{b.quantite}</td><td /><td className="px-3 py-1.5 text-right">{montantDoc(b.valeur, null)}</td><td /></tr>,
              ])}
              {rapport.clients.length > 0 && (
                <tr className="font-bold border-t-2 border-gray-400 text-gray-900 dark:text-white"><td className="px-3 py-2">{tc.grandTotal}</td><td className="px-3 py-2 text-right">{rapport.quantite}</td><td /><td className="px-3 py-2 text-right">{montantDoc(rapport.valeur, null)}</td><td /></tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
