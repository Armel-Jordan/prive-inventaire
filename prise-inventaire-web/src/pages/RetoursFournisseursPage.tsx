import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { CheckCircle2, Eye, HandCoins, PackageMinus, Pencil, Plus, Send, Timer, Trash2, Truck, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, formatDateDocument, useMontantDocument } from '@/lib/documents';
import {
  expedierRetourFournisseur, getRetourFournisseur, getRetoursFournisseurs, STATUTS_RETOUR_FOURNISSEUR, supprimerRetourFournisseur,
  TYPES_RETOUR_FOURNISSEUR, type ResumeRetoursFournisseurs, type RetourFournisseur,
} from '@/services/retoursFournisseurs';
import type { PageResultats } from '@/services/api';
import EditeurRetourFournisseur, { type OrigineRetourFournisseur } from '@/components/retoursFournisseurs/EditeurRetourFournisseur';
import CloturerRetourFournisseur from '@/components/retoursFournisseurs/CloturerRetourFournisseur';
import BoutonPdf from '@/components/BoutonPdf';
import BoutonCourriel from '@/components/BoutonCourriel';
import Pagination from '@/components/Pagination';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const COULEURS: Record<string, string> = {
  brouillon: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
  expedie: 'bg-orange-100 text-orange-800',
  clos: 'bg-green-100 text-green-800',
};
const nomPdf = (retour: RetourFournisseur) => `retour-fournisseur-${retour.numero ?? `brouillon-${retour.id}`}.pdf`;

/**
 * Retours fournisseurs et avoirs (plan de parité fripv2, phase 3.4) : marchandise renvoyée à un fournisseur (reçue
 * d'une de ses commandes, rapportée par un client, ou sans origine), expédiée (ce qui était en stock sort de son
 * secteur) puis close quand il a répondu : crédit obtenu, remplacement reçu, contenant rendu.
 */
