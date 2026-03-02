package com.telipso.fripandroid

import android.app.Application
import android.content.Context
import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults.topAppBarColors
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.telipso.fripandroid.api.InventaireApiService
import com.telipso.fripandroid.ui.theme.PriseInventaireTheme
import com.telipso.fripandroid.ui.theme.seed
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.text.SimpleDateFormat
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.util.Date
import java.util.Locale

class HistoriqueViewModel(application: Application) : AndroidViewModel(application) {
    var employeNumero by mutableStateOf("")
    var secteur by mutableStateOf("")
    var scans by mutableStateOf<List<InventaireApiService.Scan>>(emptyList())
    var isLoading by mutableStateOf(false)
    var errorMessage by mutableStateOf<String?>(null)
    var successMessage by mutableStateOf<String?>(null)

    fun chargerScans() {
        viewModelScope.launch {
            isLoading = true
            errorMessage = null
            try {
                android.util.Log.d("Historique", "chargerScans employe=$employeNumero secteur=$secteur")
                val result = withContext(Dispatchers.IO) {
                    InventaireApiService.getHistorique(employeNumero, secteur)
                }
                android.util.Log.d("Historique", "chargerScans result: ${result.size} scans")
                scans = result
            } catch (e: Exception) {
                android.util.Log.e("Historique", "chargerScans error", e)
                errorMessage = "Erreur: ${e.message}"
            } finally {
                isLoading = false
            }
        }
    }

    fun modifierScan(scanId: Int, nouvelleQuantite: Double) {
        viewModelScope.launch {
            errorMessage = null
            successMessage = null
            try {
                android.util.Log.d("Historique", "modifierScan id=$scanId qte=$nouvelleQuantite")
                val result = withContext(Dispatchers.IO) {
                    InventaireApiService.modifierScan(scanId, nouvelleQuantite)
                }
                android.util.Log.d("Historique", "modifierScan result: success=${result.success} message=${result.message}")
                if (result.success) {
                    successMessage = result.message
                    chargerScans()
                } else {
                    errorMessage = result.message
                }
            } catch (e: Exception) {
                android.util.Log.e("Historique", "modifierScan error", e)
                errorMessage = "Erreur: ${e.message}"
            }
        }
    }

    fun supprimerScan(scanId: Int) {
        viewModelScope.launch {
            errorMessage = null
            successMessage = null
            try {
                android.util.Log.d("Historique", "supprimerScan id=$scanId")
                val result = withContext(Dispatchers.IO) {
                    InventaireApiService.supprimerScan(scanId)
                }
                android.util.Log.d("Historique", "supprimerScan result: success=${result.success} message=${result.message}")
                if (result.success) {
                    successMessage = result.message
                    chargerScans()
                } else {
                    errorMessage = result.message
                }
            } catch (e: Exception) {
                android.util.Log.e("Historique", "supprimerScan error", e)
                errorMessage = "Erreur: ${e.message}"
            }
        }
    }

}

class HistoriqueActivity : AppCompatActivity() {
    private val viewModel by viewModels<HistoriqueViewModel>()

    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(LocaleManager.applyLocale(newBase))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        viewModel.employeNumero = intent.getStringExtra("employe_numero") ?: ""
        viewModel.secteur = intent.getStringExtra("secteur") ?: ""

        setContent {
            PriseInventaireTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background
                ) {
                    HistoriqueScreen(viewModel)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HistoriqueScreen(viewModel: HistoriqueViewModel) {
    val ctx = LocalContext.current
    val dateFormat = remember { SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.getDefault()) }
    var scanToEdit by remember { mutableStateOf<InventaireApiService.Scan?>(null) }
    var scanToDelete by remember { mutableStateOf<InventaireApiService.Scan?>(null) }
    var editQuantity by remember { mutableStateOf("") }

    LaunchedEffect(Unit) {
        viewModel.chargerScans()
    }

    fun parseDate(dateString: String): Date {
        return try {
            val zonedDateTime = ZonedDateTime.parse(dateString, DateTimeFormatter.ISO_DATE_TIME)
            Date.from(zonedDateTime.toInstant())
        } catch (e: Exception) {
            Date()
        }
    }

    // Dialogue de modification
    if (scanToEdit != null) {
        AlertDialog(
            onDismissRequest = { scanToEdit = null },
            title = { Text("Modifier la quantité") },
            text = {
                Column {
                    Text("Produit: ${scanToEdit?.numero}", fontSize = 14.sp, modifier = Modifier.padding(bottom = 8.dp))
                    OutlinedTextField(
                        value = editQuantity,
                        onValueChange = { editQuantity = it },
                        label = { Text("Nouvelle quantité") },
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Decimal,
                            imeAction = ImeAction.Done
                        ),
                        singleLine = true
                    )
                }
            },
            confirmButton = {
                Button(
                    onClick = {
                        val qty = editQuantity.toDoubleOrNull()
                        if (qty != null && qty > 0) {
                            viewModel.modifierScan(scanToEdit!!.id, qty)
                            scanToEdit = null
                            editQuantity = ""
                        }
                    }
                ) {
                    Text("Modifier")
                }
            },
            dismissButton = {
                TextButton(onClick = { 
                    scanToEdit = null
                    editQuantity = ""
                }) {
                    Text("Annuler")
                }
            }
        )
    }

