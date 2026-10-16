# FICHE CLIENT — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-15 — création (plan de parité fripv2, phase 1.1)

---

## 1. Ce que c'est

Une page par client, ouverte depuis la liste **Ventes → Clients** en cliquant sur son code. Elle s'ajoute à l'existant :
- la liste, le formulaire de création et de modification, les coordonnées de la fiche et les documents de vente ne changent pas ;
- les coordonnées se modifient toujours depuis la liste.

| Onglet | Contenu |
|---|---|
| **Synthèse** | Ventes de l'année et de l'année précédente, ventes depuis le début, panier moyen, encours et factures en retard, commandes et devis en cours, client depuis, dernière facture, ventes des 12 derniers mois, 5 produits les plus achetés |
| **Historique d'achats** | Lignes des factures du client, de la plus récente à la plus ancienne, filtrables par période et par produit (numéro ou description), avec le total de la sélection |
| **Contacts** | Personnes à joindre : fonction, téléphones, courriel, notes. Indicateurs « reçoit les factures » et « reçoit les commandes » |
| **Adresses** | Adresses supplémentaires (chantiers, succursales, points de livraison) avec instructions de livraison |
| **Remarques** | Remarques datées et signées (appel, visite, consigne), épinglables |

L'en-tête rappelle :
- les coordonnées de la fiche ;
- l'encours maximum et la remise globale ;
- la catégorie (modifiable sur place) ;
- l'étoile favori.

Les montants s'affichent avec le symbole de devise des paramètres de l'entreprise.

---

## 2. Règles de calcul (synthèse et historique)

- Seules comptent les factures **émises, partiellement payées ou payées**. Les brouillons et les factures annulées sont exclus.
- Les montants sont **hors taxes** (`montant_ht`). Le panier moyen est le total HT divisé par le nombre de factures.
- **Encours** : reste à payer des factures émises ou partiellement payées. **En retard** : celles dont l'échéance est dépassée.
- Les produits supprimés restent visibles dans l'historique.
- Les ventes mensuelles couvrent le mois courant et les 11 mois précédents, y compris les mois sans vente.

---

## 3. Contacts et adresses

- Au plus **un contact principal** et **une adresse principale** par client. En marquer un nouveau retire la marque du précédent.
- Le contact (`contact_nom`, `contact_telephone`) et les adresses de facturation et de livraison de la fiche restent en place. Ils sont rappelés en tête des onglets, et contacts et adresses s'y ajoutent.
- Les indicateurs « reçoit les factures » et « reçoit les commandes » préparent le choix automatique des destinataires des envois. Ils ne changent rien pour l'instant.

---

## 4. Catégories et favoris

- **Catégories de clients** (ex. Grossiste, Détaillant, Administration) :
  - créées par les administrateurs avec **Gérer les catégories**, dans la liste des clients ;
  - un utilisateur classe un client depuis sa fiche ;
  - la liste se filtre par catégorie ;
  - supprimer une catégorie garde ses clients, qui restent sans catégorie ;
  - elles serviront aux prix par catégorie (phase 1.2).
- **Favoris** : chaque utilisateur marque ses clients d'une étoile, dans la liste ou sur la fiche. **Mes favoris** filtre la liste. Les favoris d'un utilisateur ne sont pas ceux de ses collègues.

---

## 5. Droits

| Action | Qui |
|---|---|
| Consulter la fiche, gérer contacts et adresses, classer, ajouter des remarques, favoris | Tout utilisateur du module Ventes |
| Modifier, épingler ou supprimer une remarque | Son auteur, un administrateur ou un responsable |
| Créer, renommer, supprimer une catégorie | Administrateurs |

Toutes les données sont cloisonnées par entreprise. La fiche d'un client d'une autre entreprise répond 404.

---

## 6. API (module Ventes)

| Route | Rôle |
|---|---|
| `GET /clients/{id}/fiche` | Client avec catégorie, conditions de paiement, nombres de contacts, d'adresses et de remarques, favori de l'utilisateur |
| `GET /clients/{id}/synthese` | Indicateurs, ventes mensuelles, produits les plus achetés |
| `GET /clients/{id}/historique-achats?du=&au=&recherche=&produit_id=&page=` | Lignes paginées et `totaux` (quantité, montant HT) |
| `PUT /clients/{id}/categorie` | `categorie_id` (ou `null`) |
| `POST` / `DELETE /clients/{id}/favori` | Ajoute ou retire le favori de l'utilisateur |
| `GET` / `POST /clients/{id}/contacts`, `PUT` / `DELETE …/contacts/{contact}` | Contacts |
| `GET` / `POST /clients/{id}/adresses`, `PUT` / `DELETE …/adresses/{adresse}` | Adresses |
| `GET` / `POST /clients/{id}/remarques`, `PUT` / `DELETE …/remarques/{remarque}` | Remarques |
| `GET /categories-clients`, `POST` / `PUT` / `DELETE` (administrateurs) | Catégories |

`GET /clients` accepte en plus `categorie_id` et `favoris=1`, et renvoie pour chaque client sa `categorie` et `favori`.

Tables : `client_categories`, `client_contacts`, `client_adresses`, `client_remarques`, `client_favoris`, et colonne facultative `clients.categorie_id` (migration `2026_10_15_100015`).

Code :
- API : `Api\ClientFiche*Controller` ;
- front : `pages/FicheClientPage.tsx` et `components/fiche-client/*`.
