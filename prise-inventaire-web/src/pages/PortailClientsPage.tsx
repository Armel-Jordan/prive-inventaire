import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, Check, Copy, ExternalLink, Globe, Mail, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useAuth } from '@/contexts/AuthContext';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useLanguage } from '@/i18n/useLanguage';
import { getClientsActifs, type Client } from '@/services/api';
import {
  creerAccesPortail, enregistrerReglagesPortail, getPortailClients, modifierAccesPortail, renvoyerLienPortail, supprimerAccesPortail, URL_ESPACE_CLIENT,
  type AccesPortail, type EtatPortailClients, type Langue, type ReglagesPortail, type ResultatInvitation,
} from '@/services/portailClients';
import ConfirmModal from '@/components/ConfirmModal';
import Toasts from '@/components/Toasts';
import { useToast } from '@/hooks/useToast';
import { messageErreur } from '@/lib/erreurs';

const classeChamp = 'w-full px-3 py-2 border border-gray-300 rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white text-sm';
const carte = 'bg-white dark:bg-gray-800 rounded-lg shadow-md p-5';
const COULEURS_ETAT = {
  actif: 'bg-green-100 text-green-800',
  invite: 'bg-blue-100 text-blue-800',
  suspendu: 'bg-gray-200 text-gray-700',
};

/** Copie dans le presse-papiers ; le texte reste sélectionnable si le navigateur refuse. */
function BoutonCopier({ texte, libelle, copie }: { texte: string; libelle: string; copie: string }) {
  const [fait, setFait] = useState(false);
  return (
    <button type="button" onClick={() => navigator.clipboard?.writeText(texte).then(() => { setFait(true); setTimeout(() => setFait(false), 2000); }, () => {})}
      className="inline-flex items-center gap-1.5 px-3 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700 shrink-0">
      {fait ? <Check size={16} className="text-green-600" /> : <Copy size={16} />} {fait ? copie : libelle}
    </button>
  );
}

/**
 * Espace client, côté entreprise : ouverture et réglages (message d'accueil, produits proposés), adresse du site
 * de l'espace client à donner aux clients, accès des contacts (invitation, lien renvoyé, suspension, suppression).
 */
