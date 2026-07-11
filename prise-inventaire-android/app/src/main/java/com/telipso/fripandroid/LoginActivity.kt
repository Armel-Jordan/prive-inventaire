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
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import com.telipso.fripandroid.api.InventaireApiService
import com.telipso.fripandroid.entities.Config
import com.telipso.fripandroid.ui.theme.PriseInventaireTheme
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Écran de connexion : code entreprise (slug) + email + mot de passe.
 * Authentifie via /api/auth/login et stocke le token Sanctum.
 */
class LoginActivity : AppCompatActivity() {
    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(LocaleManager.applyLocale(newBase))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // URL du serveur (même source que EmployeLoginActivity)
        val config = Config()
        SQLiteDb.getInstance(this).readableDatabase.use { db -> config.loadConfig(db) }
        if (config.serveurSynchro.isNotBlank()) {
            InventaireApiService.setBaseUrl(config.serveurSynchro)
        }

        setContent {
            PriseInventaireTheme {
                Surface(modifier = Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
                    LoginScreen(
                        onLoggedIn = {
                            startActivity(Intent(this, EmployeLoginActivity::class.java))
                            finish()
                        },
                        onOpenConfig = {
                            startActivity(Intent(this, ConfigActivity::class.java))
                        },
                    )
                }
            }
        }
    }
}

@Composable
private fun LoginScreen(onLoggedIn: () -> Unit, onOpenConfig: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var slug by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Text("Connexion", style = MaterialTheme.typography.headlineSmall)
        Spacer(Modifier.height(24.dp))

        OutlinedTextField(
            value = slug,
            onValueChange = { slug = it.trim() },
            label = { Text("Code entreprise") },
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )
        Spacer(Modifier.height(12.dp))
        OutlinedTextField(
            value = email,
            onValueChange = { email = it.trim() },
            label = { Text("Email") },
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
            modifier = Modifier.fillMaxWidth(),
        )
        Spacer(Modifier.height(12.dp))
        OutlinedTextField(
            value = password,
            onValueChange = { password = it },
            label = { Text("Mot de passe") },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
            modifier = Modifier.fillMaxWidth(),
        )

        error?.let {
            Spacer(Modifier.height(12.dp))
            Text(it, color = MaterialTheme.colorScheme.error)
        }

        Spacer(Modifier.height(24.dp))
        Button(
            onClick = {
                error = null
                loading = true
                scope.launch {
                    try {
                        val result = withContext(Dispatchers.IO) {
                            InventaireApiService.login(email, password, slug)
                        }
                        AuthStore.save(context, result.token ?: "", slug)
                        loading = false
                        onLoggedIn()
                    } catch (e: Exception) {
                        loading = false
                        error = e.message ?: "Échec de la connexion"
                    }
                }
            },
            enabled = !loading && slug.isNotBlank() && email.isNotBlank() && password.isNotBlank(),
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text(if (loading) "Connexion..." else "Se connecter")
        }

        Spacer(Modifier.height(12.dp))
        TextButton(onClick = onOpenConfig) {
            Text("Configurer le serveur")
        }
    }
}
