import { useState } from 'react';
import { X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { ApiError } from '@/services/http';
import { creerLotVirements, ibanLisible, type CompteVirement, type FormatVirement, type LotVirements } from '@/services/virements';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/** Demain, au format AAAA-MM-JJ, dans le fuseau de l'utilisateur. */
function demain(): string {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/**
 * Générer le fichier d'un lot de virements : le compte d'où partent les fonds, le format (SEPA pain.001 ou CSV), la
 * date d'exécution, le numéro de lot (le prochain du compte, modifiable) et l'identifiant d'émetteur éventuel. Tout est
 * vérifié avant d'écrire le fichier ; en cas de problème, la liste s'affiche et rien n'est créé.
 */
export default function FenetreLotVirements({ compte, debourses, total, onClose, onCree }: {
  compte: CompteVirement;
  debourses: number[];
  total: string;
  onClose: () => void;
  onCree: (lot: LotVirements) => void;
}) {
  const { t } = useLanguage();
  const tv = t.transfers;
  const [saisie, setSaisie] = useState({
    format: (compte.virement_format ?? 'sepa') as FormatVirement,
    date: demain(),
    numero: String(compte.prochain_lot),
    emetteur: compte.virement_emetteur ?? '',
  });
  const [problemes, setProblemes] = useState<string[]>([]);
  const [occupe, setOccupe] = useState(false);

  async function generer(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setProblemes([]);
    try {
      onCree(await creerLotVirements({
        compte_bancaire_id: compte.id, debourses, format: saisie.format, date_execution: saisie.date,
        numero: Number(saisie.numero) || null, emetteur: saisie.emetteur.trim() || null,
      }));
    } catch (err) {
      // Tous les problèmes à corriger, pas seulement le premier.
      const erreurs = err instanceof ApiError ? (err.data as { errors?: Record<string, string[]> } | null)?.errors : undefined;
      setProblemes(erreurs ? Object.values(erreurs).flat() : [messageErreur(err, tv.error)]);
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <form onSubmit={generer} role="dialog" aria-modal="true" aria-labelledby="lot-virements-titre" className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-lg space-y-3 max-h-[94vh] overflow-y-auto">
        <div className="flex items-start justify-between gap-3">
          <h2 id="lot-virements-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tv.generateTitle}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tv.close}><X size={18} /></button>
        </div>
        <p className="text-sm dark:text-gray-200">{tv.generateSummary.replace('{n}', String(debourses.length)).replace('{total}', total)}</p>
        <div className="text-sm dark:text-gray-200">
          <p className="text-xs text-gray-500">{tv.fromAccount}</p>
          <p className="font-medium">{compte.nom} — {compte.banque}</p>
          <p className={`text-xs font-mono ${compte.iban_valide ? 'text-gray-500' : 'text-red-700 dark:text-red-300'}`}>{compte.iban ? ibanLisible(compte.iban) : tv.noIban}</p>
        </div>
        <fieldset className="space-y-1">
          <legend className="text-xs text-gray-500 dark:text-gray-400">{tv.format}</legend>
          {(['sepa', 'csv'] as FormatVirement[]).map(f => (
            <label key={f} className="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
              <input type="radio" name="format" checked={saisie.format === f} onChange={() => setSaisie({ ...saisie, format: f })} className="mt-1" />
              <span>{tv.formats[f]}<span className="block text-xs text-gray-500">{tv.formatsHelp[f]}</span></span>
            </label>
          ))}
        </fieldset>
        <div className="grid grid-cols-2 gap-3">
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tv.executionDate}
            <input type="date" required value={saisie.date} onChange={e => setSaisie({ ...saisie, date: e.target.value })} className={classeChamp} />
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tv.batchNumber}
            <input type="number" min={1} required value={saisie.numero} onChange={e => setSaisie({ ...saisie, numero: e.target.value })} className={classeChamp} />
          </label>
        </div>
        {saisie.format === 'sepa' && (
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tv.senderId}
            <input maxLength={35} value={saisie.emetteur} onChange={e => setSaisie({ ...saisie, emetteur: e.target.value })} className={classeChamp} />
            <span className="block mt-1">{tv.senderIdHelp}</span>
          </label>
        )}
        {problemes.length > 0 && (
          <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">
            <p className="font-medium">{tv.problems}</p>
            <ul className="list-disc pl-5">{problemes.map(p => <li key={p}>{p}</li>)}</ul>
          </div>
        )}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-gray-200">{tv.cancel}</button>
          <button type="submit" disabled={occupe} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-40">{tv.generate}</button>
        </div>
      </form>
    </div>
  );
}
