import { useState } from 'react';
import { Truck } from 'lucide-react';
import ChoixTransporteur from '@/components/ChoixTransporteur';
import { lienSuivi, transporteursActifs, useTransporteurs } from '@/hooks/useTransporteurs';
import { useLanguage } from '@/i18n/useLanguage';
import { updateExpeditionBonLivraison, type BonLivraison } from '@/services/api';
import { signaler } from '@/lib/erreurs';

/** Statuts où l'expédition ne se modifie plus. */
const TERMINES = ['livre_complet', 'livre_partiel', 'annule'];

interface Props {
  bon: BonLivraison;
  /** Droit de modifier les bons de livraison. */
  modifiable: boolean;
  onChange: (bon: BonLivraison) => void;
}

/**
 * Expédition d'un bon de livraison (phase 1.11b) : transporteur et numéro de suivi, repris de la facture, avec le lien
 * de suivi du transporteur ; modifiables tant que le bon n'est ni livré ni annulé. Rien pour un retrait par le client.
 */
export default function ExpeditionBon({ bon, modifiable, onChange }: Props) {
  const { t } = useLanguage();
  const tr = t.carriers;
  const transporteurs = useTransporteurs();
  const [edition, setEdition] = useState(false);
  const [transporteurId, setTransporteurId] = useState<number | null>(bon.transporteur_id ?? null);
  const [numero, setNumero] = useState(bon.numero_suivi ?? '');
  const [enCours, setEnCours] = useState(false);

  if (bon.mode_livraison === 'retrait_client') return null;
  const peutModifier = modifiable && !TERMINES.includes(bon.statut) && (transporteursActifs(transporteurs) || bon.transporteur_id != null);
  if (!bon.transporteur && !bon.numero_suivi && !peutModifier) return null;

  const lien = lienSuivi(bon.transporteur?.url_suivi, bon.numero_suivi);

  async function enregistrer() {
    setEnCours(true);
    try {
      const misAJour = await updateExpeditionBonLivraison(bon.id, { transporteur_id: transporteurId, numero_suivi: numero.trim() || null });
      onChange({ ...bon, transporteur_id: misAJour.transporteur_id, transporteur: misAJour.transporteur, numero_suivi: misAJour.numero_suivi });
      setEdition(false);
    } catch (erreur) {
      signaler(erreur, tr.editShipment);
    } finally {
      setEnCours(false);
    }
  }

  return (
    <div className="border-t pt-4 dark:border-gray-700">
      <h3 className="font-medium mb-2 flex items-center gap-2 dark:text-white"><Truck size={16} /> {tr.shipment}</h3>
      {edition ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label htmlFor={`bl-${bon.id}-transporteur`} className="block text-sm text-gray-500 mb-1">{tr.label}</label>
            <ChoixTransporteur id={`bl-${bon.id}-transporteur`} value={transporteurId} onChange={setTransporteurId} transporteurs={transporteurs} />
          </div>
          <div>
            <label htmlFor={`bl-${bon.id}-suivi`} className="block text-sm text-gray-500 mb-1">{tr.tracking}</label>
            <input id={`bl-${bon.id}-suivi`} type="text" maxLength={60} value={numero} onChange={e => setNumero(e.target.value)}
              className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
          </div>
          <div className="sm:col-span-2 flex justify-end gap-2">
            <button type="button" onClick={() => setEdition(false)} className="px-3 py-1.5 text-sm border rounded-lg dark:border-gray-600 dark:text-white">{t.common.cancel}</button>
            <button type="button" onClick={enregistrer} disabled={enCours} className="px-3 py-1.5 text-sm bg-blue-600 text-white rounded-lg disabled:opacity-50">{t.common.save}</button>
          </div>
        </div>
      ) : (
        <div className="flex flex-wrap items-center gap-3 text-sm dark:text-gray-300">
          <span>{bon.transporteur ? tr.shippedBy.replace('{transporteur}', bon.transporteur.nom) : tr.none}</span>
          {bon.numero_suivi && <span className="text-gray-500">{tr.tracking} : {bon.numero_suivi}</span>}
          {lien && <a href={lien} target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline">{tr.track}</a>}
          {peutModifier && (
            <button type="button" onClick={() => { setTransporteurId(bon.transporteur_id ?? null); setNumero(bon.numero_suivi ?? ''); setEdition(true); }}
              className="ml-auto text-blue-600 hover:underline">{tr.editShipment}</button>
          )}
        </div>
      )}
    </div>
  );
}
