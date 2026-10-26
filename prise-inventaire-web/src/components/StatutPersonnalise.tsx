import { useState } from 'react';
import { poserStatutPersonnalise, type DocumentStatut, type StatutPersonnaliseDocument } from '@/services/api';
import { useStatutsPersonnalises } from '@/hooks/useStatutsPersonnalises';
import { useLanguage } from '@/i18n/useLanguage';
import { signaler } from '@/lib/erreurs';

/** Pastille en couleur d'un statut personnalisé (fond et texte du référentiel). */
export function PastilleStatutPersonnalise({ statut }: { statut: StatutPersonnaliseDocument | null | undefined }) {
  if (!statut) return null;
  return (
    <span className="inline-block px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap"
      style={{ backgroundColor: statut.couleur ?? '#e5e7eb', color: statut.couleur_texte ?? '#111827' }}>
      {statut.libelle}
    </span>
  );
}

/** Filtre des listes par statut personnalisé ; masqué tant que ce type de document n'en a aucun. */
export function FiltreStatutPersonnalise({ document, value, onChange, className }: {
  document: DocumentStatut;
  value: number | null;
  onChange: (statutId: number | null) => void;
  className?: string;
}) {
  const { t } = useLanguage();
  const statuts = useStatutsPersonnalises(document);
  if (statuts.length === 0) return null;
  return (
    <select value={value ?? ''} onChange={e => onChange(e.target.value ? Number(e.target.value) : null)} aria-label={t.customStatuses.label}
      className={className ?? 'px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white'}>
      <option value="">{t.customStatuses.all}</option>
      {statuts.map(s => <option key={s.id} value={s.id}>{s.libelle}{s.actif ? '' : ` (${t.customStatuses.inactive})`}</option>)}
    </select>
  );
}

/**
 * Choix du statut personnalisé d'un document, enregistré tout de suite (même sur un document clos) ; en lecture
 * seule sans le droit de modifier le document. Masqué tant que ce type de document n'a aucun statut.
 */
export function ChoixStatutPersonnalise({ document, documentId, statut, modifiable, onChange }: {
  document: DocumentStatut;
  documentId: number;
  statut: StatutPersonnaliseDocument | null | undefined;
  modifiable: boolean;
  onChange: (statut: StatutPersonnaliseDocument | null) => void;
}) {
  const { t } = useLanguage();
  const statuts = useStatutsPersonnalises(document);
  const [enCours, setEnCours] = useState(false);
  if (statuts.length === 0 && !statut) return null;
  const proposes = statuts.filter(s => s.actif || s.id === statut?.id);

  async function choisir(valeur: string) {
    setEnCours(true);
    try {
      const reponse = await poserStatutPersonnalise(document, documentId, valeur ? Number(valeur) : null);
      onChange(reponse.statut_personnalise);
    } catch (erreur) {
      signaler(erreur, t.customStatuses.error);
    } finally {
      setEnCours(false);
    }
  }

  return (
    <div>
      <span className="text-sm text-gray-500">{t.customStatuses.label} :</span>
      <div className="mt-1 flex items-center gap-2">
        {modifiable ? (
          <select value={statut?.id ?? ''} disabled={enCours} onChange={e => choisir(e.target.value)} aria-label={t.customStatuses.label}
            className="px-2 py-1 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white">
            <option value="">{t.customStatuses.none}</option>
            {proposes.map(s => <option key={s.id} value={s.id}>{s.libelle}{s.actif ? '' : ` (${t.customStatuses.inactive})`}</option>)}
          </select>
        ) : !statut && <span className="text-sm text-gray-400">{t.customStatuses.none}</span>}
        <PastilleStatutPersonnalise statut={statut} />
      </div>
    </div>
  );
}
