import { useEffect, useRef, useState } from 'react';
import { ListPlus, Plus, Pencil, Trash2, X, ArrowUp, ArrowDown, Search } from 'lucide-react';
import {
  getChampsPersonnalises, creerChampPersonnalise, modifierChampPersonnalise, supprimerChampPersonnalise, ordonnerChampsPersonnalises,
  rechercherChampPersonnalise,
  type ChampPersonnalise, type DefinitionChampPersonnalise, type EntiteChampPersonnalise, type TypeChampPersonnalise, type ValeurChampPersonnalise,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { useAuth } from '@/contexts/AuthContext';
import { SaisieChampPersonnalise } from '@/components/ChampsPersonnalisesFiche';

/** Types de fiche et module de l'entreprise dont chacun dépend (null : toujours disponible). */
const ENTITES: [EntiteChampPersonnalise, string | null][] = [
  ['client', 'ventes'], ['fournisseur', 'achats'], ['produit', null], ['employe', null],
  ['devis', 'ventes'], ['commande_client', 'ventes'], ['facture', 'ventes'], ['bon_livraison', 'ventes'], ['commande_fournisseur', 'achats'],
];
const TYPES: TypeChampPersonnalise[] = ['texte', 'texte_long', 'nombre', 'date', 'case', 'liste', 'liste_multiple', 'courriel', 'url', 'telephone'];
const A_CHOIX: TypeChampPersonnalise[] = ['liste', 'liste_multiple'];
const SANS_DOUBLON: TypeChampPersonnalise[] = ['texte', 'nombre', 'date', 'courriel', 'url', 'telephone'];

interface Brouillon {
  id: number | null;
  type: TypeChampPersonnalise;
  definition: DefinitionChampPersonnalise;
  optionsTexte: string;
}

const definitionVide = (): DefinitionChampPersonnalise => ({
  libelle: '', options: null, format: null, decimales: null, obligatoire: false, sans_doublon: false, valeur_defaut: null, aide: null, actif: true,
});

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeLibelle = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';

/** Définition des champs personnalisés par type de fiche, et recherche des fiches par valeur. */
export default function ChampsPersonnalisesPage() {
  const { t } = useLanguage();
  const tc = t.customFields;
  const { tenant } = useAuth();
  const modules = tenant?.modules;
  const entites = ENTITES.filter(([, module]) => !module || !Array.isArray(modules) || modules.includes(module)).map(([entite]) => entite);

  const [entite, setEntite] = useState<EntiteChampPersonnalise>(entites[0] ?? 'produit');
  const [champs, setChamps] = useState<ChampPersonnalise[]>([]);
  const [version, setVersion] = useState(0);
  const [message, setMessage] = useState<{ erreur: boolean; texte: string } | null>(null);
  const [brouillon, setBrouillon] = useState<Brouillon | null>(null);
  const [erreurBrouillon, setErreurBrouillon] = useState('');
  const [champRecherche, setChampRecherche] = useState<number | ''>('');
  const [valeurRecherche, setValeurRecherche] = useState('');
  const [resultats, setResultats] = useState<{ entite_id: number; libelle_entite: string | null; valeur: ValeurChampPersonnalise }[] | null>(null);

  const [occupe, setOccupe] = useState(true);
  // Seule la dernière requête lancée (chargement ou déplacement) met la liste à jour.
  const derniereRequete = useRef(0);

  const messageErreur = (erreur: unknown) => (erreur instanceof ApiError ? erreur.message : tc.error);

  useEffect(() => {
    const numero = ++derniereRequete.current;
    getChampsPersonnalises(entite)
      .then(liste => { if (numero === derniereRequete.current) setChamps(liste); })
      .catch(e => { if (numero === derniereRequete.current) setMessage({ erreur: true, texte: e instanceof ApiError ? e.message : tc.error }); })
      .finally(() => { if (numero === derniereRequete.current) setOccupe(false); });
  }, [entite, version, tc.error]);

  function changerEntite(nouvelle: EntiteChampPersonnalise) {
    if (nouvelle !== entite) setOccupe(true);
    setEntite(nouvelle);
    setResultats(null);
    setChampRecherche('');
    setValeurRecherche('');
  }

  function ouvrir(champ?: ChampPersonnalise) {
    setErreurBrouillon('');
    setBrouillon(champ
      ? {
          id: champ.id,
          type: champ.type,
          definition: {
            libelle: champ.libelle, options: champ.options, format: champ.format, decimales: champ.decimales, obligatoire: champ.obligatoire,
            sans_doublon: champ.sans_doublon, valeur_defaut: champ.valeur_defaut, aide: champ.aide, actif: champ.actif,
          },
          optionsTexte: (champ.options ?? []).join('\n'),
        }
      : { id: null, type: 'texte', definition: definitionVide(), optionsTexte: '' });
  }

  const modifierDefinition = (valeurs: Partial<DefinitionChampPersonnalise>) =>
    setBrouillon(b => (b ? { ...b, definition: { ...b.definition, ...valeurs } } : b));
  const optionsDe = (b: Brouillon) => (A_CHOIX.includes(b.type) ? b.optionsTexte.split('\n').map(o => o.trim()).filter(Boolean) : null);

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    if (!brouillon) return;
    setErreurBrouillon('');
    const { valeur_defaut: defaut } = brouillon.definition;
    const definition: DefinitionChampPersonnalise = {
      ...brouillon.definition,
      options: optionsDe(brouillon),
      valeur_defaut: brouillon.type === 'nombre' ? (defaut === '' || defaut === null ? null : Number(defaut)) : defaut,
    };
    try {
      if (brouillon.id) {
        await modifierChampPersonnalise(brouillon.id, definition);
        setMessage({ erreur: false, texte: tc.updated });
      } else {
        await creerChampPersonnalise({ ...definition, entite, type: brouillon.type });
        setMessage({ erreur: false, texte: tc.created });
      }
      setBrouillon(null);
      setOccupe(true);
      setVersion(v => v + 1);
    } catch (erreur) {
      setErreurBrouillon(messageErreur(erreur));
    }
  }

  async function supprimer(champ: ChampPersonnalise) {
    if (!confirm(tc.confirmDelete)) return;
    try {
      await supprimerChampPersonnalise(champ.id);
      setMessage({ erreur: false, texte: tc.deleted });
      setOccupe(true);
      setVersion(v => v + 1);
    } catch (erreur) {
      setMessage({ erreur: true, texte: messageErreur(erreur) });
    }
  }

  async function deplacer(index: number, sens: -1 | 1) {
    const ids = champs.map(c => c.id);
    [ids[index], ids[index + sens]] = [ids[index + sens], ids[index]];
    const numero = ++derniereRequete.current;
    setOccupe(true);
    try {
      const liste = await ordonnerChampsPersonnalises(entite, ids);
      if (numero === derniereRequete.current) setChamps(liste);
    } catch (erreur) {
      setMessage({ erreur: true, texte: messageErreur(erreur) });
    } finally {
      if (numero === derniereRequete.current) setOccupe(false);
    }
  }

  async function rechercher(e: React.FormEvent) {
    e.preventDefault();
    if (champRecherche === '' || !valeurRecherche.trim()) return;
    try {
      setResultats(await rechercherChampPersonnalise(champRecherche, valeurRecherche.trim()));
    } catch (erreur) {
      setMessage({ erreur: true, texte: messageErreur(erreur) });
    }
  }

  const affichageValeur = (valeur: ValeurChampPersonnalise) =>
    Array.isArray(valeur) ? valeur.join(', ') : typeof valeur === 'boolean' ? (valeur ? '✓' : '—') : String(valeur ?? '');

  return (
    <div className="p-6">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2">
          <ListPlus className="text-purple-600" /> {tc.title}
        </h1>
        <p className="text-sm text-gray-500 dark:text-gray-400">{tc.subtitle}</p>
      </div>

      <div className="flex flex-wrap gap-1 mb-6 bg-gray-100 dark:bg-gray-800 p-1 rounded-xl w-fit max-w-full">
        {entites.map(e => (
          <button key={e} onClick={() => changerEntite(e)}
            className={`px-3 py-2 rounded-lg text-sm font-medium transition-colors ${entite === e ? 'bg-white dark:bg-gray-700 text-purple-600 shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-800'}`}>
            {tc.entities[e]}
          </button>
        ))}
      </div>

      {message && (
        <p className={`mb-4 px-3 py-2 rounded-lg text-sm max-w-3xl ${message.erreur ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}>{message.texte}</p>
      )}

      <div className="space-y-6 max-w-3xl">
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
          <div className="flex items-center justify-between gap-4 mb-4">
            <h2 className="text-lg font-semibold dark:text-white">{tc.entities[entite]}</h2>
            <button onClick={() => ouvrir()} className="flex items-center gap-1 bg-purple-600 text-white px-3 py-2 rounded-lg hover:bg-purple-700 text-sm">
              <Plus size={14} /> {tc.add}
            </button>
          </div>
          {champs.length === 0 ? (
            <p className="text-sm text-gray-500">{tc.none}</p>
          ) : (
            <ul className="space-y-2">
              {champs.map((champ, index) => (
                <li key={champ.id} className="flex flex-wrap items-center gap-2 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                  <div className="flex flex-col">
                    <button disabled={occupe || index === 0} onClick={() => deplacer(index, -1)} title={tc.moveUp} className="text-gray-500 disabled:opacity-30"><ArrowUp size={14} /></button>
                    <button disabled={occupe || index === champs.length - 1} onClick={() => deplacer(index, 1)} title={tc.moveDown} className="text-gray-500 disabled:opacity-30"><ArrowDown size={14} /></button>
                  </div>
                  <div className="flex-1 min-w-[12rem]">
                    <p className="font-medium text-gray-900 dark:text-white">{champ.libelle}</p>
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                      {tc.types[champ.type]}
                      {champ.format && champ.format !== 'nombre' ? ` · ${tc.formats[champ.format]}` : ''}
                      {champ.options?.length ? ` · ${champ.options.join(', ')}` : ''}
                    </p>
                  </div>
                  {champ.obligatoire && <span className="px-2 py-0.5 rounded text-xs bg-purple-100 text-purple-700">{tc.required}</span>}
                  {champ.sans_doublon && <span className="px-2 py-0.5 rounded text-xs bg-blue-100 text-blue-700">{tc.unique}</span>}
                  {!champ.actif && <span className="px-2 py-0.5 rounded text-xs bg-gray-200 text-gray-600">{tc.inactive}</span>}
                  <button onClick={() => ouvrir(champ)} title={tc.edit} aria-label={`${tc.edit} — ${champ.libelle}`} className="p-1 text-blue-600 hover:bg-blue-50 rounded"><Pencil size={14} /></button>
                  <button onClick={() => supprimer(champ)} title={tc.delete} aria-label={`${tc.delete} — ${champ.libelle}`} className="p-1 text-red-500 hover:bg-red-50 rounded"><Trash2 size={14} /></button>
                </li>
              ))}
            </ul>
          )}
        </div>

        {champs.length > 0 && (
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
            <h2 className="text-lg font-semibold dark:text-white">{tc.searchTitle}</h2>
            <p className="text-sm text-gray-500 mb-4">{tc.searchHelp}</p>
            <form onSubmit={rechercher} className="flex flex-wrap gap-2">
              <select value={champRecherche} onChange={e => setChampRecherche(e.target.value === '' ? '' : Number(e.target.value))} aria-label={tc.field}
                className="px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm">
                <option value="">{tc.choose}</option>
                {champs.map(c => <option key={c.id} value={c.id}>{c.libelle}</option>)}
              </select>
              <input value={valeurRecherche} onChange={e => setValeurRecherche(e.target.value)} placeholder={tc.value} aria-label={tc.value} className={`${classeChamp} max-w-xs`} />
              <button type="submit" className="flex items-center gap-1 px-3 py-2 text-sm rounded-lg border dark:border-gray-600 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-700">
                <Search size={14} /> {tc.search}
              </button>
            </form>
            {resultats && (resultats.length === 0 ? (
              <p className="text-sm text-gray-500 mt-3">{tc.noResult}</p>
            ) : (
              <table className="w-full text-sm mt-3">
                <thead>
                  <tr className="text-left text-xs uppercase text-gray-500"><th className="py-2">{tc.record}</th><th className="py-2">{tc.value}</th></tr>
                </thead>
                <tbody>
                  {resultats.map(r => (
                    <tr key={r.entite_id} className="border-t dark:border-gray-700">
                      <td className="py-2 dark:text-white">{r.libelle_entite ?? `#${r.entite_id}`}</td>
                      <td className="py-2 text-gray-700 dark:text-gray-300">{affichageValeur(r.valeur)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ))}
          </div>
        )}
      </div>

      {brouillon && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-bold dark:text-white">{brouillon.id ? tc.editField : tc.newField} — {tc.entities[entite]}</h3>
              <button onClick={() => setBrouillon(null)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" title={tc.close}><X size={18} /></button>
            </div>
            <form onSubmit={enregistrer} className="space-y-4">
              <div>
                <label htmlFor="cp-libelle" className={classeLibelle}>{tc.label} *</label>
                <input id="cp-libelle" value={brouillon.definition.libelle} onChange={e => modifierDefinition({ libelle: e.target.value })} required maxLength={100} className={classeChamp} />
              </div>
              <div>
                <label htmlFor="cp-type" className={classeLibelle}>{tc.type}</label>
                <select id="cp-type" value={brouillon.type} disabled={brouillon.id !== null} className={`${classeChamp} disabled:opacity-60`}
                  onChange={e => setBrouillon({ ...brouillon, type: e.target.value as TypeChampPersonnalise, definition: { ...brouillon.definition, valeur_defaut: null } })}>
                  {TYPES.map(type => <option key={type} value={type}>{tc.types[type]}</option>)}
                </select>
                {brouillon.id !== null && <p className="text-xs text-gray-500 mt-1">{tc.typeFixed}</p>}
              </div>

              {A_CHOIX.includes(brouillon.type) && (
                <div>
                  <label htmlFor="cp-options" className={classeLibelle}>{tc.options} *</label>
                  <textarea id="cp-options" rows={4} value={brouillon.optionsTexte} onChange={e => setBrouillon({ ...brouillon, optionsTexte: e.target.value })} className={classeChamp} />
                </div>
              )}

              {brouillon.type === 'nombre' && (
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label htmlFor="cp-format" className={classeLibelle}>{tc.format}</label>
                    <select id="cp-format" value={brouillon.definition.format ?? 'nombre'} onChange={e => modifierDefinition({ format: e.target.value as DefinitionChampPersonnalise['format'] })} className={classeChamp}>
                      {(['nombre', 'montant', 'pourcentage'] as const).map(f => <option key={f} value={f}>{tc.formats[f]}</option>)}
                    </select>
                  </div>
                  <div>
                    <label htmlFor="cp-decimales" className={classeLibelle}>{tc.decimals}</label>
                    <input id="cp-decimales" type="number" min={0} max={6} value={brouillon.definition.decimales ?? ''} className={classeChamp}
                      onChange={e => modifierDefinition({ decimales: e.target.value === '' ? null : Number(e.target.value) })} />
                  </div>
                </div>
              )}

              <SaisieChampPersonnalise
                champ={{ libelle: tc.defaultValue, type: brouillon.type, options: optionsDe(brouillon), decimales: brouillon.definition.decimales, format: brouillon.definition.format, obligatoire: false, aide: null }}
                valeur={brouillon.definition.valeur_defaut}
                onChange={valeur => modifierDefinition({ valeur_defaut: valeur })}
              />

              <div>
                <label htmlFor="cp-aide" className={classeLibelle}>{tc.help}</label>
                <input id="cp-aide" value={brouillon.definition.aide ?? ''} onChange={e => modifierDefinition({ aide: e.target.value || null })} maxLength={240}
                  placeholder={tc.helpPlaceholder} className={classeChamp} />
              </div>

              <div className="flex flex-wrap gap-x-6 gap-y-2">
                {brouillon.type !== 'case' && (
                  <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" className="rounded" checked={brouillon.definition.obligatoire} onChange={e => modifierDefinition({ obligatoire: e.target.checked })} />
                    {tc.required}
                  </label>
                )}
                {SANS_DOUBLON.includes(brouillon.type) && (
                  <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300" title={tc.uniqueHelp}>
                    <input type="checkbox" className="rounded" checked={brouillon.definition.sans_doublon} onChange={e => modifierDefinition({ sans_doublon: e.target.checked })} />
                    {tc.unique}
                  </label>
                )}
                <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                  <input type="checkbox" className="rounded" checked={brouillon.definition.actif} onChange={e => modifierDefinition({ actif: e.target.checked })} />
                  {tc.active}
                </label>
              </div>

              {erreurBrouillon && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreurBrouillon}</p>}

              <div className="flex justify-end gap-2">
                <button type="button" onClick={() => setBrouillon(null)} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{tc.cancel}</button>
                <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{tc.save}</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
