import { useCallback, useEffect, useState } from 'react';
import { Landmark, Plus, Search } from 'lucide-react';
import Pagination from '@/components/Pagination';
import DetailTransactionBancaire from '@/components/banque/DetailTransactionBancaire';
import FenetreTransactionBancaire from '@/components/banque/FenetreTransactionBancaire';
import OngletRapprochement from '@/components/banque/OngletRapprochement';
import OngletReleves from '@/components/banque/OngletReleves';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { aujourdhui, formatDateDocument, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import {
  getComptesBanque, getMouvementsBanque, getTransactionsBancaires, relierCompteBancaire, TYPES_TRANSACTION_BANCAIRE,
  type CompteBanque, type MouvementsBanque, type TransactionBancaire, type TypeTransactionBancaire,
} from '@/services/banque';
import { getComptes, getJournaux, type CompteComptable, type JournalComptable } from '@/services/comptabilite';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
type Onglet = 'comptes' | 'mouvements' | 'transactions' | 'releves' | 'rapprochement';
const debutMois = () => `${aujourdhui().slice(0, 8)}01`;

/**
 * Banque (Finance → Banque) : les comptes bancaires de l'entreprise reliés à leur compte comptable et à leur journal,
 * avec leur solde ; les mouvements d'un compte (toutes les écritures de son compte comptable, quelle qu'en soit
 * l'origine) avec le solde progressif ; les transactions bancaires sans facture (dépôts, retraits, virements) ; les
 * relevés importés de la banque reliés aux mouvements ; le rapprochement.
 */
export default function BanquePage() {
  const { t } = useLanguage();
  const tb = t.bank;
  const { peut } = usePermissions();
  const montant = useMontantDocument();
  const [onglet, setOnglet] = useState<Onglet>('comptes');
  const [comptes, setComptes] = useState<CompteBanque[]>([]);
  const [tresorerie, setTresorerie] = useState<CompteComptable[]>([]);
  const [journaux, setJournaux] = useState<JournalComptable[]>([]);
  const [erreur, setErreur] = useState('');
  const [message, setMessage] = useState('');

  const chargerComptes = useCallback(() => getComptesBanque().then(r => setComptes(r.comptes), e => setErreur(messageErreur(e, tb.error))), [tb.error]);
  useEffect(() => { chargerComptes(); }, [chargerComptes]);
  useEffect(() => {
    let actif = true;
    getComptes().then(liste => { if (actif) setTresorerie((liste ?? []).filter(c => c.type === 'tresorerie' && c.actif)); }, () => {});
    getJournaux().then(liste => { if (actif) setJournaux((liste ?? []).filter(j => j.actif)); }, () => {});
    return () => { actif = false; };
  }, []);

  async function relier(compte: CompteBanque, champ: 'compte_comptable_id' | 'journal_id', valeur: string) {
    setErreur('');
    setMessage('');
    try {
      await relierCompteBancaire(compte.id, {
        compte_comptable_id: champ === 'compte_comptable_id' ? (Number(valeur) || null) : compte.compte_comptable_id,
        journal_id: champ === 'journal_id' ? (Number(valeur) || null) : compte.journal_id,
      });
      setMessage(tb.linked.replace('{nom}', compte.nom));
      await chargerComptes();
    } catch (e) {
      setErreur(messageErreur(e, tb.error));
    }
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Landmark size={24} /> {tb.title}</h1>
        <p className="text-sm text-gray-500 max-w-3xl">{tb.subtitle}</p>
      </div>
      <div role="tablist" className="flex gap-1 border-b dark:border-gray-700">
        {(['comptes', 'mouvements', 'transactions', 'releves', 'rapprochement'] as Onglet[]).map(o => (
          <button key={o} role="tab" type="button" aria-selected={onglet === o} onClick={() => setOnglet(o)}
            className={`px-4 py-2 text-sm border-b-2 -mb-px ${onglet === o ? 'border-blue-600 text-blue-700 dark:text-blue-300 font-medium' : 'border-transparent text-gray-600 dark:text-gray-300 hover:text-gray-900'}`}>
            {tb.tabs[o]}
          </button>
        ))}
      </div>
      {message && <div role="status" className="px-3 py-2 rounded-lg text-sm bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200">{message}</div>}
      {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}

      {onglet === 'comptes' && (
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-x-auto">
          {comptes.length === 0 ? <p className="p-4 text-sm text-gray-500">{tb.noAccount}</p> : (
            <table className="w-full min-w-[800px] text-sm" aria-label={tb.tabs.comptes}>
              <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                <tr>
                  <th className="px-4 py-3 text-left">{tb.account}</th>
                  <th className="px-4 py-3 text-left">{tb.bankName}</th>
                  <th className="px-4 py-3 text-left">{tb.ledgerAccount}</th>
                  <th className="px-4 py-3 text-left">{tb.journal}</th>
                  <th className="px-4 py-3 text-right">{tb.balance}</th>
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                {comptes.map(c => (
                  <tr key={c.id} className={c.actif ? '' : 'text-gray-400'}>
                    <td className="px-4 py-3">
                      <span className="font-medium">{c.nom}</span>{c.par_defaut && <span className="ml-2 text-xs text-blue-700 dark:text-blue-300">{tb.default}</span>}
                      <span className="block text-xs text-gray-500">{[c.numero_compte, c.iban, c.devise_code].filter(Boolean).join(' · ')}</span>
                    </td>
                    <td className="px-4 py-3">{c.banque}</td>
                    <td className="px-4 py-3">
                      {peut('banque', 'configurer') ? (
                        <select value={c.compte_comptable_id ?? ''} aria-label={`${tb.ledgerAccount} ${c.nom}`} onChange={e => relier(c, 'compte_comptable_id', e.target.value)} className={classeChamp}>
                          <option value="">{tb.notLinked}</option>
                          {tresorerie.map(cc => <option key={cc.id} value={cc.id}>{cc.numero} — {cc.libelle}</option>)}
                        </select>
                      ) : (c.compte_comptable ? `${c.compte_comptable.numero} — ${c.compte_comptable.libelle}` : tb.notLinked)}
                    </td>
                    <td className="px-4 py-3">
                      {peut('banque', 'configurer') ? (
                        <select value={c.journal_id ?? ''} aria-label={`${tb.journal} ${c.nom}`} onChange={e => relier(c, 'journal_id', e.target.value)} className={classeChamp}>
                          <option value="">{tb.defaultJournal}</option>
                          {journaux.map(j => <option key={j.id} value={j.id}>{j.code} — {j.libelle}</option>)}
                        </select>
                      ) : (c.journal ? `${c.journal.code} — ${c.journal.libelle}` : tb.defaultJournal)}
                    </td>
                    <td className="px-4 py-3 text-right font-medium">{c.solde === null ? '—' : montant(c.solde)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          <p className="px-4 py-3 text-xs text-gray-500">{tb.accountsHelp}</p>
        </div>
      )}

      {onglet === 'mouvements' && <OngletMouvements comptes={comptes} />}
      {onglet === 'releves' && <OngletReleves comptes={comptes} onComptesModifies={chargerComptes} />}
      {onglet === 'rapprochement' && <OngletRapprochement comptes={comptes} />}
      {onglet === 'transactions' && <OngletTransactions comptes={comptes} peutSaisir={peut('banque', 'saisir')} onMessage={setMessage} onComptesModifies={chargerComptes} />}
    </div>
  );
}

/** Mouvements d'un compte bancaire relié, avec le solde progressif. */
function OngletMouvements({ comptes: liste }: { comptes: CompteBanque[] }) {
  const { t, language } = useLanguage();
  const tb = t.bank;
  const montant = useMontantDocument();
  const relies = liste.filter(c => c.compte_comptable_id);
  const [compte, setCompte] = useState(String(relies[0]?.id ?? ''));
  const [du, setDu] = useState(debutMois());
  const [au, setAu] = useState(aujourdhui());
  const [donnees, setDonnees] = useState<MouvementsBanque | null>(null);
  const [erreurMouvements, setErreurMouvements] = useState('');

  useEffect(() => {
    if (!compte) return;
    let actif = true;
    getMouvementsBanque(Number(compte), du, au).then(d => { if (actif) { setDonnees(d); setErreurMouvements(''); } }, e => { if (actif) setErreurMouvements(messageErreur(e, tb.error)); });
    return () => { actif = false; };
  }, [compte, du, au, tb.error]);

  return (
    <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm">
      <div className="p-4 border-b dark:border-gray-700 flex flex-wrap items-end gap-3">
        <label className="text-xs text-gray-500 dark:text-gray-400">{tb.account}
          <select value={compte} onChange={e => setCompte(e.target.value)} className={`${classeChamp} block`}>
            {relies.length === 0 && <option value="">{tb.noLinkedAccount}</option>}
            {relies.map(c => <option key={c.id} value={c.id}>{c.nom}</option>)}
          </select>
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tb.from}<input type="date" value={du} onChange={e => setDu(e.target.value)} className={`${classeChamp} block`} /></label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tb.to}<input type="date" value={au} onChange={e => setAu(e.target.value)} className={`${classeChamp} block`} /></label>
      </div>
      {erreurMouvements && <p className="px-4 py-3 text-sm text-red-700">{erreurMouvements}</p>}
      {donnees && (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-sm" aria-label={tb.tabs.mouvements}>
            <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
              <tr>
                <th className="px-3 py-2 text-left">{tb.date}</th>
                <th className="px-3 py-2 text-left">{tb.entry}</th>
                <th className="px-3 py-2 text-left">{tb.label}</th>
                <th className="px-3 py-2 text-left">{tb.origin}</th>
                <th className="px-3 py-2 text-right">{tb.moneyIn}</th>
                <th className="px-3 py-2 text-right">{tb.moneyOut}</th>
                <th className="px-3 py-2 text-right">{tb.balance}</th>
              </tr>
            </thead>
            <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
              <tr className="text-gray-500"><td colSpan={6} className="px-3 py-2">{tb.openingBalance}</td><td className="px-3 py-2 text-right">{montant(donnees.solde_ouverture)}</td></tr>
              {donnees.lignes.map((l, i) => (
                <tr key={`${l.ecriture_id}-${i}`}>
                  <td className="px-3 py-2 whitespace-nowrap">{formatDateDocument(l.date, language)}</td>
                  <td className="px-3 py-2 font-mono text-xs">{l.journal} {l.numero}</td>
                  <td className="px-3 py-2">{l.libelle}{l.reference ? <span className="block text-xs text-gray-500">{l.reference}</span> : null}</td>
                  <td className="px-3 py-2 text-xs">{t.accounting.journal.sources[l.source] ?? l.source}</td>
                  <td className="px-3 py-2 text-right">{l.debit ? montant(l.debit) : ''}</td>
                  <td className="px-3 py-2 text-right">{l.credit ? montant(l.credit) : ''}</td>
                  <td className="px-3 py-2 text-right font-medium">{montant(l.solde)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot className="font-semibold dark:text-white">
              <tr>
                <td colSpan={4} className="px-3 py-2 text-right">{tb.closingBalance}</td>
                <td className="px-3 py-2 text-right">{montant(donnees.total_debit)}</td>
                <td className="px-3 py-2 text-right">{montant(donnees.total_credit)}</td>
                <td className="px-3 py-2 text-right">{montant(donnees.solde_final ?? 0)}</td>
              </tr>
            </tfoot>
          </table>
          {donnees.lignes.length === 0 && <p className="p-4 text-sm text-gray-500">{tb.noMovement}</p>}
          <p className="px-4 py-3 text-xs text-gray-500">{tb.movementsHelp}</p>
        </div>
      )}
    </div>
  );
}

/** Transactions bancaires : filtres, liste, saisie et détail. */
function OngletTransactions({ comptes: liste, peutSaisir, onMessage, onComptesModifies }: {
  comptes: CompteBanque[];
  peutSaisir: boolean;
  onMessage: (m: string) => void;
  onComptesModifies: () => void;
}) {
  const { t, language } = useLanguage();
  const tb = t.bank;
  const montant = useMontantDocument();
  const [filtres, setFiltres] = useState({ compte: '', type: '' as TypeTransactionBancaire | '', statut: '' as 'valide' | 'annule' | '', recherche: '' });
  const [page, setPage] = useState(1);
  const [transactions, setTransactions] = useState<TransactionBancaire[]>([]);
  const [pagination, setPagination] = useState({ dernierePage: 1, total: 0 });
  const [nouvelle, setNouvelle] = useState(false);
  const [ouverte, setOuverte] = useState<number | null>(null);

  const charger = useCallback(() => getTransactionsBancaires({
    compte_bancaire_id: Number(filtres.compte) || undefined, type: filtres.type, statut: filtres.statut, recherche: filtres.recherche, page,
  }).then(r => { setTransactions(r.data ?? []); setPagination({ dernierePage: r.last_page ?? 1, total: r.total ?? 0 }); }, () => {}), [filtres, page]);
  useEffect(() => { charger(); }, [charger]);

  return (
    <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm">
      <div className="p-4 border-b dark:border-gray-700 flex flex-wrap items-end gap-3">
        <label className="relative flex-1 min-w-[200px]">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input value={filtres.recherche} onChange={e => { setFiltres({ ...filtres, recherche: e.target.value }); setPage(1); }} placeholder={tb.searchHelp} aria-label={tb.search} className={`${classeChamp} w-full pl-9`} />
        </label>
        <select value={filtres.compte} aria-label={tb.account} onChange={e => { setFiltres({ ...filtres, compte: e.target.value }); setPage(1); }} className={classeChamp}>
          <option value="">{tb.allAccounts}</option>
          {liste.map(c => <option key={c.id} value={c.id}>{c.nom}</option>)}
        </select>
        <select value={filtres.type} aria-label={tb.type} onChange={e => { setFiltres({ ...filtres, type: e.target.value as TypeTransactionBancaire | '' }); setPage(1); }} className={classeChamp}>
          <option value="">{tb.allTypes}</option>
          {TYPES_TRANSACTION_BANCAIRE.map(type => <option key={type} value={type}>{tb.types[type]}</option>)}
        </select>
        <select value={filtres.statut} aria-label={tb.status} onChange={e => { setFiltres({ ...filtres, statut: e.target.value as 'valide' | 'annule' | '' }); setPage(1); }} className={classeChamp}>
          <option value="">{tb.allStatuses}</option>
          <option value="valide">{tb.statuses.valide}</option>
          <option value="annule">{tb.statuses.annule}</option>
        </select>
        {peutSaisir && (
          <button type="button" onClick={() => setNouvelle(true)} className="ml-auto flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
            <Plus size={18} /> {tb.newTransaction}
          </button>
        )}
      </div>
      {transactions.length === 0 ? <p className="p-4 text-sm text-gray-500">{tb.noTransaction}</p> : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[800px] text-sm" aria-label={tb.tabs.transactions}>
            <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
              <tr>
                <th className="px-4 py-3 text-left">{tb.number}</th>
                <th className="px-4 py-3 text-left">{tb.date}</th>
                <th className="px-4 py-3 text-left">{tb.type}</th>
                <th className="px-4 py-3 text-left">{tb.account}</th>
                <th className="px-4 py-3 text-left">{tb.label}</th>
                <th className="px-4 py-3 text-right">{tb.amount}</th>
                <th className="px-4 py-3 text-left">{tb.status}</th>
              </tr>
            </thead>
            <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
              {transactions.map(tr => (
                <tr key={tr.id} onClick={() => setOuverte(tr.id)} className={`cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/40 ${tr.statut === 'annule' ? 'text-gray-400' : ''}`}>
                  <td className="px-4 py-3 font-mono">
                    <button type="button" onClick={ev => { ev.stopPropagation(); setOuverte(tr.id); }} className="text-blue-700 dark:text-blue-300 hover:underline">{tr.numero}</button>
                  </td>
                  <td className="px-4 py-3 whitespace-nowrap">{formatDateDocument(tr.date_transaction, language)}</td>
                  <td className="px-4 py-3">{tb.types[tr.type]}</td>
                  <td className="px-4 py-3">{tr.compte_bancaire?.nom}{tr.destination ? ` → ${tr.destination.nom}` : ''}</td>
                  <td className="px-4 py-3">{tr.libelle}{tr.reference ? <span className="block text-xs text-gray-500">{tr.reference}</span> : null}</td>
                  <td className={`px-4 py-3 text-right font-medium ${tr.type === 'retrait' ? 'text-red-700 dark:text-red-300' : ''}`}>{tr.type === 'retrait' ? '- ' : ''}{montant(tr.montant, tr.devise)}</td>
                  <td className="px-4 py-3">{tb.statuses[tr.statut]}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pagination page={page} dernierePage={pagination.dernierePage} total={pagination.total} onPage={setPage} />
      {nouvelle && (
        <FenetreTransactionBancaire comptes={liste} onClose={() => setNouvelle(false)} onEnregistree={tr => {
          setNouvelle(false);
          onMessage(tb.saved.replace('{numero}', tr.numero));
          charger();
          onComptesModifies();
          setOuverte(tr.id);
        }} />
      )}
      {ouverte !== null && <DetailTransactionBancaire id={ouverte} onClose={() => setOuverte(null)} onModifiee={() => { charger(); onComptesModifies(); }} />}
    </div>
  );
}
