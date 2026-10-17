# PRIX ET REMISES — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-17 — prix proposés dans les devis, commandes et factures (§4.1, phase 1.2b)

---

## 1. Ce que c'est

L'écran **Finance → Tarifs & Prix** (`/gestion-prix`) gère les prix de vente. Il remplace l'ancien écran de démonstration, qui n'enregistrait rien.

| Onglet | Contenu |
|---|---|
| **Grille de prix** | Pour chaque produit : son prix de base et son prix dans chaque liste active. Modification en place, puis enregistrement groupé |
| **Listes de prix** | Listes (ex. Grossiste, Détaillant, Export) : ajustement en % sur le prix de base, décimales, clients rattachés |
| **Règles de prix** | Prix client négociés, paliers de quantité, promotions par période, par produit ou par catégorie |
| **Simulateur** | Prix qu'obtiendrait un client pour un produit, une quantité et une date, avec le détail des prix comparés |

**Droits :**
- tout utilisateur du module Ventes consulte et simule ;
- les administrateurs et les responsables modifient.

**Montants :** affichés avec le symbole de devise des paramètres de l'entreprise.

**Dans les documents :** les devis, commandes et factures proposent ce prix sur chaque ligne (§4.1). Il reste modifiable.

---

## 2. Prix de base et listes de prix

- **Prix de base** : `produits.prix_unitaire`, la colonne existante. Il était déjà accepté par l'API des produits, mais aucun écran ne l'affichait.
- **Liste de prix** :
  - le prix d'un produit est le **prix saisi** pour ce produit dans la liste s'il existe ;
  - sinon, c'est le prix de base × (1 + ajustement / 100), arrondi au nombre de décimales de la liste (0 à 4).

  Exemples : −10 pour 10 % de moins, +15 pour 15 % de plus, 0 pour reprendre le prix de base.
- Dans la grille, un champ de liste vide suit la formule ; sa valeur s'affiche en grisé. Vider un prix saisi rend le produit à la formule.
- **Clients :** un client est rattaché au plus à une liste (onglet Listes de prix). Sans liste, ou si sa liste est désactivée, il paie le prix de base.
- **Suppression d'une liste :**
  - elle est refusée tant qu'une règle la vise ;
  - sinon, ses clients sont détachés et ses prix saisis supprimés.

---

## 3. Règles de prix

| Champ | Rôle |
|---|---|
| Portée | Client, liste de prix, produit, catégorie de produits (comparée sans tenir compte des majuscules). Un critère vide vaut « tous » |
| Conditions | Quantité minimale (1 par défaut), période du / au. Une date vide veut dire sans limite ; les bornes sont incluses |
| Effet | **Prix fixe**, **remise en %** (100 % au plus) ou **remise en montant**, appliqué au prix de la liste du client |
| Statut | Active ou non. La liste affiche « En vigueur », « À venir », « Expirée » ou « Inactive » |

---

## 4. Calcul du prix d'un client

Pour un client, un produit, une quantité et une date :

1. **Prix de la liste** : celui de la liste du client, sinon le prix de base.
2. **Candidats** :
   - ce prix ;
   - la **remise globale** de la fiche client (`taux_remise_global`) ;
   - chaque règle active à la date, qui vise ce client (ou sa liste, ou tous), ce produit (ou sa catégorie, ou tous), et dont la quantité minimale est atteinte.
3. **Prix retenu** : le **plus bas des prix positifs**, comme dans fripv2. À égalité, le premier trouvé l'emporte : liste, remise du client, puis règles par ancienneté.
4. **Remise affichée** : écart entre le prix retenu et le prix de la liste, en %.

**Conséquence :** une règle ne peut pas fixer un prix plus élevé que celui de la liste du client, car le plus bas l'emporte. Pour vendre plus cher à un groupe de clients, on lui donne sa propre liste de prix.

**Écarts avec fripv2 :**
- Une règle sans dates ou sans quantité minimale s'applique. Dans fripv2, d'après la lecture de sa requête (non vérifié à l'exécution), une telle règle ne correspond jamais.
- Un client sans liste obtient quand même un prix (le prix de base) et ses règles ; dans fripv2, le calcul s'arrêtait.

### 4.1 Prix proposés dans les devis, commandes et factures (phase 1.2b)

Dans les formulaires de devis, de commande et de facture, ce calcul propose le prix de chaque ligne, pour le client, la quantité et la date du document.

**Quand le prix est proposé :**
- au choix du produit, son prix de base s'affiche aussitôt, puis le prix du client le remplace ;
- un changement de quantité (paliers des règles), de client, de date ou de devise recalcule les prix proposés ;
- dans une devise étrangère, le prix est converti au taux du document.

**Ce qui n'est jamais remplacé :**
- un prix saisi à la main ;
- les prix enregistrés des lignes d'un document qu'on modifie.

**Affichage :** une pastille verte (étiquette, avec la remise s'il y en a une) signale un prix venu d'une liste, de la remise du client ou d'une règle ; son origine s'affiche au survol.

Sans liste de prix, sans règle et sans remise client, le prix proposé reste le prix de base, comme avant. Le portail client applique déjà ce calcul à ses commandes.

Code : hook `usePrixAutomatiques`, `lib/prixDocuments`, composant `OriginePrix`.

---

## 5. API (module Ventes)

| Route | Rôle |
|---|---|
| `POST /prix/calculer` | `client_id` (facultatif), `date` (facultative, aujourd'hui par défaut), `lignes[{produit_id, quantite}]`. Pour chaque ligne : `prix_base`, `liste`, `prix_liste`, `prix`, `remise_pourcentage`, `origine` (base, liste, remise_client, regle), `regle`, `candidats` |
| `GET /listes-prix` | Listes, avec le nombre de prix saisis et de clients |
| `POST` / `PUT` / `DELETE /listes-prix/{id}` | Gestion des listes (administrateurs, responsables) |
| `GET /listes-prix/{id}/clients`, `PUT /clients/{id}/liste-prix` | Clients d'une liste ; rattacher ou détacher un client (`liste_prix_id` ou `null`) |
| `GET /grille-prix?recherche=&categorie=&page=` | Produits paginés : prix de base et, par liste active, `prix`, `saisi`, `formule`. Renvoie aussi les listes et les catégories |
| `PUT /grille-prix` | `modifications[{produit_id, liste_prix_id, prix}]`. Sans liste : prix de base. Sur une liste, un prix vide retire le prix saisi |
| `GET /regles-prix?statut=&recherche=&client_id=&produit_id=` | Règles, avec client, produit et liste |
| `POST` / `PUT` / `DELETE /regles-prix/{id}` | Gestion des règles (administrateurs, responsables) |

**Tables :** `listes_prix`, `listes_prix_produits`, `regles_prix`, plus la colonne facultative `clients.liste_prix_id` (migration `2026_10_15_100016`).

**Code :**
- API : `App\Services\CalculPrix`, `App\Http\Controllers\Prix\*` ;
- front : `pages/GestionPrixPage.tsx` et `components/prix/*`.

---

## 6. Suite prévue

- Règles par **catégorie de clients**.
- **Révision de prix planifiée**, avec la tâche planifiée de la phase 0.10.
- **Import de grilles de prix** (CSV).
- **Références produit du client.**
