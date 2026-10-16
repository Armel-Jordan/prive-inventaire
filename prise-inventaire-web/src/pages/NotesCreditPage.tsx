import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Banknote, Eye, Pencil, Plus, ReceiptText, Send, Trash2, Undo2, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { formatDateDocument, useMontantDocument } from '@/lib/documents';
import { getFactures, type Facture, type PageResultats } from '@/services/api';
import { emettreAvoir, getAvoir, getAvoirs, STATUTS_AVOIR, supprimerAvoir, type Avoir } from '@/services/avoirs';
import EditeurNoteCredit from '@/components/avoirs/EditeurNoteCredit';
import ReglerCredit from '@/components/avoirs/ReglerCredit';
import BoutonPdf from '@/components/BoutonPdf';
import BoutonCourriel from '@/components/BoutonCourriel';
import RecapitulatifTaxes from '@/components/RecapitulatifTaxes';
import Pagination from '@/components/Pagination';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const COULEURS: Record<string, string> = {
  brouillon: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
  emis: 'bg-blue-100 text-blue-800',
  solde: 'bg-green-100 text-green-800',
};
const nomPdf = (avoir: Avoir) => `note-credit-${avoir.numero ?? `brouillon-${avoir.id}`}.pdf`;

/**
 * Notes de crédit (plan de parité fripv2, phase 1.5) : liste, détail, brouillons à modifier ou à émettre, crédit
 * disponible à imputer sur une facture du client ou à lui rembourser. Une note se crée depuis une facture émise
 * (« Créditer »), ici ou dans la liste des factures.
 */
