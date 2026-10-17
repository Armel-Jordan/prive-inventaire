import { useCallback, useEffect, useState } from 'react';
import { FileText, Plus, Search } from 'lucide-react';
import Pagination from '@/components/Pagination';
import DetailFactureFournisseur, { BadgeEtatFactureFournisseur } from '@/components/facturesFournisseurs/DetailFactureFournisseur';
import FenetreFactureFournisseur from '@/components/facturesFournisseurs/FenetreFactureFournisseur';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import {
  getFacturesFournisseurs, type EtatFactureFournisseur, type FactureFournisseur, type ResumeFacturesFournisseurs,
} from '@/services/facturesFournisseurs';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
const ETATS: (EtatFactureFournisseur | 'a_payer')[] = ['a_payer', 'impayee', 'partielle', 'en_retard', 'payee', 'annulee'];

/**
 * Factures fournisseurs (Achats → Factures fournisseurs) : reste à payer et montant en retard, liste filtrable par
 * recherche, état de paiement et période ; chaque facture s'ouvre sur son détail (paiements, rapprochement).
 */
export default function FacturesFournisseursPage() {
  const { t, language } = useLanguage();
  const tf = t.supplierInvoices;
  const { peut } = usePermissions();
  const montant = useMontantDocument();
  const [saisie, setSaisie] = useState('');
  const [recherche, setRecherche] = useState('');
  const [etat, setEtat] = useState<EtatFactureFournisseur | 'a_payer' | ''>('');
  const [debut, setDebut] = useState('');
  const [fin, setFin] = useState('');
  const [page, setPage] = useState(1);
  const [factures, setFactures] = useState<FactureFournisseur[]>([]);
  const [resume, setResume] = useState<ResumeFacturesFournisseurs | null>(null);
  const [pagination, setPagination] = useState({ dernierePage: 1, total: 0 });
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState('');
  const [ouverte, setOuverte] = useState<number | null>(null);
  const [nouvelle, setNouvelle] = useState(false);

  useEffect(() => {
    const minuterie = setTimeout(() => { setRecherche(saisie.trim()); setPage(1); }, 350);
    return () => clearTimeout(minuterie);
  }, [saisie]);

  const charger = useCallback(async () => {
    setChargement(true);
    try {
      const resultat = await getFacturesFournisseurs({ recherche, etat, date_debut: debut, date_fin: fin, page });
      setFactures(resultat.data ?? []);
      setResume(resultat.resume);
      setPagination({ dernierePage: resultat.last_page ?? 1, total: resultat.total ?? 0 });
      setErreur('');
    } catch (e) {
      setErreur(messageErreur(e, tf.error));
    } finally {
      setChargement(false);
    }
  }, [recherche, etat, debut, fin, page, tf.error]);

  useEffect(() => { charger(); }, [charger]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><FileText size={24} /> {tf.title}</h1>
          <p className="text-sm text-gray-500">{tf.subtitle}</p>
        </div>
        {peut('factures_fournisseurs', 'creer') && (
          <button type="button" onClick={() => setNouvelle(true)} className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            <Plus size={18} /> {tf.newInvoice}
          </button>
        )}
      </div>

      {resume && (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4">
            <p className="text-sm text-gray-500">{tf.totalToPay}</p>
            <p className="text-2xl font-semibold dark:text-white">{montant(resume.a_payer)}</p>
            <p className="text-xs text-gray-500">{tf.invoicesCount.replace('{n}', String(resume.nombre_a_payer))}</p>
          </div>
          <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-4">
            <p className="text-sm text-gray-500">{tf.totalLate}</p>
            <p className={`text-2xl font-semibold ${resume.en_retard > 0 ? 'text-red-600' : 'dark:text-white'}`}>{montant(resume.en_retard)}</p>
            <p className="text-xs text-gray-500">{tf.invoicesCount.replace('{n}', String(resume.nombre_en_retard))}</p>
          </div>
        </div>
      )}

      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm">
        <div className="p-4 border-b dark:border-gray-700 flex flex-wrap items-end gap-3">
          <label className="relative flex-1 min-w-[220px]">
            <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input value={saisie} onChange={e => setSaisie(e.target.value)} placeholder={tf.search} aria-label={tf.search} className={`${classeChamp} w-full pl-9`} />
          </label>
          <select value={etat} onChange={e => { setEtat(e.target.value as EtatFactureFournisseur | 'a_payer' | ''); setPage(1); }} aria-label={tf.state} className={classeChamp}>
            <option value="">{tf.allStates}</option>
            {ETATS.map(e => <option key={e} value={e}>{e === 'a_payer' ? tf.toPay : tf.states[e]}</option>)}
          </select>
          <label className="text-sm text-gray-600 dark:text-gray-300 flex items-center gap-1">
            {tf.from} <input type="date" value={debut} onChange={e => { setDebut(e.target.value); setPage(1); }} className={classeChamp} />
          </label>
          <label className="text-sm text-gray-600 dark:text-gray-300 flex items-center gap-1">
            {tf.to} <input type="date" value={fin} onChange={e => { setFin(e.target.value); setPage(1); }} className={classeChamp} />
          </label>
        </div>

        {erreur && <p className="px-4 py-3 text-sm text-red-700 dark:text-red-300">{erreur}</p>}
        {chargement && factures.length === 0 ? (
          <p className="p-4 text-sm text-gray-500">{tf.loading}</p>
        ) : factures.length === 0 ? (
          <p className="p-4 text-sm text-gray-500">{tf.none}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm" aria-label={tf.title}>
              <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                <tr>
                  <th className="px-4 py-3 text-left">{tf.number}</th>
                  <th className="px-4 py-3 text-left">{tf.supplier}</th>
                  <th className="px-4 py-3 text-left">{tf.supplierNumber}</th>
                  <th className="px-4 py-3 text-left">{tf.date}</th>
                  <th className="px-4 py-3 text-left">{tf.dueDate}</th>
                  <th className="px-4 py-3 text-right">{tf.total}</th>
                  <th className="px-4 py-3 text-right">{tf.remaining}</th>
                  <th className="px-4 py-3 text-left">{tf.state}</th>
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                {factures.map(f => (
                  <tr key={f.id} onClick={() => setOuverte(f.id)} className="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40">
                    <td className="px-4 py-3 font-mono">
                      <button type="button" onClick={e => { e.stopPropagation(); setOuverte(f.id); }} className="text-blue-700 dark:text-blue-300 hover:underline">{f.numero}</button>
                    </td>
                    <td className="px-4 py-3">{f.fournisseur?.raison_sociale}</td>
                    <td className="px-4 py-3">{f.numero_fournisseur || '—'}</td>
                    <td className="px-4 py-3">{formatDateDocument(f.date_facture, language)}</td>
                    <td className="px-4 py-3">{formatDateDocument(f.date_echeance, language)}</td>
                    <td className="px-4 py-3 text-right">{montant(f.montant_ttc, f.devise)}</td>
                    <td className="px-4 py-3 text-right font-medium">{montant(f.reste_a_payer, f.devise)}</td>
                    <td className="px-4 py-3"><BadgeEtatFactureFournisseur facture={f} libelles={tf.states} retard={tf.lateDays} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pagination page={page} dernierePage={pagination.dernierePage} total={pagination.total} onPage={setPage} />
      </div>

      {ouverte !== null && <DetailFactureFournisseur id={ouverte} onClose={() => setOuverte(null)} onModifiee={charger} />}
      {nouvelle && (
        <FenetreFactureFournisseur onClose={() => setNouvelle(false)} onEnregistree={f => { setNouvelle(false); charger(); setOuverte(f.id); }} />
      )}
    </div>
  );
}
