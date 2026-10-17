import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { Pencil, Plus, Star, Trash2 } from 'lucide-react';
import {
  enregistrerProduitFourni, getProduits, getProduitsFournis, retirerProduitFourni,
  type ProduitFourni, type SaisieProduitFourni,
} from '@/services/api';
import type { Produit } from '@/types';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDate, formatMontant } from '@/components/fiche/format';

interface Edition {
  produitId: number | null;
  nouveau: boolean;
  reference: string;
  prix: string;
  delai: string;
  principal: boolean;
  /** Conditionnement de ce fournisseur ; vides : celui du produit. */
  unite: string;
  facteur: string;
}

/**
 * Produits fournis : référence et prix convenus (dans la devise du fournisseur), délai, fournisseur
 * principal, dernier prix payé (dans la devise de sa commande).
 */
export default function OngletProduitsFournis({ fournisseurId, devise, deviseFournisseur, onCompte }: { fournisseurId: number; devise: string; deviseFournisseur?: string | null; onCompte: (nombre: number) => void }) {
  const { t, language } = useLanguage();
  const tp = t.supplierFile.products;
  const tc = t.clientFile;
  const id = useId();
  const [produits, setProduits] = useState<ProduitFourni[] | null>(null);
  const [catalogue, setCatalogue] = useState<Produit[]>([]);
  const [edition, setEdition] = useState<Edition | null>(null);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);
  const rappelCompte = useRef(onCompte);
  useEffect(() => { rappelCompte.current = onCompte; });

  const charger = useCallback(async () => {
    const trouves = await getProduitsFournis(fournisseurId);
    setProduits(trouves);
    rappelCompte.current(trouves.length);
  }, [fournisseurId]);

  useEffect(() => {
    let actif = true;
    getProduitsFournis(fournisseurId)
      .then(trouves => { if (actif) { setProduits(trouves); rappelCompte.current(trouves.length); } })
      .catch(e => { if (actif) setErreur(e instanceof ApiError ? e.message : t.supplierFile.error); });
    getProduits().then(p => { if (actif) setCatalogue(p); }).catch(() => {});
    return () => { actif = false; };
  }, [fournisseurId, t.supplierFile.error]);

  const montant = (v: number | null, code?: string | null) => (v === null ? '—' : formatMontant(v, code || devise, language));

  function ouvrir(produit?: ProduitFourni) {
    setErreur('');
    setEdition(produit
      ? {
        produitId: produit.produit_id, nouveau: false, reference: produit.reference_fournisseur ?? '', prix: produit.prix_achat?.toString() ?? '', delai: produit.delai_livraison?.toString() ?? '',
        principal: produit.fournisseur_principal, unite: produit.unite_achat ?? '', facteur: produit.qte_par_unite_achat?.toString() ?? '',
      }
      : { produitId: null, nouveau: true, reference: '', prix: '', delai: '', principal: false, unite: '', facteur: '' });
  }

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    if (!edition?.produitId) return;
    const donnees: SaisieProduitFourni = {
      reference_fournisseur: edition.reference.trim() || null,
      prix_achat: edition.prix.trim() === '' ? null : Number(edition.prix),
      delai_livraison: edition.delai.trim() === '' ? null : Number(edition.delai),
      fournisseur_principal: edition.principal,
      unite_achat: edition.unite.trim().toUpperCase() || null,
      qte_par_unite_achat: edition.facteur.trim() === '' ? null : Math.max(1, Math.floor(Number(edition.facteur)) || 1),
    };
    setOccupe(true);
    try {
      await enregistrerProduitFourni(fournisseurId, edition.produitId, donnees, edition.nouveau);
      setEdition(null);
      await charger();
    } catch (err) {
      setErreur(err instanceof ApiError ? err.message : t.supplierFile.error);
    } finally {
      setOccupe(false);
    }
  }

  async function retirer(produit: ProduitFourni) {
    if (!confirm(tp.confirmRemove.replace('{nom}', `${produit.numero} — ${produit.description}`))) return;
    try {
      await retirerProduitFourni(fournisseurId, produit.produit_id);
      await charger();
    } catch (err) {
      setErreur(err instanceof ApiError ? err.message : t.supplierFile.error);
    }
  }

  // Conditionnement de ce fournisseur, sinon celui du produit (en gris) ; rien pour un produit acheté à l'unité.
  const libelleConditionnement = (unite: string | null | undefined, n: number | null | undefined, mesure: string | null | undefined) =>
    (unite || (n ?? 1) > 1 ? tp.packagingOf.replace('{unite}', unite || '—').replace('{n}', String(n ?? 1)).replace('{mesure}', mesure || '') : null);
  function conditionnement(p: ProduitFourni) {
    const propre = p.unite_achat || p.qte_par_unite_achat
      ? libelleConditionnement(p.unite_achat ?? p.unite_achat_produit, p.qte_par_unite_achat ?? p.qte_par_unite_achat_produit, p.mesure)
      : null;
    if (propre) return propre;
    const duProduit = libelleConditionnement(p.unite_achat_produit, p.qte_par_unite_achat_produit, p.mesure);
    return duProduit ? <span className="text-gray-500">{tp.packagingDefault.replace('{conditionnement}', duProduit)}</span> : '—';
  }
  const enEdition = produits?.find(p => p.produit_id === edition?.produitId);
  const dejaFournis = new Set(produits?.map(p => p.produit_id));
  const champ = 'w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

  return (
    <div className="space-y-4">
      {erreur && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
      {produits?.length === 0 && !edition && <p className="text-sm text-gray-500 dark:text-gray-400">{tp.empty}</p>}

      {produits && produits.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
              <tr>
                <th className="px-3 py-2 text-left font-medium">{tp.product}</th>
                <th className="px-3 py-2 text-left font-medium">{tp.reference}</th>
                <th className="px-3 py-2 text-right font-medium">{tp.price}</th>
                <th className="px-3 py-2 text-left font-medium">{tp.packaging}</th>
                <th className="px-3 py-2 text-right font-medium">{tp.leadTime}</th>
                <th className="px-3 py-2 text-right font-medium">{tp.lastPrice}</th>
                <th className="px-3 py-2"><span className="sr-only">{tc.edit}</span></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {produits.map(p => (
                <tr key={p.produit_id} className="text-gray-800 dark:text-gray-200">
                  <td className="px-3 py-2">
                    <span className="font-mono text-xs text-gray-500 mr-2">{p.numero}</span>{p.description}
                    {p.fournisseur_principal && (
                      <span className="ml-2 inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] font-medium bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300">
                        <Star size={11} fill="currentColor" /> {tp.main}
                      </span>
                    )}
                  </td>
                  <td className="px-3 py-2 font-mono text-xs">{p.reference_fournisseur ?? '—'}</td>
                  <td className="px-3 py-2 text-right whitespace-nowrap">{montant(p.prix_achat, deviseFournisseur)}</td>
                  <td className="px-3 py-2 whitespace-nowrap">{conditionnement(p)}</td>
                  <td className="px-3 py-2 text-right">{p.delai_livraison ?? '—'}</td>
                  <td className="px-3 py-2 text-right whitespace-nowrap">
                    {montant(p.dernier_prix, p.dernier_prix_devise)}
                    {p.derniere_commande && <span className="block text-xs text-gray-500">{formatDate(p.derniere_commande, language)}</span>}
                  </td>
                  <td className="px-3 py-2 text-right whitespace-nowrap">
                    <button onClick={() => ouvrir(p)} aria-label={`${tc.edit} — ${p.numero}`} title={tc.edit} className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-gray-700 rounded"><Pencil size={16} /></button>
                    <button onClick={() => retirer(p)} aria-label={`${tp.remove} — ${p.numero}`} title={tp.remove} className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-gray-700 rounded"><Trash2 size={16} /></button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {edition ? (
        <form onSubmit={enregistrer} className="p-4 rounded-lg border border-teal-300 dark:border-teal-700 space-y-3">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div className="sm:col-span-2 lg:col-span-4">
              <label htmlFor={`${id}-produit`} className="block text-sm font-medium mb-1 dark:text-gray-300">{tp.product} *</label>
              {edition.nouveau ? (
                <select id={`${id}-produit`} required value={edition.produitId ?? ''} onChange={e => setEdition({ ...edition, produitId: e.target.value ? Number(e.target.value) : null })} className={champ}>
                  <option value="">{tp.choose}</option>
                  {catalogue.filter(p => p.id !== undefined && !dejaFournis.has(p.id)).map(p => <option key={p.id} value={p.id}>{p.numero} — {p.description}</option>)}
                </select>
              ) : (
                <p id={`${id}-produit`} className="text-sm dark:text-white">
                  {produits?.find(p => p.produit_id === edition.produitId)?.numero} — {produits?.find(p => p.produit_id === edition.produitId)?.description}
                </p>
              )}
            </div>
            <div>
              <label htmlFor={`${id}-reference`} className="block text-sm font-medium mb-1 dark:text-gray-300">{tp.reference}</label>
              <input id={`${id}-reference`} maxLength={50} value={edition.reference} onChange={e => setEdition({ ...edition, reference: e.target.value })} className={champ} />
            </div>
            <div>
              <label htmlFor={`${id}-prix`} className="block text-sm font-medium mb-1 dark:text-gray-300">{tp.price}{deviseFournisseur && ` (${deviseFournisseur})`}</label>
              <input id={`${id}-prix`} type="number" min={0} step="0.01" value={edition.prix} onChange={e => setEdition({ ...edition, prix: e.target.value })} className={champ} />
            </div>
            <div>
              <label htmlFor={`${id}-unite`} className="block text-sm font-medium mb-1 dark:text-gray-300">{tp.purchaseUnit}</label>
              <input id={`${id}-unite`} maxLength={30} value={edition.unite} onChange={e => setEdition({ ...edition, unite: e.target.value })}
                placeholder={enEdition?.unite_achat_produit ?? ''} className={champ} />
            </div>
            <div>
              <label htmlFor={`${id}-facteur`} className="block text-sm font-medium mb-1 dark:text-gray-300">{tp.perPurchaseUnit.replace('{mesure}', enEdition?.mesure ?? catalogue.find(p => p.id === edition.produitId)?.mesure ?? '')}</label>
              <input id={`${id}-facteur`} type="number" min={1} step="1" value={edition.facteur} onChange={e => setEdition({ ...edition, facteur: e.target.value })}
                placeholder={String(enEdition?.qte_par_unite_achat_produit ?? catalogue.find(p => p.id === edition.produitId)?.qte_par_unite_achat ?? 1)} className={champ} />
            </div>
            <div>
              <label htmlFor={`${id}-delai`} className="block text-sm font-medium mb-1 dark:text-gray-300">{tp.leadTime}</label>
              <input id={`${id}-delai`} type="number" min={0} step="1" value={edition.delai} onChange={e => setEdition({ ...edition, delai: e.target.value })} className={champ} />
            </div>
            <div className="flex items-end">
              <label className="flex items-center gap-2 text-sm dark:text-gray-300 pb-2">
                <input type="checkbox" checked={edition.principal} onChange={e => setEdition({ ...edition, principal: e.target.checked })} />
                {tp.main}
              </label>
            </div>
          </div>
          <p className="text-xs text-gray-500 dark:text-gray-400">{tp.mainHint}</p>
          <p className="text-xs text-gray-500 dark:text-gray-400">{tp.packagingHint}</p>
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setEdition(null)} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-700">{tc.cancel}</button>
            <button type="submit" disabled={occupe || !edition.produitId} className="px-4 py-2 text-sm bg-teal-600 text-white rounded-lg hover:bg-teal-700 disabled:opacity-50">{tc.save}</button>
          </div>
        </form>
      ) : (
        <button onClick={() => ouvrir()} className="flex items-center gap-2 px-3 py-2 text-sm rounded-lg border border-teal-600 text-teal-700 dark:text-teal-300 hover:bg-teal-50 dark:hover:bg-teal-900/30">
          <Plus size={16} /> {tp.add}
        </button>
      )}
    </div>
  );
}
