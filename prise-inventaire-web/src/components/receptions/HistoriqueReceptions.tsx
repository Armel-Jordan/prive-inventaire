import { useCallback, useEffect, useState } from 'react';
import { Search } from 'lucide-react';
import Pagination from '@/components/Pagination';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { getReceptionsDocuments, type ReceptionDocument, type StatutReception } from '@/services/receptions';
import DetailReception, { BadgeStatutReception } from '@/components/receptions/DetailReception';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/**
 * Historique des réceptions : recherche (numéro, bon de livraison, commande, fournisseur), statut et période ; chaque
 * réception s'ouvre sur son détail (corriger, annuler, bon de réception).
 */
export default function HistoriqueReceptions() {
  const { t, language } = useLanguage();
  const tr = t.receipts;
  const montant = useMontantDocument();
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
      const resultat = await getReceptionsDocuments({ recherche, statut, date_debut: debut, date_fin: fin, page });
      setReceptions(resultat.data ?? []);
      setPagination({ dernierePage: resultat.last_page ?? 1, total: resultat.total ?? 0 });
      setErreur('');
    } catch (e) {
      setErreur(messageErreur(e, tr.error));
    } finally {
      setChargement(false);
    }
  }, [recherche, statut, debut, fin, page, tr.error]);

  useEffect(() => { charger(); }, [charger]);

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
                <th className="px-4 py-3 text-left">{tr.number}</th>
                <th className="px-4 py-3 text-left">{tr.date}</th>
                <th className="px-4 py-3 text-left">{tr.supplier}</th>
                <th className="px-4 py-3 text-left">{tr.deliveryNote}</th>
                <th className="px-4 py-3 text-left">{tr.orders}</th>
                <th className="px-4 py-3 text-right">{tr.lines}</th>
                <th className="px-4 py-3 text-right">{tr.value}</th>
                <th className="px-4 py-3 text-left">{tr.status}</th>
              </tr>
            </thead>
            <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
              {receptions.map(r => (
                <tr key={r.id} onClick={() => setOuverte(r.id)} className="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40">
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
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pagination page={page} dernierePage={pagination.dernierePage} total={pagination.total} onPage={setPage} />

      {ouverte !== null && <DetailReception id={ouverte} onClose={() => setOuverte(null)} onModifiee={charger} />}
    </div>
  );
}
