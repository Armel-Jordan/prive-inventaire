import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { ArrowLeft, Shield, Check, Zap, Star, Crown } from 'lucide-react';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const STORAGE_KEY = 'prise_super_admin';

interface TenantForm {
  nom: string;
  slug: string;
  plan: 'starter' | 'pro' | 'enterprise';
  duree_abonnement: 1 | 3 | 5;
  renouvelable: boolean;
}

const PLANS: {
  key: TenantForm['plan'];
  label: string;
  icon: typeof Zap;
  color: string;
  border: string;
  features: string[];
}[] = [
  {
    key: 'starter',
    label: 'Starter',
    icon: Zap,
    color: 'text-slate-300',
    border: 'border-slate-600',
    features: ['1–5 utilisateurs', 'Inventaire de base', 'Alertes stock', 'Export CSV'],
  },
  {
    key: 'pro',
    label: 'Pro',
    icon: Star,
    color: 'text-blue-400',
    border: 'border-blue-500',
    features: ["Jusqu'à 20 utilisateurs", 'Rapports avancés', 'Commandes fournisseurs', 'Réceptions', 'Approbations'],
  },
  {
    key: 'enterprise',
    label: 'Enterprise',
    icon: Crown,
    color: 'text-purple-400',
    border: 'border-purple-500',
    features: ['Utilisateurs illimités', 'Toutes les fonctionnalités', 'Multi-dépôts', 'API access', 'Support dédié'],
  },
];

function getAuth() {
  const stored = localStorage.getItem(STORAGE_KEY);
  if (!stored) return null;
  try { return JSON.parse(stored); } catch { return null; }
}

function generateSlug(nom: string): string {
  return nom
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');
}

