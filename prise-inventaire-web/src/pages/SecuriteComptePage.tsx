import { useEffect, useId, useState } from 'react';
import QRCode from 'qrcode';
import { KeyRound, ShieldCheck, ShieldOff, Copy, Users } from 'lucide-react';
import {
  activerDeuxFacteurs, changerMotDePasse, desactiverDeuxFacteurs, getEtatDeuxFacteurs, getUtilisateursSecurite,
  preparerDeuxFacteurs, regenererCodesSecours, reinitialiserDeuxFacteursUtilisateur,
  type EtatDeuxFacteurs, type UtilisateurSecurite,
} from '@/services/securite';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { useAuth } from '@/contexts/AuthContext';
import { usePermissions } from '@/contexts/PermissionsContext';

type Message = { ok: boolean; texte: string } | null;
type ActionProtegee = 'activer' | 'codes' | 'desactiver';

const erreurDe = (e: unknown, defaut: string) => (e instanceof ApiError ? e.message : defaut);

function Bandeau({ message }: { message: Message }) {
  if (!message) return null;
  return <p role={message.ok ? 'status' : 'alert'} className={`px-3 py-2 rounded-lg text-sm ${message.ok ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>{message.texte}</p>;
}

/** Changement de mot de passe (mot de passe actuel exigé ; les autres sessions sont fermées). */
function MotDePasse() {
  const { t } = useLanguage();
  const ts = t.security;
  const id = useId();
  const [form, setForm] = useState({ mot_de_passe_actuel: '', password: '', password_confirmation: '' });
  const [message, setMessage] = useState<Message>(null);

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    try {
      const { message: texte } = await changerMotDePasse(form);
      setForm({ mot_de_passe_actuel: '', password: '', password_confirmation: '' });
      setMessage({ ok: true, texte });
    } catch (err) {
      setMessage({ ok: false, texte: erreurDe(err, ts.error) });
    }
  }

  const champ = 'w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
  const champs: [keyof typeof form, string, string][] = [
    ['mot_de_passe_actuel', ts.currentPassword, 'current-password'],
    ['password', ts.newPassword, 'new-password'],
    ['password_confirmation', ts.confirmPassword, 'new-password'],
  ];

  return (
    <section className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6 space-y-4" aria-labelledby={`${id}-titre`}>
      <h2 id={`${id}-titre`} className="text-lg font-semibold text-gray-800 dark:text-white flex items-center gap-2"><KeyRound size={18} /> {ts.passwordTitle}</h2>
      <p className="text-sm text-gray-500 dark:text-gray-400">{ts.passwordHelp}</p>
      <Bandeau message={message} />
      <form onSubmit={enregistrer} className="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
        {champs.map(([cle, libelle, autocompletion]) => (
          <div key={cle}>
            <label htmlFor={`${id}-${cle}`} className="block text-sm font-medium mb-1 dark:text-gray-300">{libelle}</label>
            <input id={`${id}-${cle}`} type="password" required minLength={cle === 'mot_de_passe_actuel' ? undefined : 8} autoComplete={autocompletion}
              value={form[cle]} onChange={(e) => setForm({ ...form, [cle]: e.target.value })} className={champ} />
          </div>
        ))}
        <div className="md:col-span-3 flex justify-end">
          <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{ts.change}</button>
        </div>
      </form>
    </section>
  );
}

/** Codes de secours affichés une seule fois, avec copie. */
function CodesSecours({ codes, onFermer }: { codes: string[]; onFermer: () => void }) {
  const { t } = useLanguage();
  const ts = t.security;
  const [copie, setCopie] = useState(false);

  return (
    <div className="p-4 rounded-lg border-2 border-amber-300 bg-amber-50 dark:bg-amber-900/20 space-y-3">
      <h3 className="font-semibold text-gray-900 dark:text-white">{ts.recoveryTitle}</h3>
      <p className="text-sm text-gray-700 dark:text-gray-300">{ts.recoveryIntro}</p>
      <ul className="grid grid-cols-2 gap-2 font-mono text-sm text-gray-900 dark:text-white" aria-label={ts.recoveryTitle}>
        {codes.map(c => <li key={c} className="px-2 py-1 rounded bg-white dark:bg-gray-800 border dark:border-gray-700 text-center">{c}</li>)}
      </ul>
      <div className="flex flex-wrap items-center gap-2">
        <button onClick={() => { navigator.clipboard?.writeText(codes.join('\n')).then(() => setCopie(true)).catch(() => {}); }}
          className="flex items-center gap-1 px-3 py-2 text-sm border rounded-lg bg-white dark:bg-gray-800 dark:border-gray-600 dark:text-white"><Copy size={14} /> {ts.copy}</button>
        {copie && <span role="status" className="text-sm text-green-700">{ts.copied}</span>}
        <button onClick={onFermer} className="ml-auto px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{ts.noted}</button>
      </div>
    </div>
  );
}

/** Double authentification du compte : activation avec QR code, codes de secours, désactivation. */
function DeuxFacteurs({ onChange }: { onChange: () => void }) {
  const { t, language } = useLanguage();
  const ts = t.security;
  const id = useId();
  const { user, updateUser } = useAuth();
  const [etat, setEtat] = useState<EtatDeuxFacteurs | null>(null);
  const [action, setAction] = useState<ActionProtegee | null>(null);
  const [motDePasse, setMotDePasse] = useState('');
  const [preparation, setPreparation] = useState<{ secret: string; uri: string } | null>(null);
  const [qr, setQr] = useState('');
  const [code, setCode] = useState('');
  const [codes, setCodes] = useState<string[] | null>(null);
  const [message, setMessage] = useState<Message>(null);

  useEffect(() => {
    let actif = true;
    getEtatDeuxFacteurs().then(e => { if (actif) setEtat(e); }).catch(e => { if (actif) setMessage({ ok: false, texte: erreurDe(e, ts.error) }); });
    return () => { actif = false; };
  }, [ts.error]);

  useEffect(() => {
    if (!preparation) return;
    let actif = true;
    QRCode.toDataURL(preparation.uri, { margin: 1, width: 200 }).then(url => { if (actif) setQr(url); }).catch(() => {});
    return () => { actif = false; };
  }, [preparation]);

  function majEtat(nouvel: EtatDeuxFacteurs) {
    setEtat(nouvel);
    if (user && user.deux_facteurs !== nouvel.active) updateUser({ ...user, deux_facteurs: nouvel.active });
    onChange();
  }

  async function confirmerMotDePasse(e: React.FormEvent) {
    e.preventDefault();
    try {
      if (action === 'activer') {
        setPreparation(await preparerDeuxFacteurs(motDePasse));
      } else if (action === 'codes') {
        const resultat = await regenererCodesSecours(motDePasse);
        majEtat(resultat);
        setCodes(resultat.codes_secours ?? null);
      } else if (action === 'desactiver') {
        majEtat(await desactiverDeuxFacteurs(motDePasse));
      }
      setAction(null);
      setMotDePasse('');
      setMessage(null);
    } catch (err) {
      setMessage({ ok: false, texte: erreurDe(err, ts.error) });
    }
  }

  async function activer(e: React.FormEvent) {
    e.preventDefault();
    try {
      const resultat = await activerDeuxFacteurs(code);
      majEtat(resultat);
      setCodes(resultat.codes_secours ?? null);
      setPreparation(null);
      setQr('');
      setCode('');
      setMessage(null);
    } catch (err) {
      setMessage({ ok: false, texte: erreurDe(err, ts.error) });
    }
  }

  const champ = 'px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';
  const date = etat?.active_le ? new Date(etat.active_le).toLocaleDateString(language === 'en' ? 'en-US' : 'fr-FR') : '';

  return (
    <section className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6 space-y-4" aria-labelledby={`${id}-titre`}>
      <h2 id={`${id}-titre`} className="text-lg font-semibold text-gray-800 dark:text-white flex items-center gap-2"><ShieldCheck size={18} /> {ts.twoFactorTitle}</h2>
      {etat && (
        <p className={`text-sm font-medium ${etat.active ? 'text-green-700 dark:text-green-400' : 'text-gray-600 dark:text-gray-300'}`}>
          {etat.active ? ts.statusOn.replace('{date}', date) : ts.statusOff}
          {etat.active && <span className="ml-2 font-normal text-gray-500">{ts.recoveryRemaining.replace('{n}', String(etat.codes_secours_restants))}</span>}
        </p>
      )}
      {!etat?.active && !preparation && (
        <>
          <p className="text-sm text-gray-600 dark:text-gray-300">{ts.intro}</p>
          <p className="text-sm text-amber-700 dark:text-amber-400">{ts.mobileWarning}</p>
        </>
      )}
      <Bandeau message={message} />

      {codes && <CodesSecours codes={codes} onFermer={() => setCodes(null)} />}

      {action ? (
        <form onSubmit={confirmerMotDePasse} className="flex flex-wrap items-end gap-2">
          <div>
            <label htmlFor={`${id}-mdp`} className="block text-sm mb-1 dark:text-gray-300">{ts.confirmWithPassword}</label>
            <input id={`${id}-mdp`} type="password" required autoFocus autoComplete="current-password" value={motDePasse} onChange={(e) => setMotDePasse(e.target.value)} className={champ} />
          </div>
          <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{ts.continue}</button>
          <button type="button" onClick={() => { setAction(null); setMotDePasse(''); setMessage(null); }} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-white">{ts.cancel}</button>
        </form>
      ) : preparation ? (
        <div className="space-y-4">
          <p className="text-sm text-gray-700 dark:text-gray-300">{ts.scan}</p>
          <div className="flex flex-wrap items-center gap-6">
            {qr && <img src={qr} alt={ts.qrAlt} width={200} height={200} className="rounded border bg-white" />}
            <div className="text-sm text-gray-600 dark:text-gray-300">
              <p>{ts.manualKey}</p>
              <p className="mt-1 font-mono text-base tracking-wider text-gray-900 dark:text-white break-all select-all">{preparation.secret.match(/.{1,4}/g)?.join(' ')}</p>
            </div>
          </div>
          <form onSubmit={activer} className="flex flex-wrap items-end gap-2">
            <div>
              <label htmlFor={`${id}-code`} className="block text-sm mb-1 dark:text-gray-300">{ts.enterCode}</label>
              <input id={`${id}-code`} required inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={code} onChange={(e) => setCode(e.target.value)}
                className={`${champ} w-36 text-center tracking-widest`} />
            </div>
            <button type="submit" className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{ts.activate}</button>
            <button type="button" onClick={() => { setPreparation(null); setQr(''); setCode(''); setMessage(null); }} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-white">{ts.cancel}</button>
          </form>
        </div>
      ) : etat && (
        <div className="flex flex-wrap gap-2">
          {etat.active ? (
            <>
              <button onClick={() => setAction('codes')} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-white hover:bg-gray-50 dark:hover:bg-gray-700">{ts.regenerate}</button>
              <button onClick={() => setAction('desactiver')} className="flex items-center gap-1 px-4 py-2 text-sm border border-red-300 text-red-700 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20"><ShieldOff size={14} /> {ts.disable}</button>
            </>
          ) : (
            <button onClick={() => setAction('activer')} className="px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700">{ts.enable}</button>
          )}
        </div>
      )}
    </section>
  );
}

/** Administrateurs : désactiver la double authentification d'un utilisateur qui a perdu son téléphone. */
function UtilisateursDeuxFacteurs({ actualisation }: { actualisation: number }) {
  const { t } = useLanguage();
  const ts = t.security;
  const id = useId();
  const [utilisateurs, setUtilisateurs] = useState<UtilisateurSecurite[]>([]);
  const [version, setVersion] = useState(0);
  const [message, setMessage] = useState<Message>(null);

  // Rechargée après une action ici, ou quand l'utilisateur change sa propre double authentification.
  useEffect(() => {
    let actif = true;
    getUtilisateursSecurite().then(u => { if (actif) setUtilisateurs(u); }).catch(e => { if (actif) setMessage({ ok: false, texte: erreurDe(e, ts.error) }); });
    return () => { actif = false; };
  }, [version, actualisation, ts.error]);

  async function reinitialiser(u: UtilisateurSecurite) {
    if (!confirm(ts.confirmReset.replace('{nom}', u.nom))) return;
    try {
      const { message: texte } = await reinitialiserDeuxFacteursUtilisateur(u.id);
      setMessage({ ok: true, texte });
      setVersion(v => v + 1);
    } catch (err) {
      setMessage({ ok: false, texte: erreurDe(err, ts.error) });
    }
  }

  return (
    <section className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6 space-y-4" aria-labelledby={`${id}-titre`}>
      <h2 id={`${id}-titre`} className="text-lg font-semibold text-gray-800 dark:text-white flex items-center gap-2"><Users size={18} /> {ts.adminTitle}</h2>
      <p className="text-sm text-gray-500 dark:text-gray-400">{ts.adminHelp}</p>
      <Bandeau message={message} />
      <table className="w-full text-sm">
        <thead className="bg-gray-50 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
          <tr>
            <th className="px-3 py-2 text-left font-medium">{ts.user}</th>
            <th className="px-3 py-2 text-left font-medium">{ts.twoFactorTitle}</th>
            <th className="px-3 py-2"><span className="sr-only">{ts.disable}</span></th>
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
          {utilisateurs.map(u => (
            <tr key={u.id} className={`text-gray-800 dark:text-gray-200 ${u.actif ? '' : 'opacity-60'}`}>
              <td className="px-3 py-2">{u.nom}<span className="block text-xs text-gray-500">{u.email}</span></td>
              <td className="px-3 py-2">
                <span className={`px-2 py-0.5 rounded-full text-xs ${u.deux_facteurs ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'}`}>
                  {u.deux_facteurs ? ts.on : ts.off}
                </span>
              </td>
              <td className="px-3 py-2 text-right">
                {u.deux_facteurs && (
                  <button onClick={() => reinitialiser(u)} aria-label={`${ts.disable} — ${u.nom}`} className="px-3 py-1 text-sm border border-red-300 text-red-700 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20">{ts.disable}</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

/** Sécurité du compte : mot de passe, double authentification, et gestion par les administrateurs. */
export default function SecuriteComptePage() {
  const { t } = useLanguage();
  const ts = t.security;
  const { isAdmin } = usePermissions();
  const [actualisation, setActualisation] = useState(0);

  return (
    <div className="max-w-4xl mx-auto space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">{ts.title}</h1>
        <p className="text-gray-500 dark:text-gray-400">{ts.subtitle}</p>
      </div>
      <MotDePasse />
      <DeuxFacteurs onChange={() => setActualisation(n => n + 1)} />
      {isAdmin() && <UtilisateursDeuxFacteurs actualisation={actualisation} />}
    </div>
  );
}
