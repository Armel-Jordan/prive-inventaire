import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument } from '@/lib/documents';
import { numerosSaisis } from '@/lib/lots';
import type { ExemplairesProduit } from '@/services/lots';

const classeChamp = 'px-2 py-1.5 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/** Zone de numéros de série, avec le compte attendu. */
function Numeros({ titre, valeur, onChange, attendus }: { titre: string; valeur: string; onChange: (texte: string) => void; attendus: number }) {
  const saisis = numerosSaisis(valeur).length;
  return (
    <label className="block text-xs text-gray-600 dark:text-gray-300 flex-1 min-w-[14rem]">
      {titre}
      <span className={`ml-2 font-medium ${saisis === attendus ? 'text-green-700' : 'text-amber-700'}`}>{saisis} / {attendus}</span>
      <textarea value={valeur} rows={Math.min(4, Math.max(2, attendus))} onChange={e => onChange(e.target.value)} className={`${classeChamp} w-full font-mono mt-1`} />
    </label>
  );
}

/**
 * Exemplaires d'une ligne de retour (produit suivi) : pour un lot, le lot qui revient ou repart (vide : choisi par
 * l'application, le dernier livré au client ou le premier à périmer) ; pour un numéro de série, les numéros concernés
 * (remis en stock et mis au rebut pour un retour client, renvoyés pour un retour fournisseur).
 */
export default function ExemplairesRetour({ exemplaire, client, lotId, onLot, series, onSeries, attendus, seriesRebut, onSeriesRebut, attendusRebut }: {
  exemplaire: ExemplairesProduit;
  /** Retour client (vrai) ou fournisseur. */
  client: boolean;
  lotId: string;
  onLot: (lot: string) => void;
  series: string;
  onSeries: (texte: string) => void;
  attendus: number;
  seriesRebut?: string;
  onSeriesRebut?: (texte: string) => void;
  attendusRebut?: number;
}) {
  const { t, language } = useLanguage();
  const tl = t.lots;
  const nombre = (v: number) => v.toLocaleString(language === 'en' ? 'en-US' : 'fr-FR', { maximumFractionDigits: 4 });

  if (exemplaire.suivi === 'lot') {
    if (attendus <= 0) return null;
    return (
      <label className="flex flex-wrap items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
        {tl.returnLot}
        <select value={lotId} onChange={e => onLot(e.target.value)} className={classeChamp}>
          <option value="">{client ? tl.autoClient : tl.autoSupplier}</option>
          {exemplaire.lots.map(lot => (
            <option key={lot.id} value={String(lot.id)}>
              {lot.numero}{lot.date_peremption ? ` · ${formatDateDocument(lot.date_peremption, language)}` : ''}
              {lot.recu_par_client != null ? ` · ${tl.receivedByCustomer.replace('{n}', nombre(lot.recu_par_client))}` : ''}
              {lot.solde != null ? ` · ${tl.left.replace('{n}', nombre(lot.solde))}` : ''}
            </option>
          ))}
        </select>
      </label>
    );
  }

  const proposes = exemplaire.series.slice(0, 30).join(', ') + (exemplaire.series.length > 30 ? ' …' : '');
  return (
    <div className="space-y-1">
      <div className="flex flex-wrap gap-3">
        {attendus > 0 && <Numeros titre={client ? tl.serialsBack : tl.serialsOut} valeur={series} onChange={onSeries} attendus={attendus} />}
        {client && onSeriesRebut && (attendusRebut ?? 0) > 0 && <Numeros titre={tl.serialsScrap} valeur={seriesRebut ?? ''} onChange={onSeriesRebut} attendus={attendusRebut ?? 0} />}
      </div>
      {exemplaire.series.length > 0 && (
        <p className="text-xs text-gray-500 dark:text-gray-400">{(client ? tl.deliveredToCustomer : tl.inStock).replace('{liste}', proposes)}</p>
      )}
    </div>
  );
}
