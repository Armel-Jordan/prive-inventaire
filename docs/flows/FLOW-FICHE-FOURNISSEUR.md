# FICHE FOURNISSEUR — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-16 — création (plan de parité fripv2, phase 3.1)

---

## 1. Ce que c'est

Une page par fournisseur, ouverte depuis la liste **Achats → Fournisseurs** en cliquant sur son code, ou depuis la recherche globale. Elle s'ajoute à l'existant :
- la liste, le formulaire du fournisseur, les commandes et les réceptions ne changent pas ;
- les coordonnées de la fiche se modifient toujours depuis la liste.

| Onglet | Contenu |
|---|---|
| **Synthèse** | Achats de l'année et de l'année précédente, achats depuis le début, commande moyenne, brouillons non envoyés, reste à recevoir, délai moyen de livraison, part des commandes livrées à temps, taux de réception, dernière commande, achats des 12 derniers mois, 5 produits les plus achetés |
| **Historique d'achats** | Lignes des commandes au fournisseur, de la plus récente à la plus ancienne, avec quantités commandées et reçues, filtrables par période et par produit, et le total de la sélection |
| **Produits fournis** | Catalogue du fournisseur : référence chez lui, prix d'achat convenu, délai, fournisseur principal, dernier prix payé |
| **Contacts** | Personnes à joindre : fonction, téléphones, courriel, notes, indicateur « reçoit les bons de commande » |
| **Adresses** | Siège, dépôts, lieux d'enlèvement de la marchandise, avec instructions |
| **Coordonnées bancaires** | Banque, agence, titulaire, numéro de compte local (RIB…) ou IBAN, BIC, devise, compte principal, auteur de la dernière saisie |
| **Remarques** | Remarques datées et signées (appel, litige, consigne), épinglables |

L'en-tête rappelle les coordonnées de la fiche, les conditions de paiement et la devise du fournisseur.

---

## 2. Règles de calcul (synthèse et historique)

- **Commandes qui comptent** : les commandes **envoyées, reçues en partie ou reçues en totalité**. Les brouillons et les commandes annulées sont exclus ; les brouillons sont seulement comptés à part (« non envoyés »).
- **Montants hors taxes**, comme les commandes : les lignes d'achat ne portent pas de taxe.
- **Devises.** Une commande est passée dans la devise du fournisseur, avec un taux vers la devise de l'entreprise :
  - les indicateurs et les graphiques de la synthèse convertissent chaque commande dans la devise de l'entreprise, au taux de cette commande (un taux absent ou nul compte pour 1) ;
  - l'historique garde chaque ligne dans la devise de sa commande, et convertit le total de la sélection ;
  - la dernière commande et le dernier prix payé s'affichent dans la devise de leur commande ; le prix convenu, dans celle du fournisseur.
- **Reste à recevoir** : quantités commandées non encore reçues sur les commandes envoyées ou reçues en partie, valorisées au prix de la ligne.
- **Délai moyen de livraison** : pour chaque commande reçue en totalité, jours entre la date de commande et sa dernière réception.
- **Livrées à temps** : part des commandes reçues en totalité au plus tard à la date de livraison prévue, parmi celles qui en ont une.
- **Taux de réception** : quantités reçues sur quantités commandées, pour les commandes déjà reçues en tout ou partie (plafonné à 100 %).
- Les achats mensuels couvrent le mois courant et les 11 mois précédents, y compris les mois sans achat. Les produits supprimés restent visibles dans l'historique.

---

## 3. Produits fournis

- Le catalogue repose sur la table existante `produit_fournisseur` (référence fournisseur, prix d'achat, délai, fournisseur principal).
- Un produit n'a qu'**un fournisseur principal** : le marquer chez un fournisseur le retire chez les autres.
- Un produit ne figure qu'une fois au catalogue d'un fournisseur (422 sinon). Seuls les produits de l'entreprise peuvent y être ajoutés.
- Le **dernier prix payé** vient de la dernière commande valide qui contient le produit.

---

## 4. Contacts, adresses et coordonnées bancaires

- Au plus **un contact principal**, **une adresse principale** et **un compte principal** par fournisseur. En marquer un nouveau retire la marque du précédent.
- Le contact (`contact_nom`, `contact_telephone`) et l'adresse de la fiche restent en place ; ils sont rappelés en tête des onglets.
- **Coordonnées bancaires** :
  - un numéro de compte local ou un IBAN est obligatoire ;
  - l'IBAN, le BIC et la devise sont enregistrés en majuscules, sans espaces ; leur format est vérifié ;
  - chaque saisie ou modification garde son auteur (`modifie_par`), affiché sous le compte avec la date ;
  - l'onglet rappelle de vérifier tout changement de coordonnées auprès du fournisseur avant de payer : c'est le premier signe d'une fraude au virement.

---

## 5. Droits

| Action | Qui |
|---|---|
| Consulter la fiche, gérer le catalogue, les contacts et les adresses, ajouter des remarques | Tout utilisateur du module Achats |
| Saisir, modifier ou supprimer des coordonnées bancaires | Administrateurs et managers (lecture seule pour les autres) |
| Modifier, épingler ou supprimer une remarque | Son auteur, un administrateur ou un manager |

Toutes les données sont cloisonnées par entreprise. La fiche d'un fournisseur d'une autre entreprise répond 404.

---

## 6. API (module Achats)

| Route | Rôle |
|---|---|
| `GET /fournisseurs/{id}/fiche` | Fournisseur avec les nombres de produits, contacts, adresses, comptes et remarques |
| `GET /fournisseurs/{id}/synthese` | Indicateurs, achats mensuels, produits les plus achetés |
| `GET /fournisseurs/{id}/historique-achats?du=&au=&recherche=&produit_id=&page=` | Lignes paginées et `totaux` (quantités commandées et reçues, montant converti) |
| `GET` / `POST /fournisseurs/{id}/produits`, `PUT` / `DELETE …/produits/{produit}` | Catalogue |
| `GET` / `POST /fournisseurs/{id}/contacts`, `PUT` / `DELETE …/contacts/{contact}` | Contacts |
| `GET` / `POST /fournisseurs/{id}/adresses`, `PUT` / `DELETE …/adresses/{adresse}` | Adresses |
| `GET /fournisseurs/{id}/comptes-bancaires` ; `POST`, `PUT`, `DELETE` (administrateurs et managers) | Coordonnées bancaires |
| `GET` / `POST /fournisseurs/{id}/remarques`, `PUT` / `DELETE …/remarques/{remarque}` | Remarques |

Tables : `fournisseur_contacts`, `fournisseur_adresses`, `fournisseur_comptes_bancaires`, `fournisseur_remarques` (migration `2026_10_16_100021`) ; catalogue dans `produit_fournisseur`.

Code :
- API : `Api\FournisseurFiche*Controller`, sur les contrôleurs génériques `Api\FicheElementController` et `Api\FicheRemarqueController`, partagés avec la fiche client ;
- front : `pages/FicheFournisseurPage.tsx` et `components/fiche-fournisseur/*`, avec les composants communs de `components/fiche/*` (listes, remarques, formats).
