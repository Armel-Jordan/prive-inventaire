import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { LogIn, Building2, AlertCircle, ShieldCheck } from 'lucide-react';
import { MARQUE_SESSION_EXPIREE, useAuth, type ResultatConnexion } from '@/contexts/AuthContext';
import { useLanguage } from '@/i18n/useLanguage';

export default function LoginPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const { login, validerDeuxFacteurs } = useAuth();
  const { t } = useLanguage();
  const ts = t.security;
  // Page demandée avant le retour à la connexion, reprise ensuite (sauf la connexion elle-même).
  const depuis = (location.state as { depuis?: string } | null)?.depuis;
  const destination = depuis && !depuis.startsWith('/login') ? depuis : '/';
  const [form, setForm] = useState({ email: '', password: '', tenant_slug: '' });
  const [error, setError] = useState('');
  // Session fermée par l'API (jeton périmé ou révoqué) : expliqué une fois, à l'arrivée sur la page.
  const [sessionExpiree] = useState(() => {
    const marque = sessionStorage.getItem(MARQUE_SESSION_EXPIREE) === '1';
    sessionStorage.removeItem(MARQUE_SESSION_EXPIREE);
    return marque;
  });
  const [loading, setLoading] = useState(false);
  // Double authentification : défi remis après le mot de passe, puis code de l'application ou de secours.
  const [defi, setDefi] = useState<string | null>(null);
  const [code, setCode] = useState('');
  const [codeSecours, setCodeSecours] = useState(false);

  function poursuivre(result: ResultatConnexion) {
    if (result.needsProfile) {
      navigate('/complete-profile');
    } else {
      navigate(destination, { replace: true });
    }
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError('');
    setLoading(true);

    const result = await login(form.email, form.password, form.tenant_slug);

    if (result.success) {
      poursuivre(result);
    } else if (result.defi) {
      setDefi(result.defi);
      setCode('');
    } else {
      setError('Identifiants invalides ou entreprise non trouvée');
    }

    setLoading(false);
  }

  async function validerCode(e: React.FormEvent) {
    e.preventDefault();
    if (!defi) return;
    setError('');
    setLoading(true);

    const result = await validerDeuxFacteurs(defi, code);

    if (result.success) {
      poursuivre(result);
    } else if (result.defiExpire) {
      setDefi(null);
      setError(ts.expired);
    } else {
      setError(result.message ?? ts.error);
      setCode('');
    }

    setLoading(false);
  }

  if (defi) {
    return (
      <div className="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 flex items-center justify-center p-4">
        <div className="bg-white rounded-2xl shadow-xl w-full max-w-md p-8">
          <div className="text-center mb-8">
            <div className="inline-flex items-center justify-center w-16 h-16 bg-blue-100 rounded-full mb-4">
              <ShieldCheck className="w-8 h-8 text-blue-600" />
            </div>
            <h1 className="text-2xl font-bold text-gray-800">{ts.twoFactorTitle}</h1>
            <p className="text-gray-500 mt-1">{codeSecours ? ts.recoveryHelp : ts.twoFactorHelp}</p>
          </div>

          {error && (
            <div role="alert" className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3 text-red-700">
              <AlertCircle size={20} />
              <span className="text-sm">{error}</span>
            </div>
          )}

          <form onSubmit={validerCode} className="space-y-5">
            <div>
              <label htmlFor="code-deux-facteurs" className="block text-sm font-medium text-gray-700 mb-1.5">{ts.code}</label>
              <input
                id="code-deux-facteurs"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                autoComplete="one-time-code"
                inputMode={codeSecours ? 'text' : 'numeric'}
                maxLength={codeSecours ? 20 : 6}
                autoFocus
                required
                className="w-full px-4 py-2.5 border border-gray-300 rounded-lg text-center text-xl tracking-widest focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                placeholder={codeSecours ? 'ABCDE-FGHJK' : '123456'}
              />
            </div>
            <button
              type="submit"
              disabled={loading}
              className="w-full py-3 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 focus:ring-4 focus:ring-blue-200 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {ts.verify}
            </button>
          </form>

          <div className="mt-6 flex flex-wrap justify-between gap-2 text-sm">
            <button type="button" onClick={() => { setCodeSecours(!codeSecours); setCode(''); }} className="text-blue-600 hover:underline">
              {codeSecours ? ts.useApp : ts.useRecovery}
            </button>
            <button type="button" onClick={() => { setDefi(null); setError(''); }} className="text-gray-500 hover:underline">
              {ts.backToLogin}
            </button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 flex items-center justify-center p-4">
      <div className="bg-white rounded-2xl shadow-xl w-full max-w-md p-8">
        <div className="text-center mb-8">
          <div className="inline-flex items-center justify-center w-16 h-16 bg-blue-100 rounded-full mb-4">
            <Building2 className="w-8 h-8 text-blue-600" />
          </div>
          <h1 className="text-2xl font-bold text-gray-800">Prise Inventaire</h1>
          <p className="text-gray-500 mt-1">Connectez-vous à votre espace</p>
        </div>

        {sessionExpiree && !error && (
          <div role="status" className="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-lg flex items-center gap-3 text-amber-800">
            <AlertCircle size={20} />
            <span className="text-sm">{t.auth.sessionExpired}</span>
          </div>
        )}

        {error && (
          <div role="alert" className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg flex items-center gap-3 text-red-700">
            <AlertCircle size={20} />
            <span className="text-sm">{error}</span>
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-5">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">
              Code entreprise
            </label>
            <input
              type="text"
              value={form.tenant_slug}
              onChange={(e) => setForm({ ...form, tenant_slug: e.target.value.toLowerCase() })}
              className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
              placeholder="ex: acme"
              required
            />
            <p className="text-xs text-gray-400 mt-1">Le code fourni par votre administrateur</p>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">
              Email
            </label>
            <input
              type="email"
              value={form.email}
              onChange={(e) => setForm({ ...form, email: e.target.value })}
              className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
              placeholder="vous@exemple.com"
              required
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">
              Mot de passe
            </label>
            <input
              type="password"
              value={form.password}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
              className="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
              placeholder="••••••••"
              required
            />
            <div className="mt-1.5 text-right">
              <Link to="/mot-de-passe-oublie" className="text-sm text-blue-600 hover:underline">{ts.forgot}</Link>
            </div>
          </div>

          <button
            type="submit"
            disabled={loading}
            className="w-full py-3 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 focus:ring-4 focus:ring-blue-200 transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
          >
            {loading ? (
              <span>Connexion...</span>
            ) : (
              <>
                <LogIn size={20} />
                Se connecter
              </>
            )}
          </button>
        </form>
      </div>
    </div>
  );
}
