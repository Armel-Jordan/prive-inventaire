import { useState } from 'react';
import { UserRound } from 'lucide-react';
import { getClientDePassage } from '@/services/api';
import type { Client, CoordonneesPassage } from '@/services/api';
import type { SaisiePassage } from '@/lib/clientDePassage';
import { useLanguage } from '@/i18n/useLanguage';

/** Bouton « Client de passage » d'un formulaire de vente : choisit la fiche du client de passage (créée au besoin). */
export function BoutonClientDePassage({ onChoisir }: { onChoisir: (client: Client) => void }) {
  const { t } = useLanguage();
  const [chargement, setChargement] = useState(false);

  const choisir = async () => {
    setChargement(true);
    try {
      onChoisir(await getClientDePassage());
    } catch {
      alert(t.walkIn.error);
    } finally {
      setChargement(false);
    }
  };

  return (
    <button type="button" onClick={choisir} disabled={chargement} title={t.walkIn.buttonHelp}
      className="mt-1 inline-flex items-center gap-1 text-xs text-purple-600 hover:underline disabled:opacity-50 dark:text-purple-400">
      <UserRound size={14} /> {t.walkIn.button}
    </button>
  );
}

/** Coordonnées du client de passage notées sur le document, toutes facultatives. */
export default function ChampsClientDePassage({ valeur, onChange }: { valeur: SaisiePassage; onChange: (valeur: SaisiePassage) => void }) {
  const { t } = useLanguage();

  const champ = (cle: keyof CoordonneesPassage, libelle: string, type: string, longueur: number) => (
    <div>
      <label htmlFor={`passage-${cle}`} className="block text-xs font-medium text-gray-600 mb-1 dark:text-gray-300">{libelle}</label>
      <input id={`passage-${cle}`} type={type} maxLength={longueur} value={valeur[cle]} onChange={e => onChange({ ...valeur, [cle]: e.target.value })}
        className="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
    </div>
  );

  return (
    <fieldset className="border border-purple-200 bg-purple-50/40 rounded-lg p-3 dark:border-purple-800 dark:bg-purple-900/10">
      <legend className="px-1 text-sm font-medium text-purple-800 dark:text-purple-300">{t.walkIn.title}</legend>
      <p className="text-xs text-gray-500 mb-2 dark:text-gray-400">{t.walkIn.help}</p>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        {champ('passage_nom', t.walkIn.name, 'text', 150)}
        {champ('passage_telephone', t.walkIn.phone, 'tel', 30)}
        {champ('passage_courriel', t.walkIn.email, 'email', 150)}
        {champ('passage_adresse', t.walkIn.address, 'text', 255)}
      </div>
    </fieldset>
  );
}

/** Coordonnées du client de passage d'un document, dans sa fiche de détail. */
export function DetailClientDePassage({ document }: { document: CoordonneesPassage & { client?: Pick<Client, 'de_passage'> | null } }) {
  const { t } = useLanguage();
  if (!document.client?.de_passage) return null;
  const lignes = [document.passage_adresse, document.passage_telephone, document.passage_courriel].filter(Boolean);
  if (lignes.length === 0) return null;

  return (
    <p className="text-xs text-gray-500 dark:text-gray-400" title={t.walkIn.title}>
      {lignes.join(' · ')}
    </p>
  );
}

/** Pastille « De passage » à côté du nom d'un client. */
export function PastilleClientDePassage({ client }: { client?: Pick<Client, 'de_passage'> | null }) {
  const { t } = useLanguage();
  if (!client?.de_passage) return null;

  return (
    <span title={t.walkIn.badgeHelp} className="ml-1 inline-block px-1.5 py-0.5 rounded text-[10px] font-medium bg-purple-100 text-purple-800 align-middle dark:bg-purple-900/40 dark:text-purple-300">
      {t.walkIn.badge}
    </span>
  );
}
