# Document Technique - Application Android
## Prise Inventaire - Configuration Mobile

**Date de création** : 2 Mars 2026  
**Version** : 1.0  
**Application** : Prise Inventaire Android

---

## 📋 Table des matières

1. [Informations générales](#1-informations-générales)
2. [Configuration du projet](#2-configuration-du-projet)
3. [Signature de l'application](#3-signature-de-lapplication)
4. [Configuration API](#4-configuration-api)
5. [Génération de l'APK](#5-génération-de-lapk)
6. [Publication Play Store](#6-publication-play-store)
7. [Dépannage](#7-dépannage)

---

## 1. Informations générales

### Identité de l'application

| Paramètre | Valeur |
|-----------|--------|
| **Nom de l'application** | Prise Inventaire |
| **Package ID** | `com.prise.inventaire` |
| **Namespace** | `com.telipso.fripandroid` |
| **Version actuelle** | 1.0 |
| **SDK minimum** | 26 (Android 8.0) |
| **SDK cible** | 34 (Android 14) |
| **SDK de compilation** | 36 |

### Technologies utilisées

- **Langage** : Kotlin
- **UI Framework** : Jetpack Compose + XML Views
- **Architecture** : MVVM
- **Base de données locale** : Room
- **HTTP Client** : OkHttp
- **JSON Parser** : Gson
- **Scanner** : ZXing

---

## 2. Configuration du projet

### Structure du projet

```
prise-inventaire-android/
├── app/
│   ├── src/
│   │   └── main/
│   │       ├── java/com/telipso/fripandroid/
│   │       │   ├── api/
│   │       │   │   └── InventaireApiService.kt
│   │       │   ├── data/
│   │       │   │   └── AppDatabase.kt
│   │       │   ├── ui/
│   │       │   └── MainActivity.kt
│   │       ├── res/
│   │       └── AndroidManifest.xml
│   ├── build.gradle
│   └── proguard-rules.pro
├── gradle/
├── Tircis.key                    # Keystore de signature
├── build.gradle
├── settings.gradle
├── gradle.properties
└── version.properties
```

### Fichier `app/build.gradle`

```groovy
plugins {
    id 'com.android.application'
    id 'org.jetbrains.kotlin.android'
    id 'org.jetbrains.kotlin.plugin.compose'
    id 'com.google.devtools.ksp'
}

android {
    namespace 'com.telipso.fripandroid'
    compileSdk 36

    signingConfigs {
        release {
            storeFile file('../Tircis.key')
            storePassword 'Tircis2024'
            keyAlias 'tircis'
            keyPassword 'Tircis2024'
        }
    }

    defaultConfig {
        applicationId "com.prise.inventaire"
        minSdk 26
        targetSdk 34
        versionCode 1
        versionName "1.0"
    }

    buildTypes {
        release {
            minifyEnabled true
            proguardFiles getDefaultProguardFile('proguard-android-optimize.txt'), 'proguard-rules.pro'
            signingConfig signingConfigs.release
        }
    }
}
```

---

## 3. Signature de l'application

### Informations du Keystore

| Paramètre | Valeur |
|-----------|--------|
| **Fichier** | `Tircis.key` |
| **Emplacement** | `/prise-inventaire-android/Tircis.key` |
| **Type** | JKS (Java KeyStore) |
| **Mot de passe du store** | `Tircis2024` |
| **Alias de la clé** | `tircis` |
| **Mot de passe de la clé** | `Tircis2024` |

### ⚠️ IMPORTANT - Sécurité

> **Ne jamais partager le keystore ou les mots de passe publiquement !**
> 
> Le keystore est essentiel pour :
> - Signer les mises à jour de l'application
> - Publier sur le Play Store
> - Maintenir la continuité des mises à jour
>
> **Si le keystore est perdu, vous ne pourrez plus mettre à jour l'application sur le Play Store !**

### Sauvegarde recommandée

1. Copier `Tircis.key` dans un emplacement sécurisé
2. Stocker les mots de passe dans un gestionnaire de mots de passe
3. Ne jamais commiter le keystore dans Git (déjà dans `.gitignore`)

### Vérifier le keystore

```bash
keytool -list -v -keystore Tircis.key -storepass Tircis2024
```

---

## 4. Configuration API

### URL de l'API

| Environnement | URL |
|---------------|-----|
| **Production** | `http://prise-api-production.eba-ghrnc2uz.us-east-1.elasticbeanstalk.com/api` |
| **Local** | `http://10.0.2.2:8000/api` (émulateur) |
| **Local** | `http://192.168.x.x:8000/api` (appareil physique) |

### Configuration dans le code

Fichier : `app/src/main/java/com/telipso/fripandroid/api/InventaireApiService.kt`

```kotlin
companion object {
    // URL de production
    private const val BASE_URL = "http://prise-api-production.eba-ghrnc2uz.us-east-1.elasticbeanstalk.com/api"
    
    // URL locale pour développement
    // private const val BASE_URL = "http://10.0.2.2:8000/api"
}
```

### Endpoints API utilisés

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/mobile/login` | POST | Authentification |
| `/mobile/secteurs` | GET | Liste des secteurs |
| `/mobile/scans` | GET/POST | Gestion des scans |
| `/mobile/historique` | GET | Historique |
| `/mobile/sync` | POST | Synchronisation |

### Permissions Android requises

```xml
<uses-permission android:name="android.permission.INTERNET" />
<uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />
<uses-permission android:name="android.permission.CAMERA" />
<uses-permission android:name="android.permission.VIBRATE" />
```

---

## 5. Génération de l'APK

### Prérequis

- Android Studio (version récente)
- JDK 11 ou supérieur
- Gradle 8.x

### Méthode 1 : Via Android Studio

1. Ouvrir le projet dans Android Studio
2. Menu **Build** → **Generate Signed Bundle / APK**
3. Sélectionner **APK**
4. Configurer la signature :
   - Key store path : `../Tircis.key`
   - Key store password : `Tircis2024`
   - Key alias : `tircis`
   - Key password : `Tircis2024`
5. Sélectionner **release**
6. Cliquer **Create**

### Méthode 2 : Via ligne de commande

```bash
cd prise-inventaire-android

# Nettoyer le projet
./gradlew clean

# Générer l'APK release signé
./gradlew assembleRelease

# L'APK sera généré dans :
# app/build/outputs/apk/release/app-release.apk
```

### Méthode 3 : Générer un App Bundle (AAB) pour Play Store

```bash
./gradlew bundleRelease

# Le bundle sera généré dans :
# app/build/outputs/bundle/release/app-release.aab
```

### Emplacement des fichiers générés

| Type | Chemin |
|------|--------|
| **APK Debug** | `app/build/outputs/apk/debug/app-debug.apk` |
| **APK Release** | `app/build/outputs/apk/release/app-release.apk` |
| **AAB Release** | `app/build/outputs/bundle/release/app-release.aab` |

---

## 6. Publication Play Store

### Informations du compte développeur

| Paramètre | Valeur |
|-----------|--------|
| **Console** | https://play.google.com/console |
| **Email développeur** | *(à compléter)* |
| **Frais d'inscription** | 25$ (une seule fois) |

### Étapes de publication

1. **Créer un compte développeur** sur Google Play Console
2. **Créer une nouvelle application**
3. **Remplir les informations** :
   - Titre : Prise Inventaire
   - Description courte et longue
   - Captures d'écran (min 2)
   - Icône 512x512
   - Graphique de fonctionnalité 1024x500
4. **Configurer la fiche Play Store**
5. **Télécharger l'AAB** (pas l'APK)
6. **Soumettre pour examen**

### Checklist avant publication

- [ ] Tester l'APK sur plusieurs appareils
- [ ] Vérifier que l'API de production fonctionne
- [ ] Préparer les captures d'écran
- [ ] Rédiger la description
- [ ] Définir la politique de confidentialité
- [ ] Configurer les pays de distribution

---

## 7. Dépannage

### Erreur : "Keystore was tampered with, or password was incorrect"

**Solution** : Vérifier le mot de passe du keystore
```bash
keytool -list -keystore Tircis.key
# Entrer le mot de passe : Tircis2024
```

### Erreur : "Could not resolve dependencies"

**Solution** : Synchroniser Gradle
```bash
./gradlew --refresh-dependencies
```

### Erreur : "SDK location not found"

**Solution** : Créer/vérifier `local.properties`
```properties
sdk.dir=/Users/VOTRE_USER/Library/Android/sdk
```

### Erreur : "Connection refused" sur l'API

**Solutions** :
1. Vérifier que l'API est en ligne
2. Vérifier l'URL dans `InventaireApiService.kt`
3. Pour l'émulateur, utiliser `10.0.2.2` au lieu de `localhost`

### Erreur : "NullPointerException" sur les données

**Solution** : Vérifier que les champs nullable sont gérés
```kotlin
data class Scan(
    val type: String? = null,
    val uniteMesure: String? = null,
    // ...
)
```

### L'APK ne s'installe pas

**Solutions** :
1. Activer "Sources inconnues" sur l'appareil
2. Désinstaller l'ancienne version
3. Vérifier que l'APK est signé

---

## 📁 Fichiers importants

| Fichier | Description |
|---------|-------------|
| `Tircis.key` | Keystore de signature (NE PAS PERDRE) |
| `app/build.gradle` | Configuration de build |
| `gradle.properties` | Propriétés Gradle |
| `version.properties` | Version de l'application |
| `local.properties` | Chemin SDK local |

---

## 🔐 Récapitulatif des accès

### Keystore
- **Fichier** : `Tircis.key`
- **Store Password** : `Tircis2024`
- **Key Alias** : `tircis`
- **Key Password** : `Tircis2024`

### API Production
- **URL** : `http://prise-api-production.eba-ghrnc2uz.us-east-1.elasticbeanstalk.com/api`

### Base de données (pour référence)
- **Host** : `prise-inventaire.c2dai848u8x4.us-east-1.rds.amazonaws.com`
- **Database** : `prise_central`
- **User** : `admin`
- **Password** : `PriseInv2026!`

---

## 📞 Support

Pour toute question technique :
- **Documentation API** : `/docs/06-CONFIGURATION-AWS-TECHNIQUE.md`
- **Repository** : GitHub - Armel-Jordan/prive-inventaire

---

## 📝 Historique des modifications

| Date | Version | Auteur | Description |
|------|---------|--------|-------------|
| 2026-03-02 | 1.0 | Équipe Technique | Création initiale |

---

*Document généré automatiquement - Prise Inventaire © 2026*
