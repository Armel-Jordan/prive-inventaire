# API Prise d'Inventaire - Documentation

## Vue d'ensemble

Cette API Laravel sert de pont entre l'application Android de prise d'inventaire et la base de données Oracle GESMAN2. Elle permet de gérer l'identification des employés, la validation des produits, et l'enregistrement des scans d'inventaire.

---

## Configuration de base

### URL de base
```
http://votre-serveur:8000/api
```

### Headers requis pour toutes les requêtes
```
Accept: application/json
Content-Type: application/json (pour les POST)
```

---

## Endpoints disponibles

### 1. Liste des employés

**Endpoint** : `GET /api/employes`

**Description** : Récupère la liste de tous les employés pour l'écran de connexion.

**Requête** :
```http
GET http://votre-serveur:8000/api/employes
Accept: application/json
```

**Réponse (200 OK)** :
```json
[
  {
    "NUMERO": "GAG-R",
    "NOM": "RICHARD GAGNON"
  },
  {
    "NUMERO": "DUC-F",
    "NOM": "FRANÇOIS DUCHESNEAU"
  }
]
```

**Utilisation Android** :
- Afficher dans un Spinner/ListView pour sélection
- Stocker `NUMERO` pour les requêtes suivantes
- Afficher `NOM` à l'utilisateur

---

### 2. Liste des produits

**Endpoint** : `GET /api/produits`

**Description** : Récupère la liste complète des produits disponibles (matières premières et produits finis).

**Requête** :
```http
GET http://votre-serveur:8000/api/produits
Accept: application/json
```

**Réponse (200 OK)** :
```json
[
  {
    "NUMERO": "ABC123",
    "DESCRIPTION": "Produit exemple",
    "MESURE": "kg",
    "TYPE": "MP"
  },
  {
    "NUMERO": "XYZ789",
    "DESCRIPTION": "Autre produit",
    "MESURE": "unité",
    "TYPE": "PF"
  }
]
```

**Champs** :
- `NUMERO` : Code du produit (utilisé pour le scan)
- `DESCRIPTION` : Description du produit
- `MESURE` : Unité de mesure (kg, unité, etc.)
- `TYPE` : `MP` (Matière Première) ou `PF` (Produit Fini)

---

### 3. Valider un produit

**Endpoint** : `POST /api/produit/valider`

**Description** : Valide qu'un code-barres scanné ou saisi existe dans la base de données.

**Requête** :
```http
POST http://votre-serveur:8000/api/produit/valider
Accept: application/json
Content-Type: application/json

{
  "numero": "ABC123"
}
```

**Réponse succès (200 OK)** :
```json
{
  "valide": true,
  "numero": "ABC123",
  "description": "Produit exemple",
  "unite_mesure": "kg",
  "type": "MP"
}
```

**Réponse échec (404 Not Found)** :
```json
{
  "valide": false,
  "message": "Numéro introuvable"
}
```

**Utilisation Android** :
- Appeler après chaque scan ou saisie manuelle
- Si `valide: true` → afficher description et demander quantité
- Si `valide: false` → afficher message d'erreur (visuel + sonore)

---

### 4. Enregistrer un scan

**Endpoint** : `POST /api/scan/enregistrer`

**Description** : Enregistre une saisie d'inventaire dans la base de données.

**Requête** :
```http
POST http://votre-serveur:8000/api/scan/enregistrer
Accept: application/json
Content-Type: application/json

{
  "numero": "ABC123",
  "quantite": 10.5,
  "employe": "GAG-R",
  "secteur": "A12",
  "scanneur": "SCANNER-01"
}
```

**Paramètres** :
- `numero` (string, requis) : Code du produit validé
- `quantite` (number, requis) : Quantité saisie (≥ 0)
- `employe` (string, requis) : Numéro de l'employé connecté
- `secteur` (string, requis) : Secteur d'inventaire (format: lettre + 1-2 chiffres, ex: A1, B12)
- `scanneur` (string, optionnel) : Identifiant du scanneur utilisé (max 20 caractères)

