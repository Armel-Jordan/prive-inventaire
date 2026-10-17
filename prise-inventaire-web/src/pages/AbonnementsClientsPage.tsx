import { Fragment, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { ChevronDown, ChevronRight, Copy, FileText, Link2, Pencil, Plus, Repeat, Trash2, X } from 'lucide-react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { aujourdhui, formatDateDocument, useMontantDocument } from '@/lib/documents';
import { getClientsActifs, getFactures, getProduits, type Client, type Facture } from '@/services/api';
import {
  FREQUENCES, VARIABLES_ABONNEMENT, apercuAbonnement, creerAbonnement, facturerAbonnements, getAbonnement, getAbonnements, modifierAbonnement,
  relierEcheance, supprimerAbonnement, type AbonnementClient, type ApercuAbonnement, type BilanFacturation, type EcheanceAbonnement, type SaisieAbonnement,
} from '@/services/abonnementsClients';
import type { PageResultats } from '@/services/api';
import Pagination from '@/components/Pagination';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';
import type { Produit } from '@/types';

const classeChamp = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const classeLibelle = 'block text-xs text-gray-500 dark:text-gray-400';
const ONGLETS = ['actif', 'bientot_echu', 'echu', 'tous'] as const;
const COULEURS: Record<string, string> = {
  actif: 'bg-green-100 text-green-800',
  bientot_echu: 'bg-amber-100 text-amber-800',
  echu: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
  a_venir: 'bg-sky-100 text-sky-800',
  en_retard: 'bg-red-100 text-red-800',
  brouillon: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
  emise: 'bg-blue-100 text-blue-800',
  partiellement_payee: 'bg-amber-100 text-amber-800',
  payee: 'bg-green-100 text-green-800',
  annulee: 'bg-red-100 text-red-800',
  creditee: 'bg-purple-100 text-purple-800',
};

type Formulaire = { mode: 'nouveau' | 'modifier' | 'copier'; abonnement?: AbonnementClient };

/**
 * Abonnements clients (plan de parité fripv2, phase 1.8 : « Facturation des abonnements ») : un produit facturé à un
 * client chaque mois, trimestre, semestre ou année. Les échéances se calculent d'avance ; « Facturer les échéances
 * dues » en fait de vraies factures (une par client et par date), en brouillon ou émises.
 */
export default function AbonnementsClientsPage() {
  const { t, language } = useLanguage();
  const ts = t.subscriptions;
  const { peut } = usePermissions();
  const montantDoc = useMontantDocument();
  const { toasts, toast, dismiss } = useToast();
  const [recherche, setRecherche] = useState('');
  const [terme, setTerme] = useState('');
  const [onglet, setOnglet] = useState<typeof ONGLETS[number]>('actif');
  const [page, setPage] = useState(1);
  const [resultat, setResultat] = useState<PageResultats<AbonnementClient> | null>(null);
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);
  const [ouverts, setOuverts] = useState<Record<number, EcheanceAbonnement[] | null>>({});
  const [formulaire, setFormulaire] = useState<Formulaire | null>(null);
  const [facturation, setFacturation] = useState<{ client?: { id: number; nom: string } } | null>(null);
  const [aRelier, setARelier] = useState<{ abonnement: AbonnementClient; echeance: EcheanceAbonnement } | null>(null);

  useEffect(() => {
    const minuterie = setTimeout(() => { setTerme(recherche.trim()); setPage(1); }, 300);
    return () => clearTimeout(minuterie);
  }, [recherche]);

  useEffect(() => {
    let actif = true;
    getAbonnements({ statut: onglet, recherche: terme || undefined, page }).then(
      r => { if (actif) { setResultat(r); setErreur(''); } },
      e => { if (actif) setErreur(messageErreur(e, ts.error)); },
    );
    return () => { actif = false; };
  }, [onglet, terme, page, version, ts.error]);

  const recharger = () => { setVersion(v => v + 1); setOuverts({}); };
  const abonnements = resultat?.data ?? [];

  async function basculer(abonnement: AbonnementClient) {
    if (abonnement.id in ouverts) {
      setOuverts(o => { const copie = { ...o }; delete copie[abonnement.id]; return copie; });
      return;
    }
    setOuverts(o => ({ ...o, [abonnement.id]: null }));
    try {
      const detail = await getAbonnement(abonnement.id);
      setOuverts(o => ({ ...o, [abonnement.id]: detail.echeances }));
    } catch (e) {
      toast(messageErreur(e, ts.error), 'error');
    }
  }

  async function supprimer(abonnement: AbonnementClient) {
    if (!confirm(ts.confirmDelete.replace('{client}', abonnement.client?.raison_sociale ?? '').replace('{produit}', abonnement.produit?.numero ?? ''))) return;
    try {
      await supprimerAbonnement(abonnement.id);
      toast(ts.deleted);
      recharger();
    } catch (e) {
      toast(messageErreur(e, ts.error), 'error');
    }
  }

  const frequence = (a: AbonnementClient) => {
    const mois = a.mois_facturation ? new Intl.DateTimeFormat(language, { month: 'short' }).format(new Date(2026, a.mois_facturation - 1, 1)) : '';
    return `${ts.frequencies[a.frequence]} · ${ts.dayShort} ${a.jour_facturation}${mois ? ` ${mois}` : ''}`;
  };

  return (
    <div className="p-4 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Repeat className="text-teal-600" /> {ts.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{ts.subtitle}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {peut('abonnements_clients', 'facturer') && (
            <button type="button" onClick={() => setFacturation({})} className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm border border-purple-300 text-purple-700 hover:bg-purple-50 dark:border-purple-500 dark:text-purple-300 dark:hover:bg-purple-900/30">
              <FileText size={18} /> {ts.invoiceDue}
            </button>
          )}
          {peut('abonnements_clients', 'creer') && (
            <button type="button" onClick={() => setFormulaire({ mode: 'nouveau' })} className="flex items-center gap-2 bg-teal-600 text-white px-4 py-2 rounded-lg hover:bg-teal-700 text-sm">
              <Plus size={18} /> {ts.newSubscription}
            </button>
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-end gap-2 mb-4">
        <div className="flex flex-wrap gap-1" role="tablist" aria-label={ts.status}>
          {ONGLETS.map(o => (
            <button key={o} type="button" role="tab" aria-selected={onglet === o} onClick={() => { setOnglet(o); setPage(1); }}
              className={`px-3 py-1.5 rounded-lg text-sm ${onglet === o ? 'bg-teal-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'}`}>
              {ts.tabs[o]}
            </button>
          ))}
        </div>
        <label className={`${classeLibelle} flex-1 min-w-[12rem]`}>{ts.search}
          <input type="search" value={recherche} onChange={e => setRecherche(e.target.value)} placeholder={ts.searchHelp} className={classeChamp} />
        </label>
      </div>

      {erreur && <p role="alert" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-x-auto">
        <table className="w-full text-sm min-w-[64rem]">
          <thead className="bg-gray-50 dark:bg-gray-700">
            <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
              <th className="px-2 py-2 w-8" />
              <th className="px-3 py-2 font-medium">{ts.customer}</th>
              <th className="px-3 py-2 font-medium">{ts.product}</th>
              <th className="px-3 py-2 font-medium">{ts.frequency}</th>
              <th className="px-3 py-2 font-medium text-right">{ts.quantity}</th>
              <th className="px-3 py-2 font-medium text-right">{ts.price}</th>
              <th className="px-3 py-2 font-medium text-right">{ts.monthly}</th>
              <th className="px-3 py-2 font-medium text-right">{ts.yearly}</th>
              <th className="px-3 py-2 font-medium">{ts.next}</th>
              <th className="px-3 py-2 font-medium text-center">{ts.status}</th>
              <th className="px-3 py-2 font-medium text-center">{ts.actions}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
            {resultat === null && !erreur && <tr><td colSpan={11} className="px-4 py-6 text-center text-gray-500">{ts.loading}</td></tr>}
            {resultat !== null && abonnements.length === 0 && <tr><td colSpan={11} className="px-4 py-6 text-center text-gray-500">{ts.empty}</td></tr>}
            {abonnements.map(a => {
              const devise = a.client?.devise ?? null;
              const ouvert = a.id in ouverts;
              return (
                <Fragment key={a.id}>
                  <tr className="hover:bg-gray-50 dark:hover:bg-gray-700/50 align-top">
                    <td className="px-2 py-2">
                      {(a.echeances_count ?? 0) > 0 && (
                        <button type="button" onClick={() => { void basculer(a); }} aria-expanded={ouvert}
                          aria-label={`${ts.dates} — ${a.client?.raison_sociale ?? ''} ${a.produit?.numero ?? ''}`} className="p-0.5 text-gray-500 hover:text-gray-900 dark:text-gray-300">
                          {ouvert ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
                        </button>
                      )}
                    </td>
                    <td className="px-3 py-2 text-gray-900 dark:text-white">{a.client?.raison_sociale}</td>
                    <td className="px-3 py-2 text-gray-700 dark:text-gray-300">
                      {a.produit?.description}
                      {a.produit?.numero && <span className="block text-xs font-mono text-gray-500">{a.produit.numero}</span>}
                      {a.description && <span className="block text-xs text-gray-500">{a.description}</span>}
                    </td>
                    <td className="px-3 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">{frequence(a)}</td>
                    <td className="px-3 py-2 text-right text-gray-900 dark:text-white">{a.quantite}</td>
                    <td className="px-3 py-2 text-right whitespace-nowrap text-gray-700 dark:text-gray-300" title={a.prix_fixe ? ts.fixedPriceHelp : ts.customerPriceHelp}>
                      {a.prix_courant !== null ? montantDoc(a.prix_courant, devise) : '—'}
                      <span className="block text-xs text-gray-500">{a.prix_fixe ? ts.fixedPrice : ts.customerPrice}</span>
                    </td>
                    <td className="px-3 py-2 text-right whitespace-nowrap text-gray-700 dark:text-gray-300">{a.total_mensuel !== null ? montantDoc(a.total_mensuel, devise) : '—'}</td>
                    <td className="px-3 py-2 text-right whitespace-nowrap text-gray-700 dark:text-gray-300">{a.total_annuel !== null ? montantDoc(a.total_annuel, devise) : '—'}</td>
                    <td className="px-3 py-2 whitespace-nowrap text-gray-700 dark:text-gray-300">
                      {a.prochaine_echeance ? formatDateDocument(a.prochaine_echeance, language) : '—'}
                      {(a.echeances_en_retard ?? 0) > 0 && <span className="block text-xs text-red-600">{ts.overdue.replace('{n}', String(a.echeances_en_retard))}</span>}
                    </td>
                    <td className="px-3 py-2 text-center">
                      <span className={`px-2 py-0.5 text-xs rounded-full whitespace-nowrap ${COULEURS[a.statut] ?? ''}`}>{ts.statuses[a.statut]}</span>
                      <span className="block text-xs text-gray-500 mt-0.5">{ts.until.replace('{date}', formatDateDocument(a.date_fin, language))}</span>
                    </td>
                    <td className="px-3 py-2">
                      <div className="flex justify-center gap-1">
                        {peut('abonnements_clients', 'modifier') && (
                          <button type="button" onClick={() => setFormulaire({ mode: 'modifier', abonnement: a })} title={ts.edit} aria-label={`${ts.edit} — ${a.client?.raison_sociale ?? ''} ${a.produit?.numero ?? ''}`}
                            className="p-1 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded"><Pencil size={16} /></button>
                        )}
                        {peut('abonnements_clients', 'creer') && (
                          <button type="button" onClick={() => setFormulaire({ mode: 'copier', abonnement: a })} title={ts.copy} aria-label={`${ts.copy} — ${a.client?.raison_sociale ?? ''} ${a.produit?.numero ?? ''}`}
                            className="p-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 rounded"><Copy size={16} /></button>
                        )}
                        {peut('abonnements_clients', 'facturer') && (a.echeances_en_retard ?? 0) > 0 && (
                          <button type="button" onClick={() => setFacturation({ client: { id: a.client_id, nom: a.client?.raison_sociale ?? '' } })} title={ts.invoiceCustomer}
                            aria-label={`${ts.invoiceCustomer} — ${a.client?.raison_sociale ?? ''}`} className="p-1 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/30 rounded"><FileText size={16} /></button>
                        )}
                        {peut('abonnements_clients', 'supprimer') && (
                          <button type="button" onClick={() => { void supprimer(a); }} title={ts.delete} aria-label={`${ts.delete} — ${a.client?.raison_sociale ?? ''} ${a.produit?.numero ?? ''}`}
                            className="p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded"><Trash2 size={16} /></button>
                        )}
                      </div>
                    </td>
                  </tr>
                  {ouvert && (
                    <tr className="bg-gray-50/60 dark:bg-gray-900/30">
                      <td />
                      <td colSpan={10} className="px-3 py-2">
                        {ouverts[a.id] === null ? <p className="text-xs text-gray-500">{ts.loading}</p> : (
                          <div className="max-h-72 overflow-y-auto">
                            <Echeances echeances={ouverts[a.id] ?? []} devise={devise}
                              onRelier={peut('abonnements_clients', 'facturer') ? e => setARelier({ abonnement: a, echeance: e }) : undefined} />
                          </div>
                        )}
                      </td>
                    </tr>
                  )}
                </Fragment>
              );
            })}
          </tbody>
        </table>
        {resultat && <Pagination page={page} dernierePage={resultat.last_page ?? 1} total={resultat.total ?? 0} onPage={setPage} />}
      </div>

      {formulaire && (
        <FormulaireAbonnement formulaire={formulaire} onClose={() => setFormulaire(null)}
          onEnregistre={() => { toast(formulaire.mode === 'modifier' ? ts.saved : ts.created); setFormulaire(null); recharger(); }} />
      )}
      {facturation && (
        <FacturerEcheances client={facturation.client} onClose={() => setFacturation(null)} onFacture={() => recharger()} />
      )}
      {aRelier && (
        <RelierEcheance abonnement={aRelier.abonnement} echeance={aRelier.echeance} onClose={() => setARelier(null)}
          onRelie={() => { setARelier(null); toast(ts.linked); recharger(); }} />
      )}
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}

/** Échéances d'un abonnement : date, période couverte, statut, facture (ou « Relier » pour une échéance à facturer). */
function Echeances({ echeances, devise, onRelier }: { echeances: EcheanceAbonnement[]; devise: string | null; onRelier?: (e: EcheanceAbonnement) => void }) {
  const { t, language } = useLanguage();
  const ts = t.subscriptions;
  const montantDoc = useMontantDocument();
  return (
    <table className="w-full text-xs">
      <thead>
        <tr className="text-left text-gray-500 dark:text-gray-400">
          <th className="py-1 font-medium">{ts.plannedDate}</th>
          <th className="py-1 font-medium">{ts.period}</th>
          <th className="py-1 font-medium">{ts.status}</th>
          <th className="py-1 font-medium">{ts.invoice}</th>
          <th className="py-1 font-medium text-right">{ts.amount}</th>
        </tr>
      </thead>
      <tbody>
        {echeances.map(e => (
          <tr key={e.id} className="text-gray-700 dark:text-gray-300">
            <td className="py-1">{formatDateDocument(e.date_prevue, language)}</td>
            <td className="py-1">{formatDateDocument(e.periode_debut, language)} – {formatDateDocument(e.periode_fin, language)}</td>
            <td className="py-1"><span className={`px-2 py-0.5 rounded-full ${COULEURS[e.statut] ?? ''}`}>{ts.dateStatuses[e.statut] ?? e.statut}</span></td>
            <td className="py-1">
              {e.facture
                ? <Link to={`/factures?recherche=${encodeURIComponent(e.facture.numero)}`} className="font-mono text-sky-700 hover:underline">{e.facture.numero}</Link>
                : onRelier && <button type="button" onClick={() => onRelier(e)} className="inline-flex items-center gap-1 text-sky-700 hover:underline"><Link2 size={12} /> {ts.link}</button>}
            </td>
            <td className="py-1 text-right">{e.facture ? montantDoc(Number(e.facture.montant_ttc), e.facture.devise ?? devise) : ''}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

/** Saisie d'un abonnement (nouveau, copie ou modification), avec l'aperçu de la première échéance. */
function FormulaireAbonnement({ formulaire, onClose, onEnregistre }: { formulaire: Formulaire; onClose: () => void; onEnregistre: () => void }) {
  const { t, language } = useLanguage();
  const ts = t.subscriptions;
  const montantDoc = useMontantDocument();
  const source = formulaire.abonnement;
  const dansCinqAns = () => { const d = new Date(); d.setFullYear(d.getFullYear() + 5); return d.toLocaleDateString('sv-SE'); };
  const [clients, setClients] = useState<Client[]>([]);
  const [produits, setProduits] = useState<Produit[]>([]);
  // La copie repart d'aujourd'hui et de cinq ans plus tard ; la modification garde les dates.
  const [saisie, setSaisie] = useState({
    client_id: source ? String(source.client_id) : '',
    produit_id: source ? String(source.produit_id) : '',
    description: source?.description ?? '',
    quantite: source ? String(source.quantite) : '1',
    prix_fixe: source?.prix_fixe ?? false,
    prix_unitaire: source?.prix_unitaire != null ? String(Number(source.prix_unitaire)) : '',
    frequence: source?.frequence ?? 'mensuelle',
    jour_facturation: source ? String(source.jour_facturation) : '1',
    mois_facturation: source?.mois_facturation ? String(source.mois_facturation) : '',
    jour_debut_periode: source ? String(source.jour_debut_periode) : '1',
    mois_debut_periode: source?.mois_debut_periode ? String(source.mois_debut_periode) : '',
    periode_precedente: source?.periode_precedente ?? false,
    date_debut: formulaire.mode === 'modifier' && source ? source.date_debut.slice(0, 10) : aujourdhui(),
    date_fin: formulaire.mode === 'modifier' && source ? source.date_fin.slice(0, 10) : dansCinqAns(),
    brouillon: source?.brouillon ?? false,
    notes: source?.notes ?? '',
  });
  const [apercu, setApercu] = useState<ApercuAbonnement | null>(null);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);
  const mensuel = saisie.frequence === 'mensuelle';
  const nomsMois = useMemo(() => Array.from({ length: 12 }, (_, i) => new Intl.DateTimeFormat(language, { month: 'long' }).format(new Date(2026, i, 1))), [language]);
  const jours = Array.from({ length: 28 }, (_, i) => i + 1);

  useEffect(() => {
    let actif = true;
    Promise.all([getClientsActifs(), getProduits('actifs')]).then(([c, p]) => { if (actif) { setClients(c.filter(x => !x.de_passage)); setProduits(p); } }, () => {});
    return () => { actif = false; };
  }, []);

  const corps = (): SaisieAbonnement => ({
    client_id: Number(saisie.client_id), produit_id: Number(saisie.produit_id), description: saisie.description.trim() || null,
    quantite: Number(saisie.quantite), prix_fixe: saisie.prix_fixe, prix_unitaire: saisie.prix_fixe ? Number(saisie.prix_unitaire) : null,
    frequence: saisie.frequence, jour_facturation: Number(saisie.jour_facturation), mois_facturation: mensuel || !saisie.mois_facturation ? null : Number(saisie.mois_facturation),
    jour_debut_periode: Number(saisie.jour_debut_periode), mois_debut_periode: mensuel || !saisie.mois_debut_periode ? null : Number(saisie.mois_debut_periode),
    periode_precedente: saisie.periode_precedente, date_debut: saisie.date_debut, date_fin: saisie.date_fin, brouillon: saisie.brouillon, notes: saisie.notes.trim() || null,
  });

  // Aperçu dès que la saisie est complète : première échéance, période couverte, description rendue, prix.
  const cleApercu = JSON.stringify(saisie);
  useEffect(() => {
    if (!saisie.client_id || !saisie.produit_id || !saisie.date_debut || !saisie.date_fin || (saisie.prix_fixe && saisie.prix_unitaire === '')) { setApercu(null); return; }
    let actif = true;
    const minuterie = setTimeout(() => {
      apercuAbonnement(corps()).then(r => { if (actif) setApercu(r); }, () => { if (actif) setApercu(null); });
    }, 400);
    return () => { actif = false; clearTimeout(minuterie); };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- la saisie entière, sérialisée, déclenche l'aperçu
  }, [cleApercu]);

  async function enregistrer(e: React.FormEvent) {
    e.preventDefault();
    setOccupe(true);
    setErreur('');
    try {
      if (formulaire.mode === 'modifier' && source) await modifierAbonnement(source.id, corps());
      else await creerAbonnement(corps());
      onEnregistre();
    } catch (err) {
      setErreur(messageErreur(err, ts.error));
    } finally {
      setOccupe(false);
    }
  }

  const titre = formulaire.mode === 'modifier' ? ts.editTitle : formulaire.mode === 'copier' ? ts.copyTitle : ts.newSubscription;
  const devise = clients.find(c => String(c.id) === saisie.client_id)?.devise ?? null;

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <form onSubmit={e => { void enregistrer(e); }} role="dialog" aria-modal="true" aria-labelledby="abonnement-titre"
        className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-2xl max-h-[92vh] overflow-y-auto space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="abonnement-titre" className="text-lg font-bold text-gray-900 dark:text-white">{titre}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={ts.close}><X size={18} /></button>
        </div>
        <div className="grid sm:grid-cols-2 gap-3">
          <label className={classeLibelle}>{ts.customer}
            <select required value={saisie.client_id} onChange={e => setSaisie({ ...saisie, client_id: e.target.value })} className={classeChamp}>
              <option value="">{ts.chooseCustomer}</option>
              {clients.map(c => <option key={c.id} value={c.id}>{c.raison_sociale} ({c.code})</option>)}
            </select>
          </label>
          <label className={classeLibelle}>{ts.product}
            <select required value={saisie.produit_id} onChange={e => setSaisie({ ...saisie, produit_id: e.target.value })} className={classeChamp}>
              <option value="">{ts.chooseProduct}</option>
              {produits.map(p => <option key={p.id} value={p.id}>{p.numero} — {p.description}</option>)}
            </select>
          </label>
        </div>
        <label className={classeLibelle}>{ts.lineText}
          <input maxLength={500} value={saisie.description} onChange={e => setSaisie({ ...saisie, description: e.target.value })} placeholder={ts.lineTextPlaceholder} className={classeChamp} />
          <span className="flex flex-wrap gap-1 mt-1">
            {VARIABLES_ABONNEMENT.map(v => (
              <button key={v} type="button" onClick={() => setSaisie(s => ({ ...s, description: `${s.description}${s.description && !s.description.endsWith(' ') ? ' ' : ''}${v}` }))}
                className="px-1.5 py-0.5 rounded bg-gray-100 text-gray-700 font-mono hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200" title={ts.variables[v]}>{v}</button>
            ))}
          </span>
        </label>
        <div className="grid sm:grid-cols-3 gap-3">
          <label className={classeLibelle}>{ts.frequency}
            <select value={saisie.frequence} onChange={e => setSaisie({ ...saisie, frequence: e.target.value as SaisieAbonnement['frequence'] })} className={classeChamp}>
              {FREQUENCES.map(f => <option key={f} value={f}>{ts.frequencies[f]}</option>)}
            </select>
          </label>
          <label className={classeLibelle}>{ts.billingDay}
            <select value={saisie.jour_facturation} onChange={e => setSaisie({ ...saisie, jour_facturation: e.target.value })} className={classeChamp}>
              {jours.map(j => <option key={j} value={j}>{j}</option>)}
            </select>
          </label>
          {!mensuel && (
            <label className={classeLibelle}>{ts.billingMonth}
              <select value={saisie.mois_facturation} onChange={e => setSaisie({ ...saisie, mois_facturation: e.target.value })} className={classeChamp}>
                <option value="">{ts.startMonth}</option>
                {nomsMois.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
              </select>
            </label>
          )}
          <label className={classeLibelle}>{ts.quantity}
            <input type="number" required min={1} max={999999} step={1} value={saisie.quantite} onChange={e => setSaisie({ ...saisie, quantite: e.target.value })} className={classeChamp} />
          </label>
          <label className={`${classeLibelle} sm:col-span-2`}>
            <span className="flex items-center gap-2 mb-1 text-gray-700 dark:text-gray-200">
              <input type="checkbox" checked={saisie.prix_fixe} onChange={e => setSaisie({ ...saisie, prix_fixe: e.target.checked })} /> {ts.fixedPrice}
            </span>
            <input type="number" min={0} step="0.01" disabled={!saisie.prix_fixe} required={saisie.prix_fixe} aria-label={ts.price}
              value={saisie.prix_fixe ? saisie.prix_unitaire : (apercu?.prix ?? '')} onChange={e => setSaisie({ ...saisie, prix_unitaire: e.target.value })}
              placeholder={ts.customerPriceHelp} className={`${classeChamp} disabled:opacity-60`} />
          </label>
        </div>
        <div className="grid sm:grid-cols-3 gap-3">
          <label className={classeLibelle}>{ts.periodStartDay}
            <select value={saisie.jour_debut_periode} onChange={e => setSaisie({ ...saisie, jour_debut_periode: e.target.value })} className={classeChamp}>
              {jours.map(j => <option key={j} value={j}>{j}</option>)}
            </select>
          </label>
          {!mensuel && (
            <label className={classeLibelle}>{ts.periodStartMonth}
              <select value={saisie.mois_debut_periode} onChange={e => setSaisie({ ...saisie, mois_debut_periode: e.target.value })} className={classeChamp}>
                <option value="">{ts.sameAsBilling}</option>
                {nomsMois.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
              </select>
            </label>
          )}
          <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200 sm:col-span-1 self-end pb-2">
            <input type="checkbox" checked={saisie.periode_precedente} onChange={e => setSaisie({ ...saisie, periode_precedente: e.target.checked })} /> {ts.previousPeriod}
          </label>
        </div>
        <div className="grid sm:grid-cols-2 gap-3">
          <label className={classeLibelle}>{ts.startDate}
            <input type="date" required value={saisie.date_debut} onChange={e => setSaisie({ ...saisie, date_debut: e.target.value })} className={classeChamp} />
          </label>
          <label className={classeLibelle}>{ts.endDate}
            <input type="date" required value={saisie.date_fin} onChange={e => setSaisie({ ...saisie, date_fin: e.target.value })} className={classeChamp} />
          </label>
        </div>
        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200" title={ts.draftHelp}>
          <input type="checkbox" checked={saisie.brouillon} onChange={e => setSaisie({ ...saisie, brouillon: e.target.checked })} /> {ts.draft}
        </label>
        <label className={classeLibelle}>{ts.notes}
          <input maxLength={500} value={saisie.notes} onChange={e => setSaisie({ ...saisie, notes: e.target.value })} className={classeChamp} />
        </label>

        {apercu && (
          <div role="status" className="rounded-lg bg-teal-50 dark:bg-teal-900/30 px-3 py-2 text-sm text-teal-900 dark:text-teal-100">
            {apercu.premiere_echeance
              ? ts.preview.replace('{date}', formatDateDocument(apercu.premiere_echeance, language)).replace('{n}', String(apercu.echeances))
                .replace('{debut}', apercu.periode ? formatDateDocument(apercu.periode[0], language) : '').replace('{fin}', apercu.periode ? formatDateDocument(apercu.periode[1], language) : '')
              : ts.previewNone}
            {apercu.prix !== null && <span className="block">{ts.previewPrice.replace('{prix}', montantDoc(apercu.prix, devise))}</span>}
            {apercu.description && <span className="block italic">« {apercu.description} »</span>}
          </div>
        )}
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{ts.cancel}</button>
          <button type="submit" disabled={occupe} className="px-4 py-2 bg-teal-600 text-white rounded-lg text-sm hover:bg-teal-700 disabled:opacity-50">{ts.save}</button>
        </div>
      </form>
    </div>
  );
}

/** Facturation des échéances dues jusqu'à une date, pour tous les clients ou un seul ; bilan des factures et des erreurs. */
function FacturerEcheances({ client, onClose, onFacture }: { client?: { id: number; nom: string }; onClose: () => void; onFacture: () => void }) {
  const { t, language } = useLanguage();
  const ts = t.subscriptions;
  const { peut } = usePermissions();
  const montantDoc = useMontantDocument();
  const [date, setDate] = useState(aujourdhui());
  const [emettre, setEmettre] = useState(false);
  const [bilan, setBilan] = useState<BilanFacturation | null>(null);
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  async function facturer() {
    setOccupe(true);
    setErreur('');
    try {
      setBilan(await facturerAbonnements(date, client?.id ?? null, emettre));
      onFacture();
    } catch (e) {
      setErreur(messageErreur(e, ts.error));
    } finally {
      setOccupe(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="facturer-abonnements-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-lg space-y-3 max-h-[92vh] overflow-y-auto">
        <div className="flex items-start justify-between gap-3">
          <h2 id="facturer-abonnements-titre" className="text-lg font-bold text-gray-900 dark:text-white">{client ? ts.invoiceCustomerTitle.replace('{client}', client.nom) : ts.invoiceDue}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={ts.close}><X size={18} /></button>
        </div>
        {!bilan ? (
          <>
            <p className="text-sm text-gray-500 dark:text-gray-400">{ts.invoiceHelp}</p>
            <label className={classeLibelle}>{ts.upTo}
              <input type="date" value={date} onChange={e => setDate(e.target.value)} className={classeChamp} />
            </label>
            {peut('factures', 'emettre') && (
              <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" checked={emettre} onChange={e => setEmettre(e.target.checked)} /> {ts.issueDirectly}
              </label>
            )}
            {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
            <div className="flex justify-end gap-2">
              <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{ts.cancel}</button>
              <button type="button" disabled={occupe || !date} onClick={() => { void facturer(); }} className="px-4 py-2 bg-purple-600 text-white rounded-lg text-sm hover:bg-purple-700 disabled:opacity-50">{ts.createInvoices}</button>
            </div>
          </>
        ) : (
          <>
            <p role="status" className="text-sm font-medium text-gray-900 dark:text-white">{bilan.message}</p>
            {bilan.factures.length > 0 && (
              <ul className="text-sm space-y-1">
                {bilan.factures.map(f => (
                  <li key={f.id} className="flex justify-between gap-2">
                    <Link to={`/factures?recherche=${encodeURIComponent(f.numero)}`} className="font-mono text-sky-700 hover:underline">{f.numero}</Link>
                    <span className="text-gray-600 dark:text-gray-300">{formatDateDocument(f.date_facture, language)} · {ts.dateStatuses[f.statut] ?? f.statut} · {montantDoc(Number(f.montant_ttc), f.devise)}</span>
                  </li>
                ))}
              </ul>
            )}
            {bilan.erreurs.length > 0 && (
              <ul role="alert" className="text-sm space-y-1 rounded-lg bg-red-50 text-red-700 px-3 py-2">
                {bilan.erreurs.map(e => <li key={`${e.client}-${e.date}`}>{e.client} ({formatDateDocument(e.date, language)}) : {e.message}</li>)}
              </ul>
            )}
            <div className="flex justify-end">
              <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{ts.close}</button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

/** Relier une échéance à une facture faite à la main pour elle (factures du client). */
function RelierEcheance({ abonnement, echeance, onClose, onRelie }: { abonnement: AbonnementClient; echeance: EcheanceAbonnement; onClose: () => void; onRelie: () => void }) {
  const { t, language } = useLanguage();
  const ts = t.subscriptions;
  const montantDoc = useMontantDocument();
  const [factures, setFactures] = useState<Facture[] | null>(null);
  const [choisie, setChoisie] = useState('');
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    let actif = true;
    getFactures({ client_id: abonnement.client_id, per_page: 50 }).then(r => { if (actif) setFactures(r.data ?? []); }, e => { if (actif) setErreur(messageErreur(e, ts.error)); });
    return () => { actif = false; };
  }, [abonnement.client_id, ts.error]);

  async function relier() {
    setErreur('');
    try {
      await relierEcheance(abonnement.id, echeance.id, Number(choisie));
      onRelie();
    } catch (e) {
      setErreur(messageErreur(e, ts.error));
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div role="dialog" aria-modal="true" aria-labelledby="relier-echeance-titre" className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md space-y-3">
        <div className="flex items-start justify-between gap-3">
          <h2 id="relier-echeance-titre" className="text-lg font-bold text-gray-900 dark:text-white">{ts.linkTitle.replace('{date}', formatDateDocument(echeance.date_prevue, language))}</h2>
          <button type="button" onClick={onClose} className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700" aria-label={ts.close}><X size={18} /></button>
        </div>
        <p className="text-sm text-gray-500 dark:text-gray-400">{ts.linkHelp}</p>
        <label className={classeLibelle}>{ts.invoice}
          <select value={choisie} onChange={e => setChoisie(e.target.value)} className={classeChamp} disabled={factures === null}>
            <option value="">{factures === null ? ts.loading : ts.chooseInvoice}</option>
            {(factures ?? []).map(f => (
              <option key={f.id} value={f.id}>{f.numero} — {formatDateDocument(f.date_facture, language)} — {montantDoc(Number(f.montant_ttc), f.devise ?? null)}</option>
            ))}
          </select>
        </label>
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50 dark:border-gray-600 dark:text-white dark:hover:bg-gray-700">{ts.cancel}</button>
          <button type="button" disabled={!choisie} onClick={() => { void relier(); }} className="px-4 py-2 bg-sky-600 text-white rounded-lg text-sm hover:bg-sky-700 disabled:opacity-50">{ts.link}</button>
        </div>
      </div>
    </div>
  );
}
