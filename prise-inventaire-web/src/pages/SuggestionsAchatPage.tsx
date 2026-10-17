import { Fragment, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Lightbulb, RefreshCw, ShoppingCart, Trash2 } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { formatDateDocument, formatMontantIso, useMontantDocument } from '@/lib/documents';
import { getFournisseursActifs, type Fournisseur } from '@/services/api';
import {
  accepterSuggestions, commanderSuggestions, genererSuggestions, getSuggestionsAchat, modifierSuggestion, retirerSuggestion,
  type SuggestionAchat,
} from '@/services/suggestionsAchat';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';

const classeChamp = 'px-2 py-1 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const nombre = (v: string | number | null | undefined) => (v === null || v === undefined ? '—' : String(Number(v)));

/**
 * Suggestions d'achat (plan de parité fripv2, phase 3.2) : PI propose les produits à commander — stock sous le
 * minimum, reliquats clients pas encore commandés, besoins signalés depuis un produit — par fournisseur ; les
 * suggestions acceptées deviennent des commandes fournisseur, une par fournisseur.
 */
export default function SuggestionsAchatPage() {
  const { t, language } = useLanguage();
  const ts = t.purchaseSuggestions;
  const { peut } = usePermissions();
  const gerer = peut('suggestions_achat', 'gerer');
  const montant = useMontantDocument();
  const { toasts, toast, dismiss } = useToast();
  const [onglet, setOnglet] = useState<'ouvertes' | 'commandees'>('ouvertes');
  const [options, setOptions] = useState({ inventaire: true, reliquats: true, avec_commandes: true, fournisseur_id: '' });
  const [fusionner, setFusionner] = useState(true);
  const [suggestions, setSuggestions] = useState<SuggestionAchat[] | null>(null);
  const [fournisseurs, setFournisseurs] = useState<Fournisseur[]>([]);
  const [saisies, setSaisies] = useState<Record<number, { quantite?: string; remarque?: string }>>({});
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);
  const [version, setVersion] = useState(0);

  useEffect(() => {
    let actif = true;
    getFournisseursActifs().then(f => { if (actif) setFournisseurs(f ?? []); }, () => {});
    return () => { actif = false; };
  }, []);

  useEffect(() => {
    let actif = true;
    getSuggestionsAchat(onglet).then(s => { if (actif) setSuggestions(s); }, e => { if (actif) setErreur(messageErreur(e, ts.error)); });
    return () => { actif = false; };
  }, [onglet, version, ts.error]);

  const recharger = () => { setSaisies({}); setVersion(v => v + 1); };
  // Par fournisseur (sans fournisseur en premier : il faut le choisir avant de commander).
  const groupes = useMemo(() => {
    const parFournisseur = new Map<string, SuggestionAchat[]>();
    for (const s of suggestions ?? []) {
      const cle = s.fournisseur ? `${s.fournisseur.code} — ${s.fournisseur.raison_sociale}` : '';
      parFournisseur.set(cle, [...(parFournisseur.get(cle) ?? []), s]);
    }
    return [...parFournisseur.entries()];
  }, [suggestions]);
  const acceptees = (suggestions ?? []).filter(s => s.statut === 'acceptee');

  async function agir(action: () => Promise<unknown>, message?: string) {
    setOccupe(true);
    setErreur('');
    try {
      const resultat = await action();
      const texte = message ?? (resultat && typeof resultat === 'object' && 'message' in resultat ? String((resultat as { message: unknown }).message) : '');
      if (texte) toast(texte);
      recharger();
    } catch (e) {
      setErreur(messageErreur(e, ts.error));
    } finally {
      setOccupe(false);
    }
  }

  const enregistrer = (s: SuggestionAchat, champ: 'quantite' | 'remarque') => {
    const valeur = saisies[s.id]?.[champ];
    if (valeur === undefined) return;
    if (champ === 'quantite' && (!Number(valeur) || Number(valeur) === (s.quantite ?? s.quantite_suggeree))) return;
    if (champ === 'remarque' && valeur === (s.remarque ?? '')) return;
    void agir(() => modifierSuggestion(s.id, champ === 'quantite' ? { quantite: Number(valeur) } : { remarque: valeur.trim() || null }), '');
  };

  const source = (s: SuggestionAchat) => {
    if (s.source === 'reliquat') {
      return (
        <span className="text-xs">
          <span className="px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-800">{ts.sources.reliquat}</span>
          <span className="block text-gray-500">{s.reliquat?.client?.raison_sociale}{s.reliquat?.commande_client && ` — ${s.reliquat.commande_client.numero}`}{s.reliquat?.date_requise && ` — ${formatDateDocument(s.reliquat.date_requise)}`}</span>
        </span>
      );
    }
    return <span className={`px-1.5 py-0.5 rounded text-xs ${s.source === 'manuelle' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700'}`}>{ts.sources[s.source]}</span>;
  };

  return (
    <div className="p-4 sm:p-6 space-y-4">
      <div>
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Lightbulb className="text-amber-500" /> {ts.title}</h1>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{ts.subtitle}</p>
      </div>
      {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      {gerer && (
        <section className="bg-white dark:bg-gray-800 rounded-xl shadow p-4 flex flex-wrap items-end gap-4" aria-label={ts.compute}>
          <label className="flex items-center gap-2 text-sm dark:text-gray-200"><input type="checkbox" checked={options.inventaire} onChange={e => setOptions({ ...options, inventaire: e.target.checked })} /> {ts.forStock}</label>
          <label className="flex items-center gap-2 text-sm dark:text-gray-200"><input type="checkbox" checked={options.reliquats} onChange={e => setOptions({ ...options, reliquats: e.target.checked })} /> {ts.forBackOrders}</label>
          <label className="flex items-center gap-2 text-sm dark:text-gray-200"><input type="checkbox" checked={options.avec_commandes} onChange={e => setOptions({ ...options, avec_commandes: e.target.checked })} /> {ts.withOrders}</label>
          <label className="block text-xs text-gray-500 dark:text-gray-400 min-w-[14rem]">{ts.supplier}
            <select value={options.fournisseur_id} onChange={e => setOptions({ ...options, fournisseur_id: e.target.value })} className={`${classeChamp} w-full py-2`}>
              <option value="">{ts.allSuppliers}</option>
              {fournisseurs.map(f => <option key={f.id} value={f.id}>{f.code} — {f.raison_sociale}</option>)}
            </select>
          </label>
          <button type="button" disabled={occupe} onClick={() => { void agir(() => genererSuggestions({ ...options, fournisseur_id: options.fournisseur_id ? Number(options.fournisseur_id) : undefined })); }}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-amber-600 text-white text-sm hover:bg-amber-700 disabled:opacity-50">
            <RefreshCw size={16} /> {ts.compute}
          </button>
          <p className="text-xs text-gray-500 dark:text-gray-400 w-full">{ts.computeHelp}</p>
        </section>
      )}

      <div className="flex gap-1" role="tablist" aria-label={ts.title}>
        {(['ouvertes', 'commandees'] as const).map(o => (
          <button key={o} type="button" role="tab" aria-selected={onglet === o} onClick={() => setOnglet(o)}
            className={`px-3 py-1.5 rounded-lg text-sm ${onglet === o ? 'bg-amber-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'}`}>
            {ts.tabs[o]}
          </button>
        ))}
      </div>

      <section className="bg-white dark:bg-gray-800 rounded-xl shadow p-4 space-y-3" aria-label={ts.tabs[onglet]}>
        {suggestions !== null && suggestions.length === 0 && <p className="text-sm text-gray-500 py-4">{onglet === 'ouvertes' ? ts.none : ts.noneOrdered}</p>}
        {groupes.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-xs uppercase text-gray-500 dark:text-gray-400 border-b dark:border-gray-700">
                <tr>
                  {onglet === 'ouvertes' && gerer && <th className="py-2 pr-2">{ts.accept}</th>}
                  <th className="py-2 pr-3">{ts.product}</th>
                  <th className="py-2 pr-3">{ts.source}</th>
                  <th className="py-2 pr-3 text-right">{ts.available}</th>
                  <th className="py-2 pr-3 text-right">{ts.minMax}</th>
                  <th className="py-2 pr-3 text-right">{ts.onOrder}</th>
                  <th className="py-2 pr-3 text-right">{ts.sold12}</th>
                  <th className="py-2 pr-3 text-right">{ts.suggested}</th>
                  <th className="py-2 pr-3 text-right">{ts.toOrder}</th>
                  <th className="py-2 pr-3 text-right">{ts.lastCost}</th>
                  <th className="py-2 pr-3">{onglet === 'ouvertes' ? ts.supplier : ts.order}</th>
                  <th className="py-2 pr-3">{ts.remark}</th>
                  <th className="py-2 pr-3">{ts.clerk}</th>
                  {onglet === 'ouvertes' && gerer && <th className="py-2" />}
                </tr>
              </thead>
              <tbody>
                {groupes.map(([fournisseur, lignes]) => (
                  <Fragment key={fournisseur || 'sans'}>
                    <tr className="bg-amber-50/60 dark:bg-amber-900/10">
                      <td colSpan={14} className="py-1.5 px-2 font-semibold text-gray-800 dark:text-gray-100">{fournisseur || ts.noSupplier}</td>
                    </tr>
                    {lignes.map(s => (
                      <tr key={s.id} className="border-b dark:border-gray-700 dark:text-gray-200 align-top">
                        {onglet === 'ouvertes' && gerer && (
                          <td className="py-2 pr-2">
                            <input type="checkbox" checked={s.statut === 'acceptee'} disabled={occupe} aria-label={`${ts.accept} — ${s.produit?.numero}`}
                              onChange={e => { void agir(() => accepterSuggestions([s.id], e.target.checked), ''); }} />
                          </td>
                        )}
                        <td className="py-2 pr-3 min-w-[13rem]"><span className="font-mono text-xs text-gray-500 mr-1">{s.produit?.numero}</span>{s.produit?.description}</td>
                        <td className="py-2 pr-3 min-w-[9rem]">{source(s)}</td>
                        <td className={`py-2 pr-3 text-right ${Number(s.disponible) < Number(s.produit?.seuil_alerte ?? 0) ? 'text-red-600' : ''}`}>{nombre(s.disponible)}</td>
                        <td className="py-2 pr-3 text-right whitespace-nowrap">{nombre(s.produit?.seuil_alerte)} / {nombre(s.produit?.stock_maximum)}</td>
                        <td className="py-2 pr-3 text-right">{nombre(s.en_commande)}</td>
                        <td className="py-2 pr-3 text-right">{s.vendu_12_mois}</td>
                        <td className="py-2 pr-3 text-right">{s.quantite_suggeree}</td>
                        <td className="py-2 pr-3 text-right">
                          {onglet === 'ouvertes' && gerer ? (
                            <input type="number" min="1" step="1" className={`${classeChamp} w-20 text-right`} aria-label={`${ts.toOrder} — ${s.produit?.numero}`}
                              value={saisies[s.id]?.quantite ?? String(s.quantite ?? s.quantite_suggeree)}
                              onChange={e => setSaisies(v => ({ ...v, [s.id]: { ...v[s.id], quantite: e.target.value } }))}
                              onBlur={() => enregistrer(s, 'quantite')} />
                          ) : (s.quantite ?? s.quantite_suggeree)}
                        </td>
                        <td className="py-2 pr-3 text-right whitespace-nowrap">
                          {s.dernier_cout === null ? '—' : s.fournisseur?.devise ? formatMontantIso(s.dernier_cout, s.fournisseur.devise, language) : montant(s.dernier_cout)}
                        </td>
                        <td className="py-2 pr-3">
                          {onglet === 'commandees' ? (
                            s.ligne_commande?.commande ? <Link to={`/commandes-fournisseur?recherche=${encodeURIComponent(s.ligne_commande.commande.numero)}`} className="text-blue-600 hover:underline whitespace-nowrap">{s.ligne_commande.commande.numero}</Link> : '—'
                          ) : gerer ? (
                            <select value={s.fournisseur_id ?? ''} aria-label={`${ts.supplier} — ${s.produit?.numero}`} className={`${classeChamp} max-w-[12rem]`}
                              onChange={e => { if (e.target.value) void agir(() => modifierSuggestion(s.id, { fournisseur_id: Number(e.target.value) }), ''); }}>
                              <option value="">{ts.chooseSupplier}</option>
                              {fournisseurs.map(f => <option key={f.id} value={f.id}>{f.code} — {f.raison_sociale}</option>)}
                            </select>
                          ) : (s.fournisseur?.raison_sociale ?? '—')}
                        </td>
                        <td className="py-2 pr-3">
                          {onglet === 'ouvertes' && gerer ? (
                            <input value={saisies[s.id]?.remarque ?? s.remarque ?? ''} maxLength={255} aria-label={`${ts.remark} — ${s.produit?.numero}`} className={`${classeChamp} w-36`}
                              onChange={e => setSaisies(v => ({ ...v, [s.id]: { ...v[s.id], remarque: e.target.value } }))}
                              onBlur={() => enregistrer(s, 'remarque')} />
                          ) : (s.remarque ?? '')}
                        </td>
                        <td className="py-2 pr-3 text-xs text-gray-500">{s.commis_nom ?? ''}</td>
                        {onglet === 'ouvertes' && gerer && (
                          <td className="py-2 text-right">
                            <button type="button" disabled={occupe} onClick={() => { if (confirm(ts.confirmRemove)) void agir(() => retirerSuggestion(s.id), ts.removed); }}
                              className="p-1 rounded text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20" aria-label={`${ts.remove} — ${s.produit?.numero}`}><Trash2 size={16} /></button>
                          </td>
                        )}
                      </tr>
                    ))}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {onglet === 'ouvertes' && gerer && (suggestions?.length ?? 0) > 0 && (
          <div className="flex flex-wrap items-center gap-3 border-t pt-3 dark:border-gray-700">
            <button type="button" disabled={occupe} onClick={() => { void agir(() => accepterSuggestions((suggestions ?? []).map(s => s.id), true), ''); }}
              className="px-3 py-1.5 rounded-lg border text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{ts.acceptAll}</button>
            <button type="button" disabled={occupe || acceptees.length === 0} onClick={() => { void agir(() => accepterSuggestions(acceptees.map(s => s.id), false), ''); }}
              className="px-3 py-1.5 rounded-lg border text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700 disabled:opacity-50">{ts.unacceptAll}</button>
            <label className="flex items-center gap-2 text-sm dark:text-gray-200 ml-auto"><input type="checkbox" checked={fusionner} onChange={e => setFusionner(e.target.checked)} /> {ts.merge}</label>
            {peut('commandes_fournisseur', 'creer') && (
              <button type="button" disabled={occupe || acceptees.length === 0} onClick={() => { void agir(() => commanderSuggestions(fusionner)); }}
                className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm hover:bg-emerald-700 disabled:opacity-50">
                <ShoppingCart size={16} /> {ts.order_.replace('{n}', String(acceptees.length))}
              </button>
            )}
          </div>
        )}
      </section>
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}
