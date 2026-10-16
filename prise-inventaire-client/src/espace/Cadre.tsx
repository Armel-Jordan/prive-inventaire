import { Link, NavLink, Outlet } from 'react-router-dom';
import { ClipboardList, FileText, LogOut, Mail, Phone, ShoppingCart, Store, UserRound } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import LanguageSelector from '@/components/LanguageSelector';
import { usePortail } from './contexte';
import { Message } from './composants';

/** Cadre des écrans du client connecté : entreprise, navigation, panier, coordonnées. */
export default function Cadre() {
  const { t } = useLanguage();
  const tp = t.portal;
  const { infos, moi, panier, erreurMoi, rechargerMoi, deconnecter } = usePortail();
  const onglets = [
    { vers: '/', libelle: tp.nav.catalogue, Icone: Store, fin: true },
    { vers: '/commandes', libelle: tp.nav.orders, Icone: ClipboardList, fin: false },
    { vers: '/factures', libelle: tp.nav.invoices, Icone: FileText, fin: false },
    { vers: '/profil', libelle: tp.nav.profile, Icone: UserRound, fin: false },
  ];
  const articles = panier.length;
  const classeOnglet = ({ isActive }: { isActive: boolean }) =>
    `flex items-center gap-1.5 px-3 py-2 text-sm rounded-lg whitespace-nowrap ${isActive ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100'}`;

  return (
    <div className="min-h-screen bg-gray-50 flex flex-col text-gray-900">
      <header className="bg-white border-b border-gray-200 sticky top-0 z-30">
        <div className="max-w-6xl mx-auto px-4 h-14 flex items-center gap-3">
          <Link to="/" className="flex items-center gap-2 min-w-0">
            {infos.logo_url
              ? <img src={infos.logo_url} alt="" className="h-8 w-8 object-contain shrink-0" />
              : <Store className="text-blue-600 shrink-0" size={24} />}
            <span className="font-semibold truncate">{infos.entreprise}</span>
          </Link>
          <nav className="hidden md:flex items-center gap-1 ml-4" aria-label={tp.nav.menu}>
            {onglets.map(({ vers, libelle, Icone, fin }) => (
              <NavLink key={vers} to={vers} end={fin} className={classeOnglet}><Icone size={16} /> {libelle}</NavLink>
            ))}
          </nav>
          <div className="ml-auto flex items-center gap-1">
            {moi && <span className="hidden lg:block text-sm text-gray-500 mr-2 truncate max-w-56">{moi.client.raison_sociale}</span>}
            <LanguageSelector />
            <NavLink to="/panier" className={({ isActive }) => `relative p-2 rounded-lg ${isActive ? 'bg-blue-50 text-blue-700' : 'text-gray-600 hover:bg-gray-100'}`}
              aria-label={`${tp.nav.cart} (${articles})`} title={tp.nav.cart}>
              <ShoppingCart size={20} />
              {articles > 0 && (
                <span className="absolute -top-0.5 -right-0.5 min-w-[1.1rem] h-[1.1rem] px-1 rounded-full bg-blue-600 text-white text-[10px] leading-[1.1rem] text-center font-semibold">
                  {articles}
                </span>
              )}
            </NavLink>
            <button type="button" onClick={() => { void deconnecter(); }} className="p-2 rounded-lg text-gray-600 hover:bg-gray-100" title={tp.logout} aria-label={tp.logout}>
              <LogOut size={20} />
            </button>
          </div>
        </div>
        {/* Petit écran : les quatre écrans tiennent sur une ligne, icône au-dessus du libellé. */}
        <nav className="md:hidden grid grid-cols-4 border-t border-gray-100" aria-label={tp.nav.menu}>
          {onglets.map(({ vers, libelle, Icone, fin }) => (
            <NavLink key={vers} to={vers} end={fin}
              className={({ isActive }) => `flex flex-col items-center gap-0.5 px-1 py-2 text-[11px] leading-tight text-center ${isActive ? 'text-blue-700 font-medium bg-blue-50' : 'text-gray-600'}`}>
              <Icone size={18} /> <span className="max-w-full truncate">{libelle}</span>
            </NavLink>
          ))}
        </nav>
      </header>

      <main className="flex-1 w-full max-w-6xl mx-auto px-4 py-6">
        {erreurMoi && (
          <div className="mb-4">
            <Message type="erreur" action={<button type="button" onClick={rechargerMoi} className="text-sm font-medium underline">{tp.retry}</button>}>
              {tp.accountLoadError}
            </Message>
          </div>
        )}
        <Outlet />
      </main>

      <footer className="bg-white border-t border-gray-200">
        <div className="max-w-6xl mx-auto px-4 py-4 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-gray-500">
          <span className="font-medium text-gray-700">{infos.entreprise}</span>
          {infos.telephone && <a href={`tel:${infos.telephone.replace(/\s/g, '')}`} className="flex items-center gap-1 hover:text-gray-800"><Phone size={12} /> {infos.telephone}</a>}
          {infos.email && <a href={`mailto:${infos.email}`} className="flex items-center gap-1 hover:text-gray-800"><Mail size={12} /> {infos.email}</a>}
          {moi && <span className="sm:ml-auto">{moi.compte.nom} · {moi.client.raison_sociale}</span>}
        </div>
      </footer>
    </div>
  );
}
