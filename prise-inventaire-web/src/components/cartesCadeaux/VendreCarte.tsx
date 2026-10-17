import { useEffect, useState } from 'react';
import { Gift, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, useCodeDevise } from '@/lib/documents';
import { getClientsActifs, getConfiguration, getReferentiel, type Client, type FicheReferentiel } from '@/services/api';
import { MODES_CAISSE, type ModeCaisse } from '@/services/caisses';
import { vendreCarteCadeau, type CarteCadeau } from '@/services/cartesCadeaux';
import { useEncaissementCaisse } from '@/hooks/useEncaissementCaisse';
import CaisseDuPoste from '@/components/caisse/CaisseDuPoste';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeLibelle = 'block text-xs text-gray-500 dark:text-gray-400';

/**
 * Vente d'une carte cadeau : montant encaissé par son mode (sur la caisse du poste s'il en a une), client acheteur et
 * expiration facultatifs, numéro saisi si les cartes sont pré-imprimées (numérotation manuelle).
 */
export default function VendreCarte({ onClose, onVendue }: { onClose: () => void; onVendue: (carte: CarteCadeau) => void }) {
  const { t } = useLanguage();
  const tg = t.giftCards;
  const deviseEntreprise = useCodeDevise();
  const encaissement = useEncaissementCaisse();
  const [clients, setClients] = useState<Client[]>([]);
  const [devises, setDevises] = useState<FicheReferentiel[]>([]);
  const [manuel, setManuel] = useState(false);
  const [saisie, setSaisie] = useState({
    numero: '', montant: '', mode: (encaissement.caisse ? 'especes' : 'carte') as ModeCaisse, reference: '', client_id: '', devise: '',
    expire: false, expire_le: '', notes: '',
  });
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    getClientsActifs().then(c => { if (actif) setClients((c ?? []).filter(x => !x.de_passage)); }, () => {});
    getReferentiel('devises', true).then(d => { if (actif) setDevises(d ?? []); }, () => {});
    // Numérotation manuelle : le numéro de la carte pré-imprimée est saisi.
    getConfiguration('carte_cadeau').then(c => { if (actif) setManuel(!c.auto_increment); }, () => {});
    return () => { actif = false; };
  }, []);

  async function vendre(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    try {
      const carte = await encaissement.encaisser(caisse_id => vendreCarteCadeau({
        numero: manuel ? saisie.numero.trim() : undefined,
        client_id: saisie.client_id ? Number(saisie.client_id) : undefined,
        devise: saisie.devise || undefined,
        montant: Number(saisie.montant),
        mode: saisie.mode,
        reference: saisie.reference.trim() || undefined,
        date: aujourdhui(),
        expire_le: saisie.expire && saisie.expire_le ? saisie.expire_le : undefined,
        notes: saisie.notes.trim() || undefined,
        caisse_id,
      }));
      onVendue(carte);
    } catch (err) {
      setErreur(messageErreur(err, tg.error));
      setOccupe(false);
    }
  }

  const autresDevises = devises.filter(d => String(d.code ?? '').toUpperCase() !== deviseEntreprise.toUpperCase());

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <form onSubmit={vendre} role="dialog" aria-modal="true" aria-label={tg.sellTitle} className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg max-h-[94vh] overflow-y-auto space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 className="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2"><Gift className="text-pink-600" size={20} /> {tg.sellTitle}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 dark:text-white" aria-label={tg.close}><X size={18} /></button>
        </div>
        <CaisseDuPoste caisse={encaissement.caisse} />
        {manuel && (
          <label className={classeLibelle}>{tg.number}
            <input value={saisie.numero} required maxLength={30} pattern="[A-Za-z0-9-]+" autoFocus onChange={e => setSaisie({ ...saisie, numero: e.target.value })} className={`${classeChamp} font-mono uppercase`} />
            <span className="block mt-0.5">{tg.numberHelp}</span>
          </label>
        )}
        <div className="grid grid-cols-2 gap-3">
          <label className={classeLibelle}>{tg.amount}
            <input type="number" min="0.01" step="0.01" required value={saisie.montant} autoFocus={!manuel} onChange={e => setSaisie({ ...saisie, montant: e.target.value })} className={classeChamp} />
          </label>
          <label className={classeLibelle}>{tg.paidBy}
            <select value={saisie.mode} onChange={e => setSaisie({ ...saisie, mode: e.target.value as ModeCaisse })} className={classeChamp}>
              {MODES_CAISSE.map(m => <option key={m} value={m}>{t.cashRegisters.modes[m]}</option>)}
            </select>
          </label>
          {autresDevises.length > 0 && (
            <label className={classeLibelle}>{tg.currency}
              <select value={saisie.devise} onChange={e => setSaisie({ ...saisie, devise: e.target.value })} className={classeChamp}>
                <option value="">{deviseEntreprise || tg.companyCurrency}</option>
                {autresDevises.map(d => <option key={d.id} value={String(d.code)}>{String(d.code)}</option>)}
              </select>
            </label>
          )}
          <label className={classeLibelle}>{tg.reference}
            <input value={saisie.reference} maxLength={100} onChange={e => setSaisie({ ...saisie, reference: e.target.value })} className={classeChamp} />
          </label>
        </div>
        <label className={classeLibelle}>{tg.buyer}
          <select value={saisie.client_id} onChange={e => setSaisie({ ...saisie, client_id: e.target.value })} className={classeChamp}>
            <option value="">{tg.noBuyer}</option>
            {clients.map(c => <option key={c.id} value={c.id}>{c.code} - {c.raison_sociale}</option>)}
          </select>
          <span className="block mt-0.5">{tg.bearerHelp}</span>
        </label>
        <div className="flex flex-wrap items-end gap-3">
          <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" checked={saisie.expire} onChange={e => setSaisie({ ...saisie, expire: e.target.checked })} /> {tg.expires}
          </label>
          {saisie.expire && (
            <input type="date" required min={aujourdhui()} value={saisie.expire_le} aria-label={tg.expiry} onChange={e => setSaisie({ ...saisie, expire_le: e.target.value })} className={`${classeChamp} max-w-[12rem]`} />
          )}
        </div>
        <label className={classeLibelle}>{tg.notes}
          <textarea rows={2} maxLength={1000} value={saisie.notes} onChange={e => setSaisie({ ...saisie, notes: e.target.value })} className={classeChamp} />
        </label>
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tg.cancel}</button>
          <button type="submit" disabled={occupe} className="px-4 py-2 bg-pink-600 text-white rounded-lg text-sm hover:bg-pink-700 disabled:opacity-50">{tg.sell}</button>
        </div>
      </form>
      {encaissement.dialogue}
    </div>
  );
}
