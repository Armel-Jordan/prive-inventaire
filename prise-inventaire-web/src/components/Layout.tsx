import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { Building2, ChevronDown, ChevronRight, CircleHelp, LayoutDashboard, LifeBuoy, LogOut, Menu, Shield, ShieldCheck, User, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useAuth } from '@/contexts/AuthContext';
import { usePermissions } from '@/contexts/PermissionsContext';
import NotificationBell from './NotificationBell';
import LanguageSelector from './LanguageSelector';
import ThemeToggle from './ThemeToggle';
import RechercheGlobale from './RechercheGlobale';
import { useLanguage } from '@/i18n/useLanguage';
import { NOM_APPLICATION } from '@/config/application';
import { ecranDuMenu, getNavCategories, type NavItem } from '@/navigation';
import { AideContext } from '@/aide/contexte';
import PanneauAide from './aide/PanneauAide';

export default function Layout() {
  const location = useLocation();
  const navigate = useNavigate();
  const { user, tenant, logout } = useAuth();
  const { t } = useLanguage();
  const { canView, peut, loading: permissionsLoading, permissions } = usePermissions();
  // Entrée du menu permise : droit de consulter le module, et l'action nommée exigée s'il y en a une.
  const permise = (item: NavItem) => canView(item.module) && (!item.action || peut(item.module, item.action));
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [expandedCategories, setExpandedCategories] = useState<string[]>(['inventaire']);
  const allCategories = getNavCategories(t);

  // Aide (phase 6.6) : le panneau suit l'écran affiché ; ouvert depuis la carte des écrans sur un autre écran, il le
  // montre jusqu'au changement de page. Touche F1 : ouvrir ou fermer l'aide de l'écran.
  const [aideOuverte, setAideOuverte] = useState(false);
  const [aideCible, setAideCible] = useState<{ chemin: string; page: string } | null>(null);
  const cheminAide = !aideOuverte ? null : aideCible && aideCible.page === location.key ? aideCible.chemin : location.pathname;
  const ouvrirAide = useCallback((cible?: string) => {
    setAideCible(cible ? { chemin: cible, page: location.key } : null);
    setAideOuverte(true);
  }, [location.key]);
  const fermerAide = useCallback(() => setAideOuverte(false), []);
  const contexteAide = useMemo(() => ({ chemin: cheminAide, ouvrir: ouvrirAide, fermer: fermerAide }), [cheminAide, ouvrirAide, fermerAide]);
  useEffect(() => {
    const touche = (evenement: KeyboardEvent) => {
      if (evenement.key !== 'F1') return;
      evenement.preventDefault();
      setAideCible(null);
      setAideOuverte(ouverte => !ouverte);
    };
    window.addEventListener('keydown', touche);
    return () => window.removeEventListener('keydown', touche);
  }, []);

  const toggleCategory = (categoryId: string) => {
    setExpandedCategories(prev =>
      prev.includes(categoryId)
        ? prev.filter(id => id !== categoryId)
        : [...prev, categoryId]
    );
  };

  // Modules fonctionnels souscrits par l'entreprise (abonnement).
  // Si absent (ancienne session), on n'applique pas le gating module (rétro-compat).
  const tenantModules = tenant?.modules;
  const moduleAllowed = (categoryId: string) =>
    !Array.isArray(tenantModules) ? true : tenantModules.includes(categoryId);

  // Filtrage à 2 niveaux : (1) module d'entreprise (abonnement), (2) permissions par rôle.
  // Pendant le chargement des permissions, ne filtre que par module.
  const categories = allCategories
    .filter(cat => moduleAllowed(cat.id))
    .map(cat => ({
      ...cat,
      items: permissionsLoading
        ? cat.items
        : cat.items.filter(item => {
            try {
              return permise(item);
            } catch {
              return true; // Si erreur, afficher par défaut
            }
          })
    }))
    .filter(cat => cat.items.length > 0);

  // Droits introuvables (échec de /permissions/me) : on garde au moins le gating module. Des droits chargés
  // qui ne donnent accès à aucune catégorie ne réaffichent plus tout le menu.
  const displayCategories = permissionsLoading || permissions
    ? categories
    : allCategories.filter(cat => moduleAllowed(cat.id));

  // Page du menu ouverte par son adresse sans le droit de la consulter : « Accès refusé » au lieu de la page
  // (l'API refuse de toute façon les écritures que le rôle ne permet pas).
  const pageCourante = ecranDuMenu(allCategories, location.pathname);
  const accesRefuse = !permissionsLoading && permissions !== null && pageCourante !== undefined && !permise(pageCourante);
  // Page d'un module que l'entreprise n'a pas souscrit (Achats, Ventes, Finance) : message au lieu d'une page
  // vide, voire d'une erreur (les appels de l'API sont refusés).
  const categorieCourante = allCategories.find(cat => cat.items.some(item => item === pageCourante));
  const moduleInactif = categorieCourante !== undefined && !moduleAllowed(categorieCourante.id);

  const handleLogout = () => {
    logout();
    navigate('/login');
  };

  return (
    <AideContext.Provider value={contexteAide}>
    <div className="min-h-screen bg-gray-50 dark:bg-gray-900">
      {/* Mobile menu button */}
      <div className="lg:hidden fixed top-4 left-4 z-50">
        <button
          onClick={() => setSidebarOpen(!sidebarOpen)}
          className="p-2 bg-white rounded-lg shadow-md"
        >
          {sidebarOpen ? <X size={24} /> : <Menu size={24} />}
        </button>
      </div>

      {/* Sidebar */}
      <aside className={`
        fixed inset-y-0 left-0 z-40 w-64 bg-white dark:bg-gray-800 shadow-lg transform transition-transform duration-200 flex flex-col
        lg:translate-x-0 ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}
      `}>
        <div className="p-6 border-b dark:border-gray-700">
          <div className="flex items-center gap-3 mb-2">
            <Building2 className="text-blue-600" size={24} />
            <h1 className="text-xl font-bold text-gray-800 dark:text-white">{NOM_APPLICATION}</h1>
          </div>
          {tenant && (
            <p className="text-sm text-blue-600 font-medium">{tenant.nom}</p>
          )}
        </div>
        
        <nav className="p-4 space-y-1 flex-1 overflow-y-auto">
          {/* Dashboard - always visible */}
          <Link
            to="/"
            onClick={() => setSidebarOpen(false)}
            className={`
              flex items-center gap-3 px-4 py-2.5 rounded-lg transition-colors
              ${location.pathname === '/'
                ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 font-medium'
                : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'
              }
            `}
          >
            <LayoutDashboard size={20} />
            {t.nav.dashboard}
          </Link>

          {/* Categories */}
          {displayCategories.map((category) => {
            const CategoryIcon = category.icon;
            const isExpanded = expandedCategories.includes(category.id);
            const hasActiveItem = category.items.some(item => location.pathname === item.path);

            return (
              <div key={category.id}>
                <button
                  onClick={() => toggleCategory(category.id)}
                  className={`
                    w-full flex items-center justify-between px-4 py-2.5 rounded-lg transition-colors
                    ${hasActiveItem
                      ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400'
                      : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'
                    }
                  `}
                >
                  <div className="flex items-center gap-3">
                    <CategoryIcon size={20} />
                    <span className="font-medium">{category.label}</span>
                  </div>
                  {isExpanded ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                </button>

                {isExpanded && (
                  <div className="ml-4 mt-1 space-y-0.5 border-l-2 border-gray-200 dark:border-gray-700">
                    {category.items.map((item) => {
                      const Icon = item.icon;
                      const isActive = location.pathname === item.path;
                      return (
                        <Link
                          key={item.path}
                          to={item.path}
                          onClick={() => setSidebarOpen(false)}
                          className={`
                            flex items-center gap-3 px-4 py-2 ml-2 rounded-lg transition-colors text-sm
                            ${isActive
                              ? 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 font-medium'
                              : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700'
                            }
                          `}
                        >
                          <Icon size={16} />
                          {item.label}
                        </Link>
                      );
                    })}
                  </div>
                )}
              </div>
            );
          })}
        </nav>

        {/* User info & logout */}
        <div className="p-4 border-t dark:border-gray-700">
          {user && (
            <div className="mb-3">
              <p className="text-sm font-medium text-gray-800 dark:text-white">{user.nom}</p>
              <p className="text-xs text-gray-500 dark:text-gray-400">{user.email}</p>
            </div>
          )}
          <button
            onClick={handleLogout}
            className="w-full flex items-center gap-2 px-4 py-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-lg transition-colors"
          >
            <LogOut size={18} />
            {t.nav.logout}
          </button>
        </div>
      </aside>

      {/* Overlay for mobile */}
      {sidebarOpen && (
        <div 
          className="fixed inset-0 bg-black/50 z-30 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      {/* Main content */}
      <main className="lg:ml-64 min-h-screen bg-gray-100 dark:bg-gray-900">
        {/* Top bar with notifications */}
        {/* Sur téléphone, la place du bouton du menu (fixe, en haut à gauche) est laissée libre. */}
        <div className="sticky top-0 z-20 bg-white dark:bg-gray-800 border-b dark:border-gray-700 pl-16 pr-3 sm:pr-6 lg:px-6 py-3 flex items-center justify-end gap-1 sm:gap-4">
          <RechercheGlobale />
          <ThemeToggle />
          <LanguageSelector />
          <NotificationBell />
          <button
            type="button"
            onClick={() => (aideOuverte ? fermerAide() : ouvrirAide())}
            aria-expanded={aideOuverte}
            className="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition-colors"
            title={t.help.button}
            aria-label={t.help.button}
          >
            <CircleHelp size={20} className="text-gray-600 dark:text-gray-300" />
          </button>
          {(peut('support', 'creer') || canView('support')) && (
            // Support et suggestions : la nouvelle demande retient l'écran d'où elle part.
            <Link
              to={`/support?depuis=${encodeURIComponent(location.pathname)}`}
              className="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition-colors"
              title={t.support.link}
              aria-label={t.support.link}
            >
              <LifeBuoy size={20} className="text-gray-600 dark:text-gray-300" />
            </Link>
          )}
          <Link
            to="/securite"
            className="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition-colors"
            title={t.security.link}
            aria-label={t.security.link}
          >
            <Shield size={20} className="text-gray-600 dark:text-gray-300" />
          </Link>
          <Link
            to="/profil"
            className="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition-colors"
            title="Mon profil"
          >
            <User size={20} className="text-gray-600 dark:text-gray-300" />
          </Link>
        </div>
        <div className="p-6 lg:p-8">
          {moduleInactif ? (
            <div className="flex flex-col items-center justify-center text-center min-h-[60vh]">
              <ShieldCheck size={48} className="text-gray-300 dark:text-gray-600 mb-4" aria-hidden />
              <h1 className="text-2xl font-bold text-gray-800 dark:text-white mb-2">{t.moduleDisabled.title}</h1>
              <p className="text-gray-500 dark:text-gray-400 mb-6 max-w-lg">
                {t.moduleDisabled.message.replace('{module}', categorieCourante?.label ?? '')}
              </p>
              <Link to="/" className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">{t.moduleDisabled.back}</Link>
            </div>
          ) : accesRefuse ? (
            <div className="flex flex-col items-center justify-center text-center min-h-[60vh]">
              <ShieldCheck size={48} className="text-gray-300 dark:text-gray-600 mb-4" aria-hidden />
              <h1 className="text-2xl font-bold text-gray-800 dark:text-white mb-2">{t.accessDenied.title}</h1>
              <p className="text-gray-500 dark:text-gray-400 mb-6 max-w-lg">{t.accessDenied.message}</p>
              <Link to="/" className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">{t.accessDenied.back}</Link>
            </div>
          ) : (
            <Outlet />
          )}
        </div>
      </main>
    </div>
    {cheminAide && <PanneauAide chemin={cheminAide} onFermer={fermerAide} />}
    </AideContext.Provider>
  );
}
