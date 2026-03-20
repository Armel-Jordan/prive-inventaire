import { createContext } from 'react';
import type { Language, Translations } from './translations';

export interface LanguageContextType {
  language: Language;
  setLanguage: (lang: Language) => void;
  t: Translations;
}

export const LanguageContext = createContext<LanguageContextType | undefined>(undefined);
