# Document Technique - Configuration AWS
## Prise Inventaire - Infrastructure Cloud

**Date de création** : 2 Mars 2026  
**Version** : 1.0  
**Auteur** : Équipe Technique

---

## 📋 Table des matières

1. [Vue d'ensemble de l'architecture](#1-vue-densemble-de-larchitecture)
2. [Informations de connexion AWS](#2-informations-de-connexion-aws)
3. [API Laravel - Elastic Beanstalk](#3-api-laravel---elastic-beanstalk)
4. [Frontend React - AWS Amplify](#4-frontend-react---aws-amplify)
5. [Base de données - Amazon RDS](#5-base-de-données---amazon-rds)
6. [Variables d'environnement](#6-variables-denvironnement)
7. [URLs et endpoints](#7-urls-et-endpoints)
8. [Commandes utiles](#8-commandes-utiles)
9. [Dépannage](#9-dépannage)

---

## 1. Vue d'ensemble de l'architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        AWS Cloud (us-east-1)                     │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌──────────────────┐     ┌──────────────────┐                  │
│  │   AWS Amplify    │     │ Elastic Beanstalk│                  │
│  │   (Frontend)     │────▶│     (API)        │                  │
│  │   React + Vite   │     │   Laravel/PHP    │                  │
│  └──────────────────┘     └────────┬─────────┘                  │
│                                     │                            │
│                                     ▼                            │
│                           ┌──────────────────┐                  │
│                           │   Amazon RDS     │                  │
│                           │     MySQL        │                  │
│                           └──────────────────┘                  │
│                                                                  │
│  ┌──────────────────┐                                           │
│  │  Android App     │──────────────────────────────────────────▶│
│  │  (Mobile)        │                                           │
│  └──────────────────┘                                           │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 2. Informations de connexion AWS

### Compte AWS
| Paramètre | Valeur |
|-----------|--------|
| **Région** | `us-east-1` (N. Virginia) |
| **IAM User** | `prise-inventaire-deploy` |
| **Console AWS** | https://console.aws.amazon.com |

### Politiques IAM attachées
- `AWSElasticBeanstalkFullAccess`
- `AmazonRDSFullAccess`
- `AWSAmplifyAdministratorAccess`
- `AmazonS3FullAccess`

---

## 3. API Laravel - Elastic Beanstalk

### Informations de l'environnement

| Paramètre | Valeur |
|-----------|--------|
| **Application** | `prise-api` |
| **Environnement** | `prise-api-prod` |
| **Environment ID** | `e-jq8xu8pz3c` |
| **Plateforme** | PHP 8.2 running on 64bit Amazon Linux 2023/4.10.0 |
| **Type d'instance** | `t3.small` |
| **Configuration** | Single Instance |
| **Statut** | ✅ Ready (Green) |

### URL de l'API
```
http://prise-api-prod.eba-ghrnc2uz.us-east-1.elasticbeanstalk.com
```

### CNAME
```
prise-api-prod.eba-ghrnc2uz.us-east-1.elasticbeanstalk.com
```

### Structure des fichiers de configuration

```
prise-inventaire-api/
├── .ebextensions/
│   ├── 01_laravel.config      # Configuration PHP et commandes Laravel
│   └── 02_https.config        # Configuration HTTPS (optionnel)
├── .platform/
│   ├── hooks/
│   │   └── postdeploy/
│   │       └── 01_storage.sh  # Script post-déploiement
│   └── nginx/
│       └── conf.d/
│           └── elasticbeanstalk/
│               └── laravel.conf  # Configuration nginx pour Laravel
├── .ebignore                   # Fichiers à exclure du déploiement
└── .elasticbeanstalk/
    └── config.yml              # Configuration EB CLI
```

### Fichier `.ebextensions/01_laravel.config`
```yaml
option_settings:
  aws:elasticbeanstalk:container:php:phpini:
    document_root: /public
    memory_limit: 256M
    max_execution_time: 60
    composer_options: --no-dev --optimize-autoloader --no-scripts

container_commands:
  01_copy_env:
    command: "cp .env.example .env || true"
  02_generate_key:
    command: "php artisan key:generate --force || true"
    leader_only: true
  03_storage_link:
    command: "php artisan storage:link || true"
    leader_only: true
  04_migrate:
    command: "php artisan migrate --force || true"
    leader_only: true
  05_cache_config:
    command: "php artisan config:clear && php artisan config:cache || true"
    leader_only: true
  06_cache_routes:
    command: "php artisan route:clear && php artisan route:cache || true"
    leader_only: true
  07_cache_views:
    command: "php artisan view:clear && php artisan view:cache || true"
    leader_only: true
```

### Fichier `.platform/hooks/postdeploy/01_storage.sh`
```bash
#!/bin/bash
cd /var/app/current

# Create directories if they don't exist
mkdir -p storage/logs
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p bootstrap/cache

# Set permissions
chmod -R 775 storage
chmod -R 775 bootstrap/cache
chown -R webapp:webapp storage
chown -R webapp:webapp bootstrap/cache

# Create storage link
php artisan storage:link || true

# Clear and cache
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true
```

### Fichier `.platform/nginx/conf.d/elasticbeanstalk/laravel.conf`
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### Fichier `.ebignore`
```
# Ignore vendor folder - let EB install via composer
vendor/

# Ignore local environment files
.env
.env.local
.env.*.local

# Ignore IDE files
.idea/
.vscode/
*.swp
*.swo

# Ignore logs and cache
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
bootstrap/cache/*

# Ignore node modules if any
node_modules/

# Ignore git
.git/
.gitignore

# Ignore tests
tests/
phpunit.xml
```

---

## 4. Frontend React - AWS Amplify

### Informations de l'application

| Paramètre | Valeur |
|-----------|--------|
| **Nom de l'application** | `prive-inventaire` |
| **Repository** | `Armel-Jordan/prive-inventaire` |
| **Branche** | `main` |
| **Répertoire monorepo** | `prise-inventaire-web` |
| **Framework** | React + Vite |
| **Commande de build** | `npm run build` |
| **Répertoire de sortie** | `dist` |

### URL du Frontend
```
https://main.d3ph830gn7z155.amplifyapp.com
```

### Variables d'environnement Amplify

| Clé | Valeur |
|-----|--------|
| `AMPLIFY_DIFF_DEPLOY` | `false` |
| `AMPLIFY_MONOREPO_APP_ROOT` | `prise-inventaire-web` |
| `VITE_API_URL` | `http://prise-api-prod.eba-ghrnc2uz.us-east-1.elasticbeanstalk.com/api` |

### Fichier `amplify.yml`
```yaml
version: 1
frontend:
  phases:
    preBuild:
      commands:
        - npm ci
    build:
      commands:
        - npm run build
  artifacts:
    baseDirectory: dist
    files:
      - '**/*'
  cache:
    paths:
      - node_modules/**/*
```

---

## 5. Base de données - Amazon RDS

### Informations de connexion

| Paramètre | Valeur |
|-----------|--------|
| **Moteur** | MySQL |
| **Host** | `prise-inventaire.c2dai848u8x4.us-east-1.rds.amazonaws.com` |
| **Port** | `3306` |
| **Base de données** | `prise_central` |
| **Utilisateur** | `admin` |
| **Mot de passe** | `PriseInv2026!` |
| **Région** | `us-east-1` |

### Chaîne de connexion
```
mysql://admin:PriseInv2026!@prise-inventaire.c2dai848u8x4.us-east-1.rds.amazonaws.com:3306/prise_central
```

### Connexion via CLI
```bash
mysql -h prise-inventaire.c2dai848u8x4.us-east-1.rds.amazonaws.com -u admin -p prise_central
```

---

## 6. Variables d'environnement

### Variables Elastic Beanstalk (API)

```bash
APP_NAME="Prise Inventaire"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://prise-api-prod.eba-ghrnc2uz.us-east-1.elasticbeanstalk.com
LOG_CHANNEL=stderr

# Base de données
DB_CONNECTION=mysql
DB_HOST=prise-inventaire.c2dai848u8x4.us-east-1.rds.amazonaws.com
DB_PORT=3306
DB_DATABASE=prise_central
DB_USERNAME=admin
DB_PASSWORD=PriseInv2026!
```

### Commande pour configurer les variables EB
```bash
eb setenv \
  APP_NAME="Prise Inventaire" \
  APP_ENV=production \
  APP_DEBUG=false \
  APP_URL="http://prise-api-prod.eba-ghrnc2uz.us-east-1.elasticbeanstalk.com" \
  LOG_CHANNEL=stderr \
  DB_CONNECTION=mysql \
  DB_HOST=prise-inventaire.c2dai848u8x4.us-east-1.rds.amazonaws.com \
  DB_PORT=3306 \
  DB_DATABASE=prise_central \
  DB_USERNAME=admin \
  DB_PASSWORD=PriseInv2026!
```

---

## 7. URLs et endpoints

### API Endpoints

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/api/mobile/secteurs` | GET | Liste des secteurs |
| `/api/mobile/login` | POST | Authentification mobile |
| `/api/mobile/scans` | GET/POST | Gestion des scans |
| `/api/mobile/historique` | GET | Historique des scans |
| `/api/mobile/sync` | POST | Synchronisation des données |

### URLs de production

| Service | URL |
|---------|-----|
| **API** | http://prise-api-prod.eba-ghrnc2uz.us-east-1.elasticbeanstalk.com |
| **Frontend** | https://main.d3ph830gn7z155.amplifyapp.com |
| **Console EB** | https://console.aws.amazon.com/elasticbeanstalk |
| **Console Amplify** | https://console.aws.amazon.com/amplify |
| **Console RDS** | https://console.aws.amazon.com/rds |

### Accès complets

#### Base de données MySQL (Amazon RDS)
| Paramètre | Valeur |
|-----------|--------|
| **Host** | `prise-inventaire.c2dai848u8x4.us-east-1.rds.amazonaws.com` |
| **Port** | `3306` |
| **Database** | `prise_central` |
| **User** | `admin` |
| **Password** | `PriseInv2026!` |

#### Commande de connexion MySQL
```bash
mysql -h prise-inventaire.c2dai848u8x4.us-east-1.rds.amazonaws.com -u admin -p prise_central
# Mot de passe : PriseInv2026!
```

#### Repository GitHub
| Paramètre | Valeur |
|-----------|--------|
| **URL** | https://github.com/Armel-Jordan/prive-inventaire |
| **Branche** | `main` |

#### Application Android
| Paramètre | Valeur |
|-----------|--------|
| **APK** | `/Users/armeljordan/Documents/prise/prise-inventaire-v1.0.apk` |
| **Keystore** | `Tircis.key` |
| **Store Password** | `tircis` |
| **Key Alias** | `tircis` |
| **Key Password** | `tircis` |

---

## 8. Commandes utiles

### Elastic Beanstalk CLI

```bash
# Vérifier le statut
eb status

# Voir la santé de l'environnement
eb health

# Déployer une nouvelle version
eb deploy

# Voir les logs
eb logs

# Ouvrir l'application dans le navigateur
eb open

# Configurer les variables d'environnement
eb setenv KEY=value

# Terminer l'environnement
eb terminate prise-api-prod

# Recréer l'environnement
eb create prise-api-prod --single --instance-type t3.small
```

### AWS CLI

```bash
# Vérifier la configuration
aws configure list

# Lister les applications EB
aws elasticbeanstalk describe-applications

# Lister les environnements EB
aws elasticbeanstalk describe-environments

# Lister les instances RDS
aws rds describe-db-instances
```

### Git et déploiement

```bash
# Commit et déploiement
git add .
git commit -m "Description du changement"
eb deploy

# Push vers GitHub (déclenche Amplify)
git push origin main
```

---

## 9. Dépannage

### Problème : Erreur 500 sur l'API

1. Vérifier les logs :
```bash
eb logs
```

2. Vérifier les variables d'environnement :
```bash
eb printenv
```

3. Vérifier la connexion à la base de données :
```bash
eb ssh
# Puis sur l'instance :
php /var/app/current/artisan tinker
>>> DB::connection()->getPdo();
```

### Problème : Composer échoue sur EB

1. Vérifier que `composer.lock` est compatible PHP 8.2
2. Supprimer les packages nécessitant PHP > 8.2 :
```bash
composer require "symfony/clock:^7.0" --ignore-platform-req=ext-oci8
```

### Problème : 404 sur les routes Laravel

1. Vérifier que le fichier `.platform/nginx/conf.d/elasticbeanstalk/laravel.conf` existe
2. Redéployer :
```bash
eb deploy
```

### Problème : Permission denied sur storage

1. Vérifier le script `01_storage.sh`
2. S'assurer que les dossiers sont créés avec les bonnes permissions

### Problème : Build Amplify échoue

1. Vérifier les logs de build dans la console Amplify
2. S'assurer que `AMPLIFY_MONOREPO_APP_ROOT` est correct
3. Vérifier que `package.json` et `package-lock.json` sont présents

---

## 📞 Support

Pour toute question technique :
- **Email** : support@prise-inventaire.com
- **Documentation** : `/docs/` dans le repository

---

## 📝 Historique des modifications

| Date | Version | Auteur | Description |
|------|---------|--------|-------------|
| 2026-03-02 | 1.0 | Équipe Technique | Création initiale |

---

*Document généré automatiquement - Prise Inventaire © 2026*
