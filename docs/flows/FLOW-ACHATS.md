# ACHATS — Flow complet & détail de chaque étape

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-04-10

---

## TABLE DES MATIÈRES

1. [Vue d'ensemble du module](#1-vue-densemble-du-module)
2. [Fournisseurs](#2-fournisseurs)
3. [Commandes Fournisseur](#3-commandes-fournisseur)
4. [Réceptions (Arrivages)](#4-réceptions-arrivages)
5. [Flow complet bout en bout](#5-flow-complet-bout-en-bout)
6. [Statuts et transitions](#6-statuts-et-transitions)
7. [Impact sur le stock](#7-impact-sur-le-stock)
8. [Multi-devise](#8-multi-devise)

---

## 1. Vue d'ensemble du module

Le module **Achats** gère tout ce qui concerne l'approvisionnement :

```
ACHATS
├── Fournisseurs       ← les entreprises à qui on achète
├── Commandes          ← les bons de commande envoyés aux fournisseurs
└── Réceptions         ← la réception physique des marchandises dans l'entrepôt
```

**Principe général :**
```
Créer commande fournisseur
       ↓
Envoyer la commande au fournisseur
       ↓
Fournisseur livre la marchandise (partiellement ou en totalité)
       ↓
Enregistrer la réception dans l'application
       ↓
Le stock est mis à jour automatiquement
```

---

## 2. Fournisseurs

### 2.1 Ce que c'est

Un **Fournisseur** est une entreprise ou personne à qui on achète des produits. Chaque fournisseur a sa fiche avec ses coordonnées, ses conditions de paiement, sa devise de facturation.

### 2.2 Champs d'un fournisseur

| Champ | Description | Obligatoire |
|-------|-------------|-------------|
| `code` | Identifiant court (ex: FRN-0001) | Oui (auto) |
| `raison_sociale` | Nom de l'entreprise | Oui |
| `adresse` | Adresse complète | Non |
| `telephone` | Numéro de téléphone | Non |
| `email` | Email principal | Non |
| `contact_nom` | Nom du contact chez le fournisseur | Non |
| `contact_telephone` | Téléphone du contact | Non |
| `conditions_paiement` | Ex: "30 jours fin de mois" | Non |
| `devise` | Devise de facturation (`EUR`, `USD`, `XAF`, `CAD`...) | Oui |
| `actif` | Actif ou inactif | Oui |

### 2.3 Relation fournisseur ↔ produit

Un fournisseur peut fournir plusieurs produits. Pour chaque produit qu'il fournit, on peut enregistrer :

| Info | Description |
|------|-------------|
| `reference_fournisseur` | La référence du produit chez ce fournisseur |
| `prix_achat` | Prix d'achat habituel |
| `delai_livraison` | Délai en jours |
| `fournisseur_principal` | Est-ce le fournisseur principal pour ce produit ? |

Cela permet de pré-remplir le prix quand on crée une commande.

### 2.4 Statut actif / inactif

- Un fournisseur **inactif** n'apparaît pas dans la liste de sélection lors de la création d'une commande.
- Ses commandes et historique restent accessibles.
- Utiliser l'inactivation plutôt que la suppression pour préserver l'historique.

### 2.5 Endpoints

```
GET    /fournisseurs          → liste avec pagination et filtre
GET    /fournisseurs/actifs   → liste simple pour les selects (création commande)
POST   /fournisseurs          → créer
PUT    /fournisseurs/{id}     → modifier
DELETE /fournisseurs/{id}     → désactiver
```

---

## 3. Commandes Fournisseur

### 3.1 Ce que c'est

Une **Commande Fournisseur** (appelée aussi "bon de commande" ou "PO — Purchase Order") est le document officiel qu'on envoie à un fournisseur pour lui demander de nous livrer des produits à un prix et une date convenus.

### 3.2 Champs de la commande

**En-tête (ComFourEntete)**

| Champ | Description |
|-------|-------------|
| `numero` | Ex: CF-2026-0001 (auto-généré) |
| `fournisseur_id` | Le fournisseur concerné |
| `date_commande` | Date de création de la commande |
| `date_livraison_prevue` | Date à laquelle on attend la livraison |
| `statut` | État actuel (voir section statuts) |
| `montant_total` | Somme de toutes les lignes |
| `devise` | Devise de la commande (héritée du fournisseur) |
| `taux_change` | Taux de change si devise ≠ devise entreprise |
| `notes` | Notes internes |

**Lignes (ComFourLigne)**

| Champ | Description |
|-------|-------------|
| `produit_id` | Le produit commandé |
| `quantite_commandee` | Quantité demandée au fournisseur |
| `quantite_recue` | Quantité effectivement reçue (mise à jour à chaque réception) |
| `prix_unitaire` | Prix d'achat unitaire HT |
| `montant_ligne` | `quantite_commandee × prix_unitaire` |
| `unite_achat` | Conditionnement (ex: "Carton") |
| `qte_par_unite_achat` | Quantité par conditionnement (ex: 12) |

### 3.3 Flow de création d'une commande

```
Étape 1 : Cliquer "Nouvelle commande"
Étape 2 : Sélectionner le fournisseur
          → La devise et les conditions paiement se pré-remplissent
Étape 3 : Définir la date et date de livraison prévue
Étape 4 : Ajouter les lignes de produits
          → Chercher le produit par numéro ou description
          → Saisir la quantité désirée
          → Le prix pré-remplit si le fournisseur a un prix enregistré
Étape 5 : Vérifier le total calculé
Étape 6 : Sauvegarder en brouillon
```

### 3.4 Statuts de la commande fournisseur

```
brouillon ──→ envoyee ──→ partielle ──→ complete
                   │                        ↑
                   └──────────────────→ annulee
```

| Statut | Signification | Ce qu'on peut faire |
|--------|---------------|---------------------|
| `brouillon` | En cours de rédaction | Modifier, supprimer, valider |
| `envoyee` | Envoyée au fournisseur | Enregistrer des réceptions, annuler |
| `partielle` | Réception(s) partielle(s) | Enregistrer d'autres réceptions, clôturer |
| `complete` | Tous les articles reçus | Lecture seule |
| `annulee` | Annulée | Lecture seule |

### 3.5 Validation / Envoi de commande

**Action :** Cliquer sur "Valider et envoyer"
**Endpoint :** `POST /commandes-fournisseur/{id}/valider`

Ce que ça fait :
1. Vérifie que la commande est en `brouillon`
2. Vérifie qu'il y a au moins une ligne
3. Passe le statut à `envoyee`
4. Enregistre `validee_par` et `date_validation`

> À noter : "Valider" dans l'application signifie "confirmer et envoyer". Il n'y a pas de step d'approbation intermédiaire côté achats (contrairement aux commandes client).

### 3.6 Clôture d'une commande partielle

**Action :** Cliquer sur "Clôturer"
**Endpoint :** `POST /commandes-fournisseur/{id}/cloturer`

Quand utiliser la clôture :
- Le fournisseur ne peut/veut pas livrer le reste
- On renonce aux articles manquants
- On veut fermer administrativement la commande

Ce que ça fait :
1. Passe le statut à `complete` (même si tout n'a pas été reçu)
2. Les quantités non reçues restent dans l'historique mais ne seront jamais réceptionnées

### 3.7 Annulation

**Endpoint :** `POST /commandes-fournisseur/{id}/annuler`
- Seulement possible en statut `envoyee`
- Le stock n'est pas affecté (rien n'a été reçu)

### 3.8 Suppression

**Endpoint :** `DELETE /commandes-fournisseur/{id}`
- Seulement possible en statut `brouillon`
- Suppression physique de la commande et ses lignes

### 3.9 PDF

**Endpoint :** `GET /commandes-fournisseur/{id}/pdf`
- Génère un PDF du bon de commande avec l'en-tête entreprise
- Peut être envoyé par email au fournisseur
- Accessible sans JWT (via token dans l'URL pour faciliter le partage)

### 3.10 Multi-devise

Si le fournisseur facture en USD mais que l'entreprise utilise EUR :
- Le `taux_change` est chargé automatiquement depuis l'API externe **Frankfurter** (`api.frankfurter.app`)
- Les montants sont stockés dans la devise du fournisseur
- La conversion s'affiche pour information

---

## 4. Réceptions (Arrivages)

### 4.1 Ce que c'est

Une **Réception** enregistre l'arrivée physique de marchandises dans l'entrepôt suite à une commande fournisseur. C'est l'étape qui fait entrer le stock dans le système.

**Concept important :** Une commande peut avoir **plusieurs réceptions**. Si on commande 100 unités et qu'elles arrivent en 3 livraisons (30, 40, 30), on fait 3 réceptions distinctes. Chaque réception met à jour les quantités reçues et le statut de la commande.

### 4.2 Champs d'une réception

| Champ | Description | Obligatoire |
|-------|-------------|-------------|
| `com_four_ligne_id` | La ligne de commande concernée | Oui |
| `date_reception` | Date d'arrivée physique | Oui |
| `quantite_recue` | Quantité reçue (≤ quantité restante à recevoir) | Oui |
| `secteur_id` | Où ranger la marchandise dans l'entrepôt | Non |
| `lot_numero` | Numéro de lot (traçabilité) | Non |
| `date_peremption` | Date de péremption si applicable | Non |
| `notes` | Remarques (ex: "3 boîtes abîmées") | Non |

### 4.3 Flow complet d'une réception

```
Étape 1 : Aller dans Réceptions → "Nouvelle réception"
Étape 2 : Sélectionner la commande fournisseur concernée
          → Seules les commandes en statut "envoyee" ou "partielle" s'affichent
Étape 3 : L'application affiche toutes les lignes avec quantités restantes à recevoir
          → Colonne "Commandé" | "Déjà reçu" | "Restant" | "À réceptionner"
Étape 4 : Saisir la quantité reçue pour chaque ligne
          → La quantité ne peut pas dépasser "Restant"
          → Possibilité de ne remplir qu'une partie des lignes (réception partielle)
Étape 5 : (optionnel) Assigner un secteur de rangement
Étape 6 : (optionnel) Saisir numéro de lot et date péremption
Étape 7 : Valider la réception
```

### 4.4 Ce qui se passe lors de la validation

```
Pour chaque ligne réceptionnée :
  1. Crée un enregistrement ReceptionArrivagesLigne
  2. Met à jour quantite_recue dans ComFourLigne
  3. Crée un mouvement de stock (entrée dans le secteur indiqué)
  4. Met à jour ProduitLocalisation (quantité dans ce secteur)

Après toutes les lignes :
  5. Recalcule le statut de la commande :
     - Si toutes les lignes sont complètes → statut = "complete"
     - Si au moins une ligne est incomplète → statut = "partielle"
```

### 4.5 Réception multiple simultanée

**Endpoint :** `POST /receptions/multiple`

Permet de soumettre plusieurs réceptions en une seule requête (pour un flow mobile plus rapide).

### 4.6 Impact sur l'inventaire

Dès qu'une réception est validée :
- Le produit apparaît dans le secteur assigné
- Son stock augmente du nombre d'unités reçues
- Un mouvement de type `entree_reception` est créé pour la traçabilité
- Si des alertes de stock bas existaient pour ce produit, elles se mettent à jour

---

## 5. Flow complet bout en bout

```
┌─────────────────────────────────────────────────────────────────┐
│                    MODULE ACHATS — FLOW COMPLET                  │
└─────────────────────────────────────────────────────────────────┘

1. PRÉREQUIS
   ✓ Fournisseur créé
   ✓ Produits du catalogue créés
   ✓ Secteurs de l'entrepôt créés

2. COMMANDE FOURNISSEUR
   → Créer commande (statut: brouillon)
   → Ajouter les lignes de produits
   → Vérifier le total
   → Valider et envoyer (statut: envoyee)

   ⚡ À ce stade, la commande est "en attente" — rien n'est en stock.

3. LIVRAISON DU FOURNISSEUR
   → Le fournisseur livre tout ou partie des marchandises

4. RÉCEPTION DANS L'ENTREPÔT
   → Sélectionner la commande concernée
   → Saisir les quantités arrivées
   → Assigner les secteurs de rangement
   → Valider la réception

   ⚡ À ce moment :
     - Si tout reçu → commande passe à "complete"
     - Si partiel  → commande passe à "partielle"
     - Le stock est mis à jour dans le secteur
     - Un mouvement de stock est tracé

5. SI LIVRAISON PARTIELLE
   → Répéter l'étape 4 pour les prochaines livraisons
   → OU clôturer la commande si on renonce au reste

6. FIN
   → Commande en statut "complete"
   → Stock à jour dans les secteurs
   → Traçabilité complète (qui a reçu quoi, quand, dans quel secteur)
```

---

## 6. Statuts et transitions

### Commande Fournisseur

```
                    [Nouvelle commande]
                           ↓
                       brouillon
                      ↙         ↘
                 [Valider]    [Supprimer]
                    ↓              ↓
                envoyee         (supprimé)
               ↙       ↘
          [Réception]  [Annuler]
              ↓              ↓
           partielle       annulee
              ↓
    [Nouvelle réception]
              ↓
    [Toutes lignes complètes ?]
           ↙         ↘
        OUI           NON
         ↓             ↓
      complete       partielle
                        ↓
                  [Clôturer] → complete
```

---

## 7. Impact sur le stock

### Types de mouvements créés

| Action | Type mouvement | Direction |
|--------|---------------|-----------|
| Réception d'arrivage | `entree_reception` | Entrée dans secteur |
| Relocalisation produit | `relocalisation` | Sortie secteur A / Entrée secteur B |
| Préparation BL | `sortie_preparation` | Sortie secteur → zone préparation |
| Livraison client | `livraison_client` | Sortie camion → client |

### Table des localisations

`produit_localisation` garde une vue en temps réel de **où se trouve chaque produit** :

| Colonne | Description |
|---------|-------------|
| `produit_id` | Le produit |
| `type_localisation` | `secteur`, `camion`, `zone_preparation` |
| `localisation_id` | L'ID du secteur/camion/zone |
| `quantite` | Quantité présente à cet endroit |

> Cette table est mise à jour automatiquement à chaque mouvement.

---

## 8. Multi-devise

### Principe

L'application gère plusieurs devises pour les achats internationaux.

### Devises supportées

`EUR`, `USD`, `XAF` (Franc CFA), `CAD`, `GBP`, et toute devise ISO 4217.

### Taux de change

- Chargé automatiquement depuis **Frankfurter API** (`api.frankfurter.app`)
- Endpoint interne : `GET /taux-change?from=USD&to=EUR`
- Le taux est stocké dans la commande au moment de sa création
- Le taux ne change pas rétroactivement — il est figé dans la commande

### Affichage

Les montants sont affichés :
1. Dans la devise de la commande (devise du fournisseur)
2. Avec conversion optionnelle dans la devise de l'entreprise

---

## Points d'attention globaux

1. **On ne peut pas réceptionner plus que ce qui est commandé.** La validation bloque si `quantite_recue > quantite_restante`.
2. **La suppression d'une commande envoyée est impossible.** On peut seulement annuler.
3. **Un fournisseur inactif ne peut pas recevoir de nouvelles commandes** mais ses commandes historiques restent visibles.
4. **Les mouvements de stock sont irréversibles.** Une réception validée ne peut pas être annulée (il faudrait un mouvement correctif).