export default function RetoursFournisseursPage() {
  const { t, language } = useLanguage();
  const tr = t.supplierReturns;
  const { peut } = usePermissions();
  const montantDoc = useMontantDocument();
  const { toasts, toast, dismiss } = useToast();
  // Liens : recherche globale (?recherche=RF-00001), retour d'une commande (?commande=12) ou d'un retour client (?retour_client=3).
  const [parametres, setParametres] = useSearchParams();
  const [recherche, setRecherche] = useState(parametres.get('recherche') ?? '');
  const [terme, setTerme] = useState(recherche.trim());
  const [statut, setStatut] = useState('');
  const [type, setType] = useState('');
  const [page, setPage] = useState(1);
  const [resultat, setResultat] = useState<(PageResultats<RetourFournisseur> & { resume: ResumeRetoursFournisseurs }) | null>(null);
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);
  const [detail, setDetail] = useState<RetourFournisseur | null>(null);
  const [cloture, setCloture] = useState<RetourFournisseur | null>(null);
  const [edition, setEdition] = useState<{ origine: OrigineRetourFournisseur; brouillon: RetourFournisseur | null } | null>(() => {
    const commande = Number(parametres.get('commande'));
    const retourClient = Number(parametres.get('retour_client'));
    if (commande > 0) return { origine: { commandeId: commande }, brouillon: null };
    if (retourClient > 0) return { origine: { retourClientId: retourClient }, brouillon: null };
    return null;
  });

  useEffect(() => {
    // Les paramètres d'origine ouvrent l'éditeur une seule fois.
    if (parametres.has('commande') || parametres.has('retour_client')) {
      const suivants = new URLSearchParams(parametres);
      suivants.delete('commande');
      suivants.delete('retour_client');
      setParametres(suivants, { replace: true });
    }
  }, [parametres, setParametres]);

  useEffect(() => {
    const minuterie = setTimeout(() => { setTerme(recherche.trim()); setPage(1); }, 300);
    return () => clearTimeout(minuterie);
  }, [recherche]);

  useEffect(() => {
    let actif = true;
    getRetoursFournisseurs({ statut: statut || undefined, type: type || undefined, recherche: terme || undefined, page }).then(
      r => { if (actif) { setResultat(r); setErreur(''); } },
      e => { if (actif) setErreur(messageErreur(e, tr.error)); },
    );
    return () => { actif = false; };
  }, [statut, type, terme, page, version, tr.error]);

  const recharger = () => setVersion(v => v + 1);
  const echec = (e: unknown) => toast(messageErreur(e, tr.error), 'error');

  async function voir(id: number) {
    try { setDetail(await getRetourFournisseur(id)); } catch (e) { echec(e); }
  }

  async function modifier(id: number) {
    try {
      const retour = await getRetourFournisseur(id);
      setEdition({ origine: null, brouillon: retour });
    } catch (e) { echec(e); }
  }

  async function expedier(retour: RetourFournisseur) {
    if (!confirm(tr.confirmShip)) return;
    try {
      const expedie = await expedierRetourFournisseur(retour.id, aujourdhui());
      toast(tr.shipped.replace('{numero}', expedie.numero ?? ''));
      setDetail(null);
      recharger();
    } catch (e) { echec(e); }
  }

  async function supprimer(retour: RetourFournisseur) {
    if (!confirm(tr.confirmDelete)) return;
    try {
      await supprimerRetourFournisseur(retour.id);
      toast(tr.deleted);
      recharger();
    } catch (e) { echec(e); }
  }

  const statutBadge = (valeur: string) => (
    <span className={`px-2 py-0.5 text-xs rounded-full whitespace-nowrap ${COULEURS[valeur] ?? 'bg-gray-100'}`}>{tr.statuses[valeur] ?? valeur}</span>
  );

  /** Actions d'un retour selon son état et les droits. */
  const actions = (retour: RetourFournisseur, taille = 18) => (
    <>
      <BoutonPdf chemin={`/retours-fournisseurs/${retour.id}/pdf`} nomFichier={nomPdf(retour)} taille={taille} />
      {retour.statut !== 'brouillon' && <BoutonCourriel type="retour_fournisseur" documentId={retour.id} taille={taille} />}
      {retour.statut === 'brouillon' && (
        <>
          {peut('retours_fournisseurs', 'modifier') && (
            <button type="button" onClick={() => { void modifier(retour.id); }} title={tr.edit} aria-label={tr.edit}
              className="p-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded"><Pencil size={taille} /></button>
          )}
          {peut('retours_fournisseurs', 'expedier') && (
            <button type="button" onClick={() => { void expedier(retour); }} title={tr.ship} aria-label={tr.ship}
              className="p-1 text-orange-600 hover:bg-orange-50 dark:hover:bg-orange-900/30 rounded"><Send size={taille} /></button>
          )}
          {peut('retours_fournisseurs', 'supprimer') && (
            <button type="button" onClick={() => { void supprimer(retour); }} title={tr.delete} aria-label={tr.delete}
              className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><Trash2 size={taille} /></button>
          )}
        </>
      )}
      {retour.statut === 'expedie' && peut('retours_fournisseurs', 'cloturer') && (
        <button type="button" onClick={() => setCloture(retour)} title={tr.close_} aria-label={`${tr.close_} — ${retour.numero}`}
          className="p-1 text-green-600 hover:bg-green-50 dark:hover:bg-green-900/30 rounded"><CheckCircle2 size={taille} /></button>
      )}
    </>
  );

  const retours = resultat?.data ?? [];
  const resume = resultat?.resume;
  const carte = (icone: React.ReactNode, libelle: string, valeur: string) => (
    <div className="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-3 flex items-center gap-3">
      {icone}
      <div><p className="text-xs text-gray-500 dark:text-gray-400">{libelle}</p><p className="text-lg font-semibold text-gray-900 dark:text-white">{valeur}</p></div>
    </div>
  );

  return (
    <div className="p-4 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><PackageMinus className="text-orange-600" /> {tr.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tr.subtitle}</p>
        </div>
        {peut('retours_fournisseurs', 'creer') && (
          <button type="button" onClick={() => setEdition({ origine: null, brouillon: null })} className="flex items-center gap-2 bg-orange-600 text-white px-4 py-2 rounded-lg hover:bg-orange-700 text-sm">
            <Plus size={18} /> {tr.newReturn}
          </button>
        )}
      </div>
      <p className="text-xs text-gray-500 dark:text-gray-400 mb-4">{tr.fromElsewhere}</p>

      {resume && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
          {carte(<Timer className="text-orange-600 shrink-0" />, tr.creditsExpected, montantDoc(resume.credits_attendus))}
          {carte(<HandCoins className="text-green-600 shrink-0" />, tr.creditsObtained, montantDoc(resume.credits_obtenus))}
          {carte(<Truck className="text-gray-500 shrink-0" />, tr.inProgress, String(resume.en_cours))}
        </div>
      )}

      <div className="flex flex-wrap items-end gap-2 mb-4">
        <label className="text-xs text-gray-500 dark:text-gray-400 flex-1 min-w-[12rem]">{tr.search}
          <input type="search" value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={tr.searchHelp} className={classeChamp} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400 w-44">{tr.type}
          <select value={type} onChange={e => { setType(e.target.value); setPage(1); }} className={classeChamp}>
            <option value="">{tr.allTypes}</option>
            {TYPES_RETOUR_FOURNISSEUR.map(ty => <option key={ty} value={ty}>{tr.types[ty]}</option>)}
          </select>
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400 w-44">{tr.status}
          <select value={statut} onChange={e => { setStatut(e.target.value); setPage(1); }} className={classeChamp}>
            <option value="">{tr.allStatuses}</option>
            {STATUTS_RETOUR_FOURNISSEUR.map(s => <option key={s} value={s}>{tr.statuses[s]}</option>)}
          </select>
        </label>
      </div>

      {erreur && <p role="alert" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
        <table className="w-full text-sm min-w-[60rem]">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-3 py-2 font-medium">{tr.number}</th>
              <th className="px-3 py-2 font-medium">{tr.supplier}</th>
              <th className="px-3 py-2 font-medium">{tr.origin}</th>
              <th className="px-3 py-2 font-medium">{tr.type}</th>
              <th className="px-3 py-2 font-medium">{tr.date}</th>
              <th className="px-3 py-2 font-medium text-right">{tr.quantity}</th>
              <th className="px-3 py-2 font-medium text-right">{tr.amount}</th>
              <th className="px-3 py-2 font-medium text-center">{tr.status}</th>
              <th className="px-3 py-2 font-medium text-center">{tr.actions}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
            {resultat === null && !erreur && <tr><td colSpan={9} className="px-4 py-6 text-center text-gray-500">{tr.loading}</td></tr>}
            {resultat !== null && retours.length === 0 && <tr><td colSpan={9} className="px-4 py-6 text-center text-gray-500">{terme || statut || type ? tr.noResult : tr.empty}</td></tr>}
            {retours.map(retour => (
              <tr key={retour.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                <td className="px-3 py-2 font-mono text-gray-900 dark:text-white whitespace-nowrap">
                  {retour.numero ?? <span className="italic text-gray-500 font-sans">{tr.draft}</span>}
                  {retour.numero_rma && <span className="block text-xs text-gray-500">RMA {retour.numero_rma}</span>}
                </td>
                <td className="px-3 py-2 text-gray-700 dark:text-gray-300">{retour.fournisseur?.raison_sociale}</td>
                <td className="px-3 py-2 font-mono text-gray-700 dark:text-gray-300 whitespace-nowrap">
                  {retour.commande?.numero ?? retour.retour_client?.numero ?? <span className="font-sans text-gray-400">—</span>}
                </td>
                <td className="px-3 py-2 text-gray-700 dark:text-gray-300 whitespace-nowrap">{tr.types[retour.type]}</td>
                <td className="px-3 py-2 text-gray-700 dark:text-gray-300 whitespace-nowrap">{formatDateDocument(retour.date_retour, language)}</td>
                <td className="px-3 py-2 text-right text-gray-900 dark:text-white">{Number(retour.quantite_totale ?? 0)}</td>
                <td className="px-3 py-2 text-right whitespace-nowrap">
                  <span className="font-medium text-gray-900 dark:text-white">{montantDoc(retour.montant, retour.devise)}</span>
                  {retour.montant_credite !== null && (
                    <span className="block text-xs text-green-700 dark:text-green-400">{tr.obtained} {montantDoc(retour.montant_credite, retour.devise)}</span>
                  )}
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

      {detail && <DetailRetourFournisseur retour={detail} onClose={() => setDetail(null)} actions={actions(detail, 16)} />}

      {edition && (
        <EditeurRetourFournisseur
          origine={edition.origine}
          brouillon={edition.brouillon}
          onClose={() => setEdition(null)}
          onEnregistre={(retour, expedie) => {
            setEdition(null);
            toast(expedie ? tr.shipped.replace('{numero}', retour.numero ?? '') : tr.saved);
            recharger();
          }}
        />
      )}

      {cloture && (
        <CloturerRetourFournisseur
          retour={cloture}
          onClose={() => setCloture(null)}
          onClos={retour => {
            setCloture(null);
            setDetail(null);
            toast(tr.closed.replace('{numero}', retour.numero ?? ''));
            recharger();
          }}
        />
      )}

      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}

/** Détail d'un retour : origine, dates, lignes (secteur, raison), crédit obtenu ou remplacement reçu. */
function DetailRetourFournisseur({ retour, onClose, actions }: { retour: RetourFournisseur; onClose: () => void; actions: React.ReactNode }) {
  const { t, language } = useLanguage();
  const tr = t.supplierReturns;
  const montantDoc = useMontantDocument();
  const montant = (v: number | string | null | undefined) => montantDoc(v, retour.devise);
  const date = (v: string | null) => (v ? formatDateDocument(v, language) : '—');

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="detail-retour-fournisseur-titre" className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-3xl max-h-[94vh] overflow-y-auto space-y-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 id="detail-retour-fournisseur-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tr.detailTitle.replace('{numero}', retour.numero ?? tr.draft)}</h2>
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {tr.types[retour.type]} · {tr.statuses[retour.statut]}
              {retour.auteur && ` · ${tr.createdBy.replace('{nom}', retour.auteur.nom)}`}
            </p>
          </div>
          <div className="flex items-center gap-1">
            {actions}
            <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tr.close}><X size={18} /></button>
          </div>
        </div>

        <dl className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
          <div><dt className="text-xs text-gray-500">{tr.supplier}</dt><dd className="text-gray-900 dark:text-white">{retour.fournisseur?.raison_sociale}</dd></div>
          <div><dt className="text-xs text-gray-500">{tr.origin}</dt><dd className="font-mono text-gray-900 dark:text-white">
            {retour.commande ? <Link to={`/commandes-fournisseur?recherche=${encodeURIComponent(retour.commande.numero)}`} className="text-orange-700 hover:underline">{retour.commande.numero}</Link>
              : retour.retour_client ? <Link to={`/retours-clients?recherche=${encodeURIComponent(retour.retour_client.numero ?? '')}`} className="text-teal-700 hover:underline">{retour.retour_client.numero}</Link> : '—'}
          </dd></div>
          <div><dt className="text-xs text-gray-500">{tr.rma}</dt><dd className="text-gray-900 dark:text-white">{retour.numero_rma || '—'}</dd></div>
          <div><dt className="text-xs text-gray-500">{tr.date}</dt><dd className="text-gray-900 dark:text-white">{date(retour.date_retour)}</dd></div>
          <div><dt className="text-xs text-gray-500">{tr.shippedOn}</dt><dd className="text-gray-900 dark:text-white">{date(retour.date_expedition)}{retour.expediteur && <span className="block text-xs text-gray-500">{retour.expediteur.nom}</span>}</dd></div>
          <div><dt className="text-xs text-gray-500">{tr.closedOn}</dt><dd className="text-gray-900 dark:text-white">{date(retour.date_cloture)}{retour.operateur && <span className="block text-xs text-gray-500">{retour.operateur.nom}</span>}</dd></div>
          {retour.type === 'remboursement' && (
            <div className="col-span-2"><dt className="text-xs text-gray-500">{tr.creditObtained}</dt><dd className="text-gray-900 dark:text-white">
              {retour.montant_credite !== null ? montant(retour.montant_credite) : '—'}{retour.reference_avoir && ` · ${retour.reference_avoir}`}
            </dd></div>
          )}
          {retour.type === 'remplacement' && retour.secteur_remplacement && (
            <div className="col-span-2"><dt className="text-xs text-gray-500">{tr.replacementSector}</dt><dd className="text-gray-900 dark:text-white">{retour.secteur_remplacement.code} — {retour.secteur_remplacement.nom}</dd></div>
          )}
        </dl>

        <div className="overflow-x-auto">
          <table className="w-full text-sm min-w-[40rem]">
            <thead>
              <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300 border-b dark:border-gray-700">
                <th className="py-2 pr-3 font-medium">{tr.product}</th>
                <th className="py-2 pr-3 font-medium text-right">{tr.quantity}</th>
                <th className="py-2 pr-3 font-medium text-right">{tr.unitPrice}</th>
                <th className="py-2 pr-3 font-medium">{tr.fromSector}</th>
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
                  <td className="py-1.5 pr-3 text-right whitespace-nowrap text-gray-700 dark:text-gray-300">{montant(l.prix_unitaire)}</td>
                  <td className="py-1.5 pr-3 text-gray-700 dark:text-gray-300">{l.secteur ? `${l.secteur.code} — ${l.secteur.nom}` : <span className="text-gray-400">{tr.notInStock}</span>}</td>
                  <td className="py-1.5 text-gray-700 dark:text-gray-300">{l.raison?.libelle ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="text-right text-sm"><span className="text-gray-500">{retour.type === 'remboursement' ? tr.expectedCredit : tr.value}</span>{' '}
          <span className="font-bold text-gray-900 dark:text-white">{montant(retour.montant)}</span></p>
        {retour.notes && <p className="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-line"><span className="font-medium">{tr.notes} : </span>{retour.notes}</p>}
      </div>
    </div>
  );
}
