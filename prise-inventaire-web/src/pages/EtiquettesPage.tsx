import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Tags } from 'lucide-react';
import OngletFormats from '@/components/etiquettes/OngletFormats';
import OngletImpression from '@/components/etiquettes/OngletImpression';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { getFormatsEtiquettes, type CatalogueEtiquettes } from '@/services/etiquettes';

type Onglet = 'imprimer' | 'formats';

/**
 * Étiquettes des produits (Paramètres → Étiquettes) : l'impression (format, prix, produits et exemplaires) et les
 * formats (rouleau ou planche, taille, contenu). Le lien « Étiquettes » d'un produit arrive ici avec ce produit
 * (?produits=12) ; les formats d'origine sont créés à la première visite.
 */
export default function EtiquettesPage() {
  const { t } = useLanguage();
  const tl = t.labels;
  const { peut } = usePermissions();
  const [parametres] = useSearchParams();
  const [onglet, setOnglet] = useState<Onglet>('imprimer');
  const [catalogue, setCatalogue] = useState<CatalogueEtiquettes | null>(null);
  const [message, setMessage] = useState('');
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);

  useEffect(() => {
    let actif = true;
    getFormatsEtiquettes().then(c => { if (actif) { setCatalogue(c); setErreur(''); } }, e => { if (actif) setErreur(messageErreur(e, tl.error)); });
    return () => { actif = false; };
  }, [version, tl.error]);

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Tags size={24} /> {tl.title}</h1>
        <p className="text-sm text-gray-500 max-w-3xl">{tl.subtitle}</p>
      </div>
      <div role="tablist" className="flex gap-1 border-b dark:border-gray-700">
        {(['imprimer', 'formats'] as Onglet[]).map(o => (
          <button key={o} role="tab" type="button" aria-selected={onglet === o} onClick={() => { setOnglet(o); setMessage(''); }}
            className={`px-4 py-2 text-sm border-b-2 -mb-px ${onglet === o ? 'border-blue-600 text-blue-700 dark:text-blue-300 font-medium' : 'border-transparent text-gray-600 dark:text-gray-300 hover:text-gray-900'}`}>
            {tl.tabs[o]}
          </button>
        ))}
      </div>
      {message && <div role="status" className="px-3 py-2 rounded-lg text-sm bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-200">{message}</div>}
      {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}
      {!catalogue ? <p className="text-sm text-gray-500">{tl.loading}</p> : onglet === 'imprimer'
        ? <OngletImpression catalogue={catalogue} produitsInitiaux={parametres.get('produits') ?? ''} />
        : <OngletFormats catalogue={catalogue} modifiable={peut('etiquettes', 'formats')} onModifie={m => { setMessage(m); setVersion(v => v + 1); }} />}
    </div>
  );
}
