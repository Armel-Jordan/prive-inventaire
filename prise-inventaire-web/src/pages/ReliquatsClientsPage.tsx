import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Ban, FileText, Hourglass, Plus, ShoppingCart, Trash2, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, formatDateDocument, useMontantDocument } from '@/lib/documents';
import { quantiteAvecUnite } from '@/lib/unitesVente';
import { getClientsActifs, getFournisseursActifs, getProduits, type Client, type Fournisseur, type PageResultats } from '@/services/api';
import {
  annulerReliquat, commanderReliquats, creerReliquat, facturerReliquats, getReliquats, supprimerReliquat,
  type ReliquatClient, type ResumeReliquats,
} from '@/services/reliquatsClients';
import Pagination from '@/components/Pagination';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';
import type { Produit } from '@/types';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const COULEURS: Record<string, string> = {
  cree: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
  commande: 'bg-blue-100 text-blue-800',
  recu_partiel: 'bg-amber-100 text-amber-800',
  recu: 'bg-green-100 text-green-800',
  facture: 'bg-purple-100 text-purple-800',
  annule: 'bg-red-100 text-red-800',
};
const ONGLETS = ['ouverts', 'cree', 'commande', 'recu_partiel', 'recu', 'facture', 'annule'] as const;

/**
 * Reliquats clients (back orders, plan de parité fripv2, phase 1.4) : produits qu'un client attend faute de stock. On
 * les commande au fournisseur (une commande brouillon par fournisseur), la réception les met à jour, puis on facture
 * ceux qui sont reçus (une facture brouillon par client).
 */
