import { createContext, useContext, useState, type ReactNode } from 'react';
import type { User, TenantInfo, AuthState } from '@/types/auth';

interface AuthContextType extends AuthState {
  login: (email: string, password: string, tenantSlug: string) => Promise<{ success: boolean; needsProfile?: boolean }>;
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

  const login = async (email: string, password: string, tenantSlug: string): Promise<{ success: boolean; needsProfile?: boolean }> => {
    const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

    try {
      const response = await fetch(`${API_URL}/auth/login`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          email,
          password,
          tenant_slug: tenantSlug,
        }),
      });

      const data = await response.json();

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
    } catch (error) {
      console.error('Erreur login:', error);
      return { success: false };
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
