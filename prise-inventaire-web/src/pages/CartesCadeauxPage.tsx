import { useEffect, useState } from 'react';
import { Eye, FileText, Gift, Plus, Search, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { formatDateDocument, formatDateHeure, useMontantDocument } from '@/lib/documents';
import { MODES_CAISSE, type ModeCaisse } from '@/services/caisses';
import {
  ETATS_CARTE, getCarteCadeau, getCartesCadeaux, imprimerCertificat, modifierCarteCadeau, rechargerCarteCadeau, rembourserCarteCadeau,
  type CarteCadeau, type EtatCarte,
} from '@/services/cartesCadeaux';
import { useEncaissementCaisse } from '@/hooks/useEncaissementCaisse';
import CaisseDuPoste from '@/components/caisse/CaisseDuPoste';
import VendreCarte from '@/components/cartesCadeaux/VendreCarte';
import Pagination from '@/components/Pagination';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeLibelle = 'block text-xs text-gray-500 dark:text-gray-400';
const COULEURS: Record<EtatCarte, string> = {
  active: 'bg-emerald-100 text-emerald-800',
  epuisee: 'bg-gray-100 text-gray-700',
  expiree: 'bg-amber-100 text-amber-800',
  annulee: 'bg-red-100 text-red-700',
};

/**
 * Cartes cadeaux (plan de parité fripv2, phase 1.10c) : cartes au porteur vendues, puis utilisées pour payer au point de
 * vente ou à l'encaissement d'une facture jusqu'à épuisement du solde ; recharge, remboursement du solde, certificat.
 */
export default function CartesCadeauxPage() {
  const { t } = useLanguage();
  const tg = t.giftCards;
  const { peut } = usePermissions();
  const montant = useMontantDocument();
  const { toasts, toast, dismiss } = useToast();
  const [recherche, setRecherche] = useState('');
  const [etat, setEtat] = useState<EtatCarte | ''>('');
  const [page, setPage] = useState(1);
  const [resultat, setResultat] = useState<{ cartes: CarteCadeau[]; derniere: number; total: number; aHonorer: number } | null>(null);
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);
  const [vente, setVente] = useState(false);
  const [ouverte, setOuverte] = useState<number | null>(null);

  useEffect(() => {
    let actif = true;
    const minuterie = setTimeout(() => {
      getCartesCadeaux({ recherche: recherche.trim(), etat, page })
        .then(r => { if (actif) { setResultat({ cartes: r.data ?? [], derniere: r.last_page ?? 1, total: r.total ?? 0, aHonorer: r.totaux.a_honorer }); setErreur(''); } },
          e => { if (actif) setErreur(messageErreur(e, tg.error)); });
    }, 250);
    return () => { actif = false; clearTimeout(minuterie); };
  }, [recherche, etat, page, version, tg.error]);

  const recharger = () => setVersion(v => v + 1);
  const certificat = (carte: CarteCadeau) => { imprimerCertificat(carte).catch(e => setErreur(messageErreur(e, tg.error))); };

  return (
    <div className="p-4 sm:p-6 space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Gift className="text-pink-600" /> {tg.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tg.subtitle}</p>
        </div>
        {peut('cartes_cadeaux', 'creer') && (
          <button type="button" onClick={() => setVente(true)} className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-pink-600 text-white hover:bg-pink-700"><Plus size={18} /> {tg.sell}</button>
        )}
      </div>
      {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      <div className="bg-white dark:bg-gray-800 rounded-xl shadow p-4 space-y-3">
        <div className="flex flex-wrap items-end gap-3">
          <div className="relative flex-1 min-w-[14rem]">
            <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
            <input value={recherche} onChange={e => { setRecherche(e.target.value); setPage(1); }} placeholder={tg.search} aria-label={tg.search} className={`${classeChamp} pl-9`} />
          </div>
          <label className={`${classeLibelle} w-44`}>{tg.state}
            <select value={etat} onChange={e => { setEtat(e.target.value as EtatCarte | ''); setPage(1); }} className={classeChamp}>
              <option value="">{tg.allStates}</option>
              {ETATS_CARTE.map(e => <option key={e} value={e}>{tg.states[e]}</option>)}
            </select>
          </label>
          {resultat && <p className="text-sm text-gray-600 dark:text-gray-300 ml-auto">{tg.outstanding} : <span className="font-semibold">{montant(resultat.aHonorer)}</span></p>}
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-xs uppercase text-gray-500 dark:text-gray-400 border-b dark:border-gray-700">
              <tr>
                <th className="py-2 pr-3">{tg.number}</th>
                <th className="py-2 pr-3">{tg.date}</th>
                <th className="py-2 pr-3">{tg.buyer}</th>
                <th className="py-2 pr-3 text-right">{tg.balance}</th>
                <th className="py-2 pr-3">{tg.expiry}</th>
                <th className="py-2 pr-3">{tg.state}</th>
                <th className="py-2 text-right">{tg.actions}</th>
              </tr>
            </thead>
            <tbody>
              {resultat && resultat.cartes.length === 0 && <tr><td colSpan={7} className="py-4 text-gray-500">{tg.none}</td></tr>}
              {resultat?.cartes.map(carte => (
                <tr key={carte.id} className="border-b dark:border-gray-700 dark:text-gray-200">
                  <td className="py-2 pr-3 font-mono">{carte.numero}</td>
                  <td className="py-2 pr-3">{formatDateDocument(carte.date_vente)}</td>
                  <td className="py-2 pr-3">{carte.client?.raison_sociale ?? '—'}</td>
                  <td className="py-2 pr-3 text-right whitespace-nowrap">{montant(carte.solde, carte.devise)}</td>
                  <td className="py-2 pr-3">{carte.expire_le ? formatDateDocument(carte.expire_le) : '—'}</td>
                  <td className="py-2 pr-3"><span className={`px-2 py-0.5 rounded-full text-xs ${COULEURS[carte.etat]}`}>{tg.states[carte.etat]}</span></td>
                  <td className="py-2 text-right whitespace-nowrap">
                    <button type="button" onClick={() => setOuverte(carte.id)} className="p-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700" title={tg.open} aria-label={`${tg.open} — ${carte.numero}`}><Eye size={16} /></button>
                    <button type="button" onClick={() => certificat(carte)} className="p-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700" title={tg.certificate} aria-label={`${tg.certificate} — ${carte.numero}`}><FileText size={16} /></button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {resultat && <Pagination page={page} dernierePage={resultat.derniere} total={resultat.total} onPage={setPage} />}
      </div>

      {vente && (
        <VendreCarte onClose={() => setVente(false)} onVendue={carte => {
          setVente(false);
          toast(tg.sold.replace('{numero}', carte.numero));
          recharger();
          setOuverte(carte.id);
        }} />
      )}
      {ouverte !== null && <FicheCarte id={ouverte} onClose={() => setOuverte(null)} onChange={recharger} toast={toast} />}
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}

/** Fiche d'une carte : solde, historique, recharge, remboursement du solde, client, expiration et notes, certificat. */
function FicheCarte({ id, onClose, onChange, toast }: { id: number; onClose: () => void; onChange: () => void; toast: (message: string) => void }) {
  const { t } = useLanguage();
  const tg = t.giftCards;
  const { peut } = usePermissions();
  const montant = useMontantDocument();
  const encaissement = useEncaissementCaisse();
  const [carte, setCarte] = useState<CarteCadeau | null>(null);
  const [version, setVersion] = useState(0);
  const [recharge, setRecharge] = useState({ montant: '', mode: (encaissement.caisse ? 'especes' : 'carte') as ModeCaisse, reference: '' });
  const [modeRemboursement, setModeRemboursement] = useState<ModeCaisse>('especes');
  const [expiration, setExpiration] = useState<string | null>(null);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    getCarteCadeau(id).then(c => { if (actif) setCarte(c); }, e => { if (actif) setErreur(messageErreur(e, tg.error)); });
    return () => { actif = false; };
  }, [id, version, tg.error]);

  async function agir(action: () => Promise<unknown>, message: string) {
    setOccupe(true);
    setErreur('');
    try {
      await action();
      toast(message);
      setVersion(v => v + 1);
      onChange();
    } catch (err) {
      setErreur(messageErreur(err, tg.error));
    } finally {
      setOccupe(false);
    }
  }

  if (!carte) {
    return (
      <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
        <div className="bg-white dark:bg-gray-800 rounded-xl p-6 text-sm dark:text-white">{erreur || tg.loading}</div>
      </div>
    );
  }
  const modifiable = !carte.annulee && peut('cartes_cadeaux', 'modifier');
  const expireLe = expiration ?? (carte.expire_le ? carte.expire_le.slice(0, 10) : '');

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div role="dialog" aria-modal="true" aria-label={tg.cardTitle.replace('{numero}', carte.numero)} className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-2xl max-h-[94vh] overflow-y-auto space-y-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 className="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2"><Gift className="text-pink-600" size={20} /> {tg.cardTitle.replace('{numero}', carte.numero)}</h2>
            <p className="text-sm text-gray-500">{tg.soldOn.replace('{date}', formatDateDocument(carte.date_vente))}{carte.client && ` — ${carte.client.raison_sociale}`}</p>
          </div>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 dark:text-white" aria-label={tg.close}><X size={18} /></button>
        </div>

        <div className="flex flex-wrap items-center gap-4">
          <div className="rounded-xl bg-pink-50 dark:bg-pink-900/20 px-4 py-3">
            <p className="text-xs text-pink-800 dark:text-pink-300">{tg.balance}</p>
            <p className="text-3xl font-bold text-pink-700 dark:text-pink-300">{montant(carte.solde, carte.devise)}</p>
          </div>
          <span className={`px-2 py-1 rounded-full text-xs ${COULEURS[carte.etat]}`}>{tg.states[carte.etat]}</span>
          <button type="button" onClick={() => { imprimerCertificat(carte).catch(e => setErreur(messageErreur(e, tg.error))); }}
            className="ml-auto inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700"><FileText size={16} /> {tg.certificate}</button>
        </div>
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

        {modifiable && (
          <div className="grid gap-3 sm:grid-cols-2">
            <form className="rounded-lg border dark:border-gray-700 p-3 space-y-2" aria-label={tg.reload}
              onSubmit={e => { e.preventDefault(); void agir(() => encaissement.encaisser(caisse_id => rechargerCarteCadeau(carte.id, { montant: Number(recharge.montant), mode: recharge.mode, reference: recharge.reference.trim() || undefined, caisse_id })), tg.reloaded); }}>
              <h3 className="text-sm font-semibold dark:text-white">{tg.reload}</h3>
              <CaisseDuPoste caisse={encaissement.caisse} />
              <div className="grid grid-cols-2 gap-2">
                <label className={classeLibelle}>{tg.amount}
                  <input type="number" min="0.01" step="0.01" required value={recharge.montant} onChange={e => setRecharge({ ...recharge, montant: e.target.value })} className={classeChamp} />
                </label>
                <label className={classeLibelle}>{tg.paidBy}
                  <select value={recharge.mode} onChange={e => setRecharge({ ...recharge, mode: e.target.value as ModeCaisse })} className={classeChamp}>
                    {MODES_CAISSE.map(m => <option key={m} value={m}>{t.cashRegisters.modes[m]}</option>)}
                  </select>
                </label>
              </div>
              <button type="submit" disabled={occupe} className="px-3 py-1.5 rounded-lg bg-pink-600 text-white text-sm hover:bg-pink-700 disabled:opacity-50">{tg.reload}</button>
            </form>
            {peut('cartes_cadeaux', 'rembourser') && (
              <div className="rounded-lg border dark:border-gray-700 p-3 space-y-2">
                <h3 className="text-sm font-semibold dark:text-white">{tg.refund}</h3>
                <p className="text-xs text-gray-500">{tg.refundHelp.replace('{montant}', montant(carte.solde, carte.devise))}</p>
                <label className={classeLibelle}>{tg.refundBy}
                  <select value={modeRemboursement} onChange={e => setModeRemboursement(e.target.value as ModeCaisse)} className={classeChamp}>
                    {MODES_CAISSE.map(m => <option key={m} value={m}>{t.cashRegisters.modes[m]}</option>)}
                  </select>
                </label>
                <button type="button" disabled={occupe} onClick={() => {
                  if (!confirm(tg.confirmRefund.replace('{numero}', carte.numero))) return;
                  void agir(() => encaissement.encaisser(caisse_id => rembourserCarteCadeau(carte.id, { mode: modeRemboursement, caisse_id })), tg.refunded);
                }} className="px-3 py-1.5 rounded-lg bg-red-600 text-white text-sm hover:bg-red-700 disabled:opacity-50">{tg.refund}</button>
              </div>
            )}
            <form className="rounded-lg border dark:border-gray-700 p-3 space-y-2 sm:col-span-2 flex flex-wrap items-end gap-3" aria-label={tg.expiry}
              onSubmit={e => { e.preventDefault(); void agir(() => modifierCarteCadeau(carte.id, { expire_le: expireLe || null }), tg.saved); }}>
              <label className={`${classeLibelle} w-48`}>{tg.expiry}
                <input type="date" value={expireLe} min={carte.date_vente.slice(0, 10)} onChange={e => setExpiration(e.target.value)} className={classeChamp} />
              </label>
              <button type="submit" disabled={occupe} className="px-3 py-1.5 rounded-lg border text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700 disabled:opacity-50">{tg.save}</button>
            </form>
          </div>
        )}

        <div>
          <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-1">{tg.history}</h3>
          <table className="w-full text-sm">
            <thead className="text-left text-xs uppercase text-gray-500 dark:text-gray-400 border-b dark:border-gray-700">
              <tr>
                <th className="py-2 pr-3">{tg.date}</th>
                <th className="py-2 pr-3">{tg.movement}</th>
                <th className="py-2 pr-3">{tg.detail}</th>
                <th className="py-2 pr-3 text-right">{tg.amount}</th>
                <th className="py-2 text-right">{tg.balanceAfter}</th>
              </tr>
            </thead>
            <tbody>
              {(carte.mouvements ?? []).map(m => (
                <tr key={m.id} className="border-b dark:border-gray-700 dark:text-gray-200">
                  <td className="py-1.5 pr-3 whitespace-nowrap">{formatDateHeure(m.created_at)}</td>
                  <td className="py-1.5 pr-3">{tg.movements[m.type]}</td>
                  <td className="py-1.5 pr-3">{m.facture?.numero ?? (m.mode ? t.cashRegisters.modes[m.mode] : '')}{m.reference && ` ${m.reference}`}{m.auteur && <span className="text-xs text-gray-500"> — {m.auteur.nom}</span>}</td>
                  <td className={`py-1.5 pr-3 text-right whitespace-nowrap ${Number(m.montant) < 0 ? 'text-red-600' : ''}`}>{montant(m.montant, carte.devise)}</td>
                  <td className="py-1.5 text-right whitespace-nowrap">{montant(m.solde_apres, carte.devise)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
      {encaissement.dialogue}
    </div>
  );
}
