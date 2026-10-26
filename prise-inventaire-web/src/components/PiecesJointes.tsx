import { useCallback, useEffect, useRef, useState } from 'react';
import { Upload, FileText, Image as ImageIcon, File as FileIcon, Pencil, Trash2, X, Check } from 'lucide-react';
import {
  getPiecesJointes, getCategoriesPiecesJointes, ajouterPiecesJointes, modifierPieceJointe, supprimerPieceJointe, ouvrirPieceJointe,
  DROITS_PIECES_JOINTES, type EntitePieceJointe, type PieceJointe,
} from '@/services/api';
import { usePermissions } from '@/contexts/PermissionsContext';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { formatTaille } from '@/lib/utils';

const classeChamp = 'w-full px-2 py-1.5 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

export function IconePieceJointe({ type }: { type: string | null }) {
  if (type?.startsWith('image/')) return <ImageIcon size={16} className="text-teal-600 shrink-0" />;
  if (type === 'application/pdf') return <FileText size={16} className="text-red-600 shrink-0" />;
  return <FileIcon size={16} className="text-gray-500 shrink-0" />;
}


/**
 * Pièces jointes d'une fiche ou d'un document : ajout de plusieurs fichiers (sélection, glisser-déposer,
 * image collée), une description par fichier et une catégorie commune, ouverture, modification, suppression.
 * Ajouter, modifier et retirer demandent le droit de modifier la fiche ; sinon les pièces se consultent seulement.
 */
