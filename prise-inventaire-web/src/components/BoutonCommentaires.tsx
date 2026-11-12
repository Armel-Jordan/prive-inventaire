import { useState } from 'react';
import { createPortal } from 'react-dom';
import { MessageSquare, Trash2, X } from 'lucide-react';
import { ajouterCommentaire, getCommentaires, retirerCommentaire, type Commentaire, type CompteurCommentaires, type EntiteHistorique } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateHeure } from '@/lib/documents';

/**
 * Bulle qui ouvre les commentaires d'une fiche ou d'un document (socle S4.6) : signés et datés, les non-lus marqués ;
 * la pastille donne les non-lus (sinon le nombre de commentaires). Ouvrir les commentaires les marque lus.
 */
export default function BoutonCommentaires({ entite, entiteId, titre, compteur, onFerme, taille = 18, className = '' }: {
  entite: EntiteHistorique;
  entiteId: number;
  titre?: string;
  compteur?: CompteurCommentaires;
  /** Appelé à la fermeture (les compteurs de la liste se relisent). */
  onFerme?: () => void;
  taille?: number;
  className?: string;
}) {
  const { t, language } = useLanguage();
  const tc = t.docComments;
  const [ouvert, setOuvert] = useState(false);
  const [commentaires, setCommentaires] = useState<Commentaire[] | null>(null);
  const [texte, setTexte] = useState('');
  const [envoi, setEnvoi] = useState(false);
  const [erreur, setErreur] = useState('');

  const ouvrir = () => {
    setOuvert(true);
    setCommentaires(null);
    setErreur('');
    getCommentaires(entite, entiteId).then(setCommentaires, () => setErreur(tc.loadError));
  };
  const fermer = () => {
    setOuvert(false);
    onFerme?.();
  };
  const ajouter = async () => {
    if (!texte.trim()) return;
    setEnvoi(true);
    setErreur('');
    try {
      const nouveau = await ajouterCommentaire(entite, entiteId, texte.trim());
      setCommentaires(cs => [...(cs ?? []), nouveau]);
      setTexte('');
    } catch {
      setErreur(tc.saveError);
    } finally {
      setEnvoi(false);
    }
  };
  const retirer = async (id: number) => {
    if (!confirm(tc.confirmRemove)) return;
    try {
      await retirerCommentaire(id);
      setCommentaires(cs => (cs ?? []).filter(c => c.id !== id));
    } catch {
      setErreur(tc.removeError);
    }
  };

  const nonLus = compteur?.non_lus ?? 0;
  const total = compteur?.total ?? 0;
  const libelle = `${tc.button}${titre ? ` — ${titre}` : ''}${nonLus ? ` (${tc.unread.replace('{n}', String(nonLus))})` : ''}`;

  return (
    <>
      <button type="button" title={libelle} aria-label={libelle} onClick={ouvrir}
        className={`relative p-1 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded ${className}`}>
        <MessageSquare size={taille} />
        {(nonLus > 0 || total > 0) && (
          <span className={`absolute -top-1 -right-1 min-w-4 h-4 px-1 rounded-full text-[10px] leading-4 text-center ${nonLus > 0 ? 'bg-purple-600 text-white' : 'bg-gray-200 text-gray-700 dark:bg-gray-600 dark:text-gray-100'}`}>
            {nonLus > 0 ? nonLus : total}
          </span>
        )}
      </button>
      {ouvert && createPortal(
        // Les clics restent dans la fenêtre (la ligne du tableau en dessous a parfois ses propres actions).
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" onClick={e => e.stopPropagation()}
          role="dialog" aria-modal="true" aria-label={`${tc.title}${titre ? ` — ${titre}` : ''}`}>
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-xl max-h-[90vh] overflow-y-auto text-left">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-bold dark:text-white">{tc.title}{titre ? ` — ${titre}` : ''}</h3>
              <button type="button" onClick={fermer} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" title={tc.close} aria-label={tc.close}>
                <X size={18} />
              </button>
            </div>
            {commentaires === null && !erreur && <p className="text-sm text-gray-500">{tc.loading}</p>}
            {commentaires?.length === 0 && <p className="text-sm text-gray-500">{tc.empty}</p>}
            <ul className="space-y-3 mb-4">
              {commentaires?.map(c => (
                <li key={c.id} className={`rounded-lg p-3 text-sm ${c.non_lu ? 'bg-purple-50 dark:bg-purple-900/30' : 'bg-gray-50 dark:bg-gray-700/50'}`}>
                  <div className="flex items-start justify-between gap-2">
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                      <span className="font-medium text-gray-700 dark:text-gray-200">{c.auteur ?? '—'}</span>
                      {c.date && ` · ${formatDateHeure(c.date, language)}`}
                      {c.non_lu && <span className="ml-2 text-purple-700 dark:text-purple-300">{tc.new}</span>}
                    </p>
                    {c.moi && (
                      <button type="button" onClick={() => retirer(c.id)} className="text-gray-400 hover:text-red-600" title={tc.remove} aria-label={tc.remove}>
                        <Trash2 size={14} />
                      </button>
                    )}
                  </div>
                  <p className="mt-1 whitespace-pre-line dark:text-white">{c.texte}</p>
                </li>
              ))}
            </ul>
            <label htmlFor={`commentaire-${entite}-${entiteId}`} className="block text-sm font-medium mb-1 dark:text-gray-300">{tc.add}</label>
            <textarea id={`commentaire-${entite}-${entiteId}`} value={texte} onChange={e => setTexte(e.target.value)} rows={3} maxLength={2000}
              className="w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
            {erreur && <p className="text-sm text-red-600 mt-1" role="alert">{erreur}</p>}
            <div className="flex justify-end gap-2 mt-3">
              <button type="button" onClick={fermer} className="px-4 py-2 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tc.close}</button>
              <button type="button" onClick={ajouter} disabled={envoi || !texte.trim()}
                className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">{tc.send}</button>
            </div>
          </div>
        </div>,
        document.body,
      )}
    </>
  );
}
