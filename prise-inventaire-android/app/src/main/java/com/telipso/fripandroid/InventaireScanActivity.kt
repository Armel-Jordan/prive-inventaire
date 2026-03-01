package com.telipso.fripandroid

import android.app.Application
import android.content.Context
import android.content.Intent
import android.media.ToneGenerator
import android.media.AudioManager
import android.os.Bundle
import android.os.VibrationEffect
import android.os.Vibrator
import androidx.activity.compose.setContent
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
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

data class ProduitValide(
    val numero: String,
    val description: String,
    val uniteMesure: String,
    val type: String // MP ou PF
)

class InventaireScanViewModel(application: Application) : AndroidViewModel(application) {
    var employeNumero by mutableStateOf("")
    var employeNom by mutableStateOf("")
    var secteur by mutableStateOf("")

    var codeProduit by mutableStateOf("")
    var isLoading by mutableStateOf(false)
    var errorMessage by mutableStateOf<String?>(null)
    var produitNotFound by mutableStateOf(false)

    var produitValide by mutableStateOf<ProduitValide?>(null)
    var quantite by mutableStateOf("")

    var isSaving by mutableStateOf(false)
    var successMessage by mutableStateOf<String?>(null)

    fun rechercherProduit() {
        if (codeProduit.isBlank()) return

        viewModelScope.launch {
            isLoading = true
            errorMessage = null
            produitNotFound = false
            produitValide = null
            quantite = ""
            successMessage = null

            try {
                val response = withContext(Dispatchers.IO) {
                    InventaireApiService.validerProduit(codeProduit.trim())
                }

                if (response.valide) {
                    produitValide = ProduitValide(
                        numero = response.numero ?: codeProduit.trim(),
                        description = response.description ?: "",
                        uniteMesure = response.unite_mesure ?: "",
                        type = response.type ?: ""
                    )
                } else {
                    produitNotFound = true
                    errorMessage = response.message ?: "Produit introuvable"
                }
            } catch (e: Exception) {
                errorMessage = "Erreur de connexion: ${e.message}"
            } finally {
                isLoading = false
            }
        }
    }

    fun confirmerQuantite() {
        val produit = produitValide ?: return
        val qte = quantite.toDoubleOrNull() ?: return

        viewModelScope.launch {
            isSaving = true
            errorMessage = null
            successMessage = null

            try {
                val response = withContext(Dispatchers.IO) {
                    InventaireApiService.enregistrerScan(
                        numero = produit.numero,
                        quantite = qte,
                        employe = employeNumero,
                        secteur = secteur,
                        scanneur = android.os.Build.MODEL
                    )
                }

                if (response.success) {
                    successMessage = "Quantité enregistrée pour ${produit.numero}"
                    // Réinitialiser pour le prochain scan
                    codeProduit = ""
                    produitValide = null
                    quantite = ""
                } else {
                    errorMessage = response.message
                }
            } catch (e: Exception) {
                errorMessage = "Erreur de connexion: ${e.message}"
            } finally {
                isSaving = false
            }
        }
    }

    fun reset() {
        codeProduit = ""
        produitValide = null
        quantite = ""
        errorMessage = null
        produitNotFound = false
        successMessage = null
    }
}

class InventaireScanActivity : AppCompatActivity() {
    private val viewModel by viewModels<InventaireScanViewModel>()

    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(LocaleManager.applyLocale(newBase))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        viewModel.employeNumero = intent.getStringExtra("employe_numero") ?: ""
        viewModel.employeNom = intent.getStringExtra("employe_nom") ?: ""
        viewModel.secteur = intent.getStringExtra("secteur") ?: ""

