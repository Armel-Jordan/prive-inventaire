import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Lock, LockOpen, RefreshCw } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';
import { messageErreur } from '@/lib/erreurs';
import { getFichesEnCours, libererFiche, type FicheEnCours } from '@/services/verrous';

/** Toutes les 30 secondes : la liste suit les fiches ouvertes et fermées. */
const ACTUALISATION_MS = 30_000;

/**
 * Fiches en cours de modification (plan de parité fripv2, phase 6.3) : qui modifie quelle fiche depuis quand ; avec le
 * droit de libérer, rendre une fiche (le compte qui la modifiait ne peut plus enregistrer ses changements).
 */
export default function FichesEnCoursPage() {
  const { t, language } = useLanguage();
  const tv = t.locks;
  const { peut } = usePermissions();
  const locale = language === 'en' ? 'en-US' : 'fr-FR';
  const [fiches, setFiches] = useState<FicheEnCours[] | null>(null);
  const [erreur, setErreur] = useState('');
  const [info, setInfo] = useState('');
  const [version, setVersion] = useState(0);
  const [enCours, setEnCours] = useState<number | null>(null);

  useEffect(() => {
    let actif = true;
    const charger = () => getFichesEnCours().then(
      liste => { if (actif) { setFiches(liste); setErreur(''); } },
      e => { if (actif) setErreur(messageErreur(e, tv.loadError)); },
    );
    charger();
    const minuterie = window.setInterval(charger, ACTUALISATION_MS);
    return () => {
      actif = false;
      window.clearInterval(minuterie);
    };
  }, [version, tv.loadError]);

  async function liberer(fiche: FicheEnCours) {
    if (!confirm(tv.confirmRelease.replace('{fiche}', fiche.libelle).replace('{nom}', fiche.par || tv.someone))) return;
    setEnCours(fiche.id);
    setErreur('');
    try {
      await libererFiche(fiche.id);
      setInfo(tv.releasedOk.replace('{fiche}', fiche.libelle));
    } catch (e) {
      setErreur(messageErreur(e, tv.releaseError));
    } finally {
      setEnCours(null);
      setVersion(v => v + 1);
    }
  }

  const depuis = (iso: string | null) => (iso ? new Date(iso).toLocaleString(locale, { dateStyle: 'short', timeStyle: 'short' }) : '');

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
            <Lock className="w-6 h-6" aria-hidden="true" /> {tv.title}
          </h1>
          <p className="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-400">{tv.subtitle}</p>
        </div>
        <button type="button" onClick={() => { setInfo(''); setVersion(v => v + 1); }}
          className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
          <RefreshCw className="w-4 h-4" aria-hidden="true" /> {tv.refresh}
        </button>
      </div>

      {erreur && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{erreur}</p>}
      {info && <p role="status" className="rounded-lg bg-green-50 p-3 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-200">{info}</p>}

      <div className="overflow-hidden rounded-xl border bg-white dark:border-gray-700 dark:bg-gray-800">
        {fiches === null ? (
          <p className="p-6 text-sm text-gray-500 dark:text-gray-400">{tv.loading}</p>
        ) : fiches.length === 0 ? (
          <p className="p-6 text-sm text-gray-500 dark:text-gray-400">{tv.none}</p>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-gray-50 text-left text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
              <tr>
                <th className="px-4 py-3 font-medium">{tv.record}</th>
                <th className="px-4 py-3 font-medium">{tv.by}</th>
                <th className="px-4 py-3 font-medium">{tv.since}</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y dark:divide-gray-700">
              {fiches.map(fiche => (
                <tr key={fiche.id} className="dark:text-gray-200">
                  <td className="px-4 py-3">
                    <span className="mr-2 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-300">{tv.types[fiche.entite] ?? fiche.entite}</span>
                    <Link to={fiche.lien} className="text-blue-600 hover:underline dark:text-blue-400">{fiche.libelle}</Link>
                  </td>
                  <td className="px-4 py-3">{fiche.par || tv.someone}</td>
                  <td className="px-4 py-3 whitespace-nowrap">{depuis(fiche.depuis)}</td>
                  <td className="px-4 py-3 text-right">
                    {peut('verrous', 'liberer') && (
                      <button type="button" onClick={() => liberer(fiche)} disabled={enCours === fiche.id}
                        className="inline-flex items-center gap-1 rounded-lg border border-amber-400 px-3 py-1 text-amber-800 hover:bg-amber-50 disabled:opacity-50 dark:border-amber-700 dark:text-amber-200 dark:hover:bg-amber-900/30">
                        <LockOpen className="w-4 h-4" aria-hidden="true" /> {tv.release}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
