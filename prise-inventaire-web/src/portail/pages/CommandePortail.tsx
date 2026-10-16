import { useCallback, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Download, FileText, RotateCcw, XCircle } from 'lucide-react';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { telecharger, type DetailCommande } from '../api';
import { useDonnees, usePortail } from '../contexte';
import { BadgeCommande, Chargement, Message } from '../composants';
import { jour, montant, remplir } from '../format';

const boutonSecondaire = 'inline-flex items-center gap-1.5 px-3 py-2 text-sm border border-gray-300 rounded-lg bg-white text-gray-700 hover:bg-gray-50 disabled:opacity-50';

/** Détail d'une commande : état, lignes, montants, documents ; annulation tant qu'elle attend. */
export default function CommandePortail() {
  const { t, language } = useLanguage();
  const tp = t.portal;
  const { id } = useParams();
  const { base, infos, appel, moi, ajouterAuPanier } = usePortail();
  const navigate = useNavigate();
  const nouvelle = (useLocation().state as { nouvelle?: string } | null)?.nouvelle;
  const charger = useCallback(() => appel<DetailCommande>(`/espace-client/commandes/${id}`), [appel, id]);
  const { donnees, erreur, recharger } = useDonnees(charger);
  const [confirmer, setConfirmer] = useState(false);
  const [message, setMessage] = useState<{ type: 'succes' | 'erreur'; texte: string } | null>(null);
  const [occupe, setOccupe] = useState(false);
  const devise = moi?.entreprise.devise;

  async function pdf(chemin: string, nom: string) {
    try {
      telecharger(await appel<Blob>(chemin, { fichier: true }), nom);
    } catch (e) {
      setMessage({ type: 'erreur', texte: messageErreur(e, tp.error) });
    }
  }

  async function annuler() {
    setOccupe(true);
    try {
      await appel(`/espace-client/commandes/${id}/annuler`, { methode: 'POST' });
      setMessage({ type: 'succes', texte: tp.cancelled });
      recharger();
    } catch (e) {
      setMessage({ type: 'erreur', texte: messageErreur(e, tp.error) });
    } finally {
      setOccupe(false);
      setConfirmer(false);
    }
  }

  const retour = (
    <Link to={`${base}/commandes`} className="inline-flex items-center gap-1 text-sm text-blue-600 hover:underline"><ArrowLeft size={16} /> {tp.backToOrders}</Link>
  );
  if (!donnees) {
    return erreur !== undefined
      ? <div className="space-y-4">{retour}<Message type="erreur">{messageErreur(erreur, tp.error)}</Message></div>
      : <Chargement />;
  }

  const { commande, facture } = donnees;
  // Commander à nouveau : les produits de la commande vont au panier, où les prix du jour sont repris.
  const recommander = () => {
    commande.lignes.forEach(ligne => {
      if (ligne.produit) {
        ajouterAuPanier({ produit_id: ligne.produit_id, numero: ligne.produit.numero, description: ligne.produit.description, mesure: ligne.produit.mesure, prix: null }, ligne.quantite);
      }
    });
    navigate(`${base}/panier`);
  };

  return (
    <div className="space-y-5">
      {retour}
      {nouvelle && !message && commande.statut === 'en_attente' && <Message type="succes">{remplir(tp.orderSent, { numero: nouvelle, entreprise: infos.entreprise })}</Message>}
      {message && <Message type={message.type}>{message.texte}</Message>}

      <section className="bg-white rounded-xl border border-gray-200 p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-xl font-bold">{remplir(tp.order, { numero: commande.numero })}</h1>
            <p className="text-sm text-gray-500">
              {remplir(tp.orderedOn, { date: jour(commande.date_commande, language) })}
              {commande.date_livraison_souhaitee && ` · ${remplir(tp.desiredOn, { date: jour(commande.date_livraison_souhaitee, language) })}`}
            </p>
          </div>
          <BadgeCommande statut={commande.statut} />
        </div>
        <p className="mt-3 text-sm text-gray-700">{remplir(tp.statusHelp[commande.statut] ?? '', { entreprise: infos.entreprise })}</p>
        {commande.statut === 'refusee' && commande.motif_refus && (
          <p className="mt-1 text-sm text-red-700"><span className="font-medium">{tp.refusalReason} :</span> {commande.motif_refus}</p>
        )}
        <div className="mt-4 flex flex-wrap gap-2">
          <button type="button" className={boutonSecondaire} onClick={() => { void pdf(`/espace-client/commandes/${commande.id}/pdf`, `commande-${commande.numero}.pdf`); }}>
            <Download size={16} /> {tp.downloadPdf}
          </button>
          {facture && (
            <button type="button" className={boutonSecondaire} onClick={() => { void pdf(`/espace-client/factures/${facture.id}/pdf`, `facture-${facture.numero}.pdf`); }}>
              <FileText size={16} /> {remplir(tp.invoice, { numero: facture.numero })}
            </button>
          )}
          <button type="button" className={boutonSecondaire} onClick={recommander}><RotateCcw size={16} /> {tp.reorder}</button>
          {commande.annulable && !confirmer && (
            <button type="button" onClick={() => setConfirmer(true)} className="inline-flex items-center gap-1.5 px-3 py-2 text-sm border border-red-200 rounded-lg bg-white text-red-700 hover:bg-red-50">
              <XCircle size={16} /> {tp.cancelOrder}
            </button>
          )}
        </div>
        {confirmer && (
          <div className="mt-3 p-3 bg-red-50 border border-red-200 rounded-lg flex flex-wrap items-center gap-3 text-sm">
            <span className="flex-1 min-w-[12rem] text-red-800">{tp.cancelConfirm}</span>
            <button type="button" onClick={() => { void annuler(); }} disabled={occupe} className="px-3 py-1.5 bg-red-600 text-white rounded-lg font-medium hover:bg-red-700 disabled:opacity-50">{tp.cancelYes}</button>
            <button type="button" onClick={() => setConfirmer(false)} className="px-3 py-1.5 border border-gray-300 rounded-lg bg-white text-gray-700">{tp.no}</button>
          </div>
        )}
      </section>

      <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 text-xs uppercase text-gray-500">
              <tr>
                <th className="px-4 py-2 text-left font-medium">{tp.product}</th>
                <th className="px-4 py-2 text-right font-medium">{tp.qty}</th>
                <th className="px-4 py-2 text-right font-medium hidden sm:table-cell">{tp.unitPrice}</th>
                <th className="px-4 py-2 text-right font-medium">{tp.amount}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {commande.lignes.map((ligne, index) => (
                <tr key={index}>
                  <td className="px-4 py-2">
                    <p>{ligne.produit?.description ?? '—'}</p>
                    {ligne.produit && <p className="text-xs text-gray-500">{ligne.produit.numero}</p>}
                  </td>
                  <td className="px-4 py-2 text-right whitespace-nowrap">{ligne.quantite}{ligne.produit?.mesure ? ` ${ligne.produit.mesure}` : ''}</td>
                  <td className="px-4 py-2 text-right whitespace-nowrap hidden sm:table-cell">{montant(ligne.prix_unitaire_ht, language, devise)}</td>
                  <td className="px-4 py-2 text-right whitespace-nowrap">{montant(ligne.montant_ht, language, devise)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <dl className="border-t border-gray-100 px-4 py-3 text-sm space-y-1 sm:w-80 sm:ml-auto">
          <div className="flex justify-between gap-4"><dt className="text-gray-600">{tp.subtotal}</dt><dd className="whitespace-nowrap">{montant(commande.montant_ht, language, devise)}</dd></div>
          <div className="flex justify-between gap-4"><dt className="text-gray-600">{tp.taxes}</dt><dd className="whitespace-nowrap">{montant(commande.montant_tva, language, devise)}</dd></div>
          <div className="flex justify-between gap-4 font-semibold text-base"><dt>{tp.total}</dt><dd className="whitespace-nowrap">{montant(commande.montant_ttc, language, devise)}</dd></div>
        </dl>
      </section>

      {commande.notes && (
        <section className="bg-white rounded-xl border border-gray-200 p-5">
          <h2 className="font-semibold mb-2">{tp.remarks}</h2>
          <p className="text-sm text-gray-700 whitespace-pre-line">{commande.notes}</p>
        </section>
      )}
    </div>
  );
}
