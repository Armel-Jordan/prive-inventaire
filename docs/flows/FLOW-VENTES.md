# VENTES — Flow complet & détail de chaque étape

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-16 — relances de paiement (§7 ter)

---

## TABLE DES MATIÈRES

1. [Vue d'ensemble du module](#1-vue-densemble-du-module)
2. [Isolation multi-tenant](#2-isolation-multi-tenant)
3. [Clients](#3-clients)
4. [Devis](#4-devis)
5. [Commandes Client](#5-commandes-client)
6. [Factures](#6-factures)
7. [Bons de Livraison](#7-bons-de-livraison)
8. [Camions](#8-camions)
9. [Tournées](#9-tournées)
10. [Flow complet bout en bout](#10-flow-complet-bout-en-bout)
11. [Tous les statuts et transitions](#11-tous-les-statuts-et-transitions)
12. [Livraison partielle — cas particulier](#12-livraison-partielle--cas-particulier)
13. [Documents PDF](#13-documents-pdf)

---

## 1. Vue d'ensemble du module

Le module **Ventes** couvre tout le cycle commercial, de la proposition commerciale jusqu'à la livraison chez le client.

```
VENTES
├── Clients           ← qui achète
├── Devis             ← proposition commerciale
├── Commandes Client  ← confirmation d'achat
├── Factures          ← document de facturation + paiement
├── Bons de Livraison ← préparation des articles à livrer
├── Camions           ← véhicules de livraison
└── Tournées          ← regroupement de plusieurs BL pour un même camion
```

**Le flow principal :**

```
Client → Devis → Commande → Facture → Bon de Livraison → Tournée → Livraison
```

Chaque étape est optionnelle selon les cas :
- On peut créer une Commande sans passer par un Devis
- On peut créer une Facture directement
- Un Bon de Livraison peut être livré hors tournée

---

---

## 2. Isolation multi-tenant

Toutes les tables du module Ventes contiennent une colonne `tenant_id`. Chaque enregistrement appartient à un seul tenant (entreprise). Les données ne sont jamais partagées entre tenants.

### Tables concernées

| Table | tenant_id | Rôle |
|-------|-----------|------|
| `clients` | ✅ | Client appartient à un tenant |
| `com_client_entete` | ✅ | Commande appartient à un tenant |
| `factures` | ✅ | Facture appartient à un tenant |
| `bons_livraison` | ✅ | BL appartient à un tenant |
| `tournees` | ✅ | Tournée appartient à un tenant |
| `camions` | ✅ | Camion appartient à un tenant |
| `devis` | ✅ | Devis appartient à un tenant |

Les tables enfants (`com_client_ligne`, `facture_lignes`, `bon_livraison_lignes`, etc.) **n'ont pas** de `tenant_id` — elles sont toujours accédées via leur parent qui a déjà le filtre.

### Fonctionnement

```php
// Lecture → filtre automatique sur tenant_id
$tenantId = auth()->user()->tenant_id;
$query = Model::where('tenant_id', $tenantId);

// Création → tenant_id injecté automatiquement
Model::create(['tenant_id' => $tenantId, ...]);
```

Le `tenant_id` est déterminé par l'utilisateur connecté (`auth()->user()->tenant_id`), lui-même rattaché à son tenant via le middleware `IdentifyTenant`.

### Migration

Migration : `2026_04_10_100001_add_tenant_id_to_ventes_tables.php`

---

## TABLEAU DES ACTIONS PAR MODULE (mis à jour)

### Clients

| Action | Disponible | Condition |
|--------|-----------|-----------|
| Créer | ✅ | Toujours |
| Modifier | ✅ | Toujours |
| Supprimer/Désactiver | ✅ | Toujours |

### Devis

| Action | Disponible | Condition |
|--------|-----------|-----------|
| Créer | ✅ | Toujours |
| Modifier (lignes, prix) | ✅ | Statut `brouillon` |
| Supprimer | ✅ | Statut `brouillon` |
| Envoyer | ✅ | Statut `brouillon` |
| Accepter | ✅ | Statut `envoye` |
| Refuser | ✅ | Statut `envoye` |
| Convertir en commande | ✅ | Statut `accepte` |

### Commandes Client

| Action | Disponible | Condition |
|--------|-----------|-----------|
| Créer | ✅ | Toujours |
| Modifier (lignes, remises) | ✅ | Statut `brouillon` — bouton ✏️ |
| Supprimer | ✅ | Statut `brouillon` |
| Soumettre | ✅ | Statut `brouillon` |
| Accepter | ✅ | Statut `soumise` |
| Refuser | ✅ | Statut `soumise` |
| Créer facture | ✅ | Statut `acceptee` |

### Factures

| Action | Disponible | Condition |
|--------|-----------|-----------|
| Créer depuis commande | ✅ | Commande `acceptee` |
| Créer manuellement | ✅ | Toujours — bouton "Nouvelle facture" |
| Supprimer | ✅ | Statut `brouillon` |
| Émettre | ✅ | Statut `brouillon` |
| Enregistrer paiement | ✅ | Statuts `emise` ou `partiellement_payee` |
| Créditer (note de crédit) | ✅ | Statuts `emise`, `partiellement_payee` ou `payee` (§5.8) |
| Payer avec un acompte | ✅ | Statuts `emise` ou `partiellement_payee`, acompte disponible du client (§5.9) |
| Créer BL | ✅ | Statut `emise`, si aucun BL existant |

### Bons de Livraison

| Action | Disponible | Condition |
|--------|-----------|-----------|
| Créer | ✅ | Automatique depuis une facture |
| Annuler | ✅ | Statuts `cree` ou `en_preparation` — bouton 🚫 |
| Démarrer préparation | ✅ | Statut `cree` |
| Modifier quantités préparées | ✅ | Statut `en_preparation` |
| Marquer prêt | ✅ | Statut `en_preparation` |
| Enregistrer livraison | ✅ | Statut `en_livraison` |

### Tournées

| Action | Disponible | Condition |
|--------|-----------|-----------|
| Créer | ✅ | Toujours |
| Supprimer | ✅ | Statut `planifiee` — bouton 🗑️ |
| Ajouter BL | ✅ | Statut `planifiee`, BL en `pret` |
| Retirer BL | ✅ | Statut `planifiee` — bouton ➖ dans modal détail |
| Démarrer | ✅ | Statut `planifiee` + au moins 1 BL |
| Terminer | ✅ | Statut `en_cours` |

---

## 2. Clients

### 2.1 Ce que c'est

Un **Client** est une entreprise ou personne qui achète nos produits. Il est le point central de tout le module ventes.

### 2.2 Champs d'un client

| Champ | Description | Obligatoire |
|-------|-------------|-------------|
| `code` | Identifiant unique (ex: CLT-0001) | Oui (auto) |
| `raison_sociale` | Nom de l'entreprise | Oui |
| `adresse_facturation` | Adresse pour les factures | Non |
| `adresse_livraison` | Adresse de livraison (si différente) | Non |
| `ville` | Ville | Non |
| `code_postal` | Code postal | Non |
| `telephone` | Téléphone | Non |
| `email` | Email | Non |
| `contact_nom` | Nom du contact principal | Non |
| `contact_telephone` | Téléphone du contact | Non |
| `encours_max` | Plafond de crédit accordé (€) | Non |
| `encours_actuel` | Montant actuellement dû (calculé auto) | - |
| `taux_remise_global` | Remise en % applicable à tous ses achats | Non |
| `actif` | Actif ou inactif | Oui |

### 2.3 Encours client

L'**encours** est le montant total que le client doit actuellement (factures émises non entièrement payées).

- `encours_actuel` = somme des `reste_a_payer` de toutes ses factures non soldées
- Mis à jour automatiquement à chaque paiement enregistré (`updateEncours()`)
- Comparé à `encours_max` pour décider si une nouvelle commande est autorisée

**Vérification :** `Client::peutCommander($montant)` retourne `true` si `encours_actuel + montant ≤ encours_max` (ou si `encours_max = 0`, pas de limite).

### 2.4 Conditions de paiement

Via `GET/POST /clients/{id}/conditions-paiement`, on peut définir des échéances de paiement personnalisées pour ce client (ex: payer en 3 fois).

### 2.5 Endpoints

```
GET    /clients          → liste avec pagination
GET    /clients/actifs   → liste pour les selects
POST   /clients          → créer
PUT    /clients/{id}     → modifier
DELETE /clients/{id}     → désactiver
GET    /clients/{id}/conditions-paiement  → voir les conditions
POST   /clients/{id}/conditions-paiement → définir les conditions
```

---

## 3. Devis

### 3.1 Ce que c'est

Un **Devis** est une proposition commerciale envoyée au client avant qu'il s'engage. Il décrit les produits, quantités et prix. Le client peut accepter ou refuser.

Un devis accepté peut être **converti en commande client** directement — sans ressaisie.

### 3.2 Champs d'un devis

**En-tête (Devis)**

| Champ | Description |
|-------|-------------|
| `numero` | Ex: DEV-2026-0001 (auto-généré) |
| `client_id` | Le client concerné |
| `date_devis` | Date de création |
| `date_validite` | Date d'expiration (après cette date → `expire`) |
| `statut` | État actuel |
| `montant_total` | Somme des lignes (calculé auto) |
| `notes` | Notes pour le client |

**Lignes (DevisLigne)**

| Champ | Description |
|-------|-------------|
| `produit_id` | Le produit proposé |
| `quantite` | Quantité proposée |
| `prix_unitaire` | Prix unitaire proposé |
| `montant_ligne` | `quantite × prix_unitaire` |

### 3.3 Statuts du devis

| Statut | Signification | Actions disponibles |
|--------|---------------|---------------------|
| `brouillon` | En rédaction | Modifier ✏️, Envoyer ✉️, Supprimer 🗑️ |
| `envoye` | Envoyé au client | Accepter ✅, Refuser ❌ |
| `accepte` | Client a accepté | Convertir en commande 📄 |
| `refuse` | Client a refusé | Lecture seule |
| `expire` | Date de validité dépassée | Lecture seule |

### 3.4 Flow du devis

```
Étape 1 : Créer le devis (statut: brouillon)
          → Sélectionner le client
          → Ajouter des lignes de produits avec prix
          → Le total se calcule automatiquement

Étape 2 : Envoyer le devis (statut: envoye)
          → Endpoint: POST /devis/{id}/envoyer
          → Enregistre la date d'envoi

Étape 3a : Client accepte (statut: accepte)
           → Endpoint: POST /devis/{id}/accepter

Étape 3b : Client refuse (statut: refuse)
           → Endpoint: POST /devis/{id}/refuser

Étape 4 : Convertir en commande (si accepté)
           → Endpoint: POST /devis/{id}/convertir
           → Crée une ComClientEntete avec les mêmes lignes
           → Le devis reste dans l'historique
```

### 3.5 Conversion en commande

Quand on convertit un devis accepté :
1. Une **Commande Client** est créée avec les mêmes lignes et prix.
2. Les taxes sont calculées comme pour une commande saisie directement : le groupe de taxes par défaut de l'entreprise s'il existe, sinon le taux par défaut.
3. La commande est en statut `brouillon` : elle peut encore être modifiée.
4. Le devis garde son statut `accepte` et pointe vers sa commande (`commande_id`), affichée dans la liste (« → CMD-… »).
5. **Un devis ne se convertit qu'une fois.** Une seconde conversion répond 422 avec le numéro de la commande existante ; un verrou empêche deux conversions simultanées.

### 3.6 Points d'attention

- **Expiration.** Un devis envoyé dont la date de validité est passée devient `expire` au chargement de la liste, et on ne peut plus l'accepter (422 avec la date d'expiration).
- Les prix dans le devis sont libres — ils ne sont pas forcément les mêmes que les prix catalogues.

---

## 4. Commandes Client

### 4.1 Ce que c'est

Une **Commande Client** est la confirmation d'achat du client. Elle engage les deux parties. Elle peut venir d'un devis converti ou être créée directement.

### 4.2 Champs d'une commande client

**En-tête (ComClientEntete)**

| Champ | Description |
|-------|-------------|
| `numero` | Ex: CMD-2026-0001 (auto-généré) |
| `client_id` | Le client |
| `date_commande` | Date de la commande |
| `date_livraison_souhaitee` | Date de livraison demandée par le client |
| `remise_globale` | Remise globale en % (s'applique après les remises ligne) |
| `montant_ht` | Total HT calculé |
| `montant_tva` | Total TVA calculé |
| `montant_ttc` | Total TTC calculé |
| `statut` | État actuel |
| `notes` | Notes internes |
| `motif_refus` | Raison si refusée |

**Lignes (ComClientLigne)**

| Champ | Description |
|-------|-------------|
| `produit_id` | Le produit |
| `quantite` | Quantité commandée |
| `prix_unitaire_ht` | Prix HT unitaire |
| `taux_tva` | Taux TVA en % (ex: 20) |
| `remise_ligne` | Remise sur cette ligne en % |
| `montant_ht` | Calculé |
| `montant_tva` | Calculé |
| `montant_ttc` | Calculé |

### 4.3 Calcul des montants

```
Pour chaque ligne :
  montant_ht    = quantite × prix_unitaire_ht × (1 - remise_ligne/100)
  montant_tva   = montant_ht × (taux_tva/100)
  montant_ttc   = montant_ht + montant_tva

Total commande :
  total_ht      = somme(montant_ht) × (1 - remise_globale/100)
  total_tva     = somme(montant_tva) × (1 - remise_globale/100)
  total_ttc     = total_ht + total_tva
```

Méthode backend : `ComClientEntete::calculerMontants()`

### 4.4 Statuts de la commande client

| Statut | Signification | Actions disponibles |
|--------|---------------|---------------------|
| `brouillon` | En cours de rédaction | Modifier ✏️, Soumettre 📤, Supprimer 🗑️ |
| `soumise` | En attente d'approbation | Accepter ✅, Refuser ❌ |
| `acceptee` | Approuvée | Créer Facture 💰 |
| `refusee` | Refusée | Lecture seule |

### 4.5 Modifier une commande brouillon

Le bouton ✏️ (crayon) apparaît sur les commandes en statut `brouillon`. Il ouvre le même modal que la création, pré-rempli avec les données existantes :
- Client, dates, remise globale, notes
- Toutes les lignes (produits, quantités, prix)

L'enregistrement appelle `PUT /commandes-client/{id}` qui recalcule les montants automatiquement.

### 4.5 Flow de la commande client

```
Étape 1 : Créer la commande (statut: brouillon)
          → Sélectionner le client
          → Ajouter les lignes
          → Appliquer les remises si nécessaire

Étape 2 : Soumettre (statut: soumise)
          → Endpoint: POST /commandes-client/{id}/soumettre
          → La commande part en validation

Étape 3a : Accepter (statut: acceptee)
           → Endpoint: POST /commandes-client/{id}/accepter

Étape 3b : Refuser (statut: refusee)
           → Endpoint: POST /commandes-client/{id}/refuser

Étape 4 : Créer la Facture
           → Sur la page Factures → "Créer depuis commande"
           → Endpoint: POST /factures/commande/{commandeId}
```

---

## 5. Factures

### 5.1 Ce que c'est

La **Facture** est le document officiel de demande de paiement envoyé au client. Elle est créée à partir d'une commande acceptée.

### 5.2 Champs d'une facture

| Champ | Description |
|-------|-------------|
| `numero` | Ex: FAC-2026-0001 (auto-généré) |
| `commande_id` | Commande d'origine |
| `client_id` | Le client |
| `facture_mere_id` | ID de la facture d'origine (si facture de reliquat) |
| `date_facture` | Date d'émission |
| `date_echeance` | Date limite de paiement |
| `statut` | État actuel |
| `montant_ht` | Total HT |
| `montant_tva` | Total TVA |
| `montant_ttc` | Total TTC |
| `montant_paye` | Somme des paiements reçus (calculé) |
| `montant_credite` | Somme des crédits de notes de crédit imputés sur la facture (calculé, §5.8) |
| `montant_escompte` | Somme des escomptes accordés à ses paiements (calculé, §5.10) |
| `condition_paiement_id` | Condition de paiement (§5.10) |
| `reste_a_payer` | `montant_ttc - montant_paye - montant_credite - montant_escompte` |

### 5.3 Statuts de la facture

| Statut | Signification | Actions disponibles |
|--------|---------------|---------------------|
| `brouillon` | Créée mais pas encore envoyée | Émettre 📤, Supprimer 🗑️ |
| `emise` | Envoyée au client | Enregistrer paiement 💵, Créer BL 📦, Créditer |
| `partiellement_payee` | Paiement partiel reçu | Enregistrer paiement 💵, Créditer |
| `payee` | Intégralement payée, ou soldée par des paiements et des notes de crédit | Créditer |
| `creditee` | Soldée par des notes de crédit, sans aucun paiement | Lecture seule |

### 5.4 Création manuelle d'une facture

En plus de la création depuis une commande, on peut créer une facture directement depuis le bouton **"Nouvelle facture"**.

**Quand l'utiliser :**
- Facturer un client sans passer par le circuit Devis → Commande
- Facture de service ou prestation non liée à une commande
- Corrections, avoirs manuels

**Endpoint :** `POST /factures`

**Champs requis :**
- Client
- Date de facture
- Lignes (produit, quantité, prix HT)

**Champs optionnels :**
- Date d'échéance
- Notes

La facture est créée en statut `brouillon`. Elle suit ensuite le flow normal : émettre → paiement → BL.

### 5.4 Flow de la facture

```
Étape 1 : Créer la facture depuis une commande acceptée
          → Page Factures → "Créer depuis commande"
          → Endpoint: POST /factures/commande/{commandeId}
          → Les lignes et montants sont copiés depuis la commande
          → Statut: brouillon

Étape 2 : Émettre la facture (statut: emise)
          → Endpoint: POST /factures/{id}/emettre
          → Enregistre la date d'émission

Étape 3 : Créer le Bon de Livraison
          → Endpoint: POST /factures/{id}/creer-bl
          → Crée un BonLivraison lié à cette facture (émise, partiellement payée ou payée)
          → Un seul bon en cours par facture ; un bon annulé ne compte pas, on peut en recréer un

Étape 4 : Enregistrer les paiements
          → Endpoint: POST /factures/{id}/paiement
          → Chaque paiement met à jour montant_paye et reste_a_payer
          → Si reste_a_payer = 0 → statut = "payee"
          → Si reste_a_payer > 0 → statut = "partiellement_payee"
```

### 5.5 Enregistrement d'un paiement

| Champ | Description |
|-------|-------------|
| `montant` | Montant du paiement |
| `date_paiement` | Date du paiement |
| `mode_paiement` | `virement`, `cheque`, `carte`, `especes`, `autre` |
| `reference` | Référence du virement/chèque |
| `escompte` | Escompte pour paiement rapide accordé avec ce paiement (§5.10) |

Après chaque paiement :
- `updateMontantPaye()` recalcule `montant_paye` et `reste_a_payer`
- Le statut de la facture est mis à jour automatiquement
- L'encours du client est mis à jour (`updateEncours()`)

### 5.6 Échéancier (FactureEcheance)

Si le client paye en plusieurs fois, on peut définir un échéancier :

| Champ | Description |
|-------|-------------|
| `date_echeance` | Date de cette échéance |
| `montant` | Montant à payer à cette date |
| `ordre` | Numéro d'ordre (1, 2, 3...) |

Exemple : Facture de 3000€ → 1000€ le 1er mai + 1000€ le 1er juin + 1000€ le 1er juillet.

Les paiements se répartissent sur les échéances dans leur ordre : chacune passe `payee`, `partiellement_payee` ou reste `en_attente`, avec son `montant_paye`.

### 5.7 Facture de reliquat (livraison partielle)

Quand un BL est livré partiellement, le système génère automatiquement une **nouvelle facture** pour les articles non livrés. Cette nouvelle facture :
- Référence la facture d'origine via `facture_mere_id`
- Contient uniquement les lignes non livrées avec les quantités restantes
- Repart en statut `brouillon`

Une livraison **complète** passe le BL en `livre_complet` et ne crée aucune facture. (Avant le 2026-10-16, les quantités livrées étaient lues avant leur enregistrement : toute livraison était vue comme partielle et une facture de reliquat reprenait la totalité du bon.)

### 5.8 Notes de crédit (Ventes → Notes de crédit)

Plan de parité fripv2, phase 1.5 : le « Créditer » d'une facture. Une note de crédit annule tout ou partie d'une facture émise, payée ou non : retour de marchandise, erreur de prix, geste commercial.

**Créer une note :**
- bouton **Créditer** d'une facture émise, partiellement payée ou payée (liste des factures) ;
- ou **Nouvelle note de crédit** (écran Notes de crédit), puis le choix de la facture, par numéro ou par client.

**L'éditeur** reprend les lignes de la facture :
- pour chaque ligne : ce qui a été facturé, ce qui a déjà été crédité et ce qui reste à créditer ;
- par défaut, tout ce qui reste ; « Tout effacer » remet les quantités à zéro ;
- la **quantité** et le **prix unitaire HT** se modifient, au plus ceux de la facture (un prix réduit sert au geste commercial) ;
- la remise de la ligne est celle de la facture ;
- les **taxes** sont celles calculées sur la facture, au prorata du montant crédité : même nom et même taux, même si le groupe de taxes a changé depuis ;
- l'écran affiche une estimation ; l'API calcule les montants définitifs ;
- « Enregistrer le brouillon », ou « Enregistrer et émettre ».

**Limites :**
- sur chaque ligne, le montant HT crédité par toutes les notes émises ne dépasse jamais celui facturé (422 sinon) ;
- la note qui crédite tout ce qui restait reprend exactement les totaux restants de la facture : pas d'écart d'arrondi ;
- un brouillon est de nouveau contrôlé à son émission : une autre note a pu être émise entre-temps ;
- la date de la note ne précède pas celle de la facture.

**Statuts de la note :**

| Statut | Signification | Actions |
|---|---|---|
| `brouillon` | Sans numéro, sans effet sur la facture | Modifier, Émettre, Supprimer, PDF |
| `emis` | Émise ; il reste du crédit disponible | Imputer sur une facture, Rembourser, PDF, Courriel |
| `solde` | Émise ; tout le crédit a été imputé ou remboursé | PDF, Courriel |

**À l'émission :**
1. la note reçoit son numéro (numérotation `avoir`, `AV-00001` par défaut, voir FLOW-PARAMETRES §7) ;
2. elle s'**impute** sur ce qui reste dû de sa facture, au plus son montant ;
3. la facture recalcule `montant_credite`, `reste_a_payer`, ses échéances et l'encours du client ;
4. soldée sans aucun paiement, la facture passe **`creditee`** ; soldée avec des paiements, **`payee`** ;
5. un paiement ne peut plus dépasser le reste dû, crédits déduits.

**Crédit disponible** (facture déjà payée, ou crédit plus grand que le reste dû) :
- **Imputer sur une facture** : une facture due du même client. Le montant ne dépasse ni le crédit disponible ni le reste de la facture, et la date ne précède ni la note ni la facture.
- **Rembourser** : montant, date, mode (virement, chèque, espèces, carte, autre) et référence.
- Entièrement utilisée, la note passe `solde`.

**Ailleurs dans l'application :**
- **Détail d'une facture** : ligne « Crédité » et liste de ses notes de crédit.
- **Comptes clients** (§7 bis) :
  - la note est au crédit du relevé, un remboursement au débit ;
  - le crédit disponible est dû au client.
- **PDF** « Note de crédit » : facture créditée, motif, totaux, imputé, remboursé et crédit disponible.
- **Courriel** : type `avoir` avec les variables `{numero_facture}`, `{motif}` et `{credit_disponible}`.
- **Recherche globale** : notes émises, par numéro, client ou facture.
- **Journal des modifications** : création, modification et suppression des notes.
- **Espace client** : une facture `creditee` s'affiche comme soldée.

**Hors périmètre :** une note de crédit est **financière seulement** : elle ne remet rien en stock. Les retours avec remise en stock relèvent des retours clients (plan, phase 1.7).

**Permissions** (module `avoirs`, onglet Ventes) :

| Permission | Code | Ce qu'elle ouvre |
|---|---|---|
| Consulter | `avoirs.voir` | Écran Notes de crédit, détail, PDF |
| Créer | `avoirs.creer` | « Créditer », nouveau brouillon |
| Modifier | `avoirs.modifier` | Modifier un brouillon |
| Supprimer | `avoirs.supprimer` | Supprimer un brouillon |
| Émettre une note de crédit | `avoirs.emettre` | Émission (numéro, imputation sur la facture) |
| Rembourser ou imputer un crédit | `avoirs.regler` | Imputer le crédit disponible, ou le rembourser |

À la mise en place, chaque groupe reçoit sur les notes de crédit ses droits sur les factures :
- « Émettre une note de crédit » suit « Émettre une facture » ;
- « Rembourser ou imputer un crédit » suit « Enregistrer un paiement ».

Pour les rôles système, cela donne :
- administrateur et responsable : tout ;
- utilisateur et lecture seule : consultation.

| Route | Rôle |
|---|---|
| `GET /avoirs?statut=&recherche=&client_id=&facture_id=` | Liste paginée |
| `GET /avoirs/{id}` | Détail : lignes, détail des taxes, imputations, remboursements |
| `GET /factures/{id}/avoir` | Ce qui reste à créditer sur une facture, ligne par ligne |
| `POST /avoirs` | Nouveau brouillon (`facture_id`, `date_avoir`, `motif`, `notes`, `lignes[]` : `facture_ligne_id`, `quantite`, `prix_unitaire_ht`) |
| `PUT /avoirs/{id}` | Modifier un brouillon |
| `DELETE /avoirs/{id}` | Supprimer un brouillon (422 une fois émise) |
| `POST /avoirs/{id}/emettre` | Émettre |
| `GET /avoirs/{id}/factures-imputables` | Factures dues du client |
| `POST /avoirs/{id}/imputer` | Imputer (`facture_id`, `montant`, `date`) |
| `POST /avoirs/{id}/rembourser` | Rembourser (`montant`, `date_remboursement`, `mode`, `reference`) |
| `GET /avoirs/{id}/pdf` | PDF |

**Données :**

| Table | Contenu |
|---|---|
| `avoirs` | En-tête : numéro, facture, client, date, statut, motif, totaux, `montant_impute`, `montant_rembourse` |
| `avoir_lignes` | Lignes : ligne de facture créditée, quantité, prix, remise, taux, montants ; détail des taxes dans `document_taxes` (`document = avoir`) |
| `avoir_imputations` | Part d'une note imputée sur une facture, datée |
| `avoir_remboursements` | Remboursements au client |
| `factures.montant_credite` | Total des crédits imputés sur la facture |

Code :
- API : service `App\Services\NotesCredit`, contrôleur `App\Http\Controllers\Api\AvoirController`, migration `2026_10_16_100030_creer_notes_de_credit` ;
- web : écran `NotesCreditPage`, éditeur `components/avoirs/EditeurNoteCredit`, fenêtre `components/avoirs/ReglerCredit`, service `src/services/avoirs.ts`.

### 5.9 Acomptes et demandes d'acompte (Ventes → Acomptes, Demandes d'acompte)

Plan de parité fripv2, phase 1.5 : les acomptes et les demandes d'acompte de fripv2.

#### Acomptes

Un **acompte** est un paiement reçu d'un client avant sa facture : date, mode (virement, chèque, espèces, carte, autre), référence, montant et notes. Il appartient au client.

**Saisir un acompte :**
- **Ventes → Acomptes → Nouvel acompte** : un acompte libre, ou réservé à une commande du client ;
- bouton **Nouvel acompte** d'une commande brouillon, en attente ou acceptée (liste des commandes) : l'acompte est réservé à cette commande ;
- une **demande d'acompte** marquée payée (plus bas).

**Utiliser un acompte :**
- **Réservé à une commande** : à l'émission de la facture de cette commande, l'acompte s'applique de lui-même à son paiement, au plus ce qui reste dû. Ce qui en reste redevient un crédit libre du client.
- **Payer une facture** : la fenêtre « Enregistrer un paiement » d'une facture liste les acomptes disponibles du client, sauf ceux réservés à une autre commande. « Appliquer » paie la facture avec l'acompte, au plus ce qui en reste et ce qui reste dû.
- **Appliquer sur une facture**, depuis l'écran Acomptes : une facture due du même client, montant et date au choix.
- **Rembourser** ce qui reste : montant, date, mode et référence. Par défaut, le mode est celui de l'acompte : l'argent ressort par où il est entré.

**Effet sur la facture :** une application est un **paiement de la facture** rattaché à l'acompte (`facture_paiements.acompte_id`). Le reste à payer, le statut, les échéances et l'encours du client suivent donc comme pour tout paiement.

**Contrôles :**
- le montant ne dépasse ni ce qui reste de l'acompte, ni ce qui reste dû sur la facture ;
- la date ne précède ni l'acompte ni la facture ;
- la facture appartient au même client ;
- un acompte déjà appliqué ou remboursé ne change plus de date, de mode ni de montant, et ne se supprime plus.

**État d'un acompte :**

| État | Signification |
|---|---|
| `disponible` | Rien n'en a encore servi |
| `entame` | Appliqué ou remboursé en partie |
| `utilise` | Plus rien de disponible |

**L'écran Acomptes** liste tous les acomptes, tous clients confondus, du plus récent au plus ancien :
- **Recherche** par référence, client ou montant.
- **Filtre** « avec solde disponible » ou « utilisés ».
- **Totaux** de la sélection : montant et disponible.
- **Détail** d'un acompte : factures payées avec, et remboursements.

#### Demandes d'acompte

Une **demande d'acompte** demande un dépôt au client avant des travaux ou une commande. Ce n'est pas une facture : elle n'a aucun effet sur le compte du client tant qu'elle n'est pas payée.

**Contenu :**
- numéro, attribué à la création (numérotation `demande_acompte`, `DA-00001` par défaut) ;
- client, date ;
- description sur plusieurs lignes ;
- montant, sans taxe ;
- au besoin, un devis et une commande du client.

**Statuts :**

| Statut | Signification | Actions |
|---|---|---|
| `brouillon` | Modifiable | Modifier, Marquer envoyée, Marquer payée, Supprimer |
| `envoyee` | Envoyée au client ; ne se modifie plus | Marquer payée, Supprimer |
| `payee` | Dépôt reçu : l'acompte est enregistré | Lecture seule |

- **Envoyée** : marquée à la main, ou d'elle-même quand la demande part par courriel (type `demande_acompte`, PDF joint).
- **Marquer payée** : date, mode et référence du dépôt. L'acompte est créé au dossier du client, réservé à la commande de la demande si elle est toujours en cours.
- **Après le paiement**, l'usage de cet acompte donne l'état affiché : **partiellement appliquée**, puis **appliquée**.
- **Supprimer un acompte** inutilisé venant d'une demande remet la demande dans son état d'avant le paiement.

**PDF** « Demande d'acompte » : la description, le montant demandé et la mention « Aucune taxe : ce document n'est pas une facture ».

#### Ailleurs dans l'application

- **Comptes clients** (§7 bis) :
  - un acompte reçu est au crédit du relevé, son remboursement au débit ;
  - son application n'est pas un nouveau mouvement, car l'acompte est déjà au crédit ;
  - ce qui reste d'un acompte compte dans les crédits dus au client.
- **Recherche globale** : les demandes d'acompte, par numéro, description ou client.
- **Journal des modifications** : création, modification et suppression des acomptes et des demandes.
- **Hors périmètre** : aucune écriture comptable n'est passée, comme pour les autres documents de vente pour l'instant.

**Permissions :**

| Écran | Module | Actions nommées |
|---|---|---|
| Acomptes | `acomptes` | « Appliquer un acompte sur une facture » (`acomptes.appliquer`), « Rembourser un acompte » (`acomptes.rembourser`) |
| Demandes d'acompte | `demandes_acompte` | « Marquer une demande d'acompte envoyée » (`demandes_acompte.envoyer`), « Marquer une demande d'acompte payée » (`demandes_acompte.encaisser`) |

À la mise en place, chaque groupe reçoit sur ces deux écrans ses droits sur les factures :
- appliquer, rembourser et marquer payée suivent « Enregistrer un paiement » ;
- marquer envoyée suit « Émettre une facture ».

Pour les rôles système, cela donne :
- administrateur et responsable : tout ;
- utilisateur et lecture seule : consultation.

| Route | Rôle |
|---|---|
| `GET /acomptes?client_id=&commande_id=&disponibilite=avec_solde\|utilise&recherche=` | Liste paginée et totaux de la sélection |
| `GET /acomptes/{id}` | Détail : factures payées avec l'acompte, remboursements |
| `POST /acomptes` | Recevoir un acompte (`client_id`, `date_acompte`, `mode`, `reference`, `montant`, `commande_id`, `notes`) |
| `PUT /acomptes/{id}` | Modifier (référence, réservation et notes toujours ; date, mode et montant tant qu'il n'a pas servi) |
| `DELETE /acomptes/{id}` | Supprimer un acompte qui n'a pas servi |
| `POST /acomptes/{id}/appliquer` | Payer une facture (`facture_id`, `montant`, `date`) |
| `POST /acomptes/{id}/rembourser` | Rembourser (`montant`, `date_remboursement`, `mode`, `reference`) |
| `GET /demandes-acompte?statut=&client_id=&recherche=` | Liste paginée |
| `GET /demandes-acompte/{id}` (`/pdf`) | Détail, PDF |
| `POST /demandes-acompte`, `PUT /demandes-acompte/{id}` | Saisie (brouillon) |
| `DELETE /demandes-acompte/{id}` | Supprimer une demande non payée |
| `POST /demandes-acompte/{id}/envoyer` | Marquer envoyée |
| `POST /demandes-acompte/{id}/encaisser` | Marquer payée (`date`, `mode`, `reference`) : crée l'acompte |

**Données :**

| Table | Contenu |
|---|---|
| `acomptes` | Client, date, mode, référence, montant, commande réservée, demande d'origine, `montant_applique`, `montant_rembourse` |
| `acompte_remboursements` | Remboursements d'un acompte |
| `facture_paiements.acompte_id` | Paiement d'une facture fait avec un acompte |
| `demandes_acompte` | Numéro, client, date, description, montant, statut, devis, commande, acompte |

Code :
- API : services `App\Services\Acomptes` et `App\Services\DemandesAcompte`, contrôleurs `AcompteController` et `DemandeAcompteController`, migration `2026_10_16_100031_creer_acomptes` ;
- web : écrans `AcomptesPage` et `DemandesAcomptePage`, composants `components/acomptes/FormulaireAcompte` et `components/acomptes/UtiliserAcompte`, service `src/services/acomptes.ts`.

### 5.10 Conditions de paiement et escompte pour paiement rapide

Plan de parité fripv2, phase 1.5 : les conditions de paiement de fripv2 (« Net 30 », « 2/10 Net 30 », « 30 jours fin de mois ») appliquées aux clients et aux factures. Les conditions se définissent dans **Référentiels → Conditions de paiement** :
- délai en jours ;
- fin de mois ;
- taux et jours d'escompte ;
- une condition par défaut.

**Client :**
- la fiche client (liste des clients → Modifier) choisit sa **condition de paiement** ;
- sans choix, c'est la condition par défaut de l'entreprise qui s'applique.

**Facture :**
- **Sa condition** est celle choisie à la création. Sinon, c'est celle du client, puis la condition par défaut de l'entreprise.
- **Sans échéance saisie**, l'échéance est la date de la facture plus le délai.
- Pour une condition **fin de mois**, l'échéance tombe le dernier jour du mois atteint. Par exemple, 30 jours fin de mois depuis le 16/10 donne le 30/11.
- **Facture créée depuis une commande**, quand le client n'a pas d'échéancier propre (§5.6) : son échéance unique est celle de la condition, sinon 30 jours comme avant.
- **Sans condition** (ni choisie, ni du client, ni par défaut), rien ne change.

**Escompte pour paiement rapide**, par exemple 2 % si le client paie dans les 10 jours :
- **Quand il est proposé** : comme dans fripv2, au **premier paiement** de la facture, fait au plus tard à sa date plus les jours d'escompte.
- **Ce qu'il donne** : le taux appliqué à ce qui reste dû, notes de crédit déduites, arrondi au centime.
- **À une condition** : le paiement doit régler tout le reste (montant = reste − escompte).
- **Dans la fenêtre de paiement**, une case « Escompte » apparaît avec le montant et la date limite. Cochée, elle remplit le montant à payer ; au-delà de la date limite, elle est grisée.
- **À l'enregistrement**, le paiement porte son `escompte` et la facture cumule `montant_escompte`. Le reste à payer, le statut, les échéances et l'encours en tiennent compte.
- **Refus (422)** :
  - paiement en retard : « Escompte impossible : le paiement doit être fait au plus tard le … » ;
  - paiement partiel : « Avec l'escompte, le paiement règle tout le reste : … » ;
  - facture qui a déjà reçu un paiement : « Aucun escompte possible sur cette facture. »

**Ailleurs :**
- **Détail de la facture** : condition de paiement et ligne « Escompte ».
- **PDF de la facture** :
  - « Conditions de paiement » parmi les informations ;
  - lignes « Escompte » et « Crédité » au règlement quand elles existent.
- **Comptes clients** :
  - au relevé, l'escompte est une ligne au crédit, après le paiement qu'il accompagne ;
  - le reste dû d'une facture à une date déduit les escomptes accordés jusqu'à ce jour.

| Route | Rôle |
|---|---|
| `GET /factures/{id}/escompte?date=` | Escompte possible pour un paiement à cette date : `applicable`, `condition`, `pourcentage`, `jours`, `date_limite`, `escompte`, `a_payer` |
| `POST /factures/{id}/paiement` (`escompte: true`) | Paiement avec escompte |
| `POST /factures` (`condition_paiement_id`) | Condition d'une nouvelle facture |
| `PUT /clients/{id}` (`condition_paiement_id`) | Condition du client |

**Données :**
- `clients.condition_paiement_id` ;
- `factures.condition_paiement_id`, `factures.montant_escompte` ;
- `facture_paiements.escompte`.

Le code est dans le service `App\Services\ConditionsPaiement` et la migration `2026_10_16_100032_conditions_paiement_et_escompte`.

---

## 6. Bons de Livraison

### 6.1 Ce que c'est

Un **Bon de Livraison (BL)** est le document qui accompagne la livraison physique des marchandises chez le client. Il est créé à partir d'une facture émise.

Il y a deux étapes clés :
1. **La préparation** : on prépare physiquement les articles dans l'entrepôt
2. **La livraison** : on livre les articles au client

### 6.2 Champs d'un BL

| Champ | Description |
|-------|-------------|
| `numero` | Ex: BL-2026-0001 (auto-généré) |
| `facture_id` | La facture d'origine |
| `mode_livraison` | `entreprise` (on livre) ou `retrait_client` (le client vient) |
| `statut` | État actuel |
| `date_preparation` | Quand la préparation a commencé |
| `date_pret` | Quand la préparation est terminée |
| `date_livraison` | Quand la livraison a eu lieu |
| `preparateur_id` | Qui a préparé |
| `signature_client` | Signature numérique du client (à la livraison) |
| `notes_livraison` | Notes laissées à la livraison |

**Lignes (BonLivraisonLigne)**

| Champ | Description |
|-------|-------------|
| `produit_id` | Le produit |
| `quantite_a_livrer` | Quantité prévue (= quantité facturée) |
| `quantite_preparee` | Quantité effectivement préparée |
| `quantite_livree` | Quantité effectivement livrée |
| `statut_ligne` | État de la ligne |

### 6.3 Statuts du BL

| Statut | Signification | Actions disponibles |
|--------|---------------|---------------------|
| `cree` | BL créé, préparation pas encore commencée | Démarrer préparation ▶️, Annuler 🚫 |
| `en_preparation` | Préparation en cours | Modifier quantités préparées, Marquer prêt ✅, Annuler 🚫 |
| `pret` | Prêt à charger | Ajouter à une tournée 📦 |
| `en_livraison` | Chargé dans un camion, en route | Enregistrer livraison 🚚 |
| `livre_complet` | Tout livré | Terminé |
| `livre_partiel` | Livraison partielle | Facture de reliquat créée auto |
| `annule` | Annulé | Lecture seule |

### 6.3b Annulation d'un BL

Le bouton 🚫 (annuler) est disponible pour les statuts `cree` et `en_preparation`.

**Endpoint :** `POST /bons-livraison/{id}/annuler`

**Quand l'utiliser :**
- Client annule sa commande après la facturation
- Erreur de création du BL
- Doublon

Un BL annulé ne peut plus être modifié. Les mouvements de stock déjà créés pendant la préparation ne sont pas automatiquement annulés — prévoir un mouvement correctif si nécessaire.

### 6.4 Statuts des lignes

| Statut ligne | Signification |
|--------------|---------------|
| `a_preparer` | Pas encore commencé |
| `en_cours` | Préparation en cours |
| `prepare` | Quantité préparée = quantité à livrer |
| `charge` | Chargé dans le camion |
| `livre` | Livré au client |

### 6.5 Flow complet du BL

```
┌─────────────────────────────────────────────────────────────────┐
│                    BON DE LIVRAISON — FLOW                       │
└─────────────────────────────────────────────────────────────────┘

ÉTAPE 1 : CRÉATION
  → Depuis FacturesPage → bouton "Créer BL" sur une facture émise
  → Endpoint: POST /factures/{id}/creer-bl
  → Statut: cree
  → Les lignes du BL sont copiées depuis les lignes de la facture
  → quantite_a_livrer = quantite de la facture

ÉTAPE 2 : DÉMARRER LA PRÉPARATION
  → Endpoint: POST /bons-livraison/{id}/preparer
  → Statut: en_preparation
  → Toutes les lignes passent en statut_ligne = "en_cours"
  → Enregistre le preparateur_id et la date_preparation

ÉTAPE 3 : SAISIR LES QUANTITÉS PRÉPARÉES
  → Endpoint: PUT /bons-livraison/{id}/lignes
  → Pour chaque ligne, saisir la quantite_preparee
  → Si quantite_preparee = quantite_a_livrer → ligne = "prepare"
  → Si quantite_preparee < quantite_a_livrer → ligne = "en_cours"
  → Un mouvement de stock "sortie_preparation" est créé

ÉTAPE 4 : MARQUER PRÊT
  → Endpoint: POST /bons-livraison/{id}/pret
  → Statut: pret
  ⚠️ Si une ligne a quantite_preparee = 0, le système la remplit automatiquement
     avec quantite_a_livrer (pour ne pas bloquer le flow)

ÉTAPE 5 : AJOUTER À UNE TOURNÉE (optionnel)
  → Depuis TourneesPage → bouton 📦+ sur une tournée planifiée
  → Statut BL reste "pret" jusqu'au démarrage de la tournée

ÉTAPE 6 : DÉMARRER LA TOURNÉE
  → Tous les BL de la tournée passent automatiquement en "en_livraison"
  → Toutes les lignes passent en "charge"

ÉTAPE 7 : ENREGISTRER LA LIVRAISON
  → Endpoint: POST /bons-livraison/{id}/livrer
  → Pour chaque ligne, saisir la quantite_livree
  → Signature du client (optionnel)
  → Notes de livraison (optionnel)
  → Un mouvement "livraison_client" est créé

  → Si tout livré (quantite_livree = quantite_a_livrer pour toutes les lignes) :
       Statut: livre_complet

  → Si livraison partielle :
       Statut: livre_partiel
       + Création automatique d'une nouvelle facture pour le reliquat
```

### 6.6 Création du BL — comment ça se passe

La création est déclenchée depuis la page Factures. Aucune saisie manuelle de quantité n'est nécessaire : les quantités sont automatiquement reprises depuis les lignes de la facture.

---

## 7. Camions

### 7.1 Ce que c'est

Un **Camion** est un véhicule de livraison. Il est associé à une tournée et transporte les BL jusqu'aux clients.

### 7.2 Champs d'un camion

| Champ | Description |
|-------|-------------|
| `immatriculation` | Plaque d'immatriculation (unique) |
| `marque` | Marque du véhicule |
| `modele` | Modèle |
| `capacite_kg` | Capacité de charge en kg |
| `actif` | Disponible ou non |

### 7.3 Disponibilité

`GET /camions/disponibles?date=YYYY-MM-DD` retourne les camions qui n'ont pas de tournée planifiée ou en cours ce jour-là. Utilisé lors de la création d'une tournée pour ne proposer que les camions libres.

---

## 7 bis. Comptes clients (Ventes → Comptes clients)

Plan de parité fripv2, phase 4.4 : ce que les clients doivent, à une date. C'est un état ; rien n'est enregistré.

- **Factures prises en compte** : les factures émises, partiellement payées, payées ou créditées, datées au plus tard ce jour. Les brouillons et les factures annulées sont exclus.
- **Reste dû à une date** : le montant TTC, moins les paiements et les crédits de notes de crédit imputés jusqu'à ce jour. Une facture payée depuis compte donc encore comme due à une date antérieure.
- **Crédits** : le crédit des notes de crédit qui n'est ni imputé ni remboursé à cette date est dû au client (§5.8), comme ce qui reste des acomptes ni appliqué ni remboursé (§5.9).
- **Analyse chronologique** :
  - le retard se compte depuis l'échéance, ou depuis la date de facture si la facture n'a pas d'échéance ;
  - tranches : non échu, 1 à 30 jours, 31 à 60, 61 à 90, plus de 90 ;
  - par client : total, tranches, nombre de factures, retard maximal ;
  - les clients sont triés par montant dû, avec les totaux, le montant en retard et le montant à plus de 90 jours ;
  - les cases se colorent selon l'ancienneté ; export CSV ;
  - quand des crédits existent, deux colonnes de plus : les crédits et le solde net (total dû moins crédits), aussi dans l'export.
- **Relevé de compte d'un client** (bouton de la ligne, du 1er janvier à la date choisie par défaut) :
  - solde d'ouverture (mouvements antérieurs) ;
  - au débit : les factures et les remboursements au client ;
  - au crédit : les paiements, les notes de crédit et les acomptes reçus ;
  - un paiement fait avec un acompte n'est pas un mouvement de plus : l'acompte est déjà au crédit ;
  - un escompte accordé avec un paiement est au crédit, après ce paiement ;
  - solde progressif ; le même jour, l'ordre est : facture, note de crédit, acompte, paiement, escompte, remboursement ;
  - solde final ;
  - factures encore dues au dernier jour, avec leur retard ;
  - crédits des notes de crédit et acomptes encore disponibles au dernier jour ;
  - export CSV.

| Route | Rôle |
|---|---|
| `GET /comptes-clients?date=[&format=csv]` | Analyse chronologique (aujourd'hui par défaut) |
| `GET /comptes-clients/{client}/releve?du=&au=[&format=csv]` | Relevé de compte |

Code : `App\Services\ComptesClients`, `App\Http\Controllers\Api\CompteClientController`, écran `ComptesClientsPage`, service `src/services/comptesClients.ts`.

---

## 7 ter. Relances de paiement (Ventes → Relances)

Plan de parité fripv2, phase 1.5 : relancer les clients dont les factures sont en retard. Dans fripv2, on repère les comptes en retard dans les comptes à recevoir pour relancer les clients ; ici, les relances sont faites et suivies dans l'application.

**Factures en retard :**
- ce sont les factures émises ou partiellement payées, avec un reste à payer, dont l'échéance est dépassée (ou la date de facture, sans échéance) ;
- elles sont classées du plus ancien retard au plus récent ;
- l'écran les filtre par retard (plus de 30, 60, 90 jours), par client ou facture, et par « jamais relancées » ;
- cartes : factures en retard, clients, reste dû, jamais relancées.

**Relancer :**
- **Par courriel** : le modèle « relance » (Paramètres → Courriels), avec la facture en PDF joint.
  - Variables : `{numero}`, `{date}`, `{date_echeance}`, `{jours_retard}`, `{niveau}`, `{reste_a_payer}`, en plus de celles du client et de l'entreprise.
  - La relance est notée à l'envoi du courriel, une seule fois par courriel.
- **Noter une relance** faite autrement : téléphone, courrier ou autre, avec date et notes (la réponse du client, par exemple). La date ne peut pas être dans le futur.
- **Niveau** : chaque relance prend le suivant (1re, 2e, 3e…).
- **Historique** : les relances d'une facture, avec leur niveau, date, moyen, auteur ou « automatique », et notes.

**Relances automatiques :** la tâche planifiée `relances_paiement` (Paramètres → Tâches planifiées). Elle est **inactive par défaut** : aucun courriel ne part sans activation.
- Chaque jour à 08:00, elle envoie le courriel de relance pour chaque facture en retard d'au moins **N jours** (15 par défaut) dont la dernière relance date d'au moins N jours.
- Au plus **3 relances automatiques** par facture.
- Seulement aux clients qui ont une adresse de courriel.
- La relance est notée dès la mise en file du courriel, marquée « automatique ».

**Permissions :** module `relances`, avec « Consulter » et « Créer » (relancer). À la mise en place, chaque groupe reçoit :
- **la consultation** de ses droits de consulter les factures ;
- **la relance** de ses droits de modifier les factures.

Pour les rôles système, cela donne :
- administrateur et responsable : consultent et relancent ;
- utilisateur et lecture seule : consultent seulement.

La liste (`GET /relances`) exige le droit de consulter les relances.

| Route | Rôle |
|---|---|
| `GET /relances?retard_min=&jamais=1&recherche=&client_id=&date=` | Factures en retard à une date et totaux |
| `GET /relances/historique?client_id=&facture_id=` | Historique des relances, paginé |
| `GET /factures/{id}/relances` | Relances d'une facture |
| `POST /factures/{id}/relances` | Noter une relance (`mode`, `date`, `notes`) |

**Données :** table `facture_relances` (facture, client, niveau, date, moyen, courriel envoyé, automatique, notes, auteur).

Code :
- API : service `App\Services\Relances`, contrôleur `RelanceController`, tâche `App\Taches\RelancerFacturesEnRetard`, migration `2026_10_16_100033_creer_relances_paiement` ;
- web : écran `RelancesPage`, service `src/services/relances.ts`.

---

## 8. Tournées

### 8.1 Ce que c'est

Une **Tournée** est un regroupement de plusieurs Bons de Livraison pour un même camion un même jour. Elle permet d'organiser la logistique : quel camion livre quoi, dans quel ordre.

### 8.2 Champs d'une tournée

| Champ | Description |
|-------|-------------|
| `numero` | Ex: TRN-2026-0001 |
| `date_tournee` | Date de la tournée |
| `camion_id` | Le camion affecté |
| `zone` | Zone géographique (ex: "Nord", "Centre-ville") |
| `statut` | État actuel |
| `heure_depart` | Heure de départ réelle |
| `heure_retour` | Heure de retour réelle |

**Liaison BL (TourneeBon)**

| Champ | Description |
|-------|-------------|
| `tournee_id` | La tournée |
| `bon_id` | Le BL associé |
| `ordre_livraison` | Ordre de passage chez les clients |
| `statut` | `en_attente`, `livre`, `echec` |

### 8.3 Statuts d'une tournée

| Statut | Signification | Actions disponibles |
|--------|---------------|---------------------|
| `planifiee` | Créée, pas encore démarrée | Ajouter BL 📦, Retirer BL ➖, Démarrer ▶️, Supprimer 🗑️ |
| `en_cours` | En route | Terminer ⏹ |
| `terminee` | Retour dépôt | Lecture seule |
| `annulee` | Annulée | Lecture seule |

### 8.4 Flow d'une tournée

```
ÉTAPE 1 : CRÉER LA TOURNÉE
  → Endpoint: POST /tournees
  → Sélectionner la date → seuls les camions libres ce jour-là s'affichent
  → Sélectionner le camion
  → (optionnel) Définir la zone géographique
  → Statut: planifiee

ÉTAPE 2 : AJOUTER LES BONS DE LIVRAISON
  → Cliquer sur 📦+ dans la liste des tournées
  → Seuls les BL en statut "pret" s'affichent
  → Cliquer "Ajouter" sur chaque BL à inclure
  → Endpoint: POST /tournees/{id}/ajouter-bon
  → Chaque BL ajouté reçoit un numéro d'ordre automatique

ÉTAPE 3 : DÉMARRER LA TOURNÉE
  → Endpoint: POST /tournees/{id}/demarrer
  → Vérifie qu'il y a au moins 1 BL dans la tournée
  → Statut tournée: en_cours
  → Statut de tous les BL: en_livraison (automatique)
  → Statut de toutes les lignes des BL: charge (automatique)
  → Enregistre l'heure de départ

ÉTAPE 4 : ENREGISTRER LES LIVRAISONS (par BL)
  → Pour chaque BL, depuis BonsLivraisonPage :
    → Endpoint: POST /bons-livraison/{id}/livrer
    → Saisir les quantités livrées
    → Signature client (optionnel)

ÉTAPE 5 : TERMINER LA TOURNÉE
  → Endpoint: POST /tournees/{id}/terminer
  → Statut: terminee
  → Enregistre l'heure de retour
```

### 8.5 Retirer un BL d'une tournée

Le bouton ➖ apparaît sur chaque BL dans le modal détail de la tournée, si celle-ci est en statut `planifiee`.

- **Endpoint :** `DELETE /tournees/{id}/bon/{bonId}`
- Le BL repasse en statut `pret` — il peut être ajouté à une autre tournée
- Impossible si la tournée est déjà `en_cours` ou `terminee`

### 8.6 Supprimer une tournée

Le bouton 🗑️ est disponible dans le tableau des tournées, uniquement pour les tournées en statut `planifiee`.

- **Endpoint :** `DELETE /tournees/{id}`
- Supprime la tournée et tous ses liens avec les BL
- Les BL eux-mêmes ne sont pas affectés — ils restent en statut `pret`
- Impossible si la tournée est `en_cours` ou `terminee`

### 8.7 Réorganiser l'ordre de livraison

`PUT /tournees/{id}/ordre` permet de changer l'ordre des BL dans la tournée (pour optimiser le trajet). Seulement pour les tournées `planifiee`.

---

## 9. Flow complet bout en bout

```
┌─────────────────────────────────────────────────────────────────┐
│                    MODULE VENTES — FLOW COMPLET                  │
└─────────────────────────────────────────────────────────────────┘

═══ PHASE COMMERCIALE ════════════════════════════════════════════

1. CLIENT
   → Créer la fiche client (une seule fois)
   → Définir son plafond de crédit si nécessaire

2. DEVIS (optionnel)
   → Créer le devis avec les produits et prix
   → Envoyer → Client accepte ou refuse
   → Si accepté → Convertir en commande

3. COMMANDE CLIENT
   → Créer ou récupérer depuis devis converti
   → Soumettre pour validation
   → Accepter

═══ PHASE FACTURATION ════════════════════════════════════════════

4. FACTURE
   → Créer depuis la commande acceptée
   → Émettre la facture

═══ PHASE LOGISTIQUE ═════════════════════════════════════════════

5. BON DE LIVRAISON
   → Créer depuis la facture émise (automatique) OU créer depuis une facture manuelle
   → Démarrer la préparation dans l'entrepôt
   → Préparer les articles (saisir quantités)
   → Marquer prêt
   ↳ Si erreur ou annulation client → Annuler le BL 🚫 (statuts cree ou en_preparation)

6. TOURNÉE
   → Créer la tournée pour la date de livraison
   → Affecter un camion disponible
   → Ajouter le(s) BL à la tournée
   ↳ Si ajout par erreur → Retirer le BL ➖
   ↳ Si tournée à annuler → Supprimer la tournée 🗑️
   → Démarrer la tournée

═══ PHASE LIVRAISON ══════════════════════════════════════════════

7. LIVRAISON
   → Livrer au client
   → Enregistrer les quantités livrées
   → Faire signer le client
   → Terminer la tournée

═══ PHASE PAIEMENT ═══════════════════════════════════════════════

8. PAIEMENT
   → Recevoir le paiement du client
   → Enregistrer le paiement dans la facture
   → Facture passe en "payée"
   → Encours client se met à jour
```

---

## 10. Tous les statuts et transitions

### Devis
```
brouillon → envoye → accepte → [converti en commande]
                   ↘ refuse
(expire si date_validite dépassée)
```

### Commande Client
```
brouillon → soumise → acceptee → [créer facture]
                    ↘ refusee
```

### Facture
```
brouillon → emise → partiellement_payee → payee
                  ↘ (créer BL)
```

### Bon de Livraison
```
cree → en_preparation → pret → en_livraison → livre_complet
                                             ↘ livre_partiel → [nouvelle facture reliquat]
```

### Tournée
```
planifiee → en_cours → terminee
```

---

## 11. Livraison partielle — cas particulier

### Ce qui se passe

Quand un livreur arrive chez le client mais ne peut livrer qu'une partie des articles (rupture de stock, client absent pour une partie, refus client...) :

1. Le livreur saisit les quantités réellement livrées (< quantités prévues)
2. Le BL passe en `livre_partiel`
3. **Automatiquement**, le backend crée une **nouvelle facture** pour les articles non livrés :
   - Elle reprend les lignes non livrées avec les quantités restantes
   - Les prix sont recalculés proportionnellement
   - Elle part en statut `brouillon`
   - Elle référence la facture d'origine (`facture_mere_id`)
4. Cette nouvelle facture reprendra le circuit normal (émettre → nouveau BL → nouveau transport)

### Mouvements de stock générés

| Étape | Type de mouvement |
|-------|------------------|
| Préparation articles | `sortie_preparation` (secteur → zone préparation) |
| Livraison au client | `livraison_client` (camion → client) |

---

## 13. Documents PDF

Chaque document de vente a son PDF, ouvert par le bouton **PDF** de sa ligne dans la liste :

| Document | Route | Contenu propre |
|---|---|---|
| Devis | `GET /devis/{id}/pdf` | Total HT, mention « Montants hors taxes », zone « Bon pour accord » |
| Commande client | `GET /commandes-client/{id}/pdf` | Sous-total HT, taxes, total TTC |
| Facture | `GET /factures/{id}/pdf` | Sous-total HT, taxes, total TTC, payé, reste à payer |
| Note de crédit | `GET /avoirs/{id}/pdf` | Facture créditée, motif, taxes au prorata de la facture, imputé, remboursé, crédit disponible |
| Bon de livraison | `GET /bons-livraison/{id}/pdf` | Adresse de livraison, quantités à livrer / préparées / livrées, signatures |

**Communs à tous :**
- En-tête et pied de page tirés des paramètres de l'entreprise (Configuration → Entreprise) : nom, adresse, téléphone, courriel, numéros d'entreprise et de taxe. Sans paramètres, le nom de l'entreprise (tenant) est utilisé.
- Montants dans la devise de l'entreprise (`devise_symbole`).
- Taxes : une ligne par taxe quand le document utilise des groupes de taxes (ex. TVA 17,5 % puis CAC 10 %), sinon une ligne « TVA » avec son taux s'il est unique.
- Gabarit unique `resources/views/pdf/document-vente.blade.php`, alimenté par `App\Services\DocumentsVentePdf`. Police en sous-ensemble : environ 25 Ko par document.

**Envoi par courriel :** le bouton à côté du PDF envoie le document avec son PDF joint (voir FLOW-PARAMETRES §12.3).

**Accès :** comme le reste de l'API (jeton dans l'en-tête `Authorization`, entreprise par `X-Tenant-Slug`), donc jamais de jeton dans l'URL. Un document d'une autre entreprise renvoie 404. Le PDF existant des bons de commande fournisseur n'est pas modifié.

---

## 14. Écrans de vente : règles d'affichage communes

- **Devise** : les montants (devis, commandes, factures, clients, comptabilité) s'affichent avec le symbole de
  devise des paramètres de l'entreprise, au format de la langue (« 1 234,50 FCFA »), et non en euros d'office.
- **Dates** : une date de document (`date_facture`, `date_commande`…) est lue telle quelle, sans passer par un
  instant UTC qui la décalait d'un jour à l'ouest de Greenwich. Une date du jour proposée par défaut est celle du
  fuseau de l'utilisateur.
- **Listes paginées** : devis, commandes, factures, bons de livraison, tournées, clients et fournisseurs affichent
  le nombre total et passent de page en page. Un changement de filtre revient à la première page.
- **Lignes** : le détail d'une facture ou d'un bon de livraison donne la désignation du produit (même retiré du
  catalogue) ; une ligne nouvelle reprend le prix du catalogue tant qu'aucun prix n'est saisi.
- **Facture** : la liste donne le montant TTC (comme le reste à payer) ; le détail donne HT, TVA (ou le détail
  par taxe) et TTC.
- **Erreurs** : le message renvoyé par l'API (encours dépassé, statut incompatible, champ invalide) est affiché
  au lieu d'un échec silencieux.
- **Tournées** : la liste « Ajouter un bon » ne propose que les bons prêts et pas encore placés
  (`GET /bons-livraison?statut=pret&sans_tournee=1`). Ajouter, retirer, démarrer ou terminer renvoie la tournée
  complète (camion, bons, factures, clients).
