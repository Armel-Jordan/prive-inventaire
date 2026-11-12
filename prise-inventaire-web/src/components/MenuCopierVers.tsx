import { Copy } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import type { CibleCopie, DocumentCopiable } from '@/services/api';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';

/** Écran et module de chaque type de document cible. */
const CIBLES: Record<CibleCopie, { chemin: string; module: string }> = {
  devis: { chemin: '/devis', module: 'devis' },
  commande: { chemin: '/commandes-client', module: 'commandes_client' },
  facture: { chemin: '/factures', module: 'factures' },
};

/**
 * « Copier vers » (socle S4.3), dans le détail d'un devis, d'une commande ou d'une facture : ouvre l'écran du type choisi
 * avec un nouveau document prérempli (client, en-tête, lignes et prix), rien n'étant enregistré avant « Créer ».
 * Seuls les types que le compte peut créer sont proposés.
 */
export default function MenuCopierVers({ source, id }: { source: DocumentCopiable; id: number }) {
  const { t } = useLanguage();
  const tc = t.copyTo;
  const { peut } = usePermissions();
  const navigate = useNavigate();
  const cibles = (Object.keys(CIBLES) as CibleCopie[]).filter(c => peut(CIBLES[c].module, 'creer'));

  if (cibles.length === 0) return null;

  return (
    <div className="flex flex-wrap items-center gap-2 text-sm" role="group" aria-label={tc.label}>
      <span className="inline-flex items-center gap-1 text-gray-500 dark:text-gray-400"><Copy size={14} /> {tc.label} :</span>
      {cibles.map(cible => (
        <button key={cible} type="button" onClick={() => navigate(`${CIBLES[cible].chemin}?copie=${source}:${id}`)}
          className="px-3 py-1 border rounded-lg hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">
          {tc.targets[cible]}
        </button>
      ))}
    </div>
  );
}
