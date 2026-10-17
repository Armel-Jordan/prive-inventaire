import { useEffect, useMemo, useState } from 'react';
import { Plus, Trash2, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { aujourdhui, tauxPropose, useCodeDevise, useMontantDocument } from '@/lib/documents';
import { messageErreur } from '@/lib/erreurs';
import { creerTransactionBancaire, TYPES_TRANSACTION_BANCAIRE, type CompteBanque, type TransactionBancaire, type TypeTransactionBancaire } from '@/services/banque';
import { getComptes, type CompteComptable } from '@/services/comptabilite';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const centimes = (valeur: string | number) => Math.round((Number(valeur) || 0) * 100);

interface LigneSaisie { cle: number; compte: string; libelle: string; montant: string }
let compteur = 0;
const nouvelleLigne = (): LigneSaisie => ({ cle: ++compteur, compte: '', libelle: '', montant: '' });

/**
 * Saisie d'une transaction bancaire sans facture : un dépôt (intérêts, recette diverse) ou un retrait (frais, dépense
 * payée par chèque ou prélèvement) réparti sur des comptes de contrepartie — une ligne négative passe de l'autre côté —,
 * ou un virement vers un autre compte bancaire de la même devise.
 */
export default function FenetreTransactionBancaire({ comptes, onClose, onEnregistree }: {
  comptes: CompteBanque[];
  onClose: () => void;
  onEnregistree: (transaction: TransactionBancaire) => void;
}) {
  const { t } = useLanguage();
  const tb = t.bank;
  const montantTexte = useMontantDocument();
  const deviseEntreprise = useCodeDevise();
  const actifs = useMemo(() => comptes.filter(c => c.actif), [comptes]);
  const [saisie, setSaisie] = useState({
    compte: String(actifs.find(c => c.par_defaut)?.id ?? actifs[0]?.id ?? ''),
    date: aujourdhui(),
    type: 'retrait' as TypeTransactionBancaire,
    destination: '',
    libelle: '',
    reference: '',
    montant: '',
    taux: '',
    notes: '',
  });
  const [lignes, setLignes] = useState<LigneSaisie[]>([nouvelleLigne()]);
  const [comptesComptables, setComptesComptables] = useState<CompteComptable[]>([]);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    // Contreparties possibles : comptes actifs, de détail, ouverts à la saisie manuelle.
    getComptes().then(liste => { if (actif) setComptesComptables((liste ?? []).filter(c => c.actif && c.type !== 'en_tete' && c.saisie_manuelle)); }, () => {});
    return () => { actif = false; };
  }, []);

  const compte = actifs.find(c => c.id === Number(saisie.compte));
  const devise = compte?.devise_code && compte.devise_code !== deviseEntreprise ? compte.devise_code : null;

  // Taux proposé pour un compte en devise étrangère.
  useEffect(() => {
    if (!devise) return;
    let actif = true;
    tauxPropose(devise, deviseEntreprise).then(taux => { if (actif) setSaisie(s => (s.taux === '' ? { ...s, taux } : s)); });
    return () => { actif = false; };
  }, [devise, deviseEntreprise]);

  const virement = saisie.type === 'virement';
  const total = virement ? centimes(saisie.montant) : lignes.reduce((s, l) => s + centimes(l.montant), 0);
  const changerLigne = (cle: number, champ: keyof Omit<LigneSaisie, 'cle'>, valeur: string) => setLignes(ls => ls.map(l => (l.cle === cle ? { ...l, [champ]: valeur } : l)));

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    try {
      onEnregistree(await creerTransactionBancaire({
        compte_bancaire_id: Number(saisie.compte),
        date_transaction: saisie.date,
        type: saisie.type,
        compte_bancaire_destination_id: virement ? Number(saisie.destination) || null : null,
        libelle: saisie.libelle.trim(),
        reference: saisie.reference.trim() || null,
        montant: virement ? total / 100 : undefined,
        taux_change: devise && Number(saisie.taux) > 0 ? Number(saisie.taux) : undefined,
        notes: saisie.notes.trim() || null,
        lignes: virement ? [] : lignes.filter(l => l.compte && centimes(l.montant) !== 0)
          .map(l => ({ compte_comptable_id: Number(l.compte), libelle: l.libelle.trim() || null, montant: centimes(l.montant) / 100 })),
      }));
    } catch (err) {
      setErreur(messageErreur(err, tb.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-2 sm:p-4">
      <form onSubmit={enregistrer} role="dialog" aria-modal="true" aria-labelledby="transaction-titre"
        className="bg-white dark:bg-gray-800 rounded-xl p-4 sm:p-6 w-full max-w-3xl max-h-[94vh] overflow-y-auto space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="transaction-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tb.newTransaction}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tb.close}><X size={18} /></button>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.type}
            <select value={saisie.type} onChange={e => setSaisie({ ...saisie, type: e.target.value as TypeTransactionBancaire })} className={classeChamp}>
              {TYPES_TRANSACTION_BANCAIRE.map(type => <option key={type} value={type}>{tb.types[type]}</option>)}
            </select>
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{virement ? tb.fromAccount : tb.account}
            <select required value={saisie.compte} onChange={e => setSaisie({ ...saisie, compte: e.target.value, taux: '' })} className={classeChamp}>
              <option value="">{tb.chooseAccount}</option>
              {actifs.map(c => <option key={c.id} value={c.id}>{c.nom}{c.devise_code ? ` (${c.devise_code})` : ''}</option>)}
            </select>
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.date}
            <input type="date" required value={saisie.date} onChange={e => setSaisie({ ...saisie, date: e.target.value })} className={classeChamp} />
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400 sm:col-span-2">{tb.label}
            <input required maxLength={255} value={saisie.libelle} placeholder={tb.labelHelp} onChange={e => setSaisie({ ...saisie, libelle: e.target.value })} className={classeChamp} />
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.reference}
            <input maxLength={100} value={saisie.reference} placeholder={tb.referenceHelp} onChange={e => setSaisie({ ...saisie, reference: e.target.value })} className={classeChamp} />
          </label>
          {devise && (
            <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.rate.replace('{devise}', devise).replace('{reference}', deviseEntreprise)}
              <input type="number" required min="0" step="any" value={saisie.taux} onChange={e => setSaisie({ ...saisie, taux: e.target.value })} className={classeChamp} />
            </label>
          )}
        </div>

        {virement ? (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.toAccount}
              <select required value={saisie.destination} onChange={e => setSaisie({ ...saisie, destination: e.target.value })} className={classeChamp}>
                <option value="">{tb.chooseAccount}</option>
                {actifs.filter(c => c.id !== Number(saisie.compte)).map(c => <option key={c.id} value={c.id}>{c.nom}{c.devise_code ? ` (${c.devise_code})` : ''}</option>)}
              </select>
            </label>
            <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.amount}
              <input type="number" required min="0.01" step="0.01" value={saisie.montant} onChange={e => setSaisie({ ...saisie, montant: e.target.value })} className={classeChamp} />
            </label>
          </div>
        ) : (
          <div className="border dark:border-gray-700 rounded-lg">
            <div className="flex items-center justify-between px-3 py-2 border-b dark:border-gray-700">
              <h3 className="text-sm font-semibold dark:text-white">{saisie.type === 'depot' ? tb.depositLines : tb.withdrawalLines}</h3>
              <button type="button" onClick={() => setLignes(ls => [...ls, nouvelleLigne()])}
                className="flex items-center gap-1 px-2 py-1 text-xs rounded border dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700"><Plus size={14} /> {tb.addLine}</button>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[600px] text-sm" aria-label={tb.lines}>
                <thead className="bg-gray-50 dark:bg-gray-700/50 text-xs text-gray-600 dark:text-gray-300">
                  <tr>
                    <th className="px-3 py-2 text-left">{tb.ledgerAccount}</th>
                    <th className="px-3 py-2 text-left">{tb.lineLabel}</th>
                    <th className="px-3 py-2 text-right">{tb.amount}</th>
                    <th className="px-3 py-2" />
                  </tr>
                </thead>
                <tbody className="divide-y dark:divide-gray-700">
                  {lignes.map((l, index) => (
                    <tr key={l.cle}>
                      <td className="px-3 py-2">
                        <select value={l.compte} aria-label={`${tb.ledgerAccount} ${index + 1}`} onChange={e => changerLigne(l.cle, 'compte', e.target.value)} className={classeChamp}>
                          <option value="">{tb.chooseLedgerAccount}</option>
                          {comptesComptables.filter(c => c.id !== compte?.compte_comptable_id).map(c => <option key={c.id} value={c.id}>{c.numero} — {c.libelle}</option>)}
                        </select>
                      </td>
                      <td className="px-3 py-2">
                        <input value={l.libelle} maxLength={255} aria-label={`${tb.lineLabel} ${index + 1}`} onChange={e => changerLigne(l.cle, 'libelle', e.target.value)} className={classeChamp} />
                      </td>
                      <td className="px-3 py-2 text-right">
                        <input type="number" step="0.01" value={l.montant} aria-label={`${tb.amount} ${index + 1}`} onChange={e => changerLigne(l.cle, 'montant', e.target.value)} className={`${classeChamp} text-right w-32`} />
                      </td>
                      <td className="px-3 py-2 text-right">
                        {lignes.length > 1 && (
                          <button type="button" onClick={() => setLignes(ls => ls.filter(x => x.cle !== l.cle))} aria-label={tb.removeLine}
                            className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><Trash2 size={14} /></button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="px-3 py-2 text-xs text-gray-500">{tb.negativeHelp}</p>
          </div>
        )}

        <p className="text-right text-sm dark:text-gray-200">{tb.total} : <strong>{montantTexte(total / 100, devise)}</strong></p>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{tb.notes}
          <textarea rows={2} maxLength={2000} value={saisie.notes} onChange={e => setSaisie({ ...saisie, notes: e.target.value })} className={classeChamp} />
        </label>

        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tb.cancel}</button>
          <button type="submit" disabled={occupe || !saisie.compte || total <= 0}
            className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 disabled:opacity-50">{tb.save}</button>
        </div>
      </form>
    </div>
  );
}
