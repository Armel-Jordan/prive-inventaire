package com.telipso.fripandroid.api

import com.google.gson.Gson
import com.google.gson.annotations.SerializedName
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.util.concurrent.TimeUnit

/**
 * Service API pour communiquer avec le backend Laravel
 * qui se connecte à la base Oracle GESMAN2.
 */
object InventaireApiService {

    private var baseUrl = "http://143.110.210.158/api"
    private var authToken: String? = null
    private var tenantSlug: String? = null
    private val gson = Gson()

    private val client = OkHttpClient.Builder()
        .connectTimeout(30, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .writeTimeout(30, TimeUnit.SECONDS)
        // Injecte le token Sanctum + le slug tenant sur chaque requête.
        .addInterceptor { chain ->
            val builder = chain.request().newBuilder()
            authToken?.let { builder.addHeader("Authorization", "Bearer $it") }
            tenantSlug?.let { builder.addHeader("X-Tenant-Slug", it) }
            chain.proceed(builder.build())
        }
        .build()

    private val JSON = "application/json; charset=utf-8".toMediaType()

    fun setBaseUrl(url: String) {
        baseUrl = url.trimEnd('/')
    }

    fun setAuth(token: String, slug: String) {
        authToken = token
        tenantSlug = slug
    }

    fun clearAuth() {
        authToken = null
        tenantSlug = null
    }

    fun hasToken(): Boolean = !authToken.isNullOrBlank()

    /**
     * POST /api/auth/login — authentifie un admin_user et récupère un token Sanctum.
     * Le token et le slug sont ensuite envoyés automatiquement sur chaque requête.
     */
    fun login(email: String, password: String, slug: String): LoginResult {
        val requestBody = gson.toJson(LoginRequest(email = email, password = password, tenantSlug = slug))
        val request = Request.Builder()
            .url("$baseUrl/auth/login")
            .post(requestBody.toRequestBody(JSON))
            .addHeader("Accept", "application/json")
            .addHeader("Content-Type", "application/json")
            .build()

        client.newCall(request).execute().use { response ->
            val body = response.body?.string() ?: throw Exception("Réponse vide")

            if (!response.isSuccessful) {
                val message = try {
                    gson.fromJson(body, LoginResult::class.java)?.message
                } catch (_: Exception) {
                    null
                }
                throw Exception(message ?: "Identifiants invalides (HTTP ${response.code})")
            }

            val result = gson.fromJson(body, LoginResult::class.java)
            if (result?.token.isNullOrBlank()) {
                throw Exception("Réponse de connexion invalide")
            }
            setAuth(result.token!!, slug)

            return result
        }
    }

    data class LoginRequest(
        @SerializedName("email") val email: String,
        @SerializedName("password") val password: String,
        @SerializedName("tenant_slug") val tenantSlug: String,
    )

    data class LoginResult(
        @SerializedName("success") val success: Boolean = false,
        @SerializedName("message") val message: String? = null,
        @SerializedName("token") val token: String? = null,
    )

    // --- Data Models ---

    data class Employe(
        @SerializedName("numero") val numero: String?,
        @SerializedName("nom") val nom: String?
    )

    data class Produit(
        @SerializedName("numero") val numero: String?,
        @SerializedName("description") val description: String?,
        @SerializedName("mesure") val mesure: String?,
        @SerializedName("type") val type: String?
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

    data class Scan(
        @SerializedName("id") val id: Int,
        @SerializedName("numero") val numero: String,
        @SerializedName("type") val type: String? = null,
        @SerializedName("quantite") val quantite: String,
        @SerializedName("unite_mesure") val uniteMesure: String? = null,
        @SerializedName("employe") val employe: String,
        @SerializedName("secteur") val secteur: String,
        @SerializedName("date_saisie") val dateSaisie: String,
        @SerializedName("scanneur") val scanneur: String? = null
    )

    // --- API Methods ---

    /**
     * GET /api/mobile/employes
     * Récupère la liste de tous les employés
     */
    fun getEmployes(): List<Employe> {
        val url = "$baseUrl/mobile/employes"
        android.util.Log.d("InventaireAPI", "GET $url")
        
        val request = Request.Builder()
            .url(url)
            .get()
            .addHeader("Accept", "application/json")
            .build()

        client.newCall(request).execute().use { response ->
            android.util.Log.d("InventaireAPI", "Response code: ${response.code}")
            android.util.Log.d("InventaireAPI", "Response message: ${response.message}")
            
            if (!response.isSuccessful) {
                val errorBody = response.body?.string() ?: "Pas de corps de réponse"
                android.util.Log.e("InventaireAPI", "Error body: $errorBody")
                throw Exception("Erreur HTTP ${response.code}: ${response.message}")
            }
            val body = response.body?.string() ?: throw Exception("Réponse vide")
            android.util.Log.d("InventaireAPI", "Response body: $body")
            return gson.fromJson(body, Array<Employe>::class.java).toList()
        }
    }

    /**
     * GET /api/mobile/produits
     * Récupère la liste complète des produits
     */
    fun getProduits(): List<Produit> {
        val request = Request.Builder()
            .url("$baseUrl/mobile/produits")
            .get()
            .addHeader("Accept", "application/json")
            .build()

        client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) {
                throw Exception("Erreur HTTP ${response.code}: ${response.message}")
            }
            val body = response.body?.string() ?: throw Exception("Réponse vide")
            return gson.fromJson(body, Array<Produit>::class.java).toList()
        }
    }

