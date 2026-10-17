import { Banknote } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import type { CaissePoste } from '@/hooks/useCaissePoste';

/** Rappel, dans une fenêtre d'encaissement, de la caisse où l'argent entre (rien si le poste n'est pas une caisse). */
export default function CaisseDuPoste({ caisse }: { caisse: CaissePoste | null }) {
  const { t } = useLanguage();
  if (!caisse) return null;
  return (
    <p className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm bg-emerald-50 text-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-200">
      <Banknote size={16} className="shrink-0" />
      {t.cashRegisters.paidInto.replace('{code}', caisse.code).replace('{nom}', caisse.nom)}
    </p>
  );
}
