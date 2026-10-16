import { useEffect, useId, useState } from 'react';
import { calculerPrix, getClientsActifs, getProduits, type Client, type PrixCalcule } from '@/services/api';
import type { Produit } from '@/types';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { formatPrix } from './format';

/** Simulateur : prix qu'obtiendrait un client, avec le détail des prix comparés. */
export default function SimulateurPrix({ devise }: { devise: string }) {
  const { t, language } = useLanguage();
  const tp = t.pricing;
  const ts = tp.simulator;
  const id = useId();
  const [clients, setClients] = useState<Client[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [saisie, setSaisie] = useState({ client_id: '', produit_id: '', quantite: '1', date: new Date().toLocaleDateString('sv-SE') });
  const [resultat, setResultat] = useState<PrixCalcule | null>(null);
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    let actif = true;
    Promise.all([getClientsActifs(), getProduits()])
      .then(([c, p]) => { if (actif) { setClients(c); setProduits(p); } })
      .catch(e => { if (actif) setErreur(e instanceof ApiError ? e.message : tp.error); });
    return () => { actif = false; };
  }, [tp.error]);

  async function calculer(e: React.FormEvent) {
    e.preventDefault();
    try {
      const [ligne] = await calculerPrix({
        client_id: saisie.client_id ? Number(saisie.client_id) : undefined,
        date: saisie.date || undefined,
        lignes: [{ produit_id: Number(saisie.produit_id), quantite: Number(saisie.quantite) || 1 }],
      });
      setResultat(ligne);
      setErreur('');
    } catch (err) {
      setErreur(err instanceof ApiError ? err.message : tp.error);
    }
  }

  const prix = (v: number | null) => formatPrix(v, devise, language);
  const estRetenu = (c: PrixCalcule['candidats'][number], r: PrixCalcule) =>
    c.prix === r.prix && c.origine === r.origine && (c.regle_id ?? null) === (r.regle?.id ?? null);
  const retenu = resultat ? resultat.candidats.find(c => estRetenu(c, resultat)) : undefined;
  const origine = (o: PrixCalcule['candidats'][number]) => (ts.origins[o.origine] ?? o.origine).replace('{libelle}', o.libelle ?? '');
  const champ = 'w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
  const etiquette = 'block text-sm font-medium mb-1 dark:text-gray-300';

  return (
    <div className="space-y-5">
      <p className="text-sm text-gray-500 dark:text-gray-400">{ts.help}</p>
      <form onSubmit={calculer} className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
        <div className="lg:col-span-2">
          <label htmlFor={`${id}-client`} className={etiquette}>{ts.client}</label>
          <select id={`${id}-client`} value={saisie.client_id} onChange={e => setSaisie({ ...saisie, client_id: e.target.value })} className={champ}>
            <option value="">{ts.noClient}</option>
            {clients.map(c => <option key={c.id} value={c.id}>{c.code} — {c.raison_sociale}</option>)}
          </select>
        </div>
        <div className="lg:col-span-2">
          <label htmlFor={`${id}-produit`} className={etiquette}>{ts.product} *</label>
          <select id={`${id}-produit`} required value={saisie.produit_id} onChange={e => setSaisie({ ...saisie, produit_id: e.target.value })} className={champ}>
            <option value="">{ts.chooseProduct}</option>
            {produits.map(p => <option key={p.id} value={p.id}>{p.numero} — {p.description}</option>)}
          </select>
        </div>
        <div>
          <label htmlFor={`${id}-quantite`} className={etiquette}>{ts.quantity}</label>
          <input id={`${id}-quantite`} type="number" min="1" step="1" value={saisie.quantite} onChange={e => setSaisie({ ...saisie, quantite: e.target.value })} className={champ} />
        </div>
        <div>
          <label htmlFor={`${id}-date`} className={etiquette}>{ts.date}</label>
          <input id={`${id}-date`} type="date" value={saisie.date} onChange={e => setSaisie({ ...saisie, date: e.target.value })} className={champ} />
        </div>
        <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{ts.compute}</button>
      </form>

      {erreur && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      {resultat && (
        <section aria-live="polite" className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div className="p-4 rounded-lg border-2 border-purple-300 dark:border-purple-700">
            <p className="text-xs uppercase tracking-wide text-gray-500">{ts.result}</p>
            {resultat.prix === null ? <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{ts.noPrice}</p> : (
              <>
                <p className="mt-1 text-3xl font-bold text-gray-900 dark:text-white">{prix(resultat.prix)}</p>
                {retenu && <p className="mt-1 text-sm text-purple-700 dark:text-purple-300">{origine(retenu)}</p>}
              </>
            )}
            <dl className="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-300">
              <div className="flex justify-between"><dt>{ts.basePrice}</dt><dd>{prix(resultat.prix_base)}</dd></div>
              {resultat.liste && <div className="flex justify-between"><dt>{ts.listPrice} ({resultat.liste.nom})</dt><dd>{prix(resultat.prix_liste)}</dd></div>}
              {resultat.remise_pourcentage > 0 && (
                <div className="flex justify-between"><dt>{ts.discount}</dt><dd>{new Intl.NumberFormat(language === 'en' ? 'en-US' : 'fr-FR').format(resultat.remise_pourcentage)} %</dd></div>
              )}
            </dl>
          </div>
          <div className="lg:col-span-2">
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">{ts.candidates}</h3>
            <ul className="divide-y divide-gray-200 dark:divide-gray-700 border rounded-lg dark:border-gray-700">
              {resultat.candidats.map((c, index) => {
                const choisi = c === retenu;
                return (
                  <li key={index} className={`flex items-center justify-between gap-3 px-3 py-2 text-sm ${choisi ? 'bg-purple-50 dark:bg-purple-900/20 font-medium' : 'text-gray-600 dark:text-gray-300'}`}>
                    <span>{origine(c)}{choisi && <span className="ml-2 px-1.5 py-0.5 rounded text-[11px] bg-purple-600 text-white">{ts.retained}</span>}</span>
                    <span className="whitespace-nowrap">{prix(c.prix)}</span>
                  </li>
                );
              })}
            </ul>
          </div>
        </section>
      )}
    </div>
  );
}
