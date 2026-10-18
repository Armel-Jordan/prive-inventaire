import { useCallback, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

/**
 * Texte de recherche d'une liste, pré-rempli par « ?recherche= » dans l'adresse : liens de la recherche globale, des
 * fiches en cours de modification et des autres écrans (« /devis?recherche=DEV-0012 »). Un nouveau lien vers la même
 * page reprend le texte de l'adresse ; ensuite, la saisie de l'utilisateur prime.
 */
export function useRechercheAdresse(): [string, (valeur: string) => void] {
  const [parametres] = useSearchParams();
  const parametre = parametres.get('recherche') ?? '';
  const [saisie, setSaisie] = useState({ parametre, valeur: parametre });
  const valeur = saisie.parametre === parametre ? saisie.valeur : parametre;
  const modifier = useCallback((nouvelle: string) => setSaisie({ parametre, valeur: nouvelle }), [parametre]);

  return [valeur, modifier];
}
