import { useState } from 'react';
import { createPortal } from 'react-dom';
import { History, X } from 'lucide-react';
import { getHistoriqueEntite, type EntiteHistorique, type EntreeHistorique } from '@/services/api';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { formatDateDocument, formatDateHeure } from '@/lib/documents';

/**
 * Horloge qui ouvre l'historique d'une fiche ou d'un document (socle S4.6) : créations et modifications, champ par
 * champ (avant → après), avec l'auteur et l'heure. Affichée seulement avec le droit de voir l'historique.
 */
export default function BoutonHistorique({ entite, entiteId, titre, taille = 18, className = '' }: {
  entite: EntiteHistorique;
  entiteId: number;
  titre?: string;
  taille?: number;
  className?: string;
}) {
  const { t, language } = useLanguage();
  const th = t.changeHistory;
  const { peut } = usePermissions();
  const [ouvert, setOuvert] = useState(false);
  const [entrees, setEntrees] = useState<EntreeHistorique[] | null>(null);
  const [erreur, setErreur] = useState(false);

  if (!peut('historique', 'voir')) return null;

  const ouvrir = () => {
    setOuvert(true);
    setEntrees(null);
    setErreur(false);
    getHistoriqueEntite(entite, entiteId).then(setEntrees, () => setErreur(true));
  };

  // Valeur lisible : le nom de la fiche désignée, une date, oui ou non, sinon le texte.
  const valeur = (v: unknown, texte: string | null) => {
    if (texte) return texte;
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'boolean') return v ? th.yes : th.no;
    if (typeof v === 'string' && /^\d{4}-\d{2}-\d{2}/.test(v)) {
      return v.length > 10 && !/[T ]00:00:00/.test(v) ? formatDateHeure(v, language) : formatDateDocument(v, language);
    }
    return typeof v === 'object' ? JSON.stringify(v) : String(v);
  };

  return (
    <>
      <button type="button" title={th.button} aria-label={`${th.button}${titre ? ` — ${titre}` : ''}`} onClick={ouvrir}
        className={`p-1 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded ${className}`}>
        <History size={taille} />
      </button>
      {ouvert && createPortal(
        // Les clics restent dans la fenêtre (la ligne du tableau en dessous a parfois ses propres actions).
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" onClick={e => e.stopPropagation()}
          role="dialog" aria-modal="true" aria-label={`${th.title}${titre ? ` — ${titre}` : ''}`}>
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto text-left">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-bold dark:text-white">{th.title}{titre ? ` — ${titre}` : ''}</h3>
              <button type="button" onClick={() => setOuvert(false)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" title={th.close} aria-label={th.close}>
                <X size={18} />
              </button>
            </div>
            {erreur && <p className="text-sm text-red-600">{th.error}</p>}
            {!erreur && entrees === null && <p className="text-sm text-gray-500">{th.loading}</p>}
            {entrees?.length === 0 && <p className="text-sm text-gray-500">{th.empty}</p>}
            <ol className="space-y-3">
              {entrees?.map(e => (
                <li key={e.id} className="border-l-2 border-purple-300 dark:border-purple-700 pl-3">
                  <p className="text-sm dark:text-white">
                    <span className="font-medium">{th.actions[e.action] ?? e.action}</span>
                    <span className="text-gray-500"> — {e.date ? formatDateHeure(e.date, language) : ''}{e.auteur ? ` · ${th.by.replace('{auteur}', e.auteur)}` : ''}</span>
                  </p>
                  {e.champs.length > 0 && (
                    <ul className="mt-1 text-sm text-gray-700 dark:text-gray-300 space-y-0.5">
                      {e.champs.map(c => (
                        <li key={c.champ}>
                          <span className="text-gray-500">{c.libelle} :</span>{' '}
                          <span className={valeur(c.avant, c.avant_texte) === '—' ? 'text-gray-400' : 'line-through decoration-gray-400'}>{valeur(c.avant, c.avant_texte)}</span>{' → '}
                          <span>{valeur(c.apres, c.apres_texte)}</span>
                        </li>
                      ))}
                    </ul>
                  )}
                </li>
              ))}
            </ol>
          </div>
        </div>,
        document.body,
      )}
    </>
  );
}
