export interface User {
  id: number;
  nom: string;
  email: string;
  role: 'super_admin' | 'admin' | 'manager' | 'user';
}

export interface TenantInfo {
  id: number;
  nom: string;
  slug: string;
  plan: string;
}

export interface AuthState {
  user: User | null;
  tenant: TenantInfo | null;
  token: string | null;
  isAuthenticated: boolean;
}

export interface LoginRequest {
  email: string;
  password: string;
  tenant_slug: string;
}

export interface LoginResponse {
  success: boolean;
  message: string;
  user?: User;
  tenant?: TenantInfo;
  token?: string;
}
