package com.telipso.fripandroid.entities

import android.annotation.SuppressLint
import android.database.sqlite.SQLiteDatabase

/**
 * Contient la configuration de l'application.
 *
 * loadConfig doit être appelé pour que les données soit chargés
 */
class Config {
    var serveurSynchro = ""

    @SuppressLint("Range")
    fun loadConfig(db: SQLiteDatabase) : Config {
        db.rawQuery("SELECT adresse_serveur_sync FROM configuration LIMIT 1", emptyArray()).use {
            if (it.moveToNext()) {
                val idx = it.getColumnIndex("adresse_serveur_sync")
                if (idx >= 0) serveurSynchro = it.getString(idx) ?: ""
            }
        }
        return this
    }
}