**Réponse succès (200 OK)** :
```json
{
  "success": true,
  "message": "Saisie enregistrée",
  "scan": {
    "ID": 1,
    "NUMERO": "ABC123",
    "TYPE": "MP",
    "QUANTITE": "10.5000",
    "UNITE_MESURE": "kg",
    "EMPLOYE": "GAG-R",
    "SECTEUR": "A12",
    "DATE_SAISIE": "2026-02-26T15:30:00.000000Z",
    "SCANNEUR": "SCANNER-01"
  }
}
```

**Réponse échec (404 Not Found)** :
```json
{
  "success": false,
  "message": "Numéro de produit introuvable"
}
```

**Réponse erreur validation (422 Unprocessable Entity)** :
```json
{
  "message": "The secteur field format is invalid.",
  "errors": {
    "secteur": [
      "The secteur field format is invalid."
    ]
  }
}
```

**Utilisation Android** :
- Appeler après validation du produit et saisie de la quantité
- Stocker le `scan.ID` localement si besoin
- Mettre à jour l'affichage de la quantité en main

---

### 5. Modifier un scan

**Endpoint** : `PUT /api/scan/{id}`

**Description** : Modifie la quantité d'un scan d'inventaire existant.

**Requête** :
```http
PUT http://votre-serveur:8000/api/scan/1
Accept: application/json
Content-Type: application/json

{
  "quantite": 15.5
}
```

