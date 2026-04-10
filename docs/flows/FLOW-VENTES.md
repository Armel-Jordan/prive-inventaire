# VENTES — Flow complet & détail de chaque étape

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-04-10

---

## TABLE DES MATIÈRES

1. [Vue d'ensemble du module](#1-vue-densemble-du-module)
2. [Clients](#2-clients)
3. [Devis](#3-devis)
4. [Commandes Client](#4-commandes-client)
5. [Factures](#5-factures)
6. [Bons de Livraison](#6-bons-de-livraison)
7. [Camions](#7-camions)
8. [Tournées](#8-tournées)
9. [Flow complet bout en bout](#9-flow-complet-bout-en-bout)
10. [Tous les statuts et transitions](#10-tous-les-statuts-et-transitions)
11. [Livraison partielle — cas particulier](#11-livraison-partielle--cas-particulier)

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
1. Une **Commande Client** est créée avec les mêmes lignes et prix
2. La commande est en statut `brouillon` (elle peut encore être modifiée)
3. Le devis garde son statut `accepte` (lien de traçabilité)
4. L'ID de la commande est stocké dans le devis (`com_entete_id`)

### 3.6 Points d'attention

- La date de validité est informative — le passage en `expire` n'est pas automatique côté backend aujourd'hui (c'est le frontend qui filtre l'affichage).
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
| `reste_a_payer` | `montant_ttc - montant_paye` |

### 5.3 Statuts de la facture

| Statut | Signification | Actions disponibles |
|--------|---------------|---------------------|
| `brouillon` | Créée mais pas encore envoyée | Émettre 📤, Supprimer 🗑️ |
| `emise` | Envoyée au client | Enregistrer paiement 💵, Créer BL 📦 |
| `partiellement_payee` | Paiement partiel reçu | Enregistrer paiement 💵 |
| `payee` | Intégralement payée | Lecture seule |

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
          → Crée un BonLivraison lié à cette facture

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

### 5.7 Facture de reliquat (livraison partielle)

Quand un BL est livré partiellement, le système génère automatiquement une **nouvelle facture** pour les articles non livrés. Cette nouvelle facture :
- Référence la facture d'origine via `facture_mere_id`
- Contient uniquement les lignes non livrées avec les quantités restantes
- Repart en statut `brouillon`

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
| `cree` | BL créé, préparation pas encore commencée | Démarrer préparation ▶️ |
| `en_preparation` | Préparation en cours | Modifier quantités préparées, Marquer prêt ✅ |
| `pret` | Prêt à charger | Ajouter à une tournée 📦 |
| `en_livraison` | Chargé dans un camion, en route | Enregistrer livraison 🚚 |
| `livre_complet` | Tout livré | Terminé |
| `livre_partiel` | Livraison partielle | Facture de reliquat créée auto |
| `annule` | Annulé | Lecture seule |

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
| `planifiee` | Créée, pas encore démarrée | Ajouter BL 📦, Démarrer ▶️ |
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

Si on s'est trompé ou si un client annule :
- Endpoint : `DELETE /tournees/{id}/bon/{bonId}`
- Possible seulement si la tournée est en `planifiee`

### 8.6 Réorganiser l'ordre de livraison

`PUT /tournees/{id}/ordre` permet de changer l'ordre des BL dans la tournée (pour optimiser le trajet).

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
   → Créer depuis la facture émise (automatique)
   → Démarrer la préparation dans l'entrepôt
   → Préparer les articles (saisir quantités)
   → Marquer prêt

6. TOURNÉE
   → Créer la tournée pour la date de livraison
   → Affecter un camion disponible
   → Ajouter le BL à la tournée
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
