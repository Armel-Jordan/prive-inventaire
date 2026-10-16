import { Globe } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import type { Language } from '@/i18n/translations';

/** Choix de la langue : deux boutons, utilisables au doigt comme à la souris. */
export default function LanguageSelector() {
  const { language, setLanguage, t } = useLanguage();
  const langues: Language[] = ['fr', 'en'];

  return (
    <div role="group" aria-label={t.language.label} className="flex items-center gap-0.5 text-gray-500">
      <Globe size={16} className="mr-0.5" aria-hidden="true" />
      {langues.map(langue => (
        <button
          key={langue}
          type="button"
          onClick={() => setLanguage(langue)}
          aria-pressed={language === langue}
          title={t.language[langue]}
          aria-label={t.language[langue]}
          className={`px-1.5 py-1 rounded text-xs font-semibold uppercase ${language === langue ? 'text-blue-700 bg-blue-50' : 'hover:text-gray-900 hover:bg-gray-100'}`}
        >
          {langue}
        </button>
      ))}
    </div>
  );
}
