import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import { ChevronRight, ClipboardList, Globe } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import type { CommandeResume, Page } from '../api';
import { useDonnees, usePortail } from '../contexte';
import { BadgeCommande, Chargement, Message, Pagination } from '../composants';
import { jour, montant, remplir } from '../format';

/** Historique des commandes du client (passées en ligne ou saisies par l'entreprise). */
export default function CommandesPortail() {
  const { t, language } = useLanguage();
  const tp = t.portal;
  const { appel, moi } = usePortail();
  const [page, setPage] = useState(1);
  const charger = useCallback(() => appel<Page<CommandeResume>>(`/espace-client/commandes?page=${page}`), [appel, page]);
  const { donnees, erreur, chargement } = useDonnees(charger);
  const devise = moi?.entreprise.devise;

  return (
    <div>
      <h1 className="text-2xl font-bold mb-5">{tp.ordersTitle}</h1>
      {erreur !== undefined && <div className="mb-4"><Message type="erreur">{messageErreur(erreur, tp.error)}</Message></div>}
      {!donnees ? (
        chargement && <Chargement />
      ) : donnees.data.length === 0 ? (
        <div className="text-center py-16 text-gray-500">
          <ClipboardList size={40} className="mx-auto mb-3 text-gray-300" />
          <p>{tp.noOrders}</p>
          <Link to="/" className="inline-block mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">{tp.browseCatalogue}</Link>
        </div>
      ) : (
        <>
          <ul className={`bg-white rounded-xl border border-gray-200 divide-y divide-gray-100 ${chargement ? 'opacity-60' : ''}`}>
            {donnees.data.map(commande => (
              <li key={commande.id}>
                <Link to={`/commandes/${commande.id}`} className="flex items-center gap-3 p-4 hover:bg-gray-50">
                  <div className="flex-1 min-w-0">
                    <p className="font-medium flex items-center gap-1.5">
                      {commande.numero}
                      {commande.portail_compte_id !== null && (
                        <span title={tp.placedOnline}><Globe size={14} className="text-gray-400" /><span className="sr-only">{tp.placedOnline}</span></span>
                      )}
                    </p>
                    <p className="text-xs text-gray-500">
                      {remplir(tp.orderedOn, { date: jour(commande.date_commande, language) })} · {remplir(tp.itemsCount, { n: commande.lignes_count })}
                    </p>
                  </div>
                  <div className="flex flex-col sm:flex-row items-end sm:items-center gap-1 sm:gap-4">
                    <BadgeCommande statut={commande.statut} />
                    <span className="text-sm font-medium whitespace-nowrap sm:w-32 sm:text-right">{montant(commande.montant_ttc, language, devise)}</span>
                  </div>
                  <ChevronRight size={18} className="text-gray-400 shrink-0" />
                </Link>
              </li>
            ))}
          </ul>
          <Pagination page={donnees.current_page} pages={donnees.last_page} onChange={setPage} />
        </>
      )}
    </div>
  );
}
