# Guide de Déploiement AWS - Prise Inventaire

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                        AWS Cloud                             │
│  ┌─────────────────┐    ┌─────────────────┐                 │
│  │  AWS Amplify    │    │ Elastic Beanstalk│                │
│  │  (Frontend)     │───▶│   (API Laravel)  │                │
│  │  React + Vite   │    │   PHP 8.2        │                │
│  └─────────────────┘    └────────┬─────────┘                │
│                                  │                           │
│                         ┌────────▼─────────┐                │
│                         │    AWS RDS       │                │
│                         │   MySQL 8.0      │                │
│                         └──────────────────┘                │
└─────────────────────────────────────────────────────────────┘
```

---

## 1. Prérequis

- Compte AWS avec accès à la console
- AWS CLI installé et configuré
- EB CLI installé (`pip install awsebcli`)
- Node.js 18+ et npm

---

## 2. Déploiement de l'API Laravel (Elastic Beanstalk)

### 2.1 Initialiser Elastic Beanstalk

```bash
cd prise-inventaire-api

# Initialiser EB
eb init -p "PHP 8.2" prise-inventaire-api --region us-east-1

# Créer l'environnement
eb create prise-inventaire-prod --single --instance-type t3.small
```

### 2.2 Configurer les variables d'environnement

Dans la console AWS Elastic Beanstalk :
1. Aller dans **Configuration** → **Software**
2. Ajouter les variables d'environnement :

| Variable | Valeur |
|----------|--------|
| APP_ENV | production |
| APP_DEBUG | false |
| APP_KEY | (générer avec `php artisan key:generate --show`) |
| APP_URL | https://api.votre-domaine.com |
| DB_CONNECTION | mysql |
| DB_HOST | prise-inventaire.c2dai848u8x4.us-east-1.rds.amazonaws.com |
| DB_PORT | 3306 |
| DB_DATABASE | prise_central |
| DB_USERNAME | votre_username |
| DB_PASSWORD | votre_password |

### 2.3 Configurer le groupe de sécurité RDS

1. Aller dans **RDS** → **Databases** → votre instance
2. Cliquer sur le **Security Group**
3. Ajouter une règle entrante :
   - Type: MySQL/Aurora
   - Port: 3306
   - Source: Le security group de Elastic Beanstalk

### 2.4 Déployer

```bash
eb deploy
```

### 2.5 Configurer HTTPS (optionnel mais recommandé)

1. Aller dans **Certificate Manager** → Demander un certificat
2. Valider le domaine
3. Dans EB → Configuration → Load Balancer → Ajouter listener HTTPS

---

## 3. Déploiement du Frontend React (AWS Amplify)

### 3.1 Via la console AWS

1. Aller dans **AWS Amplify** → **New app** → **Host web app**
2. Connecter votre repository Git
3. Sélectionner la branche `main`
4. Configurer le build :
   - Build command: `npm run build`
   - Output directory: `dist`
   - Base directory: `prise-inventaire-web`

### 3.2 Variables d'environnement Amplify

Dans **App settings** → **Environment variables** :

| Variable | Valeur |
|----------|--------|
| VITE_API_URL | https://api.votre-domaine.com/api |

### 3.3 Rewrites pour SPA

Dans **App settings** → **Rewrites and redirects** :

| Source | Target | Type |
|--------|--------|------|
| `</^[^.]+$\|\.(?!(css\|gif\|ico\|jpg\|js\|png\|txt\|svg\|woff\|woff2\|ttf\|map\|json)$)([^.]+$)/>` | `/index.html` | 200 |

---

## 4. Générer l'APK Android signé

### 4.1 Créer un keystore

```bash
cd prise-inventaire-android

keytool -genkey -v -keystore prise-inventaire.keystore \
  -alias prise-inventaire \
  -keyalg RSA -keysize 2048 -validity 10000
```

### 4.2 Configurer le signing dans `app/build.gradle`

```gradle
android {
    signingConfigs {
        release {
            storeFile file('../prise-inventaire.keystore')
            storePassword 'votre_password'
            keyAlias 'prise-inventaire'
            keyPassword 'votre_password'
        }
    }
    buildTypes {
        release {
            signingConfig signingConfigs.release
            minifyEnabled true
            proguardFiles getDefaultProguardFile('proguard-android-optimize.txt'), 'proguard-rules.pro'
        }
    }
}
```

### 4.3 Mettre à jour l'URL de l'API

Dans `app/src/main/java/.../api/InventaireApiService.kt`, changer :

```kotlin
private const val DEFAULT_BASE_URL = "https://api.votre-domaine.com/api"
```

### 4.4 Générer l'APK

```bash
./gradlew assembleRelease
```

L'APK sera dans : `app/build/outputs/apk/release/app-release.apk`

---

## 5. Checklist de déploiement

### API Laravel
- [ ] Variables d'environnement configurées
- [ ] Groupe de sécurité RDS autorise EB
- [ ] Migrations exécutées
- [ ] HTTPS configuré
- [ ] CORS configuré pour le domaine frontend

### Frontend React
- [ ] VITE_API_URL pointe vers l'API de production
- [ ] Build réussi
- [ ] Rewrites SPA configurés

### Android
- [ ] URL API mise à jour
- [ ] Keystore créé et sécurisé
- [ ] APK signé généré
- [ ] Testé sur appareil réel

---

## 6. Commandes utiles

```bash
# Voir les logs EB
eb logs

# SSH vers l'instance EB
eb ssh

# Redéployer
eb deploy

# Voir le statut
eb status

# Ouvrir l'app dans le navigateur
eb open
```

---

## 7. Coûts estimés (mensuel)

| Service | Estimation |
|---------|------------|
| Elastic Beanstalk (t3.small) | ~$15 |
| RDS MySQL (db.t3.micro) | ~$15 |
| AWS Amplify | Gratuit (tier) |
| **Total** | **~$30/mois** |

---

*Document créé le 2 mars 2026*
