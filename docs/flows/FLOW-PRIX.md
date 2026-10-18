# PRIX ET REMISES — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-18 — révisions de prix planifiées (§5, phase 1.2d)

---

## 1. Ce que c'est

L'écran **Finance → Tarifs & Prix** (`/gestion-prix`) gère les prix de vente. Il remplace l'ancien écran de démonstration, qui n'enregistrait rien.

| Onglet | Contenu |
|---|---|
| **Grille de prix** | Pour chaque produit : son prix de base et son prix dans chaque liste active. Modification en place, puis enregistrement groupé |
| **Listes de prix** | Listes (ex. Grossiste, Détaillant, Export) : ajustement en % sur le prix de base, décimales, clients rattachés |
| **Règles de prix** | Prix client négociés, paliers de quantité, promotions par période, par produit ou par catégorie |
| **Révisions** | Nouveaux prix préparés en lot, appliqués tout de suite ou à leur date d'entrée en vigueur (§5) |
| **Simulateur** | Prix qu'obtiendrait un client pour un produit, une quantité et une date, avec le détail des prix comparés |

**Droits :**
- tout utilisateur du module Ventes consulte et simule ;
- les administrateurs et les responsables modifient ;
- les révisions se consultent avec le droit de voir les tarifs, et se préparent, se planifient, s'appliquent et s'annulent avec la permission « Planifier une révision de prix » (action `gestion_prix.reviser`, qui suit par défaut le droit de modifier les tarifs).

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
| Portée | Client, **catégorie de clients** (phase 1.2c), liste de prix, produit, catégorie de produits (comparée sans tenir compte des majuscules). Un critère vide vaut « tous » ; les critères remplis s'additionnent |
| Conditions | Quantité minimale (1 par défaut), période du / au. Une date vide veut dire sans limite ; les bornes sont incluses |
| Effet | **Prix fixe**, **remise en %** (100 % au plus) ou **remise en montant**, appliqué au prix de la liste du client |
| Statut | Active ou non. La liste affiche « En vigueur », « À venir », « Expirée » ou « Inactive » |

Une règle par **catégorie de clients** vaut pour tous les clients classés dans cette catégorie (fiche client →
Catégorie). Une catégorie utilisée par une règle ne se supprime pas : sans elle, la règle vaudrait pour tous les clients.

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

## 5. Révisions de prix planifiées (phase 1.2d)

Une **révision** prépare de nouveaux prix en lot, comme l'écran « Révision de prix planifiée » de fripv2. Les prix réels ne changent qu'à son application.

**Préparer :**
1. « Nouvelle révision », puis l'**ajustement** :
   - catégorie de produits (ou toutes) ; produits actifs seulement, sauf si « Inclure les produits inactifs » est coché ;
   - prix visés : le **prix de base**, les **prix saisis d'une liste**, ou le prix de base et les prix saisis de **toutes les listes actives** ;
   - type **pourcentage** ou **montant**, et la valeur (négative pour une baisse).
2. « Appliquer l'ajustement » remplit la colonne « Nouveau prix » :
   - arrondi aux décimales de la liste (2 pour le prix de base) ;
   - jamais sous zéro ;
   - un produit sans prix de base, ou sans prix saisi dans la liste, n'est pas touché ;
   - un nouvel ajustement remplace les prix qu'il touche, retouches comprises, et garde les autres lignes : une révision peut réunir plusieurs ajustements (+5 % sur une catégorie, +3 % sur une autre).
3. Retouches à la main dans la colonne « Nouveau prix », ou retrait d'une ligne.
4. Nom, notes, puis :
   - « Enregistrer » (brouillon) ;
   - « Planifier », avec la date d'entrée en vigueur (aujourd'hui au plus tôt côté API ; l'écran propose demain au plus tôt, car pour aujourd'hui on applique) ;
   - « Appliquer maintenant », après confirmation.

**Prix des listes calculés par leur formule :** ils ne sont pas des lignes de la révision. Ils suivent le prix de base : sous une ligne de prix de base, l'écran montre leur prix avant et après (« Listes qui suivent la formule »).

**Application :**
- chaque ligne écrit son nouveau prix : `produits.prix_unitaire`, ou le prix saisi de la liste (créé s'il n'y en a plus) ;
- le prix remplacé est noté sur la ligne (`prix_remplace`) : une révision appliquée montre « Prix remplacé → Nouveau prix » ;
- **une seule fois** : la révision est verrouillée pendant l'application ; appliquée, elle ne se modifie, ne s'annule ni ne se supprime plus (historique des prix).

