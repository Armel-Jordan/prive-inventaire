import { useCallback, useEffect, useMemo, useState } from 'react';
import { Navigate, Route, Routes, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Store } from 'lucide-react';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import LanguageSelector from '@/components/LanguageSelector';
import {
  appelPortail, ecrireDerniereEntreprise, ecrirePanier, ecrireSession, lireDerniereEntreprise, lirePanier, lireSession,
  type ArticlePanier, type InfosPortail, type MoiPortail, type OptionsAppel, type SessionPortail,
} from './api';
import { ContextePortail, type EtatPortail } from './contexte';
import { Chargement } from './composants';
import Cadre from './Cadre';
import ChoixEntreprise from './pages/ChoixEntreprise';
import ConnexionPortail from './pages/ConnexionPortail';
import ActivationPortail from './pages/ActivationPortail';
import CataloguePortail from './pages/CataloguePortail';
import PanierPortail from './pages/PanierPortail';
import CommandesPortail from './pages/CommandesPortail';
import CommandePortail from './pages/CommandePortail';
import FacturesPortail from './pages/FacturesPortail';
import ProfilPortail from './pages/ProfilPortail';

type Indisponible = 'introuvable' | 'ferme' | 'reseau';

/** Code d'entreprise : lettres, chiffres, tirets et soulignés (comme côté API). */
const CODE_VALIDE = /^[a-z0-9_-]{1,64}$/;
/** Adresses des écrans : jamais prises pour un code d'entreprise. */
const RESERVES = new Set(['connexion', 'activation', 'panier', 'commandes', 'factures', 'profil']);

/**
 * Espace client : le site des clients des entreprises, distinct du back-office. L'entreprise se désigne par
 * son code : dans le lien reçu (`/<code>`, `?entreprise=<code>`), saisi par le client, ou celui de la dernière
 * visite. Comptes et sessions propres à l'espace client, sans lien avec les comptes des employés.
 */
