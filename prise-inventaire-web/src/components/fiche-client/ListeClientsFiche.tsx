import { useEffect, useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { Star, Tags } from 'lucide-react';
import { basculerFavoriClient, getCategoriesClients, type CategorieClient, type Client } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';
import CategoriesClientsModal from './CategoriesClientsModal';

/** Étoile favori + code menant à la fiche + catégorie, pour la cellule « Code » de la liste. */
export function CelluleCodeClient({ client, onFavori }: { client: Client; onFavori: () => void }) {
  const { t } = useLanguage();
  const tf = t.clientFile;
  const [favori, setFavori] = useState(Boolean(client.favori));

  async function basculer() {
    const suivant = !favori;
    setFavori(suivant);
    try {
      await basculerFavoriClient(client.id, suivant);
      onFavori();
    } catch {
      setFavori(!suivant);
    }
  }

  return (
    <div className="flex items-start gap-1.5">
      <button onClick={basculer} aria-pressed={favori} aria-label={`${favori ? tf.unfavorite : tf.favorite} — ${client.raison_sociale}`} title={favori ? tf.unfavorite : tf.favorite}
        className={`mt-0.5 ${favori ? 'text-amber-500' : 'text-gray-300 hover:text-amber-400 dark:text-gray-600'}`}>
        <Star size={15} fill={favori ? 'currentColor' : 'none'} />
      </button>
      <div>
        <Link to={`/clients/${client.id}`} title={tf.open} className="font-mono text-purple-700 dark:text-purple-300 hover:underline">{client.code}</Link>
        {client.categorie && <span className="block mt-0.5 w-fit px-1.5 py-0.5 rounded text-[11px] font-sans bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">{client.categorie.nom}</span>}
      </div>
    </div>
  );
}

export interface FiltresListeClients {
  categorie_id?: number;
  favoris?: boolean;
}

/** Filtres de la liste (catégorie, favoris) et accès à la gestion des catégories pour les administrateurs. */
export function FiltresClients({ valeur, onChange }: { valeur: FiltresListeClients; onChange: (filtres: FiltresListeClients) => void }) {
  const { t } = useLanguage();
  const tf = t.clientFile;
  const id = useId();
  const { peut } = usePermissions();
  const [categories, setCategories] = useState<CategorieClient[]>([]);
  const [gestion, setGestion] = useState(false);

  useEffect(() => {
    getCategoriesClients().then(setCategories).catch(() => setCategories([]));
  }, []);

  return (
    <div className="flex flex-wrap items-center gap-3 mt-3">
      <label htmlFor={`${id}-categorie`} className="sr-only">{tf.category}</label>
      <select id={`${id}-categorie`} value={valeur.categorie_id ?? ''} onChange={e => onChange({ ...valeur, categorie_id: e.target.value ? Number(e.target.value) : undefined })}
        className="px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white">
        <option value="">{tf.allCategories}</option>
        {categories.map(c => <option key={c.id} value={c.id}>{c.nom}</option>)}
      </select>
      <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
        <input type="checkbox" checked={Boolean(valeur.favoris)} onChange={e => onChange({ ...valeur, favoris: e.target.checked || undefined })} />
        <Star size={14} className="text-amber-500" fill="currentColor" /> {tf.favorites}
      </label>
      {peut('clients', 'categories') && (
        <button onClick={() => setGestion(true)} className="ml-auto flex items-center gap-1 px-3 py-2 text-sm rounded-lg border dark:border-gray-600 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-700">
          <Tags size={14} /> {tf.manageCategories}
        </button>
      )}
      {gestion && <CategoriesClientsModal onFermer={() => setGestion(false)} onChange={setCategories} />}
    </div>
  );
}
