import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { CircleDollarSign, Eye, FileClock, Pencil, Plus, Send, Trash2, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, dateSaisie, formatDateDocument, tauxPropose, useCodeDevise, useMontantDocument } from '@/lib/documents';
import ChampDevise from '@/components/ChampDevise';
import { getClientsActifs, getCommandesClient, type Client, type ComClientEntete, type PageResultats } from '@/services/api';
import {
  creerDemandeAcompte, encaisserDemandeAcompte, envoyerDemandeAcompte, getDemandeAcompte, getDemandesAcompte, getDevisDuClient,
  modifierDemandeAcompte, MODES_ACOMPTE, STATUTS_DEMANDE, supprimerDemandeAcompte, type DemandeAcompte, type ModeAcompte,
} from '@/services/acomptes';
import BoutonPdf from '@/components/BoutonPdf';
import BoutonCourriel from '@/components/BoutonCourriel';
import Pagination from '@/components/Pagination';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';
import { useEncaissementCaisse } from '@/hooks/useEncaissementCaisse';
import CaisseDuPoste from '@/components/caisse/CaisseDuPoste';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm disabled:opacity-60';
const COULEURS: Record<string, string> = {
  brouillon: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
  envoyee: 'bg-blue-100 text-blue-800',
  payee: 'bg-green-100 text-green-800',
  partiellement_appliquee: 'bg-amber-100 text-amber-800',
  appliquee: 'bg-purple-100 text-purple-800',
};
/** Commandes auxquelles rattacher une demande : ni refusées, ni annulées. */
const COMMANDES_OUVERTES = ['brouillon', 'en_attente', 'acceptee', 'facturee'];

/**
 * Demandes d'acompte (plan de parité fripv2, phase 1.5) : demander un dépôt au client (description et montant, sans
 * taxe), l'envoyer (PDF par courriel, ou marquée à la main), puis la marquer payée : l'acompte est enregistré au
 * dossier du client, réservé à la commande de la demande. Ensuite, l'usage de l'acompte donne son état.
 */
