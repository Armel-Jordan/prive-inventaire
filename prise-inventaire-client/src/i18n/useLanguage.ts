import { createContext, useContext } from 'react';
import type { Language, TranslationKeys } from './translations';

export interface LanguageContextType {
  language: Language;
  setLanguage: (langue: Language) => void;
  t: TranslationKeys;
}

export const LanguageContext = createContext<LanguageContextType | undefined>(undefined);

export function useLanguage(): LanguageContextType {
  const contexte = useContext(LanguageContext);
  if (!contexte) {
    throw new Error('useLanguage doit être utilisé dans un LanguageProvider');
  }
  return contexte;
}