        setContent {
            TelipsoBonTravailTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background
                ) {
                    InventaireScanScreen(viewModel)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun InventaireScanScreen(viewModel: InventaireScanViewModel) {
    val ctx = LocalContext.current
    val produitFocusRequester = remember { FocusRequester() }
    val quantiteFocusRequester = remember { FocusRequester() }

    // Feedback sonore et vibration si produit introuvable
    LaunchedEffect(viewModel.produitNotFound) {
        if (viewModel.produitNotFound) {
            try {
                // Son d'erreur
                val toneGen = ToneGenerator(AudioManager.STREAM_NOTIFICATION, 100)
                toneGen.startTone(ToneGenerator.TONE_CDMA_ABBR_ALERT, 500)

                // Vibration
                val vibrator = ctx.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
                vibrator?.vibrate(VibrationEffect.createOneShot(300, VibrationEffect.DEFAULT_AMPLITUDE))
            } catch (_: Exception) {}
        }
    }

    // Focus sur le champ quantité quand un produit est validé
    LaunchedEffect(viewModel.produitValide) {
        if (viewModel.produitValide != null) {
            try { quantiteFocusRequester.requestFocus() } catch (_: Exception) {}
        }
    }

    // Focus sur le champ produit après un succès
    LaunchedEffect(viewModel.successMessage) {
        if (viewModel.successMessage != null) {
            try { produitFocusRequester.requestFocus() } catch (_: Exception) {}
        }
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
                        Text("Fermer le secteur", color = Color.White, fontSize = 14.sp)
                    }
                },
                actions = {
                    TextButton(onClick = {
                        val intent = Intent(ctx, HistoriqueActivity::class.java)
                        intent.putExtra("employe_numero", viewModel.employeNumero)
                        intent.putExtra("secteur", viewModel.secteur)
                        ctx.startActivity(intent)
                    }) {
                        Text("Historique", color = Color.White, fontSize = 14.sp)
                    }
                }
            )
        }
    ) { innerPadding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding)
                .padding(24.dp)
                .verticalScroll(rememberScrollState()),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Text(
                text = "Secteur ${viewModel.secteur}",
                fontSize = 24.sp,
                fontWeight = FontWeight.Bold,
                textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth()
            )

            Spacer(modifier = Modifier.height(24.dp))

            // Message de succès
            if (viewModel.successMessage != null) {
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .background(Color(0xFFD4EDDA), RoundedCornerShape(8.dp))
                        .padding(12.dp)
                ) {
                    Text(
                        text = viewModel.successMessage ?: "",
                        color = Color(0xFF155724),
                        fontSize = 14.sp
                    )
                }
                Spacer(modifier = Modifier.height(16.dp))
            }

            // Champ Produit
            Text(
                text = "Produit :",
                fontSize = 16.sp,
                fontWeight = FontWeight.Medium,
                modifier = Modifier.fillMaxWidth()
            )
            Spacer(modifier = Modifier.height(4.dp))

            OutlinedTextField(
                value = viewModel.codeProduit,
                onValueChange = {
                    viewModel.codeProduit = it
                    viewModel.produitNotFound = false
                    viewModel.errorMessage = null
                    viewModel.successMessage = null
                },
                placeholder = { Text("Scanner ou saisir le code") },
                singleLine = true,
                isError = viewModel.produitNotFound,
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                keyboardActions = KeyboardActions(
                    onSearch = { viewModel.rechercherProduit() }
                ),
                modifier = Modifier
                    .fillMaxWidth()
                    .focusRequester(produitFocusRequester)
            )

            // Erreur produit introuvable
            if (viewModel.produitNotFound) {
                Spacer(modifier = Modifier.height(8.dp))
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .background(Color(0xFFF8D7DA), RoundedCornerShape(8.dp))
                        .border(1.dp, Color(0xFFF5C6CB), RoundedCornerShape(8.dp))
                        .padding(12.dp)
                ) {
                    Text(
                        text = "⚠ Produit introuvable : ${viewModel.codeProduit}",
                        color = Color(0xFF721C24),
                        fontSize = 16.sp,
                        fontWeight = FontWeight.Bold
                    )
                }
            }

            // Erreur générale
            if (viewModel.errorMessage != null && !viewModel.produitNotFound) {
                Spacer(modifier = Modifier.height(8.dp))
                Text(
                    text = viewModel.errorMessage ?: "",
                    color = MaterialTheme.colorScheme.error,
                    fontSize = 14.sp
                )
            }

            // Loading
            if (viewModel.isLoading) {
                Spacer(modifier = Modifier.height(16.dp))
                CircularProgressIndicator()
            }

            // Produit validé — afficher description et saisie quantité
            if (viewModel.produitValide != null) {
                val produit = viewModel.produitValide!!

                Spacer(modifier = Modifier.height(16.dp))

                // Description du produit
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .background(Color(0xFFE8F4FD), RoundedCornerShape(8.dp))
                        .border(1.dp, Color(0xFFB8DAFF), RoundedCornerShape(8.dp))
                        .padding(12.dp)
                ) {
                    Text(
                        text = produit.description,
                        fontSize = 16.sp,
                        color = Color(0xFF004085)
                    )
                }

                Spacer(modifier = Modifier.height(24.dp))

                // Saisie de la quantité
                Text(
                    text = "Quantité (${produit.uniteMesure}) :",
                    fontSize = 16.sp,
                    fontWeight = FontWeight.Medium,
                    modifier = Modifier.fillMaxWidth()
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
                        onDone = {
                            if (viewModel.quantite.toDoubleOrNull() != null) {
                                viewModel.confirmerQuantite()
                            }
                        }
                    ),
                    modifier = Modifier
                        .fillMaxWidth()
                        .focusRequester(quantiteFocusRequester)
                )

                Spacer(modifier = Modifier.height(24.dp))

                // Bouton Confirmer
                Button(
                    onClick = { viewModel.confirmerQuantite() },
                    enabled = viewModel.quantite.toDoubleOrNull() != null && !viewModel.isSaving,
                    colors = ButtonDefaults.buttonColors(containerColor = seed),
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(50.dp)
                ) {
                    if (viewModel.isSaving) {
                        CircularProgressIndicator(
                            color = Color.White,
                            modifier = Modifier.height(24.dp).width(24.dp)
                        )
                    } else {
                        Text(
                            "Confirmer",
                            color = Color.White,
                            fontSize = 18.sp
                        )
                    }
                }
            }

            // Si pas de produit validé et pas en chargement, afficher bouton rechercher
            if (viewModel.produitValide == null && !viewModel.isLoading && viewModel.codeProduit.isNotBlank()) {
                Spacer(modifier = Modifier.height(16.dp))
                Button(
                    onClick = { viewModel.rechercherProduit() },
                    colors = ButtonDefaults.buttonColors(containerColor = seed),
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(50.dp)
                ) {
                    Text("Rechercher", color = Color.White, fontSize = 18.sp)
                }
            }
        }
    }
}