export default function PortailClientsPage() {
  const { t, language } = useLanguage();
  const ta = t.portalAdmin;
  const { tenant } = useAuth();
  // Droits du module Espace client (rôles et permissions).
  const { canCreate, canEdit, canDelete } = usePermissions();
  const peutModifier = canEdit('espace_client');
  const peutCreer = canCreate('espace_client');
  const peutSupprimer = canDelete('espace_client');
  const { toasts, toast, dismiss } = useToast();
  const [etat, setEtat] = useState<EtatPortailClients | null>(null);
  const [erreur, setErreur] = useState('');
  const [version, setVersion] = useState(0);
  const [reglages, setReglages] = useState<ReglagesPortail | null>(null);
  const [occupe, setOccupe] = useState(false);
  const [edition, setEdition] = useState<{ acces: AccesPortail | null } | null>(null);
  const [lien, setLien] = useState<{ nom: string; resultat: ResultatInvitation } | null>(null);
  const [aSupprimer, setASupprimer] = useState<AccesPortail | null>(null);

  useEffect(() => {
    let actif = true;
    getPortailClients().then(
      donnees => { if (actif) { setEtat(donnees); setErreur(''); } },
      e => { if (actif) setErreur(messageErreur(e, ta.error)); },
    );
    return () => { actif = false; };
  }, [version, ta.error]);

  const recharger = () => setVersion(v => v + 1);
  const valeurs = reglages ?? etat?.parametres ?? null;
  // Lien de l'entreprise sur le site de l'espace client : il ouvre directement sa page de connexion.
  const urlPortail = tenant ? `${URL_ESPACE_CLIENT}/${tenant.slug}` : '';
  const locale = language === 'en' ? 'en-US' : 'fr-FR';
  const date = (iso: string | null) => (iso ? new Date(iso).toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' }) : '');

  async function enregistrer() {
    if (!valeurs) return;
    setOccupe(true);
    try {
      await enregistrerReglagesPortail(valeurs);
      setEtat(e => (e ? { ...e, parametres: valeurs } : e));
      setReglages(null);
      toast(ta.saved);
    } catch (e) {
      toast(messageErreur(e, ta.error), 'error');
    } finally {
      setOccupe(false);
    }
  }

  async function renvoyer(acces: AccesPortail) {
    try {
      const resultat = await renvoyerLienPortail(acces.id, language);
      setLien({ nom: acces.nom, resultat });
      recharger();
    } catch (e) {
      toast(messageErreur(e, ta.error), 'error');
    }
  }

  async function supprimer() {
    if (!aSupprimer) return;
    const acces = aSupprimer;
    setASupprimer(null);
    try {
      await supprimerAccesPortail(acces.id);
      toast(ta.deleted);
      recharger();
    } catch (e) {
      toast(messageErreur(e, ta.error), 'error');
    }
  }

  const basculerCategorie = (categorie: string) => {
    if (!valeurs) return;
    const actuelles = valeurs.categories ?? [];
    const suite = actuelles.includes(categorie) ? actuelles.filter(c => c !== categorie) : [...actuelles, categorie];
    setReglages({ ...valeurs, categories: suite.length ? suite : null });
  };

  return (
    <div className="p-6 max-w-6xl">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div>
          <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2"><Globe className="text-blue-600" /> {ta.title}</h1>
          <p className="text-sm text-gray-500 dark:text-gray-400 max-w-3xl">{ta.subtitle}</p>
        </div>
      </div>

      {erreur && <p role="status" className="mb-4 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
      {etat && !etat.commandes_pretes && (
        <div className="mb-4 px-4 py-3 rounded-lg text-sm bg-amber-50 text-amber-900 border border-amber-200 flex items-start gap-2">
          <AlertTriangle size={18} className="shrink-0 mt-0.5" />
          <span>{ta.numberingMissing} <Link to="/configuration" className="font-medium underline">{ta.numberingLink}</Link></span>
        </div>
      )}
      {!peutModifier && etat && <p className="mb-4 text-sm text-gray-500 dark:text-gray-400">{ta.readOnly}</p>}

      {etat && valeurs && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-6">
          <section className={carte}>
            <h2 className="font-semibold text-gray-800 dark:text-white mb-4">{ta.settings}</h2>
            <label className="flex items-start gap-3 mb-4">
              <input type="checkbox" className="mt-1 h-4 w-4" checked={valeurs.actif} disabled={!peutModifier}
                onChange={e => setReglages({ ...valeurs, actif: e.target.checked })} />
              <span>
                <span className="block text-sm font-medium text-gray-800 dark:text-gray-100">{ta.open}</span>
                <span className="block text-xs text-gray-500 dark:text-gray-400">{ta.openHelp}</span>
              </span>
            </label>
            <label className="block text-sm text-gray-700 dark:text-gray-300 mb-4">{ta.welcome}
              <textarea rows={3} maxLength={2000} disabled={!peutModifier} value={valeurs.message_accueil ?? ''} placeholder={ta.welcomePlaceholder}
                onChange={e => setReglages({ ...valeurs, message_accueil: e.target.value })} className={`${classeChamp} mt-1`} />
            </label>
            <div className="mb-4">
              <p className="text-sm text-gray-700 dark:text-gray-300">{ta.categories}</p>
              {etat.categories_disponibles.length === 0 ? (
                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">{ta.noCategories}</p>
              ) : (
                <>
                  <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">{ta.categoriesHelp}</p>
                  <div className="max-h-48 overflow-y-auto border border-gray-200 rounded-lg p-2 grid grid-cols-1 sm:grid-cols-2 gap-1 dark:border-gray-600">
                    {etat.categories_disponibles.map(categorie => (
                      <label key={categorie} className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" disabled={!peutModifier} checked={valeurs.categories?.includes(categorie) ?? false} onChange={() => basculerCategorie(categorie)} />
                        <span className="truncate">{categorie}</span>
                      </label>
                    ))}
                  </div>
                </>
              )}
            </div>
            {peutModifier && (
              <button type="button" onClick={() => { void enregistrer(); }} disabled={occupe || reglages === null}
                className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50">
                {ta.save}
              </button>
            )}
          </section>

          <section className={carte}>
            <h2 className="font-semibold text-gray-800 dark:text-white mb-1">{ta.link}</h2>
            <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">{ta.linkHelp}</p>
            <input readOnly value={urlPortail} onFocus={e => e.target.select()} className={`${classeChamp} font-mono`} aria-label={ta.link} />
            <div className="flex flex-wrap gap-2 mt-2">
              <BoutonCopier texte={urlPortail} libelle={ta.copy} copie={ta.copied} />
              <a href={urlPortail} target="_blank" rel="noreferrer"
                className="inline-flex items-center gap-1.5 px-3 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                <ExternalLink size={16} /> {ta.openPortal}
              </a>
            </div>
            {!etat.parametres.actif && <p className="mt-3 text-xs text-amber-700">{ta.closedNote}</p>}
            <div className="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700">
              <h3 className="text-sm font-medium text-gray-800 dark:text-gray-100 mb-2">{ta.howTitle}</h3>
              <ol className="list-decimal pl-5 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                <li>{ta.how1}</li>
                <li>{ta.how2}</li>
                <li>{ta.how3}</li>
              </ol>
            </div>
          </section>
        </div>
      )}

      {etat && (
        <section className="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
          <div className="flex flex-wrap items-center justify-between gap-3 p-5 pb-3">
            <h2 className="font-semibold text-gray-800 dark:text-white">{ta.accesses}</h2>
            {peutCreer && (
              <button type="button" onClick={() => setEdition({ acces: null })}
                className="inline-flex items-center gap-1.5 px-3 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">
                <Plus size={16} /> {ta.newAccess}
              </button>
            )}
          </div>
          {etat.comptes.length === 0 ? (
            <p className="px-5 pb-5 text-sm text-gray-500 dark:text-gray-400">{ta.noAccess}</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-gray-50 dark:bg-gray-700">
                  <tr className="text-left text-xs uppercase text-gray-500 dark:text-gray-300">
                    <th className="px-4 py-2 font-medium">{ta.contact}</th>
                    <th className="px-4 py-2 font-medium">{ta.client}</th>
                    <th className="px-4 py-2 font-medium">{ta.state}</th>
                    <th className="px-4 py-2 font-medium">{ta.lastLogin}</th>
                    {(peutModifier || peutSupprimer) && <th className="px-4 py-2" />}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                  {etat.comptes.map(acces => {
                    const expire = acces.etat === 'invite' && acces.invitation_expire_le !== null && new Date(acces.invitation_expire_le) < new Date();
                    return (
                      <tr key={acces.id} className="text-gray-700 dark:text-gray-200">
                        <td className="px-4 py-3">
                          <p className="font-medium">{acces.nom}</p>
                          <p className="text-xs text-gray-500 dark:text-gray-400">{acces.email}{acces.telephone ? ` · ${acces.telephone}` : ''}</p>
                        </td>
                        <td className="px-4 py-3">
                          {acces.client && <Link to={`/clients/${acces.client.id}`} className="hover:underline">{acces.client.code} — {acces.client.raison_sociale}</Link>}
                        </td>
                        <td className="px-4 py-3">
                          <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${COULEURS_ETAT[acces.etat]}`}>{ta.states[acces.etat]}</span>
                          {acces.etat === 'invite' && acces.invitation_expire_le && (
                            <p className={`text-xs mt-1 ${expire ? 'text-red-600' : 'text-gray-500 dark:text-gray-400'}`}>
                              {expire ? ta.invitationExpired : ta.invitationExpires.replace('{date}', date(acces.invitation_expire_le))}
                            </p>
                          )}
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap text-gray-500 dark:text-gray-400">{acces.derniere_connexion ? date(acces.derniere_connexion) : ta.never}</td>
                        {(peutModifier || peutSupprimer) && (
                          <td className="px-4 py-3">
                            <div className="flex justify-end gap-1">
                              {peutModifier && (
                                <>
                                  <button type="button" onClick={() => { void renvoyer(acces); }} title={ta.resend} aria-label={ta.resend} className="p-2 rounded-lg text-gray-500 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-gray-700"><Mail size={16} /></button>
                                  <button type="button" onClick={() => setEdition({ acces })} title={ta.edit} aria-label={ta.edit} className="p-2 rounded-lg text-gray-500 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-gray-700"><Pencil size={16} /></button>
                                </>
                              )}
                              {peutSupprimer && (
                                <button type="button" onClick={() => setASupprimer(acces)} title={ta.delete} aria-label={ta.delete} className="p-2 rounded-lg text-gray-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-gray-700"><Trash2 size={16} /></button>
                              )}
                            </div>
                          </td>
                        )}
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </section>
      )}

      {edition && (
        <FenetreAcces
          acces={edition.acces}
          onFermer={() => setEdition(null)}
          onCree={(nom, resultat) => { setEdition(null); setLien({ nom, resultat }); recharger(); }}
          onModifie={() => { setEdition(null); toast(ta.updated); recharger(); }}
        />
      )}

      {lien && (
        <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" onClick={() => setLien(null)}>
          <div className="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-lg p-6" onClick={e => e.stopPropagation()} role="dialog" aria-modal="true">
            <div className="flex items-start justify-between gap-3 mb-3">
              <h2 className="font-semibold text-gray-800 dark:text-white">{ta.linkTitle.replace('{nom}', lien.nom)}</h2>
              <button type="button" onClick={() => setLien(null)} aria-label={ta.close} className="text-gray-400 hover:text-gray-600"><X size={18} /></button>
            </div>
            <p className={`text-sm mb-3 px-3 py-2 rounded-lg ${lien.resultat.courriel_envoye ? 'bg-green-50 text-green-800' : 'bg-amber-50 text-amber-900'}`}>
              {lien.resultat.courriel_envoye ? ta.linkSent.replace('{email}', lien.resultat.compte.email) : ta.linkNotSent}
            </p>
            <div className="flex gap-2">
              <input readOnly value={lien.resultat.lien} onFocus={e => e.target.select()} className={`${classeChamp} font-mono text-xs`} aria-label={ta.link} />
              <BoutonCopier texte={lien.resultat.lien} libelle={ta.copy} copie={ta.copied} />
            </div>
            {lien.resultat.compte.invitation_expire_le && (
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-2">{ta.linkValidity.replace('{date}', date(lien.resultat.compte.invitation_expire_le))}</p>
            )}
            <div className="flex justify-end mt-5">
              <button type="button" onClick={() => setLien(null)} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">{ta.close}</button>
            </div>
          </div>
        </div>
      )}

      <ConfirmModal
        open={aSupprimer !== null}
        title={ta.deleteTitle}
        message={aSupprimer ? ta.deleteMessage.replace('{nom}', aSupprimer.nom) : ''}
        confirmLabel={ta.delete}
        onConfirm={() => { void supprimer(); }}
        onCancel={() => setASupprimer(null)}
      />
      <Toasts toasts={toasts} onDismiss={dismiss} />
    </div>
  );
}

/** Nouvel accès (client, contact, langue du courriel) ou modification d'un accès (contact, suspension). */
function FenetreAcces({ acces, onFermer, onCree, onModifie }: {
  acces: AccesPortail | null;
  onFermer: () => void;
  onCree: (nom: string, resultat: ResultatInvitation) => void;
  onModifie: () => void;
}) {
  const { t, language } = useLanguage();
  const ta = t.portalAdmin;
  const [clients, setClients] = useState<Client[]>([]);
  const [form, setForm] = useState({
    client_id: acces ? String(acces.client_id) : '',
    nom: acces?.nom ?? '',
    email: acces?.email ?? '',
    telephone: acces?.telephone ?? '',
    actif: acces?.actif ?? true,
    langue: language as Langue,
  });
  const [erreur, setErreur] = useState('');
  const [occupe, setOccupe] = useState(false);

  useEffect(() => {
    if (acces) return;
    let actif = true;
    getClientsActifs().then(liste => { if (actif) setClients(liste); }, e => { if (actif) setErreur(messageErreur(e, ta.error)); });
    return () => { actif = false; };
  }, [acces, ta.error]);

  // Le contact de la fiche client pré-remplit les champs encore vides.
  const choisirClient = (id: string) => {
    const client = clients.find(c => String(c.id) === id);
    setForm(f => ({
      ...f,
      client_id: id,
      nom: f.nom || client?.contact_nom || '',
      email: f.email || client?.email || '',
      telephone: f.telephone || client?.contact_telephone || client?.telephone || '',
    }));
  };

  async function valider(e: React.FormEvent) {
    e.preventDefault();
    setErreur('');
    setOccupe(true);
    try {
      const contact = { nom: form.nom.trim(), email: form.email.trim(), telephone: form.telephone.trim() || null };
      if (acces) {
        await modifierAccesPortail(acces.id, { ...contact, actif: form.actif });
        onModifie();
      } else {
        const resultat = await creerAccesPortail({ ...contact, client_id: Number(form.client_id), langue: form.langue });
        onCree(contact.nom, resultat);
      }
    } catch (err) {
      setErreur(messageErreur(err, ta.error));
      setOccupe(false);
    }
  }

  const etiquette = 'block text-sm text-gray-700 dark:text-gray-300';
  return (
    <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" onClick={onFermer}>
      <form onSubmit={valider} onClick={e => e.stopPropagation()} role="dialog" aria-modal="true"
        className="bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-lg p-6 space-y-3 max-h-[90vh] overflow-y-auto">
        <div className="flex items-start justify-between gap-3">
          <h2 className="font-semibold text-gray-800 dark:text-white">{acces ? ta.editTitle : ta.newAccess}</h2>
          <button type="button" onClick={onFermer} aria-label={ta.close} className="text-gray-400 hover:text-gray-600"><X size={18} /></button>
        </div>
        {acces ? (
          <p className="text-sm text-gray-600 dark:text-gray-300">{ta.client} : {acces.client ? `${acces.client.code} — ${acces.client.raison_sociale}` : ''}</p>
        ) : (
          <label className={etiquette}>{ta.client}
            <select required value={form.client_id} onChange={e => choisirClient(e.target.value)} className={`${classeChamp} mt-1`}>
              <option value="">{ta.chooseClient}</option>
              {clients.map(c => <option key={c.id} value={c.id}>{c.code} — {c.raison_sociale}</option>)}
            </select>
          </label>
        )}
        <label className={etiquette}>{ta.name}
          <input required maxLength={150} value={form.nom} onChange={e => setForm({ ...form, nom: e.target.value })} className={`${classeChamp} mt-1`} />
        </label>
        <label className={etiquette}>{ta.email}
          <input type="email" required maxLength={255} value={form.email} onChange={e => setForm({ ...form, email: e.target.value })} className={`${classeChamp} mt-1`} />
        </label>
        <label className={etiquette}>{ta.phone}
          <input type="tel" maxLength={50} value={form.telephone} onChange={e => setForm({ ...form, telephone: e.target.value })} className={`${classeChamp} mt-1`} />
        </label>
        {acces ? (
          <label className="flex items-start gap-3 pt-1">
            <input type="checkbox" className="mt-1 h-4 w-4" checked={form.actif} onChange={e => setForm({ ...form, actif: e.target.checked })} />
            <span>
              <span className="block text-sm font-medium text-gray-800 dark:text-gray-100">{ta.active}</span>
              <span className="block text-xs text-gray-500 dark:text-gray-400">{ta.activeHelp}</span>
            </span>
          </label>
        ) : (
          <label className={etiquette}>{ta.emailLanguage}
            <select value={form.langue} onChange={e => setForm({ ...form, langue: e.target.value as Langue })} className={`${classeChamp} mt-1`}>
              <option value="fr">Français</option>
              <option value="en">English</option>
            </select>
          </label>
        )}
        {erreur && <p role="alert" className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
        <div className="flex justify-end gap-2 pt-2">
          <button type="button" onClick={onFermer} className="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{ta.cancel}</button>
          <button type="submit" disabled={occupe} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50">
            {acces ? ta.save : ta.create}
          </button>
        </div>
      </form>
    </div>
  );
}