export default function SuperAdminNewTenantPage() {
  const navigate = useNavigate();
  const auth = getAuth();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [step, setStep] = useState<1 | 2>(1);
  const [form, setForm] = useState<TenantForm>({
    nom: '',
    slug: '',
    plan: 'pro',
    duree_abonnement: 1,
    renouvelable: true,
  });

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError('');
    try {
      const res = await fetch(`${API_BASE_URL}/super-admin/tenants`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${auth?.token}`,
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify(form),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || 'Erreur lors de la création');
      navigate(`/super-admin/tenants/${data.tenant.id}`);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Erreur lors de la création');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="min-h-screen bg-slate-950">
      {/* Header */}
      <header className="bg-slate-900 border-b border-slate-800 sticky top-0 z-10">
        <div className="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Link
              to="/super-admin/dashboard"
              className="flex items-center gap-2 text-slate-400 hover:text-white transition-colors text-sm"
            >
              <ArrowLeft size={18} />
              Dashboard
            </Link>
            <span className="text-slate-700">/</span>
            <span className="text-white font-medium">Nouvelle entreprise</span>
          </div>
          <div className="flex items-center gap-2">
            <div className="p-2 bg-purple-600 rounded-lg">
              <Shield size={18} className="text-white" />
            </div>
          </div>
        </div>
      </header>

      <main className="max-w-3xl mx-auto px-6 py-10">
        {/* Steps indicator */}
        <div className="flex items-center gap-3 mb-8">
          {[
            { n: 1, label: 'Identité' },
            { n: 2, label: 'Abonnement' },
          ].map(({ n, label }) => (
            <div key={n} className="flex items-center gap-2">
              <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold ${
                step > n ? 'bg-emerald-600 text-white' :
                step === n ? 'bg-purple-600 text-white' :
                'bg-slate-800 text-slate-500'
              }`}>
                {step > n ? <Check size={14} /> : n}
              </div>
              <span className={`text-sm ${step === n ? 'text-white font-medium' : 'text-slate-500'}`}>{label}</span>
              {n < 2 && <div className="w-12 h-px bg-slate-700 mx-1" />}
            </div>
          ))}
        </div>

        {error && (
          <div className="mb-4 px-4 py-3 bg-red-950 border border-red-800 text-red-300 rounded-lg text-sm">
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit}>
          {/* Step 1: Identity */}
          {step === 1 && (
            <div className="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-6">
              <div>
                <h2 className="text-white text-lg font-semibold mb-1">Identité de l'entreprise</h2>
                <p className="text-slate-500 text-sm">Ces informations identifient le client dans le système.</p>
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">
                  Nom de l'entreprise *
                </label>
                <input
                  type="text"
                  value={form.nom}
                  onChange={(e) => {
                    const nom = e.target.value;
                    setForm({ ...form, nom, slug: generateSlug(nom) });
                  }}
                  className="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-purple-500"
                  placeholder="Ex: Brasserie du Nord"
                  required
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-slate-300 mb-2">
                  Code entreprise (slug) *
                </label>
                <div className="relative">
                  <span className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500 text-sm font-mono">app/</span>
                  <input
                    type="text"
                    value={form.slug}
                    onChange={(e) => setForm({ ...form, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '') })}
                    className="w-full pl-14 pr-4 py-3 bg-slate-800 border border-slate-700 rounded-lg text-white placeholder-slate-500 font-mono focus:outline-none focus:ring-2 focus:ring-purple-500"
                    placeholder="brasserie-nord"
                    pattern="[a-z0-9-]+"
                    required
                  />
                </div>
                <p className="text-xs text-slate-500 mt-1.5">
                  Utilisé pour l'authentification et le header <code className="text-purple-400">X-Tenant-Slug</code>
                </p>
              </div>

              <div className="pt-2">
                <button
                  type="button"
                  onClick={() => { if (form.nom && form.slug) setStep(2); }}
                  disabled={!form.nom || !form.slug}
                  className="w-full py-3 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-40 disabled:cursor-not-allowed font-medium transition-colors"
                >
                  Suivant →
                </button>
              </div>
            </div>
          )}

          {/* Step 2: Subscription */}
          {step === 2 && (
            <div className="space-y-5">
              {/* Plan selection */}
              <div className="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <h2 className="text-white text-lg font-semibold mb-1">Choisir le plan</h2>
                <p className="text-slate-500 text-sm mb-5">Sélectionnez le plan adapté à l'entreprise cliente.</p>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  {PLANS.map(({ key, label, icon: Icon, color, border, features }) => (
                    <button
                      key={key}
                      type="button"
                      onClick={() => setForm({ ...form, plan: key })}
                      className={`relative p-5 rounded-xl border-2 text-left transition-all ${
                        form.plan === key
                          ? `${border} bg-slate-800`
                          : 'border-slate-700 hover:border-slate-600'
                      }`}
                    >
                      {form.plan === key && (
                        <div className="absolute top-3 right-3 w-5 h-5 bg-purple-600 rounded-full flex items-center justify-center">
                          <Check size={12} className="text-white" />
                        </div>
                      )}
                      <Icon size={22} className={`${color} mb-3`} />
                      <p className={`font-bold text-sm mb-3 ${form.plan === key ? 'text-white' : 'text-slate-300'}`}>{label}</p>
                      <ul className="space-y-1.5">
                        {features.map(f => (
                          <li key={f} className="flex items-start gap-1.5 text-xs text-slate-500">
                            <Check size={11} className="text-slate-600 shrink-0 mt-0.5" />
                            {f}
                          </li>
                        ))}
                      </ul>
                    </button>
                  ))}
                </div>
              </div>

              {/* Duration */}
              <div className="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <h3 className="text-white font-semibold mb-1">Durée de l'abonnement</h3>
                <p className="text-slate-500 text-sm mb-4">Choisissez la durée initiale.</p>
                <div className="grid grid-cols-3 gap-3">
                  {([
                    { duree: 1, label: '1 an', desc: 'Mensuel' },
                    { duree: 3, label: '3 ans', desc: 'Économique' },
                    { duree: 5, label: '5 ans', desc: 'Meilleur prix' },
                  ] as { duree: 1 | 3 | 5; label: string; desc: string }[]).map(({ duree, label, desc }) => (
                    <button
                      key={duree}
                      type="button"
                      onClick={() => setForm({ ...form, duree_abonnement: duree })}
                      className={`py-4 px-3 rounded-xl border-2 text-center transition-all ${
                        form.duree_abonnement === duree
                          ? 'border-purple-500 bg-purple-900/50'
                          : 'border-slate-700 hover:border-slate-600'
                      }`}
                    >
                      <p className={`text-base font-bold ${form.duree_abonnement === duree ? 'text-purple-300' : 'text-slate-300'}`}>{label}</p>
                      <p className="text-xs text-slate-500 mt-0.5">{desc}</p>
                    </button>
                  ))}
                </div>

                <div
                  onClick={() => setForm({ ...form, renouvelable: !form.renouvelable })}
                  className="mt-4 flex items-center justify-between p-4 bg-slate-800 rounded-lg cursor-pointer hover:bg-slate-700 transition-colors"
                >
                  <div>
                    <p className="text-sm text-white font-medium">Renouvellement automatique</p>
                    <p className="text-xs text-slate-500">Le client peut renouveler son abonnement à l'expiration</p>
                  </div>
                  <div className={`w-10 h-6 rounded-full transition-colors relative ${form.renouvelable ? 'bg-purple-600' : 'bg-slate-600'}`}>
                    <div className={`absolute top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform ${form.renouvelable ? 'translate-x-4' : 'translate-x-0.5'}`} />
                  </div>
                </div>
              </div>

              {/* Summary + Submit */}
              <div className="bg-slate-800 border border-slate-700 rounded-xl p-5">
                <h3 className="text-slate-300 text-sm font-medium mb-3">Récapitulatif</h3>
                <div className="space-y-2 text-sm mb-5">
                  <div className="flex justify-between">
                    <span className="text-slate-500">Entreprise</span>
                    <span className="text-white font-medium">{form.nom}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-slate-500">Slug</span>
                    <code className="text-purple-400 text-xs">{form.slug}</code>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-slate-500">Plan</span>
                    <span className="text-white capitalize">{form.plan}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-slate-500">Durée</span>
                    <span className="text-white">{form.duree_abonnement} an{form.duree_abonnement > 1 ? 's' : ''}</span>
                  </div>
                </div>
                <div className="flex gap-3">
                  <button
                    type="button"
                    onClick={() => setStep(1)}
                    className="flex-1 py-2.5 border border-slate-600 text-slate-300 rounded-lg hover:bg-slate-700 text-sm transition-colors"
                  >
                    ← Retour
                  </button>
                  <button
                    type="submit"
                    disabled={loading}
                    className="flex-1 py-2.5 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 text-sm font-medium transition-colors"
                  >
                    {loading ? 'Création en cours...' : 'Créer l\'entreprise'}
                  </button>
                </div>
              </div>
            </div>
          )}
        </form>
      </main>
    </div>
  );
}
