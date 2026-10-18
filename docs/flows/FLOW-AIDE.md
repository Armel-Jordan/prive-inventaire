# Flow — Aide contextuelle

> Plan de parité fripv2, phase 6.6 (« Aide », menu Informations : arborescence du menu, rechercher, documentation,
> à propos).

Chaque écran a son aide, en français et en anglais, dans la langue de l'écran. L'aide décrit à quoi sert l'écran,
comment faire, et ce qu'il est bon de savoir. Une carte des écrans réunit tous les écrans ouverts au compte, avec leur
résumé ; la recherche globale trouve aussi les écrans.

## Panneau d'aide

- **Ouvrir** : bouton « ? » de l'en-tête, ou touche **F1**, depuis n'importe quel écran. F1 ou « ? » le referment ;
  **Échap** aussi.
- Le panneau s'ouvre à droite, sans bloquer l'écran : on peut travailler en le lisant. Il suit l'écran affiché. Il
  passe au-dessus des fenêtres (création, modification) pour aider pendant la saisie.
- Contenu :
  - **À quoi sert cet écran** : une ou deux phrases ;
  - **Comment faire** : les étapes de la tâche principale ;
  - **Bon à savoir** : règles, points d'attention, liens avec les autres écrans.
- Pied du panneau :
  - lien vers la carte des écrans ;
  - **Écrire au support**, si le compte peut écrire à l'éditeur ; la demande retient l'écran.
- Un écran sans aide détaillée l'indique, avec le lien du support.
- Les fiches ouvertes par leur adresse ont leur propre aide : fiche client, fiche fournisseur, prise d'inventaire,
  accès d'un compte.

## Carte des écrans (`/aide`)

- Ouverte depuis le panneau d'aide (« Toute l'aide : carte des écrans ») ou par la recherche globale.
- Les écrans **ouverts au compte**, par menu, comme le menu latéral : modules souscrits par l'entreprise, puis droits
  du rôle. S'y ajoutent les écrans généraux : tableau de bord, notifications, support, sécurité du compte, profil.
- Chaque écran :
  - son nom, lien vers l'écran ;
  - le résumé de son aide ;
  - un bouton « ? » qui ouvre son aide complète dans le panneau.
- **Chercher un écran ou une tâche** : par le nom de l'écran, les mots de son résumé ou des mots-clés (« avoir »,
  « remboursement »…). Les accents et les majuscules ne comptent pas ; tous les mots doivent se trouver.
- **Raccourcis clavier** :
  - F1 : aide de l'écran ;
  - Ctrl + K : recherche globale ;
  - Échap : fermer.
- **À propos** :
  - le nom de l'application (réglable, voir `VITE_APP_NAME`) ;
  - la version (le commit construit) et la date de construction ;
  - les **nouveautés**, les plus récentes d'abord, avec le lien de chaque écran.

## Recherche globale (Ctrl + K)

- Dès 2 caractères, un groupe **Écrans** vient en tête des résultats. Il contient au plus 6 écrans ouverts au compte
  qui répondent à la recherche : nom, résumé de l'aide ou mots-clés.
- Un écran s'ouvre directement. Les fiches et documents gardent leur aperçu.
- Le clavier passe des écrans aux fiches (flèches, Entrée).

## Contenu

- L'aide fait partie de l'application. Elle n'est chargée qu'à la première ouverture du panneau, de la carte ou de la
  recherche.
- Une aide par écran, par adresse (`/clients`, `/clients/:id`…), en français et en anglais. Fichiers
  `src/aide/ecrans/*.ts` du back-office, un par menu (Inventaire, Achats, Ventes, Finance, Paramètres et écrans
  généraux).
- Les nouveautés sont dans `src/aide/nouveautes.ts`.
- Le menu (catégories, écrans, droits) est décrit une seule fois (`src/navigation.ts`). Le menu latéral, la carte des
  écrans et la recherche le partagent.

Aucun droit nouveau : l'aide est ouverte à tous les comptes, et la carte ne montre que les écrans permis.