export default function ReliquatsClientsPage() {
  const { t, language } = useLanguage();
  const tb = t.backOrders;
  const { peut } = usePermissions();
  const montantDoc = useMontantDocument();
  const { toasts, toast, dismiss } = useToast();
  const [parametres] = useSearchParams();
  const [recherche, setRecherche] = useState(parametres.get('recherche') ?? '');
  const [terme, setTerme] = useState(recherche.trim());
  const [onglet, setOnglet] = useState<typeof ONGLETS[number]>('ouverts');
  const [page, setPage] = useState(1);
  const [resultat, setResultat] = useState<(PageResultats<ReliquatClient> & { resume: ResumeReliquats }) | null>(null);
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);
  const [choisis, setChoisis] = useState<number[]>([]);
  const [dialogue, setDialogue] = useState<'nouveau' | 'commander' | 'facturer' | null>(null);

  useEffect(() => {
    const minuterie = setTimeout(() => { setTerme(recherche.trim()); setPage(1); }, 300);
    return () => clearTimeout(minuterie);
  }, [recherche]);

  useEffect(() => {
    let actif = true;
    getReliquats({ statut: onglet, recherche: terme || undefined, page }).then(
      r => { if (actif) { setResultat(r); setErreur(''); setChoisis([]); } },
      e => { if (actif) setErreur(messageErreur(e, tb.error)); },
    );
    return () => { actif = false; };
  }, [onglet, terme, page, version, tb.error]);

  const recharger = () => setVersion(v => v + 1);
  const echec = (e: unknown) => toast(messageErreur(e, tb.error), 'error');
  const reliquats = resultat?.data ?? [];
  const resume = resultat?.resume;
  const choisisReliquats = reliquats.filter(r => choisis.includes(r.id));
  const aCommander = choisisReliquats.filter(r => r.statut === 'cree');
  const aFacturer = choisisReliquats.filter(r => r.statut === 'recu');
  const compte = (o: typeof ONGLETS[number]) => !resume ? 0 : o === 'ouverts' ? resume.cree + resume.commande + resume.recu_partiel + resume.recu : resume[o];

  async function action(reliquat: ReliquatClient, type: 'annuler' | 'supprimer') {
    if (!confirm(type === 'annuler' ? tb.confirmCancel : tb.confirmDelete)) return;
    try {
      if (type === 'annuler') await annulerReliquat(reliquat.id); else await supprimerReliquat(reliquat.id);
      toast(type === 'annuler' ? tb.cancelled : tb.deleted);
      recharger();
    } catch (e) { echec(e); }
  }

  const basculer = (id: number) => setChoisis(c => (c.includes(id) ? c.filter(x => x !== id) : [...c, id]));
  const toutCocher = () => setChoisis(choisis.length === reliquats.length ? [] : reliquats.map(r => r.id));

  return (
    <div className="p-4 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Hourglass className="text-sky-600" /> {tb.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tb.subtitle}</p>
        </div>
        {peut('reliquats_clients', 'creer') && (
          <button type="button" onClick={() => setDialogue('nouveau')} className="flex items-center gap-2 bg-sky-600 text-white px-4 py-2 rounded-lg hover:bg-sky-700 text-sm">
            <Plus size={18} /> {tb.newBackOrder}
          </button>
        )}
      </div>

      <div className="flex flex-wrap gap-1 mb-3" role="tablist" aria-label={tb.status}>
        {ONGLETS.map(o => (
          <button key={o} type="button" role="tab" aria-selected={onglet === o} onClick={() => { setOnglet(o); setPage(1); }}
            className={`px-3 py-1.5 rounded-lg text-sm ${onglet === o ? 'bg-sky-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'}`}>
            {tb.tabs[o]} <span className="ml-1 text-xs opacity-80">{compte(o)}</span>
          </button>
        ))}
      </div>

      <div className="flex flex-wrap items-end gap-2 mb-4">
        <label className="text-xs text-gray-500 dark:text-gray-400 flex-1 min-w-[12rem]">{tb.search}
          <input type="search" value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={tb.searchHelp} className={classeChamp} />
        </label>
        {peut('reliquats_clients', 'commander') && (
          <button type="button" disabled={aCommander.length === 0} onClick={() => setDialogue('commander')}
            className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm border border-blue-300 text-blue-700 hover:bg-blue-50 disabled:opacity-40 dark:border-blue-500 dark:text-blue-300 dark:hover:bg-blue-900/30">
            <ShoppingCart size={16} /> {tb.orderFromSupplier.replace('{n}', String(aCommander.length))}
          </button>
        )}
        {peut('reliquats_clients', 'facturer') && (
          <button type="button" disabled={aFacturer.length === 0} onClick={() => setDialogue('facturer')}
            className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm border border-purple-300 text-purple-700 hover:bg-purple-50 disabled:opacity-40 dark:border-purple-500 dark:text-purple-300 dark:hover:bg-purple-900/30">
            <FileText size={16} /> {tb.invoice.replace('{n}', String(aFacturer.length))}
          </button>
        )}
      </div>
      <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">{tb.howItWorks}</p>

      {erreur && <p role="alert" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
        <table className="w-full text-sm min-w-[64rem]">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-3 py-2"><input type="checkbox" aria-label={tb.selectAll} checked={reliquats.length > 0 && choisis.length === reliquats.length} onChange={toutCocher} /></th>
              <th className="px-3 py-2 font-medium">{tb.customer}</th>
              <th className="px-3 py-2 font-medium">{tb.product}</th>
              <th className="px-3 py-2 font-medium text-right">{tb.quantities}</th>
              <th className="px-3 py-2 font-medium text-right">{tb.price}</th>
              <th className="px-3 py-2 font-medium">{tb.requiredDate}</th>
              <th className="px-3 py-2 font-medium">{tb.documents}</th>
              <th className="px-3 py-2 font-medium text-center">{tb.status}</th>
              <th className="px-3 py-2 font-medium text-center">{tb.actions}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
            {resultat === null && !erreur && <tr><td colSpan={9} className="px-4 py-6 text-center text-gray-500">{tb.loading}</td></tr>}
            {resultat !== null && reliquats.length === 0 && <tr><td colSpan={9} className="px-4 py-6 text-center text-gray-500">{tb.empty}</td></tr>}
            {reliquats.map(r => {
              const commandeFournisseur = r.ligne_commande_fournisseur?.commande;
              return (
                <tr key={r.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                  <td className="px-3 py-2"><input type="checkbox" checked={choisis.includes(r.id)} onChange={() => basculer(r.id)} aria-label={`${tb.select} — ${r.produit?.description ?? r.produit_id}`} /></td>
                  <td className="px-3 py-2 text-gray-900 dark:text-white">{r.client?.raison_sociale}</td>
                  <td className="px-3 py-2 text-gray-700 dark:text-gray-300">
                    {r.produit?.description}
                    {r.produit?.numero && <span className="block text-xs font-mono text-gray-500">{r.produit.numero}</span>}
                    {r.notes && <span className="block text-xs text-gray-500">{r.notes}</span>}
                  </td>
                  <td className="px-3 py-2 text-right whitespace-nowrap text-gray-900 dark:text-white">
                    {quantiteAvecUnite(r.quantite, r.unite)}
                    <span className="block text-xs text-gray-500">{tb.orderedReceived.replace('{commandee}', String(r.quantite_commandee)).replace('{recue}', String(r.quantite_recue))}</span>
                  </td>
                  <td className="px-3 py-2 text-right whitespace-nowrap text-gray-700 dark:text-gray-300">{montantDoc(r.prix_unitaire_ht, r.devise)}</td>
                  <td className="px-3 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">{r.date_requise ? formatDateDocument(r.date_requise, language) : '—'}</td>
                  <td className="px-3 py-2 text-xs text-gray-700 dark:text-gray-300 space-y-0.5">
                    {r.commande_client && <span className="block">{tb.customerOrder} <Link to={`/commandes-client?recherche=${encodeURIComponent(r.commande_client.numero)}`} className="font-mono text-sky-700 hover:underline">{r.commande_client.numero}</Link></span>}
                    {commandeFournisseur && <span className="block">{tb.purchaseOrder} <Link to={`/commandes-fournisseur?recherche=${encodeURIComponent(commandeFournisseur.numero)}`} className="font-mono text-sky-700 hover:underline">{commandeFournisseur.numero}</Link>{commandeFournisseur.fournisseur && ` · ${commandeFournisseur.fournisseur.raison_sociale}`}</span>}
                    {r.facture && <span className="block">{tb.invoiceDoc} <Link to={`/factures?recherche=${encodeURIComponent(r.facture.numero)}`} className="font-mono text-sky-700 hover:underline">{r.facture.numero}</Link></span>}
                    {!r.commande_client && !commandeFournisseur && !r.facture && <span className="text-gray-400">—</span>}
                  </td>
                  <td className="px-3 py-2 text-center"><span className={`px-2 py-0.5 text-xs rounded-full whitespace-nowrap ${COULEURS[r.statut] ?? ''}`}>{tb.statuses[r.statut]}</span></td>
                  <td className="px-3 py-2">
                    <div className="flex justify-center gap-1">
                      {r.statut === 'cree' && peut('reliquats_clients', 'modifier') && (
                        <button type="button" onClick={() => { void action(r, 'annuler'); }} title={tb.cancelBackOrder} aria-label={tb.cancelBackOrder}
                          className="p-1 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/30 rounded"><Ban size={18} /></button>
                      )}
                      {r.statut === 'cree' && peut('reliquats_clients', 'supprimer') && (
                        <button type="button" onClick={() => { void action(r, 'supprimer'); }} title={tb.delete} aria-label={tb.delete}
                          className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><Trash2 size={18} /></button>
                      )}
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
        {resultat && <Pagination page={page} dernierePage={resultat.last_page ?? 1} total={resultat.total ?? 0} onPage={setPage} />}
      </div>

      {dialogue === 'nouveau' && <NouveauReliquat onClose={() => setDialogue(null)} onCree={() => { setDialogue(null); toast(tb.created); recharger(); }} />}
      {dialogue === 'commander' && (
        <CommanderReliquats ids={aCommander.map(r => r.id)} onClose={() => setDialogue(null)}
          onCommande={commandes => { setDialogue(null); toast(tb.ordered.replace('{numeros}', commandes.map(c => c.numero).join(', '))); recharger(); }} />
      )}
      {dialogue === 'facturer' && (
        <FacturerReliquats ids={aFacturer.map(r => r.id)} onClose={() => setDialogue(null)}
          onFacture={factures => { setDialogue(null); toast(tb.invoiced.replace('{numeros}', factures.map(f => f.numero).join(', '))); recharger(); }} />
      )}
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}

/** Reliquat saisi à la main : client, produit, quantité attendue, prix convenu, date souhaitée. */
function NouveauReliquat({ onClose, onCree }: { onClose: () => void; onCree: () => void }) {
  const { t } = useLanguage();
  const tb = t.backOrders;
  const [clients, setClients] = useState<Client[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [saisie, setSaisie] = useState({ client_id: '', produit_id: '', quantite: '1', prix: '', date_requise: '', notes: '' });
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    Promise.all([getClientsActifs(), getProduits('actifs')]).then(([c, p]) => { if (actif) { setClients(c); setProduits(p); } }, () => {});
    return () => { actif = false; };
  }, []);

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    try {
      await creerReliquat({
        client_id: Number(saisie.client_id), produit_id: Number(saisie.produit_id), quantite: Number(saisie.quantite),
        prix_unitaire_ht: Number(saisie.prix) || 0, date_requise: saisie.date_requise || null, notes: saisie.notes.trim() || null,
      });
      onCree();
    } catch (err) {
      setErreur(messageErreur(err, tb.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <form onSubmit={e => { void enregistrer(e); }} role="dialog" aria-modal="true" aria-labelledby="nouveau-reliquat-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="nouveau-reliquat-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tb.newBackOrder}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tb.close}><X size={18} /></button>
        </div>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.customer}
          <select required value={saisie.client_id} onChange={e => setSaisie({ ...saisie, client_id: e.target.value })} className={classeChamp}>
            <option value="">{tb.chooseCustomer}</option>
            {clients.map(c => <option key={c.id} value={c.id}>{c.raison_sociale} ({c.code})</option>)}
          </select>
        </label>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.product}
          <select required value={saisie.produit_id} className={classeChamp} onChange={e => {
            const produit = produits.find(p => String(p.id) === e.target.value);
            setSaisie({ ...saisie, produit_id: e.target.value, prix: saisie.prix || String(Number(produit?.prix_unitaire ?? 0)) });
          }}>
            <option value="">{tb.chooseProduct}</option>
            {produits.map(p => <option key={p.id} value={p.id}>{p.numero} — {p.description}</option>)}
          </select>
        </label>
        <div className="grid grid-cols-2 gap-3">
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.quantity}
            <input type="number" required min={1} step={1} value={saisie.quantite} onChange={e => setSaisie({ ...saisie, quantite: e.target.value })} className={classeChamp} />
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.price}
            <input type="number" required min={0} step="0.01" value={saisie.prix} onChange={e => setSaisie({ ...saisie, prix: e.target.value })} className={classeChamp} />
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.requiredDate}
            <input type="date" value={saisie.date_requise} onChange={e => setSaisie({ ...saisie, date_requise: e.target.value })} className={classeChamp} />
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.notes}
            <input maxLength={255} value={saisie.notes} onChange={e => setSaisie({ ...saisie, notes: e.target.value })} className={classeChamp} />
          </label>
        </div>
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tb.cancel}</button>
          <button type="submit" disabled={occupe} className="px-4 py-2 bg-sky-600 text-white rounded-lg text-sm hover:bg-sky-700 disabled:opacity-50">{tb.save}</button>
        </div>
      </form>
    </div>
  );
}

