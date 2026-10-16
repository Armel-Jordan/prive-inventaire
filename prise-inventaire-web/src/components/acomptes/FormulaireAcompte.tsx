import { useEffect, useState } from 'react';
import { X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, dateSaisie } from '@/lib/documents';
import { getClientsActifs, getCommandesClient, type Client, type ComClientEntete } from '@/services/api';
import { creerAcompte, MODES_ACOMPTE, modifierAcompte, type Acompte, type ModeAcompte } from '@/services/acomptes';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm disabled:opacity-60';
/** Commandes auxquelles réserver un acompte : celles qui ne sont ni refusées, ni annulées, ni déjà facturées. */
const COMMANDES_OUVERTES = ['brouillon', 'en_attente', 'acceptee'];

/**
 * Saisie d'un acompte reçu (ou modification) : client, date, mode, référence, montant, réservation éventuelle à une
 * commande du client et notes. Ouvert depuis l'écran Acomptes ou depuis une commande (client et commande fixés).
 */
export default function FormulaireAcompte({ acompte, clientId, commandeId, onClose, onEnregistre }: {
  acompte?: Acompte | null;
  clientId?: number;
  commandeId?: number;
  onClose: () => void;
  onEnregistre: (acompte: Acompte) => void;
}) {
  const { t } = useLanguage();
  const td = t.deposits;
  const [clients, setClients] = useState<Client[]>([]);
  const [commandes, setCommandes] = useState<ComClientEntete[]>([]);
  const [saisie, setSaisie] = useState({
    client_id: String(acompte?.client_id ?? clientId ?? ''),
    date_acompte: acompte ? dateSaisie(acompte.date_acompte) : aujourdhui(),
    mode: (acompte?.mode ?? 'virement') as ModeAcompte,
    reference: acompte?.reference ?? '',
    montant: acompte ? String(Number(acompte.montant)) : '',
    commande_id: String(acompte?.commande_id ?? commandeId ?? ''),
    notes: acompte?.notes ?? '',
  });
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);
  // Déjà appliqué ou remboursé : seuls la référence, la réservation et les notes changent encore.
  const verrouille = !!acompte && (Number(acompte.montant_applique) > 0 || Number(acompte.montant_rembourse) > 0);
  const clientFixe = !!acompte || clientId !== undefined;
  // Commande déjà réservée (même facturée depuis) : toujours proposée.
  const commandeInitiale = acompte?.commande_id ?? commandeId ?? null;

  useEffect(() => {
    if (clientFixe) return;
    let actif = true;
    getClientsActifs().then(c => { if (actif) setClients(c ?? []); }, () => {});
    return () => { actif = false; };
  }, [clientFixe]);

  useEffect(() => {
    if (!saisie.client_id) return;
    let actif = true;
    getCommandesClient({ client_id: Number(saisie.client_id) }).then(r => {
      if (actif) setCommandes((r.data ?? []).filter(c => COMMANDES_OUVERTES.includes(c.statut) || c.id === commandeInitiale));
    }, () => {});
    return () => { actif = false; };
  }, [saisie.client_id, commandeInitiale]);

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    const donnees = {
      date_acompte: saisie.date_acompte,
      mode: saisie.mode,
      reference: saisie.reference.trim() || null,
      montant: Number(saisie.montant),
      commande_id: saisie.commande_id ? Number(saisie.commande_id) : null,
      notes: saisie.notes.trim() || null,
    };
    try {
      const resultat = acompte
        ? await modifierAcompte(acompte.id, verrouille ? { reference: donnees.reference, commande_id: donnees.commande_id, notes: donnees.notes } : donnees)
        : await creerAcompte({ client_id: Number(saisie.client_id), ...donnees });
      onEnregistre(resultat);
    } catch (err) {
      setErreur(messageErreur(err, td.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <form onSubmit={enregistrer} role="dialog" aria-modal="true" aria-labelledby="acompte-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg max-h-[94vh] overflow-y-auto space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="acompte-titre" className="text-lg font-bold text-gray-900 dark:text-white">{acompte ? td.editTitle : td.newDeposit}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={td.close}><X size={18} /></button>
        </div>
        {verrouille && <p className="px-3 py-2 rounded-lg text-xs bg-amber-50 text-amber-800">{td.usedLocked}</p>}

        {!clientFixe && (
          <label className="block text-xs text-gray-500 dark:text-gray-400">{td.customer}
            <select required value={saisie.client_id} onChange={e => setSaisie({ ...saisie, client_id: e.target.value, commande_id: '' })} className={classeChamp}>
              <option value="">{td.chooseCustomer}</option>
              {clients.map(c => <option key={c.id} value={c.id}>{c.code} · {c.raison_sociale}</option>)}
            </select>
          </label>
        )}
        <div className="grid grid-cols-2 gap-3">
          <label className="block text-xs text-gray-500 dark:text-gray-400">{td.date}
            <input type="date" required value={saisie.date_acompte} disabled={verrouille} onChange={e => setSaisie({ ...saisie, date_acompte: e.target.value })} className={classeChamp} />
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{td.amount}
            <input type="number" required min="0.01" step="0.01" value={saisie.montant} disabled={verrouille} onChange={e => setSaisie({ ...saisie, montant: e.target.value })} className={classeChamp} />
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{td.mode}
            <select value={saisie.mode} disabled={verrouille} onChange={e => setSaisie({ ...saisie, mode: e.target.value as ModeAcompte })} className={classeChamp}>
              {MODES_ACOMPTE.map(m => <option key={m} value={m}>{td.modes[m]}</option>)}
            </select>
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{td.reference}
            <input value={saisie.reference} maxLength={100} placeholder={td.referenceHelp} onChange={e => setSaisie({ ...saisie, reference: e.target.value })} className={classeChamp} />
          </label>
        </div>
        {saisie.client_id && (
          <label className="block text-xs text-gray-500 dark:text-gray-400">{td.reservedFor}
            <select value={saisie.commande_id} disabled={commandeId !== undefined} onChange={e => setSaisie({ ...saisie, commande_id: e.target.value })} className={classeChamp}>
              <option value="">{td.noOrder}</option>
              {commandes.map(c => <option key={c.id} value={c.id}>{c.numero}</option>)}
            </select>
            <span className="block mt-0.5">{td.orderHelp}</span>
          </label>
        )}
        <label className="block text-xs text-gray-500 dark:text-gray-400">{td.notes}
          <textarea rows={2} maxLength={2000} value={saisie.notes} onChange={e => setSaisie({ ...saisie, notes: e.target.value })} className={classeChamp} />
        </label>

        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{td.cancel}</button>
          <button type="submit" disabled={occupe} className="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700 disabled:opacity-50">{td.save}</button>
        </div>
      </form>
    </div>
  );
}
