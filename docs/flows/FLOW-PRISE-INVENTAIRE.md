# PRISE D'INVENTAIRE — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-16 — valorisation de l'inventaire officiel (phase 2.6)
>
> Historique : 2026-10-15 — création (plan de parité fripv2, phase 2.7)

---

## 1. Ce que c'est

**Inventaire → Prises d'inventaire** organise des sessions de comptage **par-dessus les scans existants**. Les employés scannent comme d'habitude, depuis l'application mobile ou l'écran Inventaires : rien ne change pour eux.

Une session :
1. regroupe les scans **de ses secteurs** saisis **pendant sa période** ;
2. compare chaque quantité comptée avec **le dernier inventaire validé** du secteur ;
3. affiche les **mouvements enregistrés depuis** (réceptions, relocalisations), pour expliquer les écarts ;
4. accepte des **corrections** des responsables (recomptage manuel, avec raison) ;
5. une fois **validée**, devient **l'inventaire officiel** de ses secteurs et la référence de la session suivante.

> PI n'a pas de stock théorique tenu à jour par les mouvements : son stock vient des comptages. La référence d'une session est donc le dernier inventaire validé, pas un stock calculé. Choix fait le 2026-10-15.

---

## 2. Créer une session (administrateurs et responsables)

| Champ | Rôle |
|---|---|
| Libellé | ex. « Inventaire annuel 2026 », « Contrôle allée A » |
| Secteurs | Une sélection, ou **tous les secteurs** |
| Début du comptage | Les scans saisis à partir de ce moment comptent (par défaut, maintenant) |
| Notes | Facultatif |

- Numéro automatique `INV-AAAA-NNNN`.
- **Deux sessions en cours ne peuvent pas partager un secteur**, sinon les scans seraient comptés deux fois. Il faut valider ou annuler la première.

---

## 3. Comptage et lignes

**Compté** : somme des scans du produit dans le secteur pendant la période.
- Un scan désigne son secteur par le **code** (saisie web) ou par le **nom** (application Android) : les deux sont reconnus, sans tenir compte des majuscules.
- Les scans supprimés ne comptent pas.
- En mode « tous les secteurs », un scan dont le secteur n'existe pas dans les paramètres forme sa propre ligne, signalée « Secteur inconnu ».

| Statut de ligne | Quand |
|---|---|
| **Nouveau** | Secteur jamais inventorié : pas de référence |
| **Non compté** | Produit présent au dernier inventaire, sans scan ni correction dans la session |
| **Écart** | Quantité retenue différente du dernier inventaire |
| **Conforme** | Identique au dernier inventaire |

- **Dernier inventaire** : quantité retenue par la dernière prise validée qui couvre le secteur. Un produit qui n'y figurait pas vaut 0.
- **Mouvements depuis** : réceptions rangées dans le secteur (`+`), et relocalisations vers le secteur (`+`) ou depuis lui (`−`), enregistrées après la fin de ce dernier inventaire. Ils servent à **expliquer** un écart, sans entrer dans son calcul. Les livraisons aux clients n'y figurent pas encore.
- **Retenu** : le compté, ou la **correction** d'un responsable (quantité et raison). « Revenir au comptage » retire la correction.
- **Écart** = retenu − dernier inventaire.

**Avancement**, secteur par secteur :
- scans, produits et employés ;
- dernier scan ;
- dernier inventaire du secteur (ou « jamais inventorié »).

**Actualiser** relit les scans arrivés entre-temps.

**Export CSV** : séparateur `;`, UTF-8 avec BOM pour Excel. Il suit les filtres affichés (statut, secteur, recherche).

---

## 4. Validation et annulation (administrateurs et responsables)

**Valider** fige les lignes :
- La fin de la période est fixée à ce moment.
- Les scans saisis ensuite ne changent plus la session.
- Les produits **non comptés** sont validés à **0**, sauf correction. Une confirmation affiche leur nombre avant validation.
- Les lignes à 0 sans référence ni écart ne sont pas conservées.

**Annuler** clôt une session en cours sans rien valider. Les scans ne sont pas supprimés, et les secteurs sont libérés pour une nouvelle session.

**Inventaire officiel** (onglet de la liste) : pour chaque secteur, les quantités de la dernière prise validée, filtrables par secteur et par produit.

---

