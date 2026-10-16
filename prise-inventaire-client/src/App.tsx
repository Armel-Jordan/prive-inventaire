import { BrowserRouter } from 'react-router-dom';
import { LanguageProvider } from '@/i18n/LanguageContext';
import EspaceClient from '@/espace/EspaceClient';

/** Espace client : le site des clients des entreprises (commandes, suivi, factures, profil). */
export default function App() {
  return (
    <BrowserRouter>
      <LanguageProvider>
        <EspaceClient />
      </LanguageProvider>
    </BrowserRouter>
  );
}
