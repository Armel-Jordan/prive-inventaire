import { useState } from 'react';
import { createPortal } from 'react-dom';
import { Paperclip, X } from 'lucide-react';
import type { EntitePieceJointe } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';
import PiecesJointes from '@/components/PiecesJointes';

/** Trombone qui ouvre les pièces jointes d'une fiche ou d'un document dans une fenêtre. */
export default function BoutonPiecesJointes({ entite, entiteId, titre, taille = 18, className = '' }: {
  entite: EntitePieceJointe;
  entiteId: number;
  titre?: string;
  taille?: number;
  className?: string;
}) {
  const { t } = useLanguage();
  const ta = t.attachments;
  const [ouvert, setOuvert] = useState(false);

  return (
    <>
      <button type="button" title={ta.button} aria-label={`${ta.button}${titre ? ` — ${titre}` : ''}`} onClick={() => setOuvert(true)}
        className={`p-1 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded ${className}`}>
        <Paperclip size={taille} />
      </button>
      {ouvert && createPortal(
        // Les clics restent dans la fenêtre (la ligne du tableau en dessous a parfois ses propres actions).
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" onClick={e => e.stopPropagation()}>
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto text-left">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-bold dark:text-white">
                {ta.title} — {ta.entities[entite]}{titre ? ` ${titre}` : ''}
              </h3>
              <button type="button" onClick={() => setOuvert(false)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" title={ta.close}>
                <X size={18} />
              </button>
            </div>
            <PiecesJointes entite={entite} entiteId={entiteId} />
          </div>
        </div>,
        document.body,
      )}
    </>
  );
}
