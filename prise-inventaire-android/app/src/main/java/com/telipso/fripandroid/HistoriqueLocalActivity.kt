package com.telipso.fripandroid

import android.app.Application
import android.content.Context
import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.CloudOff
import androidx.compose.material.icons.filled.Error
import androidx.compose.material.icons.filled.Sync
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults.topAppBarColors
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.AndroidViewModel
import com.telipso.fripandroid.offline.OfflineRepository
import com.telipso.fripandroid.offline.PendingScan
import com.telipso.fripandroid.offline.SyncStatus
import com.telipso.fripandroid.offline.SyncWorker
import com.telipso.fripandroid.ui.theme.TelipsoBonTravailTheme
import com.telipso.fripandroid.ui.theme.seed
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class HistoriqueLocalViewModel(application: Application) : AndroidViewModel(application) {
    private val repository = OfflineRepository(application)
    val pendingScans = repository.allPendingScans
    val pendingCount = repository.pendingCount
    var isOnline by mutableStateOf(repository.isOnline())

    fun refreshOnlineStatus() {
        isOnline = repository.isOnline()
    }

    fun syncNow() {
        SyncWorker.syncNow(getApplication())
    }
}

class HistoriqueLocalActivity : AppCompatActivity() {
    private val viewModel by viewModels<HistoriqueLocalViewModel>()

    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(LocaleManager.applyLocale(newBase))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        setContent {
            TelipsoBonTravailTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background
                ) {
                    HistoriqueLocalScreen(
                        viewModel = viewModel,
                        onBack = { finish() }
                    )
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HistoriqueLocalScreen(viewModel: HistoriqueLocalViewModel, onBack: () -> Unit) {
    val scans by viewModel.pendingScans.collectAsState(initial = emptyList())
    val pendingCount by viewModel.pendingCount.collectAsState(initial = 0)

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Historique local", color = Color.White) },
                colors = topAppBarColors(
                    containerColor = seed,
                    titleContentColor = Color.White,
                ),
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.Filled.ArrowBack, "Retour", tint = Color.White)
                    }
                },
                actions = {
                    IconButton(onClick = { viewModel.syncNow() }) {
                        Icon(Icons.Filled.Sync, "Synchroniser", tint = Color.White)
                    }
                }
            )
        }
    ) { innerPadding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding)
                .padding(16.dp)
        ) {
            // Status bar
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .background(
                        if (viewModel.isOnline) Color(0xFF4CAF50).copy(alpha = 0.1f)
                        else Color(0xFFFF9800).copy(alpha = 0.1f)
                    )
                    .padding(12.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Icon(
                    imageVector = if (viewModel.isOnline) Icons.Filled.CheckCircle else Icons.Filled.CloudOff,
                    contentDescription = null,
                    tint = if (viewModel.isOnline) Color(0xFF4CAF50) else Color(0xFFFF9800)
                )
                Spacer(modifier = Modifier.padding(8.dp))
                Column {
                    Text(
                        text = if (viewModel.isOnline) "En ligne" else "Hors ligne",
                        fontWeight = FontWeight.Bold
                    )
                    Text(
                        text = "$pendingCount scan(s) en attente de synchronisation",
                        fontSize = 12.sp,
                        color = Color.Gray
                    )
                }
            }

            Spacer(modifier = Modifier.height(16.dp))

            if (scans.isEmpty()) {
                Box(
                    modifier = Modifier.fillMaxSize(),
                    contentAlignment = Alignment.Center
                ) {
                    Text(
                        text = "Aucun scan local",
                        color = Color.Gray
                    )
                }
            } else {
                LazyColumn(
                    verticalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    items(scans) { scan ->
                        ScanCard(scan)
                    }
                }
            }
        }
    }
}

@Composable
fun ScanCard(scan: PendingScan) {
    val dateFormat = SimpleDateFormat("dd/MM/yyyy HH:mm", Locale.FRANCE)

    Card(
        modifier = Modifier.fillMaxWidth(),
        elevation = CardDefaults.cardElevation(defaultElevation = 2.dp)
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(12.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            // Status indicator
            Box(
                modifier = Modifier
                    .size(40.dp)
                    .background(
                        color = when (scan.status) {
                            SyncStatus.PENDING -> Color(0xFFFF9800)
                            SyncStatus.SYNCING -> Color(0xFF2196F3)
                            SyncStatus.SYNCED -> Color(0xFF4CAF50)
                            SyncStatus.FAILED -> Color(0xFFF44336)
                        },
                        shape = CircleShape
                    ),
                contentAlignment = Alignment.Center
            ) {
                Icon(
                    imageVector = when (scan.status) {
                        SyncStatus.PENDING -> Icons.Filled.CloudOff
                        SyncStatus.SYNCING -> Icons.Filled.Sync
                        SyncStatus.SYNCED -> Icons.Filled.CheckCircle
                        SyncStatus.FAILED -> Icons.Filled.Error
                    },
                    contentDescription = null,
                    tint = Color.White,
                    modifier = Modifier.size(24.dp)
                )
            }

            Spacer(modifier = Modifier.padding(8.dp))

            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = scan.numero,
                    fontWeight = FontWeight.Bold,
                    fontSize = 16.sp
                )
                Text(
                    text = "Qté: ${scan.quantite} | Secteur: ${scan.secteur}",
                    fontSize = 14.sp,
                    color = Color.Gray
                )
                Text(
                    text = "Employé: ${scan.employe}",
                    fontSize = 12.sp,
                    color = Color.Gray
                )
            }

            Column(horizontalAlignment = Alignment.End) {
                Text(
                    text = dateFormat.format(Date(scan.createdAt)),
                    fontSize = 12.sp,
                    color = Color.Gray
                )
                Text(
                    text = when (scan.status) {
                        SyncStatus.PENDING -> "En attente"
                        SyncStatus.SYNCING -> "Sync..."
                        SyncStatus.SYNCED -> "Synchronisé"
                        SyncStatus.FAILED -> "Échec"
                    },
                    fontSize = 12.sp,
                    fontWeight = FontWeight.Medium,
                    color = when (scan.status) {
                        SyncStatus.PENDING -> Color(0xFFFF9800)
                        SyncStatus.SYNCING -> Color(0xFF2196F3)
                        SyncStatus.SYNCED -> Color(0xFF4CAF50)
                        SyncStatus.FAILED -> Color(0xFFF44336)
                    }
                )
            }
        }
    }
}
