import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Check, PackageSearch, Search, ShoppingCart } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import type { PageCatalogue, ProduitCatalogue } from '../api';
import { useDonnees, usePortail } from '../contexte';
import { ChampQuantite, Chargement, Message, Pagination } from '../composants';
import { montant, remplir } from '../format';

/** Catalogue : produits proposés par l'entreprise, au prix du client. */
export default function CataloguePortail() {
  const { t, language } = useLanguage();
  const tp = t.portal;
  const { appel, moi, panier, ajouterAuPanier } = usePortail();
  const [saisie, setSaisie] = useState('');
  const [recherche, setRecherche] = useState('');
  const [categorie, setCategorie] = useState('');
  const [page, setPage] = useState(1);
  const [quantites, setQuantites] = useState<Record<number, number>>({});
  // Nombre d'ajouts par produit : le champ de quantité repart à 1 après chaque ajout.
  const [ajouts, setAjouts] = useState<Record<number, number>>({});
  const [ajoute, setAjoute] = useState<number | null>(null);

  // Recherche lancée une fois la saisie posée.
  useEffect(() => {
    const minuterie = setTimeout(() => {
      setRecherche(saisie.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(minuterie);
  }, [saisie]);

  const charger = useCallback(() => {
    const filtres = new URLSearchParams({ page: String(page) });
    if (recherche) filtres.set('recherche', recherche);
    if (categorie) filtres.set('categorie', categorie);
    return appel<PageCatalogue>(`/espace-client/catalogue?${filtres}`);
  }, [appel, page, recherche, categorie]);
  const { donnees, erreur, chargement } = useDonnees(charger);

  const auPanier = useMemo(() => new Map(panier.map(a => [a.produit_id, a.quantite])), [panier]);
  const devise = moi?.entreprise.devise;
  const filtre = recherche !== '' || categorie !== '';

  const ajouter = (produit: ProduitCatalogue) => {
    ajouterAuPanier({ produit_id: produit.id, numero: produit.numero, description: produit.description, mesure: produit.mesure, prix: produit.prix }, quantites[produit.id] ?? 1);
    setQuantites(actuelles => {
      const suite = { ...actuelles };
      delete suite[produit.id];
      return suite;
    });
    setAjouts(a => ({ ...a, [produit.id]: (a[produit.id] ?? 0) + 1 }));
    setAjoute(produit.id);
    setTimeout(() => setAjoute(a => (a === produit.id ? null : a)), 1500);
  };

  return (
    <div className="pb-16">
      <div className="mb-5">
        <h1 className="text-2xl font-bold">{moi ? remplir(tp.hello, { nom: moi.compte.nom }) : tp.nav.catalogue}</h1>
        {moi && <p className="text-sm text-gray-500 mt-1">{remplir(tp.pricesFor, { client: moi.client.raison_sociale })}</p>}
      </div>

      <div className="flex flex-col sm:flex-row gap-2 mb-5">
        <label className="relative flex-1">
          <span className="sr-only">{tp.search}</span>
          <Search size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input type="search" value={saisie} onChange={e => setSaisie(e.target.value)} placeholder={tp.search}
            className="w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
        </label>
        {donnees && donnees.categories.length > 0 && (
          <select value={categorie} onChange={e => { setCategorie(e.target.value); setPage(1); }} aria-label={tp.allCategories}
            className="sm:w-60 px-3 py-2.5 border border-gray-300 rounded-lg bg-white">
            <option value="">{tp.allCategories}</option>
            {donnees.categories.map(c => <option key={c} value={c}>{c}</option>)}
          </select>
        )}
      </div>

      {erreur !== undefined && <div className="mb-4"><Message type="erreur">{messageErreur(erreur, tp.error)}</Message></div>}

      {!donnees ? (
        chargement && <Chargement />
      ) : donnees.data.length === 0 ? (
        <div className="text-center py-16 text-gray-500">
          <PackageSearch size={40} className="mx-auto mb-3 text-gray-300" />
          <p>{filtre ? tp.noResults : tp.noProducts}</p>
        </div>
      ) : (
        <>
          <p className="text-xs text-gray-500 mb-3">{remplir(tp.productsCount, { n: donnees.total })}</p>
          <div className={`grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 ${chargement ? 'opacity-60' : ''}`}>
            {donnees.data.map(produit => {
              const dansPanier = auPanier.get(produit.id);
              const remise = produit.prix !== null && produit.prix_liste !== null && produit.prix_liste > produit.prix;
              return (
                <article key={produit.id} className="bg-white rounded-xl border border-gray-200 p-4 flex flex-col">
                  <p className="text-xs text-gray-400 truncate">{produit.numero}{produit.categorie ? ` · ${produit.categorie}` : ''}</p>
                  <h2 className="font-medium mt-1 leading-snug">{produit.description}</h2>
                  {produit.mesure && <p className="text-xs text-gray-500 mt-0.5">{remplir(tp.unit, { mesure: produit.mesure })}</p>}
                  <div className="mt-auto pt-3">
                    {produit.prix === null ? (
                      <p className="text-sm text-gray-500 italic">{tp.priceOnRequest}</p>
                    ) : (
                      <p className="flex flex-wrap items-baseline gap-x-2">
                        <span className="text-lg font-semibold">{montant(produit.prix, language, devise)}</span>
                        {remise && <span className="text-xs text-gray-400 line-through">{montant(produit.prix_liste, language, devise)}</span>}
                      </p>
                    )}
                    <div className="flex items-center gap-2 mt-2">
                      <ChampQuantite key={ajouts[produit.id] ?? 0} valeur={quantites[produit.id] ?? 1} desactive={produit.prix === null}
                        onChange={q => setQuantites(actuelles => ({ ...actuelles, [produit.id]: q }))} />
                      <button type="button" disabled={produit.prix === null} onClick={() => ajouter(produit)}
                        className={`flex-1 inline-flex items-center justify-center gap-1.5 py-2 rounded-lg text-sm font-medium text-white disabled:bg-gray-300 ${ajoute === produit.id ? 'bg-green-600' : 'bg-blue-600 hover:bg-blue-700'}`}>
                        {ajoute === produit.id ? <><Check size={16} /> {tp.added}</> : <><ShoppingCart size={16} /> {tp.add}</>}
                      </button>
                    </div>
                    {dansPanier !== undefined && <p className="text-xs text-blue-700 mt-1.5">{remplir(tp.inCart, { n: dansPanier })}</p>}
                  </div>
                </article>
              );
            })}
          </div>
          <Pagination page={donnees.current_page} pages={donnees.last_page} onChange={p => { setPage(p); window.scrollTo({ top: 0 }); }} />
        </>
      )}

      {panier.length > 0 && (
        <div className="fixed bottom-0 inset-x-0 z-20 bg-white border-t border-gray-200 shadow-[0_-2px_8px_rgba(0,0,0,0.05)]">
          <div className="max-w-6xl mx-auto px-4 py-2.5 flex items-center justify-between gap-3">
            <span className="text-sm text-gray-700">{remplir(tp.cartBar, { n: panier.length })}</span>
            <Link to="/panier" className="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">
              <ShoppingCart size={16} /> {tp.viewCart}
            </Link>
          </div>
        </div>
      )}
    </div>
  );
}
