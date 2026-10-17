import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ArchiveRestore, CheckCircle2, Eye, FileText, PackageCheck, PackageMinus, PackageX, Pencil, Plus, ReceiptText, Trash2, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useAuth } from '@/contexts/AuthContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { formatDateDocument, useMontantDocument } from '@/lib/documents';
import { getFactures, type Facture, type PageResultats } from '@/services/api';
import {
  crediterRetourClient, getRetourClient, getRetoursClients, STATUTS_RETOUR, supprimerRetourClient, traiterRetourClient,
  type ResumeRetours, type RetourClient,
} from '@/services/retoursClients';
import EditeurRetour from '@/components/retours/EditeurRetour';
import Pagination from '@/components/Pagination';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const COULEURS: Record<string, string> = {
  brouillon: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
  traite: 'bg-teal-100 text-teal-800',
};
const COULEURS_TRAITEMENT: Record<string, string> = {
  remise_en_stock: 'bg-green-100 text-green-800',
  rebut: 'bg-red-100 text-red-800',
};

/**
 * Retours clients (plan de parité fripv2, phase 1.7) : marchandise rapportée par un client, avec la raison du
 * retour, remise en stock dans un secteur (un arrivage) ou mise au rebut ; le retour d'une facture donne ensuite sa
 * note de crédit. Un retour se crée ici (d'une facture ou sans facture) ou depuis la liste des factures.
 */
