import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from '@/contexts/AuthContext';
import { LanguageProvider } from '@/i18n/LanguageContext';
import { ThemeProvider } from '@/contexts/ThemeContext';
import { PermissionsProvider } from '@/contexts/PermissionsContext';
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
import RapportsPage from '@/pages/RapportsPage';
import InventaireTournantPage from '@/pages/InventaireTournantPage';
import ProfilPage from '@/pages/ProfilPage';
import RolesPage from '@/pages/RolesPage';
import FournisseursPage from '@/pages/FournisseursPage';
import CommandesFournisseurPage from '@/pages/CommandesFournisseurPage';
import ReceptionsPage from '@/pages/ReceptionsPage';
import ClientsPage from '@/pages/ClientsPage';
import CamionsPage from '@/pages/CamionsPage';
import CommandesClientPage from '@/pages/CommandesClientPage';
import FacturesPage from '@/pages/FacturesPage';
import BonsLivraisonPage from '@/pages/BonsLivraisonPage';
import TourneesPage from '@/pages/TourneesPage';
import ComptabilitePage from '@/pages/ComptabilitePage';
import PrevisionsStockPage from '@/pages/PrevisionsStockPage';
import DevisPage from '@/pages/DevisPage';
import GestionPrixPage from '@/pages/GestionPrixPage';
import NotificationsConfigPage from '@/pages/NotificationsConfigPage';
import ConfigurationPage from '@/pages/ConfigurationPage';
import CompleteProfilePage from '@/pages/CompleteProfilePage';
import FichesEmployesPage from '@/pages/FichesEmployesPage';
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
      <Route path="/complete-profile" element={<CompleteProfilePage />} />
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
        <Route path="rapports" element={<RapportsPage />} />
        <Route path="inventaire-tournant" element={<InventaireTournantPage />} />
        <Route path="profil" element={<ProfilPage />} />
        <Route path="roles" element={<RolesPage />} />
        <Route path="fournisseurs" element={<FournisseursPage />} />
        <Route path="commandes-fournisseur" element={<CommandesFournisseurPage />} />
        <Route path="receptions" element={<ReceptionsPage />} />
        <Route path="clients" element={<ClientsPage />} />
        <Route path="camions" element={<CamionsPage />} />
        <Route path="commandes-client" element={<CommandesClientPage />} />
        <Route path="factures" element={<FacturesPage />} />
        <Route path="bons-livraison" element={<BonsLivraisonPage />} />
        <Route path="tournees" element={<TourneesPage />} />
        <Route path="comptabilite" element={<ComptabilitePage />} />
        <Route path="previsions-stock" element={<PrevisionsStockPage />} />
        <Route path="devis" element={<DevisPage />} />
        <Route path="gestion-prix" element={<GestionPrixPage />} />
        <Route path="alertes-config" element={<NotificationsConfigPage />} />
        <Route path="configuration" element={<ConfigurationPage />} />
        <Route path="fiches-employes" element={<FichesEmployesPage />} />
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
      <ThemeProvider>
        <LanguageProvider>
          <AuthProvider>
            <PermissionsProvider>
              <AppRoutes />
            </PermissionsProvider>
          </AuthProvider>
        </LanguageProvider>
      </ThemeProvider>
    </BrowserRouter>
  );
}

export default App;
