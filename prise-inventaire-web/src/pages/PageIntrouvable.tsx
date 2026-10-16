import { Link, useLocation } from 'react-router-dom';
import { Compass } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';

/** Adresse inconnue : message dans le cadre de l'application au lieu d'une page blanche. */
export default function PageIntrouvable() {
  const { t } = useLanguage();
  const { pathname } = useLocation();

  return (
    <div className="p-6 flex flex-col items-center justify-center text-center min-h-[60vh]">
      <Compass size={48} className="text-gray-300 dark:text-gray-600 mb-4" aria-hidden />
      <h1 className="text-2xl font-bold text-gray-800 dark:text-white mb-2">{t.notFound.title}</h1>
      <p className="text-gray-500 dark:text-gray-400 mb-6">{t.notFound.message.replace('{path}', pathname)}</p>
      <Link to="/" className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
        {t.notFound.back}
      </Link>
    </div>
  );
}
