package com.telipso.fripandroid

import android.app.Application
import android.content.Context
import android.media.AudioManager
import android.media.ToneGenerator
import android.os.Bundle
import android.os.VibrationEffect
import android.os.Vibrator
import androidx.activity.compose.setContent
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
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
import com.telipso.fripandroid.ui.theme.TelipsoBonTravailTheme
import com.telipso.fripandroid.ui.theme.seed
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

enum class RelocalisationType(val label: String, val color: Color) {
    ARRIVAGE("Arrivage", Color(0xFF28A745)),
    TRANSFERT("Transfert", Color(0xFF007BFF)),
    SORTIE("Sortie", Color(0xFFDC3545))
}

class RelocalisationViewModel(application: Application) : AndroidViewModel(application) {
    var employeNumero by mutableStateOf("")
    var employeNom by mutableStateOf("")
    
    var selectedType by mutableStateOf(RelocalisationType.ARRIVAGE)
    var secteurs by mutableStateOf<List<InventaireApiService.Secteur>>(emptyList())
    var isLoadingSecteurs by mutableStateOf(false)
    
    var codeProduit by mutableStateOf("")
    var produitNom by mutableStateOf("")
    var secteurSource by mutableStateOf("")
    var secteurDestination by mutableStateOf("")
    var quantite by mutableStateOf("")
    var uniteMesure by mutableStateOf("unité")
    var motif by mutableStateOf("")
    
    var isLoading by mutableStateOf(false)
    var errorMessage by mutableStateOf<String?>(null)
    var successMessage by mutableStateOf<String?>(null)
    
    fun loadSecteurs() {
        viewModelScope.launch {
            isLoadingSecteurs = true
            try {
                val result = withContext(Dispatchers.IO) {
                    InventaireApiService.getSecteurs()
                }
                secteurs = result
            } catch (e: Exception) {
                errorMessage = "Erreur chargement secteurs: ${e.message}"
            } finally {
                isLoadingSecteurs = false
            }
        }
    }
    
    fun enregistrerMouvement() {
        if (codeProduit.isBlank()) {
            errorMessage = "Veuillez scanner ou saisir un code produit"
            return
        }
        
        val qte = quantite.toDoubleOrNull()
        if (qte == null || qte <= 0) {
            errorMessage = "Veuillez saisir une quantité valide"
            return
        }
        
        when (selectedType) {
            RelocalisationType.ARRIVAGE -> {
                if (secteurDestination.isBlank()) {
                    errorMessage = "Veuillez sélectionner un secteur de destination"
                    return
                }
            }
            RelocalisationType.TRANSFERT -> {
                if (secteurSource.isBlank() || secteurDestination.isBlank()) {
                    errorMessage = "Veuillez sélectionner les secteurs source et destination"
                    return
                }
                if (secteurSource == secteurDestination) {
                    errorMessage = "Les secteurs source et destination doivent être différents"
                    return
                }
            }
            RelocalisationType.SORTIE -> {
                if (secteurSource.isBlank()) {
                    errorMessage = "Veuillez sélectionner un secteur source"
                    return
                }
            }
        }
        
        viewModelScope.launch {
            isLoading = true
            errorMessage = null
            successMessage = null
            
            try {
                val response = withContext(Dispatchers.IO) {
                    InventaireApiService.enregistrerRelocalisation(
                        type = selectedType.name.lowercase(),
                        produitNumero = codeProduit.trim(),
                        produitNom = produitNom.ifBlank { null },
                        secteurSource = secteurSource.ifBlank { null },
                        secteurDestination = secteurDestination.ifBlank { null },
                        quantite = qte,
                        uniteMesure = uniteMesure.ifBlank { null },
                        motif = motif.ifBlank { null },
                        employe = employeNumero
                    )
                }
                
                if (response.success) {
                    successMessage = "${selectedType.label} enregistré: ${codeProduit.trim()}"
                    // Reset pour le prochain scan
                    codeProduit = ""
                    produitNom = ""
                    quantite = ""
                    motif = ""
                } else {
                    errorMessage = response.message
                }
            } catch (e: Exception) {
                errorMessage = "Erreur: ${e.message}"
            } finally {
                isLoading = false
            }
        }
    }
    
    fun reset() {
        codeProduit = ""
        produitNom = ""
        quantite = ""
        motif = ""
        errorMessage = null
        successMessage = null
    }
}

