# Prise Inventaire - Présentation du Projet

## Vue d'ensemble

**Prise Inventaire** est une solution complète de gestion d'inventaire multi-tenant conçue pour les entreprises qui gèrent des stocks de produits répartis sur plusieurs secteurs ou zones. Le système permet de suivre en temps réel les mouvements de stock, d'effectuer des inventaires réguliers et de générer des rapports détaillés.

## Architecture

Le projet est composé de trois applications principales :

### 1. Backend API (Laravel)
- **Technologie** : Laravel 10+ / PHP 8.2+
- **Base de données** : MySQL (architecture multi-tenant)
- **Authentification** : JWT Bearer Token
- **Chemin** : `/prise-inventaire-api`

### 2. Frontend Web (React)
- **Technologie** : React 18 + TypeScript + Vite
- **UI** : TailwindCSS + Lucide Icons
- **Graphiques** : Recharts
- **Chemin** : `/prise-inventaire-web`

### 3. Application Mobile (Android)
- **Technologie** : Kotlin + Jetpack Compose
- **Fonctionnalités** : Scanner QR Code, mode hors ligne
- **Chemin** : `/prise-inventaire-android`

## Architecture Multi-Tenant

Le système utilise une architecture multi-tenant où :
- Chaque **tenant** (entreprise cliente) a ses propres données isolées
- Un **Super Admin** gère tous les tenants
- Les utilisateurs appartiennent à un tenant spécifique
- Les données sont séparées par tenant via le header `X-Tenant-Slug`

```
┌─────────────────────────────────────────────────────────┐
│                    Super Admin                          │
│              (Gestion des tenants)                      │
└─────────────────────────────────────────────────────────┘
                          │
        ┌─────────────────┼─────────────────┐
        ▼                 ▼                 ▼
┌───────────────┐ ┌───────────────┐ ┌───────────────┐
│   Tenant A    │ │   Tenant B    │ │   Tenant C    │
│  (Entreprise) │ │  (Entreprise) │ │  (Entreprise) │
├───────────────┤ ├───────────────┤ ├───────────────┤
│ - Produits    │ │ - Produits    │ │ - Produits    │
│ - Secteurs    │ │ - Secteurs    │ │ - Secteurs    │
│ - Employés    │ │ - Employés    │ │ - Employés    │
│ - Scans       │ │ - Scans       │ │ - Scans       │
│ - Mouvements  │ │ - Mouvements  │ │ - Mouvements  │
└───────────────┘ └───────────────┘ └───────────────┘
```

## Modèle de données principal

### Entités principales

| Entité | Description |
|--------|-------------|
| **Tenant** | Entreprise cliente utilisant le système |
| **AdminUser** | Utilisateur avec accès au dashboard web |
| **Produit** | Article en stock avec numéro et nom |
| **Secteur** | Zone de stockage (rayon, entrepôt, etc.) |
| **Employe** | Personne effectuant les opérations |
| **Scan** | Enregistrement d'inventaire d'un produit |
| **Mouvement** | Déplacement de stock (arrivage, sortie, transfert) |

### Relations

```
Tenant
  ├── AdminUsers (1:N)
  ├── Produits (1:N)
  ├── Secteurs (1:N)
  ├── Employes (1:N)
  ├── Scans (1:N)
  │     └── Produit, Secteur, Employe
  └── Mouvements (1:N)
        └── Produit, Secteur Source/Destination, Employe
```

## Sécurité

### Authentification
- Connexion par email/mot de passe
- Token JWT avec expiration
- Refresh token automatique

### Autorisation (Rôles)
| Rôle | Modules visibles | Permissions |
|------|------------------|-------------|
| **Admin** | Tous (16 modules) | Voir, Créer, Modifier, Supprimer, Gérer rôles |
| **Manager** | 15 modules (pas Rôles) | Voir, Créer, Modifier (pas Supprimer) |
| **User** | 6 modules (Dashboard, Inventaires, Stats, Relocalisation, Produits, Secteurs) | Voir, Créer limité |
| **Readonly** | 4 modules (Dashboard, Inventaires, Stats, Rapports) | Voir uniquement |

### Système de permissions avancé
- **Rôles personnalisables** : L'admin peut créer des rôles avec des permissions spécifiques
- **Permissions par module** : Voir, Créer, Modifier, Supprimer pour chaque module
- **Filtrage automatique** : Le menu s'adapte aux permissions de l'utilisateur
- **Page de gestion** : Interface dédiée pour créer/modifier les rôles

### Protection des données
- Isolation des données par tenant
- Validation des entrées
- Audit log de toutes les actions
- Chiffrement des mots de passe (bcrypt)

## Environnement technique

### Prérequis
- PHP 8.2+ avec extensions (pdo_mysql, mbstring, openssl)
- Composer 2.x
- Node.js 18+ et npm/yarn
- MySQL 8.0+
- Android Studio (pour l'app mobile)

### Variables d'environnement

**Backend (.env)**
```env
APP_URL=http://localhost:8000
DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=prise_inventaire
DB_USERNAME=root
DB_PASSWORD=secret
JWT_SECRET=your-jwt-secret
```

**Frontend (.env)**
```env
VITE_API_URL=http://localhost:8000/api
```

## Déploiement

### Backend
1. Cloner le repository
2. `composer install`
3. Configurer `.env`
4. `php artisan migrate`
5. `php artisan serve`

### Frontend
1. `npm install`
2. Configurer `.env`
3. `npm run dev` (développement)
4. `npm run build` (production)

### Mobile
1. Ouvrir dans Android Studio
2. Configurer l'URL de l'API
3. Build APK ou déployer sur Play Store

## Support et Contact

Pour toute question technique ou demande de support :
- Email : support@prise-inventaire.com
- Documentation API : `/api-docs`

---

*Document mis à jour le 2 mars 2026*
