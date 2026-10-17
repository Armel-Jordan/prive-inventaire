import { useEffect, useMemo, useState } from 'react';
import { BadgePercent, Pencil, Plus, Trash2, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { formatMontant, useDevise } from '@/lib/documents';
import { getProduits } from '@/services/api';
import { APPLICATIONS_FRAIS, creerFrais, getFrais, modifierFrais, supprimerFrais, type Frais, type SaisieFrais } from '@/services/frais';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';
import type { Produit } from '@/types';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const VIDE: SaisieFrais = {
  libelle: '', produit_id: 0, mode: 'montant', valeur: 0, application: 'document', repetition: 'document',
  sur_devis: true, sur_commandes: true, sur_factures: true, actif: true, produits: [],
};

/**
 * Frais additionnels et de livraison (plan de parité fripv2, phase 1.4) : les frais que les devis, commandes et
 * factures proposent d'eux-mêmes, comme une ligne du produit qui les porte (manutention, emballage, matière
 * dangereuse, livraison…).
 */
export default function FraisPage() {
  const { t, language } = useLanguage();
  const tf = t.fees;
  const { peut } = usePermissions();
  const devise = useDevise();
  const { toasts, toast, dismiss } = useToast();
  const [frais, setFrais] = useState<Frais[] | null>(null);
  const [produits, setProduits] = useState<Produit[]>([]);
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);
  const [edition, setEdition] = useState<{ id: number | null; saisie: SaisieFrais } | null>(null);
  const modifiable = peut('configuration', 'modifier');

  useEffect(() => {
    let actif = true;
    Promise.all([getFrais(), getProduits('actifs')]).then(([liste, catalogue]) => {
      if (!actif) return;
      setFrais(liste);
      setProduits(catalogue);
      setErreur('');
    }, e => { if (actif) setErreur(messageErreur(e, tf.error)); });
    return () => { actif = false; };
  }, [version, tf.error]);

  const valeur = (f: Frais) => (f.mode === 'pourcentage'
    ? `${Number(f.valeur).toLocaleString(language === 'en' ? 'en-US' : 'fr-FR')} %`
    : formatMontant(f.valeur, devise, language));

  async function supprimer(f: Frais) {
    if (!confirm(tf.confirmDelete.replace('{libelle}', f.libelle))) return;
    try {
      await supprimerFrais(f.id);
      toast(tf.deleted);
      setVersion(v => v + 1);
    } catch (e) { toast(messageErreur(e, tf.error), 'error'); }
  }

  return (
    <div className="p-4 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><BadgePercent className="text-amber-600" /> {tf.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tf.subtitle}</p>
        </div>
        {modifiable && (
          <button type="button" onClick={() => setEdition({ id: null, saisie: { ...VIDE } })} className="flex items-center gap-2 bg-amber-600 text-white px-4 py-2 rounded-lg hover:bg-amber-700 text-sm">
            <Plus size={18} /> {tf.newFee}
          </button>
        )}
      </div>

      {erreur && <p role="alert" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
        <table className="w-full text-sm min-w-[52rem]">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-3 py-2 font-medium">{tf.label}</th>
              <th className="px-3 py-2 font-medium">{tf.product}</th>
              <th className="px-3 py-2 font-medium text-right">{tf.value}</th>
              <th className="px-3 py-2 font-medium">{tf.application}</th>
              <th className="px-3 py-2 font-medium">{tf.documents}</th>
              <th className="px-3 py-2 font-medium text-center">{tf.status}</th>
              {modifiable && <th className="px-3 py-2 font-medium text-center">{tf.actions}</th>}
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
            {frais === null && !erreur && <tr><td colSpan={7} className="px-4 py-6 text-center text-gray-500">{tf.loading}</td></tr>}
            {frais !== null && frais.length === 0 && <tr><td colSpan={7} className="px-4 py-6 text-center text-gray-500">{tf.empty}</td></tr>}
            {(frais ?? []).map(f => (
              <tr key={f.id} className={`hover:bg-gray-50 dark:hover:bg-gray-700/50 ${f.actif ? '' : 'opacity-60'}`}>
                <td className="px-3 py-2 font-medium text-gray-900 dark:text-white">{f.libelle}</td>
                <td className="px-3 py-2 text-gray-700 dark:text-gray-300">{f.produit?.description ?? `#${f.produit_id}`}</td>
                <td className="px-3 py-2 text-right whitespace-nowrap text-gray-900 dark:text-white">
                  {valeur(f)}
                  {f.mode === 'montant' && f.repetition === 'ligne' && <span className="block text-xs text-gray-500">{tf.perLine}</span>}
                </td>
                <td className="px-3 py-2 text-gray-700 dark:text-gray-300">
                  {tf.applications[f.application]}
                  {f.application === 'produits' && <span className="block text-xs text-gray-500">{(f.produits ?? []).map(p => p.description).join(', ')}</span>}
                </td>
                <td className="px-3 py-2 text-gray-700 dark:text-gray-300 whitespace-nowrap">
                  {[f.sur_devis && tf.quotes, f.sur_commandes && tf.orders, f.sur_factures && tf.invoices].filter(Boolean).join(', ')}
                </td>
                <td className="px-3 py-2 text-center">
                  <span className={`px-2 py-0.5 text-xs rounded-full ${f.actif ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'}`}>{f.actif ? tf.active : tf.inactive}</span>
                </td>
                {modifiable && (
                  <td className="px-3 py-2">
                    <div className="flex justify-center gap-1">
                      <button type="button" title={tf.edit} aria-label={`${tf.edit} — ${f.libelle}`}
                        onClick={() => setEdition({ id: f.id, saisie: {
                          libelle: f.libelle, produit_id: f.produit_id, mode: f.mode, valeur: Number(f.valeur), application: f.application, repetition: f.repetition,
                          sur_devis: f.sur_devis, sur_commandes: f.sur_commandes, sur_factures: f.sur_factures, actif: f.actif, produits: (f.produits ?? []).map(p => p.id),
                        } })}
                        className="p-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded"><Pencil size={18} /></button>
                      <button type="button" onClick={() => { void supprimer(f); }} title={tf.delete} aria-label={`${tf.delete} — ${f.libelle}`}
                        className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><Trash2 size={18} /></button>
                    </div>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">{tf.howItWorks}</p>

      {edition && (
        <FormulaireFrais
          id={edition.id}
          initial={edition.saisie}
          produits={produits}
          onClose={() => setEdition(null)}
          onEnregistre={() => { setEdition(null); toast(tf.saved); setVersion(v => v + 1); }}
        />
      )}
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}

/** Création ou modification d'un frais. */
function FormulaireFrais({ id, initial, produits, onClose, onEnregistre }: {
  id: number | null;
  initial: SaisieFrais;
  produits: Produit[];
  onClose: () => void;
  onEnregistre: () => void;
}) {
  const { t } = useLanguage();
  const tf = t.fees;
  const devise = useDevise();
  const [saisie, setSaisie] = useState<SaisieFrais>(initial);
  const [filtre, setFiltre] = useState('');
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);
  const filtres = useMemo(() => {
    const texte = filtre.trim().toLowerCase();
    return produits.filter(p => p.id !== saisie.produit_id && (!texte || `${p.numero} ${p.description}`.toLowerCase().includes(texte)));
  }, [produits, filtre, saisie.produit_id]);
  const changer = <K extends keyof SaisieFrais>(champ: K, valeur: SaisieFrais[K]) => setSaisie(s => ({ ...s, [champ]: valeur }));

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    try {
      if (id) await modifierFrais(id, saisie); else await creerFrais(saisie);
      onEnregistre();
    } catch (err) {
      setErreur(messageErreur(err, tf.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <form onSubmit={e => { void enregistrer(e); }} role="dialog" aria-modal="true" aria-labelledby="formulaire-frais-titre"
        className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-2xl max-h-[94vh] overflow-y-auto space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="formulaire-frais-titre" className="text-lg font-bold text-gray-900 dark:text-white">{id ? tf.editTitle : tf.newFee}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tf.close}><X size={18} /></button>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tf.label}
            <input required maxLength={100} value={saisie.libelle} onChange={e => changer('libelle', e.target.value)} placeholder={tf.labelHelp} className={classeChamp} />
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tf.product}
            <select required value={saisie.produit_id || ''} onChange={e => changer('produit_id', Number(e.target.value))} className={classeChamp}>
              <option value="">{tf.chooseProduct}</option>
              {produits.map(p => <option key={p.id} value={p.id}>{p.numero} — {p.description}</option>)}
            </select>
            <span className="block mt-1">{tf.productHelp}</span>
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tf.mode}
            <select value={saisie.mode} onChange={e => changer('mode', e.target.value as SaisieFrais['mode'])} className={classeChamp}>
              <option value="montant">{tf.modes.montant.replace('{devise}', devise)}</option>
              <option value="pourcentage">{tf.modes.pourcentage}</option>
            </select>
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{saisie.mode === 'pourcentage' ? tf.percent : tf.amount.replace('{devise}', devise)}
            <input type="number" required min="0.0001" step="any" max={saisie.mode === 'pourcentage' ? 100 : undefined} value={saisie.valeur || ''}
              onChange={e => changer('valeur', Number(e.target.value))} className={classeChamp} />
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400 sm:col-span-2">{tf.application}
            <select value={saisie.application} onChange={e => changer('application', e.target.value as SaisieFrais['application'])} className={classeChamp}>
              {APPLICATIONS_FRAIS.map(a => <option key={a} value={a}>{tf.applications[a]}</option>)}
            </select>
            <span className="block mt-1">{tf.applicationHelp[saisie.application]}</span>
          </label>
          {saisie.mode === 'montant' && (
            <label className="block text-xs text-gray-500 dark:text-gray-400 sm:col-span-2">{tf.repetition}
              <select value={saisie.repetition} onChange={e => changer('repetition', e.target.value as SaisieFrais['repetition'])} className={classeChamp}>
                <option value="document">{tf.repetitions.document}</option>
                <option value="ligne">{tf.repetitions.ligne}</option>
              </select>
            </label>
          )}
        </div>

        {saisie.application === 'produits' && (
          <fieldset className="border rounded-lg p-3 dark:border-gray-600">
            <legend className="px-1 text-xs text-gray-500 dark:text-gray-400">{tf.triggeringProducts.replace('{n}', String(saisie.produits.length))}</legend>
            <input type="search" value={filtre} onChange={e => setFiltre(e.target.value)} placeholder={tf.searchProduct} aria-label={tf.searchProduct} className={`${classeChamp} mb-2`} />
            <div className="max-h-48 overflow-y-auto space-y-1">
              {filtres.map(p => (
                <label key={p.id} className="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200">
                  <input type="checkbox" checked={saisie.produits.includes(p.id!)}
                    onChange={e => changer('produits', e.target.checked ? [...saisie.produits, p.id!] : saisie.produits.filter(x => x !== p.id))} />
                  <span className="font-mono text-xs text-gray-500">{p.numero}</span> {p.description}
                </label>
              ))}
            </div>
          </fieldset>
        )}

        <fieldset className="flex flex-wrap gap-x-5 gap-y-2 text-sm text-gray-800 dark:text-gray-200">
          <legend className="text-xs text-gray-500 dark:text-gray-400 mb-1">{tf.documents}</legend>
          <label className="flex items-center gap-2"><input type="checkbox" checked={saisie.sur_devis} onChange={e => changer('sur_devis', e.target.checked)} /> {tf.quotes}</label>
          <label className="flex items-center gap-2"><input type="checkbox" checked={saisie.sur_commandes} onChange={e => changer('sur_commandes', e.target.checked)} /> {tf.orders}</label>
          <label className="flex items-center gap-2"><input type="checkbox" checked={saisie.sur_factures} onChange={e => changer('sur_factures', e.target.checked)} /> {tf.invoices}</label>
          <label className="flex items-center gap-2 sm:ml-auto"><input type="checkbox" checked={saisie.actif} onChange={e => changer('actif', e.target.checked)} /> {tf.active}</label>
        </fieldset>

        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tf.cancel}</button>
          <button type="submit" disabled={occupe} className="px-4 py-2 bg-amber-600 text-white rounded-lg text-sm hover:bg-amber-700 disabled:opacity-50">{tf.save}</button>
        </div>
      </form>
    </div>
  );
}
