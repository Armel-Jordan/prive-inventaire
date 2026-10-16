import { BrowserRouter, HashRouter, Routes, Route, Navigate, useLocation } from 'react-router-dom';
import { AuthProvider, useAuth } from '@/contexts/AuthContext';
import { LanguageProvider } from '@/i18n/LanguageContext';
import { ThemeProvider } from '@/contexts/ThemeContext';
import { PermissionsProvider } from '@/contexts/PermissionsContext';
import ErrorBoundary from '@/components/ErrorBoundary';
import Toaster from '@/components/Toaster';
import Layout from '@/components/Layout';
import Dashboard from '@/pages/Dashboard';
import ScansPage from '@/pages/ScansPage';
import PrisesInventairePage from '@/pages/PrisesInventairePage';
import PriseInventairePage from '@/pages/PriseInventairePage';
import ValorisationInventairePage from '@/pages/ValorisationInventairePage';
import ProduitsPage from '@/pages/ProduitsPage';
import SecteursPage from '@/pages/SecteursPage';
import EmployesPage from '@/pages/EmployesPage';
import RelocalisationPage from '@/pages/RelocalisationPage';
import StatsPage from '@/pages/StatsPage';
import AlertesPage from '@/pages/AlertesPage';
import ConfigurationACompleterPage from '@/pages/ConfigurationACompleterPage';
import ComparaisonPage from '@/pages/ComparaisonPage';
import AuditPage from '@/pages/AuditPage';
import PlanificationPage from '@/pages/PlanificationPage';
import TachesPlanifieesPage from '@/pages/TachesPlanifieesPage';
import ApprobationsPage from '@/pages/ApprobationsPage';
import TracabilitePage from '@/pages/TracabilitePage';
import RapportsPage from '@/pages/RapportsPage';
import InventaireTournantPage from '@/pages/InventaireTournantPage';
import ProfilPage from '@/pages/ProfilPage';
import RolesPage from '@/pages/RolesPage';
import FournisseursPage from '@/pages/FournisseursPage';
import ChampsPersonnalisesPage from '@/pages/ChampsPersonnalisesPage';
import CommandesFournisseurPage from '@/pages/CommandesFournisseurPage';
import ReceptionsPage from '@/pages/ReceptionsPage';
import FicheClientPage from '@/pages/FicheClientPage';
import FicheFournisseurPage from '@/pages/FicheFournisseurPage';
import ClientsPage from '@/pages/ClientsPage';
import BalisesPage from '@/pages/BalisesPage';
import CamionsPage from '@/pages/CamionsPage';
import CommandesClientPage from '@/pages/CommandesClientPage';
import FacturesPage from '@/pages/FacturesPage';
import ComptesClientsPage from '@/pages/ComptesClientsPage';
import BonsLivraisonPage from '@/pages/BonsLivraisonPage';
import TourneesPage from '@/pages/TourneesPage';
import ComptabilitePage from '@/pages/ComptabilitePage';
import PlanComptablePage from '@/pages/PlanComptablePage';
import ExercicesComptablesPage from '@/pages/ExercicesComptablesPage';
import JournalComptablePage from '@/pages/JournalComptablePage';
import GrandLivrePage from '@/pages/GrandLivrePage';
import BalanceComptablePage from '@/pages/BalanceComptablePage';
import SoldesOuverturePage from '@/pages/SoldesOuverturePage';
import EtatsFinanciersPage from '@/pages/EtatsFinanciersPage';
import PrevisionsStockPage from '@/pages/PrevisionsStockPage';
import DevisPage from '@/pages/DevisPage';
import PiecesJointesPage from '@/pages/PiecesJointesPage';
import PortailClientsPage from '@/pages/PortailClientsPage';
import PortailApp from '@/portail/PortailApp';
import GestionPrixPage from '@/pages/GestionPrixPage';
import NotificationsConfigPage from '@/pages/NotificationsConfigPage';
import ConfigurationPage from '@/pages/ConfigurationPage';
import CourrielsPage from '@/pages/CourrielsPage';
import CompleteProfilePage from '@/pages/CompleteProfilePage';
import FichesEmployesPage from '@/pages/FichesEmployesPage';
import ReferentielsPage from '@/pages/ReferentielsPage';
import LoginPage from '@/pages/LoginPage';
import MotDePasseOubliePage from '@/pages/MotDePasseOubliePage';
import ReinitialiserMotDePassePage from '@/pages/ReinitialiserMotDePassePage';
import SecuriteComptePage from '@/pages/SecuriteComptePage';
import SuperAdminLoginPage from '@/pages/SuperAdminLoginPage';
import SuperAdminDashboard from '@/pages/SuperAdminDashboard';
import SuperAdminTenantPage from '@/pages/SuperAdminTenantPage';
import SuperAdminNewTenantPage from '@/pages/SuperAdminNewTenantPage';
import NotificationsPage from '@/pages/NotificationsPage';
import PageIntrouvable from '@/pages/PageIntrouvable';

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, isLoading } = useAuth();
  const location = useLocation();

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="text-gray-500">Chargement...</div>
      </div>
    );
  }

  if (!isAuthenticated) {
    // La page demandée est reprise après la connexion (elle ramenait toujours au tableau de bord).
    return <Navigate to="/login" replace state={{ depuis: `${location.pathname}${location.search}` }} />;
  }

  return <>{children}</>;
}

