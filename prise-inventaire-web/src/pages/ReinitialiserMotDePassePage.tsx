import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { KeyRound, AlertCircle, CheckCircle2 } from 'lucide-react';
import { reinitialiserMotDePasse } from '@/services/securite';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';

/** Nouveau mot de passe à partir du lien reçu par courriel (?jeton=…). */
export default function ReinitialiserMotDePassePage() {
  const { t } = useLanguage();
  const ts = t.security;
  const [parametres] = useSearchParams();
  const jeton = parametres.get('jeton') ?? '';
  const [form, setForm] = useState({ password: '', password_confirmation: '' });
  const [reussi, setReussi] = useState('');
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    setErreur('');
    setOccupe(true);
    try {
      const { message } = await reinitialiserMotDePasse({ jeton, ...form });
      setReussi(message);
    } catch (err) {
      setErreur(err instanceof ApiError ? err.message : ts.error);
    } finally {
      setOccupe(false);
    }
  }

  const champ = 'w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500';

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 flex items-center justify-center p-4">
      <div className="bg-white rounded-2xl shadow-xl w-full max-w-md p-8">
        <div className="text-center mb-8">
          <div className="inline-flex items-center justify-center w-16 h-16 bg-blue-100 rounded-full mb-4">
            <KeyRound className="w-8 h-8 text-blue-600" />
          </div>
          <h1 className="text-2xl font-bold text-gray-800">{ts.resetTitle}</h1>
          {!reussi && jeton.length === 64 && <p className="text-gray-500 mt-1 text-sm">{ts.resetHelp}</p>}
        </div>

        {reussi ? (
          <div className="space-y-6">
            <div role="status" className="p-4 bg-green-50 border border-green-200 rounded-lg flex items-start gap-3 text-green-800">
              <CheckCircle2 size={20} className="shrink-0 mt-0.5" />
              <span className="text-sm">{reussi}</span>
            </div>
            <Link to="/login" className="block w-full py-3 text-center bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700">{ts.goToLogin}</Link>
          </div>
        ) : jeton.length !== 64 ? (
          <div className="space-y-6">
            <div role="alert" className="p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3 text-red-700">
              <AlertCircle size={20} className="shrink-0" />
              <span className="text-sm">{ts.invalidLink}</span>
            </div>
            <Link to="/mot-de-passe-oublie" className="block text-center text-sm text-blue-600 hover:underline">{ts.newLink}</Link>
          </div>
        ) : (
          <>
            {erreur && (
              <div role="alert" className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3 text-red-700">
                <AlertCircle size={20} className="shrink-0" />
                <span className="text-sm">{erreur}</span>
              </div>
            )}
            <form onSubmit={enregistrer} className="space-y-5">
              <div>
                <label htmlFor="nouveau-mot-de-passe" className="block text-sm font-medium text-gray-700 mb-1.5">{ts.newPassword}</label>
                <input id="nouveau-mot-de-passe" type="password" required minLength={8} autoComplete="new-password" value={form.password} className={champ}
                  onChange={(e) => setForm({ ...form, password: e.target.value })} />
              </div>
              <div>
                <label htmlFor="confirmation-mot-de-passe" className="block text-sm font-medium text-gray-700 mb-1.5">{ts.confirmPassword}</label>
                <input id="confirmation-mot-de-passe" type="password" required minLength={8} autoComplete="new-password" value={form.password_confirmation} className={champ}
                  onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} />
              </div>
              <button type="submit" disabled={occupe}
                className="w-full py-3 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 focus:ring-4 focus:ring-blue-200 disabled:opacity-50">
                {ts.save}
              </button>
            </form>
            {erreur && <Link to="/mot-de-passe-oublie" className="mt-4 block text-center text-sm text-blue-600 hover:underline">{ts.newLink}</Link>}
          </>
        )}
      </div>
    </div>
  );
}
