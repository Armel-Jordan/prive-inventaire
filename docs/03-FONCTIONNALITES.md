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
13. [Profil employé](#13-profil-employé)
14. [Support QR Code](#14-support-qr-code)
15. [Dashboard temps réel](#15-dashboard-temps-réel)
16. [Multi-langue](#16-multi-langue)
17. [Thème sombre/clair](#17-thème-sombreclair)
18. [API publique](#18-api-publique)

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
Système de permissions avancé basé sur les rôles pour contrôler l'accès aux fonctionnalités par module.

### Rôles par défaut

| Rôle | Modules visibles | Permissions |
|------|------------------|-------------|
| **Admin** | Tous (16 modules) | Voir, Créer, Modifier, Supprimer, Gérer rôles |
| **Manager** | 15 modules (pas Rôles) | Voir, Créer, Modifier (pas Supprimer) |
| **User** | 6 modules | Voir, Créer limité |
| **Readonly** | 4 modules | Voir uniquement |

### Système de permissions par module

Chaque rôle peut avoir des permissions spécifiques pour chaque module :

| Permission | Description |
|------------|-------------|
| **Voir** | Accès en lecture au module |
| **Créer** | Possibilité d'ajouter des éléments |
| **Modifier** | Possibilité de modifier des éléments |
| **Supprimer** | Possibilité de supprimer des éléments |

### Modules disponibles
- Dashboard, Inventaires, Statistiques, Comparaison
- Alertes, Historique, Traçabilité, Relocalisation
- Planification, Approbations, Rapports, Inventaire Tournant
- Produits, Secteurs, Employés, Rôles

### Fonctionnalités de gestion
- **Création de rôles personnalisés** : L'admin peut créer de nouveaux rôles
- **Édition des permissions** : Matrice de permissions par module
- **Filtrage automatique du menu** : Les utilisateurs ne voient que les modules autorisés
- **Protection des rôles système** : Les rôles par défaut ne peuvent pas être supprimés

---

## 13. Profil employé

### Description
Permet aux employés de compléter leur profil avec des informations personnelles et professionnelles.

### Fonctionnalités
- **Photo de profil** : Upload d'image (JPG, PNG, GIF - max 2MB)
- **Informations personnelles** : Sexe, date de naissance, téléphone
- **Adresse complète** : Adresse, ville, code postal, pays
- **Informations professionnelles** : Poste, département

### Workflow
1. L'admin/manager crée l'employé avec les infos de base (numéro, nom, prénom)
2. L'employé se connecte et accède à son profil via l'icône utilisateur
3. L'employé complète ses informations personnelles
4. Les modifications sont sauvegardées en base de données

### Champs du profil

| Catégorie | Champs |
|-----------|--------|
| **Base (lecture seule)** | Numéro employé, Nom, Prénom, Email |
| **Personnel** | Sexe, Date de naissance, Téléphone |
| **Adresse** | Adresse, Ville, Code postal, Pays |
| **Professionnel** | Poste, Département |

---

## 14. Support QR Code

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

## 15. Dashboard temps réel

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

## 16. Multi-langue

### Description
Support de plusieurs langues pour l'interface utilisateur.

### Langues supportées
- **Français** (par défaut)
- **Anglais**

### Fonctionnalités
- **Détection automatique** : Basée sur la langue du navigateur
- **Changement manuel** : Sélecteur de langue dans la barre supérieure
- **Persistance** : Mémorisation du choix utilisateur en localStorage
- **Couverture complète** : Tous les textes de l'interface

---

## 17. Thème sombre/clair

### Description
Support du mode sombre et clair pour le confort visuel des utilisateurs.

### Fonctionnalités
- **Toggle rapide** : Bouton dans la barre supérieure
- **Détection système** : Utilise la préférence du système par défaut
- **Persistance** : Mémorisation du choix utilisateur
- **Application globale** : Tous les composants s'adaptent au thème

### Implémentation
- Utilisation de TailwindCSS avec la classe `dark`
- Stockage en localStorage (`prise_theme`)
- Context React pour la gestion d'état

---

## 18. API publique

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
| **Alertes** | Seuils stock configurables, notifications |
| **Rapports** | Secteur, employé, évolution, top produits |
| **Inv. tournant** | Suggestions, planning, couverture |
| **Audit** | Journal actions, historique |
| **Utilisateurs** | Rôles personnalisables, permissions par module |
| **Profil** | Photo, infos personnelles, adresse |
| **Thème** | Mode sombre/clair, persistance |
| **API** | REST, Swagger, intégrations |

---

*Document mis à jour le 2 mars 2026*
