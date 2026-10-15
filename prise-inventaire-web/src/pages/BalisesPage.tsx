import { useEffect, useState } from 'react';
import { Tag, Plus, Pencil, Trash2, X, Search, MessageSquarePlus } from 'lucide-react';
import {
  getBalises, creerBalise, modifierBalise, supprimerBalise, ajouterItemBalise, modifierItemBalise, supprimerItemBalise, rechercherParBalises,
  remarquesRapides, creerRemarqueRapide, modifierRemarqueRapide, supprimerRemarqueRapide,
  type Balise, type BaliseItem, type CouleurBalise, type EntiteBalise, type RemarqueRapide,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { useAuth } from '@/contexts/AuthContext';
import { PastilleBalise } from '@/components/BalisesFiche';

/** Types de fiche et module de l'entreprise dont chacun dépend (null : toujours disponible). */
const ENTITES: [EntiteBalise, string | null][] = [
  ['client', 'ventes'], ['fournisseur', 'achats'], ['produit', null], ['employe', null],
  ['devis', 'ventes'], ['commande_client', 'ventes'], ['facture', 'ventes'], ['bon_livraison', 'ventes'], ['commande_fournisseur', 'achats'],
];
const COULEURS: CouleurBalise[] = ['gris', 'bleu', 'vert', 'jaune', 'orange', 'rouge', 'violet', 'rose'];

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeLibelle = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';

type BrouillonBalise = { id: number | null } & Pick<Balise, 'nom' | 'type' | 'entites' | 'actif'>;

/** Balises de classement (définitions, items, recherche) et remarques rapides de l'entreprise. */
export default function BalisesPage() {
  const { t } = useLanguage();
  const tb = t.tags;
  const { tenant } = useAuth();
  const modules = tenant?.modules;
  const entites = ENTITES.filter(([, module]) => !module || !Array.isArray(modules) || modules.includes(module)).map(([e]) => e);

  const [onglet, setOnglet] = useState<'balises' | 'remarques'>('balises');
  const [message, setMessage] = useState<{ erreur: boolean; texte: string } | null>(null);
  const messageErreur = (e: unknown) => (e instanceof ApiError ? e.message : tb.error);
  const informer = (texte: string, erreur = false) => setMessage({ texte, erreur });

  // ── Balises ──
  const [balises, setBalises] = useState<Balise[]>([]);
  const [version, setVersion] = useState(0);
  const [brouillon, setBrouillon] = useState<BrouillonBalise | null>(null);
  const [erreurBrouillon, setErreurBrouillon] = useState('');
  const [nouveauxItems, setNouveauxItems] = useState<Record<number, { libelle: string; couleur: CouleurBalise }>>({});
  const [itemEdite, setItemEdite] = useState<BaliseItem | null>(null);

  useEffect(() => {
    let actif = true;
    getBalises().then(liste => { if (actif) setBalises(liste); }).catch(e => { if (actif) setMessage({ erreur: true, texte: e instanceof ApiError ? e.message : tb.error }); });
    return () => { actif = false; };
  }, [version, tb.error]);

  const recharger = () => setVersion(v => v + 1);

  async function enregistrerBalise(e: React.FormEvent) {
    e.preventDefault();
    if (!brouillon) return;
    setErreurBrouillon('');
    const { id, ...donnees } = brouillon;
    try {
      if (id) await modifierBalise(id, donnees); else await creerBalise(donnees);
      setBrouillon(null);
      recharger();
    } catch (erreur) {
      setErreurBrouillon(messageErreur(erreur));
    }
  }

  async function action(promesse: Promise<unknown>) {
    try {
      await promesse;
      recharger();
    } catch (erreur) {
      informer(messageErreur(erreur), true);
    }
  }

  async function ajouterItem(balise: Balise) {
    const saisie = nouveauxItems[balise.id];
    if (!saisie?.libelle.trim()) return;
    await action(ajouterItemBalise(balise.id, { libelle: saisie.libelle.trim(), couleur: saisie.couleur }));
    setNouveauxItems(n => ({ ...n, [balise.id]: { libelle: '', couleur: saisie.couleur } }));
  }

  // ── Recherche ──
  const [entiteRecherche, setEntiteRecherche] = useState<EntiteBalise>(entites[0] ?? 'produit');
  const [itemsRecherche, setItemsRecherche] = useState<number[]>([]);
  const [mode, setMode] = useState<'un' | 'tous'>('un');
  const [resultats, setResultats] = useState<{ entite_id: number; libelle_entite: string | null }[] | null>(null);
  const balisesRecherche = balises.filter(b => b.actif && b.entites.includes(entiteRecherche));

  async function rechercher(e: React.FormEvent) {
    e.preventDefault();
    if (!itemsRecherche.length) return;
    try {
      setResultats(await rechercherParBalises(entiteRecherche, itemsRecherche, mode));
    } catch (erreur) {
      informer(messageErreur(erreur), true);
    }
  }

  // ── Remarques rapides ──
  const [remarques, setRemarques] = useState<RemarqueRapide[]>([]);
  const [versionRemarques, setVersionRemarques] = useState(0);
  const [remarque, setRemarque] = useState<{ id: number | null; categorie: string; texte: string }>({ id: null, categorie: '', texte: '' });

  useEffect(() => {
    let actif = true;
    remarquesRapides().then(liste => { if (actif) setRemarques(liste); }).catch(() => {});
    return () => { actif = false; };
  }, [versionRemarques]);

  async function enregistrerRemarque(e: React.FormEvent) {
    e.preventDefault();
    const donnees = { categorie: remarque.categorie.trim() || null, texte: remarque.texte };
    try {
      if (remarque.id) await modifierRemarqueRapide(remarque.id, donnees); else await creerRemarqueRapide(donnees);
      setRemarque({ id: null, categorie: '', texte: '' });
      setVersionRemarques(v => v + 1);
    } catch (erreur) {
      informer(messageErreur(erreur), true);
    }
  }

  async function supprimerRemarque(id: number) {
    if (!confirm(tb.confirmDeleteRemark)) return;
    try {
      await supprimerRemarqueRapide(id);
      setVersionRemarques(v => v + 1);
    } catch (erreur) {
      informer(messageErreur(erreur), true);
    }
  }

  const categories = [...new Set(remarques.map(r => r.categorie).filter((c): c is string => !!c))];
  const remarquesParCategorie = remarques.reduce<Record<string, RemarqueRapide[]>>((acc, r) => {
    (acc[r.categorie ?? tb.uncategorized] ??= []).push(r);
    return acc;
  }, {});

  return (
    <div className="p-6">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Tag className="text-purple-600" /> {tb.title}</h1>
        <p className="text-sm text-gray-500 dark:text-gray-400">{tb.subtitle}</p>
      </div>

      <div className="flex gap-1 mb-6 bg-gray-100 dark:bg-gray-800 p-1 rounded-xl w-fit">
        {(['balises', 'remarques'] as const).map(o => (
          <button key={o} onClick={() => { setOnglet(o); setMessage(null); }}
            className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium ${onglet === o ? 'bg-white dark:bg-gray-700 text-purple-600 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-800'}`}>
            {o === 'balises' ? <Tag size={16} /> : <MessageSquarePlus size={16} />} {o === 'balises' ? tb.tabTags : tb.tabRemarks}
          </button>
        ))}
      </div>

      {message && <p className={`mb-4 px-3 py-2 rounded-lg text-sm max-w-3xl ${message.erreur ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}>{message.texte}</p>}

      {onglet === 'balises' && (
        <div className="space-y-6 max-w-3xl">
          <div className="flex justify-end">
            <button onClick={() => { setErreurBrouillon(''); setBrouillon({ id: null, nom: '', type: 'choix', entites: [], actif: true }); }}
              className="flex items-center gap-1 bg-purple-600 text-white px-3 py-2 rounded-lg hover:bg-purple-700 text-sm">
              <Plus size={14} /> {tb.newTag}
            </button>
          </div>

          {balises.length === 0 && <p className="text-sm text-gray-500">{tb.noTags}</p>}

          {balises.map(balise => {
            const saisie = nouveauxItems[balise.id] ?? { libelle: '', couleur: 'gris' as CouleurBalise };
            return (
              <div key={balise.id} className={`bg-white dark:bg-gray-800 rounded-lg shadow-md p-5 ${balise.actif ? '' : 'opacity-60'}`}>
                <div className="flex flex-wrap items-start gap-2">
                  <div className="flex-1 min-w-[12rem]">
                    <h2 className="font-semibold text-gray-900 dark:text-white">{balise.nom}</h2>
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                      {tb.types[balise.type]} · {balise.entites.map(e => tb.entities[e]).join(', ')}{balise.actif ? '' : ` · ${tb.inactive}`}
                    </p>
                  </div>
                  <button onClick={() => { setErreurBrouillon(''); setBrouillon({ id: balise.id, nom: balise.nom, type: balise.type, entites: balise.entites, actif: balise.actif }); }}
                    title={tb.edit} aria-label={`${tb.edit} — ${balise.nom}`} className="p-1 text-blue-600 hover:bg-blue-50 rounded"><Pencil size={14} /></button>
                  <button onClick={() => confirm(tb.confirmDeleteTag) && action(supprimerBalise(balise.id))}
                    title={tb.delete} aria-label={`${tb.delete} — ${balise.nom}`} className="p-1 text-red-500 hover:bg-red-50 rounded"><Trash2 size={14} /></button>
                </div>

                <div className="flex flex-wrap items-center gap-2 mt-3">
                  {balise.items.length === 0 && <span className="text-xs text-gray-500">{tb.noItems}</span>}
                  {balise.items.map(item => (
                    <button key={item.id} type="button" onClick={() => setItemEdite(item)} title={tb.edit}
                      className={`rounded ${item.actif ? '' : 'opacity-50 line-through'}`}>
                      <PastilleBalise item={item} />
                    </button>
                  ))}
                </div>

                <form className="flex flex-wrap items-center gap-2 mt-3" onSubmit={e => { e.preventDefault(); ajouterItem(balise); }}>
                  <input value={saisie.libelle} maxLength={60} placeholder={tb.newItem} aria-label={`${tb.newItem} — ${balise.nom}`}
                    onChange={e => setNouveauxItems(n => ({ ...n, [balise.id]: { ...saisie, libelle: e.target.value } }))}
                    className="px-2 py-1 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm w-48" />
                  <select value={saisie.couleur} aria-label={`${tb.color} — ${balise.nom}`}
                    onChange={e => setNouveauxItems(n => ({ ...n, [balise.id]: { ...saisie, couleur: e.target.value as CouleurBalise } }))}
                    className="px-2 py-1 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm">
                    {COULEURS.map(c => <option key={c} value={c}>{tb.colors[c]}</option>)}
                  </select>
                  <button type="submit" className="flex items-center gap-1 text-sm text-purple-700 dark:text-purple-300 hover:underline"><Plus size={14} /> {tb.addItem}</button>
                </form>
              </div>
            );
          })}

          {balises.length > 0 && (
            <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-5">
              <h2 className="text-lg font-semibold dark:text-white">{tb.searchTitle}</h2>
              <p className="text-sm text-gray-500 mb-3">{tb.searchHelp}</p>
              <form onSubmit={rechercher} className="space-y-3">
                <select value={entiteRecherche} aria-label={tb.record} className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm"
                  onChange={e => { setEntiteRecherche(e.target.value as EntiteBalise); setItemsRecherche([]); setResultats(null); }}>
                  {entites.map(e => <option key={e} value={e}>{tb.entities[e]}</option>)}
                </select>
                {balisesRecherche.map(b => (
                  <div key={b.id} className="flex flex-wrap items-center gap-3">
                    <span className="text-sm text-gray-600 dark:text-gray-300 w-32">{b.nom}</span>
                    {b.items.filter(i => i.actif).map(item => (
                      <label key={item.id} className="flex items-center gap-1.5">
                        <input type="checkbox" className="rounded" checked={itemsRecherche.includes(item.id)}
                          onChange={e => setItemsRecherche(s => (e.target.checked ? [...s, item.id] : s.filter(id => id !== item.id)))} />
                        <PastilleBalise item={item} />
                      </label>
                    ))}
                  </div>
                ))}
                <div className="flex flex-wrap items-center gap-4">
                  {(['un', 'tous'] as const).map(m => (
                    <label key={m} className="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                      <input type="radio" name="mode-recherche" checked={mode === m} onChange={() => setMode(m)} /> {m === 'un' ? tb.modeAny : tb.modeAll}
                    </label>
                  ))}
                  <button type="submit" disabled={!itemsRecherche.length}
                    className="flex items-center gap-1 px-3 py-2 text-sm rounded-lg border dark:border-gray-600 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-40">
                    <Search size={14} /> {tb.search}
                  </button>
                </div>
              </form>
              {resultats && (resultats.length === 0
                ? <p className="text-sm text-gray-500 mt-3">{tb.noResult}</p>
                : <ul className="mt-3 divide-y dark:divide-gray-700">{resultats.map(r => <li key={r.entite_id} className="py-1.5 text-sm dark:text-white">{r.libelle_entite ?? `#${r.entite_id}`}</li>)}</ul>)}
            </div>
          )}
        </div>
      )}

      {onglet === 'remarques' && (
        <div className="space-y-6 max-w-3xl">
          <form onSubmit={enregistrerRemarque} className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-5 space-y-3">
            <p className="text-sm text-gray-500">{tb.remarksHelp}</p>
            <div>
              <label htmlFor="remarque-categorie" className={classeLibelle}>{tb.category}</label>
              <input id="remarque-categorie" list="remarques-categories" value={remarque.categorie} maxLength={60} placeholder={tb.categoryPlaceholder}
                onChange={e => setRemarque({ ...remarque, categorie: e.target.value })} className={classeChamp} />
              <datalist id="remarques-categories">{categories.map(c => <option key={c} value={c} />)}</datalist>
            </div>
            <div>
              <label htmlFor="remarque-texte" className={classeLibelle}>{tb.text} *</label>
              <textarea id="remarque-texte" rows={3} required maxLength={2000} value={remarque.texte}
                onChange={e => setRemarque({ ...remarque, texte: e.target.value })} className={classeChamp} />
            </div>
            <div className="flex justify-end gap-2">
              {remarque.id && <button type="button" onClick={() => setRemarque({ id: null, categorie: '', texte: '' })} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{tb.cancel}</button>}
              <button type="submit" className="flex items-center gap-1 px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                {remarque.id ? tb.save : <><Plus size={14} /> {tb.newRemark}</>}
              </button>
            </div>
          </form>

          {remarques.length === 0 ? <p className="text-sm text-gray-500">{tb.noRemarks}</p> : Object.entries(remarquesParCategorie).map(([categorie, liste]) => (
            <div key={categorie} className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-5">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500 mb-2">{categorie}</h2>
              <ul className="divide-y dark:divide-gray-700">
                {liste.map(r => (
                  <li key={r.id} className="py-2 flex items-start gap-2">
                    <p className="flex-1 text-sm text-gray-800 dark:text-gray-200 whitespace-pre-line">{r.texte}</p>
                    <button onClick={() => setRemarque({ id: r.id, categorie: r.categorie ?? '', texte: r.texte })} title={tb.edit} aria-label={`${tb.edit} — ${r.texte.slice(0, 30)}`} className="p-1 text-blue-600 hover:bg-blue-50 rounded"><Pencil size={14} /></button>
                    <button onClick={() => supprimerRemarque(r.id)} title={tb.delete} aria-label={`${tb.delete} — ${r.texte.slice(0, 30)}`} className="p-1 text-red-500 hover:bg-red-50 rounded"><Trash2 size={14} /></button>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}

      {/* Balise : création ou modification */}
      {brouillon && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <form onSubmit={enregistrerBalise} className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="text-lg font-bold dark:text-white">{brouillon.id ? tb.editTag : tb.newTag}</h3>
              <button type="button" onClick={() => setBrouillon(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" title={tb.close}><X size={18} /></button>
            </div>
            <div>
              <label htmlFor="balise-nom" className={classeLibelle}>{tb.name} *</label>
              <input id="balise-nom" required maxLength={60} value={brouillon.nom} onChange={e => setBrouillon({ ...brouillon, nom: e.target.value })} className={classeChamp} />
            </div>
            <fieldset>
              <legend className={classeLibelle}>{tb.type}</legend>
              <div className="flex gap-4">
                {(['choix', 'options'] as const).map(type => (
                  <label key={type} className="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                    <input type="radio" name="balise-type" checked={brouillon.type === type} onChange={() => setBrouillon({ ...brouillon, type })} /> {tb.types[type]}
                  </label>
                ))}
              </div>
            </fieldset>
            <fieldset>
              <legend className={classeLibelle}>{tb.appliesTo} *</legend>
              <p className="text-xs text-gray-500 mb-2">{tb.appliesToHelp}</p>
              <div className="grid grid-cols-2 gap-1.5">
                {entites.map(e => (
                  <label key={e} className="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" className="rounded" checked={brouillon.entites.includes(e)}
                      onChange={ev => setBrouillon({ ...brouillon, entites: ev.target.checked ? [...brouillon.entites, e] : brouillon.entites.filter(x => x !== e) })} />
                    {tb.entities[e]}
                  </label>
                ))}
              </div>
            </fieldset>
            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
              <input type="checkbox" className="rounded" checked={brouillon.actif} onChange={e => setBrouillon({ ...brouillon, actif: e.target.checked })} /> {tb.active}
            </label>
            {erreurBrouillon && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreurBrouillon}</p>}
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setBrouillon(null)} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{tb.cancel}</button>
              <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{tb.save}</button>
            </div>
          </form>
        </div>
      )}

      {/* Item : libellé, couleur, activation, suppression */}
      {itemEdite && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <form className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-sm space-y-4"
            onSubmit={async e => { e.preventDefault(); await action(modifierItemBalise(itemEdite.id, itemEdite)); setItemEdite(null); }}>
            <div className="flex items-center justify-between">
              <PastilleBalise item={itemEdite} />
              <button type="button" onClick={() => setItemEdite(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" title={tb.close}><X size={18} /></button>
            </div>
            <div>
              <label htmlFor="item-libelle" className={classeLibelle}>{tb.name} *</label>
              <input id="item-libelle" required maxLength={60} value={itemEdite.libelle} onChange={e => setItemEdite({ ...itemEdite, libelle: e.target.value })} className={classeChamp} />
            </div>
            <div>
              <label htmlFor="item-couleur" className={classeLibelle}>{tb.color}</label>
              <select id="item-couleur" value={itemEdite.couleur} onChange={e => setItemEdite({ ...itemEdite, couleur: e.target.value as CouleurBalise })} className={classeChamp}>
                {COULEURS.map(c => <option key={c} value={c}>{tb.colors[c]}</option>)}
              </select>
            </div>
            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
              <input type="checkbox" className="rounded" checked={itemEdite.actif} onChange={e => setItemEdite({ ...itemEdite, actif: e.target.checked })} /> {tb.active}
            </label>
            <div className="flex justify-between gap-2">
              <button type="button" onClick={async () => { if (!confirm(tb.confirmDeleteItem)) return; await action(supprimerItemBalise(itemEdite.id)); setItemEdite(null); }}
                className="flex items-center gap-1 px-3 py-2 text-sm text-red-600 hover:bg-red-50 rounded-lg"><Trash2 size={14} /> {tb.delete}</button>
              <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{tb.save}</button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}
