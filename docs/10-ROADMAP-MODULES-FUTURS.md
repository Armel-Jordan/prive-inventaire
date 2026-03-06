# Roadmap des Modules Futurs - Prise Inventaire
## Plan d'évolution de la plateforme

**Date de création** : 6 Mars 2026  
**Version** : 1.0  
**Statut** : Document de planification

---

## 📋 Table des matières

1. [État actuel du système](#1-état-actuel-du-système)
2. [Modules priorité haute](#2-modules-priorité-haute)
3. [Modules priorité moyenne](#3-modules-priorité-moyenne)
4. [Modules priorité basse](#4-modules-priorité-basse)
5. [Estimation des efforts](#5-estimation-des-efforts)
6. [Dépendances entre modules](#6-dépendances-entre-modules)

---

## 1. État actuel du système

### 1.1 Modules opérationnels

| Catégorie | Module | Statut | Description |
|-----------|--------|--------|-------------|
| **Inventaire** | Scans | ✅ Complet | Scan de produits via mobile |
| **Inventaire** | Statistiques | ✅ Complet | Tableaux de bord et métriques |
| **Inventaire** | Comparaison | ✅ Complet | Comparaison entre inventaires |
| **Inventaire** | Alertes Stock | ✅ Complet | Notifications stock bas/critique |
| **Inventaire** | Historique | ✅ Complet | Journal des mouvements |
| **Inventaire** | Traçabilité | ✅ Complet | Suivi des produits |
| **Inventaire** | Relocalisation | ✅ Complet | Transfert entre secteurs |
| **Inventaire** | Planification | ✅ Complet | Planification des transferts |
| **Inventaire** | Approbations | ✅ Complet | Workflow de validation |
| **Inventaire** | Rapports | ✅ Complet | Génération de rapports |
| **Inventaire** | Inv. Tournant | ✅ Complet | Inventaire cyclique |
| **Achats** | Fournisseurs | ✅ Complet | Gestion des fournisseurs |
| **Achats** | Commandes Fournisseur | ✅ Complet | Création et suivi commandes |
| **Achats** | Réceptions | ✅ Complet | Réception des marchandises |
| **Ventes** | Clients | ✅ Complet | Gestion des clients |
| **Ventes** | Camions | ✅ Complet | Gestion de la flotte |
| **Paramètres** | Produits | ✅ Complet | Catalogue produits |
| **Paramètres** | Secteurs | ✅ Complet | Zones de stockage |
| **Paramètres** | Employés | ✅ Complet | Gestion du personnel |
| **Paramètres** | Rôles | ✅ Complet | Permissions et accès |

### 1.2 Backend prêt, Frontend à créer

| Module | Backend | Frontend | Priorité |
|--------|---------|----------|----------|
| Commandes Client | ✅ API prête | ❌ Page à créer | Haute |
| Factures | ✅ API prête | ❌ Page à créer | Haute |
| Bons de Livraison | ✅ API prête | ❌ Page à créer | Haute |
| Tournées | ✅ API prête | ❌ Page à créer | Haute |
| Zones Préparation | ✅ API prête | ❌ Page à créer | Moyenne |

---

## 2. Modules priorité haute

### 2.1 📄 Commandes Client (Frontend)

**Objectif** : Interface pour créer et gérer les commandes clients

**Fonctionnalités** :
- Liste des commandes avec filtres (statut, client, date)
- Création de commande avec sélection produits
- Workflow de validation (brouillon → soumise → acceptée/refusée)
- Conversion automatique en facture

**Effort estimé** : 1-2 jours

**API disponibles** :
```
GET    /api/commandes-client
GET    /api/commandes-client/{id}
POST   /api/commandes-client
PUT    /api/commandes-client/{id}
POST   /api/commandes-client/{id}/soumettre
POST   /api/commandes-client/{id}/accepter
POST   /api/commandes-client/{id}/refuser
DELETE /api/commandes-client/{id}
```

---

### 2.2 🧾 Factures (Frontend)

**Objectif** : Gestion complète des factures et paiements

**Fonctionnalités** :
- Liste des factures avec statuts (brouillon, émise, payée, impayée)
- Création depuis commande validée
- Enregistrement des paiements (partiels ou complets)
- Gestion des échéances selon conditions client
- Création de bon de livraison

**Effort estimé** : 2-3 jours

**API disponibles** :
```
GET    /api/factures
GET    /api/factures/{id}
POST   /api/factures/commande/{commandeId}
POST   /api/factures/{id}/emettre
POST   /api/factures/{id}/paiement
POST   /api/factures/{id}/creer-bl
```

---

### 2.3 📦 Bons de Livraison (Frontend)

**Objectif** : Gestion de la préparation et livraison des commandes

**Fonctionnalités** :
- Liste des BL par statut (à préparer, en cours, prêt, livré)
- Interface de préparation (picking)
- Mise à jour des quantités préparées
- Validation livraison (complète ou partielle)
- Gestion des retours

**Effort estimé** : 2-3 jours

**API disponibles** :
```
GET    /api/bons-livraison
GET    /api/bons-livraison/{id}
POST   /api/bons-livraison/{id}/preparer
PUT    /api/bons-livraison/{id}/lignes
POST   /api/bons-livraison/{id}/pret
POST   /api/bons-livraison/{id}/livrer
```

---

### 2.4 🚚 Tournées de Livraison (Frontend)

**Objectif** : Planification et suivi des tournées de livraison

**Fonctionnalités** :
- Création de tournée (date, camion, livreur, zone)
- Ajout/retrait de bons de livraison
- Réorganisation de l'ordre de livraison
- Démarrage et clôture de tournée
- Suivi kilométrique

**Effort estimé** : 2 jours

**API disponibles** :
```
GET    /api/tournees
GET    /api/tournees/{id}
POST   /api/tournees
POST   /api/tournees/{id}/ajouter-bon
DELETE /api/tournees/{id}/bon/{bonId}
PUT    /api/tournees/{id}/ordre
POST   /api/tournees/{id}/demarrer
POST   /api/tournees/{id}/terminer
```

---

### 2.5 📊 Tableau de bord commercial

**Objectif** : Vue d'ensemble des performances commerciales

**Fonctionnalités** :
- Chiffre d'affaires (jour/semaine/mois)
- Top 10 clients
- Commandes en attente de validation
- Factures impayées / en retard
- Livraisons du jour
- Graphiques d'évolution

**Effort estimé** : 2-3 jours

**Backend requis** : Nouveaux endpoints d'agrégation

---

## 3. Modules priorité moyenne

### 3.1 💰 Comptabilité simplifiée

**Objectif** : Export et suivi comptable

**Fonctionnalités** :
- Journal des ventes
- Journal des achats
- Export au format comptable (CSV, Excel)
- Rapprochement bancaire simplifié
- TVA collectée / déductible

**Effort estimé** : 3-4 jours

**Dépendances** : Factures, Réceptions

---

### 3.2 📈 Prévisions de stock

**Objectif** : Anticiper les besoins de réapprovisionnement

**Fonctionnalités** :
- Analyse des tendances de vente
- Calcul du stock de sécurité
- Suggestions de commande fournisseur
- Alertes prévisionnelles
- Saisonnalité

**Effort estimé** : 4-5 jours

**Dépendances** : Historique des ventes, Commandes fournisseur

---

### 3.3 🏷️ Gestion des prix avancée

**Objectif** : Flexibilité tarifaire

**Fonctionnalités** :
- Tarifs par client ou groupe de clients
- Remises par volume
- Promotions temporaires
- Historique des prix
- Prix d'achat vs prix de vente (marge)

**Effort estimé** : 3-4 jours

**Tables requises** : `tarifs_clients`, `promotions`, `historique_prix`

---

### 3.4 📋 Devis

**Objectif** : Cycle de vente complet

**Fonctionnalités** :
- Création de devis
- Envoi par email (PDF)
- Suivi des relances
- Conversion devis → commande
- Taux de conversion

**Effort estimé** : 3 jours

**Tables requises** : `devis`, `devis_lignes`

---

### 3.5 🔔 Notifications avancées

**Objectif** : Alertes proactives

**Fonctionnalités** :
- Stock bas (configurable par produit)
- Commandes en retard
- Factures impayées > X jours
- Contrôle technique camion à renouveler
- Notifications push / email

**Effort estimé** : 2-3 jours

**Dépendances** : Système de notifications existant

---

### 3.6 🏭 Zones de préparation (Frontend)

**Objectif** : Gestion des zones de picking

**Fonctionnalités** :
- CRUD des zones
- Affectation des produits aux zones
- Optimisation du parcours de préparation

**Effort estimé** : 1 jour

**API disponibles** : ✅ Déjà prêtes

---

## 4. Modules priorité basse

### 4.1 📱 Application mobile livreur

**Objectif** : Outil terrain pour les livreurs

**Fonctionnalités** :
- Liste des livraisons du jour
- Navigation GPS vers client
- Signature électronique client
- Photo de livraison
- Scan des produits livrés
- Mode hors-ligne

**Effort estimé** : 10-15 jours

**Technologies** : React Native ou Flutter

---

### 4.2 🌐 Portail client B2B

**Objectif** : Self-service pour les clients

**Fonctionnalités** :
- Connexion client
- Catalogue produits avec prix personnalisés
- Passage de commande en ligne
- Suivi des commandes et livraisons
- Historique des factures
- Téléchargement des documents

**Effort estimé** : 15-20 jours

**Architecture** : Application web séparée avec API partagée

---

### 4.3 📊 Business Intelligence

**Objectif** : Analyse décisionnelle avancée

**Fonctionnalités** :
- Rapports personnalisables
- Export Excel/PDF avancé
- Graphiques interactifs
- Comparaison périodes
- KPIs configurables

**Effort estimé** : 5-7 jours

**Technologies** : Chart.js avancé, export libraries

---

### 4.4 🔗 Intégrations externes

**Objectif** : Connexion avec d'autres systèmes

**Intégrations possibles** :
| Système | Type | Effort |
|---------|------|--------|
| Sage / QuickBooks | Comptabilité | 5-7 jours |
| WooCommerce / Shopify | E-commerce | 5-7 jours |
| Stripe / PayPal | Paiement en ligne | 2-3 jours |
| Mailchimp / Sendinblue | Marketing | 2 jours |
| Google Maps | Optimisation tournées | 3-4 jours |

---

### 4.5 📦 Gestion des lots et DLC

**Objectif** : Traçabilité alimentaire / pharmaceutique

**Fonctionnalités** :
- Numéro de lot obligatoire
- Date de péremption (DLC/DLUO)
- FIFO automatique
- Alertes produits proches péremption
- Blocage vente produits périmés
- Rappel de lots

**Effort estimé** : 5-7 jours

**Tables requises** : Extension de `produit_localisations`

---

## 5. Estimation des efforts

### 5.1 Récapitulatif par priorité

| Priorité | Modules | Effort total |
|----------|---------|--------------|
| **Haute** | 5 modules | 9-13 jours |
| **Moyenne** | 6 modules | 16-20 jours |
| **Basse** | 5 modules | 40-55 jours |
| **Total** | 16 modules | 65-88 jours |

### 5.2 Planning suggéré

```
Phase 1 (Semaine 1-2) : Priorité Haute
├── Commandes Client (2j)
├── Factures (3j)
├── Bons de Livraison (3j)
├── Tournées (2j)
└── Tableau de bord commercial (3j)

Phase 2 (Semaine 3-5) : Priorité Moyenne
├── Comptabilité simplifiée (4j)
├── Prévisions de stock (5j)
├── Gestion des prix (4j)
├── Devis (3j)
├── Notifications avancées (3j)
└── Zones de préparation (1j)

Phase 3 (Mois 2-3) : Priorité Basse
├── App mobile livreur (15j)
├── Portail client B2B (20j)
├── Business Intelligence (7j)
├── Intégrations (selon besoins)
└── Gestion lots/DLC (7j)
```

---

## 6. Dépendances entre modules

```
┌─────────────────────────────────────────────────────────────┐
│                    DÉPENDANCES MODULES                       │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  Clients ──────┬──────▶ Commandes Client                    │
│                │              │                              │
│                │              ▼                              │
│                │        Factures ◀──── Comptabilité         │
│                │              │                              │
│                │              ▼                              │
│                │      Bons de Livraison                     │
│                │              │                              │
│                │              ▼                              │
│  Camions ──────┴──────▶ Tournées                            │
│                              │                              │
│                              ▼                              │
│                      App Mobile Livreur                     │
│                                                              │
│  Produits ────────────▶ Prévisions Stock                    │
│       │                      │                              │
│       │                      ▼                              │
│       └──────────────▶ Gestion Prix                         │
│                              │                              │
│                              ▼                              │
│                          Devis                              │
│                              │                              │
│                              ▼                              │
│                      Portail Client B2B                     │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

---

## 📝 Notes

- Les estimations sont basées sur un développeur expérimenté
- Les tests et corrections de bugs ajoutent ~20% au temps estimé
- La documentation utilisateur n'est pas incluse dans les estimations
- Les modules peuvent être développés en parallèle si les dépendances le permettent

---

**Document maintenu par** : Équipe Prise Inventaire  
**Dernière mise à jour** : 6 Mars 2026