**Planification :**
- la tâche planifiée `revisions_prix` (« Application des révisions de prix », module Ventes, chaque jour à 0 h 05 par défaut) applique les révisions planifiées dont la date est atteinte, et celles qu'un arrêt a fait manquer ;
- elle est **activée à la première planification** si personne ne l'a encore réglée ; désactivée exprès, elle le reste et l'écran le signale ;
- chaque révision appliquée par la tâche crée une notification « Révision de prix appliquée » (lien vers l'onglet Révisions) ; une révision en échec est rapportée dans l'historique de la tâche, les autres s'appliquent.

**Statuts :** brouillon → planifiée → appliquée ; brouillon ou planifiée → annulée. Une révision planifiée se modifie et garde sa date (requise). Une révision non appliquée se supprime.

**Limite :** 5 000 prix par révision ; au-delà, l'ajustement demande de viser une catégorie ou une liste.

**Export :** « Exporter (CSV) » télécharge la grille de la révision (séparateur « ; », virgule décimale).

**Écarts avec fripv2 :** pas de chaîne de listes (dans l'application, une liste se calcule seulement depuis le prix de base) ; la révision garde les prix remplacés.

---

## 6. API (module Ventes)

| Route | Rôle |
|---|---|
| `POST /prix/calculer` | `client_id` (facultatif), `date` (facultative, aujourd'hui par défaut), `lignes[{produit_id, quantite}]`. Pour chaque ligne : `prix_base`, `liste`, `prix_liste`, `prix`, `remise_pourcentage`, `origine` (base, liste, remise_client, regle), `regle`, `candidats` |
| `GET /listes-prix` | Listes, avec le nombre de prix saisis et de clients |
| `POST` / `PUT` / `DELETE /listes-prix/{id}` | Gestion des listes (administrateurs, responsables) |
| `GET /listes-prix/{id}/clients`, `PUT /clients/{id}/liste-prix` | Clients d'une liste ; rattacher ou détacher un client (`liste_prix_id` ou `null`) |
| `GET /grille-prix?recherche=&categorie=&page=` | Produits paginés : prix de base et, par liste active, `prix`, `saisi`, `formule`. Renvoie aussi les listes et les catégories |
| `PUT /grille-prix` | `modifications[{produit_id, liste_prix_id, prix}]`. Sans liste : prix de base. Sur une liste, un prix vide retire le prix saisi |
| `GET /regles-prix?statut=&recherche=&client_id=&produit_id=` | Règles, avec client, catégorie de clients, produit et liste |
| `POST` / `PUT` / `DELETE /regles-prix/{id}` | Gestion des règles (administrateurs, responsables) |
| `GET /revisions-prix` | Révisions (planifiées, puis brouillons, puis les autres), état de la tâche `revisions_prix` (`tache.active`), listes actives (ajustement, décimales) et catégories de produits. Droit de voir les tarifs |
| `GET /revisions-prix/{id}` | Révision et ses lignes : produit, liste, `prix_actuel` (en place ; le prix remplacé une fois appliquée), `nouveau_prix`, `formules` (listes actives dont le prix suit la formule) |
| `POST /revisions-prix/apercu` | `categorie`, `cible` (base, liste, toutes), `liste_prix_id`, `type` (pourcentage, montant), `valeur`, `inclure_inactifs` : les lignes de l'ajustement. Rien n'est écrit |
| `POST` / `PUT /revisions-prix/{id}` | `nom`, `notes`, `date_effet`, dernier ajustement (pour mémoire), `lignes[{produit_id, liste_prix_id, nouveau_prix}]` : remplace les lignes d'une révision en cours |
| `POST /revisions-prix/{id}/planifier` | `date_effet` (aujourd'hui au plus tôt) |
| `POST /revisions-prix/{id}/appliquer`, `/annuler`, `DELETE /revisions-prix/{id}` | Appliquer (une fois), annuler, supprimer (sauf une révision appliquée) |

Les écritures des révisions demandent l'action `gestion_prix.reviser` (`Droits::ECRITURES_ACTION`), leurs lectures le droit de voir les tarifs (`Droits::LECTURES`).

**Tables :**
- `listes_prix`, `listes_prix_produits`, `regles_prix`, plus la colonne facultative `clients.liste_prix_id` (migration `2026_10_15_100016`) ;
- `regles_prix.categorie_client_id` (migration `2026_10_18_100087`) ;
- `revisions_prix` et `revision_prix_lignes` (migration `2026_10_18_100088`).

**Code :**
- API : `App\Services\CalculPrix`, `App\Services\RevisionsPrix`, `App\Taches\AppliquerRevisionsPrix`, `App\Http\Controllers\Prix\*` ;
- front : `pages/GestionPrixPage.tsx` (onglet ouvert par `?onglet=revisions`) et `components/prix/*`.

---

## 7. Suite prévue

- **Import de grilles de prix** (CSV).

Faits en phase 1.2c : les règles par catégorie de clients (§ 3) et les références produits des clients
([FLOW-FICHE-CLIENT.md](./FLOW-FICHE-CLIENT.md)). Fait en phase 1.2d : les révisions de prix planifiées (§ 5).
