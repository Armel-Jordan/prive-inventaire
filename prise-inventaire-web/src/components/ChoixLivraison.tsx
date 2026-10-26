import { useEffect, useState } from 'react';
import { getListeFiche, type AdresseClient, type ContactClient, type LivraisonDocument } from '@/services/api';
import { useLanguage } from '@/i18n/useLanguage';

/** Contact et « Livrer à » choisis ; non définis : pas encore choisis (les principaux du client sont alors proposés). */
export interface ValeurLivraison {
  contact_id?: number | null;
  adresse_livraison_id?: number | null;
  /** Document modifié : l'adresse qu'il porte et ses instructions recopiées, montrées tant qu'elle reste choisie. */
  copie?: { adresse_livraison_id: number | null; instructions_livraison: string | null };
}

/** Valeur d'un document ouvert en modification : ce qu'il porte, sans rien proposer. */
export const livraisonDuDocument = (document: LivraisonDocument): ValeurLivraison => ({
  contact_id: document.contact_id ?? null,
  adresse_livraison_id: document.adresse_livraison_id ?? null,
  copie: { adresse_livraison_id: document.adresse_livraison_id ?? null, instructions_livraison: document.instructions_livraison ?? null },
});

/** Champs envoyés à l'API ; non choisis, ils sont omis et l'API prend les principaux du client. */
export const saisieLivraison = (valeur: ValeurLivraison) => ({ contact_id: valeur.contact_id, adresse_livraison_id: valeur.adresse_livraison_id });

interface Props {
  /** Préfixe des identifiants des champs, ex. « devis » : devis-contact, devis-livrer-a. */
  idPrefixe: string;
  clientId: number | null;
  valeur: ValeurLivraison;
  onChange: (valeur: ValeurLivraison) => void;
  /** Classes du bloc de chaque champ. */
  className?: string;
}

const champ = 'w-full px-3 py-2 border rounded-lg dark:bg-gray-700 dark:border-gray-600 dark:text-white';

/**
 * Contact du client et adresse « Livrer à » d'un document de vente (socle S4.1). À la création, le contact principal
 * et l'adresse de livraison principale du client sont proposés ; l'adresse est recopiée sur le document par l'API.
 * Rien n'est affiché pour un client sans contact ni adresse de livraison.
 */
export default function ChoixLivraison({ idPrefixe, clientId, valeur, onChange, className }: Props) {
  const { t } = useLanguage();
  const tr = t.deliveryContact;
  const [listes, setListes] = useState<{ client: number; contacts: ContactClient[]; adresses: AdresseClient[] } | null>(null);

  useEffect(() => {
    if (!clientId) return;
    let actif = true;
    Promise.all([
      getListeFiche<ContactClient>(`/clients/${clientId}/contacts`),
      getListeFiche<AdresseClient>(`/clients/${clientId}/adresses`),
    ])
      .then(([contacts, adresses]) => { if (actif) setListes({ client: clientId, contacts, adresses }); })
      .catch(() => { if (actif) setListes({ client: clientId, contacts: [], adresses: [] }); });
    return () => { actif = false; };
  }, [clientId]);

  const charges = listes && listes.client === clientId ? listes : null;
  const contacts = charges?.contacts.filter(c => c.actif || c.id === valeur.contact_id) ?? [];
  const adresses = charges?.adresses.filter(a => (a.actif && a.livraison) || a.id === valeur.adresse_livraison_id) ?? [];

  // Pas encore choisis : les principaux du client (sinon le premier actif).
  useEffect(() => {
    if (!charges || (valeur.contact_id !== undefined && valeur.adresse_livraison_id !== undefined)) return;
    const contact = charges.contacts.filter(c => c.actif).sort((a, b) => Number(b.principal) - Number(a.principal))[0];
    const adresse = charges.adresses.filter(a => a.actif && a.livraison).sort((a, b) => Number(b.principale) - Number(a.principale))[0];
    onChange({
      ...valeur,
      contact_id: valeur.contact_id !== undefined ? valeur.contact_id : contact?.id ?? null,
      adresse_livraison_id: valeur.adresse_livraison_id !== undefined ? valeur.adresse_livraison_id : adresse?.id ?? null,
    });
  }, [charges, valeur, onChange]);

  if (!clientId || !charges || (contacts.length === 0 && adresses.length === 0)) return null;

  const choisie = adresses.find(a => a.id === valeur.adresse_livraison_id);
  const copie = valeur.copie?.adresse_livraison_id && valeur.copie.adresse_livraison_id === valeur.adresse_livraison_id ? valeur.copie : null;
  const instructions = copie ? copie.instructions_livraison : choisie?.instructions;
  const nombre = (texte: string) => (texte ? Number(texte) : null);

  return (
    <>
      {contacts.length > 0 && (
        <div className={className}>
          <label htmlFor={`${idPrefixe}-contact`} className="block text-sm font-medium mb-1 dark:text-gray-300">{tr.contact}</label>
          <select id={`${idPrefixe}-contact`} value={valeur.contact_id ?? ''} className={champ}
            onChange={e => onChange({ ...valeur, contact_id: nombre(e.target.value) })}>
            <option value="">{tr.noContact}</option>
            {contacts.map(c => (
              <option key={c.id} value={c.id}>{[c.nom, c.fonction].filter(Boolean).join(' — ')}{c.actif ? '' : ` (${tr.inactive})`}</option>
            ))}
          </select>
        </div>
      )}
      {adresses.length > 0 && (
        <div className={className}>
          <label htmlFor={`${idPrefixe}-livrer-a`} className="block text-sm font-medium mb-1 dark:text-gray-300">{tr.deliverTo}</label>
          <select id={`${idPrefixe}-livrer-a`} value={valeur.adresse_livraison_id ?? ''} className={champ}
            onChange={e => onChange({ ...valeur, adresse_livraison_id: nombre(e.target.value) })}>
            <option value="">{tr.clientFileAddress}</option>
            {adresses.map(a => (
              <option key={a.id} value={a.id}>{[a.libelle, [a.adresse, a.ville].filter(Boolean).join(', ')].join(' — ')}{a.actif ? '' : ` (${tr.inactive})`}</option>
            ))}
          </select>
          <p className="text-xs text-gray-500 mt-1">{instructions ? `${tr.instructions} : ${instructions}` : tr.hint}</p>
        </div>
      )}
    </>
  );
}

/** Contact, « Livrer à » et instructions dans le détail d'un document (cases d'une grille). */
export function DetailLivraison({ document }: { document: LivraisonDocument }) {
  const { t } = useLanguage();
  const tr = t.deliveryContact;

  return (
    <>
      {document.contact && (
        <div>
          <span className="text-sm text-gray-500">{tr.contact} :</span>
          <p className="dark:text-white">
            {document.contact.nom}
            {(document.contact.telephone || document.contact.email) && (
              <span className="block text-xs text-gray-500">{[document.contact.telephone, document.contact.email].filter(Boolean).join(' · ')}</span>
            )}
          </p>
        </div>
      )}
      {document.livraison_adresse && (
        <div>
          <span className="text-sm text-gray-500">{tr.deliverTo} :</span>
          <p className="dark:text-white whitespace-pre-line">{document.livraison_adresse}</p>
        </div>
      )}
      {document.instructions_livraison && (
        <div className="col-span-2">
          <span className="text-sm text-gray-500">{tr.instructions} :</span>
          <p className="dark:text-white whitespace-pre-line">{document.instructions_livraison}</p>
        </div>
      )}
    </>
  );
}
