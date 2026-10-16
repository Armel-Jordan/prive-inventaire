# CONFIGURATION À COMPLÉTER — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-15 — création (plan de parité fripv2, phase 0.11)

---

## 1. Ce que c'est

Un parcours de mise en route dans **Paramètres → Configuration à compléter**. Il liste, étape par étape, les réglages nécessaires pour bien démarrer.

- **Rien ne se coche à la main.** Chaque réglage est vérifié dans les données de l'entreprise à chaque ouverture de l'écran. Il se coche dès que la donnée existe et se décoche si elle disparaît (ex. dernier secteur supprimé).
- Les réglages suivent les **modules** de l'entreprise. Sans le module Achats, aucune ligne « Fournisseurs ».
- Le parcours ne bloque rien. L'écran s'ouvre sur la première étape où il reste quelque chose à faire, et chaque ligne mène, avec **Ouvrir**, à l'écran où se fait le réglage.

---

## 2. Étapes et réglages

| Étape | Réglage (`cle`) | Type | Module | Fait quand |
|---|---|---|---|---|
| Identité de l'entreprise | `entreprise_nom` | Obligatoire | — | Le nom de l'entreprise est renseigné |
| | `devise` | Obligatoire | — | Les paramètres ont été enregistrés au moins une fois (ils naissent avec EUR / TVA 20 % par défaut) |
| | `entreprise_coordonnees` | Recommandé | — | Adresse, téléphone et courriel renseignés |
| | `entreprise_numeros` | Recommandé | — | SIRET ou numéro de TVA renseigné |
| | `numerotation` | Recommandé | — | La numérotation des documents a été enregistrée |
| Inventaire | `secteurs` | Obligatoire | — | Au moins un secteur |
| | `produits` | Obligatoire | — | Au moins un produit |
| | `seuils_alerte` | Recommandé | — | Au moins un produit avec un seuil d'alerte |
| | `employes` | Recommandé | — | Au moins une fiche employé |
| Clients, fournisseurs et taxes | `clients` | Obligatoire | Ventes | Au moins un client |
| | `taxes` | Recommandé | Ventes | Au moins une taxe |
| | `fournisseurs` | Obligatoire | Achats | Au moins un fournisseur |
| Utilisateurs | `utilisateurs` | Recommandé | — | Au moins un utilisateur non administrateur |

Pour ajouter un réglage, il faut une ligne dans `App\Support\ConfigurationACompleter::etapes()` et son libellé dans les traductions du front (`setup.tasks`).

---

## 3. Réglage qui ne concerne pas l'entreprise

Un administrateur peut **ignorer** un réglage *recommandé* en indiquant pourquoi (ex. « Nous sommes deux, sans fiches employés »).

- La ligne reste visible avec la raison et son auteur. Elle ne compte plus dans le reste à faire.
- **Reprendre** la remet dans le parcours.
- Un réglage **obligatoire** ne s'ignore pas.

---

## 4. Qualité des données

Des compteurs signalent les fiches actives incomplètes. Un compteur à zéro n'est pas affiché, et un compteur d'un module inactif non plus.

| Compteur | Fiches comptées |
|---|---|
| `produits_sans_prix` | Produits actifs sans prix (vide ou ≤ 0) |
| `produits_sans_secteur` | Produits actifs sans secteur |
| `clients_sans_courriel` | Clients actifs sans courriel (Ventes) |
| `clients_sans_telephone` | Clients actifs sans téléphone (Ventes) |
| `fournisseurs_sans_courriel` | Fournisseurs actifs sans courriel (Achats) |
| `employes_sans_courriel` | Employés actifs sans courriel |

**Voir** ouvre la liste concernée.

---

## 5. Rappel au tableau de bord

Tant qu'il reste des réglages **obligatoires**, les administrateurs voient en haut du tableau de bord un rappel avec le nombre restant et un lien vers le parcours. Le rappel disparaît une fois ces réglages faits.

---

## 6. API

| Route | Rôle |
|---|---|
| `GET /configuration-a-completer` | Étapes (réglages, fait / ignoré, lien), compteurs de qualité non nuls, reste à faire (`obligatoires`, `recommandees`) |
| `POST /configuration-a-completer/{cle}/ignorer` | Administrateurs. Body : `raison` (obligatoire). 422 pour un réglage obligatoire, 404 pour une clé inconnue |
| `DELETE /configuration-a-completer/{cle}/ignorer` | Administrateurs. Remet le réglage dans le parcours |

Les deux actions renvoient l'état complet, comme le `GET`.

Table : `configuration_taches_ignorees` (une ligne par réglage ignoré et par entreprise). Code : `App\Support\ConfigurationACompleter`, `Api\ConfigurationACompleterController`, front `ConfigurationACompleterPage` et `RappelConfiguration`.
