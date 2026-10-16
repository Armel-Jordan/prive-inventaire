import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Bell, AlertTriangle, CalendarClock, CreditCard, ShoppingCart, Truck, ClipboardCheck, Play, Timer } from 'lucide-react';
import {
  getTachesPlanifiees, modifierTachePlanifiee, executerTachePlanifiee,
  type EtatTachesPlanifiees, type TachePlanifiee, type ReglageTache,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { useAuth } from '@/contexts/AuthContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { fuseauValide, reglageDe, resumeTache } from '@/lib/taches';

/** Tâches planifiées qui préviennent par notification, dans l'ordre de l'écran. */
const ALERTES: { code: string; icone: typeof Bell; couleur: string }[] = [
  { code: 'alertes_stock', icone: AlertTriangle, couleur: 'text-red-600 bg-red-50 dark:bg-red-900/30' },
  { code: 'transferts_imminents', icone: CalendarClock, couleur: 'text-blue-600 bg-blue-50 dark:bg-blue-900/30' },
  { code: 'factures_impayees', icone: CreditCard, couleur: 'text-orange-600 bg-orange-50 dark:bg-orange-900/30' },
  { code: 'commandes_fournisseur_en_retard', icone: ShoppingCart, couleur: 'text-yellow-600 bg-yellow-50 dark:bg-yellow-900/30' },
  { code: 'livraisons_en_retard', icone: Truck, couleur: 'text-purple-600 bg-purple-50 dark:bg-purple-900/30' },
  { code: 'controles_techniques', icone: ClipboardCheck, couleur: 'text-teal-600 bg-teal-50 dark:bg-teal-900/30' },
];

/**
 * Réglage des alertes : les tâches planifiées qui notifient, limitées aux modules de l'entreprise. Activation,
 * délai en jours et vérification immédiate ; fréquence et historique se règlent dans les tâches planifiées.
 * Réservé aux administrateurs, comme elles. L'écran était factice (valeurs codées en dur, sauvegarde simulée).
 */
export default function NotificationsConfigPage() {
  const { t, language } = useLanguage();
  const ta = t.alertsConfig;
  const ts = t.scheduledTasks;
  const { tenant } = useAuth();
  const locale = language === 'en' ? 'en-US' : 'fr-FR';

  const [etat, setEtat] = useState<EtatTachesPlanifiees | null>(null);
  const [refuse, setRefuse] = useState(false);
  const [version, setVersion] = useState(0);
  const [message, setMessage] = useState<{ erreur: boolean; texte: string } | null>(null);
  // Délais en cours de saisie, par tâche (absents : valeur enregistrée).
  const [delais, setDelais] = useState<Record<number, string>>({});
  const [enCours, setEnCours] = useState<number | null>(null);

  useEffect(() => {
    let actif = true;
    getTachesPlanifiees()
      .then(e => { if (actif) setEtat(e); })
      .catch(e => {
        if (!actif) return;
        if (e instanceof ApiError && e.status === 403) setRefuse(true);
        else setMessage({ erreur: true, texte: messageErreur(e, ts.error) });
      });
    return () => { actif = false; };
  }, [version, ts.error]);

  const fuseau = fuseauValide(etat?.fuseau);
  const date = (iso: string | null) => (iso ? new Date(iso).toLocaleString(locale, { timeZone: fuseau, dateStyle: 'short', timeStyle: 'short' }) : ts.never);
  const nom = (code: string) => ts.tasks[code]?.name ?? code;
  const modules = tenant?.modules;
  const alertes = ALERTES.flatMap(alerte => {
    const tache = etat?.taches.find(x => x.code === alerte.code);
    const disponible = tache && (!tache.module || !Array.isArray(modules) || modules.includes(tache.module));
    return disponible ? [{ ...alerte, tache }] : [];
  });

  async function enregistrer(tache: TachePlanifiee, changements: Partial<ReglageTache>): Promise<boolean> {
    setMessage(null);
    try {
      await modifierTachePlanifiee(tache.id, { ...reglageDe(tache, fuseau), ...changements });
      setMessage({ erreur: false, texte: ts.saved });
      setVersion(v => v + 1);
      return true;
    } catch (e) {
      setMessage({ erreur: true, texte: messageErreur(e, ts.error) });
      return false;
    }
  }

  async function enregistrerDelai(tache: TachePlanifiee) {
    const saisie = delais[tache.id] ?? '';
    const jours = Number(saisie);
    if (saisie.trim() === '' || !Number.isInteger(jours) || jours < 0 || jours > 365) {
      setMessage({ erreur: true, texte: ta.invalidDelay });
      return;
    }
    if (await enregistrer(tache, { parametres: { jours } })) {
      setDelais(d => {
        const reste = { ...d };
        delete reste[tache.id];
        return reste;
      });
    }
  }

  async function verifier(tache: TachePlanifiee) {
    setEnCours(tache.id);
    setMessage(null);
    try {
      const { execution } = await executerTachePlanifiee(tache.id);
      setMessage({ erreur: execution.resultat === 'erreur', texte: `${nom(tache.code)} : ${execution.message ?? ''}` });
    } catch (e) {
      setMessage({ erreur: true, texte: e instanceof ApiError && e.status === 409 ? ts.alreadyRunning : messageErreur(e, ts.error) });
    } finally {
      setEnCours(null);
      setVersion(v => v + 1);
    }
  }

  const badge = (resultat: 'succes' | 'erreur' | null) => resultat && (
    <span className={`px-2 py-0.5 rounded text-xs font-medium ${resultat === 'succes' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>
      {resultat === 'succes' ? ts.success : ts.failure}
    </span>
  );

  return (
    <div className="p-6">
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Bell className="text-purple-600" /> {ta.title}</h1>
        <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{ta.subtitle}</p>
      </div>

      {refuse ? (
        <p className="px-4 py-3 rounded-lg text-sm bg-amber-50 text-amber-900 max-w-3xl">{ta.adminOnly}</p>
      ) : (
        <>
          {message && (
            <p role="status" className={`mb-4 px-3 py-2 rounded-lg text-sm max-w-6xl ${message.erreur ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}>{message.texte}</p>
          )}
          {etat && (
            <p className="mb-4 text-sm text-gray-600 dark:text-gray-300">
              {ta.activeCount.replace('{actives}', String(alertes.filter(a => a.tache.actif).length)).replace('{total}', String(alertes.length))}
              {' · '}
              {ts.timezone.replace('{fuseau}', etat.fuseau)}
            </p>
          )}
          {etat && alertes.length === 0 && <p className="text-sm text-gray-500 dark:text-gray-400">{ta.none}</p>}

          <div className="grid grid-cols-1 xl:grid-cols-2 gap-4 max-w-6xl">
            {alertes.map(({ code, icone: Icone, couleur, tache }) => {
              const textes = ts.tasks[code];
              const delai = tache.parametres?.jours;
              const saisie = delais[tache.id];
              return (
                <section key={code} aria-labelledby={`alerte-${code}`} className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 flex flex-col gap-4">
                  <div className="flex items-start gap-3">
                    <div className={`p-2 rounded-lg shrink-0 ${couleur}`}><Icone size={22} /></div>
                    <div className="flex-1 min-w-0">
                      <h2 id={`alerte-${code}`} className="font-semibold text-gray-900 dark:text-white">{nom(code)}</h2>
                      <p className="text-sm text-gray-500 dark:text-gray-400">{textes?.description}</p>
                    </div>
                    <label className="inline-flex items-center cursor-pointer shrink-0" title={ts.active}>
                      <input type="checkbox" className="sr-only peer" checked={tache.actif} aria-label={`${ts.active} — ${nom(code)}`}
                        onChange={e => enregistrer(tache, { actif: e.target.checked })} />
                      <span className="w-9 h-5 bg-gray-300 rounded-full relative transition-colors peer-checked:bg-purple-600 after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:w-4 after:h-4 after:bg-white after:rounded-full after:transition-transform peer-checked:after:translate-x-4" />
                    </label>
                  </div>

                  {delai !== undefined && (
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                      <label htmlFor={`delai-${code}`} className="text-gray-700 dark:text-gray-300">{textes?.delay ?? ts.delay}</label>
                      <input id={`delai-${code}`} type="number" min={0} max={365} inputMode="numeric"
                        value={saisie ?? String(delai)}
                        onChange={e => setDelais(d => ({ ...d, [tache.id]: e.target.value }))}
                        onKeyDown={e => { if (e.key === 'Enter') enregistrerDelai(tache); }}
                        className="w-20 px-2 py-1 border rounded text-center dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                      <span className="text-gray-500 dark:text-gray-400">{ts.daysUnit}</span>
                      {saisie !== undefined && saisie !== String(delai) && (
                        <button type="button" onClick={() => enregistrerDelai(tache)} className="px-3 py-1 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                          {ta.saveDelay}
                        </button>
                      )}
                    </div>
                  )}

                  <div className="flex flex-wrap items-end justify-between gap-3 pt-3 border-t dark:border-gray-700">
                    <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                      <div>
                        <dt className="text-xs uppercase text-gray-500 dark:text-gray-400">{ts.frequency}</dt>
                        <dd className="text-gray-800 dark:text-gray-200">{tache.actif ? resumeTache(tache, ts, date) : ts.inactive}</dd>
                      </div>
                      <div>
                        <dt className="text-xs uppercase text-gray-500 dark:text-gray-400">{ta.lastCheck}</dt>
                        <dd className="flex flex-wrap items-center gap-2 text-gray-800 dark:text-gray-200">
                          {date(tache.derniere_execution)}
                          {tache.statut === 'en_cours' ? <span className="px-2 py-0.5 rounded text-xs bg-blue-100 text-blue-800">{ts.running}</span> : badge(tache.dernier_resultat)}
                        </dd>
                      </div>
                    </dl>
                    <button type="button" onClick={() => verifier(tache)} disabled={enCours !== null} aria-label={`${ta.checkNow} — ${nom(code)}`}
                      className="flex items-center gap-1 px-3 py-1.5 text-sm text-green-700 border border-green-200 rounded-lg hover:bg-green-50 disabled:opacity-40 dark:text-green-400 dark:border-green-800 dark:hover:bg-green-900/30">
                      <Play size={14} className={enCours === tache.id ? 'animate-pulse' : ''} /> {ta.checkNow}
                    </button>
                  </div>
                </section>
              );
            })}
          </div>

          <p className="mt-6 text-sm">
            <Link to="/taches-planifiees" className="inline-flex items-center gap-1 text-purple-600 hover:underline dark:text-purple-400">
              <Timer size={16} /> {ta.advanced}
            </Link>
          </p>
        </>
      )}
    </div>
  );
}
