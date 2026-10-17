import { useCallback, useEffect, useId, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { AlertTriangle, ArrowLeft, Mail, MapPin, Phone, Truck, User } from 'lucide-react';
import {
  getFicheFournisseur,
  type AdresseFournisseur, type CompteBancaireFournisseur, type ContactFournisseur, type FicheFournisseur,
} from '@/services/api';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { usePermissions } from '@/contexts/PermissionsContext';
import ListeElementsFiche from '@/components/fiche/ListeElementsFiche';
import OngletRemarques from '@/components/fiche/OngletRemarques';
import { formatDate, useSymboleDevise } from '@/components/fiche/format';
import OngletSyntheseFournisseur from '@/components/fiche-fournisseur/OngletSyntheseFournisseur';
import OngletHistoriqueFournisseur from '@/components/fiche-fournisseur/OngletHistoriqueFournisseur';
import OngletProduitsFournis from '@/components/fiche-fournisseur/OngletProduitsFournis';

type Onglet = 'synthese' | 'historique' | 'produits' | 'contacts' | 'adresses' | 'comptes' | 'remarques';
type Compteur = 'produits_count' | 'contacts_count' | 'adresses_count' | 'comptes_bancaires_count' | 'remarques_count';

/**
 * Fiche fournisseur : coordonnées, synthèse des achats, historique, produits fournis, contacts, adresses,
 * coordonnées bancaires et remarques.
 */
export default function FicheFournisseurPage() {
  const { id: idParam } = useParams();
  const fournisseurId = Number(idParam);
  const { t, language } = useLanguage();
  const tf = t.supplierFile;
  const tcf = t.clientFile;
  const id = useId();
  const devise = useSymboleDevise();
  // Coordonnées bancaires : permission « Gérer les coordonnées bancaires ».
  const { peut } = usePermissions();
  const responsable = peut('fournisseurs', 'comptes_bancaires');
  const [fournisseur, setFournisseur] = useState<FicheFournisseur | null>(null);
  const [onglet, setOnglet] = useState<Onglet>('synthese');
  const [erreur, setErreur] = useState('');

  useEffect(() => {
    let actif = true;
    getFicheFournisseur(fournisseurId)
      .then(f => { if (actif) { setFournisseur(f); setErreur(''); } })
      .catch(e => { if (actif) setErreur(e instanceof ApiError && e.status === 404 ? tf.notFound : e instanceof ApiError ? e.message : tf.error); });
    return () => { actif = false; };
  }, [fournisseurId, tf.notFound, tf.error]);

  const compter = useCallback((cle: Compteur) => (nombre: number) => setFournisseur(f => (f && f[cle] !== nombre ? { ...f, [cle]: nombre } : f)), []);
  const compterProduits = useCallback((n: number) => compter('produits_count')(n), [compter]);
  const compterContacts = useCallback((n: number) => compter('contacts_count')(n), [compter]);
  const compterAdresses = useCallback((n: number) => compter('adresses_count')(n), [compter]);
  const compterComptes = useCallback((n: number) => compter('comptes_bancaires_count')(n), [compter]);
  const compterRemarques = useCallback((n: number) => compter('remarques_count')(n), [compter]);

  if (!fournisseur) {
    return (
      <div className="p-6">
        <Link to="/fournisseurs" className="inline-flex items-center gap-1 text-sm text-teal-700 dark:text-teal-300 hover:underline mb-4"><ArrowLeft size={14} /> {tf.back}</Link>
        {erreur && <p className="px-3 py-2 rounded-lg text-sm bg-red-50 text-red-700">{erreur}</p>}
      </div>
    );
  }

  const onglets: { cle: Onglet; libelle: string; nombre?: number }[] = [
    { cle: 'synthese', libelle: tf.tabs.summary },
    { cle: 'historique', libelle: tf.tabs.history },
    { cle: 'produits', libelle: tf.tabs.products, nombre: fournisseur.produits_count },
    { cle: 'contacts', libelle: tf.tabs.contacts, nombre: fournisseur.contacts_count },
    { cle: 'adresses', libelle: tf.tabs.addresses, nombre: fournisseur.adresses_count },
    { cle: 'comptes', libelle: tf.tabs.bank, nombre: fournisseur.comptes_bancaires_count },
    { cle: 'remarques', libelle: tf.tabs.notes, nombre: fournisseur.remarques_count },
  ];
  const tc = tcf.contacts;
  const ta = tcf.addresses;
  const tb = tf.bank;
  const base = `/fournisseurs/${fournisseur.id}`;
  const rappel = 'p-3 rounded-lg bg-gray-50 dark:bg-gray-700/40 text-sm text-gray-600 dark:text-gray-300';

  return (
    <div className="p-6 space-y-6">
      <Link to="/fournisseurs" className="inline-flex items-center gap-1 text-sm text-teal-700 dark:text-teal-300 hover:underline"><ArrowLeft size={14} /> {tf.back}</Link>

      {/* En-tête de la fiche */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6">
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white flex items-center gap-2">
          <Truck className="text-teal-600" /> {fournisseur.raison_sociale}
        </h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          <span className="font-mono">{fournisseur.code}</span>
          <span className={`ml-3 px-2 py-0.5 text-xs rounded-full ${fournisseur.actif ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'}`}>
            {fournisseur.actif ? tf.active : tf.inactive}
          </span>
        </p>

        <dl className="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
          <div>
            <dt className="text-xs text-gray-500 flex items-center gap-1"><MapPin size={12} /> {tf.address}</dt>
            <dd className="text-gray-800 dark:text-gray-200 whitespace-pre-line">{fournisseur.adresse || '—'}</dd>
          </div>
          <div className="space-y-1">
            {fournisseur.telephone && <dd className="flex items-center gap-1 text-gray-800 dark:text-gray-200"><Phone size={12} className="text-gray-400" /> {fournisseur.telephone}</dd>}
            {fournisseur.email && <dd className="flex items-center gap-1 text-gray-800 dark:text-gray-200"><Mail size={12} className="text-gray-400" /> {fournisseur.email}</dd>}
            {fournisseur.contact_nom && <dd className="flex items-center gap-1 text-gray-800 dark:text-gray-200"><User size={12} className="text-gray-400" /> {fournisseur.contact_nom}{fournisseur.contact_telephone && ` · ${fournisseur.contact_telephone}`}</dd>}
          </div>
          <div className="space-y-1 text-gray-800 dark:text-gray-200">
            <dd>{tf.paymentTerms} : {fournisseur.conditions_paiement || '—'}</dd>
            <dd>{tf.currency} : {fournisseur.devise || '—'}</dd>
          </div>
        </dl>
        <p className="mt-3 text-xs text-gray-400">{tf.editHint}</p>
      </div>

      {/* Onglets */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow-md">
        <div role="tablist" aria-label={fournisseur.raison_sociale} className="flex flex-wrap gap-1 border-b dark:border-gray-700 px-4">
          {onglets.map(o => (
            <button key={o.cle} role="tab" id={`${id}-onglet-${o.cle}`} aria-selected={onglet === o.cle} aria-controls={`${id}-panneau`} onClick={() => setOnglet(o.cle)}
              className={`px-4 py-3 text-sm font-medium border-b-2 -mb-px ${onglet === o.cle ? 'border-teal-600 text-teal-700 dark:text-teal-300' : 'border-transparent text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white'}`}>
              {o.libelle}
              {o.nombre !== undefined && o.nombre > 0 && <span className="ml-1.5 px-1.5 rounded-full text-xs bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">{o.nombre}</span>}
            </button>
          ))}
        </div>
        <div role="tabpanel" id={`${id}-panneau`} aria-labelledby={`${id}-onglet-${onglet}`} className="p-6">
          {onglet === 'synthese' && <OngletSyntheseFournisseur fournisseurId={fournisseur.id} code={fournisseur.code} devise={devise} />}
          {onglet === 'historique' && <OngletHistoriqueFournisseur fournisseurId={fournisseur.id} devise={devise} />}
          {onglet === 'produits' && <OngletProduitsFournis fournisseurId={fournisseur.id} devise={devise} deviseFournisseur={fournisseur.devise} onCompte={compterProduits} />}
          {onglet === 'contacts' && (
            <ListeElementsFiche<ContactFournisseur>
              chemin={`${base}/contacts`}
              colonnePrincipale="principal"
              onCompte={compterContacts}
              libelles={{ add: tc.add, empty: tc.empty, main: tc.main }}
              titre={c => c.nom}
              details={c => (
                <>
                  {c.fonction && <p>{c.fonction}</p>}
                  {(c.telephone || c.telephone_secondaire) && <p className="flex items-center gap-1"><Phone size={12} className="text-gray-400" /> {[c.telephone, c.telephone_secondaire].filter(Boolean).join(' · ')}</p>}
                  {c.email && <p className="flex items-center gap-1"><Mail size={12} className="text-gray-400" /> {c.email}</p>}
                  {c.recoit_commandes && <p className="text-xs text-teal-700 dark:text-teal-300">{tf.contacts.receivesOrders}</p>}
                  {c.notes && <p className="text-xs text-gray-500 whitespace-pre-line">{c.notes}</p>}
                  {!c.actif && <p className="text-xs text-red-600">{tf.inactive}</p>}
                </>
              )}
              champs={[
                { cle: 'nom', libelle: tc.name, requis: true },
                { cle: 'fonction', libelle: tc.role },
                { cle: 'telephone', libelle: tcf.phone, type: 'tel' },
                { cle: 'telephone_secondaire', libelle: tc.phone2, type: 'tel' },
                { cle: 'email', libelle: tcf.email, type: 'email' },
                { cle: 'notes', libelle: tc.notes, type: 'textarea' },
                { cle: 'principal', libelle: tc.main, type: 'case' },
                { cle: 'recoit_commandes', libelle: tf.contacts.receivesOrders, type: 'case' },
                { cle: 'actif', libelle: tf.active, type: 'case', defaut: true },
              ]}
              entete={fournisseur.contact_nom ? (
                <p className={rappel}><span className="font-medium">{tc.fromFile} :</span> {fournisseur.contact_nom}{fournisseur.contact_telephone && ` · ${fournisseur.contact_telephone}`}</p>
              ) : undefined}
            />
          )}
          {onglet === 'adresses' && (
            <ListeElementsFiche<AdresseFournisseur>
              chemin={`${base}/adresses`}
              colonnePrincipale="principale"
              onCompte={compterAdresses}
              libelles={{ add: ta.add, empty: ta.empty, main: ta.main }}
              titre={a => a.libelle}
              details={a => (
                <>
                  <p className="whitespace-pre-line">{a.adresse}</p>
                  {(a.code_postal || a.ville || a.region || a.pays) && <p>{[[a.code_postal, a.ville].filter(Boolean).join(' '), a.region, a.pays].filter(Boolean).join(', ')}</p>}
                  {a.enlevement && <p className="text-xs text-teal-700 dark:text-teal-300">{tf.addresses.pickup}</p>}
                  {a.instructions && <p className="text-xs text-gray-500 whitespace-pre-line">{tf.addresses.instructions} : {a.instructions}</p>}
                  {!a.actif && <p className="text-xs text-red-600">{tf.inactive}</p>}
                </>
              )}
              champs={[
                { cle: 'libelle', libelle: ta.label, requis: true, aide: tf.addresses.labelHint },
                { cle: 'adresse', libelle: ta.address, requis: true, type: 'textarea' },
                { cle: 'ville', libelle: ta.city },
                { cle: 'code_postal', libelle: ta.postalCode },
                { cle: 'region', libelle: ta.region },
                { cle: 'pays', libelle: ta.country },
                { cle: 'instructions', libelle: tf.addresses.instructions, type: 'textarea' },
                { cle: 'principale', libelle: ta.main, type: 'case' },
                { cle: 'enlevement', libelle: tf.addresses.pickup, type: 'case' },
                { cle: 'actif', libelle: tf.active, type: 'case', defaut: true },
              ]}
              entete={fournisseur.adresse ? (
                <p className={rappel}><span className="font-medium">{tf.addresses.fromFile} :</span> {fournisseur.adresse}</p>
              ) : undefined}
            />
          )}
          {onglet === 'comptes' && (
            <ListeElementsFiche<CompteBancaireFournisseur>
              chemin={`${base}/comptes-bancaires`}
              colonnePrincipale="principal"
              onCompte={compterComptes}
              lectureSeule={!responsable}
              libelles={{ add: tb.add, empty: tb.empty, main: tb.main }}
              titre={c => c.banque}
              details={c => (
                <>
                  {(c.agence || c.titulaire) && <p>{[c.agence, c.titulaire].filter(Boolean).join(' · ')}</p>}
                  {c.numero_compte && <p className="font-mono text-xs">{c.numero_compte}</p>}
                  {c.iban && <p className="font-mono text-xs">{tb.iban} {c.iban.replace(/(.{4})/g, '$1 ').trim()}</p>}
                  {(c.bic || c.devise) && <p className="font-mono text-xs">{[c.bic && `${tb.bic} ${c.bic}`, c.devise].filter(Boolean).join(' · ')}</p>}
                  {c.notes && <p className="text-xs text-gray-500 whitespace-pre-line">{c.notes}</p>}
                  {!c.actif && <p className="text-xs text-red-600">{tf.inactive}</p>}
                  <p className="text-xs text-gray-400">{tb.modifiedBy.replace('{nom}', c.auteur_modification?.nom ?? '—').replace('{date}', formatDate(c.updated_at, language, true))}</p>
                </>
              )}
              champs={[
                { cle: 'banque', libelle: tb.bank, requis: true },
                { cle: 'agence', libelle: tb.branch },
                { cle: 'titulaire', libelle: tb.holder },
                { cle: 'numero_compte', libelle: tb.accountNumber },
                { cle: 'iban', libelle: tb.iban },
                { cle: 'bic', libelle: tb.bic },
                { cle: 'devise', libelle: tb.currency, aide: 'XAF, EUR…' },
                { cle: 'notes', libelle: tb.notes, type: 'textarea' },
                { cle: 'principal', libelle: tb.main, type: 'case' },
                { cle: 'actif', libelle: tf.active, type: 'case', defaut: true },
              ]}
              entete={(
                <div className="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-sm text-amber-800 dark:text-amber-200 flex gap-2">
                  <AlertTriangle size={16} className="shrink-0 mt-0.5" />
                  <div>
                    <p>{tb.warning}</p>
                    {!responsable && <p className="mt-1 text-xs">{tb.readOnly}</p>}
                  </div>
                </div>
              )}
            />
          )}
          {onglet === 'remarques' && <OngletRemarques chemin={`${base}/remarques`} onCompte={compterRemarques} />}
        </div>
      </div>
    </div>
  );
}
