import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { Bookmark, Star, Trash2 } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import {
  enregistrerFiltreMemorise, getFiltresMemorises, modifierFiltreMemorise, supprimerFiltreMemorise,
  type FiltreMemorise, type ValeursFiltres,
} from '@/services/filtresMemorises';

/**
 * Filtres mémorisés d'un écran (plan de parité fripv2, phase 5.3) : appliquer un filtre enregistré, enregistrer les
 * filtres actuels sous un nom, choisir celui appliqué à l'ouverture de l'écran (étoile), supprimer. Chaque compte a les
 * siens.
 */
export default function FiltresMemorises({ ecran, valeurs, onAppliquer }: {
  /** Écran des filtres : « scans », « clients », « rapport:ventes-par-periode »… */
  ecran: string;
  /** Filtres actuels de l'écran. */
  valeurs: ValeursFiltres;
  /** Applique des filtres enregistrés (une clé absente ou vide remet le filtre à vide). */
  onAppliquer: (valeurs: ValeursFiltres) => void;
}) {
  const { t } = useLanguage();
  const tf = t.savedFilters;
  const [filtres, setFiltres] = useState<FiltreMemorise[]>([]);
  const [version, setVersion] = useState(0);
  const [ouvert, setOuvert] = useState(false);
  const [nom, setNom] = useState('');
  const [parDefaut, setParDefaut] = useState(false);
  const [erreur, setErreur] = useState('');
  const [annonce, setAnnonce] = useState('');
  // Le panneau s'ouvre vers la gauche quand le bouton est trop près du bord droit de la fenêtre.
  const [versLaGauche, setVersLaGauche] = useState(false);
  const zone = useRef<HTMLDivElement>(null);
  // Écran dont les filtres ont déjà été chargés : le filtre par défaut ne s'applique qu'à son ouverture.
  const ouvertureFaite = useRef<string | null>(null);
  const id = `filtres-${ecran.replace(/[^a-z0-9_-]/gi, '-')}`;

  const appliquerDefaut = useEffectEvent((filtre: FiltreMemorise) => {
    onAppliquer(filtre.valeurs);
    setAnnonce(tf.appliedDefault.replace('{nom}', filtre.nom));
  });

  useEffect(() => {
    let actif = true;
    getFiltresMemorises(ecran).then(liste => {
      if (!actif) return;
      setFiltres(liste);
      const defaut = liste.find(f => f.par_defaut);
      if (defaut && ouvertureFaite.current !== ecran) appliquerDefaut(defaut);
      ouvertureFaite.current = ecran;
    }, () => {});
    return () => { actif = false; };
  }, [ecran, version]);

  // Le panneau se ferme au clic en dehors et à la touche Échap.
  useEffect(() => {
    if (!ouvert) return;
    const clic = (e: MouseEvent) => { if (zone.current && !zone.current.contains(e.target as Node)) setOuvert(false); };
    const touche = (e: KeyboardEvent) => { if (e.key === 'Escape') setOuvert(false); };
    document.addEventListener('mousedown', clic);
    document.addEventListener('keydown', touche);
    return () => {
      document.removeEventListener('mousedown', clic);
      document.removeEventListener('keydown', touche);
    };
  }, [ouvert]);

  const recharger = () => setVersion(v => v + 1);

  function appliquer(filtre: FiltreMemorise) {
    onAppliquer(filtre.valeurs);
    setAnnonce(tf.applied.replace('{nom}', filtre.nom));
    setOuvert(false);
  }

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    const libelle = nom.trim();
    if (!libelle) return;
    if (filtres.some(f => f.nom.toLowerCase() === libelle.toLowerCase()) && !window.confirm(tf.confirmReplace.replace('{nom}', libelle))) return;
    setErreur('');
    try {
      await enregistrerFiltreMemorise(ecran, libelle, valeurs, parDefaut);
      setNom('');
      setParDefaut(false);
      setAnnonce(tf.saved.replace('{nom}', libelle));
      recharger();
    } catch (err) {
      setErreur(messageErreur(err, tf.error));
    }
  }

  async function basculerDefaut(filtre: FiltreMemorise) {
    setErreur('');
    try {
      await modifierFiltreMemorise(filtre.id, { par_defaut: !filtre.par_defaut });
      recharger();
    } catch (err) {
      setErreur(messageErreur(err, tf.error));
    }
  }

  async function supprimer(filtre: FiltreMemorise) {
    if (!window.confirm(tf.confirmDelete.replace('{nom}', filtre.nom))) return;
    setErreur('');
    try {
      await supprimerFiltreMemorise(filtre.id);
      recharger();
    } catch (err) {
      setErreur(messageErreur(err, tf.error));
    }
  }

  return (
    <div ref={zone} className="relative inline-flex flex-wrap items-center gap-2">
      <button type="button" aria-expanded={ouvert} aria-controls={id}
        onClick={() => {
          const bord = zone.current?.getBoundingClientRect();
          setVersLaGauche(bord !== undefined && bord.left + 336 > window.innerWidth);
          setOuvert(o => !o);
        }}
        className="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
        <Bookmark size={16} /> {tf.button}{filtres.length > 0 ? ` (${filtres.length})` : ''}
      </button>
      <span role="status" className="text-xs text-gray-500 dark:text-gray-400">{annonce}</span>
      {ouvert && (
        <div id={id} className={`absolute ${versLaGauche ? 'right-0' : 'left-0'} top-full z-30 mt-1 w-80 max-w-[90vw] p-3 space-y-3 text-left bg-white dark:bg-gray-800 border dark:border-gray-700 rounded-lg shadow-lg`}>
          <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{tf.title}</p>
          {filtres.length === 0 ? (
            <p className="text-sm text-gray-500 dark:text-gray-400">{tf.none}</p>
          ) : (
            <ul className="space-y-1">
              {filtres.map(f => (
                <li key={f.id} className="flex items-center gap-1">
                  <button type="button" onClick={() => appliquer(f)} title={tf.apply}
                    className="flex-1 min-w-0 truncate text-left px-2 py-1 rounded text-sm text-gray-800 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700">
                    {f.nom}
                  </button>
                  <button type="button" onClick={() => { void basculerDefaut(f); }} aria-pressed={f.par_defaut} aria-label={`${tf.default} — ${f.nom}`}
                    title={f.par_defaut ? tf.unsetDefault : tf.setDefault} className="p-1 rounded hover:bg-amber-50 dark:hover:bg-amber-900/30">
                    <Star size={14} className={f.par_defaut ? 'fill-amber-400 text-amber-500' : 'text-gray-400'} />
                  </button>
                  <button type="button" onClick={() => { void supprimer(f); }} aria-label={`${tf.delete} — ${f.nom}`} title={tf.delete}
                    className="p-1 rounded text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30">
                    <Trash2 size={14} />
                  </button>
                </li>
              ))}
            </ul>
          )}
          <form onSubmit={e => { void enregistrer(e); }} className="pt-3 space-y-2 border-t dark:border-gray-700">
            <label htmlFor={`${id}-nom`} className="block text-xs text-gray-500 dark:text-gray-400">{tf.saveCurrent}</label>
            <div className="flex gap-2">
              <input id={`${id}-nom`} value={nom} onChange={e => setNom(e.target.value)} required maxLength={100} placeholder={tf.namePlaceholder}
                className="flex-1 min-w-0 px-2 py-1.5 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
              <button type="submit" className="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-sm hover:bg-indigo-700">{tf.save}</button>
            </div>
            <label className="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
              <input type="checkbox" checked={parDefaut} onChange={e => setParDefaut(e.target.checked)} className="rounded" />
              {tf.asDefault}
            </label>
            {erreur && <p role="alert" className="text-xs text-red-600 dark:text-red-300">{erreur}</p>}
          </form>
        </div>
      )}
    </div>
  );
}
