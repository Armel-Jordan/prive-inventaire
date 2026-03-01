package com.telipso.fripandroid

import android.annotation.SuppressLint
import android.content.ContentValues
import android.content.Context
import android.content.Intent
import android.database.sqlite.SQLiteDatabaseLockedException
import android.os.Bundle
import android.util.Log
import android.widget.ArrayAdapter
import android.widget.AutoCompleteTextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.telipso.fripandroid.BuildConfig
import com.telipso.fripandroid.databinding.ActivityConfigBinding
import com.telipso.fripandroid.entities.Config


class ConfigActivity : AppCompatActivity() {
    private lateinit var b: ActivityConfigBinding

    val config = Config()

    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(LocaleManager.applyLocale(newBase))
    }

    @SuppressLint("SetTextI18n")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        LocaleManager.applyLocale(this)
        b = ActivityConfigBinding.inflate(layoutInflater)
        setContentView(b.root)

        setupLanguageSelector()

        b.buttonConfig.setOnClickListener {
            enregistrerConfig()
        }

        b.textViewVersion.text = getString(
            R.string.config_version,
            BuildConfig.VERSION_NAME,
            BuildConfig.VERSION_CODE.toString()
        )

        b.topAppBar.setNavigationOnClickListener {
            finish()
        }

        SQLiteDb.getInstance(this).readableDatabase.use { db ->
            config.loadConfig(db)
        }
        b.configServeur.setText(config.serveurSynchro.toString())
    }

    private fun setupLanguageSelector() {
        val languages = LocaleManager.getAvailableLanguages()
        val languageNames = languages.map { it.second }
        val adapter = ArrayAdapter(this, android.R.layout.simple_dropdown_item_1line, languageNames)
        
        val languageDropdown = b.configLangue as? AutoCompleteTextView
        languageDropdown?.setAdapter(adapter)
        
        val currentLanguage = LocaleManager.getLocale(this)
        val currentIndex = languages.indexOfFirst { it.first == currentLanguage }
        if (currentIndex >= 0 && currentIndex < languageNames.size) {
            val languageName = languageNames[currentIndex]
            if (languageName != null) {
                languageDropdown?.setText(languageName, false)
            }
        }
        
        languageDropdown?.setOnItemClickListener { _, _, position, _ ->
            val selectedLanguage = languages[position].first
            if (selectedLanguage != currentLanguage) {
                try {
                    LocaleManager.setLocale(this, selectedLanguage)
                    val intent = Intent(this, ActivityMain::class.java)
                    intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
                    startActivity(intent)
                    finish()
                } catch (e: Exception) {
                    Log.e("ConfigActivity", e.message, e)
                }
            }
        }
    }

    private fun enregistrerConfig() {
        try {
            SQLiteDb.getInstance(this).writableDatabase.use { db ->
                db.update("configuration", ContentValues().apply {
                    put("adresse_serveur_sync", b.configServeur.text.toString())
                }, "", emptyArray())
            }
            finish()
        } catch (e: SQLiteDatabaseLockedException) {
            Toast.makeText(this, "Base de données verrouillée", Toast.LENGTH_SHORT).show()
        } catch (e: Exception) {
            Log.e("ConfigActivity", e.message, e)
        }
    }
}