export default function RetoursClientsPage() {
  const { t, language } = useLanguage();
  const tr = t.customerReturns;
  const { peut } = usePermissions();
  // Renvoyer au fournisseur : seulement avec le module Achats.
  const { tenant } = useAuth();
  const achats = !Array.isArray(tenant?.modules) || tenant.modules.includes('achats');
  const montantDoc = useMontantDocument();
  const { toasts, toast, dismiss } = useToast();
  // Liens : recherche globale (/retours-clients?recherche=RC-00001), retour d'une facture (?facture=12).
  const [parametres, setParametres] = useSearchParams();
  const [recherche, setRecherche] = useState(parametres.get('recherche') ?? '');
  const [terme, setTerme] = useState(recherche.trim());
  const [statut, setStatut] = useState('');
  const [page, setPage] = useState(1);
  const [resultat, setResultat] = useState<(PageResultats<RetourClient> & { resume: ResumeRetours }) | null>(null);
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);
  const [detail, setDetail] = useState<RetourClient | null>(null);
  const [edition, setEdition] = useState<{ factureId: number | null; brouillon: RetourClient | null } | null>(() => {
    const facture = Number(parametres.get('facture'));
    return facture > 0 ? { factureId: facture, brouillon: null } : null;
  });
  const [choixFacture, setChoixFacture] = useState(false);

  useEffect(() => {
    // Le paramètre « facture » ouvre l'éditeur une seule fois.
    if (parametres.has('facture')) {
      const suivants = new URLSearchParams(parametres);
      suivants.delete('facture');
      setParametres(suivants, { replace: true });
    }
  }, [parametres, setParametres]);

  useEffect(() => {
    const minuterie = setTimeout(() => { setTerme(recherche.trim()); setPage(1); }, 300);
    return () => clearTimeout(minuterie);
  }, [recherche]);

  useEffect(() => {
    let actif = true;
    getRetoursClients({ statut: statut || undefined, recherche: terme || undefined, page }).then(
      r => { if (actif) { setResultat(r); setErreur(''); } },
      e => { if (actif) setErreur(messageErreur(e, tr.error)); },
    );
    return () => { actif = false; };
  }, [statut, terme, page, version, tr.error]);

  const recharger = () => setVersion(v => v + 1);
  const echec = (e: unknown) => toast(messageErreur(e, tr.error), 'error');

  async function voir(id: number) {
    try { setDetail(await getRetourClient(id)); } catch (e) { echec(e); }
  }

  async function modifier(id: number) {
    try {
      const retour = await getRetourClient(id);
      setEdition({ factureId: retour.facture_id, brouillon: retour });
    } catch (e) { echec(e); }
  }

  async function traiter(retour: RetourClient) {
    if (!confirm(tr.confirmProcess)) return;
    try {
      const traite = await traiterRetourClient(retour.id);
      toast(tr.processed.replace('{numero}', traite.numero ?? ''));
      setDetail(null);
      recharger();
    } catch (e) { echec(e); }
  }

  async function crediter(retour: RetourClient) {
    try {
      const avoir = await crediterRetourClient(retour.id);
      toast(tr.credited.replace('{montant}', montantDoc(avoir.montant_ttc, avoir.devise)));
      setDetail(null);
      recharger();
    } catch (e) { echec(e); }
  }

  async function supprimer(retour: RetourClient) {
    if (!confirm(tr.confirmDelete)) return;
    try {
      await supprimerRetourClient(retour.id);
      toast(tr.deleted);
      recharger();
    } catch (e) { echec(e); }
  }

  const statutBadge = (valeur: string) => (
    <span className={`px-2 py-0.5 text-xs rounded-full whitespace-nowrap ${COULEURS[valeur] ?? 'bg-gray-100'}`}>{tr.statuses[valeur] ?? valeur}</span>
  );

  /** Actions d'un retour selon son état et les droits : modifier, traiter, supprimer (brouillon) ; créditer (traité). */
  const actions = (retour: RetourClient, taille = 18) => (
    <>
      {retour.statut === 'brouillon' && (
        <>
          {peut('retours_clients', 'modifier') && (
            <button type="button" onClick={() => { void modifier(retour.id); }} title={tr.edit} aria-label={tr.edit}
              className="p-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded"><Pencil size={taille} /></button>
          )}
          {peut('retours_clients', 'traiter') && (
            <button type="button" onClick={() => { void traiter(retour); }} title={tr.process} aria-label={tr.process}
              className="p-1 text-teal-600 hover:bg-teal-50 dark:hover:bg-teal-900/30 rounded"><CheckCircle2 size={taille} /></button>
          )}
          {peut('retours_clients', 'supprimer') && (
            <button type="button" onClick={() => { void supprimer(retour); }} title={tr.delete} aria-label={tr.delete}
              className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><Trash2 size={taille} /></button>
          )}
        </>
      )}
      {retour.statut === 'traite' && achats && peut('retours_fournisseurs', 'creer') && (
        <Link to={`/retours-fournisseurs?retour_client=${retour.id}`} title={t.supplierReturns.sendBackToSupplier}
          aria-label={`${t.supplierReturns.sendBackToSupplier} — ${retour.numero}`}
          className="p-1 text-orange-600 hover:bg-orange-50 dark:hover:bg-orange-900/30 rounded"><PackageMinus size={taille} /></Link>
      )}
      {retour.statut === 'traite' && retour.facture_id && !retour.avoir && peut('avoirs', 'creer') && (
        <button type="button" onClick={() => { void crediter(retour); }} title={tr.credit} aria-label={`${tr.credit} — ${retour.numero}`}
          className="p-1 text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 rounded"><ReceiptText size={taille} /></button>
      )}
    </>
  );

  const retours = resultat?.data ?? [];
  const resume = resultat?.resume;

  return (
    <div className="p-4 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><ArchiveRestore className="text-teal-600" /> {tr.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tr.subtitle}</p>
        </div>
        {peut('retours_clients', 'creer') && (
          <div className="flex flex-wrap gap-2">
            <button type="button" onClick={() => setChoixFacture(true)} className="flex items-center gap-2 bg-teal-600 text-white px-4 py-2 rounded-lg hover:bg-teal-700 text-sm">
              <Plus size={18} /> {tr.newFromInvoice}
            </button>
            <button type="button" onClick={() => setEdition({ factureId: null, brouillon: null })} className="flex items-center gap-2 border border-teal-300 text-teal-700 px-4 py-2 rounded-lg hover:bg-teal-50 text-sm dark:border-teal-500 dark:text-teal-300 dark:hover:bg-teal-900/30">
              <Plus size={18} /> {tr.newWithoutInvoice}
            </button>
          </div>
        )}
      </div>

      {resume && (resume.remis_en_stock > 0 || resume.rebut > 0) && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-3 flex items-center gap-3">
            <PackageCheck className="text-green-600 shrink-0" />
            <div><p className="text-xs text-gray-500 dark:text-gray-400">{tr.summaryRestocked}</p><p className="text-lg font-semibold text-gray-900 dark:text-white">{resume.remis_en_stock}</p></div>
          </div>
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-3 flex items-center gap-3">
            <PackageX className="text-red-600 shrink-0" />
            <div><p className="text-xs text-gray-500 dark:text-gray-400">{tr.summaryScrapped}</p><p className="text-lg font-semibold text-gray-900 dark:text-white">{resume.rebut}</p></div>
          </div>
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-3">
            <p className="text-xs text-gray-500 dark:text-gray-400 mb-1">{tr.summaryByReason}</p>
            <ul className="text-sm space-y-0.5">
              {resume.par_raison.slice(0, 4).map(r => (
                <li key={r.raison_id ?? 'aucune'} className="flex justify-between gap-2">
                  <span className="text-gray-700 dark:text-gray-300 truncate">{r.libelle ?? tr.noReasonLabel}</span>
                  <span className="font-medium text-gray-900 dark:text-white">{r.quantite}</span>
                </li>
              ))}
            </ul>
          </div>
        </div>
      )}

      <div className="flex flex-wrap items-end gap-2 mb-4">
        <label className="text-xs text-gray-500 dark:text-gray-400 flex-1 min-w-[12rem]">{tr.search}
          <input type="search" value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={tr.searchHelp} className={classeChamp} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400 w-48">{tr.status}
          <select value={statut} onChange={e => { setStatut(e.target.value); setPage(1); }} className={classeChamp}>
            <option value="">{tr.allStatuses}</option>
            {STATUTS_RETOUR.map(s => <option key={s} value={s}>{tr.statuses[s]}</option>)}
          </select>
        </label>
      </div>

      {erreur && <p role="alert" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
        <table className="w-full text-sm min-w-[52rem]">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-3 py-2 font-medium">{tr.number}</th>
              <th className="px-3 py-2 font-medium">{tr.invoice}</th>
              <th className="px-3 py-2 font-medium">{tr.customer}</th>
              <th className="px-3 py-2 font-medium">{tr.date}</th>
              <th className="px-3 py-2 font-medium text-right">{tr.quantity}</th>
              <th className="px-3 py-2 font-medium">{tr.creditNote}</th>
              <th className="px-3 py-2 font-medium text-center">{tr.status}</th>
              <th className="px-3 py-2 font-medium text-center">{tr.actions}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
            {resultat === null && !erreur && <tr><td colSpan={8} className="px-4 py-6 text-center text-gray-500">{tr.loading}</td></tr>}
            {resultat !== null && retours.length === 0 && <tr><td colSpan={8} className="px-4 py-6 text-center text-gray-500">{terme || statut ? tr.noResult : tr.empty}</td></tr>}
            {retours.map(retour => (
              <tr key={retour.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                <td className="px-3 py-2 font-mono text-gray-900 dark:text-white whitespace-nowrap">{retour.numero ?? <span className="italic text-gray-500 font-sans">{tr.draft}</span>}</td>
                <td className="px-3 py-2 font-mono text-gray-700 dark:text-gray-300 whitespace-nowrap">{retour.facture?.numero ?? <span className="font-sans text-gray-400">{tr.noInvoice}</span>}</td>
                <td className="px-3 py-2 text-gray-700 dark:text-gray-300">{retour.client?.raison_sociale}</td>
                <td className="px-3 py-2 text-gray-700 dark:text-gray-300 whitespace-nowrap">{formatDateDocument(retour.date_retour, language)}</td>
                <td className="px-3 py-2 text-right font-medium text-gray-900 dark:text-white">{Number(retour.quantite_totale ?? 0)}</td>
                <td className="px-3 py-2 font-mono text-gray-700 dark:text-gray-300 whitespace-nowrap">
                  {retour.avoir ? (
                    <Link to={`/notes-credit?recherche=${encodeURIComponent(retour.avoir.numero ?? retour.numero ?? '')}`} className="text-indigo-600 hover:underline">{retour.avoir.numero ?? tr.draft}</Link>
                  ) : '—'}
                </td>
                <td className="px-3 py-2 text-center">{statutBadge(retour.statut)}</td>
                <td className="px-3 py-2">
                  <div className="flex justify-center gap-1">
                    <button type="button" onClick={() => { void voir(retour.id); }} title={tr.view} aria-label={`${tr.view} — ${retour.numero ?? tr.draft}`}
                      className="p-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded"><Eye size={18} /></button>
                    {actions(retour)}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {resultat && <Pagination page={page} dernierePage={resultat.last_page ?? 1} total={resultat.total ?? 0} onPage={setPage} />}
      </div>

      {detail && <DetailRetour retour={detail} onClose={() => setDetail(null)} actions={actions(detail, 16)} />}

      {choixFacture && (
        <ChoixFacture onClose={() => setChoixFacture(false)} onChoisir={id => { setChoixFacture(false); setEdition({ factureId: id, brouillon: null }); }} />
      )}

      {edition && (
        <EditeurRetour
          factureId={edition.factureId}
          brouillon={edition.brouillon}
          onClose={() => setEdition(null)}
          onEnregistre={(retour, traite) => {
            setEdition(null);
            toast(traite ? tr.processed.replace('{numero}', retour.numero ?? '') : tr.saved);
            recharger();
          }}
        />
      )}

      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}

/** Détail d'un retour : lignes (traitement, secteur, raison), note de crédit, auteur et traitement. */
function DetailRetour({ retour, onClose, actions }: { retour: RetourClient; onClose: () => void; actions: React.ReactNode }) {
  const { t, language } = useLanguage();
  const tr = t.customerReturns;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="detail-retour-titre" className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-3xl max-h-[94vh] overflow-y-auto space-y-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 id="detail-retour-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tr.detailTitle.replace('{numero}', retour.numero ?? tr.draft)}</h2>
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {tr.statuses[retour.statut]}
              {retour.traite_le && ` · ${tr.processedOn.replace('{date}', formatDateDocument(retour.traite_le, language))}`}
              {retour.operateur && ` · ${tr.processedBy.replace('{nom}', retour.operateur.nom)}`}
              {retour.auteur && ` · ${tr.createdBy.replace('{nom}', retour.auteur.nom)}`}
            </p>
          </div>
          <div className="flex items-center gap-1">
            {actions}
            <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tr.close}><X size={18} /></button>
          </div>
        </div>

        <dl className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
          <div><dt className="text-xs text-gray-500">{tr.customer}</dt><dd className="text-gray-900 dark:text-white">{retour.client?.raison_sociale}</dd></div>
          <div><dt className="text-xs text-gray-500">{tr.invoice}</dt><dd className="font-mono text-gray-900 dark:text-white">{retour.facture?.numero ?? tr.noInvoice}</dd></div>
          <div><dt className="text-xs text-gray-500">{tr.date}</dt><dd className="text-gray-900 dark:text-white">{formatDateDocument(retour.date_retour, language)}</dd></div>
          <div><dt className="text-xs text-gray-500">{tr.creditNote}</dt><dd className="font-mono text-gray-900 dark:text-white">
            {retour.avoir ? <Link to={`/notes-credit?recherche=${encodeURIComponent(retour.avoir.numero ?? retour.numero ?? '')}`} className="text-indigo-600 hover:underline">{retour.avoir.numero ?? tr.draft}</Link> : '—'}
          </dd></div>
        </dl>

        <div className="overflow-x-auto">
          <table className="w-full text-sm min-w-[36rem]">
            <thead>
              <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300 border-b dark:border-gray-700">
                <th className="py-2 pr-3 font-medium">{tr.product}</th>
                <th className="py-2 pr-3 font-medium text-right">{tr.quantity}</th>
                <th className="py-2 pr-3 font-medium">{tr.treatment}</th>
                <th className="py-2 font-medium">{tr.reason}</th>
              </tr>
            </thead>
            <tbody>
              {(retour.lignes ?? []).map(l => (
                <tr key={l.id} className="border-b dark:border-gray-700">
                  <td className="py-1.5 pr-3 text-gray-900 dark:text-white">
                    {l.produit?.description ?? `#${l.produit_id}`}
                    {l.produit?.numero && <span className="block text-xs font-mono text-gray-500">{l.produit.numero}</span>}
                  </td>
                  <td className="py-1.5 pr-3 text-right text-gray-700 dark:text-gray-300">{l.quantite}</td>
                  <td className="py-1.5 pr-3">
                    <span className={`px-2 py-0.5 text-xs rounded-full whitespace-nowrap ${COULEURS_TRAITEMENT[l.traitement] ?? ''}`}>{tr.treatments[l.traitement]}</span>
                    {l.secteur && <span className="block text-xs text-gray-500 mt-0.5">{l.secteur.code} — {l.secteur.nom}</span>}
                  </td>
                  <td className="py-1.5 text-gray-700 dark:text-gray-300">{l.raison?.libelle ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {retour.statut === 'traite' && (retour.lignes ?? []).some(l => l.traitement === 'remise_en_stock') && (
          <p className="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1"><FileText size={14} /> {tr.stockHelp}</p>
        )}
        {retour.notes && <p className="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-line"><span className="font-medium">{tr.notes} : </span>{retour.notes}</p>}
      </div>
    </div>
  );
}

/** Choix de la facture dont la marchandise revient : factures émises, payées ou non, créditées comprises. */
function ChoixFacture({ onClose, onChoisir }: { onClose: () => void; onChoisir: (factureId: number) => void }) {
  const { t, language } = useLanguage();
  const tr = t.customerReturns;
  // Chaque facture dans sa devise.
  const montantDoc = useMontantDocument();
  const [texte, setTexte] = useState('');
  const [factures, setFactures] = useState<Facture[] | null>(null);

  useEffect(() => {
    let actif = true;
    const minuterie = setTimeout(() => {
      getFactures({ recherche: texte.trim() || undefined, statuts: 'emise,partiellement_payee,payee,creditee', per_page: 10 })
        .then(r => { if (actif) setFactures(r.data ?? []); }, () => { if (actif) setFactures([]); });
    }, 250);
    return () => { actif = false; clearTimeout(minuterie); };
  }, [texte]);

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="choix-facture-retour-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="choix-facture-retour-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tr.chooseInvoice}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tr.close}><X size={18} /></button>
        </div>
        <p className="text-sm text-gray-500 dark:text-gray-400">{tr.chooseInvoiceHelp}</p>
        <input type="search" autoFocus value={texte} onChange={e => setTexte(e.target.value)} placeholder={tr.searchInvoice} aria-label={tr.searchInvoice} className={classeChamp} />
        {factures === null && <p className="text-sm text-gray-500">{tr.loading}</p>}
        {factures !== null && factures.length === 0 && <p className="text-sm text-gray-500">{tr.noInvoiceFound}</p>}
        <ul className="divide-y dark:divide-gray-700 max-h-80 overflow-y-auto">
          {(factures ?? []).map(f => (
            <li key={f.id}>
              <button type="button" onClick={() => onChoisir(f.id)} className="w-full text-left px-2 py-2 hover:bg-teal-50 dark:hover:bg-teal-900/30 rounded flex justify-between gap-3">
                <span>
                  <span className="font-mono text-gray-900 dark:text-white">{f.numero}</span>
                  <span className="block text-xs text-gray-500">{f.client?.raison_sociale} · {formatDateDocument(f.date_facture, language)}</span>
                </span>
                <span className="text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">{montantDoc(f.montant_ttc, f.devise)}</span>
              </button>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
