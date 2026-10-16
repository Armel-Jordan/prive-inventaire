import { useCallback, useEffect, useMemo, useState } from 'react';
import { Navigate, Route, Routes, useParams } from 'react-router-dom';
import { Store } from 'lucide-react';
import { ApiError } from '@/services/http';
import { useLanguage } from '@/i18n/useLanguage';
import LanguageSelector from '@/components/LanguageSelector';
import {
  appelPortail, ecrirePanier, ecrireSession, lirePanier, lireSession,
  type ArticlePanier, type InfosPortail, type MoiPortail, type OptionsAppel, type SessionPortail,
} from './api';
import { ContextePortail, type EtatPortail } from './contexte';
import { Chargement } from './composants';
import PortailLayout from './PortailLayout';
import ConnexionPortail from './pages/ConnexionPortail';
import ActivationPortail from './pages/ActivationPortail';
import CataloguePortail from './pages/CataloguePortail';
import PanierPortail from './pages/PanierPortail';
import CommandesPortail from './pages/CommandesPortail';
import CommandePortail from './pages/CommandePortail';
import FacturesPortail from './pages/FacturesPortail';
import ProfilPortail from './pages/ProfilPortail';

/**
 * Portail client d'une entreprise, à /portail/<code de l'entreprise> : ses clients commandent, suivent leurs
 * commandes et retrouvent leurs factures. Comptes et sessions propres au portail, sans lien avec le back-office.
 */
export default function PortailApp() {
  const { slug = '' } = useParams();
  const code = slug.toLowerCase();
  // Une autre entreprise : tout l'état repart de zéro.
  return <EspacePortail key={code} slug={code} />;
}

type Indisponible = 'introuvable' | 'ferme' | 'reseau';

function EspacePortail({ slug }: { slug: string }) {
  const { t } = useLanguage();
  const tp = t.portal;
  const [infos, setInfos] = useState<InfosPortail | null>(null);
  const [indisponible, setIndisponible] = useState<Indisponible | null>(null);
  const [essai, setEssai] = useState(0);
  const [session, setSession] = useState<SessionPortail | null>(() => lireSession(slug));
  const [panier, setPanier] = useState<ArticlePanier[]>(() => {
    const enCours = lireSession(slug);
    return enCours ? lirePanier(slug, enCours.compte.id) : [];
  });
  const [moi, setMoi] = useState<MoiPortail | null>(null);
  const [erreurMoi, setErreurMoi] = useState(false);
  const [versionMoi, setVersionMoi] = useState(0);
  const [avis, setAvis] = useState('');

  const jeton = session?.token ?? null;
  const compteId = session?.compte.id ?? null;

  useEffect(() => {
    let actif = true;
    appelPortail<InfosPortail>(`/portail/${encodeURIComponent(slug)}`).then(
      donnees => { if (actif) { setInfos(donnees); setIndisponible(null); } },
      e => { if (actif) setIndisponible(e instanceof ApiError && e.status === 404 ? 'introuvable' : 'reseau'); },
    );
    return () => { actif = false; };
  }, [slug, essai]);

  // Titre de l'onglet : le nom de l'entreprise.
  useEffect(() => {
    if (!infos) return;
    const precedent = document.title;
    document.title = infos.entreprise;
    return () => { document.title = precedent; };
  }, [infos]);

  // Panier gardé par compte, dans ce navigateur.
  useEffect(() => {
    if (compteId !== null) ecrirePanier(slug, compteId, panier);
  }, [slug, compteId, panier]);

  const expirer = useCallback((message: string) => {
    ecrireSession(slug, null);
    setSession(null);
    setMoi(null);
    setAvis(message);
  }, [slug]);

  // Session expirée (401) : retour à la connexion ; portail fermé entre-temps (403) : écran d'indisponibilité.
  const surErreur = useCallback((e: unknown) => {
    if (e instanceof ApiError && e.status === 401) expirer(tp.sessionExpired);
    if (e instanceof ApiError && e.status === 403 && (e.data as { portail_ferme?: boolean } | null)?.portail_ferme) setIndisponible('ferme');
  }, [expirer, tp.sessionExpired]);

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
    ecrireSession(slug, nouvelle);
    setSession(nouvelle);
    setPanier(lirePanier(slug, nouvelle.compte.id));
    setMoi(null);
    setAvis('');
  }, [slug]);

  const deconnecter = useCallback(async () => {
    try {
      await appelPortail('/espace-client/deconnexion', { methode: 'POST', jeton });
    } catch {
      // Session déjà fermée côté serveur : rien à faire.
    }
    if (compteId !== null) ecrirePanier(slug, compteId, []);
    ecrireSession(slug, null);
    setSession(null);
    setMoi(null);
    setPanier([]);
    setAvis('');
  }, [slug, jeton, compteId]);

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

  const base = `/portail/${slug}`;
  const valeur = useMemo<EtatPortail | null>(() => (infos ? {
    slug, base, infos, session, moi, panier, avis, erreurMoi,
    connecter, deconnecter, appel, rechargerMoi, ajouterAuPanier, changerQuantite, retirerDuPanier, viderPanier,
  } : null), [slug, base, infos, session, moi, panier, avis, erreurMoi, connecter, deconnecter, appel, rechargerMoi, ajouterAuPanier, changerQuantite, retirerDuPanier, viderPanier]);

  if (indisponible) {
    return <PortailIndisponible raison={indisponible} onReessayer={() => { setIndisponible(null); setEssai(n => n + 1); }} />;
  }
  if (!valeur) {
    return <div className="min-h-screen bg-gray-50"><Chargement /></div>;
  }

  return (
    <ContextePortail.Provider value={valeur}>
      <Routes>
        <Route path="connexion" element={session ? <Navigate to={base} replace /> : <ConnexionPortail />} />
        <Route path="activation" element={<ActivationPortail />} />
        <Route element={session ? <PortailLayout /> : <Navigate to={`${base}/connexion`} replace />}>
          <Route index element={<CataloguePortail />} />
          <Route path="panier" element={<PanierPortail />} />
          <Route path="commandes" element={<CommandesPortail />} />
          <Route path="commandes/:id" element={<CommandePortail />} />
          <Route path="factures" element={<FacturesPortail />} />
          <Route path="profil" element={<ProfilPortail />} />
        </Route>
        <Route path="*" element={<Navigate to={base} replace />} />
      </Routes>
    </ContextePortail.Provider>
  );
}

function PortailIndisponible({ raison, onReessayer }: { raison: Indisponible; onReessayer: () => void }) {
  const { t } = useLanguage();
  const tp = t.portal;
  const texte = { introuvable: tp.notFound, ferme: tp.closed, reseau: tp.offline }[raison];

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
          {raison !== 'introuvable' && (
            <button type="button" onClick={onReessayer} className="mt-6 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">
              {tp.retry}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
