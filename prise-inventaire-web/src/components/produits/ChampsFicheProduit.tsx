import { useEffect, useRef, useState } from 'react';
import { ImagePlus } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { LANGUES_TRADUCTION, TYPES_IMAGE_PRODUIT as TYPES_IMAGE } from '@/lib/ficheProduit';
import { ajouterPiecesJointes, getContenuPieceJointe, getPiecesJointes, getReferentiel } from '@/services/api';

export type TraductionsSaisies = Record<string, { description: string; description_longue: string }>;

export interface ValeursFiche {
  fabricant_id: string;
  reference_fabricant: string;
  description_longue: string;
  traductions: TraductionsSaisies;
  image_id: number | null;
}

interface Apercu {
  id: number;
  nom: string;
  url: string;
}

const classeChamp = 'w-full px-3 py-2 border dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm';
const classeEtiquette = 'block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1';
const classeAide = 'text-xs text-gray-500 dark:text-gray-400';

/**
 * Fiche détaillée d'un produit : fabricant (référentiel) et référence chez lui, description longue, descriptions
 * traduites, et image principale choisie parmi les images jointes à la fiche (ou ajoutée ici, jointe aussitôt).
 */
export default function ChampsFicheProduit({ valeurs, onChange, produitId, fabricantActuel }: {
  valeurs: ValeursFiche;
  onChange: (modification: Partial<ValeursFiche>) => void;
  /** Fiche en modification ; à la création, l'image se choisit une fois le produit créé. */
  produitId: number | null;
  /** Fabricant enregistré sur la fiche : proposé même s'il est désactivé ou retiré du référentiel. */
  fabricantActuel?: { id: number; nom: string } | null;
}) {
  const { t } = useLanguage();
  const tf = t.productRecord;
  const [fabricants, setFabricants] = useState<{ id: number; nom: string }[]>([]);
  const [images, setImages] = useState<Apercu[] | null>(null);
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState('');
  // Ouvertes d'emblée quand la fiche a déjà des traductions.
  const [traductionsOuvertes] = useState(() => Object.values(valeurs.traductions).some(tr => tr.description || tr.description_longue));
  const adresses = useRef<string[]>([]);

  useEffect(() => {
    getReferentiel('fabricants', true)
      .then(liste => setFabricants(liste.map(f => ({ id: f.id, nom: String(f.nom ?? '') }))))
      .catch(() => {});
  }, []);

  // Aperçus des images jointes à la fiche (une image illisible est simplement omise).
  useEffect(() => {
    if (!produitId) return;
    let abandonne = false;
    getPiecesJointes({ entite: 'produit', entite_id: produitId })
      .then(page => Promise.all(page.data.filter(p => p.type_mime && TYPES_IMAGE.includes(p.type_mime)).map(p =>
        getContenuPieceJointe(p.id).then(contenu => {
          const url = URL.createObjectURL(contenu);
          adresses.current.push(url);
          return { id: p.id, nom: p.nom_original, url };
        }, () => null))))
      .then(apercus => { if (!abandonne) setImages(apercus.filter((a): a is Apercu => a !== null)); })
      .catch(e => { if (!abandonne) { setImages([]); setErreur(messageErreur(e, tf.imagesError)); } });
    return () => { abandonne = true; };
  }, [produitId, tf.imagesError]);

  // Adresses temporaires des aperçus libérées à la fermeture du formulaire.
  useEffect(() => () => adresses.current.forEach(url => URL.revokeObjectURL(url)), []);

  async function ajouterImage(fichier: File) {
    if (!produitId) return;
    if (!TYPES_IMAGE.includes(fichier.type)) {
      setErreur(tf.imageType);
      return;
    }
    setEnvoi(true);
    setErreur('');
    try {
      const [piece] = await ajouterPiecesJointes('produit', produitId, [{ fichier, description: '' }], '');
      // Le serveur lit le type dans le contenu : c'est lui qui décide.
      if (!piece.type_mime || !TYPES_IMAGE.includes(piece.type_mime)) {
        setErreur(tf.imageType);
        return;
      }
      const url = URL.createObjectURL(fichier);
      adresses.current.push(url);
      setImages(liste => [...(liste ?? []), { id: piece.id, nom: piece.nom_original, url }]);
      onChange({ image_id: piece.id });
    } catch (e) {
      setErreur(messageErreur(e, tf.imagesError));
    } finally {
      setEnvoi(false);
    }
  }

  function traduire(langue: string, modification: Partial<TraductionsSaisies[string]>) {
    const actuelle = valeurs.traductions[langue] ?? { description: '', description_longue: '' };
    onChange({ traductions: { ...valeurs.traductions, [langue]: { ...actuelle, ...modification } } });
  }

  const choix = fabricantActuel && !fabricants.some(f => f.id === fabricantActuel.id) ? [fabricantActuel, ...fabricants] : fabricants;
  const vignette = (choisie: boolean) =>
    `w-20 h-20 rounded-lg border-2 overflow-hidden flex items-center justify-center text-xs ${choisie ? 'border-green-600 ring-2 ring-green-600/30' : 'border-gray-200 dark:border-gray-600'}`;

  return (
    <div className="border dark:border-gray-600 rounded-lg p-3 bg-gray-50 dark:bg-gray-700/50 space-y-3">
      <p className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{tf.section}</p>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label htmlFor="produit-fabricant" className={classeEtiquette}>{tf.manufacturer}</label>
          <select id="produit-fabricant" value={valeurs.fabricant_id} onChange={e => onChange({ fabricant_id: e.target.value })} className={classeChamp}>
            <option value="">{tf.noManufacturer}</option>
            {choix.map(f => <option key={f.id} value={String(f.id)}>{f.nom}</option>)}
          </select>
        </div>
        <div>
          <label htmlFor="produit-reference-fabricant" className={classeEtiquette}>{tf.manufacturerRef}</label>
          <input id="produit-reference-fabricant" value={valeurs.reference_fabricant} maxLength={60}
            onChange={e => onChange({ reference_fabricant: e.target.value })} className={classeChamp} />
        </div>
      </div>
      <div>
        <label htmlFor="produit-description-longue" className={classeEtiquette}>{tf.longDescription}</label>
        <textarea id="produit-description-longue" rows={3} maxLength={10000} value={valeurs.description_longue}
          onChange={e => onChange({ description_longue: e.target.value })} className={classeChamp} />
        <p className={`${classeAide} mt-1`}>{tf.longDescriptionHelp}</p>
      </div>
      <details open={traductionsOuvertes}>
        <summary className="text-xs font-medium text-gray-600 dark:text-gray-300 cursor-pointer select-none">{tf.translations}</summary>
        <p className={`${classeAide} mt-1`}>{tf.translationsHelp}</p>
        {LANGUES_TRADUCTION.map(langue => (
          <fieldset key={langue} className="mt-2 space-y-2">
            <legend className="text-xs font-semibold text-gray-600 dark:text-gray-300">{tf.languages[langue] ?? langue}</legend>
            <div>
              <label htmlFor={`produit-description-${langue}`} className={classeEtiquette}>{tf.description} ({langue.toUpperCase()})</label>
              <input id={`produit-description-${langue}`} maxLength={255} value={valeurs.traductions[langue]?.description ?? ''}
                onChange={e => traduire(langue, { description: e.target.value })} className={classeChamp} />
            </div>
            <div>
              <label htmlFor={`produit-description-longue-${langue}`} className={classeEtiquette}>{tf.longDescription} ({langue.toUpperCase()})</label>
              <textarea id={`produit-description-longue-${langue}`} rows={2} maxLength={10000} value={valeurs.traductions[langue]?.description_longue ?? ''}
                onChange={e => traduire(langue, { description_longue: e.target.value })} className={classeChamp} />
            </div>
          </fieldset>
        ))}
      </details>
      <div>
        <p className={classeEtiquette} id="produit-image">{tf.image}</p>
        {!produitId ? (
          <p className={classeAide}>{tf.imageAfterCreate}</p>
        ) : images === null ? (
          <p className={classeAide}>{tf.loadingImages}</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            <div role="radiogroup" aria-labelledby="produit-image" className="flex flex-wrap gap-2">
              <button type="button" role="radio" aria-checked={valeurs.image_id === null} onClick={() => onChange({ image_id: null })}
                className={`${vignette(valeurs.image_id === null)} text-gray-500 dark:text-gray-400`}>
                {tf.noImage}
              </button>
              {images.map(image => (
                <button key={image.id} type="button" role="radio" aria-checked={valeurs.image_id === image.id} aria-label={image.nom} title={image.nom}
                  onClick={() => onChange({ image_id: image.id })} className={`${vignette(valeurs.image_id === image.id)} bg-white`}>
                  <img src={image.url} alt="" className="w-full h-full object-contain" />
                </button>
              ))}
            </div>
            <label className={`${vignette(false)} border-dashed flex-col gap-1 text-gray-600 dark:text-gray-300 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 text-center`}>
              <ImagePlus size={18} aria-hidden="true" />
              {envoi ? tf.sending : tf.addImage}
              <input type="file" accept={TYPES_IMAGE.join(',')} className="sr-only" disabled={envoi}
                onChange={e => { const fichier = e.target.files?.[0]; e.target.value = ''; if (fichier) ajouterImage(fichier); }} />
            </label>
          </div>
        )}
        <p className={`${classeAide} mt-1`}>{tf.imageHelp}</p>
        {erreur && <p role="alert" className="text-xs text-red-600 dark:text-red-400 mt-1">{erreur}</p>}
      </div>
    </div>
  );
}
