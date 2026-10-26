# BALISES ET REMARQUES RAPIDES — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-15 — création (plan de parité fripv2, phase 0.9)

---

## 1. Balises de classement

Une **balise** est une catégorie que l'entreprise définit (Région, Type d'industrie, Priorité…), avec ses **items**, qui sont les valeurs possibles (Nord, Sud, Est…). Les items se posent sur les fiches et les documents pour les classer, puis pour les retrouver.

| Réglage | Rôle |
|---|---|
| Nom | Nom de la balise (60 caractères) |
| Type | **Un seul choix** (un item au plus par fiche) ou **plusieurs choix** |
| S'applique à | Types de fiches qui la portent : clients, fournisseurs, produits, employés, devis, commandes client, factures, bons de livraison, commandes fournisseur (selon les modules actifs) |
| Active | Une balise inactive disparaît des fiches ; ses poses sont gardées |
| Items | Libellé (unique dans la balise, casse ignorée), couleur de pastille (gris, bleu, vert, jaune, orange, rouge, violet, rose), actif |

- Passer une balise à « un seul choix » est refusé tant que des fiches en portent plusieurs items.
- Supprimer un item ou une balise retire aussi ses poses.

### Poser des balises

Dans les listes des neuf écrans, les items posés s'affichent en **pastilles** sous le nom ou le numéro, suivies d'une petite étiquette qui ouvre le choix des items : bouton radio (avec « Aucune ») pour une balise à un seul choix, cases à cocher sinon.

Les pastilles et l'étiquette n'apparaissent que si l'entreprise a une balise active pour ce type de fiche. Les items posés de toute une liste sont chargés en une seule requête.

### Retrouver des fiches

**Paramètres → Balises et remarques rapides**, onglet Balises : choisir un type de fiche, cocher des items, puis « au moins un des items » ou « tous les items ».

---

## 2. Remarques rapides

Textes réutilisables (2 000 caractères), classés par **catégorie libre** (Livraison, Facturation…, ou aucune), insérés d'un clic dans les notes :

| Écran | Champ |
|---|---|
| Devis | Notes (ajoutée après une espace) |
| Commandes client | Notes (ajoutée à la ligne) |
| Factures | Notes (ajoutée après une espace) |
| Commandes fournisseur | Notes (ajoutée à la ligne) |
| Bons de livraison | Notes livraison (ajoutée à la ligne) |

Sous le champ, **Remarque rapide** ouvre la liste, groupée par catégorie avec une recherche ; un clic ajoute la remarque à la suite de la note existante. Les remarques se gèrent dans l'onglet Remarques rapides du même écran.

**Droits** : tout compte lit la liste et insère une remarque ; ajouter, modifier ou supprimer une remarque demande les droits de la **Configuration** (comme les balises).

---

## 3. Stockage

- `balises` (nom, type, `entites` en JSON, actif), `balise_items` (libellé, couleur, actif), `balise_affectations` (item × type de fiche × fiche).
- `remarques_rapides` (catégorie, texte).
- Catalogue commun des types de fiches : `App\Support\Fiches` (modèle, module requis, libellé lisible).

---

## 4. API

| Route | Rôle |
|---|---|
| `GET /balises?entite=&actifs=1` | Balises avec leurs items |
| `POST /balises`, `PUT` / `DELETE /balises/{id}` | Balises |
| `POST /balises/{id}/items`, `PUT` / `DELETE /balises/items/{id}` | Items |
| `GET /balises/affectations?entite=&ids=1,2,3` | Items posés sur plusieurs fiches (200 au plus) : `{ id: [items] }` |
| `PUT /balises/affectations` | Items posés sur une fiche (`entite`, `entite_id`, `items`) |
| `GET /balises/recherche?entite=&items[]=&mode=un\|tous` | Fiches qui portent les items |
| `GET` / `POST /remarques-rapides`, `PUT` / `DELETE /remarques-rapides/{id}` | Remarques rapides |

Fiche d'une autre entreprise : 404. Module de la fiche inactif : 403.
