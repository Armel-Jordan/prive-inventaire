import { useCallback, useEffect, useMemo, useState } from 'react';
import { Ban, Pencil, X } from 'lucide-react';
import BoutonPdf from '@/components/BoutonPdf';
import CoutsAnnexes from '@/components/receptions/CoutsAnnexes';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { fraisEnSaisie, fraisPourApi, type FraisSaisi } from '@/lib/coutsAnnexes';
import { dateSaisie, formatDateDocument, formatDateHeure, useCodeDevise, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { getFournisseursActifs, type Fournisseur } from '@/services/api';
import { annulerReception, getReceptionDocument, modifierReception, type ReceptionDocument } from '@/services/receptions';

const classeChamp = 'w-full px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/** Badge du statut d'une réception. */
export function BadgeStatutReception({ statut, libelle }: { statut: string; libelle: string }) {
  return (
    <span className={`px-2 py-0.5 rounded text-xs font-medium ${statut === 'annulee' ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-200' : 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-200'}`}>
      {libelle}
    </span>
  );
}

/**
 * Détail d'une réception : fournisseur, références, lignes (commande, secteur, lot, quantité, coût, coût de revient),
 * coûts annexes, valeur, bon de réception en PDF. Enregistrée, elle se corrige (date, références, coûts, coûts
 * annexes) ou s'annule, selon les droits.
 */
export default function DetailReception({ id, onClose, onModifiee }: { id: number; onClose: () => void; onModifiee: () => void }) {
  const { t, language } = useLanguage();
  const tr = t.receipts;
  const { peut } = usePermissions();
  const montant = useMontantDocument();
  const codeEntreprise = useCodeDevise();
  const [reception, setReception] = useState<ReceptionDocument | null>(null);
  const [fraisEdition, setFraisEdition] = useState<FraisSaisi[]>([]);
  const [fournisseurs, setFournisseurs] = useState<Fournisseur[]>([]);
  const [erreur, setErreur] = useState('');
  const [enCours, setEnCours] = useState(false);
  const [edition, setEdition] = useState<{ date: string; bonLivraison: string; connaissement: string; notes: string; couts: Record<number, string> } | null>(null);
  const [annulation, setAnnulation] = useState<string | null>(null);

  const charger = useCallback(() => getReceptionDocument(id).then(setReception, e => setErreur(messageErreur(e, tr.error))), [id, tr.error]);
  useEffect(() => { charger(); }, [charger]);

  // Devises des coûts annexes : celle de l'entreprise, puis celles des commandes reçues (avec leur taux).
  const tauxParDevise = useMemo(() => Object.fromEntries((reception?.lignes ?? [])
    .filter(l => l.ligne_commande?.commande?.devise && l.ligne_commande.commande.devise !== codeEntreprise)
    .map(l => [l.ligne_commande?.commande?.devise as string, Number(l.ligne_commande?.commande?.taux_change) || 0])), [reception, codeEntreprise]);

  function corriger() {
    if (!reception) return;
    setFraisEdition(fraisEnSaisie(reception.frais));
    if (fournisseurs.length === 0) getFournisseursActifs().then(f => setFournisseurs(f ?? []), () => {});
    setEdition({
      date: dateSaisie(reception.date_reception),
      bonLivraison: reception.bon_livraison ?? '',
      connaissement: reception.connaissement ?? '',
      notes: reception.notes ?? '',
      couts: Object.fromEntries((reception.lignes ?? []).map(l => [l.id, l.cout_unitaire === null || l.cout_unitaire === undefined ? '' : String(Number(l.cout_unitaire))])),
    });
  }

  async function executer(action: () => Promise<ReceptionDocument>) {
    setEnCours(true);
    setErreur('');
    try {
      setReception(await action());
      setEdition(null);
      setAnnulation(null);
      onModifiee();
    } catch (e) {
      setErreur(messageErreur(e, tr.error));
    } finally {
      setEnCours(false);
    }
  }

  const enregistrerCorrections = () => edition && reception && executer(() => modifierReception(reception.id, {
    date_reception: edition.date,
    bon_livraison: edition.bonLivraison || null,
    connaissement: edition.connaissement || null,
    notes: edition.notes || null,
    lignes: (reception.lignes ?? []).map(l => ({ id: l.id, cout_unitaire: edition.couts[l.id] === '' ? null : Number(edition.couts[l.id]) })),
    frais: fraisPourApi(fraisEdition, codeEntreprise),
  }));

  function annuler() {
    if (!reception || !confirm(tr.confirmCancel.replace('{numero}', reception.numero))) return;
    executer(() => annulerReception(reception.id, annulation ?? ''));
  }

  const enregistree = reception?.statut === 'enregistree';
  const avecFrais = (reception?.montant_frais ?? 0) > 0;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-label={`${tr.title} ${reception?.numero ?? ''}`}
        className="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-5xl max-h-[94vh] overflow-y-auto">
        <div className="flex items-center justify-between gap-3 p-4 border-b dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800 z-10">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-lg font-semibold dark:text-white">{tr.title} {reception?.numero}</h2>
            {reception && <BadgeStatutReception statut={reception.statut} libelle={tr.statuses[reception.statut] ?? reception.statut} />}
            {reception?.facture_fournisseur && (
              <span className="px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200">
                {t.supplierInvoices.invoiced} {reception.facture_fournisseur.numero}
              </span>
            )}
            {reception && <BoutonPdf chemin={`/receptions/documents/${reception.id}/pdf`} nomFichier={`reception-${reception.numero}.pdf`} taille={18} />}
          </div>
          <button type="button" onClick={onClose} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" aria-label={tr.close}><X size={20} /></button>
        </div>

        {!reception ? (
          <p className="p-4 text-sm text-gray-500">{erreur || tr.loading}</p>
        ) : (
          <div className="p-4 space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
              <div>
                <p className="text-xs text-gray-500">{tr.supplier}</p>
                <p className="font-medium dark:text-white">{reception.fournisseur?.raison_sociale}</p>
                <p className="text-xs text-gray-500">{reception.commandes.join(', ')}</p>
              </div>
              <div>
                <p className="text-xs text-gray-500">{tr.receiveDate}</p>
                {edition ? <input type="date" value={edition.date} onChange={e => setEdition({ ...edition, date: e.target.value })} className={classeChamp} aria-label={tr.receiveDate} />
                  : <p className="font-medium dark:text-white">{formatDateDocument(reception.date_reception, language)}</p>}
                {reception.auteur && <p className="text-xs text-gray-500">{tr.receivedBy} {reception.auteur.nom}</p>}
              </div>
              <div>
                <p className="text-xs text-gray-500">{tr.deliveryNote}</p>
                {edition ? <input value={edition.bonLivraison} maxLength={60} onChange={e => setEdition({ ...edition, bonLivraison: e.target.value })} className={classeChamp} aria-label={tr.deliveryNote} />
                  : <p className="font-medium dark:text-white">{reception.bon_livraison || '—'}</p>}
              </div>
              <div>
                <p className="text-xs text-gray-500">{tr.billOfLading}</p>
                {edition ? <input value={edition.connaissement} maxLength={60} onChange={e => setEdition({ ...edition, connaissement: e.target.value })} className={classeChamp} aria-label={tr.billOfLading} />
                  : <p className="font-medium dark:text-white">{reception.connaissement || '—'}</p>}
              </div>
            </div>
            {(edition || reception.notes) && (
              <div className="text-sm">
                <p className="text-xs text-gray-500">{tr.notes}</p>
                {edition ? <textarea rows={2} value={edition.notes} onChange={e => setEdition({ ...edition, notes: e.target.value })} className={classeChamp} aria-label={tr.notes} />
                  : <p className="dark:text-gray-200 whitespace-pre-line">{reception.notes}</p>}
              </div>
            )}
            {reception.statut === 'annulee' && (
              <div className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">
                {tr.cancelledBy.replace('{date}', formatDateHeure(reception.annulee_le, language)).replace('{nom}', reception.auteur_annulation?.nom ?? '—')}
                {reception.motif_annulation && <span> — {tr.reason} : {reception.motif_annulation}</span>}
              </div>
            )}

            <div className="border dark:border-gray-700 rounded-lg overflow-x-auto">
              <table className="w-full min-w-[760px] text-sm">
                <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                  <tr>
                    <th className="px-3 py-2 text-left">{tr.order}</th>
                    <th className="px-3 py-2 text-left">{tr.product}</th>
                    <th className="px-3 py-2 text-left">{tr.sector}</th>
                    <th className="px-3 py-2 text-right">{tr.quantity}</th>
                    <th className="px-3 py-2 text-right w-40">{tr.unitCost}</th>
                    <th className="px-3 py-2 text-right">{tr.amount}</th>
                    {avecFrais && <th className="px-3 py-2 text-right">{tr.costPerUnit}</th>}
                    {avecFrais && <th className="px-3 py-2 text-right">{tr.landedPerUnit}</th>}
                  </tr>
                </thead>
                <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                  {(reception.lignes ?? []).map(l => {
                    const commande = l.ligne_commande?.commande;
                    const parUnite = l.ligne_commande?.qte_par_unite_achat ?? 1;
                    return (
                      <tr key={l.id}>
                        <td className="px-3 py-2">{commande?.numero ?? '—'}</td>
                        <td className="px-3 py-2">
                          <div>{l.ligne_commande?.produit?.numero} {l.ligne_commande?.produit?.description}</div>
                          {(l.lot_numero || l.date_peremption || l.notes) && (
                            <div className="text-xs text-gray-500">
                              {[l.lot_numero && `${tr.lot} ${l.lot_numero}`, l.date_peremption && `${tr.expiry} ${formatDateDocument(l.date_peremption, language)}`, l.notes].filter(Boolean).join(' · ')}
                            </div>
                          )}
                        </td>
                        <td className="px-3 py-2">{l.secteur ? `${l.secteur.code} ${l.secteur.nom}` : '—'}</td>
                        <td className="px-3 py-2 text-right">
                          {l.quantite_recue} {l.ligne_commande?.unite_achat ?? ''}
                          {parUnite > 1 && <div className="text-xs text-gray-500">= {l.quantite_recue * parUnite} {l.ligne_commande?.produit?.mesure ?? 'UN'}</div>}
                        </td>
                        <td className="px-3 py-2 text-right">
                          {edition ? (
                            <input type="number" min={0} step="0.0001" value={edition.couts[l.id] ?? ''} placeholder={String(Number(l.ligne_commande?.prix_unitaire ?? 0))}
                              onChange={e => setEdition({ ...edition, couts: { ...edition.couts, [l.id]: e.target.value } })} className={`${classeChamp} text-right`} aria-label={`${tr.unitCost} ${l.ligne_commande?.produit?.description ?? ''}`} />
                          ) : montant(l.cout_retenu, commande?.devise)}
                        </td>
                        <td className="px-3 py-2 text-right">{montant(l.montant, commande?.devise)}</td>
                        {avecFrais && <td className="px-3 py-2 text-right">{montant(l.frais_unitaire ?? 0)}</td>}
                        {avecFrais && <td className="px-3 py-2 text-right font-medium">{montant(l.cout_revient_unitaire ?? 0)}</td>}
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            {edition ? (
              <CoutsAnnexes frais={fraisEdition} onChange={setFraisEdition} devises={Object.keys(tauxParDevise)}
                tauxParDevise={tauxParDevise} fournisseurs={fournisseurs} codeEntreprise={codeEntreprise} />
            ) : (reception.frais ?? []).length > 0 && (
              <div className="border dark:border-gray-700 rounded-lg overflow-x-auto">
                <table className="w-full min-w-[640px] text-sm" aria-label={tr.extraCosts}>
                  <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                    <tr>
                      <th className="px-3 py-2 text-left">{tr.extraCosts}</th>
                      <th className="px-3 py-2 text-left">{tr.billedBy}</th>
                      <th className="px-3 py-2 text-left">{tr.allocation}</th>
                      <th className="px-3 py-2 text-right">{tr.amount}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y dark:divide-gray-700 dark:text-gray-200">
                    {(reception.frais ?? []).map(f => (
                      <tr key={f.id}>
                        <td className="px-3 py-2">
                          {tr.costTypes[f.type] ?? f.type}{f.libelle ? ` — ${f.libelle}` : ''}
                          {f.reference && <div className="text-xs text-gray-500">{tr.invoiceRef} {f.reference}</div>}
                        </td>
                        <td className="px-3 py-2">{f.fournisseur?.raison_sociale ?? '—'}</td>
                        <td className="px-3 py-2">{tr.allocations[f.repartition] ?? f.repartition}</td>
                        <td className="px-3 py-2 text-right">
                          {montant(f.montant, f.devise)}
                          {f.devise && <div className="text-xs text-gray-500">≈ {montant(Number(f.montant) * Number(f.taux_change))}</div>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <div className="text-right text-sm dark:text-gray-200 space-y-0.5">
              <div>
                <span className="text-gray-500">{tr.total} : </span>
                <span className="font-semibold">{montant(reception.montant, reception.devise)}</span>
                {reception.devise && <span className="text-xs text-gray-500"> ≈ {montant(reception.montant_reference)}</span>}
              </div>
              {avecFrais && (
                <>
                  <div><span className="text-gray-500">{tr.extraCostsTotal} : </span>{montant(reception.montant_frais)}</div>
                  <div><span className="text-gray-500">{tr.landedCost} : </span><span className="font-semibold">{montant(reception.valeur_revient)}</span></div>
                </>
              )}
            </div>
            {edition && <p className="text-xs text-gray-500">{tr.editHelp}</p>}

            {annulation !== null && (
              <label className="block text-sm">
                <span className="block text-gray-700 dark:text-gray-300 mb-1">{tr.cancelReason}</span>
                <input value={annulation} maxLength={255} onChange={e => setAnnulation(e.target.value)} className={classeChamp} />
              </label>
            )}
            {erreur && <div className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200">{erreur}</div>}

            <div className="flex flex-wrap justify-end gap-2">
              {edition ? (
                <>
                  <button type="button" onClick={() => setEdition(null)} disabled={enCours} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200">{tr.cancel}</button>
                  <button type="button" onClick={enregistrerCorrections} disabled={enCours} className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">{tr.saveChanges}</button>
                </>
              ) : annulation !== null ? (
                <>
                  <button type="button" onClick={() => setAnnulation(null)} disabled={enCours} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200">{tr.close}</button>
                  <button type="button" onClick={annuler} disabled={enCours} className="flex items-center gap-1 px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"><Ban size={14} /> {tr.cancelReceipt}</button>
                </>
              ) : (
                <>
                  {enregistree && peut('receptions', 'modifier') && (
                    <button type="button" onClick={corriger} className="flex items-center gap-1 px-4 py-2 text-sm border border-blue-600 text-blue-700 rounded-lg hover:bg-blue-50 dark:text-blue-300 dark:hover:bg-blue-900/30"><Pencil size={14} /> {tr.edit}</button>
                  )}
                  {enregistree && peut('receptions', 'annuler') && (
                    <button type="button" onClick={() => setAnnulation('')} className="flex items-center gap-1 px-4 py-2 text-sm border border-red-600 text-red-700 rounded-lg hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-900/30"><Ban size={14} /> {tr.cancelReceipt}</button>
                  )}
                  <button type="button" onClick={onClose} className="px-4 py-2 text-sm border rounded-lg dark:border-gray-600 dark:text-gray-200">{tr.close}</button>
                </>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
