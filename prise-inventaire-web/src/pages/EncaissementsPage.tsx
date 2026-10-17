import { useCallback, useEffect, useState } from 'react';
import { Coins, Plus, Search } from 'lucide-react';
import Pagination from '@/components/Pagination';
import DetailEncaissement from '@/components/encaissements/DetailEncaissement';
import FenetreEncaissement from '@/components/encaissements/FenetreEncaissement';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import {
  getEncaissements, MODES_ENCAISSEMENT, type Encaissement, type ModeEncaissement, type ResumeEncaissements, type StatutEncaissement,
} from '@/services/encaissements';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/**
 * Encaissements (Ventes → Encaissements) : l'argent reçu des clients, réparti sur leurs factures ; liste filtrable par
 * recherche (numéro, référence, client, facture), mode, statut et période, avec ses totaux ; chaque encaissement s'ouvre
 * sur son détail (factures réglées, reçu, annulation).
 */
export default function EncaissementsPage() {
  const { t, language } = useLanguage();
  const te = t.customerPayments;
  const { peut } = usePermissions();
  const montant = useMontantDocument();
  const [saisie, setSaisie] = useState('');
  const [recherche, setRecherche] = useState('');
  const [mode, setMode] = useState<ModeEncaissement | ''>('');
  const [statut, setStatut] = useState<StatutEncaissement | ''>('');
  const [debut, setDebut] = useState('');
  const [fin, setFin] = useState('');
  const [page, setPage] = useState(1);
  const [encaissements, setEncaissements] = useState<Encaissement[]>([]);
  const [resume, setResume] = useState<ResumeEncaissements | null>(null);
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
      const resultat = await getEncaissements({ recherche, mode, statut, date_debut: debut, date_fin: fin, page });
      setEncaissements(resultat.data ?? []);
      setResume(resultat.resume);
      setPagination({ dernierePage: resultat.last_page ?? 1, total: resultat.total ?? 0 });
      setErreur('');
    } catch (e) {
      setErreur(messageErreur(e, te.error));
    } finally {
      setChargement(false);
    }
  }, [recherche, mode, statut, debut, fin, page, te.error]);

  useEffect(() => { charger(); }, [charger]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Coins size={24} /> {te.title}</h1>
          <p className="text-sm text-gray-500 max-w-3xl">{te.subtitle}</p>
        </div>
        {peut('encaissements', 'creer') && (
          <button type="button" onClick={() => setNouveau(true)} className="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
            <Plus size={18} /> {te.newPayment}
          </button>
        )}
      </div>

      {message && <div role="status" className="px-3 py-2 rounded-lg text-sm bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200">{message}</div>}

      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm">
        <div className="p-4 border-b dark:border-gray-700 flex flex-wrap items-end gap-3">
          <label className="relative flex-1 min-w-[220px]">
            <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input value={saisie} onChange={e => setSaisie(e.target.value)} placeholder={te.searchHelp} aria-label={te.search} className={`${classeChamp} w-full pl-9`} />
          </label>
          <select value={mode} onChange={e => { setMode(e.target.value as ModeEncaissement | ''); setPage(1); }} aria-label={te.mode} className={classeChamp}>
            <option value="">{te.allModes}</option>
            {MODES_ENCAISSEMENT.map(m => <option key={m} value={m}>{te.modes[m]}</option>)}
          </select>
          <select value={statut} onChange={e => { setStatut(e.target.value as StatutEncaissement | ''); setPage(1); }} aria-label={te.status} className={classeChamp}>
            <option value="">{te.allStatuses}</option>
            <option value="valide">{te.statuses.valide}</option>
            <option value="annule">{te.statuses.annule}</option>
          </select>
          <label className="text-sm text-gray-600 dark:text-gray-300 flex items-center gap-1">
            {te.from} <input type="date" value={debut} onChange={e => { setDebut(e.target.value); setPage(1); }} className={classeChamp} />
          </label>
          <label className="text-sm text-gray-600 dark:text-gray-300 flex items-center gap-1">
            {te.to} <input type="date" value={fin} onChange={e => { setFin(e.target.value); setPage(1); }} className={classeChamp} />
          </label>
        </div>

        {resume && resume.nombre > 0 && (
          <p className="px-4 pt-3 text-sm text-gray-600 dark:text-gray-300">
            {te.totals.replace('{n}', String(resume.nombre)).replace('{montant}', montant(resume.montant))
              .replace('{escompte}', montant(resume.escompte)).replace('{surplus}', montant(resume.surplus))}
          </p>
        )}
        {erreur && <p className="px-4 py-3 text-sm text-red-700 dark:text-red-300">{erreur}</p>}
        {chargement && encaissements.length === 0 ? (
          <p className="p-4 text-sm text-gray-500">{te.loading}</p>
        ) : encaissements.length === 0 ? (
          <p className="p-4 text-sm text-gray-500">{te.none}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm" aria-label={te.title}>
              <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                <tr>
                  <th className="px-4 py-3 text-left">{te.number}</th>
                  <th className="px-4 py-3 text-left">{te.date}</th>
                  <th className="px-4 py-3 text-left">{te.customer}</th>
                  <th className="px-4 py-3 text-left">{te.mode}</th>
                  <th className="px-4 py-3 text-left">{te.invoices}</th>
                  <th className="px-4 py-3 text-right">{te.amountReceived}</th>
                  <th className="px-4 py-3 text-right">{te.surplus}</th>
                  <th className="px-4 py-3 text-left">{te.status}</th>
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                {encaissements.map(e => (
                  <tr key={e.id} onClick={() => setOuvert(e.id)} className={`cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40 ${e.statut === 'annule' ? 'text-gray-400' : ''}`}>
                    <td className="px-4 py-3 font-mono">
                      <button type="button" onClick={ev => { ev.stopPropagation(); setOuvert(e.id); }} className="text-blue-700 dark:text-blue-300 hover:underline">{e.numero}</button>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">{formatDateDocument(e.date_encaissement, language)}</td>
                    <td className="px-4 py-3">{e.client?.raison_sociale}</td>
                    <td className="px-4 py-3">{te.modes[e.mode] ?? e.mode}{e.reference ? <span className="block text-xs text-gray-500">{e.reference}</span> : null}</td>
                    <td className="px-4 py-3 text-xs">{(e.lignes ?? []).map(l => l.facture?.numero).filter(Boolean).join(', ') || '—'}</td>
                    <td className="px-4 py-3 text-right font-medium whitespace-nowrap">{montant(e.montant, e.devise)}</td>
                    <td className="px-4 py-3 text-right whitespace-nowrap">{Number(e.surplus) > 0 ? montant(e.surplus, e.devise) : '—'}</td>
                    <td className="px-4 py-3">
                      <span className={`px-2 py-0.5 rounded text-xs font-medium ${e.statut === 'valide' ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-200' : 'bg-gray-200 text-gray-500 line-through dark:bg-gray-700 dark:text-gray-400'}`}>
                        {te.statuses[e.statut]}
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

      {ouvert !== null && <DetailEncaissement id={ouvert} onClose={() => setOuvert(null)} onModifie={charger} />}
      {nouveau && (
        <FenetreEncaissement onClose={() => setNouveau(false)} onEnregistre={e => {
          setNouveau(false);
          setMessage(te.saved.replace('{numero}', e.numero));
          charger();
          setOuvert(e.id);
        }} />
      )}
    </div>
  );
}
