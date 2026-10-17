import { useState } from 'react';
import { X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import {
  creerFormatEtiquette, modifierFormatEtiquette, parPage,
  type CatalogueEtiquettes, type ContenuEtiquette, type FormatEtiquette, type PageEtiquettes, type SaisieFormatEtiquette, type SupportEtiquette,
} from '@/services/etiquettes';

const classeChamp = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/** Champs numériques de la saisie, gardés en texte le temps de la frappe (virgule acceptée). */
const NOMBRES = [
  'largeur_mm', 'hauteur_mm', 'colonnes', 'rangees', 'marge_haut_mm', 'marge_gauche_mm', 'espace_colonnes_mm', 'espace_rangees_mm', 'taille_police',
] as const;
type ChampNombre = typeof NOMBRES[number];

interface Saisie {
  nom: string;
  support: SupportEtiquette;
  page: PageEtiquettes;
  contenu: ContenuEtiquette[];
  par_defaut: boolean;
  actif: boolean;
  nombres: Record<ChampNombre, string>;
}

const NOUVEAU: Saisie = {
  nom: '', support: 'rouleau', page: 'A4', contenu: ['description', 'code_barres', 'chiffres', 'numero', 'prix'], par_defaut: false, actif: true,
  nombres: {
    largeur_mm: '38', hauteur_mm: '22', colonnes: '3', rangees: '7', marge_haut_mm: '10', marge_gauche_mm: '5',
    espace_colonnes_mm: '2', espace_rangees_mm: '0', taille_police: '7',
  },
};

function depuisFormat(format: FormatEtiquette): Saisie {
  const nombres = Object.fromEntries(NOMBRES.map(champ => [champ, String(format[champ]).replace('.', ',')])) as Record<ChampNombre, string>;
  if (format.support === 'rouleau') {
    // Valeurs proposées si le rouleau devient une planche.
    Object.assign(nombres, { colonnes: '3', rangees: '7', marge_haut_mm: '10', marge_gauche_mm: '5', espace_colonnes_mm: '2' });
  }
  return {
    nom: format.nom, support: format.support, page: format.page ?? 'A4', contenu: format.contenu, par_defaut: format.par_defaut,
    actif: format.actif, nombres,
  };
}

const nombre = (texte: string) => Number(texte.replace(',', '.')) || 0;

/**
 * Création ou modification d'un format d'étiquette : rouleau (une étiquette par page) ou planche (page, grille, marges
 * et espaces), taille de l'étiquette, contenu, taille du texte ; un aperçu de la page montre où tombent les étiquettes.
 * L'API vérifie que la grille tient dans la page et que le contenu tient dans l'étiquette.
 */
export default function FenetreFormatEtiquette({ format, catalogue, onClose, onEnregistre }: {
  /** Absent : un nouveau format. */
  format: FormatEtiquette | null;
  catalogue: CatalogueEtiquettes;
  onClose: () => void;
  onEnregistre: (format: FormatEtiquette) => void;
}) {
  const { t } = useLanguage();
  const tl = t.labels;
  const [saisie, setSaisie] = useState<Saisie>(() => (format ? depuisFormat(format) : NOUVEAU));
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);
  const planche = saisie.support === 'planche';
  const n = (champ: ChampNombre) => nombre(saisie.nombres[champ]);

  function changerNombre(champ: ChampNombre, valeur: string) {
    setSaisie(s => ({ ...s, nombres: { ...s.nombres, [champ]: valeur } }));
  }

  function basculer(element: ContenuEtiquette) {
    setSaisie(s => {
      let contenu = s.contenu.includes(element) ? s.contenu.filter(c => c !== element) : [...s.contenu, element];
      // Les taxes et les chiffres suivent le prix et le code-barres.
      if (!contenu.includes('prix')) contenu = contenu.filter(c => c !== 'prix_taxes');
      if (!contenu.includes('code_barres')) contenu = contenu.filter(c => c !== 'chiffres');
      return { ...s, contenu: catalogue.contenus.filter(c => contenu.includes(c)) };
    });
  }

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    const donnees: SaisieFormatEtiquette = {
      nom: saisie.nom.trim(), support: saisie.support, page: planche ? saisie.page : null, contenu: saisie.contenu,
      par_defaut: saisie.par_defaut, actif: saisie.actif,
      largeur_mm: n('largeur_mm'), hauteur_mm: n('hauteur_mm'), taille_police: n('taille_police'),
      colonnes: planche ? n('colonnes') : 1, rangees: planche ? n('rangees') : 1,
      marge_haut_mm: planche ? n('marge_haut_mm') : 0, marge_gauche_mm: planche ? n('marge_gauche_mm') : 0,
      espace_colonnes_mm: planche ? n('espace_colonnes_mm') : 0, espace_rangees_mm: planche ? n('espace_rangees_mm') : 0,
    };
    setOccupe(true);
    setErreur('');
    try {
      onEnregistre(format ? await modifierFormatEtiquette(format.id, donnees) : await creerFormatEtiquette(donnees));
    } catch (err) {
      setErreur(messageErreur(err, tl.error));
    } finally {
      setOccupe(false);
    }
  }

  // Aperçu : la page d'une planche et sa grille, ou l'étiquette d'un rouleau.
  const [largeurPage, hauteurPage] = planche ? catalogue.pages[saisie.page] : [n('largeur_mm'), n('hauteur_mm')];
  const cases = planche
    ? Array.from({ length: Math.min(parPage({ support: 'planche', colonnes: n('colonnes'), rangees: n('rangees') }), 400) }, (_, i) => ({
      x: n('marge_gauche_mm') + (i % n('colonnes')) * (n('largeur_mm') + n('espace_colonnes_mm')),
      y: n('marge_haut_mm') + Math.floor(i / n('colonnes')) * (n('hauteur_mm') + n('espace_rangees_mm')),
    }))
    : [{ x: 0, y: 0 }];
  const deborde = planche && cases.some(c => c.x + n('largeur_mm') > largeurPage + 0.01 || c.y + n('hauteur_mm') > hauteurPage + 0.01);
  const champ = (cle: ChampNombre, libelle: string, entier = false) => (
    <label className="text-xs text-gray-500 dark:text-gray-400">{libelle}
      <input required inputMode={entier ? 'numeric' : 'decimal'} value={saisie.nombres[cle]} onChange={e => changerNombre(cle, e.target.value)}
        className={`${classeChamp} block w-full`} />
    </label>
  );

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <form onSubmit={enregistrer} role="dialog" aria-modal="true" aria-labelledby="format-etiquette-titre"
        className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-4xl max-h-[94vh] overflow-y-auto space-y-4">
        <div className="flex items-start justify-between gap-3">
          <h2 id="format-etiquette-titre" className="text-lg font-bold text-gray-900 dark:text-white">
            {format ? tl.editFormat.replace('{nom}', format.nom) : tl.newFormat}
          </h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tl.cancel}><X size={18} /></button>
        </div>
        {erreur && <div role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}

        <div className="grid gap-4 md:grid-cols-[1fr_260px]">
          <div className="space-y-3">
            <label className="block text-xs text-gray-500 dark:text-gray-400">{tl.name}
              <input required maxLength={100} value={saisie.nom} onChange={e => setSaisie({ ...saisie, nom: e.target.value })} className={`${classeChamp} block w-full`} />
            </label>
            <fieldset>
              <legend className="text-xs text-gray-500 dark:text-gray-400 mb-1">{tl.support}</legend>
              <div className="flex flex-wrap gap-4">
                {(['rouleau', 'planche'] as SupportEtiquette[]).map(support => (
                  <label key={support} className="flex items-start gap-2 text-sm dark:text-gray-200 max-w-xs">
                    <input type="radio" name="support" checked={saisie.support === support} onChange={() => setSaisie({ ...saisie, support })} className="mt-1" />
                    <span><span className="font-medium">{tl.supports[support]}</span><span className="block text-xs text-gray-500">{tl.supportHelp[support]}</span></span>
                  </label>
                ))}
              </div>
            </fieldset>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
              {champ('largeur_mm', tl.width)}
              {champ('hauteur_mm', tl.height)}
              {champ('taille_police', tl.fontSize, true)}
              {planche && (
                <label className="text-xs text-gray-500 dark:text-gray-400">{tl.page}
                  <select value={saisie.page} onChange={e => setSaisie({ ...saisie, page: e.target.value as PageEtiquettes })} className={`${classeChamp} block w-full`}>
                    {(Object.keys(catalogue.pages) as PageEtiquettes[]).map(page => <option key={page} value={page}>{page === 'LETTER' ? 'Letter' : page}</option>)}
                  </select>
                </label>
              )}
            </div>
            {planche && (
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                {champ('colonnes', tl.columns, true)}
                {champ('rangees', tl.rows, true)}
                {champ('marge_haut_mm', tl.marginTop)}
                {champ('marge_gauche_mm', tl.marginLeft)}
                {champ('espace_colonnes_mm', tl.gapColumns)}
                {champ('espace_rangees_mm', tl.gapRows)}
              </div>
            )}
            <fieldset>
              <legend className="text-xs text-gray-500 dark:text-gray-400 mb-1">{tl.content}</legend>
              <div className="grid sm:grid-cols-2 gap-1">
                {catalogue.contenus.map(element => {
                  const inutile = (element === 'prix_taxes' && !saisie.contenu.includes('prix')) || (element === 'chiffres' && !saisie.contenu.includes('code_barres'));
                  return (
                    <label key={element} className={`flex items-center gap-2 text-sm dark:text-gray-200 ${inutile ? 'opacity-50' : ''}`}>
                      <input type="checkbox" checked={saisie.contenu.includes(element)} disabled={inutile} onChange={() => basculer(element)} />
                      {tl.contents[element]}
                    </label>
                  );
                })}
              </div>
            </fieldset>
            <div className="flex flex-wrap gap-4">
              <label className="flex items-center gap-2 text-sm dark:text-gray-200">
                <input type="checkbox" checked={saisie.par_defaut} disabled={!saisie.actif} onChange={e => setSaisie({ ...saisie, par_defaut: e.target.checked })} /> {tl.isDefault}
              </label>
              <label className="flex items-center gap-2 text-sm dark:text-gray-200">
                <input type="checkbox" checked={saisie.actif} onChange={e => setSaisie({ ...saisie, actif: e.target.checked, par_defaut: e.target.checked && saisie.par_defaut })} /> {tl.active}
              </label>
            </div>
          </div>

          <figure className="space-y-1">
            <figcaption className="text-xs text-gray-500 dark:text-gray-400">{tl.preview}</figcaption>
            {largeurPage > 0 && hauteurPage > 0 && (
              <svg viewBox={`-2 -2 ${largeurPage + 4} ${hauteurPage + 4}`} className="w-full max-h-80 bg-gray-100 dark:bg-gray-900 rounded" role="img" aria-label={tl.preview}>
                <rect x={0} y={0} width={largeurPage} height={hauteurPage} fill="white" stroke="#9ca3af" strokeWidth={0.5} />
                {cases.map((c, i) => (
                  <rect key={i} x={c.x} y={c.y} width={n('largeur_mm')} height={n('hauteur_mm')} rx={1}
                    fill={deborde ? '#fee2e2' : '#dbeafe'} stroke={deborde ? '#dc2626' : '#2563eb'} strokeWidth={0.4} />
                ))}
              </svg>
            )}
            {deborde && <p role="alert" className="text-xs text-red-600">{tl.gridTooLarge}</p>}
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {planche
                ? tl.sheet.replace('{page}', saisie.page === 'LETTER' ? 'Letter' : saisie.page).replace('{n}', String(n('colonnes') * n('rangees')))
                  .replace('{c}', String(n('colonnes'))).replace('{r}', String(n('rangees')))
                  .replace('{l}', saisie.nombres.largeur_mm).replace('{h}', saisie.nombres.hauteur_mm)
                : tl.roll.replace('{l}', saisie.nombres.largeur_mm).replace('{h}', saisie.nombres.hauteur_mm)}
            </p>
          </figure>
        </div>

        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm dark:border-gray-600 dark:text-gray-200">{tl.cancel}</button>
          <button type="submit" disabled={occupe || !saisie.nom.trim() || saisie.contenu.length === 0}
            className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 disabled:opacity-40">{tl.save}</button>
        </div>
      </form>
    </div>
  );
}
