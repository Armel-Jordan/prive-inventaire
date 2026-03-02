package com.telipso.fripandroid.offline

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "pending_scans")
data class PendingScan(
    @PrimaryKey(autoGenerate = true)
    val id: Long = 0,
    val numero: String,
    val type: String,
    val quantite: Double,
    val uniteMesure: String,
    val employe: String,
    val secteur: String,
    val createdAt: Long = System.currentTimeMillis(),
    val syncStatus: SyncStatus = SyncStatus.PENDING
)

enum class SyncStatus {
    PENDING,
    SYNCING,
    SYNCED,
    FAILED
}
