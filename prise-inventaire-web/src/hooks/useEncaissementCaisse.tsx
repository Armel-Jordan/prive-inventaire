import { useCallback, useState, type ReactNode } from 'react';
import { usePermissions } from '@/contexts/PermissionsContext';
import { useCaissePoste, type CaissePoste } from '@/hooks/useCaissePoste';
import { caisseFermee } from '@/services/caisses';
import OuvrirAvantEncaissement from '@/components/caisse/OuvrirAvantEncaissement';

interface Attente {
  reprendre: () => Promise<unknown>;
  resoudre: (valeur: unknown) => void;
  rejeter: (erreur: unknown) => void;
  erreur: unknown;
}

/**
 * Encaissement sur un poste de caisse : l'appel reçoit la caisse du poste. Caisse fermée, une personne qui peut gérer
 * les sessions l'ouvre sur-le-champ (fond initial) et l'encaissement reprend ; sinon le message de l'API demande de la
 * faire ouvrir. Le poste qui n'est pas une caisse encaisse comme avant.
 */
export function useEncaissementCaisse(): {
  caisse: CaissePoste | null;
  encaisser: <T>(appel: (caisseId: number | undefined) => Promise<T>) => Promise<T>;
  dialogue: ReactNode;
} {
  const caisse = useCaissePoste();
  const { peut } = usePermissions();
  const [attente, setAttente] = useState<Attente | null>(null);

  const encaisser = useCallback(<T,>(appel: (caisseId: number | undefined) => Promise<T>): Promise<T> => {
    const caisseId = caisse?.id;
    return appel(caisseId).catch((erreur: unknown) => {
      if (!caisseId || !caisseFermee(erreur) || !peut('caisses', 'gerer')) throw erreur;
      return new Promise<T>((resoudre, rejeter) => setAttente({
        reprendre: () => appel(caisseId), resoudre: valeur => resoudre(valeur as T), rejeter, erreur,
      }));
    });
  }, [caisse, peut]);

  const dialogue = attente && caisse ? (
    <OuvrirAvantEncaissement
      caisse={caisse}
      onAnnuler={() => { attente.rejeter(attente.erreur); setAttente(null); }}
      onOuverte={async () => {
        try {
          attente.resoudre(await attente.reprendre());
        } catch (erreur) {
          attente.rejeter(erreur);
        } finally {
          setAttente(null);
        }
      }}
    />
  ) : null;

  return { caisse, encaisser, dialogue };
}
