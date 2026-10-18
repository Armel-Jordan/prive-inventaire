import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { AlertTriangle, Loader2, ShoppingCart, Trash2 } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import type { LignePrix } from '../api';
import { usePortail } from '../contexte';
import { ChampQuantite, Message } from '../composants';
import { aujourdhui, montant, remplir } from '../format';

const classeChamp = 'w-full mt-1 px-3 py-2 border border-gray-300 rounded-lg bg-white text-sm text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500';

/**
 * Panier : prix du client recalculés par l'entreprise à chaque changement (règles de quantité), livraison,
 * puis envoi de la commande, qui arrive en attente de confirmation.
 */
export default function PanierPortail() {
  const { t, language } = useLanguage();
  const tp = t.portal;
  const { infos, appel, moi, panier, changerQuantite, retirerDuPanier, viderPanier } = usePortail();
  const navigate = useNavigate();
  const lignes = useMemo(() => panier.map(({ produit_id, quantite }) => ({ produit_id, quantite })), [panier]);
  const cle = JSON.stringify(lignes);
  const [versionPrix, setVersionPrix] = useState(0);
  const [prix, setPrix] = useState<{ cle: string; lignes: LignePrix[] } | null>(null);
  const [erreurPrix, setErreurPrix] = useState('');
  const [adresse, setAdresse] = useState<string | null>(null);
  const [date, setDate] = useState('');
  const [notes, setNotes] = useState('');
  const [erreur, setErreur] = useState('');
  const [envoi, setEnvoi] = useState(false);
  const [confirmerVider, setConfirmerVider] = useState(false);

  useEffect(() => {
    if (!lignes.length) return;
    let actif = true;
    const minuterie = setTimeout(() => {
      appel<{ lignes: LignePrix[] }>('/espace-client/prix', { methode: 'POST', corps: { lignes } }).then(
        reponse => { if (actif) { setPrix({ cle, lignes: reponse.lignes }); setErreurPrix(''); } },
        e => { if (actif) setErreurPrix(messageErreur(e, tp.error)); },
      );
    }, 300);
    return () => { actif = false; clearTimeout(minuterie); };
  }, [appel, lignes, cle, versionPrix, tp.error]);

  const devise = moi?.entreprise.devise;
  const aJour = prix?.cle === cle;
  const prixDe = new Map((prix?.lignes ?? []).map(l => [l.produit_id, l]));
  const details = panier.map(article => {
    const calcul = prixDe.get(article.produit_id);
    const unitaire = calcul ? calcul.prix : article.prix;
    return {
      article,
      unitaire,
      montant: aJour && calcul ? calcul.montant : unitaire !== null ? unitaire * article.quantite : null,
      indisponible: Boolean(calcul?.indisponible),
      // Référence du produit chez le client, donnée avec les prix du panier.
      reference: calcul?.reference_client ?? null,
    };
  });
  const total = details.reduce((somme, d) => somme + (d.indisponible ? 0 : d.montant ?? 0), 0);
  const indisponibles = aJour && details.some(d => d.indisponible);

  // Adresse proposée : la principale de livraison, sinon la première de livraison, sinon l'adresse habituelle.
  const adresses = moi?.client.adresses ?? [];
  const parDefaut = adresses.find(a => a.livraison && a.principale) ?? adresses.find(a => a.livraison);
  const adresseChoisie = adresse ?? (parDefaut ? String(parDefaut.id) : '');

  async function commander() {
    setErreur('');
    setEnvoi(true);
    try {
      const { commande } = await appel<{ commande: { id: number; numero: string } }>('/espace-client/commandes', {
        methode: 'POST',
        corps: {
          lignes,
          adresse_livraison_id: adresseChoisie ? Number(adresseChoisie) : null,
          date_livraison_souhaitee: date || null,
          notes: notes.trim() || null,
        },
      });
      viderPanier();
      navigate(`/commandes/${commande.id}`, { state: { nouvelle: commande.numero } });
    } catch (e) {
      setErreur(messageErreur(e, tp.error));
      setEnvoi(false);
      // Un produit a pu être retiré entre-temps : les prix sont repris.
      setVersionPrix(v => v + 1);
    }
  }

  if (!panier.length) {
    return (
      <div className="text-center py-16">
        <ShoppingCart size={40} className="mx-auto mb-3 text-gray-300" />
        <p className="text-gray-600">{tp.emptyCart}</p>
        <Link to="/" className="inline-block mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">{tp.browseCatalogue}</Link>
      </div>
    );
  }

  return (
    <div>
      <h1 className="text-2xl font-bold mb-5">{tp.cartTitle}</h1>
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
        <div className="lg:col-span-2 space-y-3">
          {erreurPrix && <Message type="erreur">{erreurPrix}</Message>}
          <ul className={`bg-white rounded-xl border border-gray-200 divide-y divide-gray-100 ${aJour ? '' : 'opacity-75'}`}>
            {details.map(({ article, unitaire, montant: montantLigne, indisponible, reference }) => (
              <li key={article.produit_id} className="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                <div className="flex-1 min-w-0">
                  <p className="font-medium leading-snug">{article.description}</p>
                  <p className="text-xs text-gray-500">
                    {article.numero}{article.mesure ? ` · ${article.mesure}` : ''}{reference ? ` · ${remplir(tp.yourReference, { ref: reference })}` : ''}
                  </p>
                  {unitaire !== null && !indisponible && <p className="text-xs text-gray-500">{tp.unitPrice} : {montant(unitaire, language, devise)}</p>}
                  {indisponible && <p className="text-xs text-red-600 flex items-center gap-1 mt-1"><AlertTriangle size={12} /> {tp.unavailable}</p>}
                </div>
                <div className="flex items-center justify-between sm:justify-end gap-3">
                  <ChampQuantite valeur={article.quantite} onChange={q => changerQuantite(article.produit_id, q)} />
                  <span className="w-32 text-right font-medium whitespace-nowrap">
                    {montantLigne !== null && !indisponible ? montant(montantLigne, language, devise) : '—'}
                  </span>
                  <button type="button" onClick={() => retirerDuPanier(article.produit_id)} className="p-2 text-gray-400 hover:text-red-600" title={tp.remove} aria-label={tp.remove}>
                    <Trash2 size={18} />
                  </button>
                </div>
              </li>
            ))}
          </ul>
          <div className="text-sm">
            {confirmerVider ? (
              <span className="inline-flex items-center gap-3">
                {tp.clearCartConfirm}
                <button type="button" onClick={() => { viderPanier(); setConfirmerVider(false); }} className="font-medium text-red-600 hover:underline">{tp.yes}</button>
                <button type="button" onClick={() => setConfirmerVider(false)} className="font-medium text-gray-600 hover:underline">{tp.no}</button>
              </span>
            ) : (
              <button type="button" onClick={() => setConfirmerVider(true)} className="text-gray-500 hover:text-red-600 hover:underline">{tp.clearCart}</button>
            )}
          </div>
        </div>

        <aside className="bg-white rounded-xl border border-gray-200 p-4 space-y-4 lg:sticky lg:top-20">
          <div>
            <div className="flex items-baseline justify-between gap-3">
              <span className="text-sm text-gray-600">{tp.totalExclTax}</span>
              <span className="text-xl font-bold whitespace-nowrap">{montant(total, language, devise)}</span>
            </div>
            {!aJour && !erreurPrix && <p className="mt-1 text-xs text-gray-500 flex items-center gap-1"><Loader2 size={12} className="animate-spin" /> {tp.updatingPrices}</p>}
            <p className="mt-2 text-xs text-gray-500">{remplir(tp.taxesNote, { entreprise: infos.entreprise })}</p>
          </div>

          <div className="pt-4 border-t border-gray-100 space-y-3">
            <h2 className="font-semibold">{tp.delivery}</h2>
            <label className="block text-sm text-gray-700">{tp.deliveryAddress}
              <select value={adresseChoisie} onChange={e => setAdresse(e.target.value)} className={classeChamp}>
                <option value="">{tp.usualAddress}</option>
                {adresses.map(a => (
                  <option key={a.id} value={a.id}>{[a.libelle, a.adresse, a.ville].filter(Boolean).join(' — ')}</option>
                ))}
              </select>
            </label>
            <label className="block text-sm text-gray-700">{tp.desiredDate}
              <input type="date" min={aujourdhui()} value={date} onChange={e => setDate(e.target.value)} className={classeChamp} />
            </label>
            <label className="block text-sm text-gray-700">{tp.notes}
              <textarea rows={3} maxLength={2000} value={notes} onChange={e => setNotes(e.target.value)} placeholder={tp.notesPlaceholder} className={classeChamp} />
            </label>
          </div>

          {erreur && <Message type="erreur">{erreur}</Message>}
          <button type="button" onClick={() => { void commander(); }} disabled={envoi || !aJour || indisponibles}
            className="w-full py-3 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 disabled:opacity-50">
            {envoi ? tp.placing : tp.placeOrder}
          </button>
        </aside>
      </div>
    </div>
  );
}
