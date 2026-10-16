import { useEffect, useId, useState } from 'react';
import { X } from 'lucide-react';
import { enregistrerGrillePrix, getGrillePrix, type GrillePrix as Grille } from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';

/** Clé d'une cellule modifiée : produit et liste (« base » pour le prix de base). */
const cle = (produitId: number, listeId: number | 'base') => `${produitId}:${listeId}`;

/** Grille de prix : prix de base et prix par liste, modifiés en place puis enregistrés ensemble. */
export default function GrillePrix({ modifiable }: { modifiable: boolean }) {
  const { t } = useLanguage();
  const tp = t.pricing;
  const tg = tp.grid;
  const id = useId();
  const [recherche, setRecherche] = useState('');
  const [filtres, setFiltres] = useState({ recherche: '', categorie: '' });
  const [page, setPage] = useState(1);
  const [version, setVersion] = useState(0);
  const [grille, setGrille] = useState<Grille | null>(null);
  const [modifs, setModifs] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ ok: boolean; texte: string } | null>(null);
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    if (recherche === filtres.recherche) return;
    const minuterie = setTimeout(() => { setFiltres(f => ({ ...f, recherche })); setPage(1); }, 300);
    return () => clearTimeout(minuterie);
  }, [recherche, filtres.recherche]);

  useEffect(() => {
    let actif = true;
    getGrillePrix({ ...filtres, page })
      .then(g => { if (actif) setGrille(g); })
      .catch(e => { if (actif) setMessage({ ok: false, texte: e instanceof ApiError ? e.message : tp.error }); });
    return () => { actif = false; };
  }, [filtres, page, version, tp.error]);

  const enAttente = Object.keys(modifs).length;

  async function enregistrer() {
    const modifications = Object.entries(modifs).map(([c, valeur]) => {
      const [produit, liste] = c.split(':');
      return { produit_id: Number(produit), liste_prix_id: liste === 'base' ? null : Number(liste), prix: valeur.trim() === '' ? null : Number(valeur.trim().replace(',', '.')) };
    });
    // Une saisie non numérique serait envoyée vide et effacerait le prix : on la refuse ici.
    if (modifications.some(m => m.prix !== null && (Number.isNaN(m.prix) || m.prix < 0))) {
      setMessage({ ok: false, texte: tg.invalid });
      return;
    }
    setOccupe(true);
    try {
      await enregistrerGrillePrix(modifications);
      setModifs({});
      setMessage({ ok: true, texte: tp.saved });
      setVersion(v => v + 1);
    } catch (e) {
      setMessage({ ok: false, texte: e instanceof ApiError ? e.message : tp.error });
    } finally {
      setOccupe(false);
    }
  }

  const saisir = (c: string, valeur: string) => setModifs(m => ({ ...m, [c]: valeur }));
  const champ = 'w-28 px-2 py-1 border rounded text-sm text-right dark:bg-gray-700 dark:border-gray-600 dark:text-white disabled:bg-transparent disabled:border-transparent';

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3">
        <div className="flex-1 min-w-[14rem]">
          <label htmlFor={`${id}-recherche`} className="block text-xs text-gray-500 mb-1">{tg.search}</label>
          <input id={`${id}-recherche`} type="search" value={recherche} onChange={e => setRecherche(e.target.value)}
            className="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
        </div>
        <div>
          <label htmlFor={`${id}-categorie`} className="block text-xs text-gray-500 mb-1">{tg.category}</label>
          <select id={`${id}-categorie`} value={filtres.categorie} onChange={e => { setFiltres({ ...filtres, categorie: e.target.value }); setPage(1); }}
            className="px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white">
            <option value="">{tg.allCategories}</option>
            {grille?.categories.map(c => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
        {modifiable && enAttente > 0 && (
          <div className="flex items-center gap-2 ml-auto">
            <span className="text-sm text-amber-700 dark:text-amber-400">{tg.pending.replace('{n}', String(enAttente))}</span>
            <button onClick={() => setModifs({})} className="px-3 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-white">{tp.cancel}</button>
            <button onClick={enregistrer} disabled={occupe} className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">{tp.save}</button>
          </div>
        )}
      </div>

      {message && <p className={`px-3 py-2 rounded-lg text-sm ${message.ok ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>{message.texte}</p>}
      <p className="text-xs text-gray-500 dark:text-gray-400">{grille && grille.listes.length === 0 ? tg.noLists : tg.help}</p>

      {grille && (grille.total === 0 ? <p className="text-sm text-gray-500">{tg.empty}</p> : (
        <>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                <tr>
                  <th className="px-3 py-2 text-left font-medium">{tg.product}</th>
                  <th className="px-3 py-2 text-right font-medium">{tg.basePrice}</th>
                  {grille.listes.map(l => <th key={l.id} className="px-3 py-2 text-right font-medium">{l.nom}</th>)}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {grille.data.map(p => {
                  const cBase = cle(p.id, 'base');
                  return (
                    <tr key={p.id} className={`text-gray-800 dark:text-gray-200 ${p.actif ? '' : 'opacity-60'}`}>
                      <td className="px-3 py-2">
                        <span className="font-mono text-xs text-gray-500 mr-2">{p.numero}</span>{p.description}
                        {p.categorie && <span className="ml-2 px-1.5 py-0.5 rounded text-[11px] bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">{p.categorie}</span>}
                      </td>
                      <td className="px-3 py-2 text-right">
                        <input aria-label={`${tg.basePrice} — ${p.numero}`} inputMode="decimal" disabled={!modifiable} className={`${champ} ${cBase in modifs ? 'border-amber-400' : ''}`}
                          value={modifs[cBase] ?? (p.prix_base ?? '').toString()} onChange={e => saisir(cBase, e.target.value)} />
                      </td>
                      {grille.listes.map(l => {
                        const c = cle(p.id, l.id);
                        const cellule = p.prix[l.id];
                        const valeur = modifs[c] ?? (cellule?.saisi ? String(cellule.prix ?? '') : '');
                        return (
                          <td key={l.id} className="px-3 py-2 text-right whitespace-nowrap">
                            <input aria-label={`${l.nom} — ${p.numero}`} inputMode="decimal" disabled={!modifiable}
                              placeholder={cellule?.formule != null ? String(cellule.formule) : ''} title={cellule?.saisi ? tg.typed : undefined}
                              className={`${champ} ${c in modifs ? 'border-amber-400' : cellule?.saisi ? 'font-semibold text-purple-700 dark:text-purple-300' : ''}`}
                              value={valeur} onChange={e => saisir(c, e.target.value)} />
                            {modifiable && valeur !== '' && (
                              <button onClick={() => saisir(c, '')} aria-label={`${tg.removeTyped} — ${l.nom} — ${p.numero}`} title={tg.removeTyped}
                                className="ml-1 p-0.5 text-gray-400 hover:text-red-600 align-middle"><X size={14} /></button>
                            )}
                          </td>
                        );
                      })}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {grille.last_page > 1 && (
            <div className="flex items-center justify-end gap-3 text-sm">
              <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="px-3 py-1 rounded border dark:border-gray-600 dark:text-white disabled:opacity-40">{tp.previous}</button>
              <span className="text-gray-600 dark:text-gray-300">{tp.page.replace('{page}', String(grille.current_page)).replace('{pages}', String(grille.last_page))}</span>
              <button disabled={page >= grille.last_page} onClick={() => setPage(p => p + 1)} className="px-3 py-1 rounded border dark:border-gray-600 dark:text-white disabled:opacity-40">{tp.next}</button>
            </div>
          )}
        </>
      ))}
    </div>
  );
}