    // Dialogue de suppression
    if (scanToDelete != null) {
        AlertDialog(
            onDismissRequest = { scanToDelete = null },
            title = { Text("Confirmer la suppression") },
            text = {
                Text("Voulez-vous vraiment supprimer ce scan ?\n\nProduit: ${scanToDelete?.numero}\nQuantité: ${scanToDelete?.quantite} ${scanToDelete?.uniteMesure ?: "UN"}")
            },
            confirmButton = {
                Button(
                    onClick = {
                        viewModel.supprimerScan(scanToDelete!!.id)
                        scanToDelete = null
                    },
                    colors = ButtonDefaults.buttonColors(containerColor = Color.Red)
                ) {
                    Text("Supprimer")
                }
            },
            dismissButton = {
                TextButton(onClick = { scanToDelete = null }) {
                    Text("Annuler")
                }
            }
        )
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Historique — ${viewModel.secteur}", color = Color.White) },
                colors = topAppBarColors(
                    containerColor = seed,
                    titleContentColor = Color.White,
                    actionIconContentColor = Color.White,
                    navigationIconContentColor = Color.White,
                ),
                navigationIcon = {
                    IconButton(onClick = {
                        (ctx as? AppCompatActivity)?.finish()
                    }) {
                        Icon(Icons.Filled.Close, contentDescription = "Fermer", tint = Color.White)
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
            // Messages
            if (viewModel.successMessage != null) {
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .background(Color(0xFFD4EDDA), RoundedCornerShape(8.dp))
                        .padding(12.dp)
                ) {
                    Text(viewModel.successMessage ?: "", color = Color(0xFF155724), fontSize = 14.sp)
                }
                Spacer(modifier = Modifier.height(8.dp))
            }
            if (viewModel.errorMessage != null) {
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .background(Color(0xFFF8D7DA), RoundedCornerShape(8.dp))
                        .padding(12.dp)
                ) {
                    Text(viewModel.errorMessage ?: "", color = Color(0xFF721C24), fontSize = 14.sp)
                }
                Spacer(modifier = Modifier.height(8.dp))
            }

            if (viewModel.isLoading) {
                Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    androidx.compose.material3.CircularProgressIndicator()
                }
            } else if (viewModel.scans.isEmpty()) {
                Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Text(
                        "Aucun scan enregistré",
                        fontSize = 18.sp,
                        color = Color.Gray,
                        textAlign = TextAlign.Center
                    )
                }
            } else {
                Text(
                    "${viewModel.scans.size} scan(s)",
                    fontSize = 14.sp,
                    color = Color.Gray,
                    modifier = Modifier.padding(bottom = 8.dp)
                )

                LazyColumn(
                    verticalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    items(viewModel.scans, key = { it.id }) { scan ->
                        Card(
                            modifier = Modifier.fillMaxWidth(),
                            elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
                            shape = RoundedCornerShape(8.dp)
                        ) {
                            Column(
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .padding(12.dp)
                            ) {
                                Row(
                                    modifier = Modifier.fillMaxWidth(),
                                    horizontalArrangement = Arrangement.SpaceBetween,
                                    verticalAlignment = Alignment.CenterVertically
                                ) {
                                    Text(
                                        text = scan.numero,
                                        fontSize = 16.sp,
                                        fontWeight = FontWeight.Bold,
                                        color = seed,
                                        modifier = Modifier.weight(1f)
                                    )
                                    Row {
                                        IconButton(
                                            onClick = {
                                                scanToEdit = scan
                                                editQuantity = scan.quantite
                                            }
                                        ) {
                                            Icon(
                                                Icons.Filled.Edit,
                                                contentDescription = "Modifier",
                                                tint = seed
                                            )
                                        }
                                        IconButton(
                                            onClick = { scanToDelete = scan }
                                        ) {
                                            Icon(
                                                Icons.Filled.Delete,
                                                contentDescription = "Supprimer",
                                                tint = Color.Red
                                            )
                                        }
                                    }
                                }
                                Row(
                                    modifier = Modifier.fillMaxWidth(),
                                    horizontalArrangement = Arrangement.SpaceBetween
                                ) {
                                    Text(
                                        text = "Qté: ${scan.quantite} ${scan.uniteMesure ?: "UN"}",
                                        fontSize = 14.sp
                                    )
                                    Text(
                                        text = scan.type ?: "",
                                        fontSize = 12.sp,
                                        color = Color.Gray
                                    )
                                }
                                Text(
                                    text = dateFormat.format(parseDate(scan.dateSaisie)),
                                    fontSize = 12.sp,
                                    color = Color.Gray,
                                    modifier = Modifier.padding(top = 4.dp)
                                )
                            }
                        }
                    }
                }
            }
        }
    }

}
