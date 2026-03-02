package com.telipso.fripandroid

import android.app.Application
import android.content.Context
import android.content.Intent
import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
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

class EmployeLoginViewModel(application: Application) : AndroidViewModel(application) {
    var employes by mutableStateOf<List<InventaireApiService.Employe>>(emptyList())
    var selectedEmploye by mutableStateOf<InventaireApiService.Employe?>(null)
    var isLoading by mutableStateOf(false)
    var errorMessage by mutableStateOf<String?>(null)

    fun chargerEmployes() {
        viewModelScope.launch {
            isLoading = true
            errorMessage = null
            try {
                val result = withContext(Dispatchers.IO) {
                    InventaireApiService.getEmployes()
                }
                employes = result
            } catch (e: Exception) {
                errorMessage = "Erreur de connexion: ${e.message}"
            } finally {
                isLoading = false
            }
        }
    }
}

class EmployeLoginActivity : AppCompatActivity() {
    private val viewModel by viewModels<EmployeLoginViewModel>()

    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(LocaleManager.applyLocale(newBase))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // Charger l'URL du serveur API depuis la config
        val config = com.telipso.fripandroid.entities.Config()
        SQLiteDb.getInstance(this).readableDatabase.use { db ->
            config.loadConfig(db)
        }
        if (config.serveurSynchro.isNotBlank()) {
            InventaireApiService.setBaseUrl(config.serveurSynchro)
        }

        setContent {
            TelipsoBonTravailTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background
                ) {
                    EmployeLoginScreen(viewModel)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EmployeLoginScreen(viewModel: EmployeLoginViewModel) {
    val ctx = LocalContext.current

    LaunchedEffect(Unit) {
        viewModel.chargerEmployes()
    }

    var expanded by remember { mutableStateOf(false) }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {},
                colors = topAppBarColors(containerColor = Color.Transparent),
                actions = {
                    IconButton(onClick = {
                        val intent = Intent(ctx, ConfigActivity::class.java)
                        ctx.startActivity(intent)
                    }) {
                        Icon(Icons.Filled.Settings, contentDescription = "Configuration", tint = seed)
                    }
                }
            )
        }
    ) { innerPadding ->
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(innerPadding)
            .padding(horizontal = 32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center
    ) {
        Text(
            text = "Prise Inventaire",
            fontSize = 32.sp,
            fontWeight = FontWeight.Bold,
            textAlign = TextAlign.Center,
            color = seed,
            modifier = Modifier.fillMaxWidth()
        )

        Spacer(modifier = Modifier.height(8.dp))

        Text(
            text = "Gestion d'inventaire",
            fontSize = 16.sp,
            textAlign = TextAlign.Center,
            color = Color.Gray,
            modifier = Modifier.fillMaxWidth()
        )

        Spacer(modifier = Modifier.height(48.dp))

        if (viewModel.isLoading) {
            CircularProgressIndicator()
            Spacer(modifier = Modifier.height(16.dp))
            Text("Chargement des employés...")
        } else if (viewModel.errorMessage != null) {
            Text(
                text = viewModel.errorMessage ?: "",
                color = MaterialTheme.colorScheme.error,
                textAlign = TextAlign.Center
            )
            Spacer(modifier = Modifier.height(16.dp))
            Button(onClick = { viewModel.chargerEmployes() }) {
                Text("Réessayer")
            }
        } else {
            // Dropdown des employés
            ExposedDropdownMenuBox(
                expanded = expanded,
                onExpandedChange = { expanded = !expanded },
                modifier = Modifier.fillMaxWidth()
            ) {
                OutlinedTextField(
                    value = viewModel.selectedEmploye?.nom ?: "",
                    onValueChange = {},
                    readOnly = true,
                    label = { Text("Employé") },
                    trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
                    modifier = Modifier
                        .menuAnchor()
                        .fillMaxWidth()
                )
                ExposedDropdownMenu(
                    expanded = expanded,
                    onDismissRequest = { expanded = false }
                ) {
                    viewModel.employes.forEach { employe ->
                        DropdownMenuItem(
                            text = { Text(employe.nom ?: "Employé sans nom") },
                            onClick = {
                                viewModel.selectedEmploye = employe
                                expanded = false
                            }
                        )
                    }
                }
            }

            Spacer(modifier = Modifier.height(48.dp))

            // Bouton Connexion
            Button(
                onClick = {
                    viewModel.selectedEmploye?.let { employe ->
                        val intent = Intent(ctx, SecteurActivity::class.java)
                        intent.putExtra("employe_numero", employe.numero)
                        intent.putExtra("employe_nom", employe.nom)
                        ctx.startActivity(intent)
                    }
                },
                enabled = viewModel.selectedEmploye != null,
                colors = ButtonDefaults.buttonColors(containerColor = seed),
                modifier = Modifier
                    .fillMaxWidth()
                    .height(50.dp)
            ) {
                Text(
                    "Connexion",
                    color = Color.White,
                    fontSize = 18.sp
                )
            }
        }
    }
    } // Scaffold
}

