import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { Tag, X } from 'lucide-react';
import {
  balisesActives, posesBalises, enregistrerPosesBalises,
  type Balise, type BaliseItem, type CouleurBalise, type EntiteBalise,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';

const COULEURS: Record<CouleurBalise, string> = {
  gris: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
  bleu: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200',
  vert: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-200',
  jaune: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200',
  orange: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-200',
  rouge: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-200',
  violet: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-200',
  rose: 'bg-pink-100 text-pink-700 dark:bg-pink-900/40 dark:text-pink-200',
};

/** Pastille d'un item de balise, à sa couleur. */
export function PastilleBalise({ item, titre }: { item: Pick<BaliseItem, 'libelle' | 'couleur'>; titre?: string }) {
  return (
    <span title={titre} className={`inline-block px-1.5 py-0.5 rounded text-[11px] leading-4 font-medium whitespace-nowrap ${COULEURS[item.couleur] ?? COULEURS.gris}`}>
      {item.libelle}
    </span>
  );
}

/**
 * Balises d'une fiche dans une liste : pastilles des items posés et bouton pour les modifier.
 * Rien ne s'affiche tant que l'entreprise n'a pas de balise pour ce type de fiche.
 */
export default function BalisesFiche({ entite, entiteId }: { entite: EntiteBalise; entiteId: number }) {
  const { t } = useLanguage();
  const tb = t.tags;
  const [balises, setBalises] = useState<Balise[]>([]);
  const [poses, setPoses] = useState<number[]>([]);
  const [ouvert, setOuvert] = useState(false);
  const [selection, setSelection] = useState<number[]>([]);
  const [erreur, setErreur] = useState('');
  const [enregistrement, setEnregistrement] = useState(false);

  useEffect(() => {
    let actif = true;
    balisesActives(entite)
      .then(async liste => {
        // Pas de balise pour ce type de fiche : aucune requête de plus.
        const posees = liste.length ? await posesBalises(entite, entiteId) : [];
        if (!actif) return;
        setBalises(liste);
        setPoses(posees);
      })
      .catch(() => {});
    return () => { actif = false; };
  }, [entite, entiteId]);

  if (balises.length === 0) return null;

  const items = balises.flatMap(b => b.items.map(item => ({ item, balise: b })));
  const posees = items.filter(({ item }) => poses.includes(item.id));

  function choisir(balise: Balise, itemId: number, coche: boolean) {
    const autres = selection.filter(id => !balise.items.some(i => i.id === id));
    if (balise.type === 'choix') {
      setSelection(coche ? [...autres, itemId] : autres);
    } else {
      setSelection(coche ? [...selection, itemId] : selection.filter(id => id !== itemId));
    }
  }

  async function enregistrer() {
    setEnregistrement(true);
    setErreur('');
    try {
      setPoses(await enregistrerPosesBalises(entite, entiteId, selection));
      setOuvert(false);
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : tb.error);
    } finally {
      setEnregistrement(false);
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-1 mt-1 font-sans">
      {posees.map(({ item, balise }) => <PastilleBalise key={item.id} item={item} titre={balise.nom} />)}
      <button type="button" title={tb.button} aria-label={tb.button}
        onClick={e => { e.stopPropagation(); setSelection(poses); setErreur(''); setOuvert(true); }}
        className="p-0.5 rounded text-gray-400 hover:text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/30">
        <Tag size={12} />
      </button>

      {ouvert && createPortal(
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" onClick={e => e.stopPropagation()}>
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md max-h-[90vh] overflow-y-auto text-left">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-bold dark:text-white">{tb.editTitle}</h3>
              <button type="button" onClick={() => setOuvert(false)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" title={tb.close}><X size={18} /></button>
            </div>
            <div className="space-y-4">
              {balises.map(balise => (
                <fieldset key={balise.id}>
                  <legend className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{balise.nom}</legend>
                  <div className="flex flex-wrap gap-x-4 gap-y-1.5">
                    {balise.type === 'choix' && (
                      <label className="flex items-center gap-1.5 text-sm text-gray-500">
                        <input type="radio" name={`balise-${balise.id}`} checked={!balise.items.some(i => selection.includes(i.id))}
                          onChange={() => setSelection(selection.filter(id => !balise.items.some(i => i.id === id)))} />
                        {tb.none}
                      </label>
                    )}
                    {balise.items.map(item => (
                      <label key={item.id} className="flex items-center gap-1.5 text-sm">
                        <input type={balise.type === 'choix' ? 'radio' : 'checkbox'} name={`balise-${balise.id}`} className="rounded"
                          checked={selection.includes(item.id)} onChange={e => choisir(balise, item.id, e.target.checked)} />
                        <PastilleBalise item={item} />
                      </label>
                    ))}
                  </div>
                </fieldset>
              ))}
            </div>
            {erreur && <p className="mt-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
            <div className="flex justify-end gap-2 mt-6">
              <button type="button" onClick={() => setOuvert(false)} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">{tb.cancel}</button>
              <button type="button" onClick={enregistrer} disabled={enregistrement} className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">{tb.save}</button>
            </div>
          </div>
        </div>,
        document.body,
      )}
    </div>
  );
}
