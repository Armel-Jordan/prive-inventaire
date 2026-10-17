import { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { X, Send, Paperclip, RotateCw } from 'lucide-react';
import {
  preparerCourriel, envoyerCourriel, getEnvoisCourriel, reessayerEnvoiCourriel, ouvrirPdf,
  type TypeDocumentCourriel, type LangueCourriel, type EnvoiCourriel,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import BadgeStatutCourriel from '@/components/BadgeStatutCourriel';

/** Préfixe des routes PDF des documents de vente (récupérés avec l'en-tête d'authentification). */
const ROUTES_PDF: Record<TypeDocumentCourriel, string> = {
  devis: 'devis',
  commande_client: 'commandes-client',
  facture: 'factures',
  avoir: 'avoirs',
  demande_acompte: 'demandes-acompte',
  // La relance joint la facture relancée.
  relance: 'factures',
  // La relance d'un devis joint le devis.
  relance_devis: 'devis',
  bon_livraison: 'bons-livraison',
  commande_fournisseur: 'commandes-fournisseur',
  retour_fournisseur: 'retours-fournisseurs',
};

const adresses = (texte: string) => texte.split(/[\s,;]+/).map(a => a.trim()).filter(Boolean);

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm disabled:opacity-60';

interface CompteRendu {
  erreur: boolean;
  texte: string;
  envoi?: EnvoiCourriel;
}

/**
 * Fenêtre d'envoi d'un document par courriel : pré-remplie depuis le modèle de l'entreprise
 * (destinataire, objet, texte, PDF joint), modifiable avant l'envoi ; liste des envois précédents.
 */
export default function ComposerCourriel({ type, documentId, onClose }: { type: TypeDocumentCourriel; documentId: number; onClose: () => void }) {
  const { t, language } = useLanguage();
  const tc = t.emails;
  const locale = language === 'en' ? 'en-US' : 'fr-FR';

  const [langue, setLangue] = useState<LangueCourriel>(language);
  const [chargement, setChargement] = useState(true);
  const [numero, setNumero] = useState('');
  const [personnalise, setPersonnalise] = useState(false);
  const [pieceJointe, setPieceJointe] = useState<string | null>(null);
  const [statutDocument, setStatutDocument] = useState<string | null>(null);
  const [joindrePdf, setJoindrePdf] = useState(true);
  const [destinataires, setDestinataires] = useState('');
  const [copies, setCopies] = useState('');
  const [copiesCachees, setCopiesCachees] = useState('');
  const [afficherCopies, setAfficherCopies] = useState(false);
  const [objet, setObjet] = useState('');
  const [contenu, setContenu] = useState('');
  const [envoiEnCours, setEnvoiEnCours] = useState(false);
  const [compteRendu, setCompteRendu] = useState<CompteRendu | null>(null);
  const [historique, setHistorique] = useState<EnvoiCourriel[]>([]);

  const messageErreur = useCallback((erreur: unknown) => (erreur instanceof ApiError ? erreur.message : tc.error), [tc.error]);

  // Pré-remplissage, à nouveau quand la langue du modèle change (les destinataires saisis sont gardés).
  useEffect(() => {
    let actif = true;
    preparerCourriel(type, documentId, langue)
      .then(p => {
        if (!actif) return;
        setNumero(p.document_numero);
        setPersonnalise(p.modele_personnalise);
        setPieceJointe(p.piece_jointe);
        setStatutDocument(p.statut_document ?? null);
        setDestinataires(saisis => saisis || p.destinataires.join(', '));
        setObjet(p.objet);
        setContenu(p.contenu);
      })
      .catch(erreur => { if (actif) setCompteRendu({ erreur: true, texte: messageErreur(erreur) }); })
      .finally(() => { if (actif) setChargement(false); });
    return () => { actif = false; };
  }, [type, documentId, langue, messageErreur]);

  const chargerHistorique = useCallback(
    () => getEnvoisCourriel({ type, document_id: documentId }).then(page => page.data),
    [type, documentId],
  );

  useEffect(() => {
    let actif = true;
    chargerHistorique().then(envois => { if (actif) setHistorique(envois); }).catch(() => {});
    return () => { actif = false; };
  }, [chargerHistorique]);

  function resume(envoi: EnvoiCourriel): CompteRendu {
    if (envoi.statut === 'envoye') return { erreur: false, texte: tc.sentTo.replace('{adresses}', envoi.destinataires.join(', ')), envoi };
    if (envoi.statut === 'echec') return { erreur: true, texte: `${tc.failed} : ${envoi.erreur ?? ''}`, envoi };
    return { erreur: false, texte: tc.queued, envoi };
  }

  async function executer(action: () => Promise<EnvoiCourriel>) {
    setEnvoiEnCours(true);
    setCompteRendu(null);
    try {
      setCompteRendu(resume(await action()));
    } catch (erreur) {
      setCompteRendu({ erreur: true, texte: messageErreur(erreur) });
    } finally {
      setEnvoiEnCours(false);
    }
    // La liste des envois précédents se met à jour sans retarder le compte rendu.
    chargerHistorique().then(setHistorique).catch(() => {});
  }

  const envoyer = (e: React.FormEvent) => {
    e.preventDefault();
    executer(() => envoyerCourriel({
      type, document_id: documentId, langue, objet, contenu,
      destinataires: adresses(destinataires),
      copies: adresses(copies),
      copies_cachees: adresses(copiesCachees),
      joindre_pdf: joindrePdf && pieceJointe !== null,
    }));
  };

  function voirPdf() {
    const route = ROUTES_PDF[type];
    ouvrirPdf(`/${route}/${documentId}/pdf`, pieceJointe ?? `${route}.pdf`).catch(() => alert(t.documents.pdfError));
  }

  // Une fois le courriel parti (ou en file), la fenêtre ne renvoie plus : la relance se fait sur ce même courriel.
  const termine = compteRendu?.envoi !== undefined;
  const envoiEchoue = compteRendu?.envoi?.statut === 'echec' ? compteRendu.envoi : null;
  const date = (iso: string | null) => (iso ? new Date(iso).toLocaleString(locale, { dateStyle: 'short', timeStyle: 'short' }) : '');
  // Un document au brouillon que l'envoi fait changer de statut (devis, bon de commande) : la fenêtre le dit avant.
  const remarqueBrouillon = statutDocument === 'brouillon' && !termine ? (tc.draftNotes as Partial<Record<TypeDocumentCourriel, string>>)[type] : undefined;

  return createPortal(
    // Les clics restent dans la fenêtre (la ligne du tableau en dessous a parfois ses propres actions).
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" onClick={e => e.stopPropagation()}>
      <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-3xl max-h-[92vh] overflow-y-auto text-left">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-bold dark:text-white">{tc.sendByEmail} — {tc.types[type]} {numero}</h3>
          <button onClick={onClose} className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded" title={tc.close}>
            <X size={18} />
          </button>
        </div>

        {chargement ? (
          <p className="text-sm text-gray-500">{tc.loading}</p>
        ) : (
          <form onSubmit={envoyer} className="space-y-4">
            {remarqueBrouillon && (
              <p className="px-3 py-2 rounded-lg text-sm bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{remarqueBrouillon}</p>
            )}
            <fieldset disabled={termine || envoiEnCours} className="space-y-3">
              <label className="block">
                <span className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{tc.to} *</span>
                <input value={destinataires} onChange={e => setDestinataires(e.target.value)} required className={classeChamp} />
              </label>
              {afficherCopies ? (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <label className="block">
                    <span className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{tc.cc}</span>
                    <input value={copies} onChange={e => setCopies(e.target.value)} className={classeChamp} />
                  </label>
                  <label className="block">
                    <span className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{tc.bcc}</span>
                    <input value={copiesCachees} onChange={e => setCopiesCachees(e.target.value)} className={classeChamp} />
                  </label>
                </div>
              ) : (
                <button type="button" onClick={() => setAfficherCopies(true)} className="text-xs text-purple-700 dark:text-purple-300 hover:underline">
                  {tc.addCopies}
                </button>
              )}
              <p className="text-xs text-gray-500">{tc.addressesHelp}</p>

              <div className="flex flex-wrap items-center gap-3">
                <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                  {tc.templateLanguage}
                  <select value={langue} className="px-2 py-1 border rounded dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm"
                    onChange={e => { setChargement(true); setLangue(e.target.value as LangueCourriel); }}>
                    <option value="fr">{tc.languages.fr}</option>
                    <option value="en">{tc.languages.en}</option>
                  </select>
                </label>
                <span className={`px-2 py-0.5 rounded text-xs font-medium ${personnalise ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'}`}>
                  {personnalise ? tc.customTemplate : tc.defaultTemplate}
                </span>
              </div>

              <label className="block">
                <span className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{tc.subject} *</span>
                <input value={objet} onChange={e => setObjet(e.target.value)} required maxLength={255} className={classeChamp} />
              </label>
              <div>
                <label className="block">
                  <span className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{tc.content} *</span>
                  <textarea rows={10} value={contenu} onChange={e => setContenu(e.target.value)} required maxLength={20000} className={classeChamp} />
                </label>
                <p className="text-xs text-gray-500 mt-1">{tc.formattingHelp}</p>
              </div>

              {pieceJointe && (
                <div className="flex flex-wrap items-center gap-3 p-2 bg-gray-50 dark:bg-gray-700 rounded-lg text-sm">
                  <label className="flex items-center gap-2 text-gray-700 dark:text-gray-200">
                    <input type="checkbox" checked={joindrePdf} onChange={e => setJoindrePdf(e.target.checked)} className="rounded" aria-label={tc.attachPdf} />
                    <Paperclip size={14} /> {pieceJointe}
                  </label>
                  <button type="button" onClick={voirPdf} className="text-xs text-purple-700 dark:text-purple-300 hover:underline">{tc.viewPdf}</button>
                </div>
              )}
            </fieldset>

            {compteRendu && (
              <div className={`px-3 py-2 rounded-lg text-sm ${compteRendu.erreur ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}>
                {compteRendu.texte}
                {envoiEchoue && <span className="block text-xs mt-1">{tc.failedHelp}</span>}
              </div>
            )}

            <div className="flex justify-end gap-2">
              {envoiEchoue && (
                <button type="button" disabled={envoiEnCours} onClick={() => executer(() => reessayerEnvoiCourriel(envoiEchoue.id))}
                  className="flex items-center gap-1 px-4 py-2 text-sm rounded-lg border border-purple-600 text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-900/30 disabled:opacity-50">
                  <RotateCw size={14} /> {tc.retry}
                </button>
              )}
              <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg">
                {termine ? tc.close : tc.cancel}
              </button>
              {!termine && (
                <button type="submit" disabled={envoiEnCours} className="flex items-center gap-2 px-4 py-2 text-sm bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50">
                  <Send size={14} /> {envoiEnCours ? tc.sending : tc.send}
                </button>
              )}
            </div>
          </form>
        )}

        <div className="mt-6 border-t dark:border-gray-700 pt-4">
          <p className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{tc.previousSends}</p>
          {historique.length === 0 ? (
            <p className="text-xs text-gray-500">{tc.noPreviousSends}</p>
          ) : (
            <ul className="space-y-1.5">
              {historique.map(envoi => (
                <li key={envoi.id} className="flex flex-wrap items-center gap-2 text-sm">
                  <span className="text-xs text-gray-500 w-32">{date(envoi.created_at)}</span>
                  <BadgeStatutCourriel statut={envoi.statut} titre={envoi.erreur} />
                  <span className="text-gray-700 dark:text-gray-300 truncate">{envoi.destinataires.join(', ')}</span>
                  {envoi.auteur && <span className="text-xs text-gray-400">— {envoi.auteur}</span>}
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </div>,
    document.body,
  );
}
