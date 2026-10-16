import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { ListPlus, X } from 'lucide-react';
import { champsPersonnalisesActifs, type EntiteChampPersonnalise } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';
import ChampsPersonnalisesFiche from '@/components/ChampsPersonnalisesFiche';

/**
 * Bouton des champs personnalisés d'une fiche : n'apparaît que si l'entreprise a défini des champs
 * actifs pour ce type de fiche (une seule requête pour toutes les lignes de la liste).
 */
export default function BoutonChampsPersonnalises({ entite, entiteId, titre, taille = 18, className = '' }: {
  entite: EntiteChampPersonnalise;
  entiteId: number;
  titre?: string;
  taille?: number;
  className?: string;
}) {
  const { t } = useLanguage();
  const tc = t.customFields;
  const [disponible, setDisponible] = useState(false);
  const [ouvert, setOuvert] = useState(false);

  useEffect(() => {
    let actif = true;
    champsPersonnalisesActifs(entite).then(champs => { if (actif) setDisponible(champs.length > 0); }).catch(() => {});
    return () => { actif = false; };
  }, [entite]);

  if (!disponible) return null;

  return (
    <>
      <button type="button" title={tc.button} aria-label={`${tc.button}${titre ? ` — ${titre}` : ''}`} onClick={() => setOuvert(true)}
        className={`p-1 text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 rounded ${className}`}>
        <ListPlus size={taille} />
      </button>
      {ouvert && createPortal(
        // Les clics restent dans la fenêtre (la ligne du tableau en dessous a parfois ses propres actions).
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" onClick={e => e.stopPropagation()}>
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-xl max-h-[90vh] overflow-y-auto text-left">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-bold dark:text-white">{tc.title}{titre ? ` — ${titre}` : ''}</h3>
              <button type="button" onClick={() => setOuvert(false)} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" title={tc.close}>
                <X size={18} />
              </button>
            </div>
            <ChampsPersonnalisesFiche entite={entite} entiteId={entiteId} />
          </div>
        </div>,
        document.body,
      )}
    </>
  );
}
