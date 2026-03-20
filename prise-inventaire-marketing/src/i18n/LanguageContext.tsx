import { useState, useEffect } from 'react';
import type { ReactNode } from 'react';
import { translations } from './translations';
import type { Language } from './translations';
import { LanguageContext } from './context';

function detectBrowserLanguage(): Language {
  const browserLang = navigator.language.split('-')[0];
  const supportedLanguages: Language[] = ['fr', 'en'];
  return supportedLanguages.includes(browserLang as Language) 
    ? (browserLang as Language) 
    : 'fr';
}

export function LanguageProvider({ children }: { children: ReactNode }) {
  const [language, setLanguage] = useState<Language>(() => {
    const saved = localStorage.getItem('language');
    if (saved && ['fr', 'en'].includes(saved)) {
      return saved as Language;
    }
    return detectBrowserLanguage();
  });

  useEffect(() => {
    localStorage.setItem('language', language);
    document.documentElement.lang = language;
  }, [language]);

  const t = translations[language];

  return (
    <LanguageContext.Provider value={{ language, setLanguage, t }}>
      {children}
    </LanguageContext.Provider>
  );
}

