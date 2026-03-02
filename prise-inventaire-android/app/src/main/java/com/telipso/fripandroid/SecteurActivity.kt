package com.telipso.fripandroid

import android.app.Activity
import android.app.Application
import android.content.Context
import android.content.Intent
import android.os.Bundle
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.QrCodeScanner
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.telipso.fripandroid.api.InventaireApiService
import com.telipso.fripandroid.ui.theme.TelipsoBonTravailTheme
import com.telipso.fripandroid.ui.theme.seed
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class SecteurViewModel(application: Application) : AndroidViewModel(application) {
    var secteurs by mutableStateOf<List<InventaireApiService.Secteur>>(emptyList())
    var selectedSecteur by mutableStateOf<InventaireApiService.Secteur?>(null)
    var isLoading by mutableStateOf(false)
    var errorMessage by mutableStateOf<String?>(null)

    fun chargerSecteurs() {
        viewModelScope.launch {
            isLoading = true
            errorMessage = null
            try {
                val result = withContext(Dispatchers.IO) {
                    InventaireApiService.getSecteurs()
                }
                secteurs = result.sortedBy { it.nom }
            } catch (e: Exception) {
                errorMessage = "Erreur de connexion: ${e.message}"
            } finally {
                isLoading = false
            }
        }
    }
}

class SecteurActivity : AppCompatActivity() {
    private var employeNumero: String = ""
    private var employeNom: String = ""
    private val viewModel by viewModels<SecteurViewModel>()

    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(LocaleManager.applyLocale(newBase))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        employeNumero = intent.getStringExtra("employe_numero") ?: ""
        employeNom = intent.getStringExtra("employe_nom") ?: ""

        setContent {
            TelipsoBonTravailTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background
                ) {
                    SecteurScreen(employeNumero, employeNom, viewModel)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SecteurScreen(employeNumero: String, employeNom: String, viewModel: SecteurViewModel) {
    val ctx = LocalContext.current
    var expanded by remember { mutableStateOf(false) }

    // Scanner QR code
    val scanLauncher = rememberLauncherForActivityResult(ScanContract()) { result ->
        result.contents?.let { scannedCode ->
            // Chercher le secteur correspondant au code scanné
            val secteur = viewModel.secteurs.find { 
                it.nom.equals(scannedCode, ignoreCase = true) || 
                it.code?.equals(scannedCode, ignoreCase = true) == true 
            }
            if (secteur != null) {
                viewModel.selectedSecteur = secteur
            }
        }
    }

    LaunchedEffect(Unit) {
        viewModel.chargerSecteurs()
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {},
                colors = topAppBarColors(
                    containerColor = seed,
                    titleContentColor = Color.White,
                    actionIconContentColor = Color.White,
                ),
                navigationIcon = {
                    TextButton(onClick = {
                        (ctx as? AppCompatActivity)?.finish()
                    }) {
                        Text("Quitter", color = Color.White, fontSize = 16.sp)
                    }
                }
            )
        }
    ) { innerPadding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding)
                .padding(32.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center
        ) {
            Text(
                text = "Inventaire",
                fontSize = 28.sp,
                fontWeight = FontWeight.Bold,
                textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth()
            )

            Spacer(modifier = Modifier.height(48.dp))

            Text(
                text = "Secteur :",
                fontSize = 18.sp,
                modifier = Modifier.fillMaxWidth()
            )

            Spacer(modifier = Modifier.height(8.dp))

            if (viewModel.isLoading) {
                CircularProgressIndicator()
                Spacer(modifier = Modifier.height(8.dp))
                Text("Chargement des secteurs...")
            } else if (viewModel.errorMessage != null) {
                Text(
                    text = viewModel.errorMessage ?: "",
                    color = MaterialTheme.colorScheme.error,
                    textAlign = TextAlign.Center
                )
                Spacer(modifier = Modifier.height(16.dp))
                Button(onClick = { viewModel.chargerSecteurs() }) {
                    Text("Réessayer")
                }
            } else {
                // Dropdown des secteurs avec bouton scan QR
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    ExposedDropdownMenuBox(
                        expanded = expanded,
                        onExpandedChange = { expanded = !expanded },
                        modifier = Modifier.weight(1f)
                    ) {
                        OutlinedTextField(
                            value = viewModel.selectedSecteur?.nom ?: "",
                            onValueChange = {},
                            readOnly = true,
                            label = { Text("Sélectionner un secteur") },
                            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
                            modifier = Modifier
                                .menuAnchor()
                                .fillMaxWidth()
                        )
                        ExposedDropdownMenu(
                            expanded = expanded,
                            onDismissRequest = { expanded = false }
                        ) {
                            viewModel.secteurs.forEach { secteur ->
                                DropdownMenuItem(
                                    text = { Text(secteur.nom) },
                                    onClick = {
                                        viewModel.selectedSecteur = secteur
                                        expanded = false
                                    }
                                )
                            }
                        }
                    }

                    Spacer(modifier = Modifier.width(8.dp))

                    // Bouton Scanner QR
                    IconButton(
                        onClick = {
                            val options = ScanOptions()
                            options.setDesiredBarcodeFormats(ScanOptions.QR_CODE)
                            options.setPrompt("Scanner le QR code du secteur")
                            options.setBeepEnabled(true)
                            options.setOrientationLocked(false)
                            scanLauncher.launch(options)
                        }
                    ) {
                        Icon(
                            imageVector = Icons.Filled.QrCodeScanner,
                            contentDescription = "Scanner QR",
                            tint = seed
                        )
                    }
                }
            }

            Spacer(modifier = Modifier.height(48.dp))

            Button(
                onClick = {
                    viewModel.selectedSecteur?.let { secteur ->
                        val intent = Intent(ctx, InventaireScanActivity::class.java)
                        intent.putExtra("employe_numero", employeNumero)
                        intent.putExtra("employe_nom", employeNom)
                        intent.putExtra("secteur", secteur.nom)
                        ctx.startActivity(intent)
                    }
                },
                enabled = viewModel.selectedSecteur != null,
                colors = ButtonDefaults.buttonColors(containerColor = seed),
                modifier = Modifier
                    .fillMaxWidth()
                    .height(50.dp)
            ) {
                Text(
                    "Confirmer",
                    color = Color.White,
                    fontSize = 18.sp
                )
            }

            Spacer(modifier = Modifier.height(24.dp))

            // Bouton Relocalisation
            Button(
                onClick = {
                    val intent = Intent(ctx, RelocalisationActivity::class.java)
                    intent.putExtra("employe_numero", employeNumero)
                    intent.putExtra("employe_nom", employeNom)
                    ctx.startActivity(intent)
                },
                colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF007BFF)),
                modifier = Modifier
                    .fillMaxWidth()
                    .height(50.dp)
            ) {
                Text(
                    "Relocalisation",
                    color = Color.White,
                    fontSize = 18.sp
                )
            }
        }
    }
}
