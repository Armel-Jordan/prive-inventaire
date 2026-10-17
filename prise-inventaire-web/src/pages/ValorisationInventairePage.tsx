import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Coins, Download, TriangleAlert } from 'lucide-react';
import { getSecteurs } from '@/services/api';
import { getValorisation, exporterValorisation, METHODES_VALORISATION, type MethodeValorisation, type Valorisation } from '@/services/valorisation';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { chargerEntrepots, libelleEntrepot, type EntrepotChoix } from '@/lib/entrepots';
import type { Secteur } from '@/types';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/** Valorisation de l'inventaire officiel : quantités des dernières prises validées × coût unitaire choisi. */
export default function ValorisationInventairePage() {
  const { t, language } = useLanguage();
  const tv = t.valuation;
  const locale = language === 'en' ? 'en-US' : 'fr-FR';

  const [methode, setMethode] = useState<MethodeValorisation>('cout_moyen');
  const [secteurId, setSecteurId] = useState('');
  // Multi-entrepôts : les secteurs d'un entrepôt.
  const [entrepotId, setEntrepotId] = useState('');
  const [entrepots, setEntrepots] = useState<EntrepotChoix[]>([]);
  const tw = t.warehouses;
  const [recherche, setRecherche] = useState('');
  const [secteurs, setSecteurs] = useState<Secteur[]>([]);
  // Résultat et critères qui l'ont produit : tant qu'ils diffèrent des critères affichés, le calcul est en cours.
  const [resultat, setResultat] = useState<{ cle: string; valorisation: Valorisation } | null>(null);
  const [refuse, setRefuse] = useState(false);
  const [erreur, setErreur] = useState('');
  const cle = `${methode}|${secteurId}|${entrepotId}`;
  const valorisation = resultat?.valorisation ?? null;
  const chargement = resultat?.cle !== cle;

  useEffect(() => {
    getSecteurs().then(setSecteurs).catch(() => {});
    chargerEntrepots().then(setEntrepots).catch(() => {});
  }, []);

  useEffect(() => {
    let actif = true;
    getValorisation(methode, secteurId ? Number(secteurId) : undefined, entrepotId ? Number(entrepotId) : undefined)
      .then(v => { if (actif) { setResultat({ cle: `${methode}|${secteurId}|${entrepotId}`, valorisation: v }); setErreur(''); } })
      .catch(e => {
        if (!actif) return;
        if (e instanceof ApiError && e.status === 403) setRefuse(true);
        else setErreur(messageErreur(e, tv.error));
      });
    return () => { actif = false; };
  }, [methode, secteurId, entrepotId, tv.error]);

  const nombre = (valeur: number, decimales = 2) => valeur.toLocaleString(locale, { minimumFractionDigits: decimales, maximumFractionDigits: decimales });
  const argent = (valeur: number) => `${nombre(valeur)}${valorisation?.devise ? ` ${valorisation.devise}` : ''}`;
  const quantite = (valeur: number) => valeur.toLocaleString(locale, { maximumFractionDigits: 4 });
  const produits = useMemo(() => {
    const terme = recherche.trim().toLowerCase();
    return (valorisation?.produits ?? []).filter(p => !terme || p.numero.toLowerCase().includes(terme) || (p.description ?? '').toLowerCase().includes(terme) || (p.categorie ?? '').toLowerCase().includes(terme));
  }, [valorisation, recherche]);

  if (refuse) {
    return (
      <div className="p-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2 mb-4"><Coins className="text-purple-600" /> {tv.title}</h1>
        <p className="px-4 py-3 rounded-lg text-sm bg-amber-50 text-amber-900 max-w-3xl">{tv.restricted}</p>
      </div>
    );
  }

  return (
    <div className="p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Coins className="text-purple-600" /> {tv.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tv.subtitle}</p>
        </div>
        {valorisation && valorisation.nb_produits > 0 && (
          <button onClick={() => exporterValorisation(methode, secteurId ? Number(secteurId) : undefined, entrepotId ? Number(entrepotId) : undefined).catch(e => setErreur(messageErreur(e, tv.error)))}
            className="flex items-center gap-2 px-3 py-2 text-sm border rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
            <Download size={16} /> {tv.export}
          </button>
        )}
      </div>

      <div className={`grid grid-cols-1 gap-2 mb-2 max-w-6xl ${entrepots.length > 0 ? 'sm:grid-cols-4' : 'sm:grid-cols-3'}`}>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tv.method}
          <select value={methode} onChange={e => setMethode(e.target.value as MethodeValorisation)} className={classeChamp}>
            {METHODES_VALORISATION.map(m => <option key={m} value={m}>{tv.methods[m]}</option>)}
          </select>
        </label>
        {entrepots.length > 0 && (
          <label className="text-xs text-gray-500 dark:text-gray-400">{tw.warehouse}
            {/* Un entrepôt : la liste des secteurs se limite aux siens. */}
            <select value={entrepotId} onChange={e => { setEntrepotId(e.target.value); setSecteurId(''); }} className={classeChamp}>
              <option value="">{tw.all}</option>
              {entrepots.map(e => <option key={e.id} value={String(e.id)}>{libelleEntrepot(e)}</option>)}
            </select>
          </label>
        )}
        <label className="text-xs text-gray-500 dark:text-gray-400">{tv.sector}
          <select value={secteurId} onChange={e => setSecteurId(e.target.value)} className={classeChamp}>
            <option value="">{tv.allSectors}</option>
            {secteurs.filter(s => !entrepotId || String(s.entrepot_id) === entrepotId).map(s => <option key={s.id} value={s.id}>{s.code} — {s.nom}</option>)}
          </select>
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400">{tv.search}
          <input value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={tv.searchHelp} className={classeChamp} />
        </label>
      </div>
      <p className="mb-4 text-xs text-gray-500 dark:text-gray-400 max-w-6xl">{tv.methodHelp[methode]}</p>

      {erreur && <p role="status" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 max-w-6xl">{erreur}</p>}

      {valorisation && valorisation.nb_produits === 0 && !chargement && (
        <p className="text-sm text-gray-600 dark:text-gray-300 max-w-3xl">
          {tv.empty} <Link to="/prises-inventaire" className="text-purple-600 hover:underline dark:text-purple-400">{tv.stocktakesLink}</Link>
        </p>
      )}

      {valorisation && valorisation.nb_produits > 0 && (
        <>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 max-w-6xl">
            <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
              <p className="text-xs uppercase text-gray-500 dark:text-gray-400">{tv.totalValue}</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-white">{argent(valorisation.total)}</p>
            </div>
            <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4">
              <p className="text-xs uppercase text-gray-500 dark:text-gray-400">{tv.valuedProducts}</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-white">{valorisation.nb_produits - valorisation.nb_sans_cout} / {valorisation.nb_produits}</p>
            </div>
            <div className={`rounded-lg shadow-md p-4 ${valorisation.nb_sans_cout ? 'bg-amber-50 text-amber-900' : 'bg-white dark:bg-gray-800'}`}>
              <p className="text-xs uppercase opacity-80 flex items-center gap-1">{valorisation.nb_sans_cout > 0 && <TriangleAlert size={14} />} {tv.withoutCost}</p>
              <p className="text-2xl font-bold">{valorisation.nb_sans_cout}</p>
              {valorisation.nb_sans_cout > 0 && <p className="text-xs mt-1">{tv.withoutCostHelp[methode === 'prix_vente' ? 'vente' : 'achat']}</p>}
            </div>
          </div>

          {(valorisation.entrepots ?? []).some(e => e.entrepot_id !== null) && (
            <>
              <h2 className="text-lg font-semibold text-gray-800 dark:text-white mb-2">{tw.byWarehouse}</h2>
              <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto max-w-6xl mb-6">
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 dark:bg-gray-700">
                    <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                      <th className="px-4 py-2 font-medium">{tw.warehouse}</th>
                      <th className="px-4 py-2 font-medium text-right">{tv.value}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(valorisation.entrepots ?? []).map(e => (
                      <tr key={e.entrepot_id ?? 'aucun'} className="border-t dark:border-gray-700">
                        <td className="px-4 py-2 text-gray-900 dark:text-white">
                          {e.entrepot ?? <span className="text-gray-500 dark:text-gray-400">{tw.none}</span>}
                          <span className="ml-2 text-xs text-gray-500 dark:text-gray-400">{tw.sectors.replace('{n}', String(e.nb_secteurs))}</span>
                        </td>
                        <td className="px-4 py-2 text-right whitespace-nowrap text-gray-900 dark:text-white">{argent(e.valeur)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}

          <h2 className="text-lg font-semibold text-gray-800 dark:text-white mb-2">{tv.bySector}</h2>
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto max-w-6xl mb-6">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 dark:bg-gray-700">
                <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                  <th className="px-4 py-2 font-medium">{tv.sector}</th>
                  <th className="px-4 py-2 font-medium">{tv.stocktake}</th>
                  <th className="px-4 py-2 font-medium text-right">{tv.products}</th>
                  <th className="px-4 py-2 font-medium text-right">{tv.value}</th>
                </tr>
              </thead>
              <tbody>
                {valorisation.secteurs.map(s => (
                  <tr key={s.secteur_id ?? s.secteur} className="border-t dark:border-gray-700">
                    <td className="px-4 py-2 font-mono text-gray-900 dark:text-white">
                      {s.secteur}
                      {s.entrepot && <span className="block font-sans text-xs text-gray-500 dark:text-gray-400">{s.entrepot}</span>}
                    </td>
                    <td className="px-4 py-2 text-gray-600 dark:text-gray-300">{s.prise ? `${s.prise.numero}${s.prise.date ? ` · ${new Date(s.prise.date).toLocaleDateString(locale)}` : ''}` : '—'}</td>
                    <td className="px-4 py-2 text-right text-gray-700 dark:text-gray-300">{s.nb_produits}{s.nb_sans_cout > 0 && <span className="text-amber-700"> ({tv.withoutCostShort.replace('{n}', String(s.nb_sans_cout))})</span>}</td>
                    <td className="px-4 py-2 text-right whitespace-nowrap text-gray-900 dark:text-white">{argent(s.valeur)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <h2 className="text-lg font-semibold text-gray-800 dark:text-white mb-2">{tv.byProduct}</h2>
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto max-w-6xl">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 dark:bg-gray-700">
                <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                  <th className="px-4 py-2 font-medium">{tv.product}</th>
                  <th className="px-4 py-2 font-medium">{tv.sectors}</th>
                  <th className="px-4 py-2 font-medium text-right">{tv.quantity}</th>
                  <th className="px-4 py-2 font-medium text-right">{tv.unitCost}</th>
                  <th className="px-4 py-2 font-medium text-right">{tv.value}</th>
                </tr>
              </thead>
              <tbody>
                {produits.length === 0 && <tr><td colSpan={5} className="px-4 py-6 text-center text-gray-500">{tv.noResult}</td></tr>}
                {produits.map(p => (
                  <tr key={p.numero} className={`border-t dark:border-gray-700 ${p.cout_unitaire === null ? 'bg-amber-50/60 dark:bg-amber-900/10' : ''}`}>
                    <td className="px-4 py-2">
                      <span className="font-mono text-gray-900 dark:text-white">{p.numero}</span> <span className="text-gray-700 dark:text-gray-300">{p.description}</span>
                      {p.categorie && <span className="block text-xs text-gray-500">{p.categorie}</span>}
                    </td>
                    <td className="px-4 py-2 text-gray-600 dark:text-gray-300">{p.secteurs.join(', ')}</td>
                    <td className="px-4 py-2 text-right text-gray-900 dark:text-white">{quantite(p.quantite)}</td>
                    <td className="px-4 py-2 text-right whitespace-nowrap">
                      {p.cout_unitaire !== null ? <span className="text-gray-900 dark:text-white">{nombre(p.cout_unitaire)}</span> : <span className="text-amber-700">{tv.noCost}</span>}
                      {p.reference && <span className="block text-xs text-gray-500">{p.reference}</span>}
                    </td>
                    <td className="px-4 py-2 text-right whitespace-nowrap text-gray-900 dark:text-white">{p.valeur !== null ? argent(p.valeur) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
}
