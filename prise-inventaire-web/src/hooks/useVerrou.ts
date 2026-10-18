import { useCallback, useEffect, useRef, useState } from 'react';
import { messageErreur } from '@/lib/erreurs';
import { leverVerrou, poserVerrou, RENOUVELLEMENT_MS, verrouDuRefus, type EntiteVerrouillable, type InfoVerrou } from '@/services/verrous';

/**
 * Verrou de la fiche ouverte en modification (phase 6.3) :
 * - « attente » : pas de fiche, ou réponse attendue ;
 * - « pose » : le compte tient la fiche (verrou renouvelé chaque minute, et au retour sur l'onglet) ;
 * - « occupe » : un autre compte la modifie, l'enregistrement serait refusé ;
 * - « perdu » : la fiche a été libérée par un responsable, ou modifiée entre-temps par un autre compte ;
 * - « indisponible » : le verrou n'a pas pu être posé (réseau…), l'écran réessaie ; le serveur contrôle encore
 *   l'enregistrement.
 * Le verrou est levé à la fermeture de la fenêtre, et quand la page se ferme.
 */
export type EtatVerrou = 'attente' | 'pose' | 'occupe' | 'perdu' | 'indisponible';

interface Suivi {
  cle: string;
  etat: EtatVerrou;
  verrou: InfoVerrou | null;
  erreur: string | null;
}

export interface VerrouFiche {
  etat: EtatVerrou;
  verrou: InfoVerrou | null;
  /** Échec d'une prise de main. */
  erreur: string | null;
  /** Vrai quand l'enregistrement serait refusé : le bouton d'enregistrement se grise. */
  bloque: boolean;
  /** Retente de poser le verrou (l'autre compte a peut-être fermé la fiche). */
  reessayer: () => void;
  /** Prend la main sur la fiche qu'un autre compte modifie (droit de libérer). */
  prendreLaMain: () => Promise<void>;
}

export function useVerrou(entite: EntiteVerrouillable, id: number | null | undefined): VerrouFiche {
  const cle = id ? `${entite}:${id}` : '';
  const [suivi, setSuivi] = useState<Suivi>({ cle: '', etat: 'attente', verrou: null, erreur: null });
  const [tentative, setTentative] = useState(0);
  // Dernière demande de verrou de l'écran : une réponse arrivée après la fermeture sait si la fiche est encore voulue.
  const derniere = useRef({ numero: 0, cle: '' });
  // Une autre fiche (ou aucune) : rien n'est encore connu de son verrou.
  const courant: Suivi = suivi.cle === cle ? suivi : { cle, etat: 'attente', verrou: null, erreur: null };

  useEffect(() => {
    if (!id) return;
    const cleFiche = `${entite}:${id}`;
    const numero = derniere.current.numero + 1;
    derniere.current = { numero, cle: cleFiche };
    let actif = true;
    // Le compte a tenu la fiche (les demandes suivantes renouvellent) ; il la tient encore ; un refus arrête tout.
    let tenue = false;
    let tient = false;
    let refusee = false;

    const appliquer = (etat: EtatVerrou, verrou: InfoVerrou | null) => {
      if (actif) setSuivi({ cle: cleFiche, etat, verrou, erreur: null });
    };
    const tenter = () => {
      if (!actif || refusee) return;
      poserVerrou(entite, id, { renouveler: tenue }).then(
        verrou => {
          if (!actif) {
            // Fenêtre fermée avant la réponse : le verrou est levé, sauf si l'écran redemande déjà la même fiche.
            const suite = derniere.current;
            if (suite.cle !== cleFiche || suite.numero === numero) leverVerrou(entite, id).catch(() => {});
            return;
          }
          tenue = true;
          tient = true;
          appliquer('pose', verrou);
        },
        erreur => {
          const verrou = verrouDuRefus(erreur);
          if (verrou) {
            // Un autre compte tient la fiche, ou le verrou a été perdu : l'écran cesse de le demander.
            refusee = true;
            tient = false;
            appliquer(verrou.actif ? 'occupe' : 'perdu', verrou);
          } else if (!tenue) {
            appliquer('indisponible', null);
          }
          // Un renouvellement sans réponse (réseau) est simplement retenté à la minute suivante.
        },
      );
    };
    const auRetour = () => {
      if (document.visibilityState === 'visible') tenter();
    };
    // Page restaurée depuis le cache du navigateur : le verrou levé à son départ est repris.
    const auRetourDePage = (evenement: PageTransitionEvent) => {
      if (evenement.persisted) tenter();
    };
    const lever = () => {
      if (!tient) return;
      tient = false;
      leverVerrou(entite, id).catch(() => {
        // Le verrou tombera seul dans trois minutes.
      });
    };

    tenter();
    const minuterie = window.setInterval(tenter, RENOUVELLEMENT_MS);
    document.addEventListener('visibilitychange', auRetour);
    window.addEventListener('pageshow', auRetourDePage);
    window.addEventListener('pagehide', lever);

    return () => {
      // Fenêtre fermée, autre fiche ou nouvelle tentative : la réponse en attente ne compte plus, le verrou est levé.
      actif = false;
      window.clearInterval(minuterie);
      document.removeEventListener('visibilitychange', auRetour);
      window.removeEventListener('pageshow', auRetourDePage);
      window.removeEventListener('pagehide', lever);
      lever();
    };
  }, [entite, id, tentative]);

  const reessayer = useCallback(() => setTentative(n => n + 1), []);

  const prendreLaMain = useCallback(async () => {
    if (!id) return;
    try {
      await poserVerrou(entite, id, { prendreLaMain: true });
      // Le verrou est au compte : l'écran le reprend et le renouvelle.
      setTentative(n => n + 1);
    } catch (erreur) {
      setSuivi(s => (s.cle === `${entite}:${id}` ? { ...s, erreur: messageErreur(erreur, '') } : s));
    }
  }, [entite, id]);

  return {
    etat: courant.etat,
    verrou: courant.verrou,
    erreur: courant.erreur,
    bloque: courant.etat === 'occupe' || courant.etat === 'perdu',
    reessayer,
    prendreLaMain,
  };
}
