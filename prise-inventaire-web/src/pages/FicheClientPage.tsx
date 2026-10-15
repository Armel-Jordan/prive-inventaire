import { useCallback, useEffect, useId, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Building2, Mail, MapPin, Phone, Star, User } from 'lucide-react';
import {
  basculerFavoriClient, changerCategorieClient, getCategoriesClients, getFicheClient,
  type AdresseClient, type CategorieClient, type ContactClient, type FicheClient,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import ListeElementsFiche from '@/components/fiche-client/ListeElementsFiche';
import OngletSynthese from '@/components/fiche-client/OngletSynthese';
import OngletHistorique from '@/components/fiche-client/OngletHistorique';
import OngletRemarques from '@/components/fiche-client/OngletRemarques';
import { formatMontant, useSymboleDevise } from '@/components/fiche-client/format';

type Onglet = 'synthese' | 'historique' | 'contacts' | 'adresses' | 'remarques';
type Compteur = 'contacts_count' | 'adresses_count' | 'remarques_count';

/** Fiche client : coordonnées, synthèse des ventes, historique d'achats, contacts, adresses et remarques. */
export default function FicheClientPage() {
  const { id: idParam } = useParams();
  const clientId = Number(idParam);
  const { t, language } = useLanguage();
  const tf = t.clientFile;
  const id = useId();
  const devise = useSymboleDevise();
  const [client, setClient] = useState<FicheClient | null>(null);
  const [categories, setCategories] = useState<CategorieClient[]>([]);
  const [onglet, setOnglet] = useState<Onglet>('synthese');
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    let actif = true;
    getFicheClient(clientId)
      .then(c => { if (actif) { setClient(c); setErreur(''); } })
      .catch(e => { if (actif) setErreur(e instanceof ApiError && e.status === 404 ? tf.notFound : e instanceof ApiError ? e.message : tf.error); });
    getCategoriesClients().then(c => { if (actif) setCategories(c); }).catch(() => {});
    return () => { actif = false; };
  }, [clientId, tf.notFound, tf.error]);

  const compter = useCallback((cle: Compteur) => (nombre: number) => setClient(c => (c && c[cle] !== nombre ? { ...c, [cle]: nombre } : c)), []);
  const compterContacts = useCallback((n: number) => compter('contacts_count')(n), [compter]);
  const compterAdresses = useCallback((n: number) => compter('adresses_count')(n), [compter]);
  const compterRemarques = useCallback((n: number) => compter('remarques_count')(n), [compter]);

  if (!client) {
    return (
      <div className="p-6">
        <Link to="/clients" className="inline-flex items-center gap-1 text-sm text-purple-700 dark:text-purple-300 hover:underline mb-4"><ArrowLeft size={14} /> {tf.back}</Link>
        {erreur && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
      </div>
    );
  }

  async function basculerFavori() {
    if (!client) return;
    const { favori } = await basculerFavoriClient(client.id, !client.favori);
    setClient({ ...client, favori });
  }

  async function changerCategorie(valeur: string) {
    if (!client) return;
    try {
      const misAJour = await changerCategorieClient(client.id, valeur ? Number(valeur) : null);
      setClient({ ...client, categorie_id: misAJour.categorie_id, categorie: misAJour.categorie });
    } catch (e) {
      setErreur(e instanceof ApiError ? e.message : tf.error);
    }
  }

  const onglets: { cle: Onglet; libelle: string; nombre?: number }[] = [
    { cle: 'synthese', libelle: tf.tabs.summary },
    { cle: 'historique', libelle: tf.tabs.history },
    { cle: 'contacts', libelle: tf.tabs.contacts, nombre: client.contacts_count },
    { cle: 'adresses', libelle: tf.tabs.addresses, nombre: client.adresses_count },
    { cle: 'remarques', libelle: tf.tabs.notes, nombre: client.remarques_count },
  ];
  const tc = tf.contacts;
  const ta = tf.addresses;
  const rappel = 'p-3 rounded-lg bg-gray-50 dark:bg-gray-700/40 text-sm text-gray-600 dark:text-gray-300';

  return (
    <div className="p-6 space-y-6">
      <Link to="/clients" className="inline-flex items-center gap-1 text-sm text-purple-700 dark:text-purple-300 hover:underline"><ArrowLeft size={14} /> {tf.back}</Link>

      {/* En-tête de la fiche */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2">
                <Building2 className="text-purple-600" /> {client.raison_sociale}
              </h1>
              <button onClick={basculerFavori} aria-pressed={Boolean(client.favori)} aria-label={client.favori ? tf.unfavorite : tf.favorite} title={client.favori ? tf.unfavorite : tf.favorite}
                className={client.favori ? 'text-amber-500' : 'text-gray-300 hover:text-amber-400 dark:text-gray-600'}>
                <Star size={20} fill={client.favori ? 'currentColor' : 'none'} />
              </button>
            </div>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
              <span className="font-mono">{client.code}</span>
              <span className={`ml-3 px-2 py-0.5 text-xs rounded-full ${client.actif ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'}`}>
                {client.actif ? tf.active : tf.inactive}
              </span>
            </p>
          </div>
          <div>
            <label htmlFor={`${id}-categorie`} className="block text-xs text-gray-500 mb-1">{tf.category}</label>
            <select id={`${id}-categorie`} value={client.categorie_id ?? ''} onChange={e => changerCategorie(e.target.value)}
              className="px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white">
              <option value="">{tf.noCategory}</option>
              {categories.filter(c => c.actif || c.id === client.categorie_id).map(c => <option key={c.id} value={c.id}>{c.nom}</option>)}
            </select>
          </div>
        </div>

        <dl className="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
          <div>
            <dt className="text-xs text-gray-500 flex items-center gap-1"><MapPin size={12} /> {tf.billingAddress}</dt>
            <dd className="text-gray-800 dark:text-gray-200 whitespace-pre-line">{client.adresse_facturation}{'\n'}{client.code_postal} {client.ville}</dd>
          </div>
          <div className="space-y-1">
            {client.telephone && <dd className="flex items-center gap-1 text-gray-800 dark:text-gray-200"><Phone size={12} className="text-gray-400" /> {client.telephone}</dd>}
            {client.email && <dd className="flex items-center gap-1 text-gray-800 dark:text-gray-200"><Mail size={12} className="text-gray-400" /> {client.email}</dd>}
            {client.contact_nom && <dd className="flex items-center gap-1 text-gray-800 dark:text-gray-200"><User size={12} className="text-gray-400" /> {client.contact_nom}{client.contact_telephone && ` · ${client.contact_telephone}`}</dd>}
          </div>
          <div className="space-y-1 text-gray-800 dark:text-gray-200">
            <dd>{tf.creditLimit} : {client.encours_max != null ? formatMontant(Number(client.encours_max), devise, language) : '—'}</dd>
            <dd>{tf.globalDiscount} : {Number(client.taux_remise_global)} %</dd>
          </div>
        </dl>
        <p className="mt-3 text-xs text-gray-400">{tf.editHint}</p>
        {erreur && <p className="mt-3 px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
      </div>

      {/* Onglets */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md">
        <div role="tablist" aria-label={client.raison_sociale} className="flex flex-wrap gap-1 border-b dark:border-gray-700 px-4">
          {onglets.map(o => (
            <button key={o.cle} role="tab" id={`${id}-onglet-${o.cle}`} aria-selected={onglet === o.cle} aria-controls={`${id}-panneau`} onClick={() => setOnglet(o.cle)}
              className={`px-4 py-3 text-sm font-medium border-b-2 -mb-px ${onglet === o.cle ? 'border-purple-600 text-purple-700 dark:text-purple-300' : 'border-transparent text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white'}`}>
              {o.libelle}
              {o.nombre !== undefined && o.nombre > 0 && <span className="ml-1.5 px-1.5 rounded-full text-xs bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">{o.nombre}</span>}
            </button>
          ))}
        </div>
        <div role="tabpanel" id={`${id}-panneau`} aria-labelledby={`${id}-onglet-${onglet}`} className="p-6">
          {onglet === 'synthese' && <OngletSynthese clientId={client.id} devise={devise} />}
          {onglet === 'historique' && <OngletHistorique clientId={client.id} devise={devise} />}
          {onglet === 'contacts' && (
            <ListeElementsFiche<ContactClient>
              clientId={client.id}
              liste="contacts"
              colonnePrincipale="principal"
              onCompte={compterContacts}
              libelles={{ add: tc.add, empty: tc.empty, main: tc.main }}
              titre={c => c.nom}
              details={c => (
                <>
                  {c.fonction && <p>{c.fonction}</p>}
                  {(c.telephone || c.telephone_secondaire) && <p className="flex items-center gap-1"><Phone size={12} className="text-gray-400" /> {[c.telephone, c.telephone_secondaire].filter(Boolean).join(' · ')}</p>}
                  {c.email && <p className="flex items-center gap-1"><Mail size={12} className="text-gray-400" /> {c.email}</p>}
                  {(c.recoit_factures || c.recoit_commandes) && <p className="text-xs text-purple-700 dark:text-purple-300">{[c.recoit_factures && tc.receivesInvoices, c.recoit_commandes && tc.receivesOrders].filter(Boolean).join(' · ')}</p>}
                  {c.notes && <p className="text-xs text-gray-500 whitespace-pre-line">{c.notes}</p>}
                  {!c.actif && <p className="text-xs text-red-600">{tf.inactive}</p>}
                </>
              )}
              champs={[
                { cle: 'nom', libelle: tc.name, requis: true },
                { cle: 'fonction', libelle: tc.role },
                { cle: 'telephone', libelle: tf.phone, type: 'tel' },
                { cle: 'telephone_secondaire', libelle: tc.phone2, type: 'tel' },
                { cle: 'email', libelle: tf.email, type: 'email' },
                { cle: 'notes', libelle: tc.notes, type: 'textarea' },
                { cle: 'principal', libelle: tc.main, type: 'case' },
                { cle: 'recoit_factures', libelle: tc.receivesInvoices, type: 'case' },
                { cle: 'recoit_commandes', libelle: tc.receivesOrders, type: 'case' },
                { cle: 'actif', libelle: tf.active, type: 'case', defaut: true },
              ]}
              entete={client.contact_nom ? (
                <p className={rappel}><span className="font-medium">{tc.fromFile} :</span> {client.contact_nom}{client.contact_telephone && ` · ${client.contact_telephone}`}</p>
              ) : undefined}
            />
          )}
          {onglet === 'adresses' && (
            <ListeElementsFiche<AdresseClient>
              clientId={client.id}
              liste="adresses"
              colonnePrincipale="principale"
              onCompte={compterAdresses}
              libelles={{ add: ta.add, empty: ta.empty, main: ta.main }}
              titre={a => a.libelle}
              details={a => (
                <>
                  <p className="whitespace-pre-line">{a.adresse}</p>
                  {(a.code_postal || a.ville || a.region || a.pays) && <p>{[[a.code_postal, a.ville].filter(Boolean).join(' '), a.region, a.pays].filter(Boolean).join(', ')}</p>}
                  {a.livraison && <p className="text-xs text-purple-700 dark:text-purple-300">{ta.delivery}</p>}
                  {a.instructions && <p className="text-xs text-gray-500 whitespace-pre-line">{ta.instructions} : {a.instructions}</p>}
                  {!a.actif && <p className="text-xs text-red-600">{tf.inactive}</p>}
                </>
              )}
              champs={[
                { cle: 'libelle', libelle: ta.label, requis: true, aide: ta.labelHint },
                { cle: 'adresse', libelle: ta.address, requis: true, type: 'textarea' },
                { cle: 'ville', libelle: ta.city },
                { cle: 'code_postal', libelle: ta.postalCode },
                { cle: 'region', libelle: ta.region },
                { cle: 'pays', libelle: ta.country },
                { cle: 'instructions', libelle: ta.instructions, type: 'textarea' },
                { cle: 'principale', libelle: ta.main, type: 'case' },
                { cle: 'livraison', libelle: ta.delivery, type: 'case', defaut: true },
                { cle: 'actif', libelle: tf.active, type: 'case', defaut: true },
              ]}
              entete={(
                <div className={rappel}>
                  <p className="font-medium mb-1">{ta.fromFile}</p>
                  <p><span className="text-gray-500">{tf.billingAddress} :</span> {client.adresse_facturation}, {client.code_postal} {client.ville}</p>
                  {client.adresse_livraison && <p><span className="text-gray-500">{tf.deliveryAddress} :</span> {client.adresse_livraison}</p>}
                </div>
              )}
            />
          )}
          {onglet === 'remarques' && <OngletRemarques clientId={client.id} onCompte={compterRemarques} />}
        </div>
      </div>
    </div>
  );
}