## 5. Ce qui ne change pas

- Les scans, l'application Android, la comparaison, l'inventaire tournant, les alertes et les rapports gardent leur fonctionnement.
- L'inventaire officiel est **une nouvelle donnée**. Il sert aujourd'hui à la valorisation du stock (section 5 bis) ; il pourra servir plus tard aux alertes.
- Limite connue : l'application Android, hors ligne, renvoie ses scans sans leur heure de saisie. Un scan synchronisé après la clôture d'une session n'y entre donc pas.

---

## 5 bis. Valorisation de l'inventaire (Inventaire → Valorisation)

Phase 2.6 du plan de parité. La valeur de l'inventaire officiel vaut, pour chaque secteur, les quantités retenues par sa dernière prise validée multipliées par un coût unitaire. Rien n'est enregistré : c'est un état, qui se recalcule à chaque consultation.

| Méthode | Coût unitaire |
|---|---|
| Coût moyen pondéré (par défaut) | Moyenne des prix des commandes fournisseur, pondérée par les quantités reçues (commandées si rien n'est encore reçu) |
| Dernier coût d'achat | Prix de la dernière commande fournisseur envoyée, partiellement reçue ou reçue, avec son numéro et sa date |
| Prix de vente | Prix unitaire de la fiche produit |

- **Commandes prises en compte** : les brouillons et les commandes annulées sont ignorés.
- **Prix d'achat** : ils sont ramenés à l'unité de stock (un carton de 12 à 9 000 coûte 750 l'unité) et à la devise de l'entreprise, avec le taux de change figé sur la commande.
- **Produits sans coût** : ils sont comptés et signalés, sans valeur. Un produit est sans coût s'il n'a aucune commande fournisseur, ou pas de prix de vente pour la méthode « Prix de vente ».
- **Écran** :
  - total, nombre de produits valorisés, produits sans coût ;
  - détail par secteur (prise de référence, valeur) et par produit (secteurs, quantité, coût unitaire et sa référence, valeur) ;
  - filtre par secteur, recherche, export CSV.
- **Accès** : administrateurs et responsables, car la valorisation révèle les coûts. Les autres comptes voient un message.

---

## 6. API

| Route | Accès | Rôle |
|---|---|---|
| `GET /prises-inventaire` | Tous | Sessions (secteurs, créateur, clôture, nombre de lignes) |
| `POST /prises-inventaire` | Admin, responsable | `libelle`, `tous_secteurs` ou `secteur_ids`, `date_debut`, `notes` |
| `GET /prises-inventaire/{id}` | Tous | Session, avancement par secteur, résumé (lignes par statut, corrigées, totaux) |
| `GET /prises-inventaire/{id}/lignes?statut=&secteur=&recherche=&page=` | Tous | Lignes paginées (`statut` : nouveau, non_compte, ecart, conforme ou corrigee) |
| `GET /prises-inventaire/{id}/export` | Tous | Rapport d'écarts CSV (mêmes filtres) |
| `PUT /prises-inventaire/{id}/corrections` | Admin, responsable | `secteur`, `produit_numero`, `quantite_retenue` (null = retirer), `raison` |
| `POST /prises-inventaire/{id}/valider` | Admin, responsable | Fige les lignes (inventaire officiel) |
| `POST /prises-inventaire/{id}/annuler` | Admin, responsable | Clôt sans valider |
| `GET /prises-inventaire/officiel?secteur_id=&recherche=&page=` | Tous | Inventaire officiel |
| `GET /prises-inventaire/valorisation?methode=&secteur_id=[&format=csv]` | Admin, responsable | Valorisation (`cout_moyen`, `dernier_achat` ou `prix_vente`) |

**Tables :** `prises_inventaire`, `prises_inventaire_secteurs`, `prises_inventaire_corrections` et `prises_inventaire_lignes`, toutes cloisonnées par entreprise (migration `2026_10_15_100018`).

**Code :**
- API : `App\Services\PrisesInventaire`, `App\Http\Controllers\Inventaire\PriseInventaireController` ; valorisation : `App\Services\ValorisationInventaire`, `ValorisationInventaireController` ;
- front : `PrisesInventairePage`, `PriseInventairePage`, `ValorisationInventairePage`, `services/prisesInventaire.ts`, `services/valorisation.ts`.
