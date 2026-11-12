import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { CornerUpLeft, Files } from 'lucide-react';
import { getLiensCopies, type DocumentCopiable, type LienCopie } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';

/** Écran de chaque type de document : la liste filtrée sur le numéro. */
const CHEMINS: Record<DocumentCopiable, string> = {
  devis: '/devis',
  commande_client: '/commandes-client',
  facture: '/factures',
};

/**
 * Liens « Copié de » et « Copies » d'un devis, d'une commande ou d'une facture (socle S4.3), dans son détail : le
 * document d'origine d'une copie, et les documents copiés à partir de lui. Rien ne s'affiche sans lien.
 */
export default function LiensCopies({ type, id }: { type: DocumentCopiable; id: number }) {
  const { t } = useLanguage();
  const tc = t.copyTo;
  const [liens, setLiens] = useState<{ cle: string; copie_de: LienCopie | null; copies: LienCopie[] } | null>(null);
  const cle = `${type}:${id}`;

  useEffect(() => {
    let actif = true;
    getLiensCopies(type, id).then(r => { if (actif) setLiens({ cle: `${type}:${id}`, ...r }); }, () => undefined);
    return () => { actif = false; };
  }, [type, id]);

  if (!liens || liens.cle !== cle || (!liens.copie_de && liens.copies.length === 0)) return null;

  const lien = (l: LienCopie) => (
    <Link key={`${l.type}-${l.id}`} to={`${CHEMINS[l.type]}?recherche=${encodeURIComponent(l.numero)}`}
      className="text-blue-600 hover:underline dark:text-blue-400" title={tc.types[l.type]}>
      {l.numero}
    </Link>
  );

  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
      {liens.copie_de && (
        <span className="inline-flex items-center gap-1"><CornerUpLeft size={14} /> {tc.copiedFrom} : {lien(liens.copie_de)} <span className="text-xs text-gray-400">({tc.types[liens.copie_de.type]})</span></span>
      )}
      {liens.copies.length > 0 && (
        <span className="inline-flex flex-wrap items-center gap-1"><Files size={14} /> {tc.copies} :
          {liens.copies.map((l, i) => <span key={`${l.type}-${l.id}`}>{lien(l)}{i < liens.copies.length - 1 ? ',' : ''}</span>)}
        </span>
      )}
    </div>
  );
}
