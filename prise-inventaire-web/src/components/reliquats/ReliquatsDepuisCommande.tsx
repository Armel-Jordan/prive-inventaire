import { useEffect, useState } from 'react';
import { X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { quantiteAvecUnite } from '@/lib/unitesVente';
import { getLignesCommandeReliquat, reliquatsDepuisCommande, type LignesCommandeReliquat } from '@/services/reliquatsClients';

const classeChamp = 'px-2 py-1.5 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/**
 * Reliquats d'une commande client acceptée : pour chaque ligne, la quantité qui manque (au plus la quantité commandée,
 * reliquats déjà notés déduits). Prix, remise, devise et date souhaitée viennent de la commande.
 */
export default function ReliquatsDepuisCommande({ commandeId, proposees, onClose, onEnregistre }: {
  commandeId: number;
  /** Quantités proposées par produit (manques signalés à l'acceptation de la commande). */
  proposees?: Record<number, number>;
  onClose: () => void;
  onEnregistre: (nombre: number) => void;
}) {
  const { t } = useLanguage();
  const tb = t.backOrders;
  const [donnees, setDonnees] = useState<LignesCommandeReliquat | null>(null);
  const [quantites, setQuantites] = useState<Record<number, string>>({});
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    getLignesCommandeReliquat(commandeId).then(d => {
      if (!actif) return;
      setDonnees(d);
      // Manques proposés (en unités de stock) : sur la première ligne de chaque produit, dans l'unité de la ligne
      // (une caisse de 12 couvre 12 unités), au plus ce qui peut encore être noté.
      if (proposees) {
        const vus = new Set<number>();
        setQuantites(Object.fromEntries(d.lignes.flatMap(l => {
          const produit = l.produit?.id;
          if (!produit || vus.has(produit) || !proposees[produit]) return [];
          vus.add(produit);
          const dansLUnite = Math.ceil(proposees[produit] / (Number(l.facteur) || 1) - 1e-9);
          return [[l.ligne_id, String(Math.min(dansLUnite, l.quantite - l.deja_en_reliquat))]];
        })));
      }
    }, e => { if (actif) setErreur(messageErreur(e, tb.error)); });
    return () => { actif = false; };
  }, [commandeId, proposees, tb.error]);

  const saisies = (donnees?.lignes ?? []).map(l => ({ ligne: l, quantite: Math.max(0, Math.floor(Number(quantites[l.ligne_id] ?? 0) || 0)) }));
  const retenues = saisies.filter(s => s.quantite > 0);
  const depasse = saisies.some(s => s.quantite > s.ligne.quantite - s.ligne.deja_en_reliquat);

  async function enregistrer() {
    setOccupe(true);
    setErreur('');
    try {
      const crees = await reliquatsDepuisCommande(commandeId, retenues.map(s => ({ ligne_id: s.ligne.ligne_id, quantite: s.quantite })));
      onEnregistre(crees.length);
    } catch (e) {
      setErreur(messageErreur(e, tb.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="reliquats-commande-titre" className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-2xl max-h-[94vh] overflow-y-auto space-y-3">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 id="reliquats-commande-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tb.fromOrderTitle.replace('{numero}', donnees?.commande.numero ?? '…')}</h2>
            {donnees?.commande.client && <p className="text-sm text-gray-500 dark:text-gray-400">{donnees.commande.client.raison_sociale}</p>}
          </div>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tb.close}><X size={18} /></button>
        </div>
        {donnees === null && !erreur && <p className="text-sm text-gray-500">{tb.loading}</p>}
        {donnees && !donnees.possible && <p className="px-3 py-2 rounded-lg text-sm bg-amber-50 text-amber-800">{tb.orderNotAccepted}</p>}
        {donnees?.possible && (
          <>
            <p className="text-sm text-gray-500 dark:text-gray-400">{tb.fromOrderHelp}</p>
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300 border-b dark:border-gray-700">
                  <th className="py-2 pr-3 font-medium">{tb.product}</th>
                  <th className="py-2 pr-3 font-medium text-right">{tb.orderedQty}</th>
                  <th className="py-2 pr-3 font-medium text-right">{tb.alreadyBackOrdered}</th>
                  <th className="py-2 font-medium text-right">{tb.missing}</th>
                </tr>
              </thead>
              <tbody>
                {saisies.map(({ ligne, quantite }) => {
                  const reste = ligne.quantite - ligne.deja_en_reliquat;
                  const nom = ligne.produit?.description ?? `#${ligne.ligne_id}`;
                  return (
                    <tr key={ligne.ligne_id} className="border-b dark:border-gray-700">
                      <td className="py-2 pr-3 text-gray-900 dark:text-white">{nom}</td>
                      <td className="py-2 pr-3 text-right text-gray-700 dark:text-gray-300">{quantiteAvecUnite(ligne.quantite, ligne.unite)}</td>
                      <td className="py-2 pr-3 text-right text-gray-700 dark:text-gray-300">{ligne.deja_en_reliquat}</td>
                      <td className="py-2 text-right">
                        <input type="number" min={0} max={reste} step={1} value={quantites[ligne.ligne_id] ?? '0'} disabled={reste <= 0}
                          aria-label={`${tb.missing} — ${nom}`} onChange={e => setQuantites(q => ({ ...q, [ligne.ligne_id]: e.target.value }))}
                          className={`${classeChamp} w-20 text-right ${quantite > reste ? 'border-red-500 text-red-700' : ''}`} />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </>
        )}
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tb.cancel}</button>
          <button type="button" disabled={occupe || !donnees?.possible || retenues.length === 0 || depasse} onClick={() => { void enregistrer(); }}
            className="px-4 py-2 bg-sky-600 text-white rounded-lg text-sm hover:bg-sky-700 disabled:opacity-50">{tb.saveBackOrders}</button>
        </div>
      </div>
    </div>
  );
}
