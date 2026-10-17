# ACHATS — Flow complet & détail de chaque étape

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-16 — retours fournisseurs et avoirs (§9)

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
9. [Retours fournisseurs et avoirs](#9-retours-fournisseurs-et-avoirs-achats--retours-fournisseurs)
10. [Suggestions d'achat](#10-suggestions-dachat-achats--suggestions-dachat)

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

Ce catalogue se gère dans l'onglet **Produits fournis** de la fiche fournisseur (un seul fournisseur principal par produit). Le formulaire de commande ne s'en sert pas encore pour pré-remplir le prix.

### 2.4 Fiche fournisseur

Un clic sur le code d'un fournisseur ouvre sa fiche : synthèse des achats et des livraisons, historique, produits fournis, contacts, adresses, coordonnées bancaires et remarques. Voir [FLOW-FICHE-FOURNISSEUR.md](./FLOW-FICHE-FOURNISSEUR.md).

### 2.5 Statut actif / inactif

- Un fournisseur **inactif** n'apparaît pas dans la liste de sélection lors de la création d'une commande.
- Ses commandes et historique restent accessibles.
- Utiliser l'inactivation plutôt que la suppression pour préserver l'historique.

### 2.6 Endpoints

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
| `numero` | Ex: BC-00001 : numérotation propre aux commandes fournisseur (`commande_fournisseur`), distincte de celle des commandes clients |
| `fournisseur_id` | Le fournisseur concerné |
| `date_commande` | Date de création de la commande |
| `date_livraison_prevue` | Date à laquelle on attend la livraison |
| `statut` | État actuel (voir section statuts) |
| `montant_total` | Somme de toutes les lignes |
| `devise` | Devise de la commande ; sans devise transmise, celle du fournisseur |
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

Si le fournisseur facture en USD mais que l'entreprise utilise une autre devise (XAF, CAD…) :
- Le choix du fournisseur reprend sa devise et propose un taux (voir §8), modifiable avant l'enregistrement.
- Sans taux disponible, le formulaire le demande : la commande n'est plus enregistrée au taux 1.
- Les montants sont stockés et affichés dans la devise du fournisseur, avec leur équivalent dans la devise de
  l'entreprise (« 25,00 $US ≈ 15 000 FCFA »).

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
- elle est rangée dans le secteur assigné ;
- elle ne crée pas de mouvement de stock : la prise d'inventaire suivante la compte comme une **entrée** de ce secteur (FLOW-PRISE-INVENTAIRE) ;
- la marchandise qui repart chez le fournisseur passe par un retour fournisseur (§9) ;
- les **reliquats clients** de la ligne reçue se mettent à jour, du plus ancien au plus récent (FLOW-VENTES §5.14). Annuler, supprimer ou clore une commande, ou retirer une ligne d'un brouillon, met aussi à jour ses reliquats.

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
| Réception d'arrivage | aucun : la réception, rangée dans un secteur, compte comme une entrée à la prise d'inventaire | Entrée dans secteur |
| Retour fournisseur expédié (ligne prise dans un secteur) | `sortie` (« Retour fournisseur RF-… ») | Sortie du secteur |
| Remplacement reçu à la clôture d'un retour | `arrivage` (« Remplacement fournisseur RF-… ») | Entrée dans le secteur choisi |
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

Endpoint interne : `GET /taux-change?from=USD&to=XAF`. Sources, dans l'ordre :
1. **Référentiel des devises de l'entreprise** (Paramètres › Référentiels › Devises) : chaque taux y exprime une
   unité de la devise dans la devise de référence. Un taux croisé passe par cette devise de référence. Réponse
   `source: "entreprise"`.
2. **Cours du jour de Frankfurter** (`api.frankfurter.app`), mis en cache pour la journée. Réponse
   `source: "frankfurter"`.
3. Sinon **404** avec un message : le formulaire demande le taux. Un échec du cours du jour (devise inconnue du
   service, comme XAF, ou service injoignable) est retenu dix minutes.

Le taux est stocké dans la commande au moment de sa création et ne change pas rétroactivement. Un taux nul ou
négatif est refusé (`taux_change` > 0).

### Affichage

Les montants sont affichés :
1. Dans la devise de la commande (devise du fournisseur)
2. Avec conversion optionnelle dans la devise de l'entreprise

## 9. Retours fournisseurs et avoirs (Achats → Retours fournisseurs)

Plan de parité fripv2, phase 3.4. Dans fripv2, un retour au fournisseur est une ligne à quantité négative d'une réception, ou un suivi de retour avec numéro RMA. Ici, c'est un document à part : un **retour fournisseur**.

**Origine du retour :**
- **une commande du fournisseur** : bouton **Retour au fournisseur** d'une commande envoyée, reçue en partie ou en totalité. Chaque ligne repart au plus dans la quantité reçue, moins ce qui en est déjà reparti ; le prix proposé est celui de la commande et le secteur celui de sa dernière réception ;
- **un retour client traité** (FLOW-VENTES §5.12) : action **Renvoyer au fournisseur**, par exemple pour des produits défectueux rapportés par un client :
  - les lignes mises au rebut sont proposées, hors stock ;
  - une ligne remise en stock peut aussi repartir, de son secteur ;
  - le fournisseur principal des produits est proposé ;
- **aucune** : **Nouveau retour**, n'importe quel produit.

**Type :**

| Type | Le fournisseur… | À la clôture |
|---|---|---|
| `remboursement` | crédite la marchandise | crédit obtenu et référence de son avoir |
| `remplacement` | renvoie une marchandise identique | entrée en stock du remplacement dans un secteur (facultative) |
| `consigne` | reprend un contenant, une palette, un emballage | rien d'attendu |

**Une ligne :**
- produit, quantité ;
- **prix crédité** : celui de la commande, sinon le dernier prix payé au fournisseur (ou son prix d'achat convenu) ;
- **secteur d'où la marchandise sort** du stock, ou **Hors stock** pour une marchandise qui n'y est pas (défectueuse et mise au rebut, en quarantaine) ;
- raison : raisons du référentiel de type « Retour fournisseur », **obligatoire dès que l'entreprise en a défini une**.

**En-tête :**
- fournisseur (celui de la commande pour un retour de commande) ;
- date du retour, **numéro RMA** (autorisation de retour donnée par le fournisseur, à reporter sur le colis) et notes ;
- **devise** : celle de la commande ; sinon celle du fournisseur, avec son taux de change (proposé, corrigeable ; 422 s'il est inconnu), comme les documents de vente (FLOW-VENTES §5.11).

**Statuts :**

| Statut | Signification | Actions |
|---|---|---|
| `brouillon` | Sans numéro, sans effet sur le stock | Modifier, Expédier, Supprimer, PDF |
| `expedie` | Numéroté, parti chez le fournisseur | Clôturer, PDF, Courriel |
| `clos` | Le fournisseur a répondu | PDF, Courriel |

**À l'expédition :**
1. les quantités sont contrôlées de nouveau : un autre retour de la même origine a pu partir entre-temps (422 « Au plus 0 (quantité reçue, retours déjà expédiés déduits). ») ;
2. le retour reçoit son numéro (numérotation `retour_fournisseur`, `RF-00001` par défaut, voir FLOW-PARAMETRES §7) ;
3. chaque ligne prise dans un secteur en **sort** : mouvement de stock `sortie`, motif « Retour fournisseur RF-00001 : Produit défectueux », daté du jour de l'expédition. Comme les retours clients, il ne passe pas par les seuils d'approbation (l'expédition est déjà une action réservée) ;
4. une ligne hors stock ne crée aucun mouvement ;
5. la commande d'origine n'est pas modifiée (ses quantités reçues restent celles reçues).

**À la clôture :**
- `remboursement` : **crédit obtenu** (le montant attendu est proposé) et **référence de l'avoir** du fournisseur ;
- `remplacement` : si un secteur est choisi, la marchandise de remplacement y **entre** (un arrivage par ligne, motif « Remplacement fournisseur RF-00001 ») ;
- `consigne` : simple clôture ;
- la date de clôture ne précède pas l'expédition.

**Crédits (avoirs) :**
- **attendus** : la valeur des retours `remboursement` expédiés et pas encore clos ;
- **obtenus** : les crédits notés à la clôture ;
- convertis dans la devise de l'entreprise au taux de chaque retour ;
- résumés en haut de la liste des retours et dans la synthèse de la **fiche fournisseur** (FLOW-FICHE-FOURNISSEUR) ;
- ils ne sont pas encore imputés sur des factures fournisseur : les comptes à payer viendront avec la phase 3.5.

**Documents :**
- **Bon de retour** (PDF) : coordonnées de l'entreprise et du fournisseur, numéro RMA en évidence, lignes au prix crédité, crédit attendu, crédit obtenu une fois clos ;
- **courriel** au fournisseur (type `retour_fournisseur`, bon de retour joint, adressé au courriel du fournisseur) ;
- **recherche globale** : par numéro, numéro RMA ou fournisseur.

| Route | Rôle |
|---|---|
| `GET /retours-fournisseurs` | Liste (statut, type, fournisseur, commande, retour client, période, recherche), avec `resume` |
| `GET /retours-fournisseurs/depuis-commande/{id}` | Ce qui peut repartir d'une commande |
| `GET /retours-fournisseurs/depuis-retour-client/{id}` | Ce qu'un retour client traité peut renvoyer |
| `GET /retours-fournisseurs/{id}` | Détail |
| `GET /retours-fournisseurs/{id}/pdf` | Bon de retour |
| `POST /retours-fournisseurs` | Brouillon : `commande_fournisseur_id` ou `retour_client_id` (ou aucun), `fournisseur_id`, `type`, `date_retour`, `numero_rma`, `devise`, `taux_change`, `notes`, `lignes[]` |
| `PUT /retours-fournisseurs/{id}` | Modifier un brouillon (son origine reste celle de sa création) |
| `POST /retours-fournisseurs/{id}/expedier` | Expédier (`date_expedition` facultative), action nommée « Expédier un retour fournisseur » |
| `POST /retours-fournisseurs/{id}/cloturer` | Clôturer : `date_cloture`, `montant_credite`, `reference_avoir`, `secteur_remplacement_id` ; action nommée « Clôturer un retour fournisseur » |
| `DELETE /retours-fournisseurs/{id}` | Supprimer un brouillon |

**Une ligne** (`lignes[]`) : `com_four_ligne_id`, `retour_client_ligne_id` ou `produit_id` selon l'origine, `quantite`, `prix_unitaire`, `secteur_id` (vide : hors stock), `raison_id`, `notes`.

**Droits** (module « Retours fournisseurs », onglet Achats) :
- à la mise à jour, chaque groupe reçoit ceux des commandes fournisseur ;
- « Expédier » reprend le réglage de « Valider une commande » ;
- « Clôturer » reprend celui de « Clôturer une commande ».

**Données :**
- tables `retours_fournisseurs` et `retour_fournisseur_lignes` ;
- chaque ligne garde la sortie créée à l'expédition (`mouvement_id`) et l'arrivage du remplacement (`mouvement_remplacement_id`).

Code :
- API : service `App\Services\RetoursFournisseurs`, PDF `App\Services\RetourFournisseurPdf`, migration `2026_10_16_100036_creer_retours_fournisseurs` ;
- web : page `RetoursFournisseursPage`, composants `retoursFournisseurs/EditeurRetourFournisseur` et `retoursFournisseurs/CloturerRetourFournisseur`.

---

## 10. Suggestions d'achat (Achats → Suggestions d'achat)

Plan de parité fripv2, phase 3.2 (« Suggestion de commandes aux fournisseurs ») : PI propose ce qu'il faut commander, par fournisseur, et les suggestions retenues deviennent des commandes fournisseur.

**Origines d'une suggestion :**
- **Inventaire.** Un produit actif dont le disponible passe sous son **seuil d'alerte** (le minimum) est proposé.
  - Le disponible est le stock compté (les scans) moins ce que réservent les commandes client acceptées. On peut y ajouter ce qui est **déjà en commande** : commandes envoyées, reçues en partie, et commandes en préparation.
  - La quantité suggérée remonte le stock jusqu'au **maximum** du produit, ou jusqu'au seuil s'il n'a pas de maximum.
  - Le minimum et le maximum se règlent dans Inventaire → Alertes de stock → Configurer seuils (`produits.stock_maximum`) ; le maximum ne peut pas être sous le seuil.
- **Reliquat client.** Ce qu'un client attend (reliquat inscrit, FLOW-VENTES §5.14) et qui n'est pas encore commandé au fournisseur. La quantité est celle du reliquat ; le client, la commande et la date requise sont rappelés.
- **Manuelle.** Un besoin signalé depuis la liste des produits (« Suggérer l'achat ») : quantité et remarque, en attente de la décision de l'acheteur. Une suggestion ouverte du même produit est signalée ; on peut tout de même en créer une seconde.

**Calculer les suggestions :** sources choisies (inventaire, reliquats clients, en tenant compte ou non des commandes en cours), éventuellement pour un seul fournisseur.
- Un nouveau calcul remplace les suggestions en attente qui venaient d'un calcul ; les acceptées et les manuelles restent.
- Un produit qui a déjà une suggestion ouverte n'est pas proposé une seconde fois.

**Liste, regroupée par fournisseur :**
- **Colonnes :** produit, origine, disponible, minimum et maximum, en commande, quantité vendue sur 12 mois, quantité suggérée, **quantité à commander** (modifiable), dernier coûtant (dernier prix payé au fournisseur, sinon le prix d'achat convenu), fournisseur (modifiable), remarque, commis.
- **Fournisseur :** c'est le fournisseur principal du produit. Un produit sans fournisseur principal doit en recevoir un avant d'être commandé.
- **Accepter** une suggestion retient sa quantité (la suggérée par défaut) et le nom de qui accepte. « Tout accepter » et « Tout remettre en attente » agissent sur toutes les lignes.
- Une suggestion en attente ou acceptée se retire ; une commandée, non.

**Générer les commandes :** les suggestions acceptées deviennent des commandes fournisseur **brouillons** (en préparation).
- Une commande par fournisseur, une ligne par produit, au dernier prix payé. Avec « Ajouter aux commandes en préparation du fournisseur », les lignes vont dans la commande brouillon la plus récente du fournisseur ; une ligne du même produit y est augmentée.
- Un reliquat commandé est rattaché à sa ligne et passe « commandé ». Si la commande est annulée, il redevient inscrit et réapparaît dans les suggestions.
- L'onglet « Commandées (30 jours) » montre chaque suggestion avec le numéro de sa commande.

**Droits :** module **Suggestions d'achat** (`suggestions_achat`), copié des commandes fournisseur à sa création.
- **Gérer les suggestions d'achat** (`suggestions_achat.gerer`) : calculer, accepter, modifier, retirer, commander. Il suit le droit de créer une commande fournisseur, droit que commander demande aussi (le contrôleur le vérifie).
- **Créer une suggestion d'achat** (`suggestions_achat.suggerer`) : signaler un besoin depuis un produit, sans pouvoir commander soi-même. Il est accordé à qui voit les produits.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/suggestions-achat?statut=ouvertes\|commandees` | Suggestions avec disponible, en commande, vendu 12 mois, dernier coûtant |
| POST | `/suggestions-achat/generer` | Calcul (`inventaire`, `reliquats`, `avec_commandes`, `fournisseur_id`, `produit_id`) |
| POST | `/suggestions-achat` | Suggestion manuelle (`produit_id`, `quantite`, `remarque`, `forcer`) |
| POST | `/suggestions-achat/accepter` | Accepter ou remettre en attente (`ids`, `accepte`) |
| PUT / DELETE | `/suggestions-achat/{id}` | Quantité, fournisseur, remarque ; retirer |
| POST | `/suggestions-achat/commander` | Commandes fournisseur des suggestions acceptées (`fusionner`) |

Code :
- API : service `App\Services\SuggestionsAchat` (le fournisseur principal et le dernier prix viennent de `ReliquatsClients`), `SuggestionAchatController`, migration `2026_10_17_100055_creer_suggestions_achat` (`produits.stock_maximum`, table `suggestions_achat`, droits du module) ;
- web : page `SuggestionsAchatPage`, composant `achats/SuggererAchat` (liste des produits), colonne Maximum dans les seuils des alertes.

---

## Points d'attention globaux

1. **On ne peut pas réceptionner plus que ce qui est commandé.** La validation bloque si `quantite_recue > quantite_restante`.
2. **La suppression d'une commande envoyée est impossible.** On peut seulement annuler.
3. **Un fournisseur inactif ne peut pas recevoir de nouvelles commandes** mais ses commandes historiques restent visibles.
4. **Les mouvements de stock sont irréversibles.** Une réception validée ne peut pas être annulée (il faudrait un mouvement correctif).
