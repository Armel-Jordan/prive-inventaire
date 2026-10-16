import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ClipboardCheck, CheckCircle2, Circle, CheckSquare, Square, RefreshCw, ArrowLeft, ArrowRight, AlertTriangle } from 'lucide-react';
import { getConfigurationACompleter, ignorerReglage, reprendreReglage, type ConfigurationACompleter, type ReglageAMettreEnPlace } from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';

const enAttente = (t: ReglageAMettreEnPlace) => !t.faite && !t.ignoree;

/** Mise en route : étapes vérifiées dans les données, réglages écartés avec une raison, qualité des données. */
export default function ConfigurationACompleterPage() {
  const { t } = useLanguage();
  const tc = t.setup;
  const navigate = useNavigate();
  const { isAdmin } = usePermissions();
  const admin = isAdmin();

  const [donnees, setDonnees] = useState<ConfigurationACompleter | null>(null);
  // Index de l'étape affichée, ou « qualite » ; null : première étape où il reste à faire.
  const [active, setActive] = useState<number | 'qualite' | null>(null);
  const [version, setVersion] = useState(0);
  const [erreur, setErreur] = useState('');
  const [ecart, setEcart] = useState<{ cle: string; raison: string } | null>(null);

  useEffect(() => {
    let actif = true;
    getConfigurationACompleter()
      .then(d => { if (actif) { setDonnees(d); setErreur(''); } })
      .catch(e => { if (actif) setErreur(e instanceof ApiError ? e.message : tc.error); });
    return () => { actif = false; };
  }, [version, tc.error]);

  if (!donnees) {
    return <div className="p-6">{erreur && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}</div>;
  }

  const premiere = Math.max(0, donnees.etapes.findIndex(e => e.taches.some(enAttente)));
  const courante = active ?? premiere;
  const etape = courante === 'qualite' ? null : donnees.etapes[courante];

  async function action(promesse: Promise<ConfigurationACompleter>) {
    try {
      setDonnees(await promesse);
      setEcart(null);
      setErreur('');
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : tc.error);
    }
  }

  // Étape réglée : coche verte ; sinon cercle, épais s'il reste un réglage obligatoire.
  const iconeEtape = (taches: ReglageAMettreEnPlace[], selectionnee: boolean) => {
    if (!taches.some(enAttente)) return <CheckCircle2 size={18} className={`shrink-0 ${selectionnee ? '' : 'text-green-600'}`} />;
    const obligatoire = taches.some(x => x.obligatoire && enAttente(x));
    return <Circle size={18} strokeWidth={obligatoire ? 3 : 2} className={`shrink-0 ${selectionnee ? '' : obligatoire ? 'text-purple-600' : 'text-gray-400'}`} />;
  };

  return (
    <div className="p-6">
      <div className="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><ClipboardCheck className="text-purple-600" /> {tc.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400">{tc.subtitle}</p>
          <p className="text-sm mt-1 font-medium text-gray-700 dark:text-gray-300">
            {donnees.restantes.obligatoires + donnees.restantes.recommandees === 0
              ? tc.allDone
              : tc.remaining.replace('{obligatoires}', String(donnees.restantes.obligatoires)).replace('{recommandees}', String(donnees.restantes.recommandees))}
          </p>
        </div>
        <button onClick={() => setVersion(v => v + 1)} className="flex items-center gap-1 px-3 py-2 text-sm rounded-lg border dark:border-gray-600 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-700">
          <RefreshCw size={14} /> {tc.refresh}
        </button>
      </div>

      {erreur && <p className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 max-w-4xl">{erreur}</p>}

      <div className="flex flex-col md:flex-row gap-6 max-w-5xl">
        {/* Rail des étapes */}
        <nav className="md:w-64 shrink-0 space-y-1" aria-label={tc.title}>
          {donnees.etapes.map((e, index) => (
            <button key={e.cle} onClick={() => setActive(index)} aria-current={courante === index ? 'step' : undefined}
              className={`w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-left ${courante === index ? 'bg-purple-600 text-white' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'}`}>
              {iconeEtape(e.taches, courante === index)}
              <span>{tc.step} {index + 1} — {tc.steps[e.cle] ?? e.cle}<span className="sr-only"> ({e.taches.some(enAttente) ? tc.todo : tc.done})</span></span>
            </button>
          ))}
          {donnees.qualite.length > 0 && (
            <button onClick={() => setActive('qualite')} aria-current={courante === 'qualite' ? 'step' : undefined}
              className={`w-full flex items-center gap-2 px-3 py-2 mt-4 rounded-lg text-sm text-left ${courante === 'qualite' ? 'bg-purple-600 text-white' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'}`}>
              <AlertTriangle size={18} className={`shrink-0 ${courante === 'qualite' ? '' : 'text-amber-500'}`} />
              <span className="flex-1">{tc.quality}</span>
              <span className="text-xs px-1.5 rounded bg-amber-100 text-amber-800">{donnees.qualite.length}</span>
            </button>
          )}
        </nav>

        {/* Étape affichée */}
        <section className="flex-1 bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
          {etape ? (
            <>
              <h2 className="text-lg font-semibold dark:text-white mb-4">{tc.step} {(courante as number) + 1} — {tc.steps[etape.cle] ?? etape.cle}</h2>
              <ul className="space-y-3">
                {etape.taches.map(tache => {
                  const texte = tc.tasks[tache.cle];
                  return (
                    <li key={tache.cle} className={`flex flex-wrap items-start gap-3 p-3 rounded-lg border dark:border-gray-700 ${tache.faite || tache.ignoree ? 'opacity-70' : ''}`}>
                      {tache.faite
                        ? <CheckSquare size={20} role="img" aria-label={tc.done} className="text-green-600 shrink-0 mt-0.5" />
                        : <Square size={20} role="img" aria-label={tc.todo} className={`shrink-0 mt-0.5 ${tache.obligatoire ? 'text-purple-600' : 'text-gray-400'}`} />}
                      <div className="flex-1 min-w-[14rem]">
                        <p className="font-medium text-gray-900 dark:text-white">
                          {texte?.name ?? tache.cle}
                          <span className={`ml-2 px-1.5 py-0.5 rounded text-[11px] font-medium ${tache.obligatoire ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'}`}>
                            {tache.obligatoire ? tc.required : tc.recommended}
                          </span>
                        </p>
                        {tache.ignoree ? (
                          <p className="text-xs text-gray-500 mt-0.5 italic">{tc.ignoredBy.replace('{par}', tache.ignoree.par ?? '—')} : {tache.ignoree.raison}</p>
                        ) : (
                          <p className="text-xs text-gray-500 mt-0.5">{texte?.help}</p>
                        )}
                        {ecart?.cle === tache.cle && (
                          <form className="flex flex-wrap gap-2 mt-2" onSubmit={e => { e.preventDefault(); action(ignorerReglage(tache.cle, ecart.raison)); }}>
                            <input value={ecart.raison} onChange={e => setEcart({ ...ecart, raison: e.target.value })} required maxLength={255} autoFocus
                              placeholder={tc.reason} aria-label={tc.reason} className="flex-1 min-w-[12rem] px-2 py-1 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm" />
                            <button type="submit" className="px-3 py-1 text-sm bg-purple-600 text-white rounded hover:bg-purple-700">{tc.confirmIgnore}</button>
                            <button type="button" onClick={() => setEcart(null)} className="px-3 py-1 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded">{tc.cancel}</button>
                          </form>
                        )}
                      </div>
                      <div className="flex gap-2">
                        {admin && !tache.obligatoire && !tache.faite && !tache.ignoree && ecart?.cle !== tache.cle && (
                          <button onClick={() => setEcart({ cle: tache.cle, raison: '' })} className="px-3 py-1 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded">{tc.ignore}</button>
                        )}
                        {admin && tache.ignoree && (
                          <button onClick={() => action(reprendreReglage(tache.cle))} className="px-3 py-1 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded">{tc.resume}</button>
                        )}
                        <button onClick={() => navigate(tache.lien)} aria-label={`${tc.open} — ${texte?.name ?? tache.cle}`}
                          className={`px-3 py-1 text-sm rounded ${tache.faite || tache.ignoree ? 'border border-purple-600 text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-900/30' : 'bg-purple-600 text-white hover:bg-purple-700'}`}>
                          {tc.open}
                        </button>
                      </div>
                    </li>
                  );
                })}
              </ul>
              {!etape.taches.some(enAttente) && <p className="mt-4 text-sm text-green-700 dark:text-green-400">{tc.stepDone}</p>}
              <div className="flex justify-between mt-6">
                <button disabled={courante === 0} onClick={() => setActive((courante as number) - 1)}
                  className="flex items-center gap-1 px-3 py-2 text-sm rounded-lg border dark:border-gray-600 dark:text-white disabled:opacity-40"><ArrowLeft size={14} /> {tc.previous}</button>
                <button disabled={courante === donnees.etapes.length - 1} onClick={() => setActive((courante as number) + 1)}
                  className="flex items-center gap-1 px-3 py-2 text-sm rounded-lg bg-purple-600 text-white hover:bg-purple-700 disabled:opacity-40">{tc.next} <ArrowRight size={14} /></button>
              </div>
            </>
          ) : (
            <>
              <h2 className="text-lg font-semibold dark:text-white">{tc.quality}</h2>
              <p className="text-sm text-gray-500 mb-4">{tc.qualityHelp}</p>
              {donnees.qualite.length === 0 ? <p className="text-sm text-gray-500">{tc.noQuality}</p> : (
                <ul className="divide-y dark:divide-gray-700">
                  {donnees.qualite.map(c => (
                    <li key={c.cle} className="flex items-center justify-between gap-3 py-2">
                      <span className="text-sm text-gray-800 dark:text-gray-200">{(tc.counters[c.cle] ?? c.cle).replace('{n}', String(c.nombre))}</span>
                      <button onClick={() => navigate(c.lien)} className="px-3 py-1 text-sm rounded border border-purple-600 text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-900/30">{tc.see}</button>
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}
        </section>
      </div>
    </div>
  );
}
