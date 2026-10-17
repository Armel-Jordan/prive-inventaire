import { useEffect, useState } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { Check, Download, FileText, Mail, Phone, Store, X } from 'lucide-react';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import LanguageSelector from '@/components/LanguageSelector';
import { messageErreur } from '@/lib/erreurs';
import { appelPortail, telecharger } from '../api';
import { Chargement, Message } from '../composants';
import { jour, montant, remplir } from '../format';

type StatutDevis = 'brouillon' | 'envoye' | 'accepte' | 'refuse' | 'expire';

/** Ce que renvoie l'API pour le lien d'un devis (GET /devis-en-ligne/{jeton}). */
interface PageDevis {
  entreprise: { nom: string; logo_url: string | null; telephone: string | null; email: string | null };
  devis: {
    numero: string;
    date_devis: string;
    date_validite: string;
    statut: StatutDevis;
    devise: string | null;
    montant_total: number;
    notes: string | null;
    client: string | null;
    lignes: { description: string; quantite: number; prix_unitaire: number; montant: number }[];
  };
  reponse: { le: string; en_ligne: boolean; nom: string | null } | null;
  peut_repondre: boolean;
}

type Action = 'accepter' | 'refuser';

const STYLES_STATUT: Record<StatutDevis, string> = {
  brouillon: 'bg-gray-100 text-gray-700',
  envoye: 'bg-blue-100 text-blue-800',
  accepte: 'bg-green-100 text-green-800',
  refuse: 'bg-red-100 text-red-800',
  expire: 'bg-amber-100 text-amber-800',
};

const classeChamp = 'w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-gray-900';

/**
 * Réponse à un devis, ouverte par le lien de son courriel : le client le consulte, télécharge le PDF, l'accepte ou le
 * refuse, sans compte et même si l'espace client de l'entreprise n'est pas ouvert. Le lien seul donne accès au devis.
 */
