import type { Dispatch, SetStateAction } from 'react';
import { Plus, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { tauxPropose, useMontantDocument } from '@/lib/documents';
import { etrangere, nouveauFrais, totalFrais, type FraisSaisi } from '@/lib/coutsAnnexes';
import type { RepartitionFrais, TypeFrais } from '@/services/receptions';

const TYPES: TypeFrais[] = ['transport', 'douane', 'assurance', 'manutention', 'autre'];
const REPARTITIONS: RepartitionFrais[] = ['valeur', 'quantite', 'poids', 'volume'];
const classeChamp = 'w-full px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/**
 * Coûts annexes d'une réception (transport, douane, assurance…) : montant, devise (celle de l'entreprise ou d'une
 * commande reçue, avec son taux), base de répartition sur les lignes, facturé par qui et sa référence. Le total
 * s'affiche dans la devise de l'entreprise.
 */
export default function CoutsAnnexes({ frais, onChange, devises, tauxParDevise, fournisseurs, codeEntreprise }: {
  frais: FraisSaisi[];
  onChange: Dispatch<SetStateAction<FraisSaisi[]>>;
  /** Devises étrangères proposées (celles des commandes reçues), après celle de l'entreprise. */
  devises: string[];
  /** Taux des commandes reçues, proposés pour leur devise. */
  tauxParDevise: Record<string, number>;
  fournisseurs: { id: number; raison_sociale: string }[];
  codeEntreprise: string;
}) {
  const { t } = useLanguage();
  const tr = t.receipts;
  const montant = useMontantDocument();

  const changer = (cle: string, champs: Partial<FraisSaisi>) => onChange(fs => fs.map(f => (f.cle === cle ? { ...f, ...champs } : f)));

  // Devise étrangère : le taux de sa commande, sinon celui du référentiel ou le cours du jour (modifiable).
  async function changerDevise(cle: string, devise: string) {
    const connu = tauxParDevise[devise];
    changer(cle, { devise, taux: devise === '' ? '1' : connu ? String(connu) : '' });
    if (devise !== '' && !connu) {
      const propose = await tauxPropose(devise, codeEntreprise);
      if (propose) onChange(fs => fs.map(f => (f.cle === cle && f.devise === devise && f.taux === '' ? { ...f, taux: propose } : f)));
    }
  }

  return (
    <fieldset className="border dark:border-gray-700 rounded-lg p-3 space-y-2">
      <legend className="px-1 text-sm font-medium text-gray-700 dark:text-gray-300">{tr.extraCosts}</legend>
      <p className="text-xs text-gray-500">{tr.extraCostsHelp}</p>
      {frais.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-sm">
            <thead className="text-xs text-gray-600 dark:text-gray-300">
              <tr>
                <th className="px-1 py-1 text-left w-32">{tr.costType}</th>
                <th className="px-1 py-1 text-left">{tr.label}</th>
                <th className="px-1 py-1 text-left w-28">{tr.amount}</th>
                <th className="px-1 py-1 text-left w-36">{tr.currency}</th>
                <th className="px-1 py-1 text-left w-32">{tr.allocation}</th>
                <th className="px-1 py-1 text-left w-44">{tr.billedBy}</th>
                <th className="px-1 py-1 text-left w-28">{tr.invoiceRef}</th>
                <th className="w-8" />
              </tr>
            </thead>
            <tbody>
              {frais.map(f => (
                <tr key={f.cle}>
                  <td className="px-1 py-1">
                    <select value={f.type} aria-label={tr.costType} onChange={e => changer(f.cle, { type: e.target.value as TypeFrais })} className={classeChamp}>
                      {TYPES.map(type => <option key={type} value={type}>{tr.costTypes[type]}</option>)}
                    </select>
                  </td>
                  <td className="px-1 py-1">
                    <input value={f.libelle} maxLength={120} aria-label={tr.label} onChange={e => changer(f.cle, { libelle: e.target.value })} className={classeChamp} />
                  </td>
                  <td className="px-1 py-1">
                    <input type="number" min={0} step="0.01" value={f.montant} aria-label={tr.costAmount} onChange={e => changer(f.cle, { montant: e.target.value })} className={classeChamp} />
                  </td>
                  <td className="px-1 py-1">
                    <div className="flex items-center gap-1">
                      <select value={f.devise} aria-label={tr.currency} onChange={e => changerDevise(f.cle, e.target.value)} className={classeChamp}>
                        <option value="">{codeEntreprise || '—'}</option>
                        {devises.filter(d => d !== codeEntreprise).map(d => <option key={d} value={d}>{d}</option>)}
                      </select>
                      {etrangere(f, codeEntreprise) && (
                        <input type="number" min={0} step="any" value={f.taux} placeholder={tr.rate} title={`1 ${f.devise} = ? ${codeEntreprise}`}
                          aria-label={`${tr.rate} ${f.devise}`} onChange={e => changer(f.cle, { taux: e.target.value })} className={`${classeChamp} w-20`} />
                      )}
                    </div>
                  </td>
                  <td className="px-1 py-1">
                    <select value={f.repartition} aria-label={tr.allocation} onChange={e => changer(f.cle, { repartition: e.target.value as RepartitionFrais })} className={classeChamp}>
                      {REPARTITIONS.map(r => <option key={r} value={r}>{tr.allocations[r]}</option>)}
                    </select>
                  </td>
                  <td className="px-1 py-1">
                    <select value={f.fournisseur_id ?? ''} aria-label={tr.billedBy} onChange={e => changer(f.cle, { fournisseur_id: e.target.value ? Number(e.target.value) : null })} className={classeChamp}>
                      <option value="">—</option>
                      {fournisseurs.map(fo => <option key={fo.id} value={fo.id}>{fo.raison_sociale}</option>)}
                    </select>
                  </td>
                  <td className="px-1 py-1">
                    <input value={f.reference} maxLength={60} aria-label={tr.invoiceRef} onChange={e => changer(f.cle, { reference: e.target.value })} className={classeChamp} />
                  </td>
                  <td className="px-1 py-1 text-center">
                    <button type="button" onClick={() => onChange(fs => fs.filter(x => x.cle !== f.cle))} title={tr.removeCost} aria-label={tr.removeCost}
                      className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><X size={16} /></button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <button type="button" onClick={() => onChange(fs => [...fs, nouveauFrais()])}
          className="flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg border border-blue-600 text-blue-700 hover:bg-blue-50 dark:text-blue-300 dark:hover:bg-blue-900/30">
          <Plus size={14} /> {tr.addExtraCost}
        </button>
        {frais.length > 0 && <span className="text-sm text-gray-600 dark:text-gray-300">{tr.extraCostsTotal} : <strong>{montant(totalFrais(frais, codeEntreprise))}</strong></span>}
      </div>
    </fieldset>
  );
}
