import { useEffect, useId, useState } from 'react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import {
  enregistrerReglePrix, getClientsActifs, getListesPrix, getProduits, getReglesPrix, supprimerReglePrix,
  type Client, type ListePrix, type ReglePrix, type TypeReglePrix,
} from '@/services/api';
import type { Produit } from '@/types';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateCourte, formatPrix } from './format';

type Saisie = {
  id?: number; libelle: string; client_id: string; liste_prix_id: string; produit_id: string; categorie_produit: string;
  quantite_min: string; date_debut: string; date_fin: string; type: TypeReglePrix; valeur: string; actif: boolean;
};
const vide: Saisie = {
  libelle: '', client_id: '', liste_prix_id: '', produit_id: '', categorie_produit: '',
  quantite_min: '1', date_debut: '', date_fin: '', type: 'remise_pourcentage', valeur: '', actif: true,
};

/** Statut d'une règle à aujourd'hui, comme le filtre de l'API. */
function statutDe(r: ReglePrix): 'en_vigueur' | 'a_venir' | 'expiree' | 'inactive' {
  const aujourdhui = new Date().toLocaleDateString('sv-SE'); // AAAA-MM-JJ, date locale
  if (!r.actif) return 'inactive';
  if (r.date_debut && r.date_debut.slice(0, 10) > aujourdhui) return 'a_venir';
  if (r.date_fin && r.date_fin.slice(0, 10) < aujourdhui) return 'expiree';
  return 'en_vigueur';
}

const couleurs = {
  en_vigueur: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
  a_venir: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
  expiree: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
  inactive: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
};