class RelocalisationActivity : AppCompatActivity() {
    private val viewModel by viewModels<RelocalisationViewModel>()
    
    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(LocaleManager.applyLocale(newBase))
    }
    
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        
        viewModel.employeNumero = intent.getStringExtra("employe_numero") ?: ""
        viewModel.employeNom = intent.getStringExtra("employe_nom") ?: ""
        
        viewModel.loadSecteurs()
        
        setContent {
            TelipsoBonTravailTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background
                ) {
                    RelocalisationScreen(viewModel)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RelocalisationScreen(viewModel: RelocalisationViewModel) {
    val ctx = LocalContext.current
    val produitFocusRequester = remember { FocusRequester() }
    
    // Feedback sonore sur erreur
    LaunchedEffect(viewModel.errorMessage) {
        if (viewModel.errorMessage != null) {
            try {
                val toneGen = ToneGenerator(AudioManager.STREAM_NOTIFICATION, 100)
                toneGen.startTone(ToneGenerator.TONE_CDMA_ABBR_ALERT, 300)
                val vibrator = ctx.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
                vibrator?.vibrate(VibrationEffect.createOneShot(200, VibrationEffect.DEFAULT_AMPLITUDE))
            } catch (_: Exception) {}
        }
    }
    
    // Focus sur produit après succès
    LaunchedEffect(viewModel.successMessage) {
        if (viewModel.successMessage != null) {
            try { produitFocusRequester.requestFocus() } catch (_: Exception) {}
        }
    }
    
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Relocalisation", color = Color.White) },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = seed,
                    titleContentColor = Color.White,
                ),
                navigationIcon = {
                    TextButton(onClick = { (ctx as? AppCompatActivity)?.finish() }) {
                        Text("← Retour", color = Color.White, fontSize = 14.sp)
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
                .verticalScroll(rememberScrollState()),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            // Type de mouvement
            Text(
                text = "Type de mouvement",
                fontSize = 16.sp,
                fontWeight = FontWeight.Medium,
                modifier = Modifier.fillMaxWidth()
            )
            Spacer(modifier = Modifier.height(8.dp))
            
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                RelocalisationType.values().forEach { type ->
                    Box(
                        modifier = Modifier
                            .weight(1f)
                            .background(
                                if (viewModel.selectedType == type) type.color else Color.LightGray,
                                RoundedCornerShape(8.dp)
                            )
                            .clickable { 
                                viewModel.selectedType = type
                                viewModel.secteurSource = ""
                                viewModel.secteurDestination = ""
                            }
                            .padding(12.dp),
                        contentAlignment = Alignment.Center
                    ) {
                        Text(
                            text = type.label,
                            color = Color.White,
                            fontWeight = FontWeight.Bold,
                            fontSize = 14.sp
                        )
                    }
                }
            }
            
            Spacer(modifier = Modifier.height(20.dp))
            
            // Message de succès
            if (viewModel.successMessage != null) {
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .background(Color(0xFFD4EDDA), RoundedCornerShape(8.dp))
                        .padding(12.dp)
                ) {
                    Text(
                        text = "✓ ${viewModel.successMessage}",
                        color = Color(0xFF155724),
                        fontSize = 14.sp,
                        fontWeight = FontWeight.Medium
                    )
                }
                Spacer(modifier = Modifier.height(12.dp))
            }
            
            // Message d'erreur
            if (viewModel.errorMessage != null) {
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .background(Color(0xFFF8D7DA), RoundedCornerShape(8.dp))
                        .padding(12.dp)
                ) {
                    Text(
                        text = "⚠ ${viewModel.errorMessage}",
                        color = Color(0xFF721C24),
                        fontSize = 14.sp
                    )
                }
                Spacer(modifier = Modifier.height(12.dp))
            }
            
            // Champ Produit
            Text(
                text = "Code produit *",
                fontSize = 14.sp,
                fontWeight = FontWeight.Medium,
                modifier = Modifier.fillMaxWidth()
            )
            Spacer(modifier = Modifier.height(4.dp))
            OutlinedTextField(
                value = viewModel.codeProduit,
                onValueChange = { 
                    viewModel.codeProduit = it
                    viewModel.errorMessage = null
                    viewModel.successMessage = null
                },
                placeholder = { Text("Scanner ou saisir le code") },
                singleLine = true,
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next),
                modifier = Modifier
                    .fillMaxWidth()
                    .focusRequester(produitFocusRequester)
            )
            
            Spacer(modifier = Modifier.height(12.dp))
            
            // Secteur Source (pour Transfert et Sortie)
            if (viewModel.selectedType == RelocalisationType.TRANSFERT || 
                viewModel.selectedType == RelocalisationType.SORTIE) {
                Text(
                    text = "Secteur source *",
                    fontSize = 14.sp,
                    fontWeight = FontWeight.Medium,
                    modifier = Modifier.fillMaxWidth()
                )
                Spacer(modifier = Modifier.height(4.dp))
                SecteurDropdown(
                    secteurs = viewModel.secteurs,
                    selectedValue = viewModel.secteurSource,
                    onValueChange = { viewModel.secteurSource = it },
                    isLoading = viewModel.isLoadingSecteurs
                )
                Spacer(modifier = Modifier.height(12.dp))
            }
            
            // Secteur Destination (pour Arrivage et Transfert)
            if (viewModel.selectedType == RelocalisationType.ARRIVAGE || 
                viewModel.selectedType == RelocalisationType.TRANSFERT) {
                Text(
                    text = "Secteur destination *",
                    fontSize = 14.sp,
                    fontWeight = FontWeight.Medium,
                    modifier = Modifier.fillMaxWidth()
                )
                Spacer(modifier = Modifier.height(4.dp))
                SecteurDropdown(
                    secteurs = viewModel.secteurs,
                    selectedValue = viewModel.secteurDestination,
                    onValueChange = { viewModel.secteurDestination = it },
                    isLoading = viewModel.isLoadingSecteurs
                )
                Spacer(modifier = Modifier.height(12.dp))
            }
            
            // Quantité
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(12.dp)
            ) {
                Column(modifier = Modifier.weight(1f)) {
                    Text(
                        text = "Quantité *",
                        fontSize = 14.sp,
                        fontWeight = FontWeight.Medium
                    )
                    Spacer(modifier = Modifier.height(4.dp))
                    OutlinedTextField(
                        value = viewModel.quantite,
                        onValueChange = { viewModel.quantite = it },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Decimal,
                            imeAction = ImeAction.Done
                        ),
                        keyboardActions = KeyboardActions(
                            onDone = { viewModel.enregistrerMouvement() }
                        ),
                        modifier = Modifier.fillMaxWidth()
                    )
                }
                Column(modifier = Modifier.weight(1f)) {
                    Text(
                        text = "Unité",
                        fontSize = 14.sp,
                        fontWeight = FontWeight.Medium
                    )
                    Spacer(modifier = Modifier.height(4.dp))
                    OutlinedTextField(
                        value = viewModel.uniteMesure,
                        onValueChange = { viewModel.uniteMesure = it },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth()
                    )
                }
            }
            
            Spacer(modifier = Modifier.height(12.dp))
            
            // Motif (optionnel)
            Text(
                text = "Motif (optionnel)",
                fontSize = 14.sp,
                fontWeight = FontWeight.Medium,
                modifier = Modifier.fillMaxWidth()
            )
            Spacer(modifier = Modifier.height(4.dp))
            OutlinedTextField(
                value = viewModel.motif,
                onValueChange = { viewModel.motif = it },
                placeholder = { Text("Ex: Livraison fournisseur") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth()
            )
            
            Spacer(modifier = Modifier.height(24.dp))
            
            // Bouton Enregistrer
            Button(
                onClick = { viewModel.enregistrerMouvement() },
                enabled = !viewModel.isLoading,
                colors = ButtonDefaults.buttonColors(containerColor = viewModel.selectedType.color),
                modifier = Modifier
                    .fillMaxWidth()
                    .height(56.dp)
            ) {
                if (viewModel.isLoading) {
                    CircularProgressIndicator(
                        color = Color.White,
                        modifier = Modifier.size(24.dp)
                    )
                } else {
                    Text(
                        "Enregistrer ${viewModel.selectedType.label}",
                        color = Color.White,
                        fontSize = 18.sp,
                        fontWeight = FontWeight.Bold
                    )
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SecteurDropdown(
    secteurs: List<InventaireApiService.Secteur>,
    selectedValue: String,
    onValueChange: (String) -> Unit,
    isLoading: Boolean
) {
    var expanded by remember { mutableStateOf(false) }
    
    ExposedDropdownMenuBox(
        expanded = expanded,
        onExpandedChange = { expanded = !expanded }
    ) {
        OutlinedTextField(
            value = selectedValue,
            onValueChange = {},
            readOnly = true,
            placeholder = { 
                Text(if (isLoading) "Chargement..." else "Sélectionner un secteur") 
            },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
            modifier = Modifier
                .fillMaxWidth()
                .menuAnchor()
        )
        
        ExposedDropdownMenu(
            expanded = expanded,
            onDismissRequest = { expanded = false }
        ) {
            secteurs.forEach { secteur ->
                DropdownMenuItem(
                    text = { Text(secteur.nom) },
                    onClick = {
                        onValueChange(secteur.code ?: secteur.nom)
                        expanded = false
                    }
                )
            }
        }
    }
}