export default function NotesCreditPage() {
  const { t, language } = useLanguage();
  const tc = t.creditNotes;
  // Montants dans la devise de chaque document (celle de l'entreprise, ou celle d'un client étranger).
  const montantDoc = useMontantDocument();
  const montant = (v: number | string | null | undefined, deviseDoc?: string | null) => montantDoc(v, deviseDoc);
  const { peut } = usePermissions();
  const { toasts, toast, dismiss } = useToast();
  // Lien de la recherche globale : /notes-credit?recherche=AV-00001.
  const [parametres] = useSearchParams();
  const [recherche, setRecherche] = useState(parametres.get('recherche') ?? '');
  const [terme, setTerme] = useState(recherche.trim());
  const [statut, setStatut] = useState('');
  const [page, setPage] = useState(1);
  const [resultat, setResultat] = useState<PageResultats<Avoir> | null>(null);
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);
  const [detail, setDetail] = useState<Avoir | null>(null);
  const [edition, setEdition] = useState<{ factureId: number; brouillon: Avoir | null } | null>(null);
  const [reglement, setReglement] = useState<{ avoir: Avoir; mode: 'imputer' | 'rembourser' } | null>(null);
  const [choixFacture, setChoixFacture] = useState(false);

  useEffect(() => {
    const minuterie = setTimeout(() => { setTerme(recherche.trim()); setPage(1); }, 300);
    return () => clearTimeout(minuterie);
  }, [recherche]);

  useEffect(() => {
    let actif = true;
    getAvoirs({ statut: statut || undefined, recherche: terme || undefined, page }).then(
      r => { if (actif) { setResultat(r); setErreur(''); } },
      e => { if (actif) setErreur(messageErreur(e, tc.error)); },
    );
    return () => { actif = false; };
  }, [statut, terme, page, version, tc.error]);

  const recharger = () => setVersion(v => v + 1);
  const echec = (e: unknown) => toast(messageErreur(e, tc.error), 'error');

  async function voir(id: number) {
    try { setDetail(await getAvoir(id)); } catch (e) { echec(e); }
  }

  async function modifier(id: number) {
    try {
      const avoir = await getAvoir(id);
      setEdition({ factureId: avoir.facture_id, brouillon: avoir });
    } catch (e) { echec(e); }
  }

  async function emettre(avoir: Avoir) {
    if (!confirm(tc.confirmIssue)) return;
    try {
      const emis = await emettreAvoir(avoir.id);
      toast(tc.issued.replace('{numero}', emis.numero ?? ''));
      recharger();
    } catch (e) { echec(e); }
  }

  async function supprimer(avoir: Avoir) {
    if (!confirm(tc.confirmDelete)) return;
    try {
      await supprimerAvoir(avoir.id);
      toast(tc.deleted);
      recharger();
    } catch (e) { echec(e); }
  }

  const statutBadge = (valeur: string) => (
    <span className={`px-2 py-0.5 text-xs rounded-full whitespace-nowrap ${COULEURS[valeur] ?? 'bg-gray-100'}`}>{tc.statuses[valeur] ?? valeur}</span>
  );

  const boutonsReglement = (avoir: Avoir, taille = 18) => avoir.statut === 'emis' && avoir.credit_disponible > 0 && peut('avoirs', 'regler') && (
    <>
      <button type="button" onClick={() => setReglement({ avoir, mode: 'imputer' })} title={tc.apply} aria-label={`${tc.apply} — ${avoir.numero}`}
        className="p-1 text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 rounded"><Undo2 size={taille} /></button>
      <button type="button" onClick={() => setReglement({ avoir, mode: 'rembourser' })} title={tc.refund} aria-label={`${tc.refund} — ${avoir.numero}`}
        className="p-1 text-green-600 hover:bg-green-50 dark:hover:bg-green-900/30 rounded"><Banknote size={taille} /></button>
    </>
  );

  const avoirs = resultat?.data ?? [];

  return (
    <div className="p-4 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><ReceiptText className="text-purple-600" /> {tc.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tc.subtitle}</p>
        </div>
        {peut('avoirs', 'creer') && (
          <button type="button" onClick={() => setChoixFacture(true)} className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 text-sm">
            <Plus size={18} /> {tc.newCreditNote}
          </button>
        )}
      </div>

      <div className="flex flex-wrap items-end gap-2 mb-4">
        <label className="text-xs text-gray-500 dark:text-gray-400 flex-1 min-w-[12rem]">{tc.search}
          <input type="search" value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={tc.searchHelp} className={classeChamp} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400 w-48">{tc.status}
          <select value={statut} onChange={e => { setStatut(e.target.value); setPage(1); }} className={classeChamp}>
            <option value="">{tc.allStatuses}</option>
            {STATUTS_AVOIR.map(s => <option key={s} value={s}>{tc.statuses[s]}</option>)}
          </select>
        </label>
      </div>

      {erreur && <p role="alert" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
        <table className="w-full text-sm min-w-[52rem]">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-3 py-2 font-medium">{tc.number}</th>
              <th className="px-3 py-2 font-medium">{tc.invoice}</th>
              <th className="px-3 py-2 font-medium">{tc.customer}</th>
              <th className="px-3 py-2 font-medium">{tc.date}</th>
              <th className="px-3 py-2 font-medium text-right">{tc.amount}</th>
              <th className="px-3 py-2 font-medium text-right">{tc.available}</th>
              <th className="px-3 py-2 font-medium text-center">{tc.status}</th>
              <th className="px-3 py-2 font-medium text-center">{tc.actions}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
            {resultat === null && !erreur && <tr><td colSpan={8} className="px-4 py-6 text-center text-gray-500">{tc.loading}</td></tr>}
            {resultat !== null && avoirs.length === 0 && <tr><td colSpan={8} className="px-4 py-6 text-center text-gray-500">{terme || statut ? tc.noResult : tc.empty}</td></tr>}
            {avoirs.map(avoir => (
              <tr key={avoir.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                <td className="px-3 py-2 font-mono text-gray-900 dark:text-white whitespace-nowrap">{avoir.numero ?? <span className="italic text-gray-500 font-sans">{tc.draft}</span>}</td>
                <td className="px-3 py-2 font-mono text-gray-700 dark:text-gray-300 whitespace-nowrap">{avoir.facture?.numero}</td>
                <td className="px-3 py-2 text-gray-700 dark:text-gray-300">
                  {avoir.client?.raison_sociale}
                  {avoir.motif && <span className="block text-xs text-gray-500 dark:text-gray-400">{avoir.motif}</span>}
                </td>
                <td className="px-3 py-2 text-gray-700 dark:text-gray-300 whitespace-nowrap">{formatDateDocument(avoir.date_avoir, language)}</td>
                <td className="px-3 py-2 text-right font-medium text-gray-900 dark:text-white whitespace-nowrap">{montant(avoir.montant_ttc, avoir.devise)}</td>
                <td className={`px-3 py-2 text-right whitespace-nowrap ${avoir.credit_disponible > 0 ? 'text-indigo-700 dark:text-indigo-300 font-medium' : 'text-gray-400'}`}>{avoir.statut === 'brouillon' ? '—' : montant(avoir.credit_disponible, avoir.devise)}</td>
                <td className="px-3 py-2 text-center">{statutBadge(avoir.statut)}</td>
                <td className="px-3 py-2">
                  <div className="flex justify-center gap-1">
                    <button type="button" onClick={() => { void voir(avoir.id); }} title={tc.view} aria-label={`${tc.view} — ${avoir.numero ?? tc.draft}`}
                      className="p-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded"><Eye size={18} /></button>
                    <BoutonPdf chemin={`/avoirs/${avoir.id}/pdf`} nomFichier={nomPdf(avoir)} />
                    {avoir.statut !== 'brouillon' && <BoutonCourriel type="avoir" documentId={avoir.id} />}
                    {avoir.statut === 'brouillon' && (
                      <>
                        {peut('avoirs', 'modifier') && (
                          <button type="button" onClick={() => { void modifier(avoir.id); }} title={tc.edit} aria-label={tc.edit}
                            className="p-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded"><Pencil size={18} /></button>
                        )}
                        {peut('avoirs', 'emettre') && (
                          <button type="button" onClick={() => { void emettre(avoir); }} title={tc.issue} aria-label={tc.issue}
                            className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded"><Send size={18} /></button>
                        )}
                        {peut('avoirs', 'supprimer') && (
                          <button type="button" onClick={() => { void supprimer(avoir); }} title={tc.delete} aria-label={tc.delete}
                            className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><Trash2 size={18} /></button>
                        )}
                      </>
                    )}
                    {boutonsReglement(avoir)}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {resultat && <Pagination page={page} dernierePage={resultat.last_page ?? 1} total={resultat.total ?? 0} onPage={setPage} />}
      </div>

      {detail && (
        <DetailNoteCredit avoir={detail} onClose={() => setDetail(null)} actions={boutonsReglement(detail, 16)} />
      )}

      {choixFacture && (
        <ChoixFacture onClose={() => setChoixFacture(false)} onChoisir={id => { setChoixFacture(false); setEdition({ factureId: id, brouillon: null }); }} />
      )}

      {edition && (
        <EditeurNoteCredit
          factureId={edition.factureId}
          brouillon={edition.brouillon}
          onClose={() => setEdition(null)}
          onEnregistre={(avoir, emis) => {
            setEdition(null);
            toast(emis ? tc.issued.replace('{numero}', avoir.numero ?? '') : tc.saved);
            recharger();
          }}
        />
      )}

      {reglement && (
        <ReglerCredit
          avoir={reglement.avoir}
          mode={reglement.mode}
          onClose={() => setReglement(null)}
          onRegle={(_avoir, message) => {
            setReglement(null);
            setDetail(null);
            toast(message);
            recharger();
          }}
        />
      )}

      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}

/** Détail d'une note : lignes, totaux (détail par taxe), imputations et remboursements. */
function DetailNoteCredit({ avoir, onClose, actions }: { avoir: Avoir; onClose: () => void; actions: React.ReactNode }) {
  const { t, language } = useLanguage();
  const tc = t.creditNotes;
  const montantDoc = useMontantDocument();
  // Dans la devise de la note (celle de sa facture).
  const montant = (v: number | string | null | undefined) => montantDoc(v, avoir.devise);
  const ligneTotal = (libelle: string, valeur: number | string, classe = '') => (
    <div className={`flex justify-between gap-4 ${classe}`}><span className="text-gray-500 dark:text-gray-400">{libelle}</span><span className="text-gray-900 dark:text-white">{montant(valeur)}</span></div>
  );

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="detail-avoir-titre" className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-3xl max-h-[94vh] overflow-y-auto space-y-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 id="detail-avoir-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tc.detailTitle.replace('{numero}', avoir.numero ?? tc.draft)}</h2>
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {tc.statuses[avoir.statut]}
              {avoir.emis_le && ` · ${tc.issuedOn.replace('{date}', formatDateDocument(avoir.emis_le, language))}`}
              {avoir.auteur && ` · ${tc.createdBy.replace('{nom}', avoir.auteur.nom)}`}
            </p>
          </div>
          <div className="flex items-center gap-1">
            {actions}
            <BoutonPdf chemin={`/avoirs/${avoir.id}/pdf`} nomFichier={nomPdf(avoir)} taille={16} />
            {avoir.statut !== 'brouillon' && <BoutonCourriel type="avoir" documentId={avoir.id} taille={16} />}
            <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tc.close}><X size={18} /></button>
          </div>
        </div>

        <dl className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
          <div><dt className="text-xs text-gray-500">{tc.customer}</dt><dd className="text-gray-900 dark:text-white">{avoir.client?.raison_sociale}</dd></div>
          <div><dt className="text-xs text-gray-500">{tc.invoice}</dt><dd className="font-mono text-gray-900 dark:text-white">{avoir.facture?.numero}</dd></div>
          <div><dt className="text-xs text-gray-500">{tc.date}</dt><dd className="text-gray-900 dark:text-white">{formatDateDocument(avoir.date_avoir, language)}</dd></div>
          <div><dt className="text-xs text-gray-500">{tc.reason}</dt><dd className="text-gray-900 dark:text-white">
            {avoir.motif || '—'}
            {avoir.retour?.numero && (
              <Link to={`/retours-clients?recherche=${encodeURIComponent(avoir.retour.numero)}`} className="block text-xs text-teal-600 hover:underline">
                {t.customerReturns.fromReturn.replace('{numero}', avoir.retour.numero)}
              </Link>
            )}
          </dd></div>
        </dl>

        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300 border-b dark:border-gray-700">
                <th className="py-2 pr-3 font-medium">{tc.product}</th>
                <th className="py-2 pr-3 font-medium text-right">{tc.qty}</th>
                <th className="py-2 pr-3 font-medium text-right">{tc.unitPrice}</th>
                <th className="py-2 font-medium text-right">{tc.lineTotal}</th>
              </tr>
            </thead>
            <tbody>
              {(avoir.lignes ?? []).map(l => (
                <tr key={l.id} className="border-b dark:border-gray-700">
                  <td className="py-1.5 pr-3 text-gray-900 dark:text-white">{l.produit?.description ?? `#${l.produit_id}`}</td>
                  <td className="py-1.5 pr-3 text-right text-gray-700 dark:text-gray-300">{l.quantite}</td>
                  <td className="py-1.5 pr-3 text-right whitespace-nowrap text-gray-700 dark:text-gray-300">
                    {montant(l.prix_unitaire_ht)}{Number(l.remise_ligne) > 0 && <span className="block text-xs text-gray-500">{tc.discount} {Number(l.remise_ligne)} %</span>}
                  </td>
                  <td className="py-1.5 text-right whitespace-nowrap text-gray-900 dark:text-white">{montant(l.montant_ht)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="ml-auto max-w-sm space-y-1 text-sm">
          {ligneTotal(tc.totalHt, avoir.montant_ht)}
          {avoir.recapitulatif_taxes && avoir.recapitulatif_taxes.length > 0
            ? <RecapitulatifTaxes recapitulatif={avoir.recapitulatif_taxes} devise={avoir.devise} />
            : ligneTotal(tc.taxes, avoir.montant_tva)}
          {ligneTotal(tc.amount, avoir.montant_ttc, 'font-semibold text-base')}
          {avoir.statut !== 'brouillon' && (
            <>
              {ligneTotal(tc.applied, avoir.montant_impute)}
              {Number(avoir.montant_rembourse) > 0 && ligneTotal(tc.refunded, avoir.montant_rembourse)}
              {ligneTotal(tc.available, avoir.credit_disponible, 'font-medium')}
            </>
          )}
        </div>

        {avoir.statut !== 'brouillon' && (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <div>
              <h3 className="font-semibold text-gray-800 dark:text-gray-200 mb-1">{tc.applications}</h3>
              {(avoir.imputations ?? []).length === 0 ? <p className="text-gray-500">{tc.none}</p> : (
                <ul className="divide-y dark:divide-gray-700">
                  {avoir.imputations!.map(i => (
                    <li key={i.id} className="flex justify-between gap-2 py-1">
                      <span className="text-gray-700 dark:text-gray-300">{formatDateDocument(i.date_imputation, language)} · <span className="font-mono">{i.facture?.numero}</span></span>
                      <span className="text-gray-900 dark:text-white whitespace-nowrap">{montant(i.montant)}</span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
            <div>
              <h3 className="font-semibold text-gray-800 dark:text-gray-200 mb-1">{tc.refunds}</h3>
              {(avoir.remboursements ?? []).length === 0 ? <p className="text-gray-500">{tc.none}</p> : (
                <ul className="divide-y dark:divide-gray-700">
                  {avoir.remboursements!.map(r => (
                    <li key={r.id} className="flex justify-between gap-2 py-1">
                      <span className="text-gray-700 dark:text-gray-300">{formatDateDocument(r.date_remboursement, language)} · {tc.modes[r.mode ?? ''] ?? r.mode}{r.reference && ` · ${r.reference}`}</span>
                      <span className="text-gray-900 dark:text-white whitespace-nowrap">{montant(r.montant)}</span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        )}

        {avoir.notes && <p className="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-line"><span className="font-medium">{tc.notes} : </span>{avoir.notes}</p>}
      </div>
    </div>
  );
}

/** Choix de la facture à créditer : factures émises, payées ou non, par numéro ou client. */
function ChoixFacture({ onClose, onChoisir }: { onClose: () => void; onChoisir: (factureId: number) => void }) {
  const { t, language } = useLanguage();
  const tc = t.creditNotes;
  // Chaque facture dans sa devise.
  const montantDoc = useMontantDocument();
  const [texte, setTexte] = useState('');
  const [factures, setFactures] = useState<Facture[] | null>(null);

  useEffect(() => {
    let actif = true;
    const minuterie = setTimeout(() => {
      getFactures({ recherche: texte.trim() || undefined, statuts: 'emise,partiellement_payee,payee', per_page: 10 })
        .then(r => { if (actif) setFactures(r.data ?? []); }, () => { if (actif) setFactures([]); });
    }, 250);
    return () => { actif = false; clearTimeout(minuterie); };
  }, [texte]);

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="choix-facture-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="choix-facture-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tc.chooseInvoice}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tc.close}><X size={18} /></button>
        </div>
        <p className="text-sm text-gray-500 dark:text-gray-400">{tc.chooseInvoiceHelp}</p>
        <input type="search" autoFocus value={texte} onChange={e => setTexte(e.target.value)} placeholder={tc.searchInvoice} aria-label={tc.searchInvoice} className={classeChamp} />
        {factures === null && <p className="text-sm text-gray-500">{tc.loading}</p>}
        {factures !== null && factures.length === 0 && <p className="text-sm text-gray-500">{tc.noInvoice}</p>}
        <ul className="divide-y dark:divide-gray-700 max-h-80 overflow-y-auto">
          {(factures ?? []).map(f => (
            <li key={f.id}>
              <button type="button" onClick={() => onChoisir(f.id)} className="w-full text-left px-2 py-2 hover:bg-purple-50 dark:hover:bg-purple-900/30 rounded flex justify-between gap-3">
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
