import { useCallback, useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { Pencil, Printer, Trash2, X } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { creerColis, getColis, modifierColis, supprimerColis, type Colis, type ColisDuBon } from '@/services/colis';

const classeChamp = 'w-full px-2 py-1 border rounded text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/** Texte sûr dans la page d'impression (noms de clients et de produits). */
const echapper = (texte: string) => texte.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] as string));

/**
 * Colis d'un bon de livraison (préparation des boîtes, comme dans fripv2) : les colis et leur contenu, un nouveau colis
 * avec les quantités qui restent à emballer, la modification d'un colis (toutes ses quantités à zéro : il disparaît),
 * l'impression des étiquettes (QR code du numéro, bon, client, contenu).
 */
export default function ColisBon({ bonId, peutPreparer, onClose }: { bonId: number; peutPreparer: boolean; onClose: () => void }) {
  const { t } = useLanguage();
  const tc = t.parcels;
  const [donnees, setDonnees] = useState<ColisDuBon | null>(null);
  const [erreur, setErreur] = useState('');
  // Colis en cours de saisie : nouveau (id null) ou modifié.
  const [saisie, setSaisie] = useState<{ id: number | null; quantites: Record<number, string>; poids: string; notes: string } | null>(null);
  const [envoi, setEnvoi] = useState(false);

  const charger = useCallback(() => {
    getColis(bonId).then(setDonnees, e => setErreur(messageErreur(e, tc.loadError)));
  }, [bonId, tc.loadError]);

  useEffect(() => { charger(); }, [charger]);

  const modifiable = !!donnees?.modifiable && peutPreparer;
  const produit = (ligneId: number) => donnees?.lignes.find(l => l.id === ligneId)?.produit;
  const contenu = (colis: Colis) => colis.lignes.map(l => `${produit(l.bon_livraison_ligne_id)?.description ?? '?'} × ${l.quantite}`).join(', ');

  const ouvrirNouveau = () => setSaisie({ id: null, quantites: {}, poids: '', notes: '' });
  const ouvrirModification = (colis: Colis) => setSaisie({
    id: colis.id,
    quantites: Object.fromEntries(colis.lignes.map(l => [l.bon_livraison_ligne_id, String(l.quantite)])),
    poids: colis.poids_kg != null ? String(Number(colis.poids_kg)) : '',
    notes: colis.notes ?? '',
  });

  // Quantité possible pour une ligne : le restant, plus ce que contient déjà le colis modifié.
  const maximum = (ligneId: number) => {
    const restant = donnees?.lignes.find(l => l.id === ligneId)?.restant ?? 0;
    const dejaDedans = saisie?.id ? donnees?.colis.find(c => c.id === saisie.id)?.lignes.find(l => l.bon_livraison_ligne_id === ligneId)?.quantite ?? 0 : 0;
    return restant + dejaDedans;
  };

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    if (!saisie || !donnees) return;
    setEnvoi(true);
    setErreur('');
    const corps = {
      lignes: donnees.lignes.map(l => ({ bon_livraison_ligne_id: l.id, quantite: Number(saisie.quantites[l.id] || 0) })),
      poids_kg: saisie.poids === '' ? null : Number(saisie.poids),
      notes: saisie.notes.trim() || null,
    };
    try {
      setDonnees(saisie.id ? await modifierColis(bonId, saisie.id, corps) : await creerColis(bonId, corps));
      setSaisie(null);
    } catch (err) {
      setErreur(messageErreur(err, tc.saveError));
    } finally {
      setEnvoi(false);
    }
  }

  async function supprimer(colis: Colis) {
    if (!confirm(tc.confirmDelete.replace('{numero}', colis.numero))) return;
    try {
      setDonnees(await supprimerColis(bonId, colis.id));
    } catch (err) {
      setErreur(messageErreur(err, tc.saveError));
    }
  }

  // Étiquettes 76 × 51 mm : QR code du numéro, numéro, rang, bon, client, contenu, poids.
  async function imprimer(liste: Colis[]) {
    if (!donnees || liste.length === 0) return;
    const fenetre = window.open('', '_blank');
    if (!fenetre) return;
    const images = await Promise.all(liste.map(c => QRCode.toDataURL(c.numero, { margin: 1, width: 180 })));
    const total = donnees.colis.length;
    const etiquettes = liste.map((c, i) => `
      <section class="etiquette">
        <img src="${images[i]}" alt="" />
        <div class="texte">
          <div class="numero">${echapper(c.numero)}</div>
          <div class="rang">${echapper(tc.labelOf.replace('{n}', String(donnees.colis.findIndex(x => x.id === c.id) + 1)).replace('{total}', String(total)))}</div>
          <div class="client">${echapper(donnees.bon.client ?? '')}</div>
          <div class="bon">${echapper(donnees.bon.numero)}</div>
          <div class="contenu">${echapper(contenu(c))}</div>
          ${c.poids_kg != null ? `<div class="contenu">${echapper(`${Number(c.poids_kg)} kg`)}</div>` : ''}
        </div>
      </section>`).join('');
    fenetre.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>${echapper(donnees.bon.numero)}</title><style>
      @page { size: 76mm 51mm; margin: 0; }
      body { margin: 0; font-family: sans-serif; }
      .etiquette { width: 76mm; height: 51mm; box-sizing: border-box; padding: 3mm; display: flex; gap: 3mm; page-break-after: always; overflow: hidden; }
      img { width: 22mm; height: 22mm; }
      .numero { font-size: 15px; font-weight: bold; }
      .rang { font-size: 11px; margin-bottom: 2mm; }
      .client { font-size: 12px; font-weight: bold; }
      .bon, .contenu { font-size: 9px; color: #333; }
    </style></head><body onload="window.print()">${etiquettes}</body></html>`);
    fenetre.document.close();
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" role="dialog" aria-modal="true" aria-label={tc.title.replace('{numero}', donnees?.bon.numero ?? '')}>
      <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-xl font-bold dark:text-white">{tc.title.replace('{numero}', donnees?.bon.numero ?? '')}</h2>
          <button onClick={onClose} aria-label={tc.close}><X size={20} /></button>
        </div>
        {erreur && <p role="alert" className="mb-3 px-3 py-2 rounded bg-red-50 text-red-700 text-sm">{erreur}</p>}
        {!donnees ? <p className="text-sm text-gray-500">{tc.loading}</p> : (
          <>
            <div className="space-y-2">
              {donnees.colis.length === 0 && <p className="text-sm text-gray-500 italic">{tc.none}</p>}
              {donnees.colis.map(c => (
                <div key={c.id} className="flex items-start justify-between gap-3 p-2 rounded bg-gray-50 dark:bg-gray-700">
                  <div className="min-w-0">
                    <p className="font-mono text-sm font-medium dark:text-white">{c.numero}{c.charge_le && <span className="ml-2 text-xs text-green-700">{tc.loaded}</span>}</p>
                    <p className="text-sm text-gray-600 dark:text-gray-300 break-words">{contenu(c)}</p>
                    {(c.poids_kg != null || c.notes) && <p className="text-xs text-gray-500">{[c.poids_kg != null && `${Number(c.poids_kg)} kg`, c.notes].filter(Boolean).join(' — ')}</p>}
                  </div>
                  <div className="flex shrink-0 gap-1">
                    <button type="button" onClick={() => imprimer([c])} className="p-1 text-gray-600 hover:bg-gray-100 rounded dark:text-gray-300" title={tc.printLabel} aria-label={`${tc.printLabel} — ${c.numero}`}><Printer size={16} /></button>
                    {modifiable && <button type="button" onClick={() => ouvrirModification(c)} className="p-1 text-blue-600 hover:bg-blue-50 rounded" title={tc.edit} aria-label={`${tc.edit} — ${c.numero}`}><Pencil size={16} /></button>}
                    {modifiable && <button type="button" onClick={() => supprimer(c)} className="p-1 text-red-600 hover:bg-red-50 rounded" title={tc.delete} aria-label={`${tc.delete} — ${c.numero}`}><Trash2 size={16} /></button>}
                  </div>
                </div>
              ))}
            </div>

            <div className="mt-3 text-sm text-gray-600 dark:text-gray-300">
              {tc.remaining}{' '}
              {donnees.lignes.map(l => `${l.produit?.description ?? '?'} ${l.restant}/${l.quantite_a_livrer}`).join(' · ')}
            </div>

            {saisie ? (
              <form onSubmit={enregistrer} className="mt-4 border-t pt-4 space-y-2 dark:border-gray-700">
                <p className="text-sm font-medium dark:text-white">{saisie.id ? tc.editTitle : tc.newTitle}</p>
                {donnees.lignes.map(l => (
                  <label key={l.id} className="flex items-center justify-between gap-3 text-sm dark:text-gray-200">
                    <span className="min-w-0 truncate">{l.produit?.numero} — {l.produit?.description} <span className="text-xs text-gray-500">({tc.max} {maximum(l.id)})</span></span>
                    <input type="number" min={0} max={maximum(l.id)} value={saisie.quantites[l.id] ?? ''} placeholder="0"
                      onChange={e => setSaisie({ ...saisie, quantites: { ...saisie.quantites, [l.id]: e.target.value } })}
                      className={`${classeChamp} w-24 text-right`} aria-label={`${tc.quantity} — ${l.produit?.description ?? ''}`} />
                  </label>
                ))}
                <div className="grid grid-cols-2 gap-2">
                  <input type="number" min={0} step="any" value={saisie.poids} onChange={e => setSaisie({ ...saisie, poids: e.target.value })} placeholder={tc.weight} aria-label={tc.weight} className={classeChamp} />
                  <input value={saisie.notes} onChange={e => setSaisie({ ...saisie, notes: e.target.value })} maxLength={500} placeholder={tc.notes} aria-label={tc.notes} className={classeChamp} />
                </div>
                <div className="flex justify-end gap-2">
                  <button type="button" onClick={() => setSaisie(null)} className="px-3 py-1.5 border rounded-lg text-sm dark:border-gray-600 dark:text-white">{tc.cancel}</button>
                  <button type="submit" disabled={envoi} className="px-3 py-1.5 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700 disabled:opacity-50">{tc.save}</button>
                </div>
              </form>
            ) : (
              <div className="mt-4 flex flex-wrap justify-end gap-2">
                {donnees.colis.length > 0 && (
                  <button type="button" onClick={() => imprimer(donnees.colis)} className="inline-flex items-center gap-1 px-3 py-1.5 border rounded-lg text-sm dark:border-gray-600 dark:text-white">
                    <Printer size={14} /> {tc.printAll}
                  </button>
                )}
                {modifiable && donnees.lignes.some(l => l.restant > 0) && (
                  <button type="button" onClick={ouvrirNouveau} className="px-3 py-1.5 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700">{tc.add}</button>
                )}
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
