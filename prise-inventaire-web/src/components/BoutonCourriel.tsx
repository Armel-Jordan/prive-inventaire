import { useState } from 'react';
import { Mail, type LucideIcon } from 'lucide-react';
import type { TypeDocumentCourriel } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';
import ComposerCourriel from '@/components/ComposerCourriel';

/** Bouton qui ouvre l'envoi du document par courriel. */
export default function BoutonCourriel({ type, documentId, taille = 18, className = '', titre, icone: Icone = Mail, onFerme }: {
  type: TypeDocumentCourriel;
  documentId: number;
  taille?: number;
  className?: string;
  /** Libellé du bouton (par défaut : « Envoyer par courriel »). */
  titre?: string;
  /** Icône du bouton (par défaut : une enveloppe). */
  icone?: LucideIcon;
  /** Après la fermeture de la fenêtre (un courriel a pu partir) : pour rafraîchir l'écran. */
  onFerme?: () => void;
}) {
  const { t } = useLanguage();
  const [ouvert, setOuvert] = useState(false);

  return (
    <>
      <button
        type="button"
        title={titre ?? t.emails.sendByEmail}
        aria-label={titre ?? t.emails.sendByEmail}
        onClick={() => setOuvert(true)}
        className={`p-1 text-teal-600 hover:bg-teal-50 dark:hover:bg-teal-900/30 rounded ${className}`}
      >
        <Icone size={taille} />
      </button>
      {ouvert && <ComposerCourriel type={type} documentId={documentId} onClose={() => { setOuvert(false); onFerme?.(); }} />}
    </>
  );
}
