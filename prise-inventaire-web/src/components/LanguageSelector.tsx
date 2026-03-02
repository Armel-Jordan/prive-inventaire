import { Globe } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';

export default function LanguageSelector() {
  const { language, setLanguage, t } = useLanguage();

  return (
    <div className="relative group">
      <button
        className="flex items-center gap-2 px-3 py-2 text-sm text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg transition-colors"
        title={t.settings.language}
      >
        <Globe className="w-4 h-4" />
        <span className="uppercase font-medium">{language}</span>
      </button>
      <div className="absolute right-0 mt-1 w-32 bg-white border border-gray-200 rounded-lg shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50">
        <button
          onClick={() => setLanguage('fr')}
          className={`w-full px-4 py-2 text-left text-sm hover:bg-gray-100 rounded-t-lg ${
            language === 'fr' ? 'bg-blue-50 text-blue-600 font-medium' : 'text-gray-700'
          }`}
        >
          {t.settings.french}
        </button>
        <button
          onClick={() => setLanguage('en')}
          className={`w-full px-4 py-2 text-left text-sm hover:bg-gray-100 rounded-b-lg ${
            language === 'en' ? 'bg-blue-50 text-blue-600 font-medium' : 'text-gray-700'
          }`}
        >
          {t.settings.english}
        </button>
      </div>
    </div>
  );
}