export default function ReponseDevis() {
  const { jeton = '' } = useParams();
  const [parametres] = useSearchParams();
  const { t, language } = useLanguage();
  const tq = t.quote;
  const lienValide = /^[A-Za-z0-9]{64}$/.test(jeton);
  const [page, setPage] = useState<PageDevis | null>(null);
  const [erreur, setErreur] = useState<'invalide' | 'reseau' | null>(lienValide ? null : 'invalide');
  const [essai, setEssai] = useState(0);
  // Bouton du courriel (?reponse=accepter|refuser) : la réponse choisie est déjà ouverte.
  const [action, setAction] = useState<Action | null>(() => {
    const reponse = parametres.get('reponse');
    return reponse === 'accepter' || reponse === 'refuser' ? reponse : null;
  });
  const [form, setForm] = useState({ nom: '', commentaire: '' });
  const [envoi, setEnvoi] = useState(false);
  const [message, setMessage] = useState<{ type: 'succes' | 'erreur'; texte: string } | null>(null);

  useEffect(() => {
    if (!lienValide) return;
    let actif = true;
    appelPortail<PageDevis>(`/devis-en-ligne/${jeton}`).then(
      donnees => { if (actif) { setPage(donnees); setErreur(null); } },
      e => { if (actif) setErreur(e instanceof ApiError && e.status === 404 ? 'invalide' : 'reseau'); },
    );
    return () => { actif = false; };
  }, [jeton, lienValide, essai]);

  useEffect(() => {
    if (page) document.title = `${remplir(tq.title, { numero: page.devis.numero })} — ${page.entreprise.nom}`;
  }, [page, tq.title]);

  async function repondre(e: React.FormEvent) {
    e.preventDefault();
    if (!action || !page) return;
    setEnvoi(true);
    setMessage(null);
    try {
      const resultat = await appelPortail<PageDevis>(`/devis-en-ligne/${jeton}/${action}`, {
        methode: 'POST',
        corps: { nom: form.nom.trim() || null, commentaire: form.commentaire.trim() || null },
      });
      setPage(resultat);
      setAction(null);
      setMessage({ type: 'succes', texte: remplir(action === 'accepter' ? tq.accepted : tq.declined, { entreprise: resultat.entreprise.nom }) });
    } catch (err) {
      // Devis décidé ou expiré entre-temps : la page reprend son état.
      const donnees = err instanceof ApiError ? (err.data as Partial<PageDevis> | null) : null;
      if (donnees?.devis) {
        setPage(donnees as PageDevis);
        setAction(null);
      }
      setMessage({ type: 'erreur', texte: err instanceof ApiError && err.status === 429 ? tq.tooManyAttempts : messageErreur(err, tq.error) });
    } finally {
      setEnvoi(false);
    }
  }

  async function telechargerPdf() {
    if (!page) return;
    try {
      telecharger(await appelPortail<Blob>(`/devis-en-ligne/${jeton}/pdf`, { fichier: true }), `devis-${page.devis.numero}.pdf`);
    } catch {
      setMessage({ type: 'erreur', texte: tq.pdfError });
    }
  }

  if (erreur || !page) {
    return (
      <Fond>
        {erreur ? (
          <div className="bg-white rounded-2xl shadow-sm border border-gray-200 p-8 text-center">
            <div className="inline-flex items-center justify-center w-14 h-14 bg-gray-100 rounded-full mb-4"><FileText className="w-7 h-7 text-gray-500" /></div>
            <p className="text-sm text-gray-700">{erreur === 'invalide' ? tq.invalid : tq.offline}</p>
            {erreur === 'reseau' && (
              <button type="button" onClick={() => { setErreur(null); setEssai(n => n + 1); }} className="mt-5 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">{tq.retry}</button>
            )}
          </div>
        ) : <Chargement />}
      </Fond>
    );
  }

  const { entreprise, devis, reponse } = page;
  const prix = (valeur: number) => montant(valeur, language, devis.devise);
  // Moment de la réponse, à l'heure du visiteur (l'API le donne en UTC).
  const moment = (iso: string) => new Date(iso).toLocaleString(language === 'en' ? 'en-US' : 'fr-FR', { dateStyle: 'medium', timeStyle: 'short' });

  return (
    <Fond>
      <div className="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
        {/* En-tête : l'entreprise et le devis */}
        <div className="p-5 sm:p-6 border-b border-gray-100 flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-center gap-3 min-w-0">
            {entreprise.logo_url
              ? <img src={entreprise.logo_url} alt="" className="h-12 w-auto max-w-[8rem] object-contain" />
              : <div className="inline-flex items-center justify-center w-12 h-12 bg-blue-100 rounded-full shrink-0"><Store className="w-6 h-6 text-blue-600" /></div>}
            <div className="min-w-0">
              <p className="text-sm text-gray-500 truncate">{entreprise.nom}</p>
              <h1 className="text-xl font-bold text-gray-900">{remplir(tq.title, { numero: devis.numero })}</h1>
            </div>
          </div>
          <span className={`px-3 py-1 rounded-full text-xs font-semibold ${STYLES_STATUT[devis.statut]}`}>{tq.statuses[devis.statut] ?? devis.statut}</span>
        </div>

        <dl className="px-5 sm:px-6 py-4 grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
          <div><dt className="text-gray-500">{tq.date}</dt><dd className="font-medium text-gray-900">{jour(devis.date_devis, language)}</dd></div>
          <div><dt className="text-gray-500">{tq.validUntil}</dt><dd className="font-medium text-gray-900">{jour(devis.date_validite, language)}</dd></div>
          {devis.client && <div className="col-span-2 sm:col-span-1"><dt className="text-gray-500">{tq.customer}</dt><dd className="font-medium text-gray-900 break-words">{devis.client}</dd></div>}
        </dl>

        {/* Lignes */}
        <div className="px-5 sm:px-6">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-200 text-left text-gray-500">
                <th className="py-2 pr-2 font-medium">{tq.product}</th>
                <th className="py-2 px-2 font-medium text-right">{tq.quantity}</th>
                <th className="py-2 px-2 font-medium text-right hidden sm:table-cell">{tq.unitPrice}</th>
                <th className="py-2 pl-2 font-medium text-right">{tq.amount}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {devis.lignes.map((ligne, index) => (
                <tr key={index}>
                  <td className="py-2 pr-2 text-gray-900 break-words">{ligne.description}</td>
                  <td className="py-2 px-2 text-right whitespace-nowrap">{ligne.quantite.toLocaleString(language === 'en' ? 'en-US' : 'fr-FR')}</td>
                  <td className="py-2 px-2 text-right whitespace-nowrap hidden sm:table-cell">{prix(ligne.prix_unitaire)}</td>
                  <td className="py-2 pl-2 text-right whitespace-nowrap">{prix(ligne.montant)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="flex justify-between gap-4 border-t border-gray-200 py-3 font-semibold text-gray-900">
            <span>{tq.total}</span>
            <span className="whitespace-nowrap">{prix(devis.montant_total)}</span>
          </div>
          {devis.notes && (
            <div className="pb-4 text-sm">
              <p className="text-gray-500">{tq.notes}</p>
              <p className="text-gray-800 whitespace-pre-line break-words">{devis.notes}</p>
            </div>
          )}
        </div>

        <div className="px-5 sm:px-6 pb-5">
          <button type="button" onClick={telechargerPdf} className="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
            <Download size={16} /> {tq.downloadPdf}
          </button>
        </div>

        {/* Réponse */}
        <div className="px-5 sm:px-6 py-5 bg-gray-50 border-t border-gray-100 space-y-3">
          {message && <Message type={message.type}>{message.texte}</Message>}
          {page.peut_repondre ? (
            <>
              <div>
                <h2 className="font-semibold text-gray-900">{tq.answerTitle}</h2>
                <p className="text-sm text-gray-600">{remplir(tq.answerHelp, { entreprise: entreprise.nom })}</p>
              </div>
              {action ? (
                <form onSubmit={repondre} className="space-y-3">
                  <div>
                    <label htmlFor="devis-nom" className="block text-sm font-medium text-gray-700 mb-1">{tq.yourName}{action === 'accepter' ? ' *' : ''}</label>
                    <input id="devis-nom" value={form.nom} onChange={e => setForm(f => ({ ...f, nom: e.target.value }))} required={action === 'accepter'} maxLength={150} autoComplete="name" className={classeChamp} />
                  </div>
                  <div>
                    <label htmlFor="devis-commentaire" className="block text-sm font-medium text-gray-700 mb-1">{action === 'accepter' ? tq.comment : tq.reason}</label>
                    <textarea id="devis-commentaire" rows={3} value={form.commentaire} onChange={e => setForm(f => ({ ...f, commentaire: e.target.value }))} maxLength={2000} className={classeChamp} />
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <button type="submit" disabled={envoi}
                      className={`inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white disabled:opacity-50 ${action === 'accepter' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'}`}>
                      {action === 'accepter' ? <Check size={16} /> : <X size={16} />} {action === 'accepter' ? tq.confirmAccept : tq.confirmDecline}
                    </button>
                    <button type="button" onClick={() => setAction(null)} disabled={envoi} className="px-4 py-2.5 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">{tq.cancel}</button>
                  </div>
                </form>
              ) : (
                <div className="flex flex-wrap gap-2">
                  <button type="button" onClick={() => setAction('accepter')} className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-green-600 hover:bg-green-700">
                    <Check size={16} /> {tq.accept}
                  </button>
                  <button type="button" onClick={() => setAction('refuser')} className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-red-700 bg-white border border-red-300 hover:bg-red-50">
                    <X size={16} /> {tq.decline}
                  </button>
                </div>
              )}
            </>
          ) : (
            <p className="text-sm text-gray-700">
              {reponse
                ? remplir(reponse.nom ? tq.answeredBy : tq.answered, { date: moment(reponse.le), nom: reponse.nom ?? '' })
                : devis.statut === 'expire'
                  ? remplir(tq.expired, { date: jour(devis.date_validite, language), entreprise: entreprise.nom })
                  : tq.closed}
            </p>
          )}
        </div>
      </div>

      {(entreprise.telephone || entreprise.email) && (
        <div className="mt-4 text-center text-xs text-gray-600 space-y-1">
          <p className="font-medium">{remplir(tq.contact, { entreprise: entreprise.nom })}</p>
          <div className="flex flex-wrap justify-center gap-x-4 gap-y-1">
            {entreprise.telephone && <a href={`tel:${entreprise.telephone.replace(/\s/g, '')}`} className="flex items-center gap-1 hover:text-gray-900"><Phone size={12} /> {entreprise.telephone}</a>}
            {entreprise.email && <a href={`mailto:${entreprise.email}`} className="flex items-center gap-1 hover:text-gray-900"><Mail size={12} /> {entreprise.email}</a>}
          </div>
        </div>
      )}
    </Fond>
  );
}

function Fond({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 text-gray-900">
      <div className="flex justify-end p-3"><LanguageSelector /></div>
      <main className="max-w-3xl mx-auto px-4 pb-10">{children}</main>
    </div>
  );
}