    /**
     * POST /api/mobile/produit/valider
     * Valide qu'un code-barres scanné existe dans la base
     */
    fun validerProduit(numero: String): ValiderProduitResponse {
        val requestBody = gson.toJson(ValiderProduitRequest(numero))
        val request = Request.Builder()
            .url("$baseUrl/mobile/produit/valider")
            .post(requestBody.toRequestBody(JSON))
            .addHeader("Accept", "application/json")
            .addHeader("Content-Type", "application/json")
            .build()

        client.newCall(request).execute().use { response ->
            val body = response.body?.string() ?: throw Exception("Réponse vide")
            
            if (response.code == 404) {
                // Produit introuvable
                return gson.fromJson(body, ValiderProduitResponse::class.java)
            }
            
            if (!response.isSuccessful) {
                throw Exception("Erreur HTTP ${response.code}: ${response.message}")
            }
            
            return gson.fromJson(body, ValiderProduitResponse::class.java)
        }
    }

    /**
     * POST /api/scan/enregistrer
     * Enregistre une saisie d'inventaire
     */
    fun enregistrerScan(
        numero: String,
        quantite: Double,
        employe: String,
        secteur: String,
        scanneur: String? = null
    ): EnregistrerScanResponse {
        val requestBody = gson.toJson(
            EnregistrerScanRequest(
                numero = numero,
                quantite = quantite,
                employe = employe,
                secteur = secteur,
                scanneur = scanneur
            )
        )
        
        val request = Request.Builder()
            .url("$baseUrl/mobile/scan/enregistrer")
            .post(requestBody.toRequestBody(JSON))
            .addHeader("Accept", "application/json")
            .addHeader("Content-Type", "application/json")
            .build()

        android.util.Log.d("InventaireAPI", "POST $baseUrl/mobile/scan/enregistrer")
        android.util.Log.d("InventaireAPI", "Request body: $requestBody")

        client.newCall(request).execute().use { response ->
            val body = response.body?.string() ?: throw Exception("Réponse vide")
            android.util.Log.d("InventaireAPI", "Response code: ${response.code}")
            android.util.Log.d("InventaireAPI", "Response body: $body")
            
            if (response.code == 404 || response.code == 422) {
                return gson.fromJson(body, EnregistrerScanResponse::class.java)
            }
            
            if (!response.isSuccessful) {
                android.util.Log.e("InventaireAPI", "Error body: $body")
                throw Exception("Erreur HTTP ${response.code}: ${response.message}")
            }
            
            return gson.fromJson(body, EnregistrerScanResponse::class.java)
        }
    }

