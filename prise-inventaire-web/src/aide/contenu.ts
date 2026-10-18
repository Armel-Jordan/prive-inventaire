import type { AidesEcrans } from './types';
import { AIDES_INVENTAIRE } from './ecrans/inventaire';
import { AIDES_ACHATS } from './ecrans/achats';
import { AIDES_VENTES } from './ecrans/ventes';
import { AIDES_VENTES_SUITE } from './ecrans/ventes-suite';
import { AIDES_FINANCE } from './ecrans/finance';
import { AIDES_PARAMETRES } from './ecrans/parametres';

/** Aide de la carte des écrans elle-même. */
const AIDE_CARTE: AidesEcrans = {
  '/aide': {
    fr: {
      resume: "La carte de tous les écrans ouverts à votre compte, par menu, avec le résumé de leur aide, les raccourcis clavier, la version de l'application et ses nouveautés.",
      etapes: [
        "Tapez dans « Chercher un écran ou une tâche » le nom d'un écran ou ce que vous voulez faire (ex. : « remboursement »).",
        "Cliquez sur le nom d'un écran pour l'ouvrir, ou sur « ? » pour lire son aide complète sans quitter la carte.",
        "Sur n'importe quel écran, appuyez sur F1 ou cliquez sur « ? » dans l'en-tête pour ouvrir son aide.",
      ],
      astuces: [
        "La carte ne montre que les écrans de vos droits et des modules de votre entreprise : pour un écran absent, demandez l'accès à un administrateur.",
        "La recherche globale (Ctrl + K) trouve aussi les écrans, par leur nom ou par ce qu'on veut y faire.",
      ],
      motsCles: ['carte des écrans', 'menu', 'nouveautés', 'version', 'à propos', 'raccourcis'],
    },
    en: {
      resume: "The map of every screen your account can open, by menu, with the summary of its help, the keyboard shortcuts, the application's version and what's new.",
      etapes: [
        'Type in “Search a screen or a task” the name of a screen or what you want to do (e.g. “refund”).',
        'Click the name of a screen to open it, or “?” to read its full help without leaving the map.',
        'On any screen, press F1 or click “?” in the header to open its help.',
      ],
      astuces: [
        'The map only shows the screens of your permissions and of your company’s modules: for a missing screen, ask an administrator for access.',
        'The global search (Ctrl + K) also finds screens, by their name or by what you want to do there.',
      ],
      motsCles: ['screen map', 'menu', "what's new", 'version', 'about', 'shortcuts'],
    },
  },
};

/** Aide de tous les écrans (chargée à la demande : voir chargerAides). */
export const AIDES: AidesEcrans = {
  ...AIDES_PARAMETRES,
  ...AIDES_INVENTAIRE,
  ...AIDES_ACHATS,
  ...AIDES_VENTES,
  ...AIDES_VENTES_SUITE,
  ...AIDES_FINANCE,
  ...AIDE_CARTE,
};
