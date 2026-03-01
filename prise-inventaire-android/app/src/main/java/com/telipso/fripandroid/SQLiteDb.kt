package com.telipso.fripandroid

import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper

class SQLiteDb(context: Context) : SQLiteOpenHelper(context, DATABASE_NAME, null, DATABASE_VERSION) {

    override fun onCreate(db: SQLiteDatabase) {
        this.setWriteAheadLoggingEnabled(true)
        db.execSQL("PRAGMA encoding = \"UTF-8\";")
        onUpgrade(db, 0, DATABASE_VERSION)
    }

    override fun onConfigure(db: SQLiteDatabase) {
        super.onConfigure(db)
        this.setWriteAheadLoggingEnabled(true)
        db.execSQL("PRAGMA encoding = \"UTF-8\";")
        db.enableWriteAheadLogging()
    }


    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        android.util.Log.d("SQLiteDb", "onUpgrade: oldVersion=$oldVersion, newVersion=$newVersion")
        // Drop all tables except for a few utility tables
        db.rawQuery("SELECT name FROM sqlite_master WHERE type = 'table'", null).use { cursor ->
            while (cursor.moveToNext()) {
                val tableName = cursor.getString(0)
                if (tableName != "android_metadata" && tableName != "sqlite_sequence") {
                    android.util.Log.d("SQLiteDb", "Dropping table: $tableName")
                    db.execSQL("DROP TABLE IF EXISTS $tableName")
                }
            }
        }

        db.execSQL(
            """
            CREATE TABLE configuration (  
                adresse_serveur_sync  TEXT not null default 'http://10.0.2.2:8000/api',  
                langue TEXT not null default 'fr'
            )
            """.trimIndent()
        )
        db.execSQL("INSERT INTO configuration (adresse_serveur_sync) VALUES ('http://10.0.2.2:8000/api')")

        // Table employe - synchronisée depuis Oracle GESMAN2.EMPLOYE
        db.execSQL(
            """
            CREATE TABLE employe (
                numero TEXT PRIMARY KEY NOT NULL,
                nom TEXT,
                adresse1 TEXT,
                adresse2 TEXT,
                ville TEXT,
                cp TEXT,
                tel TEXT,
                date_creation INTEGER,
                date_modif INTEGER,
                tmp_simple REAL,
                tmp_demi REAL,
                tmp_double REAL,
                note TEXT,
                synced INTEGER DEFAULT 0,
                created_at INTEGER DEFAULT (cast(strftime('%s', 'now') as int)),
                updated_at INTEGER DEFAULT (cast(strftime('%s', 'now') as int))
            )
            """.trimIndent()
        )

        db.execSQL("CREATE INDEX IF NOT EXISTS emp_nom ON employe(nom)")
        db.execSQL("CREATE INDEX IF NOT EXISTS emp_synced ON employe(synced)")

        // Table produit - synchronisée depuis Oracle V_PRODUITS_MOBILES
        db.execSQL(
            """
            CREATE TABLE produit (
                numero TEXT PRIMARY KEY NOT NULL,
                description TEXT,
                mesure TEXT,
                type TEXT,
                synced INTEGER DEFAULT 0,
                created_at INTEGER DEFAULT (cast(strftime('%s', 'now') as int)),
                updated_at INTEGER DEFAULT (cast(strftime('%s', 'now') as int))
            )
            """.trimIndent()
        )

        db.execSQL("CREATE INDEX IF NOT EXISTS prod_numero ON produit(numero)")
        db.execSQL("CREATE INDEX IF NOT EXISTS prod_type ON produit(type)")
        db.execSQL("CREATE INDEX IF NOT EXISTS prod_synced ON produit(synced)")
    }

    override fun onDowngrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        onUpgrade(db, oldVersion, newVersion)
    }

    override fun close() {
        super.close()
    }

    companion object {
        // If you change the database schema, you must increment the database version.
        const val DATABASE_VERSION = 26
        const val DATABASE_NAME = "Agrimetal.db"

        private var mInstance: SQLiteDb? = null

        fun getInstance(ctx: Context): SQLiteDb {
            if (mInstance == null) {
                mInstance = SQLiteDb(ctx.applicationContext)
            }
            return mInstance!!
        }

        fun clearInstance() {
            mInstance = null
        }
    }
}
