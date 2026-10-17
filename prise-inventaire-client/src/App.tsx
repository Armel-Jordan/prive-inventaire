import { BrowserRouter, Route, Routes } from 'react-router-dom';
import { LanguageProvider } from '@/i18n/LanguageContext';
import EspaceClient from '@/espace/EspaceClient';
import ReponseDevis from '@/espace/pages/ReponseDevis';

/** Espace client : le site des clients des entreprises (commandes, suivi, factures, profil). */
export default function App() {
  return (
    <BrowserRouter>
      <LanguageProvider>
        <Routes>
          {/* Réponse à un devis : lien du courriel, sans compte ni espace client ouvert. */}
          <Route path="/devis/:jeton" element={<ReponseDevis />} />
          <Route path="*" element={<EspaceClient />} />
        </Routes>
      </LanguageProvider>
    </BrowserRouter>
  );
}
