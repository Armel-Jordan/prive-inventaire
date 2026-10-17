import { useCallback, useEffect, useState } from 'react';
import { BanknoteArrowUp, Plus, Search } from 'lucide-react';
import Pagination from '@/components/Pagination';
import DetailDebourse from '@/components/debourses/DetailDebourse';
import FenetreDebourse from '@/components/debourses/FenetreDebourse';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import {
  getDebourses, MODES_DEBOURSE, type Debourse, type ModeDebourse, type ResumeDebourses, type StatutDebourse,
} from '@/services/debourses';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/**
 * Déboursés (Achats → Déboursés) : l'argent versé aux fournisseurs, réparti sur leurs factures ; liste filtrable par
 * recherche (numéro, référence, fournisseur, facture), mode, statut et période, avec ses totaux ; chaque déboursé
 * s'ouvre sur son détail (factures réglées, avis de paiement, annulation).
 */
export default function DeboursesPage() {
  const { t, language } = useLanguage();
  const td = t.supplierPayments;
  const { peut } = usePermissions();
  const montant = useMontantDocument();
  const [saisie, setSaisie] = useState('');
  const [recherche, setRecherche] = useState('');
  const [mode, setMode] = useState<ModeDebourse | ''>('');
  const [statut, setStatut] = useState<StatutDebourse | ''>('');
  const [debut, setDebut] = useState('');
  const [fin, setFin] = useState('');
  const [page, setPage] = useState(1);
  const [debourses, setDebourses] = useState<Debourse[]>([]);
  const [resume, setResume] = useState<ResumeDebourses | null>(null);
  const [pagination, setPagination] = useState({ dernierePage: 1, total: 0 });
  const [chargement, setChargement] = useState(true);
  const [erreur, setErreur] = useState('');
  const [message, setMessage] = useState('');
  const [ouvert, setOuvert] = useState<number | null>(null);
  const [nouveau, setNouveau] = useState(false);

  useEffect(() => {
    const minuterie = setTimeout(() => { setRecherche(saisie.trim()); setPage(1); }, 350);
    return () => clearTimeout(minuterie);
  }, [saisie]);

  const charger = useCallback(async () => {
    setChargement(true);
    try {
      const resultat = await getDebourses({ recherche, mode, statut, date_debut: debut, date_fin: fin, page });
      setDebourses(resultat.data ?? []);
      setResume(resultat.resume);
      setPagination({ dernierePage: resultat.last_page ?? 1, total: resultat.total ?? 0 });
      setErreur('');
    } catch (e) {
      setErreur(messageErreur(e, td.error));
    } finally {
      setChargement(false);
    }
  }, [recherche, mode, statut, debut, fin, page, td.error]);

  useEffect(() => { charger(); }, [charger]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><BanknoteArrowUp size={24} /> {td.title}</h1>
          <p className="text-sm text-gray-500 max-w-3xl">{td.subtitle}</p>
        </div>
        {peut('debourses', 'creer') && (
          <button type="button" onClick={() => setNouveau(true)} className="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
            <Plus size={18} /> {td.newPayment}
          </button>
        )}
      </div>

      {message && <div role="status" className="px-3 py-2 rounded-lg text-sm bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200">{message}</div>}

      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm">
        <div className="p-4 border-b dark:border-gray-700 flex flex-wrap items-end gap-3">
          <label className="relative flex-1 min-w-[220px]">
            <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input value={saisie} onChange={e => setSaisie(e.target.value)} placeholder={td.searchHelp} aria-label={td.search} className={`${classeChamp} w-full pl-9`} />
          </label>
          <select value={mode} onChange={e => { setMode(e.target.value as ModeDebourse | ''); setPage(1); }} aria-label={td.mode} className={classeChamp}>
            <option value="">{td.allModes}</option>
            {MODES_DEBOURSE.map(m => <option key={m} value={m}>{td.modes[m]}</option>)}
          </select>
          <select value={statut} onChange={e => { setStatut(e.target.value as StatutDebourse | ''); setPage(1); }} aria-label={td.status} className={classeChamp}>
            <option value="">{td.allStatuses}</option>
            <option value="valide">{td.statuses.valide}</option>
            <option value="annule">{td.statuses.annule}</option>
          </select>
          <label className="text-sm text-gray-600 dark:text-gray-300 flex items-center gap-1">
            {td.from} <input type="date" value={debut} onChange={e => { setDebut(e.target.value); setPage(1); }} className={classeChamp} />
          </label>
          <label className="text-sm text-gray-600 dark:text-gray-300 flex items-center gap-1">
            {td.to} <input type="date" value={fin} onChange={e => { setFin(e.target.value); setPage(1); }} className={classeChamp} />
          </label>
        </div>

        {resume && resume.nombre > 0 && (
          <p className="px-4 pt-3 text-sm text-gray-600 dark:text-gray-300">
            {td.totals.replace('{n}', String(resume.nombre)).replace('{montant}', montant(resume.montant)).replace('{escompte}', montant(resume.escompte))}
          </p>
        )}
        {erreur && <p className="px-4 py-3 text-sm text-red-700 dark:text-red-300">{erreur}</p>}
        {chargement && debourses.length === 0 ? (
          <p className="p-4 text-sm text-gray-500">{td.loading}</p>
        ) : debourses.length === 0 ? (
          <p className="p-4 text-sm text-gray-500">{td.none}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm" aria-label={td.title}>
              <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                <tr>
                  <th className="px-4 py-3 text-left">{td.number}</th>
                  <th className="px-4 py-3 text-left">{td.date}</th>
                  <th className="px-4 py-3 text-left">{td.supplier}</th>
                  <th className="px-4 py-3 text-left">{td.mode}</th>
                  <th className="px-4 py-3 text-left">{td.invoices}</th>
                  <th className="px-4 py-3 text-right">{td.amountPaid}</th>
                  <th className="px-4 py-3 text-right">{td.discount}</th>
                  <th className="px-4 py-3 text-left">{td.status}</th>
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                {debourses.map(d => (
                  <tr key={d.id} onClick={() => setOuvert(d.id)} className={`cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40 ${d.statut === 'annule' ? 'text-gray-400' : ''}`}>
                    <td className="px-4 py-3 font-mono">
                      <button type="button" onClick={ev => { ev.stopPropagation(); setOuvert(d.id); }} className="text-blue-700 dark:text-blue-300 hover:underline">{d.numero}</button>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">{formatDateDocument(d.date_debourse, language)}</td>
                    <td className="px-4 py-3">{d.fournisseur?.raison_sociale}</td>
                    <td className="px-4 py-3">{td.modes[d.mode] ?? d.mode}{d.reference ? <span className="block text-xs text-gray-500">{d.reference}</span> : null}</td>
                    <td className="px-4 py-3 text-xs">{(d.lignes ?? []).map(l => l.facture?.numero_fournisseur || l.facture?.numero).filter(Boolean).join(', ') || '—'}</td>
                    <td className="px-4 py-3 text-right font-medium whitespace-nowrap">{montant(d.montant, d.devise)}</td>
                    <td className="px-4 py-3 text-right whitespace-nowrap">{Number(d.escompte) > 0 ? montant(d.escompte, d.devise) : '—'}</td>
                    <td className="px-4 py-3">
                      <span className={`px-2 py-0.5 rounded text-xs font-medium ${d.statut === 'valide' ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-200' : 'bg-gray-200 text-gray-500 line-through dark:bg-gray-700 dark:text-gray-400'}`}>
                        {td.statuses[d.statut]}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <Pagination page={page} dernierePage={pagination.dernierePage} total={pagination.total} onPage={setPage} />
      </div>

      {ouvert !== null && <DetailDebourse id={ouvert} onClose={() => setOuvert(null)} onModifie={charger} />}
      {nouveau && (
        <FenetreDebourse onClose={() => setNouveau(false)} onEnregistre={d => {
          setNouveau(false);
          setMessage(td.saved.replace('{numero}', d.numero));
          charger();
          setOuvert(d.id);
        }} />
      )}
    </div>
  );
}
