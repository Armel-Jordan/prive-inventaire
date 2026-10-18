# Flow — Fiches en cours de modification (verrous)

> Plan de parité fripv2, phase 6.3 (« Verrouillage des fiches »).

Quand un compte ouvre une fiche en modification, la fiche est **verrouillée** à son nom. Un autre compte qui l'ouvre
voit qui la modifie et depuis quand. Il ne peut ni l'enregistrer ni la supprimer tant qu'elle n'est pas fermée. Deux
personnes ne se marchent donc plus dessus : sans verrou, la dernière à enregistrer effaçait les changements de l'autre.

Fiches verrouillées :

| Fiche | Écran | Enregistrement et suppression refusés (API) |
|---|---|---|
| Client | Clients | `PUT` / `DELETE /clients/{id}` |
| Fournisseur | Fournisseurs | `PUT` / `DELETE /fournisseurs/{id}` |
| Produit | Produits | `PUT` / `DELETE /produits/{id}` |
| Devis | Devis | `PUT` / `DELETE /devis/{id}` |
| Commande client | Commandes clients | `PUT` / `DELETE /commandes-client/{id}` |
| Commande fournisseur | (pas de fenêtre de modification à l'écran) | `PUT` / `DELETE /commandes-fournisseur/{id}` |

Les listes attachées à une fiche (contacts, adresses, remarques, codes-barres, unités…) ne sont pas verrouillées :
plusieurs personnes peuvent y travailler en même temps.

## Dans la fenêtre de modification

- **À l'ouverture**, l'écran pose le verrou. Il le **renouvelle chaque minute**, et dès que l'onglet redevient visible.
- **À la fermeture** (enregistrement, Annuler, fermeture de la page), il le lève.
- **Fiche tenue par un autre compte** : bandeau orange « Paul Vendeur modifie cette fiche depuis 09:42 ». Le bouton
  d'enregistrement est grisé. Deux boutons :
  - **Réessayer** : l'autre compte a peut-être fermé la fiche ;
  - **Prendre la main**, avec le droit de libérer, après confirmation. Les changements que l'autre compte n'a pas encore
    enregistrés seront refusés.
- **Modification interrompue** : bandeau rouge, enregistrement grisé. Il faut fermer la fenêtre et rouvrir la fiche
  pour repartir de sa version enregistrée. Deux cas :
  - un responsable a libéré la fiche ;
  - un autre compte l'a modifiée entre-temps, par exemple pendant la mise en veille de l'ordinateur.
- **Navigateur fermé brutalement, réseau coupé** : le verrou n'est plus renouvelé et **tombe de lui-même au bout de
  trois minutes**.
- Le même compte peut ouvrir la même fiche dans deux onglets : fermer l'un ne fait pas perdre la main à l'autre.

Le serveur contrôle aussi l'enregistrement : une mise à jour ou une suppression d'un autre compte reçoit une réponse
409 avec le message « Cette fiche est en cours de modification par Paul Vendeur : réessayez quand la modification sera
terminée. » C'est vrai aussi pour un appel qui ne passe pas par l'écran.

## Fiches en cours de modification (Paramètres → `/fiches-en-cours`)

- La liste des fiches en cours de modification dans l'entreprise, les plus anciennes d'abord. Pour chacune :
  - le type et le libellé, avec le lien de son écran ;
  - qui la modifie, depuis quand.
- Seules apparaissent les fiches des modules que le compte peut consulter.
- **Libérer**, avec le droit de libérer, après confirmation. La fiche redevient libre. Le compte qui la modifiait
  l'apprend à son renouvellement suivant (dans la minute) : son bandeau dit qui a libéré la fiche, et ses changements
  non enregistrés sont refusés.
- La liste s'actualise toutes les 30 secondes.

## Droits (module « Fiches en cours de modification »)

| Droit | Permet | Au départ |
|---|---|---|
| Consulter | Ouvrir la liste des fiches en cours de modification | D'après le droit de voir la configuration |
| Prendre la main sur une fiche en cours de modification | Prendre la main depuis le bandeau, libérer depuis la liste | D'après le droit de modifier la configuration |

Poser son propre verrou ne demande que le droit de **modifier la fiche** (module Clients, Produits…). Les
administrateurs ont tous les droits.

## API

| Méthode | Route | Rôle |
|---|---|---|
| POST | `/verrous` | `entite`, `entite_id`, `renouveler`, `prendre_la_main` : pose ou renouvelle le verrou du compte |
| DELETE | `/verrous?entite=&entite_id=` | Lève le verrou du compte |
| GET | `/verrous` | Fiches en cours de modification (droit de consulter) |
| DELETE | `/verrous/{id}` | Libère la fiche d'un autre compte (droit de libérer) |

Réponse de `POST /verrous` : `{ verrou: { entite, entite_id, actif, par, par_id, depuis, expire_le, libere_par } }`.

- **409** quand le verrou est empêché, avec `message` et `verrou` :
  - `actif: true` : un autre compte tient la fiche ;
  - `actif: false` : à un renouvellement, le verrou a été perdu. `libere_par` est rempli si un responsable a libéré la
    fiche ; sinon `par` est le compte qui l'a modifiée entre-temps.
- **403** sans le droit de modifier la fiche, ou pour prendre la main sans le droit de libérer.
- **404** pour une fiche introuvable dans l'entreprise.
- **422** pour un type de fiche inconnu.

### Règles

- Un verrou par fiche et par entreprise. Il dure trois minutes ; l'écran le renouvelle chaque minute.
- **Renouvellement** (`renouveler: true`) : le verrou n'est jamais repris s'il a été perdu, que la fiche ait été
  libérée par un responsable ou tenue entre-temps par un autre compte. L'écran repart alors de la fiche enregistrée.
  Le verrou du compte qui a seulement expiré (écran en veille, personne d'autre n'a touché la fiche) est repris, avec
  le même début de modification.
- **Ouverture** (sans `renouveler`) : la fiche est prise si personne d'autre ne la tient. « Depuis » marque alors le
  début d'une nouvelle modification.
- Lever un verrou le fait expirer sans effacer la ligne, pour qu'un écran en retard sache s'il a été devancé. Les
  verrous expirés depuis plus d'un jour sont effacés.
- Table `verrous` : `tenant_id`, `entite`, `entite_id`, `admin_user_id`, `expire_le`, `libere_par_id`, `libere_le`.
  Unique par `tenant_id` + `entite` + `entite_id`.
- Middleware `verrou:{entite},{paramètre}` sur les routes de mise à jour et de suppression des fiches.
