import { FileDown } from 'lucide-react';
import { ouvrirPdf } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';

/** Bouton qui ouvre le PDF d'un document de vente dans un nouvel onglet. */
export default function BoutonPdf({ chemin, nomFichier, taille = 18 }: { chemin: string; nomFichier: string; taille?: number }) {
  const { t } = useLanguage();

  return (
    <button
      type="button"
      title={t.documents.openPdf}
      onClick={() => { ouvrirPdf(chemin, nomFichier).catch(() => alert(t.documents.pdfError)); }}
      className="p-1 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/30 rounded"
    >
      <FileDown size={taille} />
    </button>
  );
}
