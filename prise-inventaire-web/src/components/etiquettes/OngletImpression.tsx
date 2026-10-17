import { useEffect, useMemo, useState } from 'react';
import { Eraser, Printer, Trash2 } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { getGroupesTaxes, getListesPrix, getProduits, type GroupeTaxe, type ListePrix } from '@/services/api';
import { imprimerEtiquettes, parPage, type CatalogueEtiquettes, type FormatEtiquette } from '@/services/etiquettes';
import type { Produit } from '@/types';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

type ProduitEtiquette = Produit & { id: number };

interface Ligne {
  cle: number;
  produit: ProduitEtiquette;
  /** Vide : le code principal, sinon le numéro du produit. */
  codeBarreId: number | null;
  copies: string;
}

/** Texte comparable : minuscules, sans accents. */
function simplifier(texte: string): string {
  return texte.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
}

let prochaineCle = 1;
const nouvelleLigne = (produit: ProduitEtiquette, codeBarreId: number | null = null): Ligne => ({ cle: prochaineCle++, produit, codeBarreId, copies: '1' });

/**
 * Impression des étiquettes : le format (et, s'il porte un prix, la liste de prix et le groupe de taxes), la première
 * étiquette libre d'une planche entamée, puis les produits (un par un, par recherche ou lecture d'un code-barres, ou
 * tout un secteur), le code imprimé et le nombre d'exemplaires de chacun ; le PDF s'ouvre dans un nouvel onglet.
 */
