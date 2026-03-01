package com.telipso.fripandroid

import android.content.Context
import android.content.Context.MODE_PRIVATE
import android.content.res.Configuration
import android.content.res.Resources
import android.os.Build
import java.util.Locale

object LocaleManager {
    private const val PREF_NAME = "locale_prefs"
    private const val KEY_LANGUAGE = "selected_language"
    
    const val LANGUAGE_FRENCH = "fr"
    const val LANGUAGE_ENGLISH = "en"
    
    /**
     * Vérifie et ajoute la colonne langue si elle n'existe pas
     */
    private fun ensureLanguageColumnExists(context: Context) {
        try {
            val db = SQLiteDb.getInstance(context).writableDatabase
            // Vérifier si la colonne existe
            val cursor = db.rawQuery("PRAGMA table_info(configuration)", null)
            var columnExists = false
            while (cursor.moveToNext()) {
                val columnIndex = cursor.getColumnIndex("name")
                if (columnIndex >= 0) {
                    val columnName = cursor.getString(columnIndex)
                    if (columnName == "langue") {
                        columnExists = true
                        break
                    }
                }
            }
            cursor.close()
            
            // Ajouter la colonne si elle n'existe pas
            if (!columnExists) {
                android.util.Log.d("LocaleManager", "Ajout de la colonne 'langue' à la table configuration")
                db.execSQL("ALTER TABLE configuration ADD COLUMN langue TEXT NOT NULL DEFAULT 'fr'")
            }
            // NE PAS fermer la base de données ici - elle est gérée par SQLiteDb.getInstance()
        } catch (e: Exception) {
            android.util.Log.e("LocaleManager", "Erreur lors de la vérification/création de la colonne langue", e)
        }
    }
    
    /**
     * Sauvegarde la langue sélectionnée
     */
    fun setLocale(context: Context, languageCode: String) {
        val prefs = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
        prefs.edit().putString(KEY_LANGUAGE, languageCode).apply()
        
        // Sauvegarder aussi dans la base de données
        try {
            ensureLanguageColumnExists(context)
            val db = SQLiteDb.getInstance(context).writableDatabase
            db.execSQL("UPDATE configuration SET langue = ?", arrayOf(languageCode))
        } catch (e: Exception) {
            android.util.Log.e("LocaleManager", "Erreur lors de la sauvegarde de la langue", e)
        }
    }
    
    /**
     * Récupère la langue sauvegardée (utilise uniquement SharedPreferences pour éviter ANR)
     */
    fun getLocale(context: Context): String {
        // Utiliser uniquement SharedPreferences pour éviter les accès DB sur le thread principal
        val prefs = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
        return prefs.getString(KEY_LANGUAGE, LANGUAGE_FRENCH) ?: LANGUAGE_FRENCH
    }
    
    /**
     * Récupère la langue depuis la base de données (à utiliser en arrière-plan uniquement)
     */
    fun getLocaleFromDatabase(context: Context): String? {
        try {
            ensureLanguageColumnExists(context)
            val db = SQLiteDb.getInstance(context).readableDatabase
            val cursor = db.rawQuery("SELECT langue FROM configuration LIMIT 1", null)
            if (cursor.moveToFirst()) {
                val langue = cursor.getString(0)
                cursor.close()
                if (!langue.isNullOrEmpty()) {
                    return langue
                }
            }
            cursor.close()
        } catch (e: Exception) {
            android.util.Log.e("LocaleManager", "Erreur lors de la lecture de la langue depuis DB", e)
        }
        return null
    }
    
    /**
     * Applique la langue à un contexte
     */
    fun applyLocale(context: Context, languageCode: String = ""): Context {
        val finalLanguageCode = if (languageCode.isEmpty()) {
            getLocale(context)
        } else {
            languageCode
        }
        
        val locale = Locale.forLanguageTag(finalLanguageCode)
        Locale.setDefault(locale)
        
        val resources: Resources = context.resources
        val configuration = Configuration(resources.configuration)
        
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            configuration.setLocale(locale)
            return context.createConfigurationContext(configuration)
        } else {
            @Suppress("DEPRECATION")
            configuration.locale = locale
            @Suppress("DEPRECATION")
            resources.updateConfiguration(configuration, resources.displayMetrics)
            return context
        }
    }
    
    /**
     * Obtient le nom de la langue pour l'affichage
     */
    fun getLanguageName(languageCode: String): String {
        return when (languageCode) {
            LANGUAGE_FRENCH -> "Français"
            LANGUAGE_ENGLISH -> "English"
            else -> "Français"
        }
    }
    
    /**
     * Liste des langues disponibles
     */
    fun getAvailableLanguages(): List<Pair<String, String>> {
        return listOf(
            LANGUAGE_FRENCH to "Français",
            LANGUAGE_ENGLISH to "English"
        )
    }

    fun applySavedLocale(context: Context) {
        applyLocale(context,
            context.getSharedPreferences(PREF_NAME, MODE_PRIVATE).getString(
                KEY_LANGUAGE,
                LANGUAGE_FRENCH
            ) ?: LANGUAGE_FRENCH
        )
    }
}
