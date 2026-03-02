package com.telipso.fripandroid

import android.app.Application

class PriseApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        // Appliquer le thème sauvegardé au démarrage de l'application
        ThemeManager.applySavedTheme(this)
    }
}