    /**
     * GET /api/scan/historique
     * Récupère les 50 derniers scans pour un employé et secteur
     */
    fun getHistorique(employe: String, secteur: String): List<Scan> {
        val url = "$baseUrl/mobile/scan/historique?employe=$employe&secteur=$secteur"
        val request = Request.Builder()
            .url(url)
            .get()
            .addHeader("Accept", "application/json")
            .build()

        client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) {
                throw Exception("Erreur HTTP ${response.code}: ${response.message}")
            }
            val body = response.body?.string() ?: throw Exception("Réponse vide")
            return gson.fromJson(body, Array<Scan>::class.java).toList()
        }
    }

    /**
     * PUT /api/scan/{id}
     * Modifie la quantité d'un scan existant
     */
    fun modifierScan(id: Int, quantite: Double): ModifierScanResponse {
        val requestBody = gson.toJson(ModifierScanRequest(quantite))
        android.util.Log.d("InventaireAPI", "PUT $baseUrl/mobile/scan/$id")
        android.util.Log.d("InventaireAPI", "Request body: $requestBody")

        val request = Request.Builder()
            .url("$baseUrl/mobile/scan/$id")
            .put(requestBody.toRequestBody(JSON))
            .addHeader("Accept", "application/json")
            .addHeader("Content-Type", "application/json")
            .build()

        client.newCall(request).execute().use { response ->
            val body = response.body?.string() ?: throw Exception("Réponse vide")
            android.util.Log.d("InventaireAPI", "PUT Response code: ${response.code}")
            android.util.Log.d("InventaireAPI", "PUT Response body: $body")
            
            if (!response.isSuccessful) {
                android.util.Log.e("InventaireAPI", "PUT Error body: $body")
                throw Exception("Erreur HTTP ${response.code}: ${response.message}")
            }
            
            return gson.fromJson(body, ModifierScanResponse::class.java)
        }
    }

    /**
     * DELETE /api/scan/{id}
     * Supprime logiquement un scan (soft delete)
     */
    fun supprimerScan(id: Int): SupprimerScanResponse {
        android.util.Log.d("InventaireAPI", "DELETE $baseUrl/mobile/scan/$id")

        val request = Request.Builder()
            .url("$baseUrl/mobile/scan/$id")
            .delete()
            .addHeader("Accept", "application/json")
            .build()

        client.newCall(request).execute().use { response ->
            val body = response.body?.string() ?: throw Exception("Réponse vide")
            android.util.Log.d("InventaireAPI", "DELETE Response code: ${response.code}")
            android.util.Log.d("InventaireAPI", "DELETE Response body: $body")
            
            if (!response.isSuccessful) {
                android.util.Log.e("InventaireAPI", "DELETE Error body: $body")
                throw Exception("Erreur HTTP ${response.code}: ${response.message}")
            }
            
            return gson.fromJson(body, SupprimerScanResponse::class.java)
        }
    }

    // ============================================
    // RELOCALISATION API
    // ============================================

    data class Mouvement(
        @SerializedName("id") val id: Int,
        @SerializedName("type") val type: String,
        @SerializedName("produit_numero") val produitNumero: String,
        @SerializedName("produit_nom") val produitNom: String?,
        @SerializedName("secteur_source") val secteurSource: String?,
        @SerializedName("secteur_destination") val secteurDestination: String?,
        @SerializedName("quantite") val quantite: String,
        @SerializedName("unite_mesure") val uniteMesure: String?,
        @SerializedName("motif") val motif: String?,
        @SerializedName("employe") val employe: String,
        @SerializedName("date_mouvement") val dateMouvement: String
    )

    data class RelocalisationRequest(
        val type: String,
        @SerializedName("produit_numero") val produitNumero: String,
        @SerializedName("produit_nom") val produitNom: String? = null,
        @SerializedName("secteur_source") val secteurSource: String? = null,
        @SerializedName("secteur_destination") val secteurDestination: String? = null,
        val quantite: Double,
        @SerializedName("unite_mesure") val uniteMesure: String? = null,
        val motif: String? = null,
        val employe: String
    )

    data class RelocalisationResponse(
        val success: Boolean,
        val message: String,
        val mouvement: Mouvement? = null
    )

    data class Secteur(
        @SerializedName("id") val id: Int,
        @SerializedName("nom") val nom: String,
        @SerializedName("code") val code: String?
    )

    /**
     * GET /api/secteurs
     * Récupère la liste des secteurs
     */
    fun getSecteurs(): List<Secteur> {
        val request = Request.Builder()
            .url("$baseUrl/mobile/secteurs")
            .get()
            .addHeader("Accept", "application/json")
            .build()

        client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) {
                throw Exception("Erreur HTTP ${response.code}: ${response.message}")
            }
            val body = response.body?.string() ?: throw Exception("Réponse vide")
            return gson.fromJson(body, Array<Secteur>::class.java).toList()
        }
    }

    /**
     * POST /api/relocalisation
     * Enregistre un mouvement de relocalisation (arrivage, transfert, sortie)
     */
    fun enregistrerRelocalisation(
        type: String,
        produitNumero: String,
        produitNom: String? = null,
        secteurSource: String? = null,
        secteurDestination: String? = null,
        quantite: Double,
        uniteMesure: String? = null,
        motif: String? = null,
        employe: String
    ): RelocalisationResponse {
        val requestBody = gson.toJson(
            RelocalisationRequest(
                type = type,
                produitNumero = produitNumero,
                produitNom = produitNom,
                secteurSource = secteurSource,
                secteurDestination = secteurDestination,
                quantite = quantite,
                uniteMesure = uniteMesure,
                motif = motif,
                employe = employe
            )
        )

        android.util.Log.d("InventaireAPI", "POST $baseUrl/mobile/relocalisation")
        android.util.Log.d("InventaireAPI", "Request body: $requestBody")

        val request = Request.Builder()
            .url("$baseUrl/mobile/relocalisation")
            .post(requestBody.toRequestBody(JSON))
            .addHeader("Accept", "application/json")
            .addHeader("Content-Type", "application/json")
            .build()

        client.newCall(request).execute().use { response ->
            val body = response.body?.string() ?: throw Exception("Réponse vide")
            android.util.Log.d("InventaireAPI", "Response code: ${response.code}")
            android.util.Log.d("InventaireAPI", "Response body: $body")

            if (!response.isSuccessful && response.code != 422) {
                throw Exception("Erreur HTTP ${response.code}: ${response.message}")
            }

            return gson.fromJson(body, RelocalisationResponse::class.java)
        }
    }

    /**
     * GET /api/relocalisation
     * Récupère l'historique des mouvements
     */
    fun getHistoriqueMouvements(type: String? = null, limit: Int = 50): List<Mouvement> {
        var url = "$baseUrl/mobile/relocalisation?limit=$limit"
        if (type != null) {
            url += "&type=$type"
        }

        val request = Request.Builder()
            .url(url)
            .get()
            .addHeader("Accept", "application/json")
            .build()

        client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) {
                throw Exception("Erreur HTTP ${response.code}: ${response.message}")
            }
            val body = response.body?.string() ?: throw Exception("Réponse vide")
            return gson.fromJson(body, Array<Mouvement>::class.java).toList()
        }
    }
}