export default function DemandesAcomptePage() {
  const { t, language } = useLanguage();
  const tr = t.depositRequests;
  // Montants dans la devise de chaque document (celle de l'entreprise, ou celle d'un client étranger).
  const montantDoc = useMontantDocument();
  const montant = (v: number | string | null | undefined, deviseDoc?: string | null) => montantDoc(v, deviseDoc);
  const { peut } = usePermissions();
  const { toasts, toast, dismiss } = useToast();
  const [parametres] = useSearchParams();
  const [recherche, setRecherche] = useState(parametres.get('recherche') ?? '');
  const [terme, setTerme] = useState(recherche.trim());
  const [statut, setStatut] = useState('');
  const [page, setPage] = useState(1);
  const [resultat, setResultat] = useState<PageResultats<DemandeAcompte> | null>(null);
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);
  const [edition, setEdition] = useState<{ demande: DemandeAcompte | null } | null>(null);
  const [detail, setDetail] = useState<DemandeAcompte | null>(null);
  const [aEncaisser, setAEncaisser] = useState<DemandeAcompte | null>(null);

  useEffect(() => {
    const minuterie = setTimeout(() => { setTerme(recherche.trim()); setPage(1); }, 300);
    return () => clearTimeout(minuterie);
  }, [recherche]);

  useEffect(() => {
    let actif = true;
    getDemandesAcompte({ statut: statut || undefined, recherche: terme || undefined, page }).then(
      r => { if (actif) { setResultat(r); setErreur(''); } },
      e => { if (actif) setErreur(messageErreur(e, tr.error)); },
    );
    return () => { actif = false; };
  }, [statut, terme, page, version, tr.error]);

  const recharger = () => setVersion(v => v + 1);
  const echec = (e: unknown) => toast(messageErreur(e, tr.error), 'error');

  async function ouvrir(demande: DemandeAcompte) {
    try {
      const complete = await getDemandeAcompte(demande.id);
      if (complete.statut === 'brouillon' && peut('demandes_acompte', 'modifier')) setEdition({ demande: complete });
      else setDetail(complete);
    } catch (e) { echec(e); }
  }

  async function envoyer(demande: DemandeAcompte) {
    if (!confirm(tr.confirmSend)) return;
    try {
      await envoyerDemandeAcompte(demande.id);
      toast(tr.sent);
      recharger();
    } catch (e) { echec(e); }
  }

  async function supprimer(demande: DemandeAcompte) {
    if (!confirm(tr.confirmDelete)) return;
    try {
      await supprimerDemandeAcompte(demande.id);
      toast(tr.deleted);
      recharger();
    } catch (e) { echec(e); }
  }

  const badge = (etat: string) => <span className={`px-2 py-0.5 text-xs rounded-full whitespace-nowrap ${COULEURS[etat] ?? 'bg-gray-100'}`}>{tr.statuses[etat] ?? etat}</span>;
  const demandes = resultat?.data ?? [];

  return (
    <div className="p-4 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><FileClock className="text-purple-600" /> {tr.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{tr.subtitle}</p>
        </div>
        {peut('demandes_acompte', 'creer') && (
          <button type="button" onClick={() => setEdition({ demande: null })} className="flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 text-sm">
            <Plus size={18} /> {tr.newRequest}
          </button>
        )}
      </div>

      <div className="flex flex-wrap items-end gap-2 mb-4">
        <label className="text-xs text-gray-500 dark:text-gray-400 flex-1 min-w-[12rem]">{tr.search}
          <input type="search" value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={tr.searchHelp} className={classeChamp} />
        </label>
        <label className="text-xs text-gray-500 dark:text-gray-400 w-48">{tr.status}
          <select value={statut} onChange={e => { setStatut(e.target.value); setPage(1); }} className={classeChamp}>
            <option value="">{tr.allStatuses}</option>
            {STATUTS_DEMANDE.map(s => <option key={s} value={s}>{tr.statuses[s]}</option>)}
          </select>
        </label>
      </div>

      {erreur && <p role="alert" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
        <table className="w-full text-sm min-w-[48rem]">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-3 py-2 font-medium">{tr.number}</th>
              <th className="px-3 py-2 font-medium">{tr.customer}</th>
              <th className="px-3 py-2 font-medium">{tr.date}</th>
              <th className="px-3 py-2 font-medium">{tr.appliesTo}</th>
              <th className="px-3 py-2 font-medium text-right">{tr.amount}</th>
              <th className="px-3 py-2 font-medium text-center">{tr.status}</th>
              <th className="px-3 py-2 font-medium text-center">{tr.actions}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
            {resultat === null && !erreur && <tr><td colSpan={7} className="px-4 py-6 text-center text-gray-500">{tr.loading}</td></tr>}
            {resultat !== null && demandes.length === 0 && <tr><td colSpan={7} className="px-4 py-6 text-center text-gray-500">{terme || statut ? tr.noResult : tr.empty}</td></tr>}
            {demandes.map(demande => (
              <tr key={demande.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                <td className="px-3 py-2 font-mono text-gray-900 dark:text-white whitespace-nowrap">{demande.numero}</td>
                <td className="px-3 py-2 text-gray-700 dark:text-gray-300">
                  {demande.client?.raison_sociale}
                  <span className="block text-xs text-gray-500 dark:text-gray-400 line-clamp-1">{demande.description}</span>
                </td>
                <td className="px-3 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">{formatDateDocument(demande.date_demande, language)}</td>
                <td className="px-3 py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{[demande.devis?.numero, demande.commande?.numero].filter(Boolean).join(' · ') || '—'}</td>
                <td className="px-3 py-2 text-right whitespace-nowrap font-medium text-gray-900 dark:text-white">{montant(demande.montant, demande.devise)}</td>
                <td className="px-3 py-2 text-center">{badge(demande.etat)}</td>
                <td className="px-3 py-2">
                  <div className="flex justify-center gap-1">
                    <button type="button" onClick={() => { void ouvrir(demande); }} title={demande.statut === 'brouillon' ? tr.edit : tr.title} aria-label={`${tr.edit} — ${demande.numero}`}
                      className="p-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded">
                      {demande.statut === 'brouillon' && peut('demandes_acompte', 'modifier') ? <Pencil size={18} /> : <Eye size={18} />}
                    </button>
                    <BoutonPdf chemin={`/demandes-acompte/${demande.id}/pdf`} nomFichier={`demande-acompte-${demande.numero}.pdf`} />
                    <BoutonCourriel type="demande_acompte" documentId={demande.id} />
                    {demande.statut === 'brouillon' && peut('demandes_acompte', 'envoyer') && (
                      <button type="button" onClick={() => { void envoyer(demande); }} title={tr.send} aria-label={`${tr.send} — ${demande.numero}`}
                        className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded"><Send size={18} /></button>
                    )}
                    {demande.statut !== 'payee' && peut('demandes_acompte', 'encaisser') && (
                      <button type="button" onClick={() => setAEncaisser(demande)} title={tr.markPaid} aria-label={`${tr.markPaid} — ${demande.numero}`}
                        className="p-1 text-green-600 hover:bg-green-50 dark:hover:bg-green-900/30 rounded"><CircleDollarSign size={18} /></button>
                    )}
                    {demande.statut !== 'payee' && peut('demandes_acompte', 'supprimer') && (
                      <button type="button" onClick={() => { void supprimer(demande); }} title={tr.delete} aria-label={`${tr.delete} — ${demande.numero}`}
                        className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><Trash2 size={18} /></button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {resultat && <Pagination page={page} dernierePage={resultat.last_page ?? 1} total={resultat.total ?? 0} onPage={setPage} />}
      </div>

      {edition && (
        <EditeurDemande
          demande={edition.demande}
          onClose={() => setEdition(null)}
          onEnregistre={() => { setEdition(null); toast(tr.saved); recharger(); }}
        />
      )}

      {detail && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div role="dialog" aria-modal="true" aria-labelledby="detail-demande-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg max-h-[94vh] overflow-y-auto space-y-3 text-sm">
            <div className="flex items-start justify-between gap-3">
              <div>
                <h2 id="detail-demande-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tr.editTitle.replace('{numero}', detail.numero)}</h2>
                <p className="text-gray-500 dark:text-gray-400">{detail.client?.raison_sociale} · {formatDateDocument(detail.date_demande, language)}</p>
              </div>
              <div className="flex items-center gap-2">
                {badge(detail.etat)}
                <button type="button" onClick={() => setDetail(null)} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tr.close}><X size={18} /></button>
              </div>
            </div>
            <p className="whitespace-pre-line text-gray-800 dark:text-gray-200">{detail.description}</p>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-1">
              <dt className="text-gray-500">{tr.amount}</dt><dd className="text-right font-semibold text-gray-900 dark:text-white">{montant(detail.montant, detail.devise)}</dd>
              {detail.devis && <><dt className="text-gray-500">{tr.quote}</dt><dd className="text-right font-mono text-gray-900 dark:text-white">{detail.devis.numero}</dd></>}
              {detail.commande && <><dt className="text-gray-500">{tr.order}</dt><dd className="text-right font-mono text-gray-900 dark:text-white">{detail.commande.numero}</dd></>}
              {detail.acompte && (
                <>
                  <dt className="text-gray-500">{tr.deposit}</dt>
                  <dd className="text-right text-gray-900 dark:text-white">{formatDateDocument(detail.acompte.date_acompte ?? null, language)} · {t.deposits.modes[detail.acompte.mode ?? ''] ?? detail.acompte.mode}</dd>
                  <dt className="text-gray-500">{t.deposits.applied}</dt>
                  <dd className="text-right text-gray-900 dark:text-white">{montant(detail.acompte.montant_applique, detail.devise)}</dd>
                </>
              )}
            </dl>
            {(detail.acompte?.applications ?? []).length > 0 && (
              <ul className="divide-y dark:divide-gray-700">
                {detail.acompte!.applications!.map(a => (
                  <li key={a.id} className="flex justify-between gap-2 py-1">
                    <span className="text-gray-700 dark:text-gray-300">{formatDateDocument(a.date_paiement, language)} · <span className="font-mono">{a.facture?.numero}</span></span>
                    <span className="text-gray-900 dark:text-white whitespace-nowrap">{montant(a.montant, detail.devise)}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}

      {aEncaisser && (
        <EncaisserDemande
          demande={aEncaisser}
          onClose={() => setAEncaisser(null)}
          onEncaisse={() => { setAEncaisser(null); toast(tr.paid); recharger(); }}
        />
      )}

      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}

/** Saisie d'une demande (nouvelle ou brouillon) : client, date, description, montant, devis et commande du client. */
function EditeurDemande({ demande, onClose, onEnregistre }: { demande: DemandeAcompte | null; onClose: () => void; onEnregistre: () => void }) {
  const { t } = useLanguage();
  const tr = t.depositRequests;
  const deviseEntreprise = useCodeDevise();
  const [clients, setClients] = useState<Client[]>([]);
  const [devis, setDevis] = useState<{ id: number; numero: string }[]>([]);
  const [commandes, setCommandes] = useState<ComClientEntete[]>([]);
  const [saisie, setSaisie] = useState({
    client_id: String(demande?.client_id ?? ''),
    date_demande: demande ? dateSaisie(demande.date_demande) : aujourdhui(),
    description: demande?.description ?? '',
    montant: demande ? String(Number(demande.montant)) : '',
    devis_id: String(demande?.devis_id ?? ''),
    commande_id: String(demande?.commande_id ?? ''),
    // Devise de la demande (vide : celle de l'entreprise) ; par défaut, celle du client.
    devise: demande?.devise ?? '',
    taux: String(demande?.taux_change ?? 1),
  });
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    let actif = true;
    getClientsActifs().then(c => { if (actif) setClients(c ?? []); }, () => {});
    return () => { actif = false; };
  }, []);

  useEffect(() => {
    if (!saisie.client_id) return;
    let actif = true;
    const client = Number(saisie.client_id);
    getDevisDuClient(client).then(d => { if (actif) setDevis(d); }, () => {});
    getCommandesClient({ client_id: client }).then(r => { if (actif) setCommandes((r.data ?? []).filter(c => COMMANDES_OUVERTES.includes(c.statut))); }, () => {});
    return () => { actif = false; };
  }, [saisie.client_id]);

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    const donnees = {
      client_id: Number(saisie.client_id),
      date_demande: saisie.date_demande,
      description: saisie.description.trim(),
      montant: Number(saisie.montant),
      devis_id: saisie.devis_id ? Number(saisie.devis_id) : null,
      commande_id: saisie.commande_id ? Number(saisie.commande_id) : null,
    };
    // À la création : devise toujours envoyée (celle de l'entreprise l'emporte alors sur celle du client).
    const devise = demande ? {} : { devise: saisie.devise || deviseEntreprise, taux_change: saisie.devise ? Number(saisie.taux) : undefined };
    try {
      if (demande) await modifierDemandeAcompte(demande.id, donnees);
      else await creerDemandeAcompte({ ...donnees, ...devise });
      onEnregistre();
    } catch (err) {
      setErreur(messageErreur(err, tr.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <form onSubmit={enregistrer} role="dialog" aria-modal="true" aria-labelledby="editeur-demande-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg max-h-[94vh] overflow-y-auto space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="editeur-demande-titre" className="text-lg font-bold text-gray-900 dark:text-white">{demande ? tr.editTitle.replace('{numero}', demande.numero) : tr.createTitle}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tr.close}><X size={18} /></button>
        </div>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{tr.customer}
          <select required value={saisie.client_id} onChange={e => {
            // Devise du client proposée, avec son taux.
            const deviseClient = clients.find(c => c.id === Number(e.target.value))?.devise ?? '';
            setSaisie({ ...saisie, client_id: e.target.value, devis_id: '', commande_id: '', devise: deviseClient, taux: deviseClient ? '' : '1' });
            if (deviseClient) tauxPropose(deviseClient, deviseEntreprise).then(taux => setSaisie(s => (s.devise === deviseClient ? { ...s, taux } : s)));
          }} className={classeChamp}>
            <option value="">{tr.chooseCustomer}</option>
            {clients.map(c => <option key={c.id} value={c.id}>{c.code} · {c.raison_sociale}</option>)}
          </select>
        </label>
        <div className="grid grid-cols-2 gap-3">
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tr.date}
            <input type="date" required value={saisie.date_demande} onChange={e => setSaisie({ ...saisie, date_demande: e.target.value })} className={classeChamp} />
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tr.amount}
            <input type="number" required min="0.01" step="0.01" value={saisie.montant} onChange={e => setSaisie({ ...saisie, montant: e.target.value })} className={classeChamp} />
          </label>
        </div>
        {!demande && <ChampDevise valeur={{ devise: saisie.devise, taux: saisie.taux }} onChange={v => setSaisie(s => ({ ...s, devise: v.devise, taux: v.taux }))} />}
        <label className="block text-xs text-gray-500 dark:text-gray-400">{tr.description}
          <textarea required rows={4} maxLength={5000} value={saisie.description} placeholder={tr.descriptionHelp}
            onChange={e => setSaisie({ ...saisie, description: e.target.value })} className={classeChamp} />
        </label>
        {saisie.client_id && (
          <div className="grid grid-cols-2 gap-3">
            <label className="block text-xs text-gray-500 dark:text-gray-400">{tr.quote}
              <select value={saisie.devis_id} onChange={e => setSaisie({ ...saisie, devis_id: e.target.value })} className={classeChamp}>
                <option value="">{tr.none}</option>
                {devis.map(d => <option key={d.id} value={d.id}>{d.numero}</option>)}
              </select>
            </label>
            <label className="block text-xs text-gray-500 dark:text-gray-400">{tr.order}
              <select value={saisie.commande_id} onChange={e => setSaisie({ ...saisie, commande_id: e.target.value })} className={classeChamp}>
                <option value="">{tr.none}</option>
                {commandes.map(c => <option key={c.id} value={c.id}>{c.numero}</option>)}
              </select>
            </label>
          </div>
        )}
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tr.cancel}</button>
          <button type="submit" disabled={occupe} className="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700 disabled:opacity-50">{tr.save}</button>
        </div>
      </form>
    </div>
  );
}

/** Dépôt reçu : date, mode et référence ; l'acompte est enregistré au dossier du client. */
function EncaisserDemande({ demande, onClose, onEncaisse }: { demande: DemandeAcompte; onClose: () => void; onEncaisse: () => void }) {
  const { t } = useLanguage();
  const tr = t.depositRequests;
  const montantDoc = useMontantDocument();
  // Poste de caisse : le dépôt entre dans la session ouverte de sa caisse ; l'argent comptant est proposé.
  const encaissement = useEncaissementCaisse();
  const [saisie, setSaisie] = useState({ date: aujourdhui(), mode: (encaissement.caisse ? 'especes' : 'virement') as ModeAcompte, reference: '' });
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  async function encaisser(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    try {
      await encaissement.encaisser(caisse_id => encaisserDemandeAcompte(demande.id, { date: saisie.date, mode: saisie.mode, reference: saisie.reference.trim() || undefined, caisse_id }));
      onEncaisse();
    } catch (err) {
      setErreur(messageErreur(err, tr.error));
    } finally {
      setOccupe(false);
    }
  }

  const commande = demande.commande ? tr.payOrder.replace('{numero}', demande.commande.numero) : '';

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <form onSubmit={encaisser} role="dialog" aria-modal="true" aria-labelledby="encaisser-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="encaisser-titre" className="text-lg font-bold text-gray-900 dark:text-white">{tr.payTitle.replace('{numero}', demande.numero)}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={tr.close}><X size={18} /></button>
        </div>
        <p className="text-sm text-gray-600 dark:text-gray-300">{tr.payHelp.replace('{montant}', montantDoc(demande.montant, demande.devise)).replace('{commande}', commande)}</p>
        <CaisseDuPoste caisse={encaissement.caisse} />
        <div className="grid grid-cols-2 gap-3">
          <label className="block text-xs text-gray-500 dark:text-gray-400">{tr.date}
            <input type="date" required value={saisie.date} min={dateSaisie(demande.date_demande)} onChange={e => setSaisie({ ...saisie, date: e.target.value })} className={classeChamp} />
          </label>
          <label className="block text-xs text-gray-500 dark:text-gray-400">{t.deposits.mode}
            <select value={saisie.mode} onChange={e => setSaisie({ ...saisie, mode: e.target.value as ModeAcompte })} className={classeChamp}>
              {MODES_ACOMPTE.map(m => <option key={m} value={m}>{t.deposits.modes[m]}</option>)}
            </select>
          </label>
        </div>
        <label className="block text-xs text-gray-500 dark:text-gray-400">{t.deposits.reference}
          <input value={saisie.reference} maxLength={100} placeholder={t.deposits.referenceHelp} onChange={e => setSaisie({ ...saisie, reference: e.target.value })} className={classeChamp} />
        </label>
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{tr.cancel}</button>
          <button type="submit" disabled={occupe} className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 disabled:opacity-50">{tr.markPaid}</button>
        </div>
      </form>
      {encaissement.dialogue}
    </div>
  );
}
