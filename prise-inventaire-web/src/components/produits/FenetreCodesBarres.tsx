import { useCallback, useEffect, useState } from 'react';
import { Plus, Star, Trash2, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import {
  ajouterCodeBarre, getCodesBarres, modifierCodeBarre, supprimerCodeBarre, TYPES_CODE_BARRE, type CodeBarre, type TypeCodeBarre,
} from '@/services/codesBarres';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/**
 * Codes-barres d'un produit : la liste (le principal d'abord), l'ajout d'un code (type deviné ou choisi, clé d'un EAN
 * contrôlée, quantité par lecture), le choix du principal, la quantité et le retrait. Un scan d'un de ces codes compte
 * pour le produit, à la quantité du code.
 */
export default function FenetreCodesBarres({ produit, modifiable, onClose, onModifie }: {
  produit: { id: number; numero: string; description: string };
  modifiable: boolean;
  onClose: () => void;
  onModifie: () => void;
}) {
  const { t } = useLanguage();
  const tc = t.barcodes;
  const [codes, setCodes] = useState<CodeBarre[] | null>(null);
  const [saisie, setSaisie] = useState({ code: '', type: '' as TypeCodeBarre | '', quantite: '1' });
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  const charger = useCallback(() => getCodesBarres(produit.id).then(setCodes, e => setErreur(messageErreur(e, tc.error))), [produit.id, tc.error]);
  useEffect(() => { charger(); }, [charger]);

  async function agir(action: () => Promise<unknown>) {
    setOccupe(true);
    setErreur('');
    try {
      await action();
      await charger();
      onModifie();
    } catch (e) {
      setErreur(messageErreur(e, tc.error));
    } finally {
      setOccupe(false);
    }
  }

  function ajouter(e: React.FormEvent) {
    e.preventDefault();
    agir(async () => {
      await ajouterCodeBarre(produit.id, { code: saisie.code.trim(), type: saisie.type || null, quantite: Number(saisie.quantite.replace(',', '.')) || 1 });
      setSaisie({ code: '', type: '', quantite: '1' });
    });
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="codes-barres-titre" className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-2xl max-h-[94vh] overflow-y-auto space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="codes-barres-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tc.title.replace('{numero}', produit.numero)}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tc.close}><X size={18} /></button>
        </div>
        <p className="text-sm text-gray-600 dark:text-gray-300">{produit.description}</p>
        <p className="text-xs text-gray-500">{tc.help}</p>
        {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}

        {codes === null ? <p className="text-sm text-gray-500">{tc.loading}</p> : codes.length === 0 ? <p className="text-sm text-gray-500">{tc.none}</p> : (
          <table className="w-full text-sm" aria-label={tc.list}>
            <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
              <tr>
                <th className="px-3 py-2 text-left">{tc.code}</th>
                <th className="px-3 py-2 text-left">{tc.type}</th>
                <th className="px-3 py-2 text-right">{tc.quantity}</th>
                <th className="px-3 py-2 text-center">{tc.main}</th>
                {modifiable && <th className="px-3 py-2" />}
              </tr>
            </thead>
            <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
              {codes.map(c => (
                <tr key={c.id}>
                  <td className="px-3 py-2 font-mono">{c.code}</td>
                  <td className="px-3 py-2">{tc.types[c.type]}</td>
                  <td className="px-3 py-2 text-right">
                    {modifiable ? (
                      <input key={`${c.id}-${c.quantite}`} type="number" min={0.001} step="any" defaultValue={Number(c.quantite)} aria-label={`${tc.quantity} ${c.code}`}
                        onBlur={e => { if (Number(e.target.value) > 0 && Number(e.target.value) !== Number(c.quantite)) agir(() => modifierCodeBarre(produit.id, c.id, { code: c.code, quantite: Number(e.target.value) })); }}
                        className={`${classeChamp} w-24 text-right`} />
                    ) : Number(c.quantite)}
                  </td>
                  <td className="px-3 py-2 text-center">
                    <button type="button" disabled={!modifiable || c.principal || occupe} title={c.principal ? tc.isMain : tc.makeMain} aria-label={`${tc.makeMain} ${c.code}`}
                      onClick={() => agir(() => modifierCodeBarre(produit.id, c.id, { code: c.code, principal: true }))}
                      className={`p-1 rounded ${c.principal ? 'text-amber-500' : 'text-gray-300 hover:text-amber-500'} disabled:cursor-default`}>
                      <Star size={16} fill={c.principal ? 'currentColor' : 'none'} />
                    </button>
                  </td>
                  {modifiable && (
                    <td className="px-3 py-2 text-right">
                      <button type="button" disabled={occupe} onClick={() => confirm(tc.confirmDelete.replace('{code}', c.code)) && agir(() => supprimerCodeBarre(produit.id, c.id))}
                        className="p-1 rounded text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30" aria-label={`${tc.remove} ${c.code}`}><Trash2 size={16} /></button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}

        {modifiable && (
          <form onSubmit={ajouter} className="flex flex-wrap items-end gap-2 border-t dark:border-gray-700 pt-3" aria-label={tc.add}>
            <label className="text-xs text-gray-500 dark:text-gray-400 flex-1 min-w-[180px]">{tc.code}
              <input required maxLength={50} value={saisie.code} onChange={e => setSaisie({ ...saisie, code: e.target.value })} placeholder={tc.codeHelp} className={`${classeChamp} block w-full font-mono`} />
            </label>
            <label className="text-xs text-gray-500 dark:text-gray-400">{tc.type}
              <select value={saisie.type} onChange={e => setSaisie({ ...saisie, type: e.target.value as TypeCodeBarre | '' })} className={`${classeChamp} block`}>
                <option value="">{tc.autoType}</option>
                {TYPES_CODE_BARRE.map(type => <option key={type} value={type}>{tc.types[type]}</option>)}
              </select>
            </label>
            <label className="text-xs text-gray-500 dark:text-gray-400">{tc.quantity}
              <input type="number" min={0.001} step="any" value={saisie.quantite} onChange={e => setSaisie({ ...saisie, quantite: e.target.value })} className={`${classeChamp} block w-24`} />
            </label>
            <button type="submit" disabled={occupe || !saisie.code.trim()} className="flex items-center gap-1 px-3 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-40">
              <Plus size={16} /> {tc.add}
            </button>
          </form>
        )}
        <div className="flex justify-end">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-gray-200">{tc.close}</button>
        </div>
      </div>
    </div>
  );
}
