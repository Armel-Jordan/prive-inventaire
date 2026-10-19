import type { ParametreRapport } from '@/services/bibliothequeRapports';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/** Listes de choix des paramètres : clients, fournisseurs, catégories de produits, représentants. */
export interface ListesParametres {
  clients: { id: number; nom: string }[];
  fournisseurs: { id: number; nom: string }[];
  categories: string[];
  representants: { id: number; nom: string }[];
}

/**
 * Champ d'un paramètre de rapport : date, nombre, choix, ou liste (client, fournisseur, catégorie de produits) dont la
 * première option, vide, ne filtre pas.
 */
export default function ChampParametre({ id, parametre, valeur, onChange, listes, sansFiltre }: {
  id: string;
  parametre: ParametreRapport;
  valeur: string;
  onChange: (valeur: string) => void;
  listes: ListesParametres;
  /** Libellé de l'option vide d'une liste. */
  sansFiltre: string;
}) {
  switch (parametre.type) {
    case 'date':
      return <input id={id} type="date" value={valeur} onChange={e => onChange(e.target.value)} className={classeChamp} />;
    case 'entier':
      return <input id={id} type="number" value={valeur} min={parametre.min} max={parametre.max} onChange={e => onChange(e.target.value)} className={classeChamp} />;
    case 'choix':
      return (
        <select id={id} value={valeur} onChange={e => onChange(e.target.value)} className={classeChamp}>
          {(parametre.options ?? []).map(o => <option key={o.valeur} value={o.valeur}>{o.libelle}</option>)}
        </select>
      );
    default: {
      const options = parametre.type === 'client' ? listes.clients.map(c => ({ valeur: String(c.id), libelle: c.nom }))
        : parametre.type === 'fournisseur' ? listes.fournisseurs.map(f => ({ valeur: String(f.id), libelle: f.nom }))
          : parametre.type === 'representant' ? listes.representants.map(r => ({ valeur: String(r.id), libelle: r.nom }))
            : listes.categories.map(c => ({ valeur: c, libelle: c }));
      return (
        <select id={id} value={valeur} onChange={e => onChange(e.target.value)} className={classeChamp}>
          <option value="">{sansFiltre}</option>
          {options.map(o => <option key={o.valeur} value={o.valeur}>{o.libelle}</option>)}
        </select>
      );
    }
  }
}
