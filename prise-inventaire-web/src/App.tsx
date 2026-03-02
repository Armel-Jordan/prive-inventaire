import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from '@/contexts/AuthContext';
import Layout from '@/components/Layout';
import Dashboard from '@/pages/Dashboard';
import ScansPage from '@/pages/ScansPage';
import ProduitsPage from '@/pages/ProduitsPage';
import SecteursPage from '@/pages/SecteursPage';
import EmployesPage from '@/pages/EmployesPage';
import RelocalisationPage from '@/pages/RelocalisationPage';
import StatsPage from '@/pages/StatsPage';
import AlertesPage from '@/pages/AlertesPage';
import ComparaisonPage from '@/pages/ComparaisonPage';
import AuditPage from '@/pages/AuditPage';
import PlanificationPage from '@/pages/PlanificationPage';
import ApprobationsPage from '@/pages/ApprobationsPage';
import TracabilitePage from '@/pages/TracabilitePage';
import LoginPage from '@/pages/LoginPage';
import SuperAdminLoginPage from '@/pages/SuperAdminLoginPage';
import SuperAdminDashboard from '@/pages/SuperAdminDashboard';
import SuperAdminTenantPage from '@/pages/SuperAdminTenantPage';
import SuperAdminNewTenantPage from '@/pages/SuperAdminNewTenantPage';

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, isLoading } = useAuth();

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="text-gray-500">Chargement...</div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  return <>{children}</>;
}

function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route
        path="/"
        element={
          <ProtectedRoute>
            <Layout />
          </ProtectedRoute>
        }
      >
        <Route index element={<Dashboard />} />
        <Route path="scans" element={<ScansPage />} />
        <Route path="produits" element={<ProduitsPage />} />
        <Route path="secteurs" element={<SecteursPage />} />
        <Route path="employes" element={<EmployesPage />} />
        <Route path="relocalisation" element={<RelocalisationPage />} />
        <Route path="statistiques" element={<StatsPage />} />
        <Route path="alertes" element={<AlertesPage />} />
        <Route path="comparaison" element={<ComparaisonPage />} />
        <Route path="audit" element={<AuditPage />} />
        <Route path="planification" element={<PlanificationPage />} />
        <Route path="approbations" element={<ApprobationsPage />} />
        <Route path="tracabilite" element={<TracabilitePage />} />
      </Route>
      
      {/* Routes Super Admin */}
      <Route path="/super-admin" element={<SuperAdminLoginPage />} />
      <Route path="/super-admin/dashboard" element={<SuperAdminDashboard />} />
      <Route path="/super-admin/tenants/new" element={<SuperAdminNewTenantPage />} />
      <Route path="/super-admin/tenants/:tenantId" element={<SuperAdminTenantPage />} />
    </Routes>
  );
}

function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <AppRoutes />
      </AuthProvider>
    </BrowserRouter>
  );
}

export default App;
