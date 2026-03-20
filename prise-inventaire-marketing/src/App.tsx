import { BrowserRouter, Routes, Route, useLocation } from 'react-router-dom';
import { useEffect, Suspense, lazy } from 'react';
import { HelmetProvider } from 'react-helmet-async';
import { LanguageProvider } from './i18n';
import Navbar from './components/Navbar';
import Footer from './components/Footer';
import Loader from './components/Loader';
import './index.css';

// Lazy loading des pages
const HomePage = lazy(() => import('./pages/HomePage'));
const SolutionsPage = lazy(() => import('./pages/SolutionsPage'));
const VisionPage = lazy(() => import('./pages/VisionPage'));
const TarifsPage = lazy(() => import('./pages/TarifsPage'));
const FormationsPage = lazy(() => import('./pages/FormationsPage'));
const ContactPage = lazy(() => import('./pages/ContactPage'));
const LegalPage = lazy(() => import('./pages/LegalPage'));
const PrivacyPage = lazy(() => import('./pages/PrivacyPage'));
const TermsPage = lazy(() => import('./pages/TermsPage'));
const NotFoundPage = lazy(() => import('./pages/NotFoundPage'));

function ScrollToTop() {
  const { pathname } = useLocation();

  useEffect(() => {
    window.scrollTo(0, 0);
  }, [pathname]);

  return null;
}

function App() {
  return (
    <HelmetProvider>
      <LanguageProvider>
        <BrowserRouter>
          <ScrollToTop />
          <Navbar />
          <main>
            <Suspense fallback={<Loader />}>
              <Routes>
                <Route path="/" element={<HomePage />} />
                <Route path="/solutions" element={<SolutionsPage />} />
                <Route path="/vision" element={<VisionPage />} />
                <Route path="/tarifs" element={<TarifsPage />} />
                <Route path="/formations" element={<FormationsPage />} />
                <Route path="/contact" element={<ContactPage />} />
                <Route path="/mentions-legales" element={<LegalPage />} />
                <Route path="/confidentialite" element={<PrivacyPage />} />
                <Route path="/cgv" element={<TermsPage />} />
                <Route path="*" element={<NotFoundPage />} />
              </Routes>
            </Suspense>
          </main>
          <Footer />
        </BrowserRouter>
      </LanguageProvider>
    </HelmetProvider>
  );
}

export default App;
