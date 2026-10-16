import { useState } from 'react';
import { Building2, KeyRound, Mail, MapPin, Phone, UserRound } from 'lucide-react';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import { messageErreur } from '@/lib/erreurs';
import { usePortail } from '../contexte';
import { Chargement, Message } from '../composants';
import { remplir } from '../format';

const classeChamp = 'w-full mt-1 px-3 py-2 border border-gray-300 rounded-lg bg-white text-sm text-gray-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-50 disabled:text-gray-500';
type Retour = { type: 'succes' | 'erreur'; texte: string } | null;

/** Profil : nom et téléphone du contact, mot de passe, coordonnées du client (gérées par l'entreprise). */
export default function ProfilPortail() {
  const { t } = useLanguage();
  const tp = t.portal;
  const { infos, appel, moi, rechargerMoi } = usePortail();
  const [profil, setProfil] = useState<{ nom: string; telephone: string } | null>(null);
  const [retourProfil, setRetourProfil] = useState<Retour>(null);
  const [motsDePasse, setMotsDePasse] = useState({ mot_de_passe_actuel: '', password: '', password_confirmation: '' });
  const [retourMotDePasse, setRetourMotDePasse] = useState<Retour>(null);
  const [occupe, setOccupe] = useState<'profil' | 'mot-de-passe' | null>(null);

  if (!moi) return <Chargement />;
  const valeurs = profil ?? { nom: moi.compte.nom, telephone: moi.compte.telephone ?? '' };

  async function enregistrerProfil(e: React.FormEvent) {
    e.preventDefault();
    setRetourProfil(null);
    setOccupe('profil');
    try {
      await appel('/espace-client/moi', { methode: 'PUT', corps: { nom: valeurs.nom.trim(), telephone: valeurs.telephone.trim() || null } });
      setRetourProfil({ type: 'succes', texte: tp.saved });
      rechargerMoi();
    } catch (err) {
      setRetourProfil({ type: 'erreur', texte: messageErreur(err, tp.error) });
    } finally {
      setOccupe(null);
    }
  }

  async function changerMotDePasse(e: React.FormEvent) {
    e.preventDefault();
    setRetourMotDePasse(null);
    if (motsDePasse.password !== motsDePasse.password_confirmation) {
      setRetourMotDePasse({ type: 'erreur', texte: tp.passwordMismatch });
      return;
    }
    setOccupe('mot-de-passe');
    try {
      await appel('/espace-client/moi/mot-de-passe', { methode: 'PUT', corps: motsDePasse });
      setRetourMotDePasse({ type: 'succes', texte: tp.passwordChanged });
      setMotsDePasse({ mot_de_passe_actuel: '', password: '', password_confirmation: '' });
    } catch (err) {
      const erreurs = err instanceof ApiError ? (err.data as { errors?: Record<string, string[]> } | null)?.errors : undefined;
      setRetourMotDePasse({ type: 'erreur', texte: erreurs?.mot_de_passe_actuel ? tp.wrongCurrentPassword : messageErreur(err, tp.error) });
    } finally {
      setOccupe(null);
    }
  }

  const { client } = moi;
  const carte = 'bg-white rounded-xl border border-gray-200 p-5';
  const titre = 'font-semibold flex items-center gap-2 mb-4';

  return (
    <div>
      <h1 className="text-2xl font-bold mb-5">{tp.profileTitle}</h1>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">
        <div className="space-y-5">
          <section className={carte}>
            <h2 className={titre}><UserRound size={18} className="text-blue-600" /> {tp.myAccount}</h2>
            <form onSubmit={enregistrerProfil} className="space-y-3">
              <label className="block text-sm text-gray-700">{tp.name}
                <input required maxLength={150} value={valeurs.nom} onChange={e => setProfil({ ...valeurs, nom: e.target.value })} className={classeChamp} />
              </label>
              <label className="block text-sm text-gray-700">{tp.email}
                <input value={moi.compte.email} disabled className={classeChamp} />
                <span className="block mt-1 text-xs text-gray-500">{remplir(tp.emailHelp, { entreprise: infos.entreprise })}</span>
              </label>
              <label className="block text-sm text-gray-700">{tp.phone}
                <input type="tel" maxLength={50} value={valeurs.telephone} onChange={e => setProfil({ ...valeurs, telephone: e.target.value })} className={classeChamp} />
              </label>
              {retourProfil && <Message type={retourProfil.type}>{retourProfil.texte}</Message>}
              <button type="submit" disabled={occupe !== null} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50">{tp.save}</button>
            </form>
          </section>

          <section className={carte}>
            <h2 className={titre}><KeyRound size={18} className="text-blue-600" /> {tp.changePassword}</h2>
            <form onSubmit={changerMotDePasse} className="space-y-3">
              <label className="block text-sm text-gray-700">{tp.currentPassword}
                <input type="password" required autoComplete="current-password" value={motsDePasse.mot_de_passe_actuel}
                  onChange={e => setMotsDePasse({ ...motsDePasse, mot_de_passe_actuel: e.target.value })} className={classeChamp} />
              </label>
              <label className="block text-sm text-gray-700">{tp.newPassword}
                <input type="password" required minLength={8} autoComplete="new-password" value={motsDePasse.password}
                  onChange={e => setMotsDePasse({ ...motsDePasse, password: e.target.value })} className={classeChamp} />
              </label>
              <label className="block text-sm text-gray-700">{tp.confirmPassword}
                <input type="password" required minLength={8} autoComplete="new-password" value={motsDePasse.password_confirmation}
                  onChange={e => setMotsDePasse({ ...motsDePasse, password_confirmation: e.target.value })} className={classeChamp} />
              </label>
              {retourMotDePasse && <Message type={retourMotDePasse.type}>{retourMotDePasse.texte}</Message>}
              <button type="submit" disabled={occupe !== null} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50">{tp.changePassword}</button>
            </form>
          </section>
        </div>

        <div className="space-y-5">
          <section className={carte}>
            <h2 className={titre}><Building2 size={18} className="text-blue-600" /> {tp.company}</h2>
            <p className="font-medium">{client.raison_sociale}</p>
            <p className="text-xs text-gray-500">{remplir(tp.customerCode, { code: client.code })}</p>
            {(client.email || client.telephone) && (
              <div className="mt-2 text-sm text-gray-700 space-y-0.5">
                {client.email && <p className="flex items-center gap-1.5"><Mail size={14} className="text-gray-400" /> {client.email}</p>}
                {client.telephone && <p className="flex items-center gap-1.5"><Phone size={14} className="text-gray-400" /> {client.telephone}</p>}
              </div>
            )}
            {client.adresse_facturation && (
              <div className="mt-4">
                <p className="text-xs uppercase text-gray-500 mb-1">{tp.billingAddress}</p>
                <p className="text-sm text-gray-700">{client.adresse_facturation}<br />{[client.code_postal, client.ville].filter(Boolean).join(' ')}</p>
              </div>
            )}
            <div className="mt-4">
              <p className="text-xs uppercase text-gray-500 mb-1">{tp.addresses}</p>
              {client.adresses.length === 0 ? (
                <p className="text-sm text-gray-500">{tp.noAddress}</p>
              ) : (
                <ul className="space-y-2">
                  {client.adresses.map(a => (
                    <li key={a.id} className="text-sm text-gray-700 flex gap-2">
                      <MapPin size={14} className="text-gray-400 shrink-0 mt-0.5" />
                      <span>
                        {a.libelle && <span className="font-medium">{a.libelle} — </span>}
                        {a.adresse}, {[a.code_postal, a.ville].filter(Boolean).join(' ')}{a.pays ? `, ${a.pays}` : ''}
                        {a.principale && <span className="ml-2 px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 text-xs">{tp.main}</span>}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
              <p className="mt-3 text-xs text-gray-500">{remplir(tp.addressesHelp, { entreprise: infos.entreprise })}</p>
            </div>
          </section>

          {(infos.telephone || infos.email) && (
            <section className={carte}>
              <h2 className={titre}><Phone size={18} className="text-blue-600" /> {remplir(tp.supplierContact, { entreprise: infos.entreprise })}</h2>
              <div className="text-sm text-gray-700 space-y-1">
                {infos.telephone && <p><a href={`tel:${infos.telephone.replace(/\s/g, '')}`} className="text-blue-600 hover:underline">{infos.telephone}</a></p>}
                {infos.email && <p><a href={`mailto:${infos.email}`} className="text-blue-600 hover:underline">{infos.email}</a></p>}
              </div>
            </section>
          )}
        </div>
      </div>
    </div>
  );
}
