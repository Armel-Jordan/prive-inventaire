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
11. [Import d'une grille de prix fournisseur](#11-import-dune-grille-de-prix-fournisseur-achats--fournisseurs)
12. [Factures fournisseurs](#12-factures-fournisseurs-achats--factures-fournisseurs)
13. [Déboursés](#13-déboursés-achats--déboursés)

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
| `envoyee_le`, `envoyee_par` | Jour, heure et auteur de l'envoi au fournisseur (« Commandée le » de fripv2), notés quand la commande quitte le brouillon ; vides pour les commandes envoyées avant leur introduction |
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

La commande quitte le brouillon de deux façons, comme dans fripv2 (bouton « Commander » ou envoi par courriel) :
- le bouton **« Valider et envoyer »** (`POST /commandes-fournisseur/{id}/valider`, droit `commandes_fournisseur.valider`) ;
- l'**envoi du bon de commande par courriel** (bouton enveloppe de la liste ou du détail de la commande).

Ce que ça fait (service `App\Services\CommandesFournisseur`) :
1. Vérifie que la commande est en `brouillon` (sinon 422) ;
2. Passe le statut à `envoyee` ;
3. Note le jour et l'auteur de l'envoi (`envoyee_le`, `envoyee_par`), affichés dans le détail (« Envoyée le … par … ») et imprimés sur le bon (« Commandée le ») quand ce n'est pas le jour de la commande ;
4. Inscrit le prix de chaque ligne à l'historique des prix d'achat.

**Envoi par courriel** (type `commande_fournisseur`, bon de commande joint) :
- **Destinataires proposés :** les contacts actifs du fournisseur qui **reçoivent les bons de commande** (case de leur fiche), le contact principal d'abord ; sans contact coché, le courriel du fournisseur. La liste reste modifiable.
- **Commande au brouillon :** la fenêtre prévient que l'envoi la valide. Il faut le droit de valider la commande (`commandes_fournisseur.valider`, sinon 403). Le PDF joint part tel que la commande sera une fois envoyée, sans la mention « Brouillon ». La commande n'est validée qu'une fois le courriel parti : un envoi en échec la laisse au brouillon, et sa relance la valide au nom de celui qui l'avait envoyé.
- **Commande déjà envoyée :** le courriel peut repartir (relance, copie), sans rien changer à la commande.

> Même principe côté clients : la facture, sa relance, la note de crédit et la demande d'acompte sont proposées aux contacts du client qui **reçoivent les factures**, la commande client à ceux qui **reçoivent les commandes** ; sans contact coché, au courriel du client.

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

Depuis la phase 3.3 du plan de parité fripv2 (« Réception fournisseur »), chaque livraison est un **document numéroté** (`REC-00001`, numérotation « Réceptions » de Configuration → Numérotation) :
- il regroupe ses lignes, d'une ou de **plusieurs commandes envoyées du même fournisseur** (un camion qui livre deux commandes) ;
- il porte le **bon de livraison** du fournisseur, le **connaissement** du transporteur et une note ;
- il se retrouve dans l'**historique**, se **corrige** et s'**annule**.

Les réceptions enregistrées avant n'ont pas de document et comptent comme avant.

### 4.2 Champs d'une réception

**Document (`receptions`)** : `numero`, `fournisseur_id`, `date_reception`, `bon_livraison`, `connaissement`, `notes`, `statut` (`enregistree` ou `annulee`), `recue_par`, et pour une annulation `annulee_le`, `annulee_par`, `motif_annulation`.

**Lignes (`reception_arrivages_ligne`)** :

| Champ | Description | Obligatoire |
|-------|-------------|-------------|
| `reception_id` | Le document de la réception (vide pour les réceptions antérieures) | — |
| `com_four_ligne_id` | La ligne de commande concernée | Oui |
| `date_reception` | Date d'arrivée physique | Oui |
| `quantite_recue` | Quantité reçue, en unité d'achat (≤ reste à recevoir, sauf sur-réception confirmée) | Oui |
| `cout_unitaire` | Coût unitaire facturé, dans la devise de la commande et par unité d'achat ; vide = prix commandé | Non |
| `secteur_id` | Où ranger la marchandise dans l'entrepôt | Non |
| `lot_numero` | Numéro de lot (traçabilité) | Non |
| `date_peremption` | Date de péremption si applicable | Non |
| `notes` | Remarques (ex: "3 boîtes abîmées") | Non |
| `annulee` | Ligne d'une réception annulée : elle ne compte plus nulle part | — |

Une même ligne de commande peut revenir plusieurs fois dans une réception : une fois par **secteur** où la marchandise est rangée.

### 4.3 Flow complet d'une réception

```
Étape 1 : Achats → Réceptions, onglet « À recevoir »
Étape 2 : Sélectionner la commande fournisseur concernée, puis « Réceptionner »
          → Seules les commandes en statut "envoyee" ou "partielle" s'affichent
Étape 3 : La fenêtre reprend les lignes en attente, quantités pré-remplies avec le reste à recevoir
          → « Tout recevoir » / « Tout à zéro »
          → « Ajouter une commande du même fournisseur » si la livraison en couvre plusieurs
Étape 4 : Saisir le bon de livraison et le connaissement (facultatifs)
Étape 5 : Ligne par ligne : quantité reçue, coût facturé (pré-rempli avec le prix commandé), secteur, lot,
          péremption, remarque ; « Répartir » range une partie de la ligne dans un autre secteur
Étape 6 : (optionnel) Cocher « Clôturer » sur une ligne dont le reste ne viendra pas
Étape 7 : Enregistrer → le document est numéroté
```

### 4.4 Ce qui se passe à l'enregistrement

Tout ou rien (service `App\Services\ReceptionsFournisseur`) :

```
Contrôles :
  - les lignes appartiennent à des commandes envoyées ou partielles d'un seul fournisseur ;
  - la quantité d'une ligne de commande (toutes répartitions confondues) ne dépasse pas son reste,
    sauf sur-réception confirmée (accepter_surplus) ;
  - la numérotation des réceptions est active.

Pour chaque ligne réceptionnée :
  1. Crée la ligne de réception rattachée au document
  2. Met à jour quantite_recue dans ComFourLigne et le statut de la commande
  3. Répartit la quantité sur les reliquats clients de la ligne (FLOW-VENTES §5.14)
  4. Coût facturé différent du prix commandé : inscrit à l'historique des prix d'achat et, si la commande est dans
     la devise du fournisseur, au catalogue du fournisseur (journal « Prix fournisseur », source « Réception REC-… »)

Lignes clôturées :
  5. La quantité commandée est ramenée au reçu ; le reste est noté abandonné (quantite_abandonnee),
     visible dans le détail de la commande (« 4 abandonné(s) »)
  6. Les reliquats clients de la ligne reçus en partie deviennent facturables ; les autres redeviennent inscrits
  7. Une commande dont rien n'a été reçu ne se clôture pas ligne par ligne : on l'annule
```

La clôture d'une commande entière (« Clôturer le reste », §3.6) note aussi le reste abandonné de ses lignes.

### 4.5 Endpoints

| Méthode | Route | Rôle |
|---|---|---|
| POST | `/receptions/multiple` | Réception d'une livraison : `date_reception`, `bon_livraison`, `connaissement`, `notes`, `accepter_surplus`, `receptions[]` (ligne, quantité, `cout_unitaire`, secteur, lot, péremption, note), `clotures[]` (lignes à clôturer). Clôturer sans rien recevoir ne crée pas de document. |
| POST | `/receptions` | Réception d'une seule ligne (même traitement, un document d'une ligne) |
| GET | `/receptions/documents` | Historique : `recherche` (numéro, bon de livraison, connaissement, fournisseur, commande), `fournisseur_id`, `commande_id`, `produit_id`, `statut`, `date_debut`, `date_fin` ; avec les commandes et la valeur reçue |
| GET | `/receptions/documents/{id}` | Détail, lignes avec coût retenu et montant |
| PUT | `/receptions/documents/{id}` | Correction : date, bon de livraison, connaissement, note, coût des lignes (jamais les quantités) |
| POST | `/receptions/documents/{id}/annuler` | Annulation, avec un motif |
| GET | `/receptions/documents/{id}/pdf` | Bon de réception |
| GET | `/receptions` | Lignes reçues (journal comptable), sans celles des réceptions annulées |

### 4.6 Correction et annulation

- **Corriger** (droit « Modifier » des réceptions) : date, références, note et coût des lignes. La valorisation suit le nouveau coût. Les quantités ne se corrigent pas, car elles sont déjà reportées sur les commandes : on annule la réception et on la refait.
- **Annuler** (droit « Annuler une réception », `receptions.annuler`, réglé à sa création comme le droit de supprimer des réceptions) :
  - ses quantités sont retirées des lignes de commande, qui redeviennent à recevoir ;
  - le statut des commandes est recalculé (envoyée, partielle ou complète) ;
  - les reliquats clients rendent ce qu'ils avaient reçu en trop, du plus récent au plus ancien ;
  - ses lignes ne comptent plus nulle part.
- **L'annulation est refusée** si la marchandise est déjà repartie au fournisseur (retour expédié), déjà facturée au client d'un reliquat, ou si la réception est facturée par une facture fournisseur (§12 : on annule d'abord la facture). Une ligne clôturée par la réception n'est pas rouverte.

### 4.7 Impact sur l'inventaire

Dès qu'une réception est validée :
- elle est rangée dans le secteur assigné ;
- elle ne crée pas de mouvement de stock : la prise d'inventaire suivante la compte comme une **entrée** de ce secteur, **en unités de stock** (2 cartons de 12 comptent 24 unités), sans les réceptions annulées (FLOW-PRISE-INVENTAIRE) ;
- la marchandise qui repart chez le fournisseur passe par un retour fournisseur (§9) ;
- les **reliquats clients** de la ligne reçue se mettent à jour, du plus ancien au plus récent (FLOW-VENTES §5.14). Annuler, supprimer ou clore une commande, ou retirer une ligne d'un brouillon, met aussi à jour ses reliquats ;
- la **valorisation de l'inventaire** prend, pour une ligne de commande, le coût réel de ses réceptions qui en portent un (moyenne pondérée par les quantités reçues), sinon le prix commandé, plus les **coûts annexes** répartis sur ses réceptions (§4.8) ;
- une **sur-réception** ne rend pas négatif ce qui reste en commande (disponibilité, suggestions d'achat).

### 4.8 Coûts annexes répartis

Transport, douane, assurance, manutention… : ce qu'il a fallu payer pour faire venir la marchandise s'ajoute à son **coût de revient** (plan de parité, phase 3.3 « coûts annexes répartis »).

**Saisie** : avec la réception (section « Coûts annexes » de la fenêtre), ou plus tard en la corrigeant, par exemple quand la facture du transitaire arrive. Chaque coût porte :
- sa nature (transport, douane, assurance, manutention, autre) et un libellé ;
- son montant, dans la devise de l'entreprise ou dans celle d'une commande reçue, avec son taux (pré-rempli avec celui de la commande) ;
- qui le facture (un fournisseur : transporteur, transitaire) et la référence de sa facture ;
- sa **répartition** sur les lignes de la réception.

**Répartition** (service `ReceptionsFournisseur::repartirFrais`), au prorata de :

| Base | Calcul par ligne |
|---|---|
| Valeur | quantité × coût (réel, sinon commandé) × taux de la commande, dans la devise de l'entreprise |
| Quantité | unités de stock reçues (2 cartons de 12 = 24) |
| Poids | unités de stock × poids de la fiche produit |
| Volume | unités de stock × volume de la fiche produit |

- Chaque ligne retient ses coûts annexes **par unité de stock**, dans la devise de l'entreprise (`frais_unitaire`). Ils sont recalculés à chaque changement : coûts annexes modifiés, ou coût d'une ligne corrigé si la répartition est à la valeur.
- Une base nulle est refusée, avec le motif : par exemple, aucun produit de la réception n'a de poids sur sa fiche.
- Les coûts annexes ne s'enregistrent qu'avec une réception, pas avec une simple clôture de ligne.

**Résultat :**
- Le détail de la réception affiche, par ligne, les coûts annexes et le coût de revient par unité, et en pied la valeur de la marchandise, les coûts annexes et le **coût de revient** total.
- Le bon de réception PDF les reprend.
- La **valorisation de l'inventaire** ajoute à chaque ligne de commande la moyenne de ses coûts annexes par unité de stock reçue.

Table `reception_frais` (migration `2026_10_17_100059_couts_annexes_receptions`) ; `reception_arrivages_ligne.frais_unitaire`. Les coûts annexes se saisissent avec le droit de créer une réception, et se corrigent avec celui de la modifier.

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

## 11. Import d'une grille de prix fournisseur (Achats → Fournisseurs)

Plan de parité fripv2, phase 3.6 (« Importer une grille de prix fournisseur ») : le fichier de prix reçu d'un fournisseur met à jour, en une fois, le prix d'achat des produits de son catalogue (fiche fournisseur, onglet Produits fournis).

**Fichier :** un CSV, avec le séparateur deviné (point-virgule, tabulation ou virgule). Un classeur Excel s'enregistre d'abord au format CSV.

**Réglages** (bouton « Importer une grille de prix ») :
- Les colonnes du fichier se choisissent dans un aperçu de ses premières lignes. Elles sont devinées d'après les en-têtes (fournisseur, article, coût).
- **Fournisseur** : une colonne du code fournisseur (plusieurs fournisseurs dans un fichier), ou un fournisseur fixé pour tout le fichier.
- **Code de l'article** : la colonne qui identifie l'article.
- **Coût d'achat** : la colonne du coût. Les formes « 1 234,56 », « 1234.56 » et « 1.234,56 € » sont lues.
- **Ligne de début** des données : 2 par défaut, la ligne 1 étant celle des en-têtes.
- Les réglages se **mémorisent** sous un nom (profils d'import de l'entreprise) et se rechargent à l'import suivant.

**Règles :**
- L'article se cherche d'abord par la **référence du fournisseur** (au catalogue de ce fournisseur), puis par le **numéro de produit**. Dans les deux cas, il doit figurer au catalogue du fournisseur, car c'est là que le prix est inscrit.
- Une ligne est **ignorée**, avec son motif, dans ces cas :
  - fournisseur introuvable avec ce numéro ;
  - code article vide ;
  - coût illisible ou négatif ;
  - produit introuvable ;
  - produit non rattaché à ce fournisseur ;
  - article déjà présent plus haut dans le fichier.
- **Simuler** donne le rapport sans rien écrire : prix à changer (ancien → nouveau), prix inchangés, lignes ignorées.
- **Importer** applique les prix reconnus d'un seul tenant, et chaque changement est journalisé (« Prix fournisseur », ancien et nouveau prix).
- L'import ne crée ni produit ni lien fournisseur.

**Droits :** **Importer une grille de prix fournisseur** (`fournisseurs.importer_prix`), qui couvre la simulation, l'import et les profils. À sa création, il est réglé comme le droit de modifier les fournisseurs.

| Méthode | Route | Rôle |
|---|---|---|
| POST | `/fournisseurs/import-prix` | Simulation ou import : `fichier`, `fournisseur_id` ou `colonne_fournisseur`, `colonne_code`, `colonne_cout`, `ligne_debut`, `simuler` |
| GET / POST | `/profils-import?type=prix_fournisseur` | Profils d'import (le POST remplace un profil de même nom) |
| DELETE | `/profils-import/{id}` | Supprimer un profil |

Code :
- API : service `App\Services\ImportPrixFournisseurs`, `ImportPrixFournisseurController`, migration `2026_10_17_100056_import_prix_fournisseurs` (table `profils_import`, action du droit) ;
- web : composant `fournisseurs/ImportGrillePrix`, service `importPrix.ts`.

---

## 12. Factures fournisseurs (Achats → Factures fournisseurs)

Plan de parité fripv2, phase 3.5 (« Compte à payer ») : la facture reçue d'un fournisseur, rapprochée de la marchandise reçue, avec ses taxes, son échéance et ses paiements.

**Saisie** (« Nouvelle facture », ou cases cochées dans l'historique des réceptions puis « Facturer les réceptions ») :
- en-tête : fournisseur, numéro de la facture chez lui, date, condition de paiement, devise (celle de l'entreprise ou une autre, avec son taux), note ;
- lignes : description, quantité, prix unitaire HT, taxe (taux de la ligne, sinon groupe de taxes par défaut, sinon taux de l'entreprise) ;
- **depuis des réceptions** d'un même fournisseur, pas encore facturées : une ligne par ligne reçue, à la quantité reçue et au coût réel (sinon au prix commandé), dans la devise des commandes. Des réceptions dans des devises différentes se facturent séparément ;
- l'**échéance** se calcule d'après la condition de paiement choisie, sinon celle par défaut de l'entreprise (fin de mois comprise). Elle peut aussi se saisir ;
- une même facture du fournisseur (même numéro) ne s'enregistre pas deux fois ;
- numérotation « Factures fournisseurs » (`FF-00001`, Configuration → Numérotation).

**Réceptions facturées** :
- elles portent la facture qui les règle (« Facturée FF-00001 » dans l'historique et le détail, filtre « Facturation » : à facturer / facturées) ;
- elles ne s'annulent plus tant que la facture n'est pas annulée.

**Rapprochement** :
- pour chaque ligne qui facture une ligne de réception : valeur reçue (quantité reçue × coût réel, sinon prix commandé) et **écart** avec le montant facturé ; écart total de la facture ;
- « **Aligner le coût des réceptions sur la facture** » : le coût réel des lignes reçues prend le prix facturé, comme l'ajustement des coûtants de fripv2. La valorisation de l'inventaire et les coûts annexes répartis à la valeur suivent. Seules les lignes dans la devise de leur commande sont alignées.

**Paiements et état** :
- un paiement (date, montant, mode : virement, chèque, espèces, carte, autre ; référence) va jusqu'au reste à payer ; un paiement saisi par erreur s'annule ;
- l'**état** se calcule : impayée, payée en partie, payée, **en retard** (reste à payer et échéance passée, avec le nombre de jours), annulée ;
- une facture qui a reçu un paiement ne se modifie plus et ne s'annule pas (ses paiements s'annulent d'abord) ;
- annulée (avec un motif), elle libère ses réceptions.

**Liste** : reste à payer et montant en retard (dans la devise de l'entreprise), recherche (numéro, numéro chez le fournisseur, fournisseur), filtres par état et par période. La facture du fournisseur (PDF, photo) se joint au document (pièces jointes).

**Droits** : module **Factures fournisseurs** (`factures_fournisseurs` : voir, créer, modifier ; aligner les coûts relève de « modifier »), réglé à sa création comme les commandes fournisseur ; actions **Payer une facture fournisseur** (`payer`, réglée comme l'encaissement des factures) et **Annuler une facture fournisseur** (`annuler`, réglée comme la suppression des commandes fournisseur).

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/factures-fournisseurs` | Liste (`recherche`, `fournisseur_id`, `etat` : impayee, partielle, payee, en_retard, annulee, a_payer ; `date_debut`, `date_fin`) avec le `resume` (à payer, en retard) |
| GET | `/factures-fournisseurs/proposition?receptions[]=` | Facture proposée depuis des réceptions |
| POST / PUT | `/factures-fournisseurs[/{id}]` | Enregistrer (la modification est refusée après un paiement) |
| GET | `/factures-fournisseurs/{id}` | Détail : lignes avec `valeur_recue` et `ecart`, taxes, paiements, réceptions |
| POST | `/factures-fournisseurs/{id}/aligner-couts` | Aligner le coût réel des réceptions sur la facture |
| POST / DELETE | `/factures-fournisseurs/{id}/paiements[/{paiement}]` | Payer, annuler un paiement |
| POST | `/factures-fournisseurs/{id}/annuler` | Annuler la facture (motif) |

Code :
- API : service `App\Services\FacturesFournisseurs`, `FactureFournisseurController`, migration `2026_10_17_100060_creer_factures_fournisseurs` (tables `factures_fournisseurs`, `facture_fournisseur_lignes`, `facture_fournisseur_paiements`, `receptions.facture_fournisseur_id`, droits) ;
- web : page `FacturesFournisseursPage`, composants `facturesFournisseurs/*`, service `facturesFournisseurs.ts`.

Les écritures comptables des achats sont passées par la comptabilisation automatique (phase 4.3). Les paiements répartis sur plusieurs factures, avec escompte, sont les déboursés (§13).

À venir : les frais d'achat de fripv2 (surcharges par produit ou par document).

---

## 13. Déboursés (Achats → Déboursés)

Plan de parité fripv2, phase 4.5 (« Déboursé à un fournisseur ») : l'argent versé à un fournisseur en une fois (un chèque, un virement…) se répartit sur plusieurs de ses factures, avec l'escompte qu'il accorde pour un paiement rapide.

**Saisir un déboursé** (bouton **Nouveau déboursé**) :
- **Fournisseur, date, mode** (chèque, virement, espèces, carte, autre), **référence** (n° de chèque, de virement) et **devise**. La devise est celle du fournisseur par défaut ; seules ses factures dans cette devise sont proposées.
- **Factures à payer** : enregistrées, avec un reste à payer. Elles sont listées l'échéance la plus ancienne d'abord (sans échéance, à la date de la facture), avec leur retard.
- **Escompte offert** : selon la condition de paiement de la facture (« 2/10 Net 30 »).
  - Le taux s'applique au montant TTC, au plus ce qui reste dû.
  - Il vaut jusqu'à la date de la facture plus les jours d'escompte (le dernier jour compte), pour un paiement à la date du déboursé.
  - Il n'est offert qu'une fois par facture.
  - La colonne affiche le montant et la date limite, ou « expiré ».
- **Répartition**, par facture : l'argent payé et l'escompte obtenu. Ensemble, ils ne dépassent pas le reste dû.
  - La case **Tout** règle le reste : l'escompte offert, et l'argent pour le solde.
  - L'escompte se corrige à la main (le fournisseur peut en accorder un autre).
- **Montant du déboursé** : l'argent versé, somme des parts payées. Un escompte seul ne fait pas un déboursé.
- **Contrôles** :
  - factures du fournisseur, enregistrées (pas annulées), dans la devise du déboursé, chacune une seule fois ;
  - pas de date avant celle d'une facture, ni plus tard que demain.
- **Numéro** : `DEB-00001` (Configuration → Numérotation). Il n'est consommé qu'à l'enregistrement.

**Ce qui est enregistré** :
- Chaque part devient un **paiement de sa facture**, au mode et à la référence du déboursé (à défaut, son numéro), avec l'escompte obtenu.
- Le **réglé** de la facture compte l'argent versé et les escomptes. Le reste à payer et l'état (payée, en partie, en retard) suivent.
- Dans le détail de la facture, chaque paiement montre son escompte et son déboursé.
- Une part de déboursé ne s'annule pas seule depuis la facture : on annule le déboursé.
- Le paiement simple d'une facture fournisseur (sans escompte) reste possible.

**Détail d'un déboursé** :
- factures réglées (leur numéro et le nôtre), escomptes obtenus, montant versé ;
- **avis de paiement** (PDF) à transmettre au fournisseur avec le chèque ou le virement : ses factures réglées, l'escompte et le montant versé ;
- pièces jointes (copie du chèque, avis de virement) ;
- **corriger** : la référence et les notes (les paiements des factures suivent la référence). Le reste se corrige en annulant puis en refaisant le déboursé.

**Annuler un déboursé** (avec un motif) :
- ses paiements et ses escomptes sont retirés des factures, qui redeviennent dues ;
- le déboursé reste dans la liste, barré, avec la date, l'auteur et le motif.

**Liste** : recherche par numéro, référence, fournisseur ou facture (la nôtre ou celle du fournisseur) ; filtres mode, statut et période ; totaux des déboursés valides (montant versé, escomptes obtenus) dans la devise de l'entreprise.

**Comptabilité** : la comptabilisation automatique passe un déboursé en **une écriture**. Chaque facture réglée est au débit des fournisseurs ; l'argent versé (caisse ou banque selon le mode) et l'escompte obtenu (compte « Escomptes obtenus » des réglages) sont au crédit. Un déboursé annulé fait contre-passer son écriture (voir FLOW-COMPTABILITE).

**Droits** : module **Déboursés** (`debourses`), réglé pour chaque groupe comme le paiement des factures fournisseurs.
- **Consulter** : liste, détail, avis de paiement ; les lectures de `/debourses` sont contrôlées.
- **Saisir** : créer.
- **Corriger** : modifier.
- **Annuler un déboursé** : `debourses.annuler`.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/debourses` | Liste (`recherche`, `fournisseur_id`, `mode`, `statut`, `date_debut`, `date_fin`) et `resume` |
| GET | `/debourses/a-payer?fournisseur_id=&devise=&date=` | Factures à payer du fournisseur dans la devise, avec l'escompte offert à la date |
| POST | `/debourses` | Saisie (`fournisseur_id`, `date_debourse`, `mode`, `reference`, `devise`, `taux_change`, `notes`, `lignes[]` : `facture_fournisseur_id`, `montant` (argent), `escompte`) |
| GET / PUT | `/debourses/{id}` | Détail ; correction (`reference`, `notes`) |
| POST | `/debourses/{id}/annuler` | Annulation (`motif`) |
| GET | `/debourses/{id}/pdf` | Avis de paiement |

Code : services `App\Services\Debourses` et `AvisPaiementPdf`, `DebourseController`, vue `pdf/avis-paiement` ; web : page `DeboursesPage`, composants `debourses/FenetreDebourse` et `debourses/DetailDebourse`, service `src/services/debourses.ts` ; migration `2026_10_17_100064_creer_debourses` (tables `debourses`, `debourse_lignes`, colonnes `facture_fournisseur_paiements.escompte` et `.debourse_id`, `parametres_comptables.compte_escomptes_obtenus_id`, droits du module).

À venir : le rapport des paiements dus et le paiement d'une sélection de factures en un geste, l'impression des chèques et les fichiers de paiement bancaires (phase 4.5).

---

## Points d'attention globaux

1. **On ne peut pas réceptionner plus que ce qui est commandé.** La validation bloque si `quantite_recue > quantite_restante`.
2. **La suppression d'une commande envoyée est impossible.** On peut seulement annuler.
3. **Un fournisseur inactif ne peut pas recevoir de nouvelles commandes** mais ses commandes historiques restent visibles.
4. **Les mouvements de stock sont irréversibles.** Une réception validée ne peut pas être annulée (il faudrait un mouvement correctif).
