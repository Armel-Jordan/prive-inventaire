package com.telipso.fripandroid.offline

import androidx.room.*
import kotlinx.coroutines.flow.Flow

@Dao
interface PendingScanDao {
    @Query("SELECT * FROM pending_scans ORDER BY createdAt DESC")
    fun getAllPendingScans(): Flow<List<PendingScan>>

    @Query("SELECT * FROM pending_scans WHERE syncStatus = :status")
    suspend fun getScansByStatus(status: SyncStatus): List<PendingScan>

    @Query("SELECT COUNT(*) FROM pending_scans WHERE syncStatus = :status")
    fun countByStatus(status: SyncStatus): Flow<Int>

    @Insert
    suspend fun insert(scan: PendingScan): Long

    @Update
    suspend fun update(scan: PendingScan)

    @Delete
    suspend fun delete(scan: PendingScan)

    @Query("DELETE FROM pending_scans WHERE id = :id")
    suspend fun deleteById(id: Long)

    @Query("UPDATE pending_scans SET syncStatus = :status WHERE id = :id")
    suspend fun updateStatus(id: Long, status: SyncStatus)

    @Query("DELETE FROM pending_scans WHERE syncStatus = :status")
    suspend fun deleteByStatus(status: SyncStatus)
}
