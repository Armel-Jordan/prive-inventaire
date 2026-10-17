import { useState } from 'react';
import { Gift } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { useMontantDocument } from '@/lib/documents';
import { consulterCarteCadeau, type CarteConsultee } from '@/services/cartesCadeaux';

/**
 * Carte cadeau présentée pour payer : numéro saisi ou scanné, puis vérifié (solde, état) ; la carte vérifiée est
 * transmise pour proposer le montant (au plus son solde).
 */
export default function ChampCarteCadeau({ numero, onNumero, onVerifiee, className = '' }: {
  numero: string;
  onNumero: (numero: string) => void;
  onVerifiee: (carte: CarteConsultee) => void;
  className?: string;
}) {
  const { t } = useLanguage();
  const tg = t.giftCards;
  const montant = useMontantDocument();
  const [carte, setCarte] = useState<CarteConsultee | null>(null);
  const [erreur, setErreur] = useState('');

  async function verifier() {
    if (!numero.trim()) return;
    setErreur('');
    try {
      const trouvee = await consulterCarteCadeau(numero.trim());
      setCarte(trouvee);
      if (trouvee.etat === 'active') onVerifiee(trouvee);
    } catch (e) {
      setCarte(null);
      setErreur(messageErreur(e, tg.error));
    }
  }

  return (
    <div className={className}>
      <div className="flex gap-2">
        <input value={numero} maxLength={30} placeholder={tg.cardNumber} aria-label={tg.cardNumber}
          onChange={e => { onNumero(e.target.value); setCarte(null); setErreur(''); }}
          onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); void verifier(); } }}
          className="flex-1 min-w-0 px-3 py-2 border rounded-lg font-mono uppercase text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
        <button type="button" onClick={() => { void verifier(); }} className="px-3 py-2 rounded-lg border text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tg.check}</button>
      </div>
      {carte && (
        <p className={`mt-1 flex items-center gap-1 text-xs ${carte.etat === 'active' ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-600'}`}>
          <Gift size={12} /> {tg.cardBalance.replace('{montant}', montant(carte.solde, carte.devise)).replace('{etat}', tg.states[carte.etat])}
        </p>
      )}
      {erreur && <p className="mt-1 text-xs text-red-600">{erreur}</p>}
    </div>
  );
}
