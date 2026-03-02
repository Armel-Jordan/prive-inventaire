package com.telipso.fripandroid.offline

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import kotlinx.coroutines.flow.Flow

class OfflineRepository(context: Context) {
    private val database = AppDatabase.getDatabase(context)
    private val pendingScanDao = database.pendingScanDao()
    private val connectivityManager = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager

    val allPendingScans: Flow<List<PendingScan>> = pendingScanDao.getAllPendingScans()
    val pendingCount: Flow<Int> = pendingScanDao.countByStatus(SyncStatus.PENDING)

    fun isOnline(): Boolean {
        val network = connectivityManager.activeNetwork ?: return false
        val capabilities = connectivityManager.getNetworkCapabilities(network) ?: return false
        return capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    suspend fun saveScanOffline(
        numero: String,
        type: String,
        quantite: Double,
        uniteMesure: String,
        employe: String,
        secteur: String
    ): Long {
        val scan = PendingScan(
            numero = numero,
            type = type,
            quantite = quantite,
            uniteMesure = uniteMesure,
            employe = employe,
            secteur = secteur
        )
        return pendingScanDao.insert(scan)
    }

    suspend fun getPendingScans(): List<PendingScan> {
        return pendingScanDao.getScansByStatus(SyncStatus.PENDING)
    }

    suspend fun getFailedScans(): List<PendingScan> {
        return pendingScanDao.getScansByStatus(SyncStatus.FAILED)
    }

    suspend fun updateScanStatus(id: Long, status: SyncStatus) {
        pendingScanDao.updateStatus(id, status)
    }

    suspend fun deleteSyncedScans() {
        pendingScanDao.deleteByStatus(SyncStatus.SYNCED)
    }

    suspend fun deleteScan(id: Long) {
        pendingScanDao.deleteById(id)
    }
}