/** Règles de prix : prix client, paliers de quantité, promotions par période, par produit ou catégorie. */
export default function ReglesPrix({ modifiable, devise }: { modifiable: boolean; devise: string }) {
  const { t, language } = useLanguage();
  const tp = t.pricing;
  const tr = tp.rules;
  const id = useId();
  const [filtres, setFiltres] = useState({ statut: '', recherche: '' });
  const [version, setVersion] = useState(0);
  const [regles, setRegles] = useState<ReglePrix[] | null>(null);
  const [saisie, setSaisie] = useState<Saisie | null>(null);
  const [erreur, setErreur] = useState('');
  const [clients, setClients] = useState<Client[]>([]);
  const [produits, setProduits] = useState<(Produit & { categorie?: string | null })[]>([]);
  const [listes, setListes] = useState<ListePrix[]>([]);

  useEffect(() => {
    let actif = true;
    getReglesPrix(filtres)
      .then(r => { if (actif) setRegles(r.data); })
      .catch(e => { if (actif) setErreur(e instanceof ApiError ? e.message : tp.error); });
    return () => { actif = false; };
  }, [filtres, version, tp.error]);

  // Listes de choix du formulaire, chargées à la première ouverture.
  const formulaireOuvert = saisie !== null;
  useEffect(() => {
    if (!formulaireOuvert || produits.length > 0) return;
    let actif = true;
    Promise.all([getClientsActifs(), getProduits(), getListesPrix()])
      .then(([c, p, l]) => { if (actif) { setClients(c); setProduits(p); setListes(l); } })
      .catch(() => {});
    return () => { actif = false; };
  }, [formulaireOuvert, produits.length]);

  const categories = [...new Set(produits.map(p => p.categorie).filter((c): c is string => Boolean(c)))].sort();

  async function executer(action: () => Promise<unknown>) {
    try {
      await action();
      setErreur('');
      setVersion(v => v + 1);
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : tp.error);
    }
  }

  function portee(r: ReglePrix): string {
    const qui = r.client ? `${r.client.raison_sociale}` : r.liste_prix ? `${tr.list} ${r.liste_prix.nom}` : tr.allClients;
    const quoi = r.produit ? `${r.produit.numero} ${r.produit.description}` : r.categorie_produit ? `${tr.productCategory} ${r.categorie_produit}` : tr.allProducts;
    return `${qui} · ${quoi}`;
  }

  function conditions(r: ReglePrix): string {
    const du = formatDateCourte(r.date_debut, language);
    const au = formatDateCourte(r.date_fin, language);
    const periode = r.date_debut && r.date_fin ? tr.period.replace('{du}', du).replace('{au}', au)
      : r.date_debut ? tr.since.replace('{du}', du) : r.date_fin ? tr.until.replace('{au}', au) : tr.always;
    return [r.quantite_min > 1 ? tr.qtyFrom.replace('{n}', String(r.quantite_min)) : null, periode].filter(Boolean).join(' · ');
  }

  const effet = (r: ReglePrix) => r.type === 'remise_pourcentage' ? `−${Number(r.valeur)} %`
    : r.type === 'remise_montant' ? `−${formatPrix(r.valeur, devise, language)}` : formatPrix(r.valeur, devise, language);

  const versSaisie = (r: ReglePrix): Saisie => ({
    id: r.id, libelle: r.libelle, client_id: r.client_id ? String(r.client_id) : '', liste_prix_id: r.liste_prix_id ? String(r.liste_prix_id) : '',
    produit_id: r.produit_id ? String(r.produit_id) : '', categorie_produit: r.categorie_produit ?? '', quantite_min: String(r.quantite_min),
    date_debut: r.date_debut?.slice(0, 10) ?? '', date_fin: r.date_fin?.slice(0, 10) ?? '', type: r.type, valeur: String(Number(r.valeur)), actif: r.actif,
  });

  const champ = 'w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
  const etiquette = 'block text-sm font-medium mb-1 dark:text-gray-300';

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3">
        <div>
          <label htmlFor={`${id}-statut`} className="block text-xs text-gray-500 mb-1">{tr.status}</label>
          <select id={`${id}-statut`} value={filtres.statut} onChange={e => setFiltres({ ...filtres, statut: e.target.value })} className="px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white">
            <option value="">{tr.allStatuses}</option>
            {Object.entries(tr.statuses).map(([cle, libelle]) => <option key={cle} value={cle}>{libelle}</option>)}
          </select>
        </div>
        <div className="flex-1 min-w-[12rem]">
          <label htmlFor={`${id}-recherche`} className="block text-xs text-gray-500 mb-1">{tr.search}</label>
          <input id={`${id}-recherche`} type="search" value={filtres.recherche} onChange={e => setFiltres({ ...filtres, recherche: e.target.value })} className={champ} />
        </div>
        {modifiable && !saisie && (
          <button onClick={() => setSaisie(vide)} className="ml-auto flex items-center gap-2 px-3 py-2 text-sm rounded-lg bg-purple-600 text-white hover:bg-purple-700">
            <Plus size={16} /> {tr.add}
          </button>
        )}
      </div>

      {erreur && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      {saisie && (
        <form className="p-4 rounded-lg border border-purple-300 dark:border-purple-700 space-y-3" onSubmit={e => {
          e.preventDefault();
          const nombre = (v: string) => (v.trim() === '' ? null : Number(v.replace(',', '.')));
          executer(async () => {
            await enregistrerReglePrix({
              libelle: saisie.libelle, type: saisie.type, valeur: nombre(saisie.valeur) ?? 0, actif: saisie.actif,
              client_id: nombre(saisie.client_id), liste_prix_id: nombre(saisie.liste_prix_id), produit_id: nombre(saisie.produit_id),
              categorie_produit: saisie.categorie_produit || null, quantite_min: nombre(saisie.quantite_min) ?? 1,
              date_debut: saisie.date_debut || null, date_fin: saisie.date_fin || null,
            }, saisie.id);
            setSaisie(null);
          });
        }}>
          <div>
            <label htmlFor={`${id}-libelle`} className={etiquette}>{tr.label} *</label>
            <input id={`${id}-libelle`} required maxLength={150} placeholder={tr.labelHint} value={saisie.libelle} onChange={e => setSaisie({ ...saisie, libelle: e.target.value })} className={champ} />
          </div>
          <fieldset className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <legend className="text-xs uppercase tracking-wide text-gray-500 mb-1">{tr.scope}</legend>
            <div>
              <label htmlFor={`${id}-client`} className={etiquette}>{tr.client}</label>
              <select id={`${id}-client`} value={saisie.client_id} onChange={e => setSaisie({ ...saisie, client_id: e.target.value })} className={champ}>
                <option value="">{tr.allClients}</option>
                {clients.map(c => <option key={c.id} value={c.id}>{c.code} — {c.raison_sociale}</option>)}
              </select>
            </div>
            <div>
              <label htmlFor={`${id}-liste`} className={etiquette}>{tr.list}</label>
              <select id={`${id}-liste`} value={saisie.liste_prix_id} onChange={e => setSaisie({ ...saisie, liste_prix_id: e.target.value })} className={champ}>
                <option value="">{tr.allLists}</option>
                {listes.map(l => <option key={l.id} value={l.id}>{l.nom}</option>)}
              </select>
            </div>
            <div>
              <label htmlFor={`${id}-produit`} className={etiquette}>{tr.product}</label>
              <select id={`${id}-produit`} value={saisie.produit_id} onChange={e => setSaisie({ ...saisie, produit_id: e.target.value })} className={champ}>
                <option value="">{tr.allProducts}</option>
                {produits.map(p => <option key={p.id} value={p.id}>{p.numero} — {p.description}</option>)}
              </select>
            </div>
            <div>
              <label htmlFor={`${id}-categorie`} className={etiquette}>{tr.productCategory}</label>
              <select id={`${id}-categorie`} value={saisie.categorie_produit} onChange={e => setSaisie({ ...saisie, categorie_produit: e.target.value })} className={champ}>
                <option value="">{tr.allProductCategories}</option>
                {categories.map(c => <option key={c} value={c}>{c}</option>)}
              </select>
            </div>
          </fieldset>
          <fieldset className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <legend className="text-xs uppercase tracking-wide text-gray-500 mb-1">{tr.conditions}</legend>
            <div>
              <label htmlFor={`${id}-quantite`} className={etiquette}>{tr.minQuantity}</label>
              <input id={`${id}-quantite`} type="number" min="1" step="1" value={saisie.quantite_min} onChange={e => setSaisie({ ...saisie, quantite_min: e.target.value })} className={champ} />
            </div>
            <div>
              <label htmlFor={`${id}-du`} className={etiquette}>{tr.from}</label>
              <input id={`${id}-du`} type="date" value={saisie.date_debut} onChange={e => setSaisie({ ...saisie, date_debut: e.target.value })} className={champ} />
            </div>
            <div>
              <label htmlFor={`${id}-au`} className={etiquette}>{tr.to}</label>
              <input id={`${id}-au`} type="date" value={saisie.date_fin} min={saisie.date_debut || undefined} onChange={e => setSaisie({ ...saisie, date_fin: e.target.value })} className={champ} />
            </div>
          </fieldset>
          <fieldset className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <legend className="text-xs uppercase tracking-wide text-gray-500 mb-1">{tr.effect}</legend>
            <div>
              <label htmlFor={`${id}-type`} className={etiquette}>{tr.type}</label>
              <select id={`${id}-type`} value={saisie.type} onChange={e => setSaisie({ ...saisie, type: e.target.value as TypeReglePrix })} className={champ}>
                {Object.entries(tr.types).map(([cle, libelle]) => <option key={cle} value={cle}>{libelle}</option>)}
              </select>
            </div>
            <div>
              <label htmlFor={`${id}-valeur`} className={etiquette}>{tr.value} * {saisie.type === 'remise_pourcentage' ? '(%)' : devise && `(${devise})`}</label>
              <input id={`${id}-valeur`} required type="number" min="0" step="0.01" max={saisie.type === 'remise_pourcentage' ? 100 : undefined}
                value={saisie.valeur} onChange={e => setSaisie({ ...saisie, valeur: e.target.value })} className={champ} />
            </div>
            <label className="flex items-center gap-2 text-sm dark:text-gray-300 self-end pb-2">
              <input type="checkbox" checked={saisie.actif} onChange={e => setSaisie({ ...saisie, actif: e.target.checked })} /> {tr.active}
            </label>
          </fieldset>
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setSaisie(null)} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-white">{tp.cancel}</button>
            <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{tp.save}</button>
          </div>
        </form>
      )}

      {regles && (regles.length === 0 ? <p className="text-sm text-gray-500 dark:text-gray-400">{tr.empty}</p> : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
              <tr>
                <th className="px-3 py-2 text-left font-medium">{tr.label}</th>
                <th className="px-3 py-2 text-left font-medium">{tr.scope}</th>
                <th className="px-3 py-2 text-left font-medium">{tr.conditions}</th>
                <th className="px-3 py-2 text-right font-medium">{tr.effect}</th>
                <th className="px-3 py-2 text-center font-medium">{tr.status}</th>
                {modifiable && <th className="px-3 py-2"><span className="sr-only">{tp.edit}</span></th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {regles.map(r => {
                const statut = statutDe(r);
                return (
                  <tr key={r.id} className="text-gray-800 dark:text-gray-200">
                    <td className="px-3 py-2 font-medium">{r.libelle}</td>
                    <td className="px-3 py-2">{portee(r)}</td>
                    <td className="px-3 py-2 text-gray-600 dark:text-gray-300">{conditions(r)}</td>
                    <td className="px-3 py-2 text-right whitespace-nowrap">{effet(r)}</td>
                    <td className="px-3 py-2 text-center"><span className={`px-2 py-0.5 rounded-full text-xs ${couleurs[statut]}`}>{tr.statuses[statut]}</span></td>
                    {modifiable && (
                      <td className="px-3 py-2">
                        <div className="flex justify-end gap-1">
                          <button onClick={() => setSaisie(versSaisie(r))} aria-label={`${tp.edit} — ${r.libelle}`} className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-gray-700 rounded"><Pencil size={16} /></button>
                          <button onClick={() => { if (confirm(tp.confirmDelete.replace('{nom}', r.libelle))) executer(() => supprimerReglePrix(r.id)); }}
                            aria-label={`${tp.delete} — ${r.libelle}`} className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-gray-700 rounded"><Trash2 size={16} /></button>
                        </div>
                      </td>
                    )}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      ))}
    </div>
  );
}
