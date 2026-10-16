# SÉCURITÉ DES COMPTES — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-15 — création (plan de parité fripv2, phase 6.1)

---

## 1. Ce que c'est

| Fonction | Où | Pour qui |
|---|---|---|
| **Mot de passe oublié** | Lien sous le champ mot de passe de l'écran de connexion | Tout utilisateur |
| **Changement de mot de passe** | Icône bouclier de l'en-tête → **Sécurité du compte** | Utilisateur connecté |
| **Double authentification** | Sécurité du compte | Chacun l'active pour son propre compte (facultatif) |
| **Désactiver la double authentification d'un utilisateur** | Sécurité du compte, section réservée aux administrateurs | Administrateurs (utilisateur qui a perdu son téléphone) |

Rien ne change pour un compte qui n'active pas la double authentification : la connexion reste celle d'aujourd'hui.

---

## 2. Mot de passe oublié

1. L'utilisateur saisit le code de son entreprise et son courriel.
2. La réponse est **toujours la même**, que le compte existe ou non : on ne peut pas découvrir quelles adresses ont un compte.
3. Si le compte existe et est actif, un courriel (français ou anglais, selon la langue de l'écran) contient un lien `…/reinitialiser-mot-de-passe?jeton=…` :
   - valable **60 minutes** ;
   - **à usage unique** ;
   - une nouvelle demande annule la précédente.
4. Sur la page du lien, l'utilisateur choisit un nouveau mot de passe (8 caractères au moins, avec confirmation).
5. Une fois le mot de passe changé, **toutes les sessions ouvertes du compte sont fermées** : autres navigateurs et application mobile.

**Sécurité :**
- Le jeton est stocké **haché** (sha256) : la base ne contient jamais un lien utilisable.
- Le courriel part **après la réponse**. Le temps de réponse ne trahit donc pas l'existence du compte.
- 5 demandes par minute au plus par adresse IP.
- Adresse du lien :
  - l'origine du site qui fait la demande est reprise seulement si elle figure dans `CORS_ALLOWED_ORIGINS` ;
  - sinon on utilise `FRONTEND_URL`, puis `APP_URL`.

  Une origine quelconque n'est jamais reprise : elle permettrait d'envoyer un lien piégé vers un autre site.

---

## 3. Changement de mot de passe

- Le mot de passe actuel est exigé. Le nouveau doit contenir au moins 8 caractères et différer de l'actuel.
- **Les autres sessions sont fermées.** La session en cours reste ouverte.

---

## 4. Double authentification (TOTP)

**Activation :**
1. **Activer**, puis confirmer avec le mot de passe.
2. Scanner le **QR code** avec une application d'authentification (Google Authenticator, Microsoft Authenticator, FreeOTP…), ou saisir la clé affichée.
3. Saisir le code à 6 chiffres affiché par l'application.
4. **8 codes de secours** s'affichent **une seule fois**, avec un bouton pour les copier. Chacun permet une connexion sans téléphone.

**Connexion :**
1. Après le mot de passe, l'écran demande le code de l'application, ou un code de secours (« Utiliser un code de secours »).
2. La saisie doit avoir lieu dans les **5 minutes**, avec **5 essais** au plus. Au-delà, il faut ressaisir le mot de passe.
3. Après **10 codes faux en 15 minutes** pour un même utilisateur (tous essais confondus, quelle que soit l'adresse IP), l'étape du code est bloquée 15 minutes (réponse 429).

**Règles :**
- Codes conformes à la RFC 6238 : 6 chiffres, pas de 30 s, HMAC-SHA1.
- Un décalage d'horloge d'un pas est toléré.
- **Un même code ne sert qu'une fois** (anti-rejeu).
- Un code de secours est **consommé** à l'usage. Les codes peuvent être régénérés (mot de passe exigé) ; les anciens ne valent plus.
- Désactivation par l'utilisateur, mot de passe exigé.
- Le secret et les codes de secours sont **chiffrés** en base (clé de l'application). Les codes de secours ne sont stockés que hachés, et rien de tout cela n'est renvoyé par l'API.

**Téléphone perdu :** un administrateur de l'entreprise désactive la double authentification de l'utilisateur. Celui-ci se connecte avec son mot de passe et peut la réactiver.

**Application mobile :** elle ne gère pas encore la seconde étape. Un utilisateur qui active la double authentification ne peut plus s'y connecter : elle affiche le message de l'API, qui l'explique. Le support viendra avec la phase 7 (mobile terrain). L'écran d'activation prévient l'utilisateur.

---

## 5. API

| Route | Accès | Rôle |
|---|---|---|
| `POST /auth/mot-de-passe-oublie` | Public, 5 par minute | `tenant_slug`, `email`, `origine`, `langue`. Réponse identique dans tous les cas |
| `POST /auth/reinitialiser-mot-de-passe` | Public, 10 par minute | `jeton`, `password`, `password_confirmation` |
| `POST /auth/login` | Public | Inchangé sans double authentification. Avec : **403** `{deux_facteurs: true, defi}`, sans jeton |
| `POST /auth/login/deux-facteurs` | Public, 10 par minute | `defi`, `code` (application ou secours). Même réponse que la connexion. `defi_expire: true` s'il faut ressaisir le mot de passe |
| `PUT /auth/mot-de-passe` | Connecté | `mot_de_passe_actuel`, `password`, `password_confirmation` |
| `GET /auth/deux-facteurs` | Connecté | `active`, `active_le`, `codes_secours_restants` |
| `POST /auth/deux-facteurs/preparer` | Connecté | `password` → `secret`, `uri` (otpauth://) |
| `POST /auth/deux-facteurs/activer` | Connecté | `code` → état et `codes_secours` |
| `POST /auth/deux-facteurs/desactiver` | Connecté | `password` |
| `POST /auth/deux-facteurs/codes-secours` | Connecté | `password` → nouveaux `codes_secours` |
| `GET /securite/utilisateurs` | Administrateurs | Utilisateurs de l'entreprise et état de leur double authentification |
| `DELETE /securite/utilisateurs/{id}/deux-facteurs` | Administrateurs | Désactive la double authentification d'un utilisateur de l'entreprise |

`/auth/login` et `/auth/me` renvoient aussi `user.deux_facteurs`.

**Données :**
- colonnes facultatives `admin_users.deux_facteurs_secret`, `deux_facteurs_active_le`, `deux_facteurs_codes_secours` et `deux_facteurs_dernier_pas` ;
- table `reinitialisations_mot_de_passe` (migration `2026_10_15_100017`).

**Configuration** (`config/comptes.php`) :
- `FRONTEND_URL` ;
- `REINITIALISATION_MOT_DE_PASSE_MINUTES` (60 par défaut) ;
- `DEUX_FACTEURS_EMETTEUR` (nom affiché dans l'application, `APP_NAME` par défaut).

**Code :**
- API : `App\Support\Totp`, `App\Services\DeuxFacteurs`, `App\Http\Controllers\Securite\*`, `App\Mail\LienReinitialisationMotDePasse` ;
- front : `LoginPage`, `MotDePasseOubliePage`, `ReinitialiserMotDePassePage`, `SecuriteComptePage`, `services/securite.ts`.

---

## 6. À configurer en production

- **Envoi des courriels** : `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`. Avec `MAIL_MAILER=log`, le lien n'est écrit que dans le journal.
- **Adresse du site** : `FRONTEND_URL` (ex. `https://app.mondomaine.com`), ou `CORS_ALLOWED_ORIGINS` avec l'adresse exacte du site.
- La connexion du **super administrateur** (`/super-admin/login`) n'est pas concernée par cette phase.
