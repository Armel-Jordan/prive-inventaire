import { useState } from 'react';
import { Store } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import LanguageSelector from '@/components/LanguageSelector';

/**
 * Premier écran sans entreprise connue : le client saisit le code de son fournisseur (il figure dans le lien
 * reçu par courriel). Le code retenu, la page de connexion de l'entreprise s'ouvre.
 */
export default function ChoixEntreprise({ onChoisir }: { onChoisir: (code: string) => void }) {
  const { t } = useLanguage();
  const tp = t.portal;
  const [code, setCode] = useState('');

  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 flex flex-col text-gray-900">
      <div className="flex justify-end p-3"><LanguageSelector /></div>
      <div className="flex-1 flex items-center justify-center px-4 pb-10">
        <form
          onSubmit={e => { e.preventDefault(); if (code.trim()) onChoisir(code.trim().toLowerCase()); }}
          className="bg-white rounded-2xl shadow-xl w-full max-w-md p-6 sm:p-8 space-y-4"
        >
          <div className="text-center">
            <div className="inline-flex items-center justify-center w-14 h-14 bg-blue-100 rounded-full mb-3">
              <Store className="w-7 h-7 text-blue-600" />
            </div>
            <h1 className="text-xl font-bold">{tp.codeTitle}</h1>
            <p className="text-sm text-gray-600 mt-1">{tp.codeHelp}</p>
          </div>
          <div>
            <label htmlFor="code-entreprise" className="block text-sm font-medium text-gray-700 mb-1">{tp.codeLabel}</label>
            <input
              id="code-entreprise"
              required
              autoComplete="organization"
              autoCapitalize="none"
              spellCheck={false}
              maxLength={64}
              value={code}
              onChange={e => setCode(e.target.value)}
              className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            />
          </div>
          <button type="submit" className="w-full py-2.5 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700">{tp.continue}</button>
        </form>
      </div>
    </div>
  );
}
