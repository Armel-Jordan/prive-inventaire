# Analyse Fonctionnelle - Module Gestion Clients & Ventes
## Prise Inventaire - Module Commercial

**Date de création** : 6 Mars 2026  
**Version** : 1.0  
**Statut** : Analyse validée - En attente d'implémentation

---

## 📋 Table des matières

1. [Contexte et objectifs](#1-contexte-et-objectifs)
2. [Flux métier complet](#2-flux-métier-complet)
3. [Entités et relations](#3-entités-et-relations)
4. [Spécifications détaillées](#4-spécifications-détaillées)
5. [Règles métier](#5-règles-métier)
6. [Schéma de base de données](#6-schéma-de-base-de-données)
7. [API Endpoints prévus](#7-api-endpoints-prévus)
8. [Permissions](#8-permissions)
9. [Importance stratégique](#9-importance-stratégique)

---

## 1. Contexte et objectifs

### 1.1 Pourquoi ce module ?

Le module **Gestion Clients & Ventes** est essentiel pour transformer Prise Inventaire d'un simple outil de gestion de stock en une **solution complète de gestion commerciale**. Il permet de :

- **Gérer le cycle de vente complet** : de la commande client jusqu'à la livraison
- **Optimiser la logistique** : préparation des commandes, gestion des camions, tournées de livraison
- **Améliorer la trésorerie** : suivi des factures, conditions de paiement flexibles, encours clients
- **Réduire les erreurs** : automatisation de la facturation et des bons de livraison
- **Traçabilité complète** : historique de toutes les transactions avec chaque client

### 1.2 Bénéfices attendus

| Bénéfice | Impact |
|----------|--------|
| **Gain de temps** | Automatisation facture → bon de livraison → préparation |
| **Réduction erreurs** | Plus de saisie manuelle, calculs automatiques |
| **Meilleur suivi** | Visibilité en temps réel sur les commandes et livraisons |
| **Optimisation logistique** | Tournées optimisées par zone géographique |
| **Gestion financière** | Contrôle des encours et conditions de paiement |

---

## 2. Flux métier complet

### 2.1 Diagramme du flux

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           FLUX COMMANDE CLIENT                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────┐                                                                │
│  │  CLIENT  │ ◀── Création du client avec conditions de paiement            │
│  └────┬─────┘                                                                │
│       │                                                                      │
│       ▼                                                                      │
│  ┌──────────────┐                                                            │
│  │  COMMANDE    │ ◀── Client passe commande (produits + quantités)          │
│  │  (brouillon) │                                                            │
│  └────┬─────────┘                                                            │
│       │                                                                      │
│       ▼                                                                      │
│  ┌──────────────┐     ┌──────────────┐                                      │
│  │  VALIDATION  │────▶│   REFUSÉE    │ ◀── Fin du processus                 │
│  │  (Admin/Mgr) │     └──────────────┘                                      │
│  └────┬─────────┘                                                            │
│       │ Acceptée                                                             │
│       ▼                                                                      │
│  ┌──────────────┐                                                            │
│  │   FACTURE    │ ◀── Génération automatique avec TVA et conditions         │
│  │   (émise)    │                                                            │
│  └────┬─────────┘                                                            │
│       │                                                                      │
│       ▼                                                                      │
│  ┌──────────────┐                                                            │
│  │     BON      │ ◀── Conversion facture → bon de livraison                 │
│  │  LIVRAISON   │                                                            │
│  └────┬─────────┘                                                            │
│       │                                                                      │
│       ▼                                                                      │
│  ┌──────────────┐                                                            │
│  │ PRÉPARATION  │ ◀── Picking des produits (diminue le stock)               │
│  │  COMMANDE    │                                                            │
│  └────┬─────────┘                                                            │
│       │                                                                      │
│       ▼                                                                      │
│  ┌──────────────┐     ┌──────────────┐                                      │
│  │   CAMION     │ ou  │   RETRAIT    │ ◀── Client vient chercher            │
│  │ (entreprise) │     │   CLIENT     │                                      │
│  └────┬─────────┘     └──────────────┘                                      │
│       │                                                                      │
│       ▼                                                                      │
│  ┌──────────────┐                                                            │
│  │   TOURNÉE    │ ◀── Regroupement par zone/ville                           │
│  │  LIVRAISON   │                                                            │
│  └────┬─────────┘                                                            │
│       │                                                                      │
│       ▼                                                                      │
│  ┌──────────────┐     ┌──────────────┐                                      │
│  │   LIVRÉ      │ ou  │    LIVRÉ     │                                      │
│  │   COMPLET    │     │   PARTIEL    │                                      │
│  └──────────────┘     └────┬─────────┘                                      │
│                            │                                                 │
│                            ▼                                                 │
│                       ┌──────────────┐                                      │
│                       │  NOUVELLE    │ ◀── Facture + commande pour reste    │
│                       │   FACTURE    │                                      │
│                       └──────────────┘                                      │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 2.2 Description des étapes

| Étape | Acteur | Action | Résultat |
|-------|--------|--------|----------|
| 1 | Admin/Manager | Créer le client | Client enregistré avec conditions |
| 2 | Employé | Saisir la commande | Commande en brouillon |
| 3 | Admin/Manager | Valider ou refuser | Commande acceptée ou refusée |
| 4 | Système | Générer la facture | Facture émise |
| 5 | Employé | Convertir en bon de livraison | BL créé |
| 6 | Préparateur | Préparer la commande | Stock diminué, BL prêt |
| 7 | Logistique | Charger le camion | BL assigné à une tournée |
| 8 | Livreur | Effectuer la livraison | BL livré (complet ou partiel) |
| 9 | Système | Si partiel, régénérer | Nouvelle facture pour le reste |

---

## 3. Entités et relations

### 3.1 Diagramme des entités

```
┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐
│     CLIENTS     │       │  CONDITIONS     │       │    CAMIONS      │
│─────────────────│       │   PAIEMENT      │       │─────────────────│
│ id              │       │─────────────────│       │ id              │
│ code            │       │ id              │       │ immatriculation │
│ raison_sociale  │◀──────│ client_id       │       │ marque          │
│ adresse_fact    │       │ nb_jours        │       │ capacite_kg     │
│ adresse_livr    │       │ pourcentage     │       │ type            │
│ encours_max     │       │ ordre           │       │ actif           │
│ ...             │       └─────────────────┘       └────────┬────────┘
└────────┬────────┘                                          │
         │                                                   │
         │                                                   │
         ▼                                                   │
┌─────────────────┐       ┌─────────────────┐               │
│  COM_CLIENT     │       │  COM_CLIENT     │               │
│    ENTETE       │       │    LIGNE        │               │
│─────────────────│       │─────────────────│               │
│ id              │       │ id              │               │
│ numero          │──────▶│ com_entete_id   │               │
│ client_id       │       │ produit_id      │               │
│ date_commande   │       │ quantite        │               │
│ statut          │       │ prix_unitaire   │               │
│ montant_total   │       │ remise_ligne    │               │
└────────┬────────┘       │ montant_ligne   │               │
         │                └─────────────────┘               │
         │                                                   │
         ▼                                                   │
┌─────────────────┐       ┌─────────────────┐               │
│    FACTURES     │       │ FACTURE_LIGNES  │               │
│─────────────────│       │─────────────────│               │
│ id              │       │ id              │               │
│ numero          │──────▶│ facture_id      │               │
│ commande_id     │       │ produit_id      │               │
│ facture_mere_id │       │ quantite        │               │
│ date_facture    │       │ prix_unitaire   │               │
│ statut          │       │ taux_tva        │               │
│ montant_ht      │       │ montant_ht      │               │
│ montant_tva     │       │ montant_ttc     │               │
│ montant_ttc     │       └─────────────────┘               │
└────────┬────────┘                                          │
         │                                                   │
         ▼                                                   │
┌─────────────────┐       ┌─────────────────┐               │
│ BONS_LIVRAISON  │       │    BL_LIGNES    │               │
│─────────────────│       │─────────────────│               │
│ id              │       │ id              │               │
│ numero          │──────▶│ bon_id          │               │
│ facture_id      │       │ produit_id      │               │
│ mode_livraison  │       │ qte_a_livrer    │               │
│ statut          │       │ qte_preparee    │               │
│ date_livraison  │       │ qte_livree      │               │
└────────┬────────┘       │ statut_ligne    │               │
         │                └─────────────────┘               │
         │                                                   │
         ▼                                                   │
┌─────────────────┐       ┌─────────────────┐               │
│    TOURNEES     │       │  TOURNEE_BONS   │               │
│─────────────────│       │─────────────────│               │
│ id              │       │ id              │               │
│ date_tournee    │──────▶│ tournee_id      │               │
│ camion_id       │◀──────│ bon_livraison_id│               │
│ livreur_id      │       │ ordre_livraison │               │
│ zone            │       │ statut          │               │
│ statut          │       └─────────────────┘               │
└─────────────────┘                                          │
         ▲                                                   │
         └───────────────────────────────────────────────────┘
```

---

## 4. Spécifications détaillées

### 4.1 Clients

| Champ | Type | Description | Obligatoire |
|-------|------|-------------|-------------|
| `code` | varchar(20) | Code unique auto-généré (CLI-0001) | Oui |
| `raison_sociale` | varchar(255) | Nom de l'entreprise | Oui |
| `adresse_facturation` | text | Adresse pour les factures | Oui |
| `adresse_livraison` | text | Adresse de livraison (peut différer) | Non |
| `ville` | varchar(100) | Ville (pour regroupement tournées) | Oui |
| `code_postal` | varchar(10) | Code postal | Oui |
| `telephone` | varchar(20) | Téléphone principal | Non |
| `email` | varchar(255) | Email pour envoi factures | Non |
| `contact_nom` | varchar(100) | Nom du contact principal | Non |
| `contact_telephone` | varchar(20) | Téléphone du contact | Non |
| `encours_max` | decimal(12,2) | Encours maximum autorisé (€) | Non |
| `encours_actuel` | decimal(12,2) | Encours actuel calculé | Auto |
| `taux_remise_global` | decimal(5,2) | Remise par défaut (%) | Non |
| `actif` | boolean | Client actif/inactif | Oui |

### 4.2 Conditions de paiement (flexible)

Permet de définir des **tranches de paiement personnalisées** par client.

| Champ | Type | Description |
|-------|------|-------------|
| `client_id` | FK | Référence au client |
| `libelle` | varchar(100) | Ex: "30% à la commande" |
| `nb_jours` | int | Nombre de jours après facture (0 = comptant) |
| `pourcentage` | decimal(5,2) | Pourcentage du montant total |
| `ordre` | int | Ordre de la tranche (1, 2, 3...) |

**Exemples de configurations :**

```
Client A - Comptant :
  └─ Tranche 1 : 100% à 0 jours

Client B - 30 jours :
  └─ Tranche 1 : 100% à 30 jours

Client C - 30/60/90 jours :
  ├─ Tranche 1 : 30% à 30 jours
  ├─ Tranche 2 : 30% à 60 jours
  └─ Tranche 3 : 40% à 90 jours

Client D - Acompte + solde :
  ├─ Tranche 1 : 50% à 0 jours (acompte)
  └─ Tranche 2 : 50% à 30 jours (solde)
```

### 4.3 Commande Client

**Entête :**

| Champ | Type | Description |
|-------|------|-------------|
| `numero` | varchar(20) | Numéro auto (CMD-2026-0001) |
| `client_id` | FK | Référence au client |
| `date_commande` | date | Date de la commande |
| `date_livraison_souhaitee` | date | Date souhaitée par le client |
| `statut` | enum | Voir statuts ci-dessous |
| `remise_globale` | decimal(5,2) | Remise sur le total (%) |
| `montant_ht` | decimal(12,2) | Montant HT |
| `montant_tva` | decimal(12,2) | Montant TVA |
| `montant_ttc` | decimal(12,2) | Montant TTC |
| `notes` | text | Remarques |
| `motif_refus` | text | Si refusée, raison |
| `validee_par` | FK | Qui a validé/refusé |
| `created_by` | FK | Qui a créé |

**Lignes :**

| Champ | Type | Description |
|-------|------|-------------|
| `produit_id` | FK | Référence au produit |
| `quantite` | int | Quantité commandée |
| `prix_unitaire_ht` | decimal(10,2) | Prix unitaire HT |
| `taux_tva` | decimal(5,2) | Taux TVA (20, 10, 5.5, 0) |
| `remise_ligne` | decimal(5,2) | Remise sur cette ligne (%) |
| `montant_ht` | decimal(12,2) | Montant HT ligne |
| `montant_ttc` | decimal(12,2) | Montant TTC ligne |

**Statuts commande :**

| Statut | Description |
|--------|-------------|
| `brouillon` | En cours de saisie |
| `en_attente` | Soumise, en attente de validation |
| `acceptee` | Validée par admin/manager |
| `refusee` | Refusée (avec motif) |
| `facturee` | Facture générée |
| `annulee` | Annulée |

### 4.4 Facture

| Champ | Type | Description |
|-------|------|-------------|
| `numero` | varchar(20) | Numéro séquentiel (FAC-2026-0001) |
| `commande_id` | FK | Commande source |
| `facture_mere_id` | FK | Si issue d'une livraison partielle |
| `client_id` | FK | Client |
| `date_facture` | date | Date d'émission |
| `date_echeance` | date | Date d'échéance principale |
| `statut` | enum | brouillon, emise, partiellement_payee, payee, annulee |
| `montant_ht` | decimal(12,2) | Total HT |
| `montant_tva` | decimal(12,2) | Total TVA |
| `montant_ttc` | decimal(12,2) | Total TTC |
| `montant_paye` | decimal(12,2) | Montant déjà payé |
| `reste_a_payer` | decimal(12,2) | Reste à payer |

### 4.5 Bon de Livraison

| Champ | Type | Description |
|-------|------|-------------|
| `numero` | varchar(20) | Numéro (BL-2026-0001) |
| `facture_id` | FK | Facture associée |
| `mode_livraison` | enum | entreprise, retrait_client |
| `statut` | enum | Voir ci-dessous |
| `date_preparation` | datetime | Début préparation |
| `date_pret` | datetime | Fin préparation |
| `date_livraison` | datetime | Date livraison effective |
| `preparateur_id` | FK | Qui a préparé |
| `signature_client` | text | Signature (base64 ou référence) |
| `notes_livraison` | text | Remarques du livreur |

**Statuts bon de livraison :**

| Statut | Description |
|--------|-------------|
| `cree` | Bon créé, en attente de préparation |
| `en_preparation` | Picking en cours |
| `pret` | Préparé, prêt à charger |
| `en_livraison` | En cours de livraison |
| `livre_complet` | Tout livré |
| `livre_partiel` | Partiellement livré |
| `annule` | Annulé |

**Lignes bon de livraison :**

| Champ | Type | Description |
|-------|------|-------------|
| `produit_id` | FK | Produit |
| `quantite_a_livrer` | int | Quantité prévue |
| `quantite_preparee` | int | Quantité préparée |
| `quantite_livree` | int | Quantité effectivement livrée |
| `statut_ligne` | enum | a_preparer, en_cours, prepare, charge, livre |

### 4.6 Camions

| Champ | Type | Description |
|-------|------|-------------|
| `immatriculation` | varchar(20) | Plaque d'immatriculation |
| `marque` | varchar(50) | Marque du véhicule |
| `modele` | varchar(50) | Modèle |
| `type` | enum | camionnette, camion, semi_remorque |
| `capacite_kg` | int | Capacité en kg |
| `capacite_m3` | decimal(5,2) | Capacité en m³ |
| `date_controle_technique` | date | Prochaine date CT |
| `actif` | boolean | En service ou non |

### 4.7 Tournées

| Champ | Type | Description |
|-------|------|-------------|
| `numero` | varchar(20) | Numéro (TRN-2026-0001) |
| `date_tournee` | date | Date de la tournée |
| `camion_id` | FK | Camion assigné |
| `livreur_id` | FK | Employé livreur |
| `zone` | varchar(100) | Zone/ville de livraison |
| `statut` | enum | planifiee, en_cours, terminee, annulee |
| `heure_depart` | time | Heure de départ prévue |
| `heure_retour` | time | Heure de retour effective |
| `km_depart` | int | Kilométrage départ |
| `km_retour` | int | Kilométrage retour |

**Bons dans la tournée :**

| Champ | Type | Description |
|-------|------|-------------|
| `tournee_id` | FK | Tournée |
| `bon_livraison_id` | FK | Bon de livraison |
| `ordre_livraison` | int | Ordre dans la tournée |
| `heure_livraison` | time | Heure de livraison effective |
| `statut` | enum | en_attente, livre, echec |
| `motif_echec` | text | Si échec, raison |

---

## 5. Règles métier

### 5.1 Validation commande

```
SI client.encours_actuel + commande.montant_ttc > client.encours_max
ALORS
    Alerte "Dépassement encours" (validation possible avec autorisation)
FIN SI
```

### 5.2 Génération facture

```
QUAND commande.statut = 'acceptee'
ALORS
    Créer facture avec :
    - Numéro séquentiel FAC-YYYY-NNNN
    - Copie des lignes commande
    - Calcul TVA par ligne
    - Date échéance selon conditions paiement client
FIN
```

### 5.3 Préparation et stock

```
QUAND bon_livraison_ligne.statut = 'prepare'
ALORS
    produit.stock -= bon_livraison_ligne.quantite_preparee
    Créer mouvement_stock (type: 'sortie_preparation')
FIN
```

### 5.4 Livraison partielle

```
SI bon_livraison.quantite_livree < bon_livraison.quantite_a_livrer
ALORS
    bon_livraison.statut = 'livre_partiel'
    
    Créer nouvelle_facture avec :
    - facture_mere_id = facture_originale.id
    - Lignes = quantités non livrées
    - Numéro séquentiel
    
    Créer nouvelle_commande liée (optionnel)
    
    Mettre à jour facture_originale.montant pour quantités livrées
FIN SI
```

### 5.5 Encours client

```
client.encours_actuel = SUM(factures.reste_a_payer)
WHERE factures.client_id = client.id
AND factures.statut IN ('emise', 'partiellement_payee')
```

---

## 5bis. Mouvements d'inventaire et Localisation

### 5bis.1 Principe de traçabilité

Chaque produit doit avoir une **localisation connue à tout moment**. Le système enregistre automatiquement les mouvements de stock et les changements de localisation à chaque étape du processus.

### 5bis.2 Types de localisation

| Type | Description | Exemple |
|------|-------------|---------|
| `secteur` | Emplacement fixe dans l'entrepôt | Entrepôt A - Zone B - Rayon 3 |
| `zone_preparation` | Zone de picking/préparation | Zone Préparation Nord |
| `camion` | Véhicule de livraison | Camion AB-123-CD |
| `client` | Livré au client | Client XYZ |
| `retour` | Zone de retour/litige | Zone Retours |

### 5bis.3 Flux de localisation par étape

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                    TRAÇABILITÉ LOCALISATION PRODUIT                              │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                  │
│  ÉTAPE 1 : COMMANDE ACCEPTÉE                                                    │
│  ┌─────────────────────────────────────────────────────────────────────────┐   │
│  │ Action    : Réservation du stock                                        │   │
│  │ Mouvement : RESERVATION (stock disponible → stock réservé)              │   │
│  │ Localisation : Inchangée (produit toujours dans son secteur)            │   │
│  │ Stock dispo : -N unités                                                  │   │
│  │ Stock réservé : +N unités                                                │   │
│  └─────────────────────────────────────────────────────────────────────────┘   │
│                              │                                                   │
│                              ▼                                                   │
│  ÉTAPE 2 : PRÉPARATION EN COURS                                                 │
│  ┌─────────────────────────────────────────────────────────────────────────┐   │
│  │ Action    : Picking des produits                                         │   │
│  │ Mouvement : SORTIE_PREPARATION                                           │   │
│  │ Localisation : Secteur → Zone de préparation                             │   │
│  │ Stock réservé : -N unités                                                │   │
│  │ Stock en préparation : +N unités                                         │   │
│  └─────────────────────────────────────────────────────────────────────────┘   │
│                              │                                                   │
│                              ▼                                                   │
│  ÉTAPE 3 : CHARGEMENT CAMION                                                    │
│  ┌─────────────────────────────────────────────────────────────────────────┐   │
│  │ Action    : Chargement dans le véhicule                                  │   │
│  │ Mouvement : CHARGEMENT_CAMION                                            │   │
│  │ Localisation : Zone préparation → Camion (immatriculation)               │   │
│  │ Stock en préparation : -N unités                                         │   │
│  │ Stock en transit : +N unités                                             │   │
│  └─────────────────────────────────────────────────────────────────────────┘   │
│                              │                                                   │
│                              ▼                                                   │
│  ÉTAPE 4a : LIVRAISON COMPLÈTE                                                  │
│  ┌─────────────────────────────────────────────────────────────────────────┐   │
│  │ Action    : Livraison au client                                          │   │
│  │ Mouvement : LIVRAISON_CLIENT                                             │   │
│  │ Localisation : Camion → Client (sorti du stock)                          │   │
│  │ Stock en transit : -N unités                                             │   │
│  │ Stock total : -N unités (sortie définitive)                              │   │
│  └─────────────────────────────────────────────────────────────────────────┘   │
│                                                                                  │
│  ÉTAPE 4b : LIVRAISON PARTIELLE                                                 │
│  ┌─────────────────────────────────────────────────────────────────────────┐   │
│  │ Action    : Livraison partielle + retour                                 │   │
│  │ Mouvement 1 : LIVRAISON_CLIENT (quantité livrée)                         │   │
│  │ Mouvement 2 : RETOUR_CAMION (quantité non livrée)                        │   │
│  │ Localisation livrée : Camion → Client                                    │   │
│  │ Localisation reste : Camion → Zone retour ou Secteur origine             │   │
│  └─────────────────────────────────────────────────────────────────────────┘   │
│                                                                                  │
│  CAS SPÉCIAL : RETRAIT CLIENT                                                   │
│  ┌─────────────────────────────────────────────────────────────────────────┐   │
│  │ Action    : Client vient chercher                                        │   │
│  │ Mouvement : RETRAIT_CLIENT                                               │   │
│  │ Localisation : Zone préparation → Client (direct, sans camion)           │   │
│  └─────────────────────────────────────────────────────────────────────────┘   │
│                                                                                  │
└─────────────────────────────────────────────────────────────────────────────────┘
```

### 5bis.4 Table des mouvements d'inventaire

| Champ | Type | Description |
|-------|------|-------------|
| `id` | bigint | Identifiant unique |
| `produit_id` | FK | Produit concerné |
| `type_mouvement` | enum | Voir types ci-dessous |
| `quantite` | int | Quantité (positive ou négative) |
| `localisation_source_type` | enum | secteur, zone_preparation, camion |
| `localisation_source_id` | int | ID du secteur/camion source |
| `localisation_dest_type` | enum | secteur, zone_preparation, camion, client |
| `localisation_dest_id` | int | ID de la destination |
| `reference_type` | varchar | Type de document (commande, bl, facture) |
| `reference_id` | int | ID du document lié |
| `motif` | text | Description du mouvement |
| `effectue_par` | FK | Employé qui a fait le mouvement |
| `created_at` | datetime | Date/heure du mouvement |

### 5bis.5 Types de mouvements

| Type | Description | Impact stock |
|------|-------------|--------------|
| `reservation` | Réservation pour commande | Dispo → Réservé |
| `annulation_reservation` | Annulation commande | Réservé → Dispo |
| `sortie_preparation` | Picking pour préparation | Réservé → En prépa |
| `chargement_camion` | Chargement véhicule | En prépa → Transit |
| `livraison_client` | Livraison effective | Transit → Sorti |
| `retour_camion` | Retour après livraison partielle | Transit → Dispo |
| `retrait_client` | Client vient chercher | En prépa → Sorti |

### 5bis.6 Table de localisation produit (temps réel)

Pour savoir où est chaque unité de produit à tout moment :

| Champ | Type | Description |
|-------|------|-------------|
| `id` | bigint | Identifiant |
| `produit_id` | FK | Produit |
| `localisation_type` | enum | secteur, zone_preparation, camion |
| `localisation_id` | int | ID du lieu |
| `quantite` | int | Quantité à cet endroit |
| `statut` | enum | disponible, reserve, en_preparation, en_transit |
| `commande_id` | FK | Si réservé, pour quelle commande |
| `updated_at` | datetime | Dernière mise à jour |

### 5bis.7 Règles métier localisation

```
RÈGLE 1 : Réservation à la commande
─────────────────────────────────────
QUAND commande.statut passe à 'acceptee'
ALORS
    POUR CHAQUE ligne de commande :
        Créer mouvement (type: 'reservation')
        produit_localisation.statut = 'reserve'
        produit_localisation.commande_id = commande.id
    FIN POUR
FIN

RÈGLE 2 : Préparation
─────────────────────────────────────
QUAND bon_livraison_ligne.statut passe à 'en_preparation'
ALORS
    Créer mouvement (type: 'sortie_preparation')
    localisation_source = secteur actuel
    localisation_dest = zone_preparation
    Mettre à jour produit_localisation
FIN

RÈGLE 3 : Chargement camion
─────────────────────────────────────
QUAND bon_livraison.statut passe à 'en_livraison'
ALORS
    POUR CHAQUE ligne préparée :
        Créer mouvement (type: 'chargement_camion')
        localisation_source = zone_preparation
        localisation_dest = camion (tournee.camion_id)
        produit_localisation.localisation_type = 'camion'
        produit_localisation.localisation_id = camion.id
        produit_localisation.statut = 'en_transit'
    FIN POUR
FIN

RÈGLE 4 : Livraison
─────────────────────────────────────
QUAND tournee_bon.statut passe à 'livre'
ALORS
    POUR CHAQUE ligne livrée :
        Créer mouvement (type: 'livraison_client')
        Supprimer produit_localisation (sorti du stock)
    FIN POUR
    
    SI livraison partielle :
        POUR CHAQUE ligne non livrée :
            Créer mouvement (type: 'retour_camion')
            produit_localisation.localisation_type = 'secteur'
            produit_localisation.statut = 'disponible'
        FIN POUR
    FIN SI
FIN
```

### 5bis.8 Vue temps réel : Où est mon produit ?

Le système permet de répondre instantanément à la question "Où sont les X unités du produit Y ?"

```
Exemple : Produit "Café Premium" - 150 unités totales

┌─────────────────────────────────────────────────────────┐
│ LOCALISATION TEMPS RÉEL                                 │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  📦 Entrepôt A - Zone B      : 80 unités (disponible)  │
│  📦 Entrepôt A - Zone C      : 20 unités (disponible)  │
│  🔒 Réservé CMD-2026-0045    : 15 unités               │
│  📋 Zone Préparation Nord    : 10 unités (en prépa)    │
│  🚚 Camion AB-123-CD         : 25 unités (en transit)  │
│                                                         │
│  TOTAL EN STOCK              : 150 unités              │
│  DISPONIBLE À LA VENTE       : 100 unités              │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

---

## 6. Schéma de base de données

### Tables à créer

| Table | Description |
|-------|-------------|
| `clients` | Informations clients |
| `client_conditions_paiement` | Tranches de paiement par client |
| `com_client_entete` | Entêtes commandes clients |
| `com_client_ligne` | Lignes commandes clients |
| `factures` | Factures |
| `facture_lignes` | Lignes factures |
| `facture_echeances` | Échéances de paiement |
| `facture_paiements` | Paiements reçus |
| `bons_livraison` | Bons de livraison |
| `bon_livraison_lignes` | Lignes BL |
| `camions` | Flotte de véhicules |
| `tournees` | Tournées de livraison |
| `tournee_bons` | Bons dans une tournée |
| `mouvements_inventaire` | Historique de tous les mouvements de stock |
| `produit_localisations` | Localisation temps réel de chaque produit |
| `zones_preparation` | Zones de préparation/picking |

### Relations principales

```sql
clients (1) ──────────────────── (N) com_client_entete
clients (1) ──────────────────── (N) client_conditions_paiement
com_client_entete (1) ─────────── (N) com_client_ligne
com_client_entete (1) ─────────── (1) factures
factures (1) ──────────────────── (N) facture_lignes
factures (1) ──────────────────── (1) bons_livraison
factures (1) ──────────────────── (N) factures (facture_mere_id - auto-référence)
bons_livraison (1) ────────────── (N) bon_livraison_lignes
camions (1) ───────────────────── (N) tournees
tournees (1) ──────────────────── (N) tournee_bons
tournee_bons (N) ──────────────── (1) bons_livraison

-- Relations mouvements et localisation
produits (1) ──────────────────── (N) mouvements_inventaire
produits (1) ──────────────────── (N) produit_localisations
secteurs (1) ──────────────────── (N) produit_localisations
camions (1) ───────────────────── (N) produit_localisations (quand en transit)
zones_preparation (1) ─────────── (N) produit_localisations
com_client_entete (1) ─────────── (N) mouvements_inventaire (référence)
bons_livraison (1) ────────────── (N) mouvements_inventaire (référence)
```

---

## 7. API Endpoints prévus

### 7.1 Clients

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/clients` | Liste paginée |
| GET | `/api/clients/actifs` | Clients actifs uniquement |
| GET | `/api/clients/{id}` | Détail client |
| POST | `/api/clients` | Créer client |
| PUT | `/api/clients/{id}` | Modifier client |
| DELETE | `/api/clients/{id}` | Supprimer client |
| GET | `/api/clients/{id}/conditions-paiement` | Conditions du client |
| POST | `/api/clients/{id}/conditions-paiement` | Définir conditions |

### 7.2 Commandes Clients

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/commandes-client` | Liste avec filtres |
| GET | `/api/commandes-client/{id}` | Détail commande |
| POST | `/api/commandes-client` | Créer commande |
| PUT | `/api/commandes-client/{id}` | Modifier (si brouillon) |
| POST | `/api/commandes-client/{id}/soumettre` | Soumettre pour validation |
| POST | `/api/commandes-client/{id}/accepter` | Accepter |
| POST | `/api/commandes-client/{id}/refuser` | Refuser |
| DELETE | `/api/commandes-client/{id}` | Supprimer (si brouillon) |

### 7.3 Factures

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/factures` | Liste avec filtres |
| GET | `/api/factures/{id}` | Détail facture |
| POST | `/api/factures/{id}/emettre` | Émettre la facture |
| POST | `/api/factures/{id}/paiement` | Enregistrer paiement |
| GET | `/api/factures/{id}/pdf` | Télécharger PDF |
| POST | `/api/factures/{id}/creer-bl` | Créer bon de livraison |

### 7.4 Bons de Livraison

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/bons-livraison` | Liste |
| GET | `/api/bons-livraison/{id}` | Détail |
| POST | `/api/bons-livraison/{id}/preparer` | Démarrer préparation |
| PUT | `/api/bons-livraison/{id}/lignes` | Mettre à jour quantités préparées |
| POST | `/api/bons-livraison/{id}/pret` | Marquer prêt |
| POST | `/api/bons-livraison/{id}/livrer` | Enregistrer livraison |
| GET | `/api/bons-livraison/{id}/pdf` | Télécharger PDF |

### 7.5 Camions

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/camions` | Liste |
| POST | `/api/camions` | Créer |
| PUT | `/api/camions/{id}` | Modifier |
| DELETE | `/api/camions/{id}` | Supprimer |
| GET | `/api/camions/disponibles` | Camions disponibles à une date |

### 7.6 Tournées

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/tournees` | Liste |
| POST | `/api/tournees` | Créer tournée |
| POST | `/api/tournees/{id}/ajouter-bon` | Ajouter un BL |
| POST | `/api/tournees/{id}/demarrer` | Démarrer tournée |
| POST | `/api/tournees/{id}/terminer` | Terminer tournée |
| PUT | `/api/tournees/{id}/ordre` | Réorganiser l'ordre |

### 7.7 Mouvements d'inventaire

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/mouvements-inventaire` | Liste avec filtres (date, produit, type) |
| GET | `/api/mouvements-inventaire/{id}` | Détail d'un mouvement |
| GET | `/api/produits/{id}/mouvements` | Historique mouvements d'un produit |
| GET | `/api/produits/{id}/localisation` | Localisation temps réel d'un produit |

### 7.8 Localisations

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/localisations/produits` | Vue globale : où sont tous les produits |
| GET | `/api/localisations/secteur/{id}` | Produits dans un secteur |
| GET | `/api/localisations/camion/{id}` | Produits dans un camion |
| GET | `/api/localisations/zone-preparation/{id}` | Produits en zone de préparation |
| GET | `/api/zones-preparation` | Liste des zones de préparation |
| POST | `/api/zones-preparation` | Créer une zone |
| PUT | `/api/zones-preparation/{id}` | Modifier une zone |
| DELETE | `/api/zones-preparation/{id}` | Supprimer une zone |

---

## 8. Permissions

### Nouvelles permissions

| Module | Permission | Description |
|--------|------------|-------------|
| `clients` | read | Voir les clients |
| `clients` | write | Créer/modifier clients |
| `clients` | delete | Supprimer clients |
| `commandes_client` | read | Voir les commandes |
| `commandes_client` | write | Créer/modifier commandes |
| `commandes_client` | valider | Accepter/refuser commandes |
| `factures` | read | Voir les factures |
| `factures` | write | Créer/émettre factures |
| `factures` | paiement | Enregistrer paiements |
| `bons_livraison` | read | Voir les BL |
| `bons_livraison` | write | Créer/modifier BL |
| `bons_livraison` | preparer | Préparer commandes |
| `bons_livraison` | livrer | Enregistrer livraisons |
| `camions` | read | Voir la flotte |
| `camions` | write | Gérer la flotte |
| `tournees` | read | Voir les tournées |
| `tournees` | write | Planifier tournées |
| `mouvements_inventaire` | read | Voir l'historique des mouvements |
| `localisations` | read | Voir les localisations produits |
| `zones_preparation` | read | Voir les zones de préparation |
| `zones_preparation` | write | Gérer les zones de préparation |

### Attribution par rôle

| Rôle | Permissions |
|------|-------------|
| **Admin** | Toutes |
| **Manager** | Tout sauf suppression clients et camions |
| **Préparateur** | Lecture + préparation BL |
| **Livreur** | Lecture + livraison BL |
| **Commercial** | Clients + commandes (lecture/écriture) |
| **Comptable** | Factures + paiements |

---

## 9. Importance stratégique

### 9.1 Pourquoi ce module est crucial ?

| Aspect | Sans le module | Avec le module |
|--------|----------------|----------------|
| **Commandes** | Saisie manuelle, papier | Workflow digital complet |
| **Facturation** | Excel, erreurs de calcul | Automatique, TVA correcte |
| **Livraison** | Organisation chaotique | Tournées optimisées |
| **Stock** | Décalage réalité/système | Synchronisation temps réel |
| **Trésorerie** | Suivi difficile | Encours et échéances clairs |
| **Traçabilité** | Aucune | Historique complet |

### 9.2 ROI attendu

- **Réduction erreurs facturation** : -90%
- **Gain temps préparation** : -30%
- **Optimisation tournées** : -20% km parcourus
- **Réduction impayés** : Contrôle encours automatique
- **Satisfaction client** : Livraisons plus fiables

### 9.3 Intégration avec modules existants

```
┌─────────────────────────────────────────────────────────────────┐
│                    PRISE INVENTAIRE                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌──────────────┐     ┌──────────────┐     ┌──────────────┐    │
│  │ FOURNISSEURS │────▶│   PRODUITS   │◀────│   CLIENTS    │    │
│  │  (achats)    │     │   (stock)    │     │   (ventes)   │    │
│  └──────────────┘     └──────┬───────┘     └──────────────┘    │
│                              │                                   │
│         ┌────────────────────┼────────────────────┐             │
│         │                    │                    │             │
│         ▼                    ▼                    ▼             │
│  ┌──────────────┐     ┌──────────────┐     ┌──────────────┐    │
│  │  RÉCEPTION   │     │  INVENTAIRE  │     │  LIVRAISON   │    │
│  │  (entrée)    │     │  (contrôle)  │     │  (sortie)    │    │
│  └──────────────┘     └──────────────┘     └──────────────┘    │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │                    TABLEAUX DE BORD                       │  │
│  │  • Stock temps réel  • CA par client  • Tournées du jour │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 10. Prochaines étapes

1. ✅ **Analyse validée** - Ce document
2. ⏳ **Implémentation backend** - Migrations, modèles, controllers
3. ⏳ **Implémentation frontend** - Pages React
4. ⏳ **Tests** - Validation du flux complet
5. ⏳ **Déploiement** - Production

---

*Document d'analyse généré le 6 Mars 2026*
*En attente de validation pour démarrer l'implémentation*
