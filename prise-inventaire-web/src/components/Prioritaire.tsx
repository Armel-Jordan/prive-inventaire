import { useState } from 'react';
import { Star } from 'lucide-react';
import { poserPrioritaire, type DocumentPrioritaire } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';

interface EtoileProps {
  document: DocumentPrioritaire;
  documentId: number;
  valeur: boolean | undefined;
  /** Droit de modifier le document : sans lui, l'étoile s'affiche seulement si le document est prioritaire. */
  modifiable: boolean;
  onChange: (prioritaire: boolean) => void;
}

/**
 * Étoile « prioritaire » d'un devis, d'une commande, d'une facture ou d'un bon de livraison (socle S4.1) : enregistrée
 * tout de suite, même sur un document clos.
 */
export function EtoilePrioritaire({ document, documentId, valeur, modifiable, onChange }: EtoileProps) {
  const { t } = useLanguage();
  const tr = t.priority;
  const [envoi, setEnvoi] = useState(false);
  const actif = !!valeur;
  const dessin = actif ? 'text-amber-500 fill-amber-400' : 'text-gray-300 hover:text-amber-400 dark:text-gray-500';

  if (!modifiable) {
    return actif ? <span title={tr.label} aria-label={tr.label} className="inline-flex align-middle ml-1"><Star size={14} className={dessin} /></span> : null;
  }

  const basculer = async () => {
    setEnvoi(true);
    try {
      onChange((await poserPrioritaire(document, documentId, !actif)).prioritaire);
    } catch {
      alert(tr.error);
    } finally {
      setEnvoi(false);
    }
  };

  return (
    <button type="button" onClick={basculer} disabled={envoi} aria-pressed={actif} title={actif ? tr.remove : tr.mark} aria-label={actif ? tr.remove : tr.mark}
      className="inline-flex align-middle ml-1 p-0.5 rounded disabled:opacity-50">
      <Star size={14} className={dessin} />
    </button>
  );
}

/** Filtre des listes : les documents prioritaires seulement. */
export function FiltrePrioritaires({ actif, onChange }: { actif: boolean; onChange: (actif: boolean) => void }) {
  const { t } = useLanguage();

  return (
    <button type="button" onClick={() => onChange(!actif)} aria-pressed={actif}
      className={`inline-flex items-center gap-1 px-3 py-2 border rounded-lg text-sm dark:border-gray-600 ${actif ? 'bg-amber-50 border-amber-300 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200' : 'dark:text-white'}`}>
      <Star size={14} className={actif ? 'text-amber-500 fill-amber-400' : 'text-gray-400'} /> {t.priority.filter}
    </button>
  );
}