**Paramètres** :
- `id` (integer, dans l'URL) : ID du scan à modifier
- `quantite` (numeric, requis) : Nouvelle quantité (>= 0)

**Réponse succès (200 OK)** :
```json
{
  "success": true,
  "message": "Scan modifié avec succès",
  "scan": {
    "ID": 1,
    "NUMERO": "ABC123",
    "TYPE": "MP",
    "QUANTITE": "15.5000",
    "UNITE_MESURE": "kg",
    "EMPLOYE": "GAG-R",
    "SECTEUR": "A12",
    "DATE_SAISIE": "2026-02-26T15:30:00.000000Z",
    "SCANNEUR": "SCANNER-01",
    "CREATED_AT": "2026-02-26T15:30:00.000000Z",
    "UPDATED_AT": "2026-02-26T16:45:00.000000Z",
    "DELETED_AT": null
  }
}
```

**Réponse échec (404 Not Found)** :
```json
{
  "success": false,
  "message": "Erreur lors de la modification: No query results for model..."
}
```

**Utilisation Android** :
- Permettre la modification depuis l'historique
- Afficher un dialog avec la quantité actuelle pré-remplie
- Mettre à jour automatiquement `UPDATED_AT`

---

### 6. Supprimer un scan

**Endpoint** : `DELETE /api/scan/{id}`

**Description** : Supprime logiquement un scan d'inventaire (soft delete). Le scan reste en base mais n'apparaît plus dans l'historique.

**Requête** :
```http
DELETE http://votre-serveur:8000/api/scan/1
Accept: application/json
```

**Paramètres** :
- `id` (integer, dans l'URL) : ID du scan à supprimer

**Réponse succès (200 OK)** :
```json
{
  "success": true,
  "message": "Scan supprimé avec succès"
}
```

**Réponse échec (404 Not Found)** :
```json
{
  "success": false,
  "message": "Erreur lors de la suppression: No query results for model..."
}
```

**Utilisation Android** :
- Ajouter un bouton de suppression dans l'historique
- Demander confirmation avant suppression
- Le scan ne sera plus visible dans l'historique après suppression
- La suppression est logique (soft delete) : le champ `DELETED_AT` est rempli

---

### 7. Historique des scans

**Endpoint** : `GET /api/scan/historique`

**Description** : Récupère les 50 derniers scans actifs (non supprimés) pour un employé et un secteur donnés.

**Requête** :
```http
GET http://votre-serveur:8000/api/scan/historique?employe=GAG-R&secteur=A12
Accept: application/json
```

**Paramètres (query string)** :
- `employe` (string, requis) : Numéro de l'employé
- `secteur` (string, requis) : Secteur d'inventaire (format: A12)

**Réponse (200 OK)** :
```json
[
  {
    "ID": 2,
    "NUMERO": "XYZ789",
    "TYPE": "PF",
    "QUANTITE": "5.0000",
    "UNITE_MESURE": "unité",
    "EMPLOYE": "GAG-R",
    "SECTEUR": "A12",
    "DATE_SAISIE": "2026-02-26T15:35:00.000000Z"
  },
  {
    "ID": 1,
    "NUMERO": "ABC123",
    "TYPE": "MP",
    "QUANTITE": "10.5000",
    "UNITE_MESURE": "kg",
    "EMPLOYE": "GAG-R",
    "SECTEUR": "A12",
    "DATE_SAISIE": "2026-02-26T15:30:00.000000Z"
  }
]
```

**Utilisation Android** :
- Afficher dans une liste/RecyclerView
- Trier par date décroissante (plus récent en premier)
- Limité aux 50 dernières entrées

---

## Flux de travail Android

### 1. Écran de connexion
```kotlin
// 1. Récupérer la liste des employés
GET /api/employes

// 2. Afficher dans un Spinner
// 3. Au clic sur "Se connecter", stocker employe.NUMERO
```

### 2. Sélection du secteur
```kotlin
// Validation du format secteur : ^[A-Za-z]\d{1,2}$
// Exemples valides : A1, B12, C99
// Exemples invalides : AB1, 1A, A123
```

### 3. Scan de produit
```kotlin
// 1. Scanner le code-barres ou saisie manuelle
val numero = scanResult // ou editText.text

// 2. Valider le produit
POST /api/produit/valider
{
  "numero": numero
}

// 3a. Si valide: afficher description + unité de mesure
// 3b. Si invalide: afficher erreur (Toast + son d'erreur)
```

### 4. Saisie de la quantité
```kotlin
// 1. Afficher un dialog avec EditText pour la quantité
// 2. Afficher l'unité de mesure à côté (ex: "Quantité (kg)")
// 3. Valider que quantité >= 0
```

### 5. Enregistrement
```kotlin
POST /api/scan/enregistrer
{
  "numero": produit.numero,
  "quantite": quantiteSaisie,
  "employe": employeConnecte.NUMERO,
  "secteur": secteurActuel,
  "scanneur": "SCANNER-01" // Optionnel: identifiant du scanneur
}

// Si succès: 
// - Afficher confirmation
// - Mettre à jour quantité en main
// - Retour à l'écran de scan
```

### 6. Consultation de l'historique
```kotlin
GET /api/scan/historique?employe=${employeConnecte.NUMERO}&secteur=${secteurActuel}

// Afficher dans une RecyclerView avec:
// - Numéro du produit
// - Quantité + unité
// - Date/heure
// - Boutons: Modifier / Supprimer
```

### 7. Modification d'un scan
```kotlin
// 1. Au clic sur "Modifier" dans l'historique
// 2. Afficher un dialog avec la quantité actuelle pré-remplie
// 3. Permettre la modification de la quantité

PUT /api/scan/${scan.ID}
{
  "quantite": nouvelleQuantite
}

// Si succès:
// - Afficher confirmation
// - Rafraîchir l'historique
```

### 8. Suppression d'un scan
```kotlin
// 1. Au clic sur "Supprimer" dans l'historique
// 2. Afficher un dialog de confirmation
// 3. Si confirmé:

DELETE /api/scan/${scan.ID}

// Si succès:
// - Afficher confirmation
// - Retirer l'élément de la liste
// - Le scan est marqué comme supprimé (soft delete)
```

---

## Gestion des erreurs

### Codes HTTP
- `200 OK` : Requête réussie
- `404 Not Found` : Ressource introuvable (produit, employé)
- `422 Unprocessable Entity` : Erreur de validation
- `500 Internal Server Error` : Erreur serveur

### Exemple de gestion Android (Retrofit)
```kotlin
try {
    val response = apiService.validerProduit(numero)
    if (response.valide) {
        // Produit valide
        afficherProduit(response)
    } else {
        // Produit invalide
        afficherErreur(response.message)
        jouerSonErreur()
    }
} catch (e: HttpException) {
    when (e.code()) {
        404 -> afficherErreur("Produit introuvable")
        422 -> afficherErreur("Données invalides")
        500 -> afficherErreur("Erreur serveur")
        else -> afficherErreur("Erreur réseau")
    }
}
```

---

## Configuration Android (Retrofit)

### 1. Dépendances (build.gradle)
```gradle
dependencies {
    implementation 'com.squareup.retrofit2:retrofit:2.9.0'
    implementation 'com.squareup.retrofit2:converter-gson:2.9.0'
    implementation 'com.squareup.okhttp3:logging-interceptor:4.11.0'
}
```

### 2. Interface API
```kotlin
interface InventaireApi {
    
    @GET("employes")
    suspend fun getEmployes(): List<Employe>
    
    @GET("produits")
    suspend fun getProduits(): List<Produit>
    
    @POST("produit/valider")
    suspend fun validerProduit(@Body request: ValiderProduitRequest): ValiderProduitResponse
    
    @POST("scan/enregistrer")
    suspend fun enregistrerScan(@Body request: EnregistrerScanRequest): EnregistrerScanResponse
    
    @GET("scan/historique")
    suspend fun getHistorique(
        @Query("employe") employe: String,
        @Query("secteur") secteur: String
    ): List<Scan>
    
    @PUT("scan/{id}")
    suspend fun modifierScan(
        @Path("id") id: Int,
        @Body request: ModifierScanRequest
    ): ModifierScanResponse
    
    @DELETE("scan/{id}")
    suspend fun supprimerScan(@Path("id") id: Int): SupprimerScanResponse
}
```

### 3. Modèles de données
```kotlin
data class Employe(
    @SerializedName("NUMERO") val numero: String,
    @SerializedName("NOM") val nom: String
)

data class Produit(
    @SerializedName("NUMERO") val numero: String,
    @SerializedName("DESCRIPTION") val description: String,
    @SerializedName("MESURE") val mesure: String,
    @SerializedName("TYPE") val type: String
)

data class ValiderProduitRequest(
    val numero: String
)

data class ValiderProduitResponse(
    val valide: Boolean,
    val numero: String? = null,
    val description: String? = null,
    val unite_mesure: String? = null,
    val type: String? = null,
    val message: String? = null
)

data class EnregistrerScanRequest(
    val numero: String,
    val quantite: Double,
    val employe: String,
    val secteur: String,
    val scanneur: String? = null
)

data class EnregistrerScanResponse(
    val success: Boolean,
    val message: String,
    val scan: Scan? = null
)

data class Scan(
    @SerializedName("ID") val id: Int,
    @SerializedName("NUMERO") val numero: String,
    @SerializedName("TYPE") val type: String,
    @SerializedName("QUANTITE") val quantite: String,
    @SerializedName("UNITE_MESURE") val uniteMesure: String,
    @SerializedName("EMPLOYE") val employe: String,
    @SerializedName("SECTEUR") val secteur: String,
    @SerializedName("DATE_SAISIE") val dateSaisie: String,
    @SerializedName("SCANNEUR") val scanneur: String?,
    @SerializedName("CREATED_AT") val createdAt: String? = null,
    @SerializedName("UPDATED_AT") val updatedAt: String? = null,
    @SerializedName("DELETED_AT") val deletedAt: String? = null
)

data class ModifierScanRequest(
    val quantite: Double
)

data class ModifierScanResponse(
    val success: Boolean,
    val message: String,
    val scan: Scan? = null
)

data class SupprimerScanResponse(
    val success: Boolean,
    val message: String
)
```

### 4. Configuration Retrofit
```kotlin
object RetrofitClient {
    private const val BASE_URL = "http://10.0.55.162:8000/api/"
    
    private val loggingInterceptor = HttpLoggingInterceptor().apply {
        level = HttpLoggingInterceptor.Level.BODY
    }
    
    private val client = OkHttpClient.Builder()
        .addInterceptor(loggingInterceptor)
        .connectTimeout(30, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .build()
    
    val api: InventaireApi by lazy {
        Retrofit.Builder()
            .baseUrl(BASE_URL)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(InventaireApi::class.java)
    }
}
```

### 5. Permissions (AndroidManifest.xml)
```xml
<uses-permission android:name="android.permission.INTERNET" />
<uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />
<uses-permission android:name="android.permission.CAMERA" />

<application
    android:usesCleartextTraffic="true"
    ...>
```

---

## Validation des données

### Format du secteur
- **Regex** : `^[A-Za-z]\d{1,2}$`
- **Exemples valides** : A1, B12, C99, a5
- **Exemples invalides** : AB1, 1A, A123, A

### Quantité
- **Type** : Nombre décimal
- **Contrainte** : ≥ 0
- **Précision** : 4 décimales maximum

### Type de produit
- **Valeurs possibles** : `MP` (Matière Première) ou `PF` (Produit Fini)

---

## Base de données Oracle

### Tables/Vues utilisées

#### EMPLOYE
- `NUMERO` (VARCHAR2) : Numéro unique de l'employé
- `NOM` (VARCHAR2) : Nom complet de l'employé

#### V_PRODUITS_MOBILES (Vue)
- `NUMERO` (VARCHAR2) : Code du produit
- `DESCRIPTION` (VARCHAR2) : Description du produit
- `MESURE` (VARCHAR2) : Unité de mesure
- `TYPE` (VARCHAR2) : MP ou PF

#### INVENTAIRE_SCAN (Table créée par l'API)
- `ID` (NUMBER) : Identifiant auto-incrémenté
- `NUMERO` (VARCHAR2) : Code du produit
- `TYPE` (VARCHAR2) : Type de produit (MP/PF)
- `QUANTITE` (NUMBER) : Quantité saisie
- `UNITE_MESURE` (VARCHAR2) : Unité de mesure
- `EMPLOYE` (VARCHAR2) : Numéro de l'employé
- `SECTEUR` (VARCHAR2) : Secteur d'inventaire
- `DATE_SAISIE` (TIMESTAMP) : Date et heure de la saisie
- `SCANNEUR` (VARCHAR2) : Identifiant du scanneur (optionnel)
- `CREATED_AT` (TIMESTAMP) : Date de création de l'enregistrement
- `UPDATED_AT` (TIMESTAMP) : Date de dernière modification
- `DELETED_AT` (TIMESTAMP) : Date de suppression logique (NULL si actif)

---

## Déploiement

### Prérequis serveur
1. PHP 8.2+ avec extension OCI8
2. Oracle Instant Client
3. Accès réseau à la base Oracle GESMAN2

### Variables d'environnement (.env)
```env
DB_ORACLE_HOST=10.0.55.162
DB_ORACLE_PORT=1521
DB_ORACLE_DATABASE=GESMAN2
DB_ORACLE_SERVICE_NAME=GESMAN2
DB_ORACLE_USERNAME=votre_user
DB_ORACLE_PASSWORD=votre_password
DB_ORACLE_CHARSET=AL32UTF8
```

### Commandes de déploiement
```bash
# Installation des dépendances
composer install --optimize-autoloader --no-dev

# Optimisation
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Démarrage du serveur
php artisan serve --host=0.0.0.0 --port=8000
```

---

## Support et contact

Pour toute question ou problème d'intégration, contactez l'équipe de développement.

**Version de l'API** : 1.1  
**Dernière mise à jour** : 26 février 2026

---

## Changelog

### Version 1.1 (26 février 2026)
- ✅ Ajout de l'endpoint `PUT /api/scan/{id}` pour modifier un scan
- ✅ Ajout de l'endpoint `DELETE /api/scan/{id}` pour supprimer un scan (soft delete)
- ✅ Ajout des colonnes d'audit : `CREATED_AT`, `UPDATED_AT`, `DELETED_AT`
- ✅ Support de la suppression logique (soft delete)
- ✅ Amélioration de la gestion d'erreur dans la validation de produit

### Version 1.0 (26 février 2026)
- ✅ Endpoints de base : employes, produits, validation, enregistrement, historique
- ✅ Connexion à Oracle GESMAN2
- ✅ Support du champ optionnel `SCANNEUR`
