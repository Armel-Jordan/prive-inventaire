# Documentation Technique - Gestion des Commandes Fournisseurs
## Prise Inventaire - Module Achats

**Date de création** : 6 Mars 2026  
**Version** : 1.0  
**Auteur** : Équipe Technique

---

## 📋 Table des matières

1. [Vue d'ensemble](#1-vue-densemble)
2. [Schéma de base de données](#2-schéma-de-base-de-données)
3. [API Endpoints](#3-api-endpoints)
4. [Flux de travail](#4-flux-de-travail)
5. [Permissions](#5-permissions)
6. [Pages Frontend](#6-pages-frontend)
7. [Génération PDF](#7-génération-pdf)

---

## 1. Vue d'ensemble

Le module de gestion des commandes fournisseurs permet de :
- Gérer les fournisseurs (CRUD)
- Créer et suivre les commandes d'achat
- Réceptionner les arrivages (partiels ou complets)
- Générer des bons de commande PDF
- Suivre l'historique des prix d'achat

### Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                     Module Commandes Fournisseurs                │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌──────────────┐     ┌──────────────┐     ┌──────────────┐    │
│  │ Fournisseurs │────▶│  Commandes   │────▶│  Réceptions  │    │
│  │    CRUD      │     │   (Entête)   │     │  (Arrivages) │    │
│  └──────────────┘     └──────┬───────┘     └──────┬───────┘    │
│                              │                     │            │
│                              ▼                     ▼            │
│                       ┌──────────────┐     ┌──────────────┐    │
│                       │   Lignes     │     │    Stock     │    │
│                       │  Commande    │     │  (Produits)  │    │
│                       └──────────────┘     └──────────────┘    │
│                                                                  │
│  ┌──────────────┐     ┌──────────────┐                         │
│  │  Historique  │     │     PDF      │                         │
│  │    Prix      │     │ Bon Commande │                         │
│  └──────────────┘     └──────────────┘                         │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 2. Schéma de base de données

### Tables créées

#### 2.1 `fournisseurs`

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | bigint | Clé primaire |
| `code` | varchar(20) | Code unique (ex: FOUR-0001) |
| `raison_sociale` | varchar(255) | Nom de l'entreprise |
| `adresse` | text | Adresse complète |
| `telephone` | varchar(20) | Téléphone |
| `email` | varchar(255) | Email |
| `contact_nom` | varchar(100) | Nom du contact principal |
| `contact_telephone` | varchar(20) | Téléphone du contact |
| `conditions_paiement` | varchar(100) | Ex: "30 jours" |
| `actif` | boolean | Fournisseur actif/inactif |
| `created_at`, `updated_at` | timestamp | Horodatage |

#### 2.2 `produit_fournisseur` (table pivot)

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | bigint | Clé primaire |
| `produit_id` | bigint | FK → produits |
| `fournisseur_id` | bigint | FK → fournisseurs |
| `reference_fournisseur` | varchar(50) | Référence chez le fournisseur |
| `prix_achat` | decimal(10,2) | Prix d'achat |
| `delai_livraison` | int | Délai en jours |
| `fournisseur_principal` | boolean | Fournisseur principal ? |

#### 2.3 `com_four_entete` (Entête commande)

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | bigint | Clé primaire |
| `numero` | varchar(20) | Numéro unique (ex: CF-2026-0001) |
| `fournisseur_id` | bigint | FK → fournisseurs |
| `date_commande` | date | Date de la commande |
| `date_livraison_prevue` | date | Date de livraison souhaitée |
| `statut` | enum | brouillon, envoyee, partielle, complete, annulee |
| `montant_total` | decimal(12,2) | Montant total HT |
| `notes` | text | Remarques |
| `created_by` | bigint | FK → admin_users |
| `created_at`, `updated_at` | timestamp | Horodatage |

#### 2.4 `com_four_ligne` (Lignes commande)

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | bigint | Clé primaire |
| `com_four_entete_id` | bigint | FK → com_four_entete |
| `produit_id` | bigint | FK → produits |
| `quantite_commandee` | int | Quantité commandée |
| `quantite_recue` | int | Quantité déjà reçue |
| `prix_unitaire` | decimal(10,2) | Prix unitaire |
| `montant_ligne` | decimal(12,2) | Montant de la ligne |

#### 2.5 `reception_arrivages_ligne` (Réceptions)

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | bigint | Clé primaire |
| `com_four_ligne_id` | bigint | FK → com_four_ligne |
| `date_reception` | date | Date de réception |
| `quantite_recue` | int | Quantité reçue |
| `secteur_id` | bigint | FK → secteurs (destination) |
| `lot_numero` | varchar(50) | Numéro de lot |
| `date_peremption` | date | Date de péremption |
| `notes` | text | Remarques |
| `received_by` | bigint | FK → admin_users |

#### 2.6 `historique_prix_achat`

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | bigint | Clé primaire |
| `produit_id` | bigint | FK → produits |
| `fournisseur_id` | bigint | FK → fournisseurs |
| `prix_achat` | decimal(10,2) | Prix d'achat |
| `date_effet` | date | Date d'entrée en vigueur |
| `com_four_entete_id` | bigint | FK → commande source |

### Modification table existante

- **`produits`** : Ajout de `fournisseur_principal_id` (FK → fournisseurs)

---

## 3. API Endpoints

### 3.1 Fournisseurs

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/fournisseurs` | Liste des fournisseurs (paginée) |
| GET | `/api/fournisseurs/actifs` | Liste des fournisseurs actifs |
| GET | `/api/fournisseurs/{id}` | Détail d'un fournisseur |
| POST | `/api/fournisseurs` | Créer un fournisseur |
| PUT | `/api/fournisseurs/{id}` | Modifier un fournisseur |
| DELETE | `/api/fournisseurs/{id}` | Supprimer un fournisseur |

### 3.2 Commandes Fournisseur

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/commandes-fournisseur` | Liste des commandes |
| GET | `/api/commandes-fournisseur/{id}` | Détail d'une commande |
| POST | `/api/commandes-fournisseur` | Créer une commande |
| PUT | `/api/commandes-fournisseur/{id}` | Modifier une commande |
| POST | `/api/commandes-fournisseur/{id}/valider` | Valider/envoyer |
| POST | `/api/commandes-fournisseur/{id}/annuler` | Annuler |
| DELETE | `/api/commandes-fournisseur/{id}` | Supprimer (brouillon) |
| GET | `/api/commandes-fournisseur/{id}/pdf` | Télécharger PDF |
| GET | `/api/commandes-fournisseur/{id}/pdf/preview` | Prévisualiser PDF |

### 3.3 Réceptions

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| GET | `/api/receptions` | Liste des réceptions |
| GET | `/api/receptions/commandes-en-attente` | Commandes à réceptionner |
| GET | `/api/receptions/commande/{id}/lignes` | Lignes en attente |
| POST | `/api/receptions` | Enregistrer une réception |
| POST | `/api/receptions/multiple` | Réception multiple |

---

## 4. Flux de travail

### 4.1 Cycle de vie d'une commande

```
┌─────────────┐
│  BROUILLON  │ ◀── Création
└──────┬──────┘
       │ Valider
       ▼
┌─────────────┐
│   ENVOYEE   │ ◀── Génération historique prix
└──────┬──────┘
       │ Réception partielle
       ▼
┌─────────────┐
│  PARTIELLE  │ ◀── Stock mis à jour
└──────┬──────┘
       │ Réception complète
       ▼
┌─────────────┐
│  COMPLETE   │ ◀── Toutes les lignes reçues
└─────────────┘

       │ Annulation (depuis envoyee/partielle)
       ▼
┌─────────────┐
│   ANNULEE   │
└─────────────┘
```

### 4.2 Processus de réception

1. Sélectionner une commande en attente
2. Saisir les quantités reçues pour chaque ligne
3. Optionnel : spécifier le secteur de destination, numéro de lot, date de péremption
4. Valider la réception
5. **Automatiquement** :
   - Mise à jour `quantite_recue` sur la ligne de commande
   - Mise à jour du stock produit
   - Mise à jour du statut de la commande

---

## 5. Permissions

### Permissions disponibles

| Permission | Description |
|------------|-------------|
| `fournisseurs.read` | Voir les fournisseurs |
| `fournisseurs.write` | Créer/modifier les fournisseurs |
| `fournisseurs.delete` | Supprimer les fournisseurs |
| `commandes_fournisseur.read` | Voir les commandes |
| `commandes_fournisseur.write` | Créer/modifier les commandes |
| `commandes_fournisseur.valider` | Valider/envoyer les commandes |
| `commandes_fournisseur.annuler` | Annuler les commandes |
| `receptions.read` | Voir les réceptions |
| `receptions.write` | Enregistrer les réceptions |

### Attribution par rôle

| Rôle | Permissions |
|------|-------------|
| **Admin** | Toutes |
| **Manager** | Tout sauf suppression fournisseurs et annulation |
| **User** | Lecture + réceptions |
| **Readonly** | Lecture seule |

---

## 6. Pages Frontend

### 6.1 Fournisseurs (`/fournisseurs`)

- Liste des fournisseurs avec recherche
- Création/modification via modal
- Affichage : code, raison sociale, contact, téléphone, statut
- Actions : éditer, supprimer

### 6.2 Commandes Fournisseur (`/commandes-fournisseur`)

- Liste des commandes avec filtres (statut, recherche)
- Création de commande avec lignes dynamiques
- Détail de commande avec lignes et quantités reçues
- Actions : voir, PDF, valider, annuler, supprimer

### 6.3 Réceptions (`/receptions`)

- Liste des commandes en attente de réception
- Sélection d'une commande pour voir les lignes
- Formulaire de réception multiple
- Mise à jour automatique du stock

---

## 7. Génération PDF

### Template

Le bon de commande PDF inclut :
- En-tête avec logo et informations entreprise
- Informations fournisseur
- Dates (commande, livraison prévue)
- Tableau des produits (référence, désignation, quantité, prix, montant)
- Totaux (HT, TVA 20%, TTC)
- Notes/instructions
- Zones de signature

### Utilisation

```php
// Téléchargement
GET /api/commandes-fournisseur/{id}/pdf

// Prévisualisation (stream)
GET /api/commandes-fournisseur/{id}/pdf/preview
```

### Dépendance

```bash
composer require barryvdh/laravel-dompdf
```

---

## 8. Installation

### Exécuter les migrations

```bash
cd prise-inventaire-api
php artisan migrate
```

### Fichiers créés

#### Backend (Laravel)
```
app/Models/
├── Fournisseur.php
├── ComFourEntete.php
├── ComFourLigne.php
├── ReceptionArrivagesLigne.php
└── HistoriquePrixAchat.php

app/Http/Controllers/Api/
├── FournisseurController.php
├── CommandeFournisseurController.php
├── ReceptionController.php
└── BonCommandePdfController.php

database/migrations/
├── 2026_03_06_000001_create_fournisseurs_table.php
├── 2026_03_06_000002_create_produit_fournisseur_table.php
├── 2026_03_06_000003_create_com_four_entete_table.php
├── 2026_03_06_000004_create_com_four_ligne_table.php
├── 2026_03_06_000005_create_reception_arrivages_ligne_table.php
├── 2026_03_06_000006_create_historique_prix_achat_table.php
├── 2026_03_06_000007_add_fournisseur_principal_to_produits.php
└── 2026_03_06_000008_add_fournisseur_permissions.php

resources/views/pdf/
└── bon-commande.blade.php
```

#### Frontend (React)
```
src/pages/
├── FournisseursPage.tsx
├── CommandesFournisseurPage.tsx
└── ReceptionsPage.tsx
```

---

## 📞 Support

Pour toute question technique :
- **Documentation API** : `/docs/06-CONFIGURATION-AWS-TECHNIQUE.md`
- **Documentation Android** : `/docs/07-CONFIGURATION-ANDROID-TECHNIQUE.md`
- **Repository** : GitHub - Armel-Jordan/prive-inventaire

---

Réception vs Relocalisation (Arrivages)
Aspect	Réception (nouveau module)	Relocalisation (existant)
Source	Commande fournisseur externe	Stock interne existant
Action	Recevoir des produits achetés	Déplacer des produits entre secteurs
Impact stock	Augmente le stock (entrée)	Déplace le stock (transfert)
Lien	Lié à une commande fournisseur (com_four_ligne)	Lié aux secteurs internes
Exemple	Réceptionner 100 unités commandées chez un fournisseur	Déplacer 50 unités de l'entrepôt A vers le rayon B
Flux simplifié
┌─────────────────────────────────────────────────────────────┐
│                                                             │
│  FOURNISSEUR ──► RÉCEPTION ──► STOCK ──► RELOCALISATION    │
│     (externe)      (entrée)    (interne)    (transfert)    │
│                                                             │
└─────────────────────────────────────────────────────────────┘
En résumé
Réception = Entrée de marchandise depuis un fournisseur (augmente le stock total)
Relocalisation = Mouvement interne entre secteurs (stock total inchangé)
Les deux sont complémentaires : vous commandez chez un fournisseur → vous réceptionnez → puis vous relocalisez dans les différents secteurs de stockage si nécessaire.

*Document généré le 6 Mars 2026*
