import { useCallback, useEffect, useState } from 'react';
import { Plus, Trash2, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import {
  ajouterLienProduit, getLiensProduit, modifierLienProduit, retirerLienProduit, type LiensDuProduit, type LienProduit, type TypeLien,
} from '@/services/liensProduits';
import type { Produit } from '@/types';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
const nombre = (texte: string) => Number(texte.replace(',', '.'));
const CLES: Record<TypeLien, 'composants' | 'annexes' | 'equivalents'> = { composant: 'composants', annexe: 'annexes', equivalent: 'equivalents' };
const SECTIONS = (Object.keys(CLES) as TypeLien[]).map(type => ({ type, cle: CLES[type] }));

/**
 * Liens d'un produit : ses composants (il devient un ensemble), ses annexes (ajoutées d'office à la vente), ses
 * équivalents (substituts, dans les deux sens), et les ensembles dont il fait partie.
 */
export default function FenetreLiensProduit({ produit, produits, modifiable, onClose, onModifie }: {
  produit: { id: number; numero: string; description: string };
  /** Catalogue : les produits que l'on peut lier. */
  produits: Produit[];
  modifiable: boolean;
  onClose: () => void;
  onModifie: () => void;
}) {
  const { t } = useLanguage();
  const tl = t.productLinks;
  const [liens, setLiens] = useState<LiensDuProduit | null>(null);
  const [saisies, setSaisies] = useState<Record<TypeLien, { lie_id: string; quantite: string; remarque: string }>>({
    composant: { lie_id: '', quantite: '1', remarque: '' },
    annexe: { lie_id: '', quantite: '1', remarque: '' },
    equivalent: { lie_id: '', quantite: '1', remarque: '' },
  });
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  const charger = useCallback(() => getLiensProduit(produit.id).then(setLiens, e => setErreur(messageErreur(e, tl.error))), [produit.id, tl.error]);
  useEffect(() => { charger(); }, [charger]);

  async function agir(action: () => Promise<unknown>) {
    setOccupe(true);
    setErreur('');
    try {
      await action();
      await charger();
      onModifie();
    } catch (e) {
      setErreur(messageErreur(e, tl.error));
    } finally {
      setOccupe(false);
    }
  }

  function ajouter(type: TypeLien, e: React.FormEvent) {
    e.preventDefault();
    const saisie = saisies[type];
    agir(async () => {
      await ajouterLienProduit(produit.id, {
        type, lie_id: Number(saisie.lie_id),
        quantite: type === 'equivalent' ? undefined : nombre(saisie.quantite) || 1,
        remarque: type === 'equivalent' ? (saisie.remarque.trim() || null) : undefined,
      });
      setSaisies(s => ({ ...s, [type]: { lie_id: '', quantite: '1', remarque: '' } }));
    });
  }

  const choix = (type: TypeLien) => {
    const deja = new Set((liens?.[CLES[type]] ?? []).map(l => l.lie_id));
    return produits.filter(p => p.id !== undefined && p.id !== produit.id && p.actif !== false && !deja.has(p.id));
  };

  const ligne = (lien: LienProduit) => (
    <tr key={lien.id}>
      <td className="px-3 py-2"><span className="font-mono text-xs text-gray-500 mr-2">{lien.produit?.numero}</span>{lien.produit?.description}</td>
      <td className="px-3 py-2 text-right">
        {lien.type === 'equivalent' ? (
          modifiable ? (
            <input key={`${lien.id}-${lien.remarque}`} defaultValue={lien.remarque ?? ''} maxLength={255} aria-label={`${tl.remark} ${lien.produit?.numero}`}
              onBlur={e => { const v = e.target.value.trim() || null; if (v !== lien.remarque) agir(() => modifierLienProduit(produit.id, lien.id, { remarque: v })); }}
              className={`${classeChamp} w-full`} placeholder={tl.remarkHelp} />
          ) : (lien.remarque ?? '')
        ) : modifiable ? (
          <input key={`${lien.id}-${lien.quantite}`} inputMode="decimal" defaultValue={String(lien.quantite)} aria-label={`${tl.quantity} ${lien.produit?.numero}`}
            onBlur={e => { const v = nombre(e.target.value); if (v > 0 && v !== Number(lien.quantite)) agir(() => modifierLienProduit(produit.id, lien.id, { quantite: v })); }}
            className={`${classeChamp} w-24 text-right`} />
        ) : Number(lien.quantite)}
      </td>
      {modifiable && (
        <td className="px-3 py-2 text-right">
          <button type="button" disabled={occupe} onClick={() => confirm(tl.confirmDelete.replace('{numero}', lien.produit?.numero ?? '')) && agir(() => retirerLienProduit(produit.id, lien.id))}
            className="p-1 rounded text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30" aria-label={`${tl.remove} ${lien.produit?.numero}`}><Trash2 size={16} /></button>
        </td>
      )}
    </tr>
  );

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="liens-produit-titre" className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-3xl max-h-[94vh] overflow-y-auto space-y-4">
        <div className="flex items-start justify-between gap-3">
          <h2 id="liens-produit-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tl.title.replace('{numero}', produit.numero)}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tl.close}><X size={18} /></button>
        </div>
        <p className="text-sm text-gray-600 dark:text-gray-300">{produit.description}</p>
        {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}
        {liens === null ? <p className="text-sm text-gray-500">{tl.loading}</p> : (
          <>
            {SECTIONS.map(({ type, cle }) => (
              <section key={type} aria-labelledby={`liens-${type}`} className="space-y-2">
                <h3 id={`liens-${type}`} className="font-semibold text-gray-800 dark:text-gray-100">{tl.sections[type]}</h3>
                <p className="text-xs text-gray-500">{tl.help[type]}</p>
                {liens[cle].length === 0 ? <p className="text-sm text-gray-500 italic">{tl.none}</p> : (
                  <table className="w-full text-sm" aria-label={tl.sections[type]}>
                    <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                      <tr>
                        <th className="px-3 py-2 text-left">{tl.product}</th>
                        <th className="px-3 py-2 text-right">{type === 'equivalent' ? tl.remark : tl.quantity}</th>
                        {modifiable && <th className="px-3 py-2" />}
                      </tr>
                    </thead>
                    <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">{liens[cle].map(ligne)}</tbody>
                  </table>
                )}
                {modifiable && (
                  <form onSubmit={e => ajouter(type, e)} className="flex flex-wrap items-end gap-2" aria-label={`${tl.add} — ${tl.sections[type]}`}>
                    <label className="text-xs text-gray-500 dark:text-gray-400 flex-1 min-w-[220px]">{tl.product}
                      <select required value={saisies[type].lie_id} onChange={e => setSaisies(s => ({ ...s, [type]: { ...s[type], lie_id: e.target.value } }))}
                        className={`${classeChamp} block w-full`}>
                        <option value="">{tl.choose}</option>
                        {choix(type).map(p => <option key={p.id} value={p.id}>{p.numero} — {p.description}</option>)}
                      </select>
                    </label>
                    {type === 'equivalent' ? (
                      <label className="text-xs text-gray-500 dark:text-gray-400 flex-1 min-w-[180px]">{tl.remark}
                        <input maxLength={255} value={saisies[type].remarque} onChange={e => setSaisies(s => ({ ...s, [type]: { ...s[type], remarque: e.target.value } }))}
                          placeholder={tl.remarkHelp} className={`${classeChamp} block w-full`} />
                      </label>
                    ) : (
                      <label className="text-xs text-gray-500 dark:text-gray-400">{tl.quantity}
                        <input required inputMode="decimal" value={saisies[type].quantite} onChange={e => setSaisies(s => ({ ...s, [type]: { ...s[type], quantite: e.target.value } }))}
                          className={`${classeChamp} block w-24`} />
                      </label>
                    )}
                    <button type="submit" disabled={occupe || !saisies[type].lie_id}
                      className="flex items-center gap-1 px-3 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-40">
                      <Plus size={16} /> {tl.add}
                    </button>
                  </form>
                )}
              </section>
            ))}
            {liens.ensembles.length > 0 && (
              <section className="space-y-1">
                <h3 className="font-semibold text-gray-800 dark:text-gray-100">{tl.partOf}</h3>
                <ul className="text-sm text-gray-700 dark:text-gray-300 list-disc pl-5">
                  {liens.ensembles.map(e => <li key={e.id}><span className="font-mono text-xs">{e.numero}</span> — {e.description} (× {Number(e.quantite)})</li>)}
                </ul>
              </section>
            )}
          </>
        )}
        <div className="flex justify-end">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-gray-200">{tl.close}</button>
        </div>
      </div>
    </div>
  );
}
