import { useEffect, useState } from 'react';
import { X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, dateSaisie, formatDateDocument, formatMontant, useDevise } from '@/lib/documents';
import { creerAvoir, emettreAvoir, getPropositionAvoir, modifierAvoir, type Avoir, type LigneProposee, type PropositionAvoir } from '@/services/avoirs';

interface Saisie { quantite: string; prix: string }

const classeChamp = 'px-2 py-1.5 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';

/** Total HT d'une ligne de note, comme l'API : quantité × prix, remise de la ligne facturée déduite. */
const totalLigne = (ligne: LigneProposee, quantite: number, prix: number) => Math.round(quantite * prix * (1 - ligne.remise_ligne / 100) * 100) / 100;

/**
 * Éditeur d'une note de crédit, comme « Créditer » dans fripv2 : les lignes de la facture avec ce qui reste à
 * créditer, la quantité et le prix unitaire à créditer (au plus ceux facturés). Enregistre un brouillon, ou
 * l'enregistre et l'émet. Les taxes définitives sont calculées par l'API, au prorata de celles de la facture.
 */
export default function EditeurNoteCredit({ factureId, brouillon, onClose, onEnregistre }: {
  factureId: number;
  /** Brouillon à modifier (avec ses lignes) ; absent pour une nouvelle note. */
  brouillon?: Avoir | null;
  onClose: () => void;
  onEnregistre: (avoir: Avoir, emis: boolean) => void;
}) {
  const { t, language } = useLanguage();
  const tc = t.creditNotes;
  const devise = useDevise();
  const montant = (v: number | string | null | undefined) => formatMontant(v, devise, language);
  const { peut } = usePermissions();
  const [proposition, setProposition] = useState<PropositionAvoir | null>(null);
  const [saisies, setSaisies] = useState<Record<number, Saisie>>({});
  const [entete, setEntete] = useState({
    date_avoir: brouillon ? dateSaisie(brouillon.date_avoir) : aujourdhui(),
    motif: brouillon?.motif ?? '',
    notes: brouillon?.notes ?? '',
  });
  // Brouillon créé par un premier essai (émission refusée ensuite) : un nouvel essai le modifie au lieu d'en créer un autre.
  const [idBrouillon, setIdBrouillon] = useState<number | null>(brouillon?.id ?? null);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    getPropositionAvoir(factureId).then(p => {
      if (!actif) return;
      setProposition(p);
      // Brouillon : ses quantités et ses prix ; nouvelle note : tout ce qui reste à créditer, au prix facturé.
      const existantes = new Map((brouillon?.lignes ?? []).map(l => [l.facture_ligne_id, l]));
      setSaisies(Object.fromEntries(p.lignes.map(l => {
        const existante = existantes.get(l.facture_ligne_id);
        return [l.facture_ligne_id, existante
          ? { quantite: String(existante.quantite), prix: String(Number(existante.prix_unitaire_ht)) }
          : { quantite: String(brouillon ? 0 : l.quantite_proposee), prix: String(l.prix_unitaire_ht) }];
      })));
    }, e => { if (actif) setErreur(messageErreur(e, tc.error)); });
    return () => { actif = false; };
  }, [factureId, brouillon, tc.error]);

  const lignes = proposition?.lignes ?? [];
  const calcul = lignes.map(ligne => {
    const saisie = saisies[ligne.facture_ligne_id] ?? { quantite: '0', prix: String(ligne.prix_unitaire_ht) };
    const quantite = Math.max(0, Math.floor(Number(saisie.quantite) || 0));
    const prix = Math.max(0, Number(saisie.prix) || 0);
    const ht = totalLigne(ligne, quantite, prix);
    const depasse = quantite > ligne.quantite_facturee || prix > ligne.prix_unitaire_ht + 1e-9 || ht > ligne.reste_ht + 0.005;
    return { ligne, saisie, quantite, prix, ht, depasse };
  });
  const aCrediter = calcul.filter(c => c.quantite > 0);
  const totalHt = calcul.reduce((somme, c) => somme + c.ht, 0);
  // Estimation au taux effectif de chaque ligne facturée ; l'API calcule le détail exact.
  const taxes = calcul.reduce((somme, c) => somme + c.ht * c.ligne.taux_tva / 100, 0);
  const rienACrediter = proposition !== null && (!proposition.creditable || lignes.every(l => l.reste_ht <= 0));
  const peutEnregistrer = idBrouillon ? peut('avoirs', 'modifier') : peut('avoirs', 'creer');
  const peutEmettre = peutEnregistrer && peut('avoirs', 'emettre');
  const bloque = occupe || rienACrediter || aCrediter.length === 0 || calcul.some(c => c.depasse);

  const changer = (ligne: number, champ: keyof Saisie, valeur: string) => setSaisies(s => ({ ...s, [ligne]: { ...s[ligne], [champ]: valeur } }));
  const toutCrediter = () => setSaisies(Object.fromEntries(lignes.map(l => [l.facture_ligne_id, { quantite: String(l.quantite_proposee), prix: String(l.prix_unitaire_ht) }])));
  const toutEffacer = () => setSaisies(s => Object.fromEntries(Object.entries(s).map(([cle, v]) => [cle, { ...v, quantite: '0' }])));

  async function enregistrer(emettre: boolean) {
    if (aCrediter.length === 0) { setErreur(tc.noQuantity); return; }
    if (emettre && !confirm(tc.confirmIssue)) return;
    setOccupe(true);
    setErreur('');
    const saisie = {
      date_avoir: entete.date_avoir,
      motif: entete.motif.trim() || null,
      notes: entete.notes.trim() || null,
      lignes: aCrediter.map(c => ({ facture_ligne_id: c.ligne.facture_ligne_id, quantite: c.quantite, prix_unitaire_ht: c.prix })),
    };
    try {
      let avoir = idBrouillon ? await modifierAvoir(idBrouillon, saisie) : await creerAvoir(factureId, saisie);
      setIdBrouillon(avoir.id);
      if (emettre) avoir = await emettreAvoir(avoir.id);
      onEnregistre(avoir, emettre);
    } catch (e) {
      setErreur(messageErreur(e, tc.error));
    } finally {
      setOccupe(false);
    }
  }

  const facture = proposition?.facture;
  const titre = (brouillon ? tc.editDraft : tc.creditInvoice).replace('{numero}', facture?.numero ?? '…');

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="editeur-avoir-titre" className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-5xl max-h-[94vh] overflow-y-auto space-y-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 id="editeur-avoir-titre" className="text-lg font-bold text-gray-900 dark:text-white">{titre}</h2>
            {facture && (
              <p className="text-sm text-gray-500 dark:text-gray-400">
                {facture.client?.raison_sociale} · {formatDateDocument(facture.date_facture, language)} · {montant(facture.montant_ttc)}
              </p>
            )}
          </div>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tc.close}><X size={18} /></button>
        </div>

        {proposition === null && !erreur && <p className="text-sm text-gray-500">{tc.loading}</p>}

        {proposition && (
          <>
            <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
              <span className="text-gray-700 dark:text-gray-300">{tc.invoiceDue.replace('{montant}', montant(proposition.facture.reste_a_payer))}</span>
              {proposition.deja_credite.ttc > 0 && <span className="text-gray-700 dark:text-gray-300">{tc.alreadyCreditedTotal.replace('{montant}', montant(proposition.deja_credite.ttc))}</span>}
            </div>
            {rienACrediter ? (
              <p className="px-3 py-2 rounded-lg text-sm bg-amber-50 text-amber-800">{tc.nothingLeft}</p>
            ) : (
              <>
                <p className="text-sm text-gray-500 dark:text-gray-400">{tc.editorHelp}</p>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                  <label className="text-xs text-gray-500 dark:text-gray-400">{tc.date}
                    <input type="date" value={entete.date_avoir} min={dateSaisie(proposition.facture.date_facture)}
                      onChange={e => setEntete({ ...entete, date_avoir: e.target.value })} className={`${classeChamp} w-full`} />
                  </label>
                  <label className="text-xs text-gray-500 dark:text-gray-400 sm:col-span-2">{tc.reason}
                    <input value={entete.motif} maxLength={255} placeholder={tc.reasonHelp}
                      onChange={e => setEntete({ ...entete, motif: e.target.value })} className={`${classeChamp} w-full`} />
                  </label>
                </div>

                <div className="flex flex-wrap gap-2">
                  <button type="button" onClick={toutCrediter} className="px-3 py-1.5 border rounded-lg text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{tc.creditAll}</button>
                  <button type="button" onClick={toutEffacer} className="px-3 py-1.5 border rounded-lg text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{tc.clearAll}</button>
                </div>

                <div className="overflow-x-auto">
                  <table className="w-full text-sm min-w-[42rem]">
                    <thead>
                      <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300 border-b dark:border-gray-700">
                        <th className="py-2 pr-3 font-medium">{tc.product}</th>
                        <th className="py-2 pr-3 font-medium text-right">{tc.invoiced}</th>
                        <th className="py-2 pr-3 font-medium text-right">{tc.alreadyCredited}</th>
                        <th className="py-2 pr-3 font-medium text-right">{tc.quantity}</th>
                        <th className="py-2 pr-3 font-medium text-right">{tc.unitPrice}</th>
                        <th className="py-2 font-medium text-right">{tc.lineTotal}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {calcul.map(({ ligne, saisie, ht, depasse }) => {
                        const nom = ligne.produit?.description ?? `#${ligne.produit_id}`;
                        const epuisee = ligne.reste_ht <= 0;
                        return (
                          <tr key={ligne.facture_ligne_id} className={`border-b dark:border-gray-700 ${epuisee ? 'opacity-60' : ''}`}>
                            <td className="py-2 pr-3">
                              <span className="text-gray-900 dark:text-white">{nom}</span>
                              {ligne.produit?.numero && <span className="block text-xs font-mono text-gray-500">{ligne.produit.numero}</span>}
                            </td>
                            <td className="py-2 pr-3 text-right whitespace-nowrap text-gray-700 dark:text-gray-300">
                              {ligne.quantite_facturee} × {montant(ligne.prix_unitaire_ht)}
                              {ligne.remise_ligne > 0 && <span className="block text-xs text-gray-500">{tc.discount} {ligne.remise_ligne} %</span>}
                            </td>
                            <td className="py-2 pr-3 text-right whitespace-nowrap text-gray-700 dark:text-gray-300">
                              {ligne.credite_ht > 0 ? montant(ligne.credite_ht) : '—'}
                              <span className="block text-xs text-gray-500">{tc.remaining.replace('{montant}', montant(ligne.reste_ht))}</span>
                            </td>
                            <td className="py-2 pr-3 text-right">
                              <input type="number" min={0} max={ligne.quantite_facturee} step={1} value={saisie.quantite} disabled={epuisee}
                                aria-label={`${tc.quantity} — ${nom}`}
                                onChange={e => changer(ligne.facture_ligne_id, 'quantite', e.target.value)}
                                className={`${classeChamp} w-20 text-right ${depasse ? 'border-red-500 text-red-700' : ''}`} />
                            </td>
                            <td className="py-2 pr-3 text-right">
                              <input type="number" min={0} max={ligne.prix_unitaire_ht} step="0.01" value={saisie.prix} disabled={epuisee}
                                aria-label={`${tc.unitPrice} — ${nom}`}
                                onChange={e => changer(ligne.facture_ligne_id, 'prix', e.target.value)}
                                className={`${classeChamp} w-28 text-right ${depasse ? 'border-red-500 text-red-700' : ''}`} />
                            </td>
                            <td className={`py-2 text-right whitespace-nowrap font-medium ${depasse ? 'text-red-700' : 'text-gray-900 dark:text-white'}`}>{montant(ht)}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>

                <div className="flex flex-col items-end gap-1 text-sm">
                  <div className="flex gap-6"><span className="text-gray-500">{tc.totalHt}</span><span className="font-medium text-gray-900 dark:text-white w-32 text-right">{montant(totalHt)}</span></div>
                  <div className="flex gap-6"><span className="text-gray-500">≈ {tc.taxes}</span><span className="text-gray-700 dark:text-gray-300 w-32 text-right">{montant(taxes)}</span></div>
                  <div className="flex gap-6 text-base"><span className="text-gray-700 dark:text-gray-200 font-semibold">≈ {tc.amount}</span><span className="font-bold text-gray-900 dark:text-white w-32 text-right">{montant(totalHt + taxes)}</span></div>
                  <p className="text-xs text-gray-500 dark:text-gray-400">{tc.totalEstimate}</p>
                </div>

                <label className="block text-xs text-gray-500 dark:text-gray-400">{tc.notes}
                  <textarea value={entete.notes} rows={2} maxLength={2000} onChange={e => setEntete({ ...entete, notes: e.target.value })} className={`${classeChamp} w-full`} />
                </label>
              </>
            )}
          </>
        )}

        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

        <div className="flex flex-wrap justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tc.cancel}</button>
          {peutEnregistrer && (
            <button type="button" onClick={() => { void enregistrer(false); }} disabled={bloque}
              className="px-4 py-2 border border-purple-300 text-purple-700 rounded-lg text-sm hover:bg-purple-50 disabled:opacity-50 dark:border-purple-500 dark:text-purple-300 dark:hover:bg-purple-900/30">{tc.saveDraft}</button>
          )}
          {peutEmettre && (
            <button type="button" onClick={() => { void enregistrer(true); }} disabled={bloque}
              className="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700 disabled:opacity-50">{tc.saveAndIssue}</button>
          )}
        </div>
      </div>
    </div>
  );
}