export default function PiecesJointes({ entite, entiteId }: { entite: EntitePieceJointe; entiteId: number }) {
  const { t, language } = useLanguage();
  const ta = t.attachments;
  const anglais = language === 'en';
  const { peut } = usePermissions();
  const modifiable = peut(...DROITS_PIECES_JOINTES[entite]);

  const [pieces, setPieces] = useState<PieceJointe[]>([]);
  const [categories, setCategories] = useState<string[]>([]);
  const [chargement, setChargement] = useState(true);
  const [message, setMessage] = useState<{ erreur: boolean; texte: string } | null>(null);
  const [aEnvoyer, setAEnvoyer] = useState<{ fichier: File; description: string }[]>([]);
  const [categorie, setCategorie] = useState('');
  const [envoiEnCours, setEnvoiEnCours] = useState(false);
  const [survol, setSurvol] = useState(false);
  const [edition, setEdition] = useState<{ id: number; description: string; categorie: string } | null>(null);
  const selecteur = useRef<HTMLInputElement>(null);
  const idSuggestions = `categories-${entite}-${entiteId}`;

  // 413 : refusé par le serveur web avant l'application (limite d'envoi de nginx ou de PHP).
  const messageErreur = useCallback((erreur: unknown) => {
    if (erreur instanceof ApiError) return erreur.status === 413 ? ta.tooLarge : erreur.message;
    return ta.error;
  }, [ta.error, ta.tooLarge]);

  const recharger = useCallback(async () => {
    const [page, liste] = await Promise.all([getPiecesJointes({ entite, entite_id: entiteId }), getCategoriesPiecesJointes(entite)]);
    setPieces(page.data);
    setCategories(liste);
  }, [entite, entiteId]);

  useEffect(() => {
    let actif = true;
    Promise.all([getPiecesJointes({ entite, entite_id: entiteId }), getCategoriesPiecesJointes(entite)])
      .then(([page, liste]) => {
        if (!actif) return;
        setPieces(page.data);
        setCategories(liste);
      })
      .catch(erreur => { if (actif) setMessage({ erreur: true, texte: messageErreur(erreur) }); })
      .finally(() => { if (actif) setChargement(false); });
    return () => { actif = false; };
  }, [entite, entiteId, messageErreur]);

  const ajouterFichiers = useCallback((fichiers: File[]) => {
    if (fichiers.length) setAEnvoyer(liste => [...liste, ...fichiers.map(fichier => ({ fichier, description: '' }))]);
  }, []);

  // Image collée (capture d'écran…) pendant que le panneau est ouvert, hors des champs de saisie.
  useEffect(() => {
    if (!modifiable) return;
    function coller(e: ClipboardEvent) {
      const cible = e.target as HTMLElement | null;
      if (cible && ['INPUT', 'TEXTAREA'].includes(cible.tagName)) return;
      const fichiers = Array.from(e.clipboardData?.files ?? []);
      if (!fichiers.length) return;
      e.preventDefault();
      const horodatage = new Date().toISOString().slice(0, 19).replace('T', ' ').replace(/:/g, '');
      ajouterFichiers(fichiers.map(f => (f.type.startsWith('image/')
        ? new File([f], `${ta.pastedImage} ${horodatage}.${f.type.split('/')[1] || 'png'}`, { type: f.type })
        : f)));
    }
    document.addEventListener('paste', coller);
    return () => document.removeEventListener('paste', coller);
  }, [ajouterFichiers, modifiable, ta.pastedImage]);

  async function envoyer() {
    setEnvoiEnCours(true);
    setMessage(null);
    try {
      await ajouterPiecesJointes(entite, entiteId, aEnvoyer, categorie);
      setAEnvoyer([]);
      setCategorie('');
      await recharger();
      setMessage({ erreur: false, texte: ta.added });
    } catch (erreur) {
      setMessage({ erreur: true, texte: messageErreur(erreur) });
    } finally {
      setEnvoiEnCours(false);
    }
  }

  async function enregistrerEdition() {
    if (!edition) return;
    try {
      const modifiee = await modifierPieceJointe(edition.id, { description: edition.description || null, categorie: edition.categorie || null });
      setPieces(liste => liste.map(p => (p.id === modifiee.id ? modifiee : p)));
      setEdition(null);
      setMessage({ erreur: false, texte: ta.updated });
      getCategoriesPiecesJointes(entite).then(setCategories).catch(() => {});
    } catch (erreur) {
      setMessage({ erreur: true, texte: messageErreur(erreur) });
    }
  }

  async function supprimer(piece: PieceJointe) {
    if (!confirm(ta.confirmDelete)) return;
    try {
      await supprimerPieceJointe(piece.id);
      setPieces(liste => liste.filter(p => p.id !== piece.id));
      setMessage({ erreur: false, texte: ta.deleted });
    } catch (erreur) {
      setMessage({ erreur: true, texte: messageErreur(erreur) });
    }
  }

  const date = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString(anglais ? 'en-US' : 'fr-FR') : '');

  return (
    <div className="space-y-4">
      {message && (
        <p className={`px-3 py-2 rounded-lg text-sm ${message.erreur ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}>{message.texte}</p>
      )}

      {/* Zone d'ajout : clic, glisser-déposer ou image collée */}
      {modifiable && <>
      <button
        type="button"
        onClick={() => selecteur.current?.click()}
        onDragOver={e => { e.preventDefault(); setSurvol(true); }}
        onDragLeave={() => setSurvol(false)}
        onDrop={e => { e.preventDefault(); setSurvol(false); ajouterFichiers(Array.from(e.dataTransfer.files)); }}
        className={`w-full flex flex-col items-center gap-1 px-4 py-5 border-2 border-dashed rounded-lg text-sm transition-colors ${survol ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20' : 'border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'}`}
      >
        <Upload size={20} className="text-purple-600" />
        <span className="font-medium text-gray-700 dark:text-gray-200">{ta.add}</span>
        <span className="text-xs text-gray-500">{ta.dropHere}</span>
      </button>
      <input ref={selecteur} type="file" multiple className="hidden" aria-label={ta.add}
        onChange={e => { ajouterFichiers(Array.from(e.target.files ?? [])); e.target.value = ''; }} />
      </>}

      {aEnvoyer.length > 0 && (
        <div className="p-3 bg-gray-50 dark:bg-gray-700 rounded-lg space-y-2">
          {aEnvoyer.map((element, index) => (
            <div key={`${element.fichier.name}-${index}`} className="flex flex-wrap items-center gap-2">
              <IconePieceJointe type={element.fichier.type} />
              <span className="text-sm dark:text-white truncate max-w-[14rem]" title={element.fichier.name}>{element.fichier.name}</span>
              <span className="text-xs text-gray-500">{formatTaille(element.fichier.size, anglais)}</span>
              <input value={element.description} placeholder={ta.description} aria-label={`${ta.description} — ${element.fichier.name}`} maxLength={500}
                onChange={e => setAEnvoyer(liste => liste.map((x, i) => (i === index ? { ...x, description: e.target.value } : x)))}
                className={`${classeChamp} flex-1 min-w-[10rem]`} />
              <button type="button" onClick={() => setAEnvoyer(liste => liste.filter((_, i) => i !== index))} title={ta.remove}
                className="p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><X size={14} /></button>
            </div>
          ))}
          <div className="flex flex-wrap items-end gap-2 pt-1">
            <label className="block flex-1 min-w-[12rem]">
              <span className="block text-xs text-gray-500 mb-1">{ta.category}</span>
              <input value={categorie} onChange={e => setCategorie(e.target.value)} list={idSuggestions} maxLength={100}
                placeholder={ta.categoryPlaceholder} className={classeChamp} />
            </label>
            <button type="button" onClick={() => setAEnvoyer([])} className="px-3 py-1.5 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600 rounded-lg">{ta.cancel}</button>
            <button type="button" onClick={envoyer} disabled={envoiEnCours}
              className="px-3 py-1.5 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">
              {envoiEnCours ? ta.saving : ta.save}
            </button>
          </div>
        </div>
      )}
      <datalist id={idSuggestions}>{categories.map(c => <option key={c} value={c} />)}</datalist>

      {chargement ? null : pieces.length === 0 ? (
        <p className="text-sm text-gray-500">{ta.empty}</p>
      ) : (
        <ul className="divide-y dark:divide-gray-700">
          {pieces.map(piece => (
            <li key={piece.id} className="py-2 flex flex-wrap items-center gap-2">
              <IconePieceJointe type={piece.type_mime} />
              <div className="flex-1 min-w-[12rem]">
                <button type="button" onClick={() => ouvrirPieceJointe(piece).catch(() => setMessage({ erreur: true, texte: ta.openError }))}
                  className="text-sm font-medium text-purple-700 dark:text-purple-300 hover:underline text-left break-all" title={ta.open}>
                  {piece.nom_original}
                </button>
                {edition?.id === piece.id ? (
                  <div className="flex flex-wrap gap-2 mt-1">
                    <input value={edition.description} onChange={e => setEdition({ ...edition, description: e.target.value })} placeholder={ta.description}
                      aria-label={ta.description} maxLength={500} className={`${classeChamp} flex-1 min-w-[10rem]`} />
                    <input value={edition.categorie} onChange={e => setEdition({ ...edition, categorie: e.target.value })} placeholder={ta.category}
                      aria-label={ta.category} list={idSuggestions} maxLength={100} className={`${classeChamp} w-40`} />
                    <button type="button" onClick={enregistrerEdition} title={ta.save} className="p-1 text-green-600 hover:bg-green-50 rounded"><Check size={16} /></button>
                    <button type="button" onClick={() => setEdition(null)} title={ta.cancel} className="p-1 text-gray-500 hover:bg-gray-100 rounded"><X size={16} /></button>
                  </div>
                ) : (
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    {piece.categorie && <span className="mr-2 px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">{piece.categorie}</span>}
                    {piece.description && <span className="mr-2">{piece.description}</span>}
                    {formatTaille(piece.taille, anglais)} · {date(piece.created_at)}{piece.auteur ? ` · ${piece.auteur}` : ''}
                  </p>
                )}
              </div>
              {modifiable && edition?.id !== piece.id && (
                <>
                  <button type="button" onClick={() => setEdition({ id: piece.id, description: piece.description ?? '', categorie: piece.categorie ?? '' })}
                    title={ta.edit} aria-label={`${ta.edit} — ${piece.nom_original}`} className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded">
                    <Pencil size={14} />
                  </button>
                  <button type="button" onClick={() => supprimer(piece)} title={ta.delete} aria-label={`${ta.delete} — ${piece.nom_original}`}
                    className="p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30 rounded">
                    <Trash2 size={14} />
                  </button>
                </>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
