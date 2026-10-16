import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { appelPortail, type ComptePortail } from '../api';
import { usePortail } from '../contexte';
import { CadrePublic, Message } from '../composants';
import { remplir } from '../format';

const classeChamp = 'w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-gray-900';

/** Connexion du client, ou demande d'un lien pour choisir un nouveau mot de passe. */
export default function ConnexionPortail() {
  const { t, language } = useLanguage();
  const tp = t.portal;
  const { slug, infos, avis, connecter, changerEntreprise } = usePortail();
  const [parametres] = useSearchParams();
  const [oubli, setOubli] = useState(parametres.has('oubli'));
  const [email, setEmail] = useState('');
  const [motDePasse, setMotDePasse] = useState('');
  const [erreur, setErreur] = useState('');
  const [envoye, setEnvoye] = useState(false);
  const [occupe, setOccupe] = useState(false);

  const erreurConnexion = (e: unknown) => {
    if (e instanceof ApiError && e.status === 429) return tp.tooManyAttempts;
    // 422 sans détail de champ : adresse ou mot de passe incorrect.
    if (e instanceof ApiError && e.status === 422 && !(e.data as { errors?: unknown } | null)?.errors) return tp.loginFailed;
    return messageErreur(e, tp.error);
  };

  async function seConnecter(e: React.FormEvent) {
    e.preventDefault();
    setErreur('');
    setOccupe(true);
    try {
      const reponse = await appelPortail<{ token: string; compte: ComptePortail }>(`/portail/${slug}/connexion`, {
        methode: 'POST',
        corps: { email: email.trim(), password: motDePasse },
      });
      connecter({ slug, token: reponse.token, compte: reponse.compte });
    } catch (err) {
      setErreur(erreurConnexion(err));
      setOccupe(false);
    }
  }

  async function demanderLien(e: React.FormEvent) {
    e.preventDefault();
    setErreur('');
    setOccupe(true);
    try {
      await appelPortail(`/portail/${slug}/mot-de-passe-oublie`, {
        methode: 'POST',
        corps: { email: email.trim(), langue: language },
      });
      setEnvoye(true);
    } catch (err) {
      setErreur(err instanceof ApiError && err.status === 429 ? tp.tooManyAttempts : messageErreur(err, tp.error));
    } finally {
      setOccupe(false);
    }
  }

  const changerMode = (versOubli: boolean) => {
    setOubli(versOubli);
    setErreur('');
    setEnvoye(false);
  };

  return (
    <CadrePublic>
      {oubli ? (
        <div className="space-y-4">
          <h2 className="font-semibold">{tp.forgotPassword}</h2>
          {envoye ? (
            <Message type="succes">{tp.resetSent}</Message>
          ) : (
            <form onSubmit={demanderLien} className="space-y-4">
              <p className="text-sm text-gray-600">{tp.forgotHelp}</p>
              {erreur && <Message type="erreur">{erreur}</Message>}
              <div>
                <label htmlFor="portail-oubli-email" className="block text-sm font-medium text-gray-700 mb-1">{tp.email}</label>
                <input id="portail-oubli-email" type="email" required autoComplete="email" value={email} onChange={e => setEmail(e.target.value)} className={classeChamp} />
              </div>
              <button type="submit" disabled={occupe} className="w-full py-2.5 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 disabled:opacity-50">
                {tp.sendLink}
              </button>
            </form>
          )}
          <button type="button" onClick={() => changerMode(false)} className="block mx-auto text-sm text-blue-600 hover:underline">{tp.backToLogin}</button>
        </div>
      ) : (
        <div className="space-y-4">
          {infos.message_accueil && <p className="text-sm text-gray-700 whitespace-pre-line bg-gray-50 rounded-lg p-3">{infos.message_accueil}</p>}
          {avis && !erreur && <Message type="info">{avis}</Message>}
          {erreur && <Message type="erreur">{erreur}</Message>}
          <form onSubmit={seConnecter} className="space-y-4">
            <div>
              <label htmlFor="portail-email" className="block text-sm font-medium text-gray-700 mb-1">{tp.email}</label>
              <input id="portail-email" type="email" required autoComplete="email" value={email} onChange={e => setEmail(e.target.value)} className={classeChamp} />
            </div>
            <div>
              <label htmlFor="portail-mot-de-passe" className="block text-sm font-medium text-gray-700 mb-1">{tp.password}</label>
              <input id="portail-mot-de-passe" type="password" required autoComplete="current-password" value={motDePasse} onChange={e => setMotDePasse(e.target.value)} className={classeChamp} />
            </div>
            <button type="submit" disabled={occupe} className="w-full py-2.5 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 disabled:opacity-50">
              {tp.login}
            </button>
          </form>
          <button type="button" onClick={() => changerMode(true)} className="block mx-auto text-sm text-blue-600 hover:underline">{tp.forgotPassword}</button>
          <p className="text-xs text-center text-gray-500">{remplir(tp.noAccount, { entreprise: infos.entreprise })}</p>
          <button type="button" onClick={changerEntreprise} className="block mx-auto text-xs text-gray-500 hover:text-gray-800 hover:underline">{tp.changeCompany}</button>
        </div>
      )}
    </CadrePublic>
  );
}
