import { useEffect, useState } from 'react';
import { getParametres, getReferentiel } from '@/services/api';
import { getAuthHeaders } from '@/services/http';

/** Transporteur du référentiel, tel que proposé dans les fiches et les documents. */
export interface Transporteur {
  id: number;
  nom: string;
  /** Lien de suivi des colis, où {numero} est remplacé par le numéro de suivi. */
  url_suivi: string | null;
  actif: boolean;
}

/** Une liste chargée sert à tous les champs d'un écran pendant une minute (une seule requête), pour la même connexion. */
const DUREE_CACHE_MS = 60_000;
let cache: { connexion: string; expire: number; liste: Promise<Transporteur[]> } | null = null;

function charger(): Promise<Transporteur[]> {
  const connexion = getAuthHeaders().Authorization ?? '';
  if (!cache || cache.connexion !== connexion || cache.expire < Date.now()) {
    const liste = getReferentiel('transporteurs').then(fiches => fiches.map(f => ({
      id: f.id,
      nom: String(f.nom ?? ''),
      url_suivi: f.url_suivi ? String(f.url_suivi) : null,
      actif: Boolean(f.actif),
    })));
    const entree = { connexion, expire: Date.now() + DUREE_CACHE_MS, liste };
    cache = entree;
    // Un échec n'est pas gardé : le prochain écran redemande la liste.
    liste.catch(() => { if (cache === entree) cache = null; });
  }
  return cache.liste;
}

/**
 * Transporteurs (phase 1.11b), actifs et désactivés : une fiche ou un document garde le sien même désactivé, et son
 * nom s'affiche ; les listes de choix ne proposent que les actifs (voir ChoixTransporteur).
 */
export function useTransporteurs(): Transporteur[] {
  const [transporteurs, setTransporteurs] = useState<Transporteur[]>([]);

  useEffect(() => {
    let actif = true;
    charger().then(liste => { if (actif) setTransporteurs(liste); }, () => {});
    return () => { actif = false; };
  }, []);

  return transporteurs;
}

/** Option « transporteur obligatoire » de l'entreprise, lue une fois par minute et par connexion. */
let cacheObligatoire: { connexion: string; expire: number; valeur: Promise<boolean> } | null = null;

function chargerObligatoire(): Promise<boolean> {
  const connexion = getAuthHeaders().Authorization ?? '';
  if (!cacheObligatoire || cacheObligatoire.connexion !== connexion || cacheObligatoire.expire < Date.now()) {
    const valeur = getParametres().then(p => Boolean(p.transporteur_obligatoire));
    const entree = { connexion, expire: Date.now() + DUREE_CACHE_MS, valeur };
    cacheObligatoire = entree;
    valeur.catch(() => { if (cacheObligatoire === entree) cacheObligatoire = null; });
  }
  return cacheObligatoire.valeur;
}

/** L'option vient de changer : les écrans la relisent. */
export function oublierTransporteurObligatoire(): void {
  cacheObligatoire = null;
}

/**
 * Transporteur exigé sur un document saisi : l'option de l'entreprise est active et elle a un transporteur actif
 * (comme l'API, qui n'exige rien tant qu'il n'y a rien à choisir).
 */
export function useTransporteurRequis(transporteurs: Transporteur[]): boolean {
  const [obligatoire, setObligatoire] = useState(false);

  useEffect(() => {
    let actif = true;
    chargerObligatoire().then(v => { if (actif) setObligatoire(v); }, () => {});
    return () => { actif = false; };
  }, []);

  return obligatoire && transporteursActifs(transporteurs);
}

/** Vrai quand l'entreprise a au moins un transporteur actif : sinon les champs « Transporteur » restent masqués. */
export function transporteursActifs(transporteurs: Transporteur[]): boolean {
  return transporteurs.some(t => t.actif);
}

/** Lien de suivi d'un envoi : le lien du transporteur, {numero} remplacé par le numéro de suivi ; null s'il manque l'un des deux. */
export function lienSuivi(urlSuivi: string | null | undefined, numero: string | null | undefined): string | null {
  if (!urlSuivi || !numero) return null;
  return urlSuivi.includes('{numero}') ? urlSuivi.split('{numero}').join(encodeURIComponent(numero)) : urlSuivi;
}
