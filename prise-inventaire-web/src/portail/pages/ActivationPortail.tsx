import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { appelPortail, type ComptePortail } from '../api';
import { usePortail } from '../contexte';
import { CadrePublic, Message } from '../composants';
import { remplir } from '../format';

const classeChamp = 'w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-gray-900';

/** Lien reçu par courriel (invitation ou mot de passe oublié) : le client choisit son mot de passe. */
export default function ActivationPortail() {
  const { t } = useLanguage();
  const tp = t.portal;
  const { slug, base, infos, connecter } = usePortail();
  const navigate = useNavigate();
  const [parametres] = useSearchParams();
  const jeton = parametres.get('jeton') ?? '';
  const [form, setForm] = useState({ password: '', password_confirmation: '' });
  const [erreur, setErreur] = useState('');
  const [lienInvalide, setLienInvalide] = useState(jeton.length !== 64);
  const [occupe, setOccupe] = useState(false);

  async function activer(e: React.FormEvent) {
    e.preventDefault();
    setErreur('');
    if (form.password !== form.password_confirmation) {
      setErreur(tp.passwordMismatch);
      return;
    }
    setOccupe(true);
    try {
      const reponse = await appelPortail<{ token: string; compte: ComptePortail }>(`/portail/${slug}/activation`, {
        methode: 'POST',
        corps: { jeton, ...form },
      });
      connecter({ token: reponse.token, compte: reponse.compte });
      navigate(base, { replace: true });
    } catch (err) {
      // 422 sans détail de champ : lien expiré, déjà utilisé ou remplacé par un plus récent.
      if (err instanceof ApiError && err.status === 422 && !(err.data as { errors?: unknown } | null)?.errors) setLienInvalide(true);
      else setErreur(err instanceof ApiError && err.status === 429 ? tp.tooManyAttempts : messageErreur(err, tp.error));
      setOccupe(false);
    }
  }

  return (
    <CadrePublic>
      {lienInvalide ? (
        <div className="space-y-4">
          <Message type="erreur">{tp.invalidLink}</Message>
          <Link to={`${base}/connexion?oubli=1`} className="block w-full py-2.5 text-center bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700">{tp.requestNewLink}</Link>
          <Link to={`${base}/connexion`} className="block text-center text-sm text-blue-600 hover:underline">{tp.backToLogin}</Link>
        </div>
      ) : (
        <form onSubmit={activer} className="space-y-4">
          <div>
            <h2 className="font-semibold">{tp.activationTitle}</h2>
            <p className="text-sm text-gray-600 mt-1">{remplir(tp.activationHelp, { entreprise: infos.entreprise })}</p>
          </div>
          {erreur && <Message type="erreur">{erreur}</Message>}
          <div>
            <label htmlFor="portail-nouveau-mdp" className="block text-sm font-medium text-gray-700 mb-1">{tp.newPassword}</label>
            <input id="portail-nouveau-mdp" type="password" required minLength={8} autoComplete="new-password" value={form.password}
              onChange={e => setForm({ ...form, password: e.target.value })} className={classeChamp} />
          </div>
          <div>
            <label htmlFor="portail-confirmation-mdp" className="block text-sm font-medium text-gray-700 mb-1">{tp.confirmPassword}</label>
            <input id="portail-confirmation-mdp" type="password" required minLength={8} autoComplete="new-password" value={form.password_confirmation}
              onChange={e => setForm({ ...form, password_confirmation: e.target.value })} className={classeChamp} />
          </div>
          <button type="submit" disabled={occupe} className="w-full py-2.5 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 disabled:opacity-50">
            {tp.activate}
          </button>
        </form>
      )}
    </CadrePublic>
  );
}
