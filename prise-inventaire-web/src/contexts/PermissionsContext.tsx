import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { useAuth } from './AuthContext';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const STORAGE_KEY = 'prise_auth';

interface Permission {
  can_view: boolean;
  can_create: boolean;
  can_edit: boolean;
  can_delete: boolean;
}

interface PermissionsData {
  role: string;
  role_id: number | null;
  permissions: Record<string, Permission>;
}

interface PermissionsContextType {
  permissions: PermissionsData | null;
  loading: boolean;
  canView: (module: string) => boolean;
  canCreate: (module: string) => boolean;
  canEdit: (module: string) => boolean;
  canDelete: (module: string) => boolean;
  isAdmin: () => boolean;
  refreshPermissions: () => Promise<void>;
}

const PermissionsContext = createContext<PermissionsContextType | null>(null);

export function PermissionsProvider({ children }: { children: ReactNode }) {
  const { isAuthenticated } = useAuth();
  const [permissions, setPermissions] = useState<PermissionsData | null>(null);
  const [loading, setLoading] = useState(true);

  async function loadPermissions() {
    if (!isAuthenticated) {
      setPermissions(null);
      setLoading(false);
      return;
    }

    try {
      const stored = localStorage.getItem(STORAGE_KEY);
      const headers: Record<string, string> = { 'Accept': 'application/json' };
      if (stored) {
        const data = JSON.parse(stored);
        if (data.token) headers['Authorization'] = `Bearer ${data.token}`;
        if (data.tenant?.slug) headers['X-Tenant-Slug'] = data.tenant.slug;
      }

      const res = await fetch(`${API_BASE_URL}/permissions/me`, { headers });
      if (res.ok) {
        const data = await res.json();
        setPermissions(data);
      }
    } catch (error) {
      console.error('Erreur chargement permissions:', error);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadPermissions();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAuthenticated]);

  function canView(module: string): boolean {
    if (!permissions) return false;
    const perm = permissions.permissions[module];
    return perm?.can_view ?? false;
  }

  function canCreate(module: string): boolean {
    if (!permissions) return false;
    const perm = permissions.permissions[module];
    return perm?.can_create ?? false;
  }

  function canEdit(module: string): boolean {
    if (!permissions) return false;
    const perm = permissions.permissions[module];
    return perm?.can_edit ?? false;
  }

  function canDelete(module: string): boolean {
    if (!permissions) return false;
    const perm = permissions.permissions[module];
    return perm?.can_delete ?? false;
  }

  function isAdmin(): boolean {
    return permissions?.role === 'admin';
  }

  return (
    <PermissionsContext.Provider value={{
      permissions,
      loading,
      canView,
      canCreate,
      canEdit,
      canDelete,
      isAdmin,
      refreshPermissions: loadPermissions,
    }}>
      {children}
    </PermissionsContext.Provider>
  );
}

// eslint-disable-next-line react-refresh/only-export-components
export function usePermissions() {
  const context = useContext(PermissionsContext);
  if (!context) {
    throw new Error('usePermissions must be used within a PermissionsProvider');
  }
  return context;
}
