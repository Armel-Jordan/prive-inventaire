import { createContext, useContext, useState, type ReactNode } from 'react';
import type { User, TenantInfo, AuthState } from '@/types/auth';
import { apiFetch, ApiError } from '@/services/http';

interface LoginResponse {
  success: boolean;
  token?: string;
  user: User;
  tenant: TenantInfo;
}

/**
 * Résultat d'une étape de connexion. `defi` : le mot de passe est bon mais la double authentification
 * demande un code ; `defiExpire` : il faut ressaisir le mot de passe.
 */
export interface ResultatConnexion {
  success: boolean;
  needsProfile?: boolean;
  defi?: string;
  defiExpire?: boolean;
  message?: string;
}

interface AuthContextType extends AuthState {
  login: (email: string, password: string, tenantSlug: string) => Promise<ResultatConnexion>;
  validerDeuxFacteurs: (defi: string, code: string) => Promise<ResultatConnexion>;
  logout: () => void;
  updateUser: (user: User) => void;
  isLoading: boolean;
}

const AuthContext = createContext<AuthContextType | null>(null);

const STORAGE_KEY = 'prise_auth';

function getInitialState() {
  const stored = localStorage.getItem(STORAGE_KEY);
  if (stored) {
    try {
      return JSON.parse(stored);
    } catch {
      localStorage.removeItem(STORAGE_KEY);
    }
  }
  return { user: null, tenant: null, token: null };
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const initial = getInitialState();
  const [user, setUser] = useState<User | null>(initial.user);
  const [tenant, setTenant] = useState<TenantInfo | null>(initial.tenant);
  const [token, setToken] = useState<string | null>(initial.token);
  const [isLoading] = useState(false);

  const ouvrirSession = (data: LoginResponse): ResultatConnexion => {
    if (data.success && data.token) {
      setUser(data.user);
      setTenant(data.tenant);
      setToken(data.token);

      localStorage.setItem(STORAGE_KEY, JSON.stringify({
        user: data.user,
        tenant: data.tenant,
        token: data.token,
      }));

      const needsProfile = !data.user.profil_complete;
      return { success: true, needsProfile };
    }

    return { success: false };
  };

  const login = async (email: string, password: string, tenantSlug: string): Promise<ResultatConnexion> => {
    try {
      const data = await apiFetch<LoginResponse>('/auth/login', {
        method: 'POST',
        body: JSON.stringify({
          email,
          password,
          tenant_slug: tenantSlug,
        }),
      });

      return ouvrirSession(data);
    } catch (error) {
      // Double authentification : l'API répond 403 avec un défi ; la connexion continue avec un code.
      const donnees = error instanceof ApiError ? (error.data as { deux_facteurs?: boolean; defi?: string } | undefined) : undefined;
      if (donnees?.deux_facteurs && donnees.defi) {
        return { success: false, defi: donnees.defi };
      }
      console.error('Erreur login:', error);
      return { success: false };
    }
  };

  const validerDeuxFacteurs = async (defi: string, code: string): Promise<ResultatConnexion> => {
    try {
      const data = await apiFetch<LoginResponse>('/auth/login/deux-facteurs', {
        method: 'POST',
        body: JSON.stringify({ defi, code }),
      });

      return ouvrirSession(data);
    } catch (error) {
      const donnees = error instanceof ApiError ? (error.data as { defi_expire?: boolean } | undefined) : undefined;
      return { success: false, defiExpire: Boolean(donnees?.defi_expire), message: error instanceof ApiError ? error.message : undefined };
    }
  };

  const logout = () => {
    setUser(null);
    setTenant(null);
    setToken(null);
    localStorage.removeItem(STORAGE_KEY);
  };

  const updateUser = (updatedUser: User) => {
    setUser(updatedUser);
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored) {
      try {
        const data = JSON.parse(stored);
        data.user = updatedUser;
        localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
      } catch {
        // ignore
      }
    }
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        tenant,
        token,
        isAuthenticated: !!token,
        login,
        validerDeuxFacteurs,
        logout,
        updateUser,
        isLoading,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return context;
}
