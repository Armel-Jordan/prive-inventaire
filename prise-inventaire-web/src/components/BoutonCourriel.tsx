import { useState } from 'react';
import { Mail } from 'lucide-react';
import type { TypeDocumentCourriel } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';
import ComposerCourriel from '@/components/ComposerCourriel';

/** Bouton qui ouvre l'envoi du document par courriel. */
export default function BoutonCourriel({ type, documentId, taille = 18, className = '' }: { type: TypeDocumentCourriel; documentId: number; taille?: number; className?: string }) {
  const { t } = useLanguage();
  const [ouvert, setOuvert] = useState(false);

  return (
    <>
      <button
        type="button"
        title={t.emails.sendByEmail}
        aria-label={t.emails.sendByEmail}
        onClick={() => setOuvert(true)}
        className={`p-1 text-teal-600 hover:bg-teal-50 dark:hover:bg-teal-900/30 rounded ${className}`}
      >
        <Mail size={taille} />
      </button>
      {ouvert && <ComposerCourriel type={type} documentId={documentId} onClose={() => setOuvert(false)} />}
    </>
  );
}
