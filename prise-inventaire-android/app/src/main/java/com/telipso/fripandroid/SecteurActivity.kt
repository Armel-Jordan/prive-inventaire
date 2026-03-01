package com.telipso.fripandroid

import android.content.Context
import android.content.Intent
import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.appcompat.app.AppCompatActivity
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
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
import com.telipso.fripandroid.ui.theme.TelipsoBonTravailTheme
import com.telipso.fripandroid.ui.theme.seed

class SecteurActivity : AppCompatActivity() {
    private var employeNumero: String = ""
    private var employeNom: String = ""

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
                    SecteurScreen(employeNumero, employeNom)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SecteurScreen(employeNumero: String, employeNom: String) {
    val ctx = LocalContext.current
    var secteur by remember { mutableStateOf("") }
    var erreur by remember { mutableStateOf<String?>(null) }

    // Regex pour valider le format : une lettre suivie de 1 à 2 chiffres
    val secteurRegex = Regex("^[A-Za-z]\\d{1,2}$")

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

            OutlinedTextField(
                value = secteur,
                onValueChange = { newValue ->
                    // Limiter à 3 caractères max (1 lettre + 2 chiffres)
                    if (newValue.length <= 3) {
                        secteur = newValue.uppercase()
                        erreur = null
                    }
                },
                placeholder = { Text("Ex: C12") },
                singleLine = true,
                isError = erreur != null,
                modifier = Modifier.fillMaxWidth()
            )

            if (erreur != null) {
                Spacer(modifier = Modifier.height(4.dp))
                Text(
                    text = erreur ?: "",
                    color = MaterialTheme.colorScheme.error,
                    fontSize = 14.sp
                )
            }

            Spacer(modifier = Modifier.height(48.dp))

            Button(
                onClick = {
                    if (secteurRegex.matches(secteur)) {
                        val intent = Intent(ctx, InventaireScanActivity::class.java)
                        intent.putExtra("employe_numero", employeNumero)
                        intent.putExtra("employe_nom", employeNom)
                        intent.putExtra("secteur", secteur)
                        ctx.startActivity(intent)
                    } else {
                        erreur = "Format invalide. Saisir une lettre suivie de 1 à 2 chiffres (ex: C12)"
                    }
                },
                enabled = secteur.isNotBlank(),
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
        }
    }
}
