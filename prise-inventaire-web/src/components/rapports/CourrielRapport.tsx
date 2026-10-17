import { useCallback, useState } from 'react';
import { Paperclip } from 'lucide-react';
import ComposerCourriel from '@/components/ComposerCourriel';
import { useLanguage } from '@/i18n/useLanguage';
import type { LangueCourriel } from '@/services/api';
import {
  envoyerCourrielRapport, getEnvoisRapport, preparerCourrielRapport, FORMATS_RAPPORT, type FormatRapport,
} from '@/services/bibliothequeRapports';

/**
 * Envoi d'un rapport par courriel : la fenêtre d'envoi des documents (modèle « rapport » de l'entreprise, copies,
 * envois précédents), le rapport joint dans le format choisi avec les paramètres saisis.
 */
export default function CourrielRapport({ code, titre, parametres, onClose }: {
  code: string;
  titre: string;
  parametres: Record<string, string>;
  onClose: () => void;
}) {
  const { t } = useLanguage();
  const tr = t.reportsLibrary;
  const [format, setFormat] = useState<FormatRapport>('pdf');
  const preparer = useCallback((langue: LangueCourriel) => preparerCourrielRapport(code, parametres, langue), [code, parametres]);
  const historique = useCallback(() => getEnvoisRapport(code), [code]);
  const jour = new Date().toISOString().slice(0, 10);

  return (
    <ComposerCourriel
      onClose={onClose}
      source={{
        titre,
        preparer,
        historique,
        aucunEnvoi: tr.noPreviousSends,
        envoyer: courriel => envoyerCourrielRapport(code, { ...courriel, parametres, format }),
        pieceJointe: (
          <div className="flex flex-wrap items-center gap-3 p-2 bg-gray-50 dark:bg-gray-700 rounded-lg text-sm text-gray-700 dark:text-gray-200">
            <Paperclip size={14} aria-hidden="true" />
            <label className="flex items-center gap-2">
              {tr.attachedFormat}
              <select value={format} onChange={e => setFormat(e.target.value as FormatRapport)}
                className="px-2 py-1 border rounded dark:bg-gray-800 dark:border-gray-600 dark:text-white text-sm">
                {FORMATS_RAPPORT.map(f => <option key={f} value={f}>{tr.formats[f]}</option>)}
              </select>
            </label>
            <span className="text-xs text-gray-500 dark:text-gray-400">{`${code}-${jour}.${format}`}</span>
          </div>
        ),
      }}
    />
  );
}
