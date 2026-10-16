import { useId, useState } from 'react';
import { Tags } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';
import GrillePrix from '@/components/prix/GrillePrix';
import ListesPrix from '@/components/prix/ListesPrix';
import ReglesPrix from '@/components/prix/ReglesPrix';
import SimulateurPrix from '@/components/prix/SimulateurPrix';
import { useSymboleDevise } from '@/components/prix/format';

type Onglet = 'grille' | 'listes' | 'regles' | 'simulateur';

/**
 * Prix et remises (plan de parité fripv2, phase 1.2a) : grille de prix par produit, listes de prix,
 * règles (prix client, paliers, promotions) et simulateur. Remplace l'écran de démonstration, qui
 * n'enregistrait rien.
 */
export default function GestionPrixPage() {
  const { t } = useLanguage();
  const tp = t.pricing;
  const id = useId();
  const { canEdit } = usePermissions();
  const modifiable = canEdit('gestion_prix');
  const devise = useSymboleDevise();
  const [onglet, setOnglet] = useState<Onglet>('grille');

  const onglets: { cle: Onglet; libelle: string }[] = [
    { cle: 'grille', libelle: tp.tabs.grid },
    { cle: 'listes', libelle: tp.tabs.lists },
    { cle: 'regles', libelle: tp.tabs.rules },
    { cle: 'simulateur', libelle: tp.tabs.simulator },
  ];

  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Tags className="text-purple-600" /> {tp.title}</h1>
        <p className="text-sm text-gray-500 dark:text-gray-400">{tp.subtitle}</p>
        {!modifiable && <p className="mt-1 text-xs text-amber-700 dark:text-amber-400">{tp.readOnly}</p>}
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md">
        <div role="tablist" aria-label={tp.title} className="flex flex-wrap gap-1 border-b dark:border-gray-700 px-4">
          {onglets.map(o => (
            <button key={o.cle} role="tab" id={`${id}-onglet-${o.cle}`} aria-selected={onglet === o.cle} aria-controls={`${id}-panneau`} onClick={() => setOnglet(o.cle)}
              className={`px-4 py-3 text-sm font-medium border-b-2 -mb-px ${onglet === o.cle ? 'border-purple-600 text-purple-700 dark:text-purple-300' : 'border-transparent text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white'}`}>
              {o.libelle}
            </button>
          ))}
        </div>
        <div role="tabpanel" id={`${id}-panneau`} aria-labelledby={`${id}-onglet-${onglet}`} className="p-6">
          {onglet === 'grille' && <GrillePrix modifiable={modifiable} />}
          {onglet === 'listes' && <ListesPrix modifiable={modifiable} />}
          {onglet === 'regles' && <ReglesPrix modifiable={modifiable} devise={devise} />}
          {onglet === 'simulateur' && <SimulateurPrix devise={devise} />}
        </div>
      </div>
    </div>
  );
}
