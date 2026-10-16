import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Banknote, Eye, Pencil, Plus, Trash2, Undo2, Wallet, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { formatDateDocument, useMontantDocument } from '@/lib/documents';
import { getAcompte, getAcomptes, supprimerAcompte, type Acompte, type ListeAcomptes } from '@/services/acomptes';
import FormulaireAcompte from '@/components/acomptes/FormulaireAcompte';
import UtiliserAcompte from '@/components/acomptes/UtiliserAcompte';
import Pagination from '@/components/Pagination';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const COULEURS: Record<string, string> = {
  disponible: 'bg-blue-100 text-blue-800',
  entame: 'bg-amber-100 text-amber-800',
  utilise: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
};

/**
 * Acomptes (plan de parité fripv2, phase 1.5), comme la liste des acomptes de fripv2 : tous les acomptes reçus, tous
 * clients confondus, avec ce qui reste de chacun, ce qui a été appliqué et la commande à laquelle il est réservé.
 * Recherche par référence, client ou montant ; saisie, application sur une facture, remboursement.
 */
export default function AcomptesPage() {
  const { t, language } = useLanguage();
  const td = t.deposits;
  // Montants dans la devise de chaque document (celle de l'entreprise, ou celle d'un client étranger).
  const montantDoc = useMontantDocument();
  const montant = (v: number | string | null | undefined, deviseDoc?: string | null) => montantDoc(v, deviseDoc);
  const { peut } = usePermissions();
  const { toasts, toast, dismiss } = useToast();
  const [parametres] = useSearchParams();
  const [recherche, setRecherche] = useState(parametres.get('recherche') ?? '');
  const [terme, setTerme] = useState(recherche.trim());
  const [disponibilite, setDisponibilite] = useState<'' | 'avec_solde' | 'utilise'>('');
  const [page, setPage] = useState(1);
  const [resultat, setResultat] = useState<ListeAcomptes | null>(null);
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);
  const [detail, setDetail] = useState<Acompte | null>(null);
  const [formulaire, setFormulaire] = useState<{ acompte: Acompte | null } | null>(null);
  const [utilisation, setUtilisation] = useState<{ acompte: Acompte; mode: 'appliquer' | 'rembourser' } | null>(null);

  useEffect(() => {
    const minuterie = setTimeout(() => { setTerme(recherche.trim()); setPage(1); }, 300);
    return () => clearTimeout(minuterie);
  }, [recherche]);

  useEffect(() => {
    let actif = true;
    getAcomptes({ recherche: terme || undefined, disponibilite: disponibilite || undefined, page }).then(
      r => { if (actif) { setResultat(r); setErreur(''); } },
      e => { if (actif) setErreur(messageErreur(e, td.error)); },
    );
    return () => { actif = false; };
  }, [terme, disponibilite, page, version, td.error]);

  const recharger = () => setVersion(v => v + 1);
  const echec = (e: unknown) => toast(messageErreur(e, td.error), 'error');

  async function voir(id: number) {
    try { setDetail(await getAcompte(id)); } catch (e) { echec(e); }
  }

  async function supprimer(acompte: Acompte) {
    if (!confirm(td.confirmDelete)) return;
    try {
      await supprimerAcompte(acompte.id);
      toast(td.deleted);
      recharger();
    } catch (e) { echec(e); }
  }

  const boutonsUtiliser = (acompte: Acompte, taille = 18) => acompte.disponible > 0 && (
    <>
      {peut('acomptes', 'appliquer') && (
        <button type="button" onClick={() => setUtilisation({ acompte, mode: 'appliquer' })} title={td.apply} aria-label={`${td.apply} — ${acompte.client?.raison_sociale ?? ''}`}
          className="p-1 text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 rounded"><Undo2 size={taille} /></button>
      )}
      {peut('acomptes', 'rembourser') && (
        <button type="button" onClick={() => setUtilisation({ acompte, mode: 'rembourser' })} title={td.refund} aria-label={`${td.refund} — ${acompte.client?.raison_sociale ?? ''}`}
          className="p-1 text-green-600 hover:bg-green-50 dark:hover:bg-green-900/30 rounded"><Banknote size={taille} /></button>
      )}
    </>
  );

  const acomptes = resultat?.data ?? [];

  return (
    <div className="p-4 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Wallet className="text-purple-600" /> {td.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{td.subtitle}</p>
        </div>
        {peut('acomptes', 'creer') && (
          <button type="button" onClick={() => setFormulaire({ acompte: null })} className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 text-sm">
            <Plus size={18} /> {td.newDeposit}
          </button>
        )}
      </div>

      <div className="flex flex-wrap items-end gap-2 mb-4">
        <label className="text-xs text-gray-500 dark:text-gray-400 flex-1 min-w-[12rem]">{td.search}
          <input type="search" value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={td.searchHelp} className={classeChamp} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400 w-56">{td.availability}
          <select value={disponibilite} onChange={e => { setDisponibilite(e.target.value as typeof disponibilite); setPage(1); }} className={classeChamp}>
            <option value="">{td.all}</option>
            <option value="avec_solde">{td.withBalance}</option>
            <option value="utilise">{td.used}</option>
          </select>
        </label>
      </div>

      {erreur && <p role="alert" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
      {resultat && resultat.total > 0 && (
        <p className="mb-2 text-sm text-gray-600 dark:text-gray-300">
          {td.totals.replace('{montant}', montant(resultat.totaux.montant)).replace('{disponible}', montant(resultat.totaux.disponible))}
        </p>
      )}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
        <table className="w-full text-sm min-w-[52rem]">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-3 py-2 font-medium">{td.date}</th>
              <th className="px-3 py-2 font-medium">{td.customer}</th>
              <th className="px-3 py-2 font-medium">{td.mode}</th>
              <th className="px-3 py-2 font-medium text-right">{td.amount}</th>
              <th className="px-3 py-2 font-medium text-right">{td.available}</th>
              <th className="px-3 py-2 font-medium">{td.reservedFor}</th>
              <th className="px-3 py-2 font-medium text-center">{td.status}</th>
              <th className="px-3 py-2 font-medium text-center">{td.actions}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
            {resultat === null && !erreur && <tr><td colSpan={8} className="px-4 py-6 text-center text-gray-500">{td.loading}</td></tr>}
            {resultat !== null && acomptes.length === 0 && <tr><td colSpan={8} className="px-4 py-6 text-center text-gray-500">{terme || disponibilite ? td.noResult : td.empty}</td></tr>}
            {acomptes.map(acompte => (
              <tr key={acompte.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                <td className="px-3 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">{formatDateDocument(acompte.date_acompte, language)}</td>
                <td className="px-3 py-2 text-gray-900 dark:text-white">
                  {acompte.client?.raison_sociale}
                  {acompte.demande && <span className="block text-xs text-gray-500">{td.origin} {acompte.demande.numero}</span>}
                </td>
                <td className="px-3 py-2 text-gray-700 dark:text-gray-300">
                  {td.modes[acompte.mode] ?? acompte.mode}
                  {acompte.reference && <span className="block text-xs font-mono text-gray-500">{acompte.reference}</span>}
                </td>
                <td className="px-3 py-2 text-right whitespace-nowrap font-medium text-gray-900 dark:text-white">{montant(acompte.montant, acompte.devise)}</td>
                <td className={`px-3 py-2 text-right whitespace-nowrap ${acompte.disponible > 0 ? 'text-indigo-700 dark:text-indigo-300 font-medium' : 'text-gray-400'}`}>{montant(acompte.disponible, acompte.devise)}</td>
                <td className="px-3 py-2 font-mono text-gray-700 dark:text-gray-300">{acompte.commande?.numero ?? '—'}</td>
                <td className="px-3 py-2 text-center"><span className={`px-2 py-0.5 text-xs rounded-full whitespace-nowrap ${COULEURS[acompte.etat]}`}>{td.states[acompte.etat]}</span></td>
                <td className="px-3 py-2">
                  <div className="flex justify-center gap-1">
                    <button type="button" onClick={() => { void voir(acompte.id); }} title={td.view} aria-label={`${td.view} — ${acompte.client?.raison_sociale ?? ''}`}
                      className="p-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded"><Eye size={18} /></button>
                    {boutonsUtiliser(acompte)}
                    {peut('acomptes', 'modifier') && (
                      <button type="button" onClick={() => setFormulaire({ acompte })} title={td.edit} aria-label={`${td.edit} — ${acompte.client?.raison_sociale ?? ''}`}
                        className="p-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded"><Pencil size={18} /></button>
                    )}
                    {peut('acomptes', 'supprimer') && acompte.etat === 'disponible' && (
                      <button type="button" onClick={() => { void supprimer(acompte); }} title={td.delete} aria-label={`${td.delete} — ${acompte.client?.raison_sociale ?? ''}`}
                        className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><Trash2 size={18} /></button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {resultat && <Pagination page={page} dernierePage={resultat.last_page ?? 1} total={resultat.total ?? 0} onPage={setPage} />}
      </div>

      {detail && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div role="dialog" aria-modal="true" aria-labelledby="detail-acompte-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg max-h-[94vh] overflow-y-auto space-y-4 text-sm">
            <div className="flex items-start justify-between gap-3">
              <div>
                <h2 id="detail-acompte-titre" className="text-lg font-bold text-gray-900 dark:text-white">{td.detailTitle.replace('{date}', formatDateDocument(detail.date_acompte, language))}</h2>
                <p className="text-gray-500 dark:text-gray-400">{detail.client?.raison_sociale} · {td.modes[detail.mode] ?? detail.mode}{detail.reference && ` · ${detail.reference}`}</p>
              </div>
              <div className="flex items-center gap-1">
                {boutonsUtiliser(detail, 16)}
                <button type="button" onClick={() => setDetail(null)} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={td.close}><X size={18} /></button>
              </div>
            </div>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-1">
              <dt className="text-gray-500">{td.amount}</dt><dd className="text-right text-gray-900 dark:text-white">{montant(detail.montant, detail.devise)}</dd>
              <dt className="text-gray-500">{td.applied}</dt><dd className="text-right text-gray-900 dark:text-white">{montant(detail.montant_applique, detail.devise)}</dd>
              <dt className="text-gray-500">{td.refunded}</dt><dd className="text-right text-gray-900 dark:text-white">{montant(detail.montant_rembourse, detail.devise)}</dd>
              <dt className="text-gray-500 font-medium">{td.available}</dt><dd className="text-right font-semibold text-gray-900 dark:text-white">{montant(detail.disponible, detail.devise)}</dd>
              {detail.commande && <><dt className="text-gray-500">{td.reservedFor}</dt><dd className="text-right font-mono text-gray-900 dark:text-white">{detail.commande.numero}</dd></>}
              {detail.demande && <><dt className="text-gray-500">{td.origin}</dt><dd className="text-right font-mono text-gray-900 dark:text-white">{detail.demande.numero}</dd></>}
            </dl>
            <div>
              <h3 className="font-semibold text-gray-800 dark:text-gray-200 mb-1">{td.applications}</h3>
              {(detail.applications ?? []).length === 0 ? <p className="text-gray-500">{td.none}</p> : (
                <ul className="divide-y dark:divide-gray-700">
                  {detail.applications!.map(a => (
                    <li key={a.id} className="flex justify-between gap-2 py-1">
                      <span className="text-gray-700 dark:text-gray-300">{formatDateDocument(a.date_paiement, language)} · <span className="font-mono">{a.facture?.numero}</span></span>
                      <span className="text-gray-900 dark:text-white whitespace-nowrap">{montant(a.montant, detail.devise)}</span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
            <div>
              <h3 className="font-semibold text-gray-800 dark:text-gray-200 mb-1">{td.refunds}</h3>
              {(detail.remboursements ?? []).length === 0 ? <p className="text-gray-500">{td.none}</p> : (
                <ul className="divide-y dark:divide-gray-700">
                  {detail.remboursements!.map(r => (
                    <li key={r.id} className="flex justify-between gap-2 py-1">
                      <span className="text-gray-700 dark:text-gray-300">{formatDateDocument(r.date_remboursement, language)} · {td.modes[r.mode ?? ''] ?? r.mode}{r.reference && ` · ${r.reference}`}</span>
                      <span className="text-gray-900 dark:text-white whitespace-nowrap">{montant(r.montant, detail.devise)}</span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
            {detail.notes && <p className="text-gray-700 dark:text-gray-300 whitespace-pre-line"><span className="font-medium">{td.notes} : </span>{detail.notes}</p>}
          </div>
        </div>
      )}

      {formulaire && (
        <FormulaireAcompte
          acompte={formulaire.acompte}
          onClose={() => setFormulaire(null)}
          onEnregistre={() => { setFormulaire(null); toast(td.saved); recharger(); }}
        />
      )}

      {utilisation && (
        <UtiliserAcompte
          acompte={utilisation.acompte}
          mode={utilisation.mode}
          onClose={() => setUtilisation(null)}
          onFait={(_acompte, message) => { setUtilisation(null); setDetail(null); toast(message); recharger(); }}
        />
      )}

      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}
