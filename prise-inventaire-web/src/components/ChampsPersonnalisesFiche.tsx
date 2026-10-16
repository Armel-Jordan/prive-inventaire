import { useEffect, useId, useState } from 'react';
import {
  getValeursChampsPersonnalises, enregistrerValeursChampsPersonnalises,
  type ChampPersonnalise, type ChampPersonnaliseFiche, type EntiteChampPersonnalise, type ValeurChampPersonnalise,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

type ChampAffiche = Pick<ChampPersonnalise, 'libelle' | 'type' | 'options' | 'decimales' | 'format' | 'obligatoire' | 'aide'>;

/** Saisie d'un champ personnalisé selon son type, avec son libellé, son aide et son erreur. */
export function SaisieChampPersonnalise({ champ, valeur, onChange, erreur }: {
  champ: ChampAffiche;
  valeur: ValeurChampPersonnalise;
  onChange: (valeur: ValeurChampPersonnalise) => void;
  erreur?: string;
}) {
  const { t } = useLanguage();
  const id = useId();
  const libelle = `${champ.libelle}${champ.obligatoire && champ.type !== 'case' ? ' *' : ''}`;
  const texte = typeof valeur === 'string' || typeof valeur === 'number' ? String(valeur) : '';
  const pied = (
    <>
      {champ.aide && <span className="block text-xs text-gray-500 mt-1">{champ.aide}</span>}
      {erreur && <span className="block text-xs text-red-600 mt-1">{erreur}</span>}
    </>
  );

  if (champ.type === 'case') {
    return (
      <div>
        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
          <input type="checkbox" checked={valeur === true} onChange={e => onChange(e.target.checked)} className="rounded" />
          {libelle}
        </label>
        {pied}
      </div>
    );
  }

  if (champ.type === 'liste_multiple') {
    const choisis = Array.isArray(valeur) ? valeur : [];
    return (
      <fieldset>
        <legend className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{libelle}</legend>
        <div className="flex flex-wrap gap-x-4 gap-y-1">
          {(champ.options ?? []).map(option => (
            <label key={option} className="flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-300">
              <input type="checkbox" className="rounded" checked={choisis.includes(option)}
                onChange={e => onChange(e.target.checked ? [...choisis, option] : choisis.filter(x => x !== option))} />
              {option}
            </label>
          ))}
        </div>
        {pied}
      </fieldset>
    );
  }

  let saisie;
  switch (champ.type) {
    case 'texte_long':
      saisie = <textarea id={id} rows={3} maxLength={5000} value={texte} onChange={e => onChange(e.target.value)} className={classeChamp} />;
      break;
    case 'nombre':
      saisie = (
        <div className="flex items-center gap-2">
          <input id={id} type="number" value={texte} onChange={e => onChange(e.target.value)} className={classeChamp}
            step={champ.decimales !== null && champ.decimales !== undefined ? 1 / 10 ** champ.decimales : 'any'} />
          {champ.format === 'pourcentage' && <span className="text-sm text-gray-500">%</span>}
        </div>
      );
      break;
    case 'date':
      saisie = <input id={id} type="date" value={texte} onChange={e => onChange(e.target.value)} className={classeChamp} />;
      break;
    case 'liste':
      saisie = (
        <select id={id} value={texte} onChange={e => onChange(e.target.value)} className={classeChamp}>
          <option value="">{t.customFields.choose}</option>
          {(champ.options ?? []).map(option => <option key={option} value={option}>{option}</option>)}
        </select>
      );
      break;
    default: {
      const type = { courriel: 'email', url: 'url', telephone: 'tel' }[champ.type as string] ?? 'text';
      saisie = <input id={id} type={type} maxLength={champ.type === 'url' ? 500 : 255} value={texte} onChange={e => onChange(e.target.value)} className={classeChamp} />;
    }
  }

  return (
    <div>
      <label htmlFor={id} className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{libelle}</label>
      {saisie}
      {pied}
    </div>
  );
}

/** Valeur prête pour l'API : un nombre vide devient null. */
function pourEnvoi(champ: ChampPersonnalise, valeur: ValeurChampPersonnalise): ValeurChampPersonnalise {
  if (champ.type === 'nombre') return valeur === '' || valeur === null ? null : Number(valeur);
  return valeur;
}

/** Champs personnalisés d'une fiche ou d'un document : saisie et enregistrement. */
export default function ChampsPersonnalisesFiche({ entite, entiteId }: { entite: EntiteChampPersonnalise; entiteId: number }) {
  const { t } = useLanguage();
  const tc = t.customFields;
  const [champs, setChamps] = useState<ChampPersonnaliseFiche[] | null>(null);
  const [valeurs, setValeurs] = useState<Record<number, ValeurChampPersonnalise>>({});
  const [erreurs, setErreurs] = useState<Record<number, string>>({});
  const [message, setMessage] = useState<{ erreur: boolean; texte: string } | null>(null);
  const [enregistrement, setEnregistrement] = useState(false);

  const afficher = (liste: ChampPersonnaliseFiche[]) => {
    setChamps(liste);
    setValeurs(Object.fromEntries(liste.map(c => [c.id, c.valeur])));
  };

  useEffect(() => {
    let actif = true;
    getValeursChampsPersonnalises(entite, entiteId)
      .then(liste => {
        if (!actif) return;
        setChamps(liste);
        setValeurs(Object.fromEntries(liste.map(c => [c.id, c.valeur])));
      })
      .catch(e => { if (actif) setMessage({ erreur: true, texte: e instanceof ApiError ? e.message : tc.error }); });
    return () => { actif = false; };
  }, [entite, entiteId, tc.error]);

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    if (!champs) return;
    setEnregistrement(true);
    setErreurs({});
    setMessage(null);
    try {
      afficher(await enregistrerValeursChampsPersonnalises(entite, entiteId,
        Object.fromEntries(champs.map(c => [c.id, pourEnvoi(c, valeurs[c.id] ?? null)]))));
      setMessage({ erreur: false, texte: tc.saved });
    } catch (erreur) {
      // Erreurs de validation rangées par champ (« valeurs.12 », « valeurs.12.1 »).
      const detail = erreur instanceof ApiError ? (erreur.data as { errors?: Record<string, string[]> } | null)?.errors : undefined;
      if (detail) {
        const parChamp: Record<number, string> = {};
        Object.entries(detail).forEach(([cle, textes]) => {
          const id = Number(cle.split('.')[1]);
          if (id && !parChamp[id]) parChamp[id] = textes[0];
        });
        setErreurs(parChamp);
      }
      setMessage({ erreur: true, texte: erreur instanceof ApiError ? erreur.message : tc.error });
    } finally {
      setEnregistrement(false);
    }
  }

  if (!champs) {
    return message ? <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{message.texte}</p> : <p className="text-sm text-gray-500">{tc.loading}</p>;
  }
  if (champs.length === 0) return <p className="text-sm text-gray-500">{tc.none}</p>;

  return (
    <form onSubmit={enregistrer} className="space-y-4">
      {champs.map(champ => (
        <SaisieChampPersonnalise key={champ.id} champ={champ} valeur={valeurs[champ.id] ?? null} erreur={erreurs[champ.id]}
          onChange={valeur => setValeurs(v => ({ ...v, [champ.id]: valeur }))} />
      ))}
      {message && (
        <p className={`px-3 py-2 rounded-lg text-sm ${message.erreur ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}>{message.texte}</p>
      )}
      <div className="flex justify-end">
        <button type="submit" disabled={enregistrement} className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">
          {tc.save}
        </button>
      </div>
    </form>
  );
}
