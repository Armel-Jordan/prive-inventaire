import { useEffect, useState, type ReactNode } from 'react';
import { translations, type Language } from './translations';
import { LanguageContext } from './useLanguage';

const CLE = 'espace_client_langue';

/** Langue de l'espace client : celle choisie, sinon celle du navigateur (français par défaut). */
export function LanguageProvider({ children }: { children: ReactNode }) {
  const [language, setLanguage] = useState<Language>(() => {
    try {
      const choisie = localStorage.getItem(CLE);
      if (choisie === 'fr' || choisie === 'en') return choisie;
    } catch {
      // Stockage indisponible (navigation privée) : langue du navigateur.
    }
    return navigator.language.toLowerCase().startsWith('en') ? 'en' : 'fr';
  });

  useEffect(() => {
    try {
      localStorage.setItem(CLE, language);
    } catch {
      // Sans stockage, la langue vaut pour la visite.
    }
    document.documentElement.lang = language;
  }, [language]);

  return (
    <LanguageContext.Provider value={{ language, setLanguage, t: translations[language] }}>
      {children}
    </LanguageContext.Provider>
  );
}
