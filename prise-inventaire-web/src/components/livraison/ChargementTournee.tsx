import { useCallback, useEffect, useRef, useState } from 'react';
import { FileText, ScanLine, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur, signaler } from '@/lib/erreurs';
import { formatDateHeure } from '@/lib/documents';
import { ouvrirPdf } from '@/services/api';
import { chargerColis, chargerVrac, getChargement, scannerColis, type ElementCharge, type EtatChargement } from '@/services/chargement';

const contenu = (elements: ElementCharge[]) => elements.map(e => `${e.produit?.description ?? '?'} × ${e.quantite}`).join(', ');

/**
 * Chargement du camion d'une tournée (comme dans fripv2) : les arrêts dans l'ordre de chargement (le dernier livré
 * d'abord), chaque colis et la marchandise hors colis à cocher ; un champ reçoit le numéro lu par la douchette sur
 * l'étiquette (ou saisi) et coche le colis ; la fiche de chargement s'imprime.
 */
export default function ChargementTournee({ tourneeId, peutCharger, onClose }: { tourneeId: number; peutCharger: boolean; onClose: () => void }) {
  const { t } = useLanguage();
  const tl = t.truckLoading;
  const [etat, setEtat] = useState<EtatChargement | null>(null);
  const [erreur, setErreur] = useState('');
  const [numero, setNumero] = useState('');
  // Réponse au dernier scan : chargé, déjà chargé ou refusé.
  const [retour, setRetour] = useState<{ ton: 'ok' | 'deja' | 'erreur'; texte: string } | null>(null);
  const [envoi, setEnvoi] = useState(false);
  const champScan = useRef<HTMLInputElement>(null);

  const charger = useCallback(() => {
    getChargement(tourneeId).then(setEtat, e => setErreur(messageErreur(e, tl.loadError)));
  }, [tourneeId, tl.loadError]);

  useEffect(() => { charger(); }, [charger]);

  const modifiable = !!etat?.modifiable && peutCharger;
  const total = etat ? etat.resume.colis + etat.resume.vrac : 0;
  const faits = etat ? etat.resume.colis_charges + etat.resume.vrac_charges : 0;

  async function scanner(e: React.FormEvent) {
    e.preventDefault();
    const lu = numero.trim();
    if (!lu) return;
    setEnvoi(true);
    try {
      const reponse = await scannerColis(tourneeId, lu);
      setEtat(reponse);
      setRetour({ ton: reponse.deja ? 'deja' : 'ok', texte: reponse.message });
    } catch (err) {
      setRetour({ ton: 'erreur', texte: messageErreur(err, tl.scanError) });
    } finally {
      setEnvoi(false);
      setNumero('');
      champScan.current?.focus();
    }
  }

  // La case se coche tout de suite (réseau lent du dépôt) ; l'état renvoyé par l'API fait foi, et un échec recharge.
  const cocher = (maj: (e: EtatChargement) => EtatChargement) => setEtat(e => (e ? maj(e) : e));
  const maintenant = (charge: boolean) => (charge ? new Date().toISOString() : null);

  async function basculerColis(colisId: number, charge: boolean) {
    cocher(e => ({ ...e, arrets: e.arrets.map(a => ({ ...a, colis: a.colis.map(c => (c.id === colisId ? { ...c, charge_le: maintenant(charge), charge_par: null } : c)) })) }));
    try {
      setEtat(await chargerColis(tourneeId, colisId, charge));
    } catch (err) {
      signaler(err, tl.saveError);
      charger();
    }
  }

  async function basculerVrac(bonId: number, charge: boolean) {
    cocher(e => ({ ...e, arrets: e.arrets.map(a => (a.bon_livraison_id === bonId ? { ...a, vrac_charge_le: maintenant(charge), vrac_charge_par: null } : a)) }));
    try {
      setEtat(await chargerVrac(tourneeId, bonId, charge));
    } catch (err) {
      signaler(err, tl.saveError);
      charger();
    }
  }

  // Qui a chargé et quand (une fois la réponse de l'API reçue).
  const pointage = (nom: string | null, date: string | null) =>
    date && nom ? <span className="text-xs text-green-700 dark:text-green-400">{tl.loadedBy.replace('{nom}', nom).replace('{date}', formatDateHeure(date))}</span> : null;

  const titre = tl.title.replace('{numero}', etat?.tournee.numero ?? '');

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" role="dialog" aria-modal="true" aria-label={titre}>
      <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-1">
          <h2 className="text-xl font-bold dark:text-white">{titre}</h2>
          <button onClick={onClose} aria-label={tl.close}><X size={20} /></button>
        </div>
        {etat?.tournee.camion && <p className="text-sm text-gray-500 dark:text-gray-400 mb-3">{tl.truck.replace('{camion}', etat.tournee.camion)}</p>}
        {erreur && <p role="alert" className="mb-3 px-3 py-2 rounded bg-red-50 text-red-700 text-sm">{erreur}</p>}
        {!etat ? <p className="text-sm text-gray-500">{tl.opening}</p> : (
          <>
            {total > 0 && (
              <div className="mb-3">
                <div className="flex justify-between text-sm mb-1 dark:text-gray-200">
                  <span>{tl.progress.replace('{n}', String(faits)).replace('{total}', String(total))}</span>
                  {etat.resume.reste === 0 && <span className="font-medium text-green-700 dark:text-green-400">{tl.complete}</span>}
                </div>
                <div className="h-2 rounded bg-gray-200 dark:bg-gray-700" role="progressbar" aria-valuemin={0} aria-valuemax={total} aria-valuenow={faits}>
                  <div className={`h-2 rounded ${etat.resume.reste === 0 ? 'bg-green-600' : 'bg-amber-500'}`} style={{ width: `${Math.round((faits / total) * 100)}%` }} />
                </div>
              </div>
            )}

            {modifiable ? (
              <form onSubmit={scanner} className="flex gap-2 mb-2">
                <div className="relative flex-1">
                  <ScanLine size={16} className="absolute left-2 top-1/2 -translate-y-1/2 text-gray-400" />
                  <input ref={champScan} autoFocus value={numero} onChange={e => setNumero(e.target.value)} aria-label={tl.scanLabel} placeholder={tl.scanPlaceholder}
                    className="w-full pl-8 pr-2 py-2 border rounded-lg text-sm font-mono dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                </div>
                <button type="submit" disabled={envoi || !numero.trim()} className="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 disabled:opacity-50">{tl.scanButton}</button>
              </form>
            ) : etat.modifiable ? null : <p className="mb-2 text-sm text-gray-500 italic">{tl.readOnly}</p>}
            {retour && (
              <p role="status" className={`mb-3 px-3 py-1.5 rounded text-sm ${
                retour.ton === 'ok' ? 'bg-green-50 text-green-800' : retour.ton === 'deja' ? 'bg-amber-50 text-amber-800' : 'bg-red-50 text-red-700'
              }`}>{retour.texte}</p>
            )}

            <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">{tl.order}</p>
            <ol className="space-y-3">
              {etat.arrets.map((arret, rang) => (
                <li key={arret.bon_livraison_id} className="rounded border border-gray-200 dark:border-gray-700">
                  <p className="px-3 py-1.5 bg-gray-50 dark:bg-gray-700 text-sm font-medium dark:text-white">
                    {rang + 1}. {arret.bon} — {tl.stop.replace('{ordre}', String(arret.ordre))}
                    {arret.client && <span className="font-normal text-gray-500 dark:text-gray-300"> · {arret.client}</span>}
                  </p>
                  <div className="divide-y divide-gray-100 dark:divide-gray-700">
                    {arret.colis.map(c => (
                      <label key={c.id} className="flex items-start gap-3 px-3 py-2 text-sm dark:text-gray-200">
                        <input type="checkbox" className="mt-0.5" checked={!!c.charge_le} disabled={!modifiable}
                          onChange={e => basculerColis(c.id, e.target.checked)} aria-label={tl.loaded.replace('{element}', c.numero)} />
                        <span className="min-w-0">
                          <span className="font-mono font-medium">{c.numero}</span>
                          {c.poids_kg != null && <span className="text-gray-500"> · {Number(c.poids_kg)} kg</span>}
                          <span className="block text-gray-600 dark:text-gray-400 break-words">{contenu(c.contenu)}</span>
                          {pointage(c.charge_par, c.charge_le)}
                        </span>
                      </label>
                    ))}
                    {arret.vrac.length > 0 && (
                      <label className="flex items-start gap-3 px-3 py-2 text-sm dark:text-gray-200">
                        <input type="checkbox" className="mt-0.5" checked={!!arret.vrac_charge_le} disabled={!modifiable}
                          onChange={e => basculerVrac(arret.bon_livraison_id, e.target.checked)} aria-label={tl.loaded.replace('{element}', `${tl.loose} ${arret.bon}`)} />
                        <span className="min-w-0">
                          <span className="italic text-gray-500 dark:text-gray-400">{tl.loose}</span>
                          <span className="block text-gray-600 dark:text-gray-400 break-words">{contenu(arret.vrac)}</span>
                          {pointage(arret.vrac_charge_par, arret.vrac_charge_le)}
                        </span>
                      </label>
                    )}
                    {arret.colis.length === 0 && arret.vrac.length === 0 && <p className="px-3 py-2 text-sm text-gray-500 italic">{tl.nothing}</p>}
                  </div>
                </li>
              ))}
            </ol>

            <div className="mt-4 flex flex-wrap justify-end gap-2">
              {etat.arrets.length > 0 && (
                <button type="button" title={tl.sheetHelp}
                  onClick={() => { ouvrirPdf(`/tournees/${tourneeId}/chargement/pdf`, `fiche-chargement-${etat.tournee.numero}.pdf`).catch(e => signaler(e, tl.sheet)); }}
                  className="inline-flex items-center gap-1 px-3 py-1.5 border rounded-lg text-sm dark:border-gray-600 dark:text-white">
                  <FileText size={14} /> {tl.sheet}
                </button>
              )}
              <button type="button" onClick={onClose} className="px-3 py-1.5 border rounded-lg text-sm dark:border-gray-600 dark:text-white">{tl.close}</button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
