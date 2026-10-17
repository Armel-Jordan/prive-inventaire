import { useState } from 'react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import FenetreFormatEtiquette from '@/components/etiquettes/FenetreFormatEtiquette';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { parPage, supprimerFormatEtiquette, type CatalogueEtiquettes, type FormatEtiquette } from '@/services/etiquettes';

/** Mesure en millimètres, à la française si besoin (63,5). */
function mm(valeur: number, langue: string): string {
  return langue === 'fr' ? String(valeur).replace('.', ',') : String(valeur);
}

/**
 * Formats d'étiquettes : la liste (support et taille, contenu, taille du texte, par défaut, actif), la création, la
 * modification et le retrait (droit « gérer les formats »). Il reste toujours un format actif.
 */
export default function OngletFormats({ catalogue, modifiable, onModifie }: {
  catalogue: CatalogueEtiquettes;
  modifiable: boolean;
  onModifie: (message: string) => void;
}) {
  const { t, language } = useLanguage();
  const tl = t.labels;
  const [fenetre, setFenetre] = useState<FormatEtiquette | 'nouveau' | null>(null);
  const [erreur, setErreur] = useState('');

  async function retirer(format: FormatEtiquette) {
    if (!confirm(tl.confirmDelete.replace('{nom}', format.nom))) return;
    setErreur('');
    try {
      await supprimerFormatEtiquette(format.id);
      onModifie(tl.deleted);
    } catch (e) {
      setErreur(messageErreur(e, tl.error));
    }
  }

  const taille = (f: FormatEtiquette) => (f.support === 'planche'
    ? tl.sheet.replace('{page}', f.page === 'LETTER' ? 'Letter' : (f.page ?? 'A4')).replace('{n}', String(parPage(f)))
      .replace('{c}', String(f.colonnes)).replace('{r}', String(f.rangees))
      .replace('{l}', mm(f.largeur_mm, language)).replace('{h}', mm(f.hauteur_mm, language))
    : tl.roll.replace('{l}', mm(f.largeur_mm, language)).replace('{h}', mm(f.hauteur_mm, language)));

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        {modifiable ? <span /> : <p className="text-sm text-gray-500">{tl.readOnly}</p>}
        {modifiable && (
          <button type="button" onClick={() => setFenetre('nouveau')} className="flex items-center gap-1 px-3 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">
            <Plus size={16} /> {tl.newFormat}
          </button>
        )}
      </div>
      {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow overflow-x-auto">
        <table className="w-full text-sm" aria-label={tl.tabs.formats}>
          <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
            <tr>
              <th className="px-4 py-2 text-left">{tl.name}</th>
              <th className="px-4 py-2 text-left">{tl.support}</th>
              <th className="px-4 py-2 text-left">{tl.content}</th>
              <th className="px-4 py-2 text-right">{tl.fontSize}</th>
              {modifiable && <th className="px-4 py-2" />}
            </tr>
          </thead>
          <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
            {catalogue.formats.map(f => (
              <tr key={f.id} className={f.actif ? '' : 'opacity-60'}>
                <td className="px-4 py-2">
                  <span className="font-medium">{f.nom}</span>
                  {f.par_defaut && <span className="ml-2 px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200">{tl.default}</span>}
                  {!f.actif && <span className="ml-2 px-2 py-0.5 rounded-full text-xs bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">{tl.inactive}</span>}
                </td>
                <td className="px-4 py-2 whitespace-nowrap">{taille(f)}</td>
                <td className="px-4 py-2 text-gray-600 dark:text-gray-300">{f.contenu.map(c => tl.contents[c]).join(', ')}</td>
                <td className="px-4 py-2 text-right">{f.taille_police}</td>
                {modifiable && (
                  <td className="px-4 py-2 text-right whitespace-nowrap">
                    <button type="button" onClick={() => setFenetre(f)} className="p-1 rounded text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30"
                      aria-label={`${tl.edit} ${f.nom}`} title={tl.edit}><Pencil size={16} /></button>
                    <button type="button" onClick={() => retirer(f)} className="p-1 rounded text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30"
                      aria-label={`${tl.delete} ${f.nom}`} title={tl.delete}><Trash2 size={16} /></button>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {fenetre && (
        <FenetreFormatEtiquette format={fenetre === 'nouveau' ? null : fenetre} catalogue={catalogue} onClose={() => setFenetre(null)}
          onEnregistre={() => { setFenetre(null); onModifie(tl.saved); }} />
      )}
    </div>
  );
}
