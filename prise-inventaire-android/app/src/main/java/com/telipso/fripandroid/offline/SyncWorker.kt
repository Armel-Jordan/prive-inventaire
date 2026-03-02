package com.telipso.fripandroid.offline

import android.content.Context
import android.util.Log
import androidx.work.*
import com.telipso.fripandroid.api.InventaireApiService
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.util.concurrent.TimeUnit

class SyncWorker(
    context: Context,
    workerParams: WorkerParameters
) : CoroutineWorker(context, workerParams) {

    private val repository = OfflineRepository(context)

    override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
        Log.d(TAG, "Starting sync worker...")

        if (!repository.isOnline()) {
            Log.d(TAG, "No network connection, retrying later")
            return@withContext Result.retry()
        }

        val pendingScans = repository.getPendingScans()
        Log.d(TAG, "Found ${pendingScans.size} pending scans to sync")

        var successCount = 0
        var failCount = 0

        for (scan in pendingScans) {
            try {
                repository.updateScanStatus(scan.id, SyncStatus.SYNCING)

                val response = InventaireApiService.enregistrerScan(
                    numero = scan.numero,
                    quantite = scan.quantite,
                    employe = scan.employe,
                    secteur = scan.secteur
                )

                if (response.success) {
                    repository.updateScanStatus(scan.id, SyncStatus.SYNCED)
                    successCount++
                    Log.d(TAG, "Synced scan ${scan.id}")
                } else {
                    repository.updateScanStatus(scan.id, SyncStatus.FAILED)
                    failCount++
                    Log.e(TAG, "Failed to sync scan ${scan.id}: ${response.message}")
                }
            } catch (e: Exception) {
                repository.updateScanStatus(scan.id, SyncStatus.FAILED)
                failCount++
                Log.e(TAG, "Error syncing scan ${scan.id}: ${e.message}")
            }
        }

        // Nettoyer les scans synchronisés
        repository.deleteSyncedScans()

        Log.d(TAG, "Sync completed: $successCount success, $failCount failed")

        if (failCount > 0) {
            Result.retry()
        } else {
            Result.success()
        }
    }

    companion object {
        private const val TAG = "SyncWorker"
        private const val WORK_NAME = "sync_pending_scans"

        fun schedule(context: Context) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()

            val syncRequest = PeriodicWorkRequestBuilder<SyncWorker>(
                15, TimeUnit.MINUTES
            )
                .setConstraints(constraints)
                .setBackoffCriteria(
                    BackoffPolicy.EXPONENTIAL,
                    1, TimeUnit.MINUTES
                )
                .build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                syncRequest
            )

            Log.d(TAG, "Sync worker scheduled")
        }

        fun syncNow(context: Context) {
            val constraints = Constraints.Builder()
                .setRequiredNetworkType(NetworkType.CONNECTED)
                .build()

            val syncRequest = OneTimeWorkRequestBuilder<SyncWorker>()
                .setConstraints(constraints)
                .build()

            WorkManager.getInstance(context).enqueue(syncRequest)
            Log.d(TAG, "Immediate sync requested")
        }

        fun cancel(context: Context) {
            WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME)
        }
    }
}
