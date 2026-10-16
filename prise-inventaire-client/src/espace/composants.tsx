import { useState, type ReactNode } from 'react';
import { AlertCircle, CheckCircle2, ChevronLeft, ChevronRight, Info, Loader2, Mail, Minus, Phone, Plus, Store } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import LanguageSelector from '@/components/LanguageSelector';
import { usePortail } from './contexte';
import type { StatutCommande, StatutFacture } from './api';
import { remplir } from './format';

/** Quantité entière (1 à 1 000 000) : saisie libre, boutons − et +. */
export function ChampQuantite({ valeur, onChange, desactive = false }: { valeur: number; onChange: (quantite: number) => void; desactive?: boolean }) {
  const { t } = useLanguage();
  const [texte, setTexte] = useState(String(valeur));
  const appliquer = (quantite: number) => {
    const bornee = Math.max(1, Math.min(quantite, 1000000));
    setTexte(String(bornee));
    onChange(bornee);
  };
  const bouton = 'p-2 text-gray-500 hover:text-gray-900 hover:bg-gray-100 disabled:opacity-40 disabled:hover:bg-transparent';

  return (
    <div className="inline-flex items-center border border-gray-300 rounded-lg bg-white">
      <button type="button" className={`${bouton} rounded-l-lg`} disabled={desactive || valeur <= 1} onClick={() => appliquer(valeur - 1)} aria-label={t.portal.decrease}>
        <Minus size={14} />
      </button>
      <input
        inputMode="numeric"
        aria-label={t.portal.quantity}
        disabled={desactive}
        value={texte}
        className="w-12 py-1.5 text-center text-sm border-x border-gray-200 focus:outline-none disabled:bg-gray-50"
        onChange={e => {
          const chiffres = e.target.value.replace(/\D/g, '').slice(0, 7);
          setTexte(chiffres);
          const quantite = parseInt(chiffres, 10);
          if (quantite >= 1) onChange(Math.min(quantite, 1000000));
        }}
        onBlur={() => setTexte(String(valeur))}
      />
      <button type="button" className={`${bouton} rounded-r-lg`} disabled={desactive || valeur >= 1000000} onClick={() => appliquer(valeur + 1)} aria-label={t.portal.increase}>
        <Plus size={14} />
      </button>
    </div>
  );
}

const COULEURS_COMMANDE: Record<StatutCommande, string> = {
  en_attente: 'bg-amber-100 text-amber-800',
  acceptee: 'bg-green-100 text-green-800',
  refusee: 'bg-red-100 text-red-800',
  facturee: 'bg-blue-100 text-blue-800',
  annulee: 'bg-gray-100 text-gray-600',
};

export function BadgeCommande({ statut }: { statut: StatutCommande }) {
  const { t } = useLanguage();
  return <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap ${COULEURS_COMMANDE[statut] ?? 'bg-gray-100 text-gray-600'}`}>{t.portal.status[statut] ?? statut}</span>;
}

export function BadgeFacture({ statut, enRetard }: { statut: StatutFacture; enRetard: boolean }) {
  const { t } = useLanguage();
  const couleur = statut === 'payee' ? 'bg-green-100 text-green-800' : enRetard ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800';
  return (
    <span className={`inline-block px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap ${couleur}`}>
      {statut !== 'payee' && enRetard ? t.portal.overdue : t.portal.invoiceStatus[statut] ?? statut}
    </span>
  );
}

export function Pagination({ page, pages, onChange }: { page: number; pages: number; onChange: (page: number) => void }) {
  const { t } = useLanguage();
  if (pages <= 1) return null;
  const bouton = 'inline-flex items-center gap-1 px-3 py-1.5 text-sm border border-gray-300 rounded-lg bg-white text-gray-700 hover:bg-gray-50 disabled:opacity-40 disabled:hover:bg-white';
  return (
    <nav className="flex items-center justify-center gap-3 mt-6">
      <button type="button" className={bouton} disabled={page <= 1} onClick={() => onChange(page - 1)}><ChevronLeft size={16} /> {t.portal.previous}</button>
      <span className="text-sm text-gray-500">{remplir(t.portal.pageOf, { page, pages })}</span>
      <button type="button" className={bouton} disabled={page >= pages} onClick={() => onChange(page + 1)}>{t.portal.next} <ChevronRight size={16} /></button>
    </nav>
  );
}

const STYLES_MESSAGE = {
  erreur: { classe: 'bg-red-50 border-red-200 text-red-700', Icone: AlertCircle },
  succes: { classe: 'bg-green-50 border-green-200 text-green-800', Icone: CheckCircle2 },
  info: { classe: 'bg-blue-50 border-blue-200 text-blue-800', Icone: Info },
};

export function Message({ type, children, action }: { type: keyof typeof STYLES_MESSAGE; children: ReactNode; action?: ReactNode }) {
  const { classe, Icone } = STYLES_MESSAGE[type];
  return (
    <div role={type === 'erreur' ? 'alert' : 'status'} className={`p-3 border rounded-lg flex items-start gap-2 text-sm ${classe}`}>
      <Icone size={18} className="shrink-0 mt-0.5" />
      <div className="flex-1 min-w-0">{children}</div>
      {action}
    </div>
  );
}

export function Chargement() {
  const { t } = useLanguage();
  return (
    <div className="flex items-center justify-center gap-2 py-16 text-gray-500" role="status">
      <Loader2 size={20} className="animate-spin" /> {t.portal.loading}
    </div>
  );
}

/** Cadre des écrans publics du portail (connexion, choix du mot de passe). */
export function CadrePublic({ children }: { children: ReactNode }) {
  const { t } = useLanguage();
  const { infos } = usePortail();
  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 flex flex-col text-gray-900">
      <div className="flex justify-end p-3"><LanguageSelector /></div>
      <div className="flex-1 flex items-center justify-center px-4 pb-10">
        <div className="w-full max-w-md">
          <div className="bg-white rounded-2xl shadow-xl p-6 sm:p-8">
            <div className="text-center mb-6">
              {infos.logo_url
                ? <img src={infos.logo_url} alt="" className="h-14 mx-auto mb-3 object-contain" />
                : <div className="inline-flex items-center justify-center w-14 h-14 bg-blue-100 rounded-full mb-3"><Store className="w-7 h-7 text-blue-600" /></div>}
              <h1 className="text-xl font-bold">{infos.entreprise}</h1>
              <p className="text-sm text-gray-500">{t.portal.loginTitle}</p>
            </div>
            {children}
          </div>
          {(infos.telephone || infos.email) && (
            <div className="mt-4 text-center text-xs text-gray-600 space-y-1">
              <p className="font-medium">{t.portal.contact}</p>
              <div className="flex flex-wrap justify-center gap-x-4 gap-y-1">
                {infos.telephone && <a href={`tel:${infos.telephone.replace(/\s/g, '')}`} className="flex items-center gap-1 hover:text-gray-900"><Phone size={12} /> {infos.telephone}</a>}
                {infos.email && <a href={`mailto:${infos.email}`} className="flex items-center gap-1 hover:text-gray-900"><Mail size={12} /> {infos.email}</a>}
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
