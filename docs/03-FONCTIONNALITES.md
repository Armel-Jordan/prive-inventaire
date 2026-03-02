# Prise Inventaire - Description des Fonctionnalités

## Table des matières

1. [Gestion des inventaires](#1-gestion-des-inventaires)
2. [Relocalisation et mouvements](#2-relocalisation-et-mouvements)
3. [Planification des transferts](#3-planification-des-transferts)
4. [Workflow d'approbation](#4-workflow-dapprobation)
5. [Traçabilité des produits](#5-traçabilité-des-produits)
6. [Alertes de stock](#6-alertes-de-stock)
7. [Notifications](#7-notifications)
8. [Rapports et statistiques](#8-rapports-et-statistiques)
9. [Inventaire tournant](#9-inventaire-tournant)
10. [Comparaison de périodes](#10-comparaison-de-périodes)
11. [Audit et historique](#11-audit-et-historique)
12. [Gestion des utilisateurs et rôles](#12-gestion-des-utilisateurs-et-rôles)
13. [Support QR Code](#13-support-qr-code)
14. [Dashboard temps réel](#14-dashboard-temps-réel)
15. [Multi-langue](#15-multi-langue)
16. [API publique](#16-api-publique)

---

## 1. Gestion des inventaires

### Description
Système complet de gestion des scans d'inventaire permettant d'enregistrer, modifier et suivre les comptages de produits par secteur.

### Fonctionnalités
- **Création de scans** : Enregistrement des comptages avec produit, quantité, secteur et employé
- **Recherche avancée** : Filtrage par date, secteur, employé, produit
- **Modification/Suppression** : Gestion complète du cycle de vie des scans
- **Export CSV** : Téléchargement des données filtrées au format CSV
- **Validation en lot** : Validation ou suppression de plusieurs scans simultanément

### Données enregistrées
| Champ | Description |
|-------|-------------|
| Numéro produit | Code-barres ou référence unique |
| Nom produit | Désignation du produit |
| Quantité | Nombre d'unités comptées |
| Secteur | Zone de stockage |
| Employé | Personne ayant effectué le scan |
| Date de saisie | Horodatage automatique |

---

## 2. Relocalisation et mouvements

### Description
Suivi complet des mouvements de stock : arrivages, sorties et transferts entre secteurs.

### Types de mouvements

| Type | Description | Secteur source | Secteur destination |
|------|-------------|----------------|---------------------|
| **Arrivage** | Entrée de marchandise | - | Requis |
| **Sortie** | Sortie de marchandise | Requis | - |
| **Transfert** | Déplacement interne | Requis | Requis |

### Fonctionnalités
- **Scan en lot** : Scanner plusieurs produits pour un même mouvement
- **Historique complet** : Visualisation de tous les mouvements
- **Statistiques** : Compteurs par type et par période
- **Notes** : Ajout de commentaires sur chaque mouvement

### Scan en lot
Permet de regrouper plusieurs produits dans une seule opération :
1. Sélectionner le type de mouvement
2. Définir les secteurs source/destination
3. Scanner ou saisir les produits un par un
4. Valider l'ensemble du lot

---

## 3. Planification des transferts

### Description
Programmation à l'avance des transferts de stock avec suivi de l'exécution.

### Fonctionnalités
- **Création de transferts planifiés** : Définir date, produits, secteurs
- **Statuts de suivi** :
  - `planifie` : En attente d'exécution
  - `en_cours` : Transfert démarré
  - `termine` : Transfert complété
  - `annule` : Transfert annulé
- **Tableau de bord** : Vue des transferts à venir (7 jours)
- **Exécution** : Transformation en mouvement réel

### Données d'un transfert planifié
| Champ | Description |
|-------|-------------|
| Date prévue | Date d'exécution planifiée |
| Produit | Numéro et nom du produit |
| Quantité | Nombre d'unités à transférer |
| Secteur source | Zone de départ |
| Secteur destination | Zone d'arrivée |
| Employé assigné | Personne responsable |
| Notes | Instructions particulières |

---

## 4. Workflow d'approbation

### Description
Système de validation pour les mouvements importants dépassant un seuil défini.

### Fonctionnement
1. Un mouvement dépassant le seuil (ex: 100 unités) crée une demande d'approbation
2. Un manager ou admin reçoit une notification
3. Il peut approuver ou rejeter la demande
4. Si approuvé, le mouvement est créé automatiquement

### Statuts
| Statut | Description |
|--------|-------------|
| `en_attente` | Demande en cours de traitement |
| `approuve` | Demande validée, mouvement créé |
| `rejete` | Demande refusée avec motif |

### Configuration
- **Seuil de quantité** : Configurable par tenant
- **Approbateurs** : Utilisateurs avec rôle manager ou admin

---

## 5. Traçabilité des produits

### Description
Historique complet d'un produit : tous les secteurs où il est passé, tous les mouvements et scans.

### Fonctionnalités
- **Recherche par produit** : Trouver un produit par numéro ou nom
- **Timeline** : Chronologie de tous les événements
- **Statistiques produit** :
  - Nombre total de mouvements
  - Nombre de scans
  - Secteurs visités
  - Quantité totale déplacée
- **Détail des événements** : Type, date, secteur, employé, quantité

### Informations affichées
- Premier et dernier mouvement
- Secteur actuel (dernier connu)
- Historique des passages par secteur
- Graphique d'activité

---

## 6. Alertes de stock

### Description
Système d'alertes pour prévenir les ruptures de stock.

### Fonctionnalités
- **Définition de seuils** : Seuil minimum par produit
- **Détection automatique** : Comparaison avec les quantités scannées
- **Indicateurs visuels** : Code couleur (rouge = alerte)
- **Notifications** : Alerte envoyée aux utilisateurs concernés

### Configuration d'une alerte
| Champ | Description |
|-------|-------------|
| Produit | Produit à surveiller |
| Seuil minimum | Quantité en dessous de laquelle alerter |
| Actif | Activation/désactivation de l'alerte |

### Statuts
- **En alerte** : Quantité actuelle < seuil minimum
- **Normal** : Quantité actuelle >= seuil minimum

---

## 7. Notifications

### Description
Système de notifications en temps réel pour informer les utilisateurs des événements importants.

### Types de notifications
| Type | Description |
|------|-------------|
| `transfert_complete` | Un transfert planifié a été exécuté |
| `approbation_requise` | Une demande d'approbation attend |
| `alerte_stock` | Un produit est en rupture |
| `planification_imminente` | Un transfert est prévu prochainement |

### Fonctionnalités
- **Cloche de notification** : Indicateur dans la barre supérieure
- **Compteur non-lus** : Badge avec nombre de notifications
- **Marquer comme lu** : Individuellement ou en masse
- **Liens directs** : Accès rapide à l'élément concerné
- **Nettoyage automatique** : Suppression des anciennes notifications

---

## 8. Rapports et statistiques

### Description
Génération de rapports détaillés sur l'activité de l'inventaire.

### Types de rapports

#### Rapport par secteur
- Mouvements entrants et sortants par secteur
- Graphique comparatif
- Solde net (entrants - sortants)
- Filtrage par mois/année

#### Rapport par employé
- Activité de chaque employé
- Nombre de mouvements et scans
- Quantités traitées

#### Évolution annuelle
- Graphique linéaire sur 12 mois
- Tendances des mouvements et scans
- Comparaison mois par mois

#### Top produits
- Classement des produits les plus actifs
- Nombre de mouvements
- Quantités totales

### Export
Tous les rapports sont exportables au format CSV.

---

## 9. Inventaire tournant

### Description
Système de suggestions pour organiser les inventaires réguliers par secteur.

### Fonctionnalités
- **Suggestions prioritaires** : Classement des secteurs à vérifier
- **Score de priorité** : Calculé selon :
  - Jours depuis le dernier scan
  - Nombre de mouvements depuis
- **Planning mensuel** : Répartition des secteurs sur les jours ouvrables
- **Statistiques de couverture** :
  - Secteurs scannés ce mois
  - Secteurs jamais scannés
  - Taux de couverture

### Calcul du score
```
Score = Jours depuis scan + (Mouvements depuis × 2)
```

### Niveaux de priorité
| Score | Priorité | Couleur |
|-------|----------|---------|
| >= 100 | Critique | Rouge |
| >= 50 | Haute | Orange |
| >= 20 | Moyenne | Jaune |
| < 20 | Basse | Vert |

---

## 10. Comparaison de périodes

### Description
Analyse des différences d'inventaire entre deux périodes.

### Fonctionnalités
- **Sélection de périodes** : Deux plages de dates à comparer
- **Calcul des écarts** : Différence de quantité par produit
- **Indicateurs visuels** :
  - Vert : Augmentation
  - Rouge : Diminution
  - Gris : Stable
- **Filtrage** : Par secteur, par type d'écart

### Métriques
- Quantité période 1
- Quantité période 2
- Écart absolu
- Écart en pourcentage

---

## 11. Audit et historique

### Description
Journal complet de toutes les actions effectuées dans le système.

### Événements tracés
- Création, modification, suppression de scans
- Création de mouvements
- Approbations et rejets
- Connexions utilisateurs
- Modifications de configuration

### Informations enregistrées
| Champ | Description |
|-------|-------------|
| Action | Type d'opération effectuée |
| Utilisateur | Personne ayant effectué l'action |
| Date/Heure | Horodatage précis |
| Détails | Données avant/après modification |
| Adresse IP | Origine de la requête |

### Consultation
- Filtrage par date, utilisateur, type d'action
- Recherche textuelle
- Export des logs

---

## 12. Gestion des utilisateurs et rôles

### Description
Système de permissions basé sur les rôles pour contrôler l'accès aux fonctionnalités.

### Rôles disponibles

| Rôle | Description | Permissions principales |
|------|-------------|------------------------|
| **Admin** | Administrateur | Accès complet, gestion utilisateurs |
| **Manager** | Responsable | Lecture/écriture, approbations, rapports |
| **User** | Utilisateur | Scans, mouvements basiques |
| **Readonly** | Consultation | Lecture seule, rapports |

### Permissions détaillées
- `scans.read/write/delete`
- `produits.read/write/delete`
- `secteurs.read/write/delete`
- `employes.read/write/delete`
- `relocalisation.read/write`
- `approbations.read/approve`
- `rapports.read`
- `users.read/write`
- `settings.write`

---

## 13. Support QR Code

### Description
Utilisation de QR codes pour faciliter l'identification des secteurs.

### Fonctionnalités
- **Génération de QR codes** : Création automatique pour chaque secteur
- **Validation** : Vérification qu'un QR code correspond à un secteur
- **Scan mobile** : Lecture via l'application Android
- **Mise à jour** : Régénération si nécessaire

### Format du QR Code
Le QR code contient un identifiant unique lié au secteur, permettant une identification rapide et sans erreur.

---

## 14. Dashboard temps réel

### Description
Tableau de bord avec mise à jour automatique des statistiques.

### Fonctionnalités
- **Rafraîchissement automatique** : Toutes les 30 secondes
- **Mode manuel** : Désactivation du rafraîchissement auto
- **Indicateur de mise à jour** : Affichage de l'heure du dernier refresh
- **Actions requises** : Section dédiée aux éléments nécessitant attention

### Sections
1. Actions requises (alertes, approbations, notifications)
2. Statistiques inventaire
3. Statistiques relocalisation
4. Aperçu général

---

## 15. Multi-langue

### Description
Support de plusieurs langues pour l'interface utilisateur.

### Langues supportées
- **Français** (par défaut)
- **Anglais**

### Fonctionnalités
- **Détection automatique** : Basée sur la langue du navigateur
- **Changement manuel** : Sélecteur de langue
- **Persistance** : Mémorisation du choix utilisateur
- **Couverture complète** : Tous les textes de l'interface

---

## 16. API publique

### Description
API REST documentée pour permettre les intégrations avec d'autres systèmes.

### Documentation
- **Format** : OpenAPI 3.0 (Swagger)
- **Accès** : `/api-docs`
- **Interface interactive** : Test des endpoints en ligne

### Authentification
- Bearer Token (JWT)
- Header `X-Tenant-Slug` pour le multi-tenant

### Endpoints principaux
| Méthode | Endpoint | Description |
|---------|----------|-------------|
| POST | `/auth/login` | Authentification |
| GET | `/scans` | Liste des scans |
| POST | `/scans` | Créer un scan |
| GET | `/relocalisation` | Liste des mouvements |
| POST | `/relocalisation` | Créer un mouvement |
| GET | `/rapports/*` | Génération de rapports |
| GET | `/notifications` | Liste des notifications |

### Codes de réponse
- `200` : Succès
- `201` : Créé
- `400` : Requête invalide
- `401` : Non authentifié
- `403` : Permission refusée
- `404` : Non trouvé
- `422` : Erreur de validation

---

## Résumé des fonctionnalités par module

| Module | Fonctionnalités clés |
|--------|---------------------|
| **Inventaire** | Scans, recherche, export, validation lot |
| **Relocalisation** | Mouvements, scan lot, QR code |
| **Planification** | Transferts programmés, suivi statut |
| **Approbation** | Workflow validation, seuils |
| **Traçabilité** | Historique produit, timeline |
| **Alertes** | Seuils stock, notifications |
| **Rapports** | Secteur, employé, évolution, top produits |
| **Inv. tournant** | Suggestions, planning, couverture |
| **Audit** | Journal actions, historique |
| **Utilisateurs** | Rôles, permissions |
| **API** | REST, Swagger, intégrations |

---

*Document mis à jour le 1er mars 2026*
