import { Bell, Home, LayoutDashboard, LifeBuoy, Shield, User } from 'lucide-react';
import { useAuth } from '@/contexts/AuthContext';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { getNavCategories, type NavCategory, type NavItem } from '@/navigation';

/**
 * Écrans du menu ouverts au compte, comme le menu latéral : modules souscrits par l'entreprise, puis droits du rôle
 * (tous les écrans des modules souscrits tant que les droits se chargent, ou s'ils n'ont pas pu être lus).
 */
export function useMenu(): NavCategory[] {
  const { t } = useLanguage();
  const { tenant } = useAuth();
  const { canView, peut, loading, permissions } = usePermissions();
  const modules = tenant?.modules;
  const souscrite = (categorie: NavCategory) => !Array.isArray(modules) || modules.includes(categorie.id);
  const permis = (item: NavItem) => canView(item.module) && (!item.action || peut(item.module, item.action));

  const categories = getNavCategories(t).filter(souscrite);
  if (loading || !permissions) return categories;

  return categories
    .map(categorie => ({ ...categorie, items: categorie.items.filter(permis) }))
    .filter(categorie => categorie.items.length > 0);
}

/** Écrans hors menu ouverts à tous : tableau de bord, notifications, support (selon ses droits), sécurité, profil. */
export function useEcransGeneraux(): NavCategory {
  const { t } = useLanguage();
  const { peut, canView } = usePermissions();
  const titres = t.help.titles;
  const support: NavItem[] = peut('support', 'creer') || canView('support')
    ? [{ path: '/support', label: titres['/support'], icon: LifeBuoy, module: 'support' }]
    : [];

  return {
    id: 'general',
    label: t.help.general,
    icon: Home,
    items: [
      { path: '/', label: titres['/'], icon: LayoutDashboard, module: 'dashboard' },
      { path: '/notifications', label: titres['/notifications'], icon: Bell, module: 'dashboard' },
      ...support,
      { path: '/securite', label: titres['/securite'], icon: Shield, module: 'dashboard' },
      { path: '/profil', label: titres['/profil'], icon: User, module: 'dashboard' },
    ],
  };
}
