import { useEffect, useState } from 'react';
import { Paperclip, Search, Trash2 } from 'lucide-react';
import {
  getPiecesJointes, supprimerPieceJointe, ouvrirPieceJointe,
  type EntitePieceJointe, type PagePiecesJointes, type PieceJointe,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { IconePieceJointe } from '@/components/PiecesJointes';
import { formatTaille } from '@/lib/utils';

const ENTITES: EntitePieceJointe[] = ['client', 'fournisseur', 'produit', 'employe', 'devis', 'commande_client', 'facture', 'bon_livraison', 'commande_fournisseur', 'facture_fournisseur', 'encaissement', 'debourse'];

/** Toutes les pièces jointes de l'entreprise : recherche, filtre par type de fiche, ouverture, nettoyage. */
export default function PiecesJointesPage() {
  const { t, language } = useLanguage();
  const ta = t.attachments;
  const anglais = language === 'en';

  const [saisie, setSaisie] = useState('');
  const [recherche, setRecherche] = useState('');
  const [entite, setEntite] = useState<EntitePieceJointe | ''>('');
  const [page, setPage] = useState(1);
  const [resultat, setResultat] = useState<PagePiecesJointes | null>(null);
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);

  useEffect(() => {
    let actif = true;
    getPiecesJointes({ recherche: recherche || undefined, entite: entite || undefined, page })
      .then(r => { if (actif) { setResultat(r); setErreur(''); } })
      .catch(e => { if (actif) setErreur(e instanceof ApiError ? e.message : ta.error); });
    return () => { actif = false; };
  }, [recherche, entite, page, version, ta.error]);

  async function supprimer(piece: PieceJointe) {
    if (!confirm(ta.confirmDelete)) return;
    try {
      await supprimerPieceJointe(piece.id);
      setVersion(v => v + 1);
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : ta.error);
    }
  }

  const pieces = resultat?.data ?? [];
  const classeChamp = 'px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

  return (
    <div className="p-6">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2">
          <Paperclip className="text-purple-600" /> {ta.title}
        </h1>
        <p className="text-sm text-gray-500 dark:text-gray-400">{ta.globalSubtitle}</p>
      </div>

      <form className="flex flex-wrap gap-3 mb-4" onSubmit={e => { e.preventDefault(); setRecherche(saisie.trim()); setPage(1); }}>
        <div className="relative">
          <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
          <input value={saisie} onChange={e => setSaisie(e.target.value)} placeholder={ta.search} aria-label={ta.search} className={`${classeChamp} pl-9 w-80`} />
        </div>
        <select value={entite} onChange={e => { setEntite(e.target.value as EntitePieceJointe | ''); setPage(1); }} aria-label={ta.attachedTo} className={classeChamp}>
          <option value="">{ta.allEntities}</option>
          {ENTITES.map(x => <option key={x} value={x}>{ta.entities[x]}</option>)}
        </select>
      </form>

      {erreur && <p className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-4 py-3 font-medium">{ta.file}</th>
              <th className="px-4 py-3 font-medium">{ta.attachedTo}</th>
              <th className="px-4 py-3 font-medium">{ta.category}</th>
              <th className="px-4 py-3 font-medium">{ta.size}</th>
              <th className="px-4 py-3 font-medium">{ta.addedBy}</th>
              <th className="px-4 py-3 font-medium">{ta.date}</th>
              <th className="px-4 py-3" />
            </tr>
          </thead>
          <tbody>
            {resultat && pieces.length === 0 && (
              <tr><td colSpan={7} className="px-4 py-8 text-center text-gray-500">{ta.empty}</td></tr>
            )}
            {pieces.map(piece => (
              <tr key={piece.id} className="border-t dark:border-gray-700">
                <td className="px-4 py-3">
                  <div className="flex items-center gap-2">
                    <IconePieceJointe type={piece.type_mime} />
                    <div className="min-w-0">
                      <button type="button" onClick={() => ouvrirPieceJointe(piece).catch(() => setErreur(ta.openError))}
                        className="font-medium text-purple-700 dark:text-purple-300 hover:underline text-left break-all" title={ta.open}>
                        {piece.nom_original}
                      </button>
                      {piece.description && <p className="text-xs text-gray-500 truncate max-w-xs" title={piece.description}>{piece.description}</p>}
                    </div>
                  </div>
                </td>
                <td className="px-4 py-3 whitespace-nowrap dark:text-white">
                  <span className="text-gray-500">{ta.entities[piece.entite]}</span> {piece.libelle_entite ?? `#${piece.entite_id}`}
                </td>
                <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{piece.categorie ?? '—'}</td>
                <td className="px-4 py-3 whitespace-nowrap text-gray-600 dark:text-gray-300">{formatTaille(piece.taille, anglais)}</td>
                <td className="px-4 py-3 whitespace-nowrap text-gray-600 dark:text-gray-300">{piece.auteur ?? '—'}</td>
                <td className="px-4 py-3 whitespace-nowrap text-gray-600 dark:text-gray-300">
                  {piece.created_at ? new Date(piece.created_at).toLocaleDateString(anglais ? 'en-US' : 'fr-FR') : '—'}
                </td>
                <td className="px-4 py-3 text-right">
                  <button type="button" onClick={() => supprimer(piece)} title={ta.delete} aria-label={`${ta.delete} — ${piece.nom_original}`}
                    className="p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30 rounded">
                    <Trash2 size={16} />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {resultat && resultat.last_page > 1 && (
        <div className="flex items-center justify-end gap-3 mt-4 text-sm">
          <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} className="px-3 py-1.5 border rounded-lg dark:border-gray-600 dark:text-white disabled:opacity-40">{ta.previous}</button>
          <span className="text-gray-600 dark:text-gray-300">{ta.pageOf.replace('{page}', String(resultat.current_page)).replace('{total}', String(resultat.last_page))}</span>
          <button disabled={page >= resultat.last_page} onClick={() => setPage(p => p + 1)} className="px-3 py-1.5 border rounded-lg dark:border-gray-600 dark:text-white disabled:opacity-40">{ta.next}</button>
        </div>
      )}
    </div>
  );
}