export default function EspaceClient() {
  const { t } = useLanguage();
  const tp = t.portal;
  const navigate = useNavigate();
  const [parametres] = useSearchParams();
  const [session, setSession] = useState<SessionPortail | null>(lireSession);
  const [derniere, setDerniere] = useState<string | null>(lireDerniereEntreprise);
  const codeAdresse = parametres.get('entreprise')?.trim().toLowerCase() || null;
  // L'adresse l'emporte (lien d'invitation d'une autre entreprise), puis la session, puis la dernière visite.
  const slug = [codeAdresse, session?.slug, derniere].find((code): code is string => !!code && CODE_VALIDE.test(code)) ?? null;

  const [infos, setInfos] = useState<{ slug: string; donnees: InfosPortail } | null>(null);
  const [indisponible, setIndisponible] = useState<{ slug: string; raison: Indisponible } | null>(null);
  const [essai, setEssai] = useState(0);
  const [panier, setPanier] = useState<ArticlePanier[]>(() => {
    const enCours = lireSession();
    return enCours ? lirePanier(enCours.slug, enCours.compte.id) : [];
  });
  const [moi, setMoi] = useState<MoiPortail | null>(null);
  const [erreurMoi, setErreurMoi] = useState(false);
  const [versionMoi, setVersionMoi] = useState(0);
  const [avis, setAvis] = useState('');

  // Une session ne vaut que pour son entreprise.
  const sessionCourante = session && session.slug === slug ? session : null;
  const jeton = sessionCourante?.token ?? null;

  useEffect(() => {
    if (!slug) return;
    let actif = true;
    appelPortail<InfosPortail>(`/portail/${encodeURIComponent(slug)}`).then(
      donnees => {
        if (!actif) return;
        setInfos({ slug, donnees });
        setIndisponible(null);
        // Entreprise reconnue : le client retrouvera sa page de connexion à la prochaine visite.
        setDerniere(slug);
        ecrireDerniereEntreprise(slug);
      },
      e => { if (actif) setIndisponible({ slug, raison: e instanceof ApiError && e.status === 404 ? 'introuvable' : 'reseau' }); },
    );
    return () => { actif = false; };
  }, [slug, essai]);

  const infosCourantes = infos?.slug === slug ? infos.donnees : null;
  const raisonIndisponible = indisponible?.slug === slug ? indisponible.raison : null;

  // Titre de l'onglet : le nom de l'entreprise.
  useEffect(() => {
    document.title = infosCourantes?.entreprise ?? tp.defaultTitle;
  }, [infosCourantes, tp.defaultTitle]);

  // Panier gardé par accès, dans ce navigateur.
  useEffect(() => {
    if (session) ecrirePanier(session.slug, session.compte.id, panier);
  }, [session, panier]);

  const expirer = useCallback((message: string) => {
    ecrireSession(null);
    setSession(null);
    setMoi(null);
    setAvis(message);
  }, []);

  // Session expirée (401) : retour à la connexion ; espace fermé entre-temps (403) : écran d'indisponibilité.
  const surErreur = useCallback((e: unknown) => {
    if (e instanceof ApiError && e.status === 401) expirer(tp.sessionExpired);
    if (e instanceof ApiError && e.status === 403 && (e.data as { portail_ferme?: boolean } | null)?.portail_ferme && slug) {
      setIndisponible({ slug, raison: 'ferme' });
    }
  }, [expirer, tp.sessionExpired, slug]);

  const appel = useCallback(async <T,>(chemin: string, options: Omit<OptionsAppel, 'jeton'> = {}): Promise<T> => {
    try {
      return await appelPortail<T>(chemin, { ...options, jeton });
    } catch (e) {
      surErreur(e);
      throw e;
    }
  }, [jeton, surErreur]);

  useEffect(() => {
    if (!jeton) return;
    let actif = true;
    appelPortail<MoiPortail>('/espace-client/moi', { jeton }).then(
      donnees => { if (actif) { setMoi(donnees); setErreurMoi(false); } },
      e => { if (actif) { surErreur(e); setErreurMoi(!(e instanceof ApiError && (e.status === 401 || e.status === 403))); } },
    );
    return () => { actif = false; };
  }, [jeton, versionMoi, surErreur]);

  const connecter = useCallback((nouvelle: SessionPortail) => {
    ecrireSession(nouvelle);
    setSession(nouvelle);
    setPanier(lirePanier(nouvelle.slug, nouvelle.compte.id));
    setMoi(null);
    setAvis('');
  }, []);

  const deconnecter = useCallback(async () => {
    if (session) {
      try {
        await appelPortail('/espace-client/deconnexion', { methode: 'POST', jeton: session.token });
      } catch {
        // Session déjà fermée côté serveur : rien à faire.
      }
      ecrirePanier(session.slug, session.compte.id, []);
    }
    ecrireSession(null);
    setSession(null);
    setMoi(null);
    setPanier([]);
    setAvis('');
  }, [session]);

  const changerEntreprise = useCallback(() => {
    ecrireDerniereEntreprise(null);
    setDerniere(null);
    navigate('/connexion');
  }, [navigate]);

  const ajouterAuPanier = useCallback((article: Omit<ArticlePanier, 'quantite'>, quantite: number) => {
    setPanier(actuel => actuel.some(a => a.produit_id === article.produit_id)
      ? actuel.map(a => (a.produit_id === article.produit_id ? { ...a, quantite: Math.min(a.quantite + quantite, 1000000) } : a))
      : [...actuel, { ...article, quantite }]);
  }, []);
  const changerQuantite = useCallback((produitId: number, quantite: number) => {
    setPanier(actuel => actuel.map(a => (a.produit_id === produitId ? { ...a, quantite } : a)));
  }, []);
  const retirerDuPanier = useCallback((produitId: number) => {
    setPanier(actuel => actuel.filter(a => a.produit_id !== produitId));
  }, []);
  const viderPanier = useCallback(() => setPanier([]), []);
  const rechargerMoi = useCallback(() => setVersionMoi(v => v + 1), []);

  const valeur = useMemo<EtatPortail | null>(() => (slug && infosCourantes ? {
    slug, infos: infosCourantes, session: sessionCourante, moi, panier, avis, erreurMoi,
    connecter, deconnecter, changerEntreprise, appel, rechargerMoi, ajouterAuPanier, changerQuantite, retirerDuPanier, viderPanier,
  } : null), [slug, infosCourantes, sessionCourante, moi, panier, avis, erreurMoi, connecter, deconnecter, changerEntreprise, appel, rechargerMoi, ajouterAuPanier, changerQuantite, retirerDuPanier, viderPanier]);

  const choisir = (code: string) => navigate(`/connexion?entreprise=${encodeURIComponent(code)}`);

  // Aucune entreprise connue : le client saisit le code de son fournisseur (ou suit le lien reçu).
  if (!slug) {
    return (
      <Routes>
        <Route path="/:code" element={<VersEntreprise session={session} />} />
        <Route path="*" element={<ChoixEntreprise onChoisir={choisir} />} />
      </Routes>
    );
  }
  if (raisonIndisponible) {
    return (
      <EspaceIndisponible
        raison={raisonIndisponible}
        connecte={sessionCourante !== null}
        onReessayer={() => { setIndisponible(null); setEssai(n => n + 1); }}
        onChanger={changerEntreprise}
        onDeconnecter={() => { void deconnecter().then(changerEntreprise); }}
      />
    );
  }
  if (!valeur) {
    return <div className="min-h-screen bg-gray-50"><Chargement /></div>;
  }

  return (
    <ContextePortail.Provider value={valeur}>
      <Routes>
        <Route path="/connexion" element={sessionCourante ? <Navigate to="/" replace /> : <ConnexionPortail />} />
        <Route path="/activation" element={<ActivationPortail />} />
        <Route element={sessionCourante ? <Cadre /> : <Navigate to={`/connexion?entreprise=${slug}`} replace />}>
          <Route index element={<CataloguePortail />} />
          <Route path="panier" element={<PanierPortail />} />
          <Route path="commandes" element={<CommandesPortail />} />
          <Route path="commandes/:id" element={<CommandePortail />} />
          <Route path="factures" element={<FacturesPortail />} />
          <Route path="profil" element={<ProfilPortail />} />
        </Route>
        <Route path="/:code" element={<VersEntreprise session={session} />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </ContextePortail.Provider>
  );
}

/** Lien d'une entreprise (`/<code>`) : ouvre sa page de connexion, ou l'espace déjà ouvert. */
function VersEntreprise({ session }: { session: SessionPortail | null }) {
  const { code = '' } = useParams();
  const entreprise = code.toLowerCase();
  if (!CODE_VALIDE.test(entreprise) || RESERVES.has(entreprise)) {
    return <Navigate to="/" replace />;
  }
  return <Navigate to={session?.slug === entreprise ? '/' : `/connexion?entreprise=${encodeURIComponent(entreprise)}`} replace />;
}

function EspaceIndisponible({ raison, connecte, onReessayer, onChanger, onDeconnecter }: {
  raison: Indisponible;
  connecte: boolean;
  onReessayer: () => void;
  onChanger: () => void;
  onDeconnecter: () => void;
}) {
  const { t } = useLanguage();
  const tp = t.portal;
  const texte = { introuvable: tp.notFound, ferme: tp.closed, reseau: tp.offline }[raison];
  const secondaire = 'px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50';

  return (
    <div className="min-h-screen bg-gray-50 flex flex-col">
      <div className="flex justify-end p-3"><LanguageSelector /></div>
      <div className="flex-1 flex items-center justify-center p-4">
        <div className="bg-white rounded-2xl shadow-sm border border-gray-200 w-full max-w-md p-8 text-center">
          <div className="inline-flex items-center justify-center w-14 h-14 bg-gray-100 rounded-full mb-4">
            <Store className="w-7 h-7 text-gray-500" />
          </div>
          <h1 className="text-xl font-semibold text-gray-900">{tp.unavailableTitle}</h1>
          <p className="text-sm text-gray-600 mt-2">{texte}</p>
          <div className="mt-6 flex flex-wrap justify-center gap-2">
            {raison !== 'introuvable' && (
              <button type="button" onClick={onReessayer} className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">{tp.retry}</button>
            )}
            {connecte
              ? <button type="button" onClick={onDeconnecter} className={secondaire}>{tp.logout}</button>
              : <button type="button" onClick={onChanger} className={secondaire}>{tp.changeCompany}</button>}
          </div>
        </div>
      </div>
    </div>
  );
}