export default function OngletImpression({ catalogue, produitsInitiaux }: {
  catalogue: CatalogueEtiquettes;
  /** Ids séparés par des virgules (lien « Étiquettes » de la liste des produits). */
  produitsInitiaux: string;
}) {
  const { t, language } = useLanguage();
  const tl = t.labels;
  const [produits, setProduits] = useState<ProduitEtiquette[] | null>(null);
  const [listes, setListes] = useState<ListePrix[]>([]);
  const [groupes, setGroupes] = useState<GroupeTaxe[]>([]);
  const [formatId, setFormatId] = useState(0);
  const [listePrixId, setListePrixId] = useState('');
  const [groupeTaxeId, setGroupeTaxeId] = useState('');
  const [depart, setDepart] = useState(1);
  const [contours, setContours] = useState(false);
  const [lignes, setLignes] = useState<Ligne[]>([]);
  const [recherche, setRecherche] = useState('');
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    getProduits().then(liste => {
      if (!actif) return;
      const avecId = liste.filter((p): p is ProduitEtiquette => typeof p.id === 'number');
      setProduits(avecId);
      // Venus de la liste des produits : ils sont déjà dans la liste à imprimer.
      const ids = produitsInitiaux.split(',').map(Number).filter(Boolean);
      setLignes(l => (l.length > 0 ? l : ids.flatMap(id => avecId.filter(p => p.id === id).map(p => nouvelleLigne(p)))));
    }, e => { if (actif) setErreur(messageErreur(e, tl.error)); });
    // Listes de prix et groupes de taxes : facultatifs (module des ventes désactivé, droits).
    getListesPrix().then(l => { if (actif) setListes(l.filter(liste => liste.actif)); }, () => undefined);
    getGroupesTaxes().then(g => { if (actif) setGroupes(g.filter(groupe => groupe.actif)); }, () => undefined);
    return () => { actif = false; };
  }, [produitsInitiaux, tl.error]);

  const formats = catalogue.formats.filter(f => f.actif);
  const format: FormatEtiquette | undefined = formats.find(f => f.id === formatId) ?? formats.find(f => f.par_defaut) ?? formats[0];
  const avecPrix = format?.contenu.includes('prix') ?? false;
  const avecTaxes = format?.contenu.includes('prix_taxes') ?? false;
  const planche = format?.support === 'planche';
  const parFeuille = format ? parPage(format) : 1;
  const premiere = planche ? Math.min(depart, parFeuille) : 1;
  const total = lignes.reduce((somme, l) => somme + (Number(l.copies) || 0), 0);
  const pages = total === 0 ? 0 : Math.ceil((premiere - 1 + total) / parFeuille);
  const trop = total > catalogue.maximum;
  const secteurs = useMemo(() => {
    const vus = new Map<number, string>();
    (produits ?? []).forEach(p => { if (p.secteur?.id) vus.set(p.secteur.id, p.secteur.nom); });
    return [...vus].sort((a, b) => a[1].localeCompare(b[1]));
  }, [produits]);

  const termes = simplifier(recherche.trim());
  const trouves = termes === '' || !produits ? [] : produits.filter(p => simplifier(`${p.numero} ${p.description}`).includes(termes)
    || (p.codes_barres ?? []).some(c => simplifier(c.code).startsWith(termes))).slice(0, 8);

  /** Ajoute un produit (ou un exemplaire de plus s'il est déjà là avec le même code). */
  function ajouter(produit: ProduitEtiquette, codeBarreId: number | null = null) {
    setLignes(l => {
      const deja = l.find(ligne => ligne.produit.id === produit.id && ligne.codeBarreId === codeBarreId);
      return deja
        ? l.map(ligne => (ligne === deja ? { ...ligne, copies: String((Number(ligne.copies) || 0) + 1) } : ligne))
        : [...l, nouvelleLigne(produit, codeBarreId)];
    });
    setRecherche('');
  }

  /** Entrée : le produit du code lu (ce code-là est imprimé), sinon le premier trouvé. */
  function valider(e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key !== 'Enter' || !produits) return;
    e.preventDefault();
    const lu = recherche.trim();
    const parCode = produits.find(p => (p.codes_barres ?? []).some(c => c.code === lu));
    if (parCode) {
      const code = (parCode.codes_barres ?? []).find(c => c.code === lu);
      ajouter(parCode, code && !code.principal ? code.id : null);
      return;
    }
    const parNumero = produits.find(p => p.numero === lu) ?? trouves[0];
    if (parNumero) ajouter(parNumero);
  }

  function ajouterSecteur(id: number) {
    const nouveaux = (produits ?? []).filter(p => p.secteur?.id === id);
    setLignes(l => [...l, ...nouveaux.filter(p => !l.some(ligne => ligne.produit.id === p.id)).map(p => nouvelleLigne(p))]);
  }

  async function imprimer() {
    if (!format) return;
    // Onglet ouvert pendant le clic (sinon bloqué comme fenêtre surgissante), rempli quand le PDF arrive.
    const fenetre = window.open('', '_blank');
    setOccupe(true);
    setErreur('');
    try {
      const pdf = await imprimerEtiquettes({
        format_id: format.id,
        lignes: lignes.map(l => ({ produit_id: l.produit.id, code_barre_id: l.codeBarreId, copies: Number(l.copies) || 1 })),
        liste_prix_id: avecPrix && listePrixId ? Number(listePrixId) : null,
        groupe_taxe_id: avecTaxes && groupeTaxeId ? Number(groupeTaxeId) : null,
        depart: planche ? premiere : null,
        langue: language === 'en' ? 'en' : 'fr',
        contours: planche && contours,
      });
      const url = URL.createObjectURL(pdf);
      if (fenetre) {
        fenetre.location.href = url;
      } else {
        const lien = document.createElement('a');
        lien.href = url;
        lien.download = 'etiquettes.pdf';
        lien.click();
      }
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (e) {
      fenetre?.close();
      setErreur(messageErreur(e, tl.error));
    } finally {
      setOccupe(false);
    }
  }

  if (!format) return <p className="text-sm text-gray-500">{tl.loading}</p>;

  return (
    <div className="space-y-4">
      {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow p-4 flex flex-wrap gap-4 items-start">
        <div className="space-y-3 flex-1 min-w-[260px]">
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tl.format}
            <select value={format.id} onChange={e => { setFormatId(Number(e.target.value)); setDepart(1); }} className={`${classeChamp} block w-full`}>
              {formats.map(f => <option key={f.id} value={f.id}>{f.nom}</option>)}
            </select>
          </label>
          {avecPrix && (
            <label className="block text-xs text-gray-500 dark:text-gray-400">{tl.priceList}
              <select value={listePrixId} onChange={e => setListePrixId(e.target.value)} className={`${classeChamp} block w-full`}>
                <option value="">{tl.basePrice}</option>
                {listes.map(l => <option key={l.id} value={l.id}>{l.nom}</option>)}
              </select>
            </label>
          )}
          {avecTaxes && (
            <label className="block text-xs text-gray-500 dark:text-gray-400">{tl.taxGroup}
              <select value={groupeTaxeId} onChange={e => setGroupeTaxeId(e.target.value)} className={`${classeChamp} block w-full`}>
                <option value="">{tl.defaultTaxGroup}</option>
                {groupes.map(g => <option key={g.id} value={g.id}>{g.nom}</option>)}
              </select>
            </label>
          )}
          {planche && (
            <label className="flex items-center gap-2 text-sm dark:text-gray-200">
              <input type="checkbox" checked={contours} onChange={e => setContours(e.target.checked)} /> {tl.outlines}
            </label>
          )}
        </div>
        {planche && parFeuille <= 200 && (
          <fieldset className="space-y-1">
            <legend className="text-xs text-gray-500 dark:text-gray-400">{tl.start}</legend>
            <div className="grid gap-0.5 w-48 p-1 bg-gray-100 dark:bg-gray-900 rounded" style={{ gridTemplateColumns: `repeat(${format.colonnes}, minmax(0, 1fr))` }}>
              {Array.from({ length: parFeuille }, (_, i) => i + 1).map(position => (
                <button key={position} type="button" onClick={() => setDepart(position)} aria-pressed={position === premiere}
                  aria-label={tl.position.replace('{n}', String(position))} title={tl.position.replace('{n}', String(position))}
                  style={{ aspectRatio: `${format.largeur_mm} / ${format.hauteur_mm}` }}
                  className={`rounded-sm border text-[9px] leading-none ${position < premiere ? 'bg-gray-300 border-gray-300 dark:bg-gray-700 dark:border-gray-700'
                    : position === premiere ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-gray-300 dark:bg-gray-800 dark:border-gray-600'}`}>
                  {position === premiere ? position : ''}
                </button>
              ))}
            </div>
            <p className="text-xs text-gray-500 max-w-[12rem]">{tl.startHelp}</p>
          </fieldset>
        )}
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-xl shadow p-4 space-y-3">
        <div className="flex flex-wrap gap-3 items-end">
          <div className="relative flex-1 min-w-[260px]">
            <label className="block text-xs text-gray-500 dark:text-gray-400">{tl.addProduct}
              <input type="search" value={recherche} onChange={e => setRecherche(e.target.value)} onKeyDown={valider} placeholder={tl.searchProduct}
                disabled={!produits} className={`${classeChamp} block w-full`} autoComplete="off" aria-controls="etiquettes-produits-trouves" />
            </label>
            {termes !== '' && produits && (
              <ul id="etiquettes-produits-trouves" role="listbox" aria-label={tl.results} className="absolute z-10 mt-1 w-full bg-white dark:bg-gray-800 border dark:border-gray-600 rounded-lg shadow-lg max-h-72 overflow-y-auto">
                {trouves.length === 0 && <li className="px-3 py-2 text-sm text-gray-500">{tl.noMatch}</li>}
                {trouves.map(p => (
                  <li key={p.id} role="option" aria-selected={false}>
                    <button type="button" onClick={() => ajouter(p)} className="w-full text-left px-3 py-2 text-sm hover:bg-blue-50 dark:hover:bg-gray-700 dark:text-gray-200">
                      <span className="font-mono">{p.numero}</span> — {p.description}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
          {secteurs.length > 0 && (
            <label className="text-xs text-gray-500 dark:text-gray-400">{tl.addSector}
              <select value="" onChange={e => { if (e.target.value) ajouterSecteur(Number(e.target.value)); }} className={`${classeChamp} block`}>
                <option value="">{tl.chooseSector}</option>
                {secteurs.map(([id, nom]) => <option key={id} value={id}>{nom}</option>)}
              </select>
            </label>
          )}
        </div>

        {lignes.length === 0 ? <p className="text-sm text-gray-500 py-4">{tl.empty}</p> : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm" aria-label={tl.tabs.imprimer}>
              <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                <tr>
                  <th className="px-3 py-2 text-left">{tl.product}</th>
                  <th className="px-3 py-2 text-left">{tl.code}</th>
                  <th className="px-3 py-2 text-right">{tl.copies}</th>
                  <th className="px-3 py-2" />
                </tr>
              </thead>
              <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                {lignes.map(l => {
                  const codes = l.produit.codes_barres ?? [];
                  const principal = codes.find(c => c.principal) ?? codes[0];
                  return (
                    <tr key={l.cle}>
                      <td className="px-3 py-2"><span className="font-mono">{l.produit.numero}</span> — {l.produit.description}</td>
                      <td className="px-3 py-2">
                        <select value={l.codeBarreId ?? ''} aria-label={`${tl.code} ${l.produit.numero}`}
                          onChange={e => setLignes(ls => ls.map(x => (x.cle === l.cle ? { ...x, codeBarreId: e.target.value ? Number(e.target.value) : null } : x)))}
                          className={`${classeChamp} font-mono`}>
                          <option value="">{principal ? `${tl.mainCode} — ${principal.code}` : `${tl.productNumber} — ${l.produit.numero}`}</option>
                          {codes.filter(c => c !== principal).map(c => (
                            <option key={c.id} value={c.id}>{c.code}{Number(c.quantite) !== 1 ? ` (×${Number(c.quantite)})` : ''}</option>
                          ))}
                        </select>
                      </td>
                      <td className="px-3 py-2 text-right">
                        <input type="number" min={1} max={catalogue.maximum} value={l.copies} aria-label={`${tl.copies} ${l.produit.numero}`}
                          onChange={e => setLignes(ls => ls.map(x => (x.cle === l.cle ? { ...x, copies: e.target.value } : x)))}
                          className={`${classeChamp} w-24 text-right`} />
                      </td>
                      <td className="px-3 py-2 text-right">
                        <button type="button" onClick={() => setLignes(ls => ls.filter(x => x.cle !== l.cle))} aria-label={`${tl.remove} ${l.produit.numero}`}
                          className="p-1 rounded text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30"><Trash2 size={16} /></button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        <div className="flex flex-wrap items-center justify-between gap-3 border-t dark:border-gray-700 pt-3">
          <p className="text-sm text-gray-600 dark:text-gray-300" aria-live="polite">
            {tl.total.replace('{n}', String(total))} · {tl.pages.replace('{n}', String(pages))}
            {trop && <span className="ml-2 text-red-600">{tl.tooMany.replace('{max}', String(catalogue.maximum))}</span>}
          </p>
          <div className="flex gap-2">
            {lignes.length > 0 && (
              <button type="button" onClick={() => setLignes([])} className="flex items-center gap-1 px-3 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-gray-200">
                <Eraser size={16} /> {tl.clear}
              </button>
            )}
            <button type="button" onClick={imprimer} disabled={occupe || total === 0 || trop}
              className="flex items-center gap-1 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-40">
              <Printer size={16} /> {occupe ? tl.printing : tl.print}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
