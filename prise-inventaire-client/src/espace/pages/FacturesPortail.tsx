import { useCallback, useState } from 'react';
import { Download, FileText } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { telecharger, type FacturePortail, type Page } from '../api';
import { useDonnees, usePortail } from '../contexte';
import { BadgeFacture, Chargement, Message, Pagination } from '../composants';
import { aujourdhui, jour, montant, remplir } from '../format';

/** Factures du client (émises, payées ou non), avec le reste à payer et le document PDF. */
export default function FacturesPortail() {
  const { t, language } = useLanguage();
  const tp = t.portal;
  const { appel, moi } = usePortail();
  const [page, setPage] = useState(1);
  const [erreurPdf, setErreurPdf] = useState('');
  const charger = useCallback(() => appel<Page<FacturePortail>>(`/espace-client/factures?page=${page}`), [appel, page]);
  const { donnees, erreur, chargement } = useDonnees(charger);
  const devise = moi?.entreprise.devise;
  const ceJour = aujourdhui();

  async function pdf(facture: FacturePortail) {
    setErreurPdf('');
    try {
      telecharger(await appel<Blob>(`/espace-client/factures/${facture.id}/pdf`, { fichier: true }), `facture-${facture.numero}.pdf`);
    } catch (e) {
      setErreurPdf(messageErreur(e, tp.error));
    }
  }

  return (
    <div>
      <h1 className="text-2xl font-bold mb-5">{tp.invoicesTitle}</h1>
      {(erreur !== undefined || erreurPdf) && <div className="mb-4"><Message type="erreur">{erreurPdf || messageErreur(erreur, tp.error)}</Message></div>}
      {!donnees ? (
        chargement && <Chargement />
      ) : donnees.data.length === 0 ? (
        <div className="text-center py-16 text-gray-500">
          <FileText size={40} className="mx-auto mb-3 text-gray-300" />
          <p>{tp.noInvoices}</p>
        </div>
      ) : (
        <>
          <ul className={`bg-white rounded-xl border border-gray-200 divide-y divide-gray-100 ${chargement ? 'opacity-60' : ''}`}>
            {donnees.data.map(facture => {
              const reste = Number(facture.reste_a_payer);
              const enRetard = facture.statut !== 'payee' && reste > 0 && facture.date_echeance !== null && facture.date_echeance.slice(0, 10) < ceJour;
              return (
                <li key={facture.id} className="p-4 flex flex-wrap items-center gap-3">
                  <div className="flex-1 min-w-[10rem]">
                    <p className="font-medium">{facture.numero}</p>
                    <p className="text-xs text-gray-500">
                      {jour(facture.date_facture, language)}
                      {facture.date_echeance && ` · ${remplir(tp.dueOn, { date: jour(facture.date_echeance, language) })}`}
                    </p>
                  </div>
                  <BadgeFacture statut={facture.statut} enRetard={enRetard} />
                  <div className="text-right text-sm sm:w-40">
                    <p className="font-medium whitespace-nowrap">{montant(facture.montant_ttc, language, facture.devise ?? devise)}</p>
                    {facture.statut !== 'payee' && reste > 0 && (
                      <p className={`text-xs whitespace-nowrap ${enRetard ? 'text-red-600' : 'text-gray-500'}`}>{tp.remaining} : {montant(reste, language, facture.devise ?? devise)}</p>
                    )}
                  </div>
                  <button type="button" onClick={() => { void pdf(facture); }} className="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg" title={tp.downloadPdf} aria-label={`${tp.downloadPdf} ${facture.numero}`}>
                    <Download size={18} />
                  </button>
                </li>
              );
            })}
          </ul>
          <Pagination page={donnees.current_page} pages={donnees.last_page} onChange={setPage} />
        </>
      )}
    </div>
  );
}
