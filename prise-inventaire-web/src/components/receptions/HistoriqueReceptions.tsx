import { useCallback, useEffect, useState } from 'react';
import { FileInput, Search } from 'lucide-react';
import Pagination from '@/components/Pagination';
import FenetreFactureFournisseur from '@/components/facturesFournisseurs/FenetreFactureFournisseur';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { propositionFacture, type PropositionFacture } from '@/services/facturesFournisseurs';
import { getReceptionsDocuments, type ReceptionDocument, type StatutReception } from '@/services/receptions';
import DetailReception, { BadgeStatutReception } from '@/components/receptions/DetailReception';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/**
 * Historique des réceptions : recherche (numéro, bon de livraison, commande, fournisseur), statut, facturation et
 * période ; chaque réception s'ouvre sur son détail (corriger, annuler, bon de réception). Des réceptions d'un même
 * fournisseur se cochent pour être facturées ensemble (facture fournisseur proposée).
 */
export default function HistoriqueReceptions() {
  const { t, language } = useLanguage();
  const tr = t.receipts;
  const tf = t.supplierInvoices;
  const { peut } = usePermissions();
  const montant = useMontantDocument();
  const [facturee, setFacturee] = useState<0 | 1 | ''>('');
  const [choisies, setChoisies] = useState<ReceptionDocument[]>([]);
  const [proposition, setProposition] = useState<PropositionFacture | null>(null);
  const facturer = peut('factures_fournisseurs', 'creer');
  const [saisie, setSaisie] = useState('');
  const [recherche, setRecherche] = useState('');
  const [statut, setStatut] = useState<StatutReception | ''>('');
  const [debut, setDebut] = useState('');
  const [fin, setFin] = useState('');
  const [page, setPage] = useState(1);
  const [receptions, setReceptions] = useState<ReceptionDocument[]>([]);
  const [pagination, setPagination] = useState({ dernierePage: 1, total: 0 });
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState('');
  const [ouverte, setOuverte] = useState<number | null>(null);

  // La recherche part après une courte pause de saisie.
  useEffect(() => {
    const minuterie = setTimeout(() => { setRecherche(saisie.trim()); setPage(1); }, 350);
    return () => clearTimeout(minuterie);
  }, [saisie]);

  const charger = useCallback(async () => {
    setChargement(true);
    try {
      const resultat = await getReceptionsDocuments({ recherche, statut, facturee, date_debut: debut, date_fin: fin, page });
      setReceptions(resultat.data ?? []);
      setPagination({ dernierePage: resultat.last_page ?? 1, total: resultat.total ?? 0 });
      setErreur('');
    } catch (e) {
      setErreur(messageErreur(e, tr.error));
    } finally {
      setChargement(false);
    }
  }, [recherche, statut, facturee, debut, fin, page, tr.error]);

  useEffect(() => { charger(); }, [charger]);

  // Réceptions à facturer : enregistrées, sans facture, du même fournisseur que celles déjà cochées.
  const facturable = (r: ReceptionDocument) => r.statut === 'enregistree' && !r.facture_fournisseur_id
    && (choisies.length === 0 || choisies[0].fournisseur_id === r.fournisseur_id);
  const cocher = (r: ReceptionDocument, coche: boolean) => setChoisies(cs => (coche ? [...cs, r] : cs.filter(c => c.id !== r.id)));

  async function proposerFacture() {
    try {
      setProposition(await propositionFacture(choisies.map(r => r.id)));
    } catch (e) {
      setErreur(messageErreur(e, tr.error));
    }
  }

  return (
    <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm">
      <div className="p-4 border-b dark:border-gray-700 flex flex-wrap items-end gap-3">
        <label className="relative flex-1 min-w-[220px]">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input value={saisie} onChange={e => setSaisie(e.target.value)} placeholder={tr.search} aria-label={tr.search} className={`${classeChamp} w-full pl-9`} />
        </label>
        <select value={statut} onChange={e => { setStatut(e.target.value as StatutReception | ''); setPage(1); }} aria-label={tr.status} className={classeChamp}>
          <option value="">{tr.allStatuses}</option>
          <option value="enregistree">{tr.statuses.enregistree}</option>
          <option value="annulee">{tr.statuses.annulee}</option>
        </select>
        <select value={facturee} onChange={e => { setFacturee(e.target.value === '' ? '' : (Number(e.target.value) as 0 | 1)); setPage(1); }} aria-label={tf.invoicing} className={classeChamp}>
          <option value="">{tf.allReceipts}</option>
          <option value="0">{tf.toInvoice}</option>
          <option value="1">{tf.invoiced}</option>
        </select>
        {facturer && choisies.length > 0 && (
          <button type="button" onClick={proposerFacture} className="flex items-center gap-1 px-3 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            <FileInput size={16} /> {tf.invoiceReceipts} ({choisies.length})
          </button>
        )}
        <label className="text-sm text-gray-600 dark:text-gray-300 flex items-center gap-1">
          {tr.from} <input type="date" value={debut} onChange={e => { setDebut(e.target.value); setPage(1); }} className={classeChamp} />
        </label>
        <label className="text-sm text-gray-600 dark:text-gray-300 flex items-center gap-1">
          {tr.to} <input type="date" value={fin} onChange={e => { setFin(e.target.value); setPage(1); }} className={classeChamp} />
        </label>
      </div>

      {erreur && <p className="px-4 py-3 text-sm text-red-700 dark:text-red-300">{erreur}</p>}
      {chargement && receptions.length === 0 ? (
        <p className="p-4 text-sm text-gray-500">{tr.loading}</p>
      ) : receptions.length === 0 ? (
        <p className="p-4 text-sm text-gray-500">{tr.none}</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm" aria-label={tr.tabHistory}>
            <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
              <tr>
                {facturer && <th className="w-10" />}
                <th className="px-4 py-3 text-left">{tr.number}</th>
                <th className="px-4 py-3 text-left">{tr.date}</th>
                <th className="px-4 py-3 text-left">{tr.supplier}</th>
                <th className="px-4 py-3 text-left">{tr.deliveryNote}</th>
                <th className="px-4 py-3 text-left">{tr.orders}</th>
                <th className="px-4 py-3 text-right">{tr.lines}</th>
                <th className="px-4 py-3 text-right">{tr.value}</th>
                <th className="px-4 py-3 text-left">{tr.status}</th>
                <th className="px-4 py-3 text-left">{tf.invoicing}</th>
              </tr>
            </thead>
            <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
              {receptions.map(r => (
                <tr key={r.id} onClick={() => setOuverte(r.id)} className="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40">
                  {facturer && (
                    <td className="px-2 py-3 text-center" onClick={e => e.stopPropagation()}>
                      {(facturable(r) || choisies.some(c => c.id === r.id)) && (
                        <input type="checkbox" className="rounded" aria-label={tf.selectReceipt.replace('{numero}', r.numero)}
                          checked={choisies.some(c => c.id === r.id)} onChange={e => cocher(r, e.target.checked)} />
                      )}
                    </td>
                  )}
                  <td className="px-4 py-3 font-mono">
                    <button type="button" onClick={e => { e.stopPropagation(); setOuverte(r.id); }} className="text-blue-700 dark:text-blue-300 hover:underline">{r.numero}</button>
                  </td>
                  <td className="px-4 py-3">{formatDateDocument(r.date_reception, language)}</td>
                  <td className="px-4 py-3">{r.fournisseur?.raison_sociale}</td>
                  <td className="px-4 py-3">{r.bon_livraison || '—'}</td>
                  <td className="px-4 py-3 text-xs">{r.commandes.join(', ')}</td>
                  <td className="px-4 py-3 text-right">{r.lignes_count}</td>
                  <td className="px-4 py-3 text-right">{montant(r.montant, r.devise)}</td>
                  <td className="px-4 py-3"><BadgeStatutReception statut={r.statut} libelle={tr.statuses[r.statut] ?? r.statut} /></td>
                  <td className="px-4 py-3 text-xs">
                    {r.facture_fournisseur ? <span className="px-2 py-0.5 rounded bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200">{tf.invoiced} {r.facture_fournisseur.numero}</span>
                      : r.statut === 'enregistree' ? <span className="text-gray-500">{tf.toInvoice}</span> : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pagination page={page} dernierePage={pagination.dernierePage} total={pagination.total} onPage={setPage} />

      {ouverte !== null && <DetailReception id={ouverte} onClose={() => setOuverte(null)} onModifiee={charger} />}
      {proposition && (
        <FenetreFactureFournisseur proposition={proposition} onClose={() => setProposition(null)}
          onEnregistree={() => { setProposition(null); setChoisies([]); charger(); }} />
      )}
    </div>
  );
}
