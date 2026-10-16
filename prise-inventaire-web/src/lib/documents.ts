import { useEffect, useState } from 'react';
import { getParametres } from '@/services/api';
import { getAuthHeaders } from '@/services/http';

/**
 * Affichage des documents de vente et d'achat : montants dans la devise de l'entreprise (et non en euros
 * d'office), dates de document sans décalage de fuseau.
 */

const locale = (langue: string) => (langue === 'en' ? 'en-US' : 'fr-FR');

/** Montant (nombre ou chaîne de l'API, ex. "66.00") suivi du symbole de devise. */
export function formatMontant(valeur: number | string | null | undefined, devise: string, langue = 'fr'): string {
  const nombre = Number(valeur ?? 0);
  const texte = new Intl.NumberFormat(locale(langue), { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    .format(Number.isFinite(nombre) ? nombre : 0);
  return `${texte} ${devise}`.trim();
}

/**
 * Montant dans une devise désignée par son code ISO (« USD », « XAF ») : commandes fournisseur passées dans la
 * devise du fournisseur. Un code inconnu du navigateur est simplement écrit après le nombre.
 */
export function formatMontantIso(valeur: number | string | null | undefined, codeIso: string, langue = 'fr'): string {
  const nombre = Number(valeur ?? 0);
  const fini = Number.isFinite(nombre) ? nombre : 0;
  try {
    return new Intl.NumberFormat(locale(langue), { style: 'currency', currency: codeIso }).format(fini);
  } catch {
    return formatMontant(fini, codeIso, langue);
  }
}

/**
 * Date d'un document (« 2026-10-16 » ou « 2026-10-16T00:00:00.000000Z ») : seule la date compte. La lire
 * comme un instant UTC décalait d'un jour à l'ouest de Greenwich (16/10 affiché 15/10).
 */
export function formatDateDocument(valeur: string | null | undefined, langue = 'fr'): string {
  if (!valeur) return '—';
  const [annee, mois, jour] = valeur.slice(0, 10).split('-').map(Number);
  if (!annee || !mois || !jour) return valeur;
  return new Date(annee, mois - 1, jour).toLocaleDateString(locale(langue));
}

/** Instant (livraison effectuée, préparation…) : date et heure dans le fuseau de l'utilisateur. */
export function formatDateHeure(valeur: string | null | undefined, langue = 'fr'): string {
  if (!valeur) return '—';
  const instant = new Date(valeur);
  if (Number.isNaN(instant.getTime())) return valeur;
  return instant.toLocaleString(locale(langue), { dateStyle: 'short', timeStyle: 'short' });
}

/** Valeur d'un champ <input type="date"> à partir d'une date de l'API (vide si absente). */
export function dateSaisie(valeur: string | null | undefined): string {
  return valeur ? valeur.slice(0, 10) : '';
}

/** Date du jour au format AAAA-MM-JJ, dans le fuseau de l'utilisateur (et non en UTC). */
export function aujourdhui(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

let deviseEnCache: { connexion: string; devise: Promise<{ symbole: string; code: string }> } | null = null;

/** Devise des paramètres de l'entreprise (symbole et code ISO), chargée une fois par connexion. */
function useDeviseEntreprise(): { symbole: string; code: string } {
  const [devise, setDevise] = useState({ symbole: '', code: '' });
  useEffect(() => {
    // Après une déconnexion suivie d'une connexion à une autre entreprise, la devise de la précédente
    // n'est pas réutilisée.
    const connexion = getAuthHeaders().Authorization ?? '';
    if (deviseEnCache?.connexion !== connexion) {
      const entree = {
        connexion,
        devise: getParametres()
          .then(p => ({ symbole: p.devise_symbole || '', code: p.devise_code || '' }))
          .catch(() => {
            if (deviseEnCache === entree) deviseEnCache = null;
            return { symbole: '', code: '' };
          }),
      };
      deviseEnCache = entree;
    }
    let actif = true;
    deviseEnCache.devise.then(d => { if (actif) setDevise(d); });
    return () => { actif = false; };
  }, []);
  return devise;
}

/** Symbole de devise de l'entreprise (« FCFA », « $ »), pour afficher les montants. */
export function useDevise(): string {
  return useDeviseEntreprise().symbole;
}

/** Code ISO de la devise de l'entreprise (« XAF », « CAD »), valeur par défaut des devises à saisir. */
export function useCodeDevise(): string {
  return useDeviseEntreprise().code;
}