/** Commande au fournisseur : le principal de chaque produit, ou un fournisseur choisi pour tous. */
function CommanderReliquats({ ids, onClose, onCommande }: { ids: number[]; onClose: () => void; onCommande: (commandes: { id: number; numero: string }[]) => void }) {
  const { t } = useLanguage();
  const tb = t.backOrders;
  const [fournisseurs, setFournisseurs] = useState<Fournisseur[]>([]);
  const [fournisseurId, setFournisseurId] = useState('');
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    getFournisseursActifs().then(f => { if (actif) setFournisseurs(f); }, () => {});
    return () => { actif = false; };
  }, []);

  async function commander() {
    setOccupe(true);
    setErreur('');
    try {
      onCommande(await commanderReliquats(ids, fournisseurId ? Number(fournisseurId) : null));
    } catch (e) {
      setErreur(messageErreur(e, tb.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="commander-reliquats-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="commander-reliquats-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tb.orderTitle.replace('{n}', String(ids.length))}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tb.close}><X size={18} /></button>
        </div>
        <p className="text-sm text-gray-500 dark:text-gray-400">{tb.orderHelp}</p>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.supplier}
          <select value={fournisseurId} onChange={e => setFournisseurId(e.target.value)} className={classeChamp}>
            <option value="">{tb.mainSupplier}</option>
            {fournisseurs.map(f => <option key={f.id} value={f.id}>{f.raison_sociale} ({f.code})</option>)}
          </select>
        </label>
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tb.cancel}</button>
          <button type="button" disabled={occupe} onClick={() => { void commander(); }} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-50">{tb.createPurchaseOrders}</button>
        </div>
      </div>
    </div>
  );
}

/** Facturation des reliquats reçus : une facture brouillon par client (et par devise). */
function FacturerReliquats({ ids, onClose, onFacture }: { ids: number[]; onClose: () => void; onFacture: (factures: { id: number; numero: string }[]) => void }) {
  const { t } = useLanguage();
  const tb = t.backOrders;
  const [date, setDate] = useState(aujourdhui());
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  async function facturer() {
    setOccupe(true);
    setErreur('');
    try {
      onFacture(await facturerReliquats(ids, date));
    } catch (e) {
      setErreur(messageErreur(e, tb.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="facturer-reliquats-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="facturer-reliquats-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tb.invoiceTitle.replace('{n}', String(ids.length))}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tb.close}><X size={18} /></button>
        </div>
        <p className="text-sm text-gray-500 dark:text-gray-400">{tb.invoiceHelp}</p>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.invoiceDate}
          <input type="date" value={date} onChange={e => setDate(e.target.value)} className={classeChamp} />
        </label>
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tb.cancel}</button>
          <button type="button" disabled={occupe || !date} onClick={() => { void facturer(); }} className="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700 disabled:opacity-50">{tb.createInvoices}</button>
        </div>
      </div>
    </div>
  );
}