function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/mot-de-passe-oublie" element={<MotDePasseOubliePage />} />
      <Route path="/reinitialiser-mot-de-passe" element={<ReinitialiserMotDePassePage />} />
      {/* Portail client d'une entreprise : écrans de ses clients, sessions propres au portail. */}
      <Route path="/portail/:slug/*" element={<PortailApp />} />
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
        <Route path="securite" element={<SecuriteComptePage />} />
        <Route path="scans" element={<ScansPage />} />
        <Route path="prises-inventaire" element={<PrisesInventairePage />} />
        <Route path="prises-inventaire/:id" element={<PriseInventairePage />} />
        <Route path="valorisation-inventaire" element={<ValorisationInventairePage />} />
        <Route path="produits" element={<ProduitsPage />} />
        <Route path="secteurs" element={<SecteursPage />} />
        <Route path="employes" element={<EmployesPage />} />
        <Route path="relocalisation" element={<RelocalisationPage />} />
        <Route path="statistiques" element={<StatsPage />} />
        <Route path="alertes" element={<AlertesPage />} />
        <Route path="configuration-a-completer" element={<ConfigurationACompleterPage />} />
        <Route path="comparaison" element={<ComparaisonPage />} />
        <Route path="audit" element={<AuditPage />} />
        <Route path="planification" element={<PlanificationPage />} />
        <Route path="taches-planifiees" element={<TachesPlanifieesPage />} />
        <Route path="approbations" element={<ApprobationsPage />} />
        <Route path="tracabilite" element={<TracabilitePage />} />
        <Route path="rapports" element={<RapportsPage />} />
        <Route path="inventaire-tournant" element={<InventaireTournantPage />} />
        <Route path="profil" element={<ProfilPage />} />
        <Route path="roles" element={<RolesPage />} />
        <Route path="fournisseurs" element={<FournisseursPage />} />
        <Route path="fournisseurs/:id" element={<FicheFournisseurPage />} />
        <Route path="champs-personnalises" element={<ChampsPersonnalisesPage />} />
        <Route path="commandes-fournisseur" element={<CommandesFournisseurPage />} />
        <Route path="receptions" element={<ReceptionsPage />} />
        <Route path="clients/:id" element={<FicheClientPage />} />
        <Route path="clients" element={<ClientsPage />} />
        <Route path="balises" element={<BalisesPage />} />
        <Route path="camions" element={<CamionsPage />} />
        <Route path="commandes-client" element={<CommandesClientPage />} />
        <Route path="factures" element={<FacturesPage />} />
        <Route path="comptes-clients" element={<ComptesClientsPage />} />
        <Route path="bons-livraison" element={<BonsLivraisonPage />} />
        <Route path="portail-clients" element={<PortailClientsPage />} />
        <Route path="tournees" element={<TourneesPage />} />
        <Route path="comptabilite" element={<ComptabilitePage />} />
        <Route path="plan-comptable" element={<PlanComptablePage />} />
        <Route path="exercices-comptables" element={<ExercicesComptablesPage />} />
        <Route path="journal-comptable" element={<JournalComptablePage />} />
        <Route path="grand-livre" element={<GrandLivrePage />} />
        <Route path="balance-comptable" element={<BalanceComptablePage />} />
        <Route path="soldes-ouverture" element={<SoldesOuverturePage />} />
        <Route path="etats-financiers" element={<EtatsFinanciersPage />} />
        <Route path="previsions-stock" element={<PrevisionsStockPage />} />
        <Route path="devis" element={<DevisPage />} />
        <Route path="pieces-jointes" element={<PiecesJointesPage />} />
        <Route path="gestion-prix" element={<GestionPrixPage />} />
        <Route path="alertes-config" element={<NotificationsConfigPage />} />
        <Route path="configuration" element={<ConfigurationPage />} />
        <Route path="courriels" element={<CourrielsPage />} />
        <Route path="fiches-employes" element={<FichesEmployesPage />} />
        <Route path="referentiels" element={<ReferentielsPage />} />
        <Route path="notifications" element={<NotificationsPage />} />
        {/* Adresse inconnue : page introuvable dans le cadre de l'application (page blanche auparavant). */}
        <Route path="*" element={<PageIntrouvable />} />
      </Route>
      
      {/* Routes Super Admin */}
      <Route path="/super-admin" element={<SuperAdminLoginPage />} />
      <Route path="/super-admin/dashboard" element={<SuperAdminDashboard />} />
      <Route path="/super-admin/tenants/new" element={<SuperAdminNewTenantPage />} />
      <Route path="/super-admin/tenants/:tenantId" element={<SuperAdminTenantPage />} />
    </Routes>
  );
}

// En desktop (Tauri, app chargée via tauri://) il n'y a pas de serveur pour
// résoudre les routes profondes : on utilise HashRouter. En web : BrowserRouter.
const isTauri = typeof window !== 'undefined' && '__TAURI_INTERNALS__' in window;
const Router = isTauri ? HashRouter : BrowserRouter;

function App() {
  return (
    <ErrorBoundary>
      <Router>
        <ThemeProvider>
          <LanguageProvider>
            <AuthProvider>
              <PermissionsProvider>
                <AppRoutes />
                <Toaster />
              </PermissionsProvider>
            </AuthProvider>
          </LanguageProvider>
        </ThemeProvider>
      </Router>
    </ErrorBoundary>
  );
}

export default App;
