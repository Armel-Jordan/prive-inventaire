import { useCallback, useEffect, useState } from 'react';
import { Plus, Star, Trash2, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { ajouterUniteProduit, getUnitesProduit, modifierUniteProduit, supprimerUniteProduit, type UnitesDuProduit } from '@/services/unitesProduits';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
const nombre = (texte: string) => Number(texte.replace(',', '.'));

/**
 * Unités de vente d'un produit : son unité de stock (facteur 1), puis les unités du référentiel dans lesquelles il se
 * vend, avec leur facteur (proposé d'après les conversions), leur prix propre et celle proposée sur les lignes de vente.
 */
export default function FenetreUnitesProduit({ produit, modifiable, onClose, onModifie }: {
  produit: { id: number; numero: string; description: string; prix_unitaire?: number | string | null };
  modifiable: boolean;
  onClose: () => void;
  onModifie: () => void;
}) {
  const { t } = useLanguage();
  const tu = t.productUnits;
  const [donnees, setDonnees] = useState<UnitesDuProduit | null>(null);
  const [saisie, setSaisie] = useState({ unite_id: '', facteur: '', prix: '' });
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  const charger = useCallback(() => getUnitesProduit(produit.id).then(setDonnees, e => setErreur(messageErreur(e, tu.error))), [produit.id, tu.error]);
  useEffect(() => { charger(); }, [charger]);

  const mesure = donnees?.mesure ?? '';
  const prixBase = Number(produit.prix_unitaire) || 0;
  const disponible = donnees?.disponibles.find(u => String(u.id) === saisie.unite_id);

  async function agir(action: () => Promise<unknown>) {
    setOccupe(true);
    setErreur('');
    try {
      await action();
      await charger();
      onModifie();
    } catch (e) {
      setErreur(messageErreur(e, tu.error));
    } finally {
      setOccupe(false);
    }
  }

  function ajouter(e: React.FormEvent) {
    e.preventDefault();
    agir(async () => {
      await ajouterUniteProduit(produit.id, {
        unite_id: Number(saisie.unite_id),
        facteur: saisie.facteur.trim() ? nombre(saisie.facteur) : null,
        prix: saisie.prix.trim() ? nombre(saisie.prix) : null,
      });
      setSaisie({ unite_id: '', facteur: '', prix: '' });
    });
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="unites-produit-titre" className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-3xl max-h-[94vh] overflow-y-auto space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="unites-produit-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tu.title.replace('{numero}', produit.numero)}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tu.close}><X size={18} /></button>
        </div>
        <p className="text-sm text-gray-600 dark:text-gray-300">{produit.description}</p>
        <p className="text-xs text-gray-500">{tu.help.replaceAll('{mesure}', mesure || '—')}</p>
        {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}

        {donnees === null ? <p className="text-sm text-gray-500">{tu.loading}</p> : (
          <table className="w-full text-sm" aria-label={tu.title.replace('{numero}', produit.numero)}>
            <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
              <tr>
                <th className="px-3 py-2 text-left">{tu.unit}</th>
                <th className="px-3 py-2 text-right">{tu.factor}</th>
                <th className="px-3 py-2 text-right">{tu.price}</th>
                <th className="px-3 py-2 text-center" title={tu.isDefault}><Star size={14} className="inline" /></th>
                {modifiable && <th className="px-3 py-2" />}
              </tr>
            </thead>
            <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
              <tr className="text-gray-500">
                <td className="px-3 py-2 font-mono">{mesure || '—'} <span className="text-xs">({tu.stockUnit})</span></td>
                <td className="px-3 py-2 text-right">1</td>
                <td className="px-3 py-2 text-right">{prixBase}</td>
                <td className="px-3 py-2 text-center">
                  {donnees.unites.every(u => !u.par_defaut) && <Star size={16} className="inline text-amber-500" fill="currentColor" aria-label={tu.isDefault} />}
                </td>
                {modifiable && <td />}
              </tr>
              {donnees.unites.map(u => (
                <tr key={u.id}>
                  <td className="px-3 py-2"><span className="font-mono">{u.code}</span> <span className="text-xs text-gray-500">{u.libelle}</span></td>
                  <td className="px-3 py-2 text-right">
                    {modifiable ? (
                      <input key={`${u.id}-${u.facteur}`} inputMode="decimal" defaultValue={String(u.facteur)} aria-label={`${tu.factor} ${u.code}`}
                        onBlur={e => { const v = nombre(e.target.value); if (v > 0 && v !== Number(u.facteur)) agir(() => modifierUniteProduit(produit.id, u.id, { facteur: v })); }}
                        className={`${classeChamp} w-24 text-right`} />
                    ) : Number(u.facteur)}
                  </td>
                  <td className="px-3 py-2 text-right">
                    {modifiable ? (
                      <input key={`${u.id}-${u.prix}`} inputMode="decimal" defaultValue={u.prix === null ? '' : String(u.prix)} aria-label={`${tu.price} ${u.code}`}
                        placeholder={String(Math.round(prixBase * Number(u.facteur) * 100) / 100)}
                        onBlur={e => {
                          const texte = e.target.value.trim();
                          const prix = texte === '' ? null : nombre(texte);
                          if ((prix === null || prix >= 0) && prix !== (u.prix === null ? null : Number(u.prix))) agir(() => modifierUniteProduit(produit.id, u.id, { prix }));
                        }}
                        className={`${classeChamp} w-28 text-right`} />
                    ) : (u.prix ?? Math.round(prixBase * Number(u.facteur) * 100) / 100)}
                  </td>
                  <td className="px-3 py-2 text-center">
                    <button type="button" disabled={!modifiable || occupe} aria-pressed={u.par_defaut} title={u.par_defaut ? tu.isDefault : tu.makeDefault}
                      aria-label={`${tu.makeDefault} : ${u.code}`}
                      onClick={() => agir(() => modifierUniteProduit(produit.id, u.id, { par_defaut: !u.par_defaut }))}
                      className={`p-1 rounded ${u.par_defaut ? 'text-amber-500' : 'text-gray-300 hover:text-amber-500'} disabled:cursor-default`}>
                      <Star size={16} fill={u.par_defaut ? 'currentColor' : 'none'} />
                    </button>
                  </td>
                  {modifiable && (
                    <td className="px-3 py-2 text-right">
                      <button type="button" disabled={occupe} onClick={() => confirm(tu.confirmDelete.replace('{code}', u.code)) && agir(() => supprimerUniteProduit(produit.id, u.id))}
                        className="p-1 rounded text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30" aria-label={`${tu.remove} ${u.code}`}><Trash2 size={16} /></button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {donnees && donnees.unites.length === 0 && <p className="text-sm text-gray-500">{tu.none}</p>}

        {modifiable && donnees && (donnees.disponibles.length === 0 ? <p className="text-xs text-gray-500 border-t dark:border-gray-700 pt-3">{tu.noUnitsInReferential}</p> : (
          <form onSubmit={ajouter} className="flex flex-wrap items-end gap-2 border-t dark:border-gray-700 pt-3" aria-label={tu.add}>
            <label className="text-xs text-gray-500 dark:text-gray-400">{tu.unit}
              <select required value={saisie.unite_id} className={`${classeChamp} block`}
                onChange={e => {
                  const choisie = donnees.disponibles.find(u => String(u.id) === e.target.value);
                  setSaisie({ ...saisie, unite_id: e.target.value, facteur: choisie?.facteur_propose ? String(choisie.facteur_propose) : '' });
                }}>
                <option value="">{tu.chooseUnit}</option>
                {donnees.disponibles.map(u => <option key={u.id} value={u.id}>{u.code} — {u.libelle}</option>)}
              </select>
            </label>
            <label className="text-xs text-gray-500 dark:text-gray-400">{tu.factor}
              <input required inputMode="decimal" value={saisie.facteur} onChange={e => setSaisie({ ...saisie, facteur: e.target.value })}
                placeholder={tu.factorHelp.replace('{mesure}', mesure)} className={`${classeChamp} block w-40`} />
              <span className="block mt-0.5">
                {disponible ? (disponible.facteur_propose ? tu.proposed.replace('{facteur}', String(disponible.facteur_propose)) : tu.noConversion) : tu.factorHelp.replace('{mesure}', mesure)}
              </span>
            </label>
            <label className="text-xs text-gray-500 dark:text-gray-400">{tu.price}
              <input inputMode="decimal" value={saisie.prix} onChange={e => setSaisie({ ...saisie, prix: e.target.value })} className={`${classeChamp} block w-32`}
                placeholder={tu.priceHelp.replace('{prix}', String(Math.round(prixBase * (nombre(saisie.facteur) || 0) * 100) / 100))} />
            </label>
            <button type="submit" disabled={occupe || !saisie.unite_id || !(nombre(saisie.facteur) > 0)}
              className="flex items-center gap-1 px-3 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-40">
              <Plus size={16} /> {tu.add}
            </button>
          </form>
        ))}
        <div className="flex justify-end">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-gray-200">{tu.close}</button>
        </div>
      </div>
    </div>
  );
}
