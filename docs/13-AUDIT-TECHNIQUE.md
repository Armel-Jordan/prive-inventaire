# 13 — Audit technique complet

> **Date de l'audit** : 2026-07-09
> **Périmètre** : projet Prise Inventaire dans son ensemble — API Laravel, Web React, Mobile Android, site Marketing, documentation & infrastructure.
> **Méthode** : audit en lecture seule, mené par 4 experts (backend, frontend, mobile, architecture) explorant le code réel. Toutes les références sont au format `fichier:ligne` et ont été vérifiées.
> **Statut** : instantané à date. À réévaluer après chaque phase de remédiation.

---

## Sommaire

1. [Verdict global](#1-verdict-global)
2. [Métriques du projet](#2-métriques-du-projet)
3. [🚨 Cluster de failles CRITIQUES (bloquant production)](#3--cluster-de-failles-critiques-bloquant-production)
4. [API Laravel — détail](#4-api-laravel--détail)
5. [Web React — détail](#5-web-react--détail)
6. [Mobile Android — détail](#6-mobile-android--détail)
7. [Marketing — détail](#7-marketing--détail)
8. [Documentation & infrastructure — détail](#8-documentation--infrastructure--détail)
9. [Points positifs](#9-points-positifs)
10. [Feuille de route de remédiation](#10-feuille-de-route-de-remédiation)
11. [Index des fichiers de référence](#11-index-des-fichiers-de-référence)

---

## 1. Verdict global

**Projet fonctionnellement avancé, avec un socle DevOps étonnamment mûr, mais PAS prêt pour une exposition production sécurisée en l'état, à cause d'un bloc de failles de sécurité critiques.**

- **Forces** : stack moderne et cohérente (Laravel 12 / PHP 8.2+ / React 19 / TypeScript strict / Kotlin Compose), pipeline CI/CD complet (lint + PHPUnit + déploiement SSH + health check + monitoring 15 min), documentation fonctionnelle riche et à jour (`docs/flows`).
- **Faiblesse structurante n°1** : la **sécurité**. Plusieurs failles sont exploitables aujourd'hui — l'isolation multi-tenant est en pratique contournable, et des secrets de production sont commités en clair.
- **Faiblesse structurante n°2** : la **cohérence** (pas de client API unique côté web, enveloppe de réponse API non standardisée) et l'**absence de tests métier** côté API.

**Niveau de maturité : intermédiaire.** Le projet est fonctionnellement riche mais les points de sécurité relèvent d'une remédiation obligatoire avant toute montée en charge ou ouverture client.

---

## 2. Métriques du projet

| Composant | Stack | Volume |
|-----------|-------|--------|
| API | Laravel 12 / PHP 8.2+ / MySQL | 27 controllers, 43 models, 74 migrations, ~460 lignes de routes, **2 tests (stubs uniquement)** |
| Web | React 19 / TypeScript / Tailwind 4 / Vite 7 / React Router 7 | 39 pages, 53 composants, ~18 800 lignes |
| Mobile | Kotlin / Jetpack Compose / OkHttp | 26 fichiers Kotlin, ~3 900 lignes |
| Marketing | React 19 / Vite 8 | 18 composants |

---

## 3. 🚨 Cluster de failles CRITIQUES (bloquant production)

Ces points forment un tout : **tant qu'ils ne sont pas réglés, le multi-tenant est une illusion et les données de tous les clients sont accessibles.**

### 3.1 🔴 Isolation multi-tenant trouée (IDOR généralisé)

Les méthodes `index()` scopent bien par `tenant_id`, mais **toutes les méthodes `show/update/destroy` qui utilisent `findOrFail($id)` nu** permettent d'accéder/modifier/supprimer les données d'un autre tenant en devinant l'ID.

Controllers confirmés vulnérables (liste non exhaustive) :
- `app/Http/Controllers/ProduitTenantController.php:76,83,110,131`
- `app/Http/Controllers/EmployeTenantController.php:98,105,174,186`
- `app/Http/Controllers/SecteurController.php:61,68,101,142,162`
- `app/Http/Controllers/Api/ClientController.php:47,86,110,123,130`
- `app/Http/Controllers/Api/FactureController.php:45,206,221,256,299`
- `app/Http/Controllers/Api/CommandeClientController.php:42,107,156,170,211,231`
- `app/Http/Controllers/Api/BonLivraisonController.php:37,44,62,109,131,190`
- `app/Http/Controllers/Api/CamionController.php:43,69,89`, `Api/TourneeController.php` (8 occurrences), `Api/ZonePreparationController.php:22,44,60`
- `app/Http/Controllers/AlerteStockController.php:114` (`ProduitTenant::find` en batch → modifie les seuils d'alerte d'autres tenants)

**Recommandation** : imposer un scope tenant systématique via un **Global Scope Eloquent** (trait `BelongsToTenant` avec `addGlobalScope`) branché sur le tenant courant. C'est la seule solution robuste vu l'ampleur. À défaut, remplacer chaque `Model::findOrFail($id)` par `Model::where('tenant_id',$tenantId)->findOrFail($id)`.

### 3.2 🔴 Endpoints entièrement sans scope tenant

- **`NotificationController`** : `index` (l.16), `unreadCount` (l.37), `markAsRead` (l.47), `markAllAsRead` (l.62 — update global), `destroy` (l.75), `cleanup` (l.89 — delete global). Toutes les notifications de tous les tenants sont exposées ; `markAllAsRead`/`cleanup` opèrent sur toute la table.
- **`RolePermissionController`** : la table `roles_custom` n'a pas de `tenant_id` (migration `2026_03_01_000016`). `index()` (l.22-37) liste tous les rôles de tous les tenants. Les rôles sont partagés entre entreprises.
- **`ScanTenantController`** : `enregistrer` (l.42) ne fixe **jamais** `tenant_id` alors que la colonne existe ; `historique`/`stats`/`modifier`/`supprimer` ne filtrent jamais. Tous les scans de tous les tenants sont mélangés dans `inventaire_scan` avec `tenant_id = NULL`.

**Recommandation** : ajouter `where('tenant_id', ...)` partout, ajouter la colonne `tenant_id` à `roles_custom`, renseigner `tenant_id` à la création des scans.

### 3.3 🔴 Routes `/mobile/*` sans authentification + app Android sans login réel

- `routes/api.php:79-100` : les endpoints mobiles n'ont **ni `auth:sanctum` ni `tenant`**. Ils appellent des controllers qui font `auth()->user()->tenant_id` → soit **erreur 500 (TypeError)** avec user null, soit **fuite de tous les produits/secteurs de tous les tenants** (`ProduitTenantController::index`, `SecteurController::index` ne filtrent que `actif`).
- Côté Android : **aucune authentification**. La « connexion » (`EmployeLoginActivity.kt:61-115`) est une simple sélection d'employé dans un dropdown, sans mot de passe ni token. Aucun header `Authorization`, aucun Bearer, aucun stockage de token (grep exhaustif : zéro occurrence fonctionnelle).

**Conséquence combinée** : n'importe qui installant l'APK (ou appelant l'API directement) accède en lecture/écriture à tout l'inventaire, sans authentification, tous tenants confondus.

**Recommandation** : exiger une authentification tenant sur les routes `/mobile` (token appareil scopé tenant), et implémenter un vrai login + token Sanctum stocké en `EncryptedSharedPreferences` côté Android.

### 3.4 🔴 Escalade de privilèges super-admin

`routes/api.php:105` protège `super-admin/*` par `auth:sanctum` **seul**. Aucun middleware ne vérifie que le porteur est un `SuperAdmin`. Or `AuthController::login` émet des tokens `AdminUser` avec abilities `['*']` (`AuthController.php:59`).

**Conséquence** : n'importe quel `AdminUser` de n'importe quel tenant peut lister/créer/supprimer tous les tenants et créer des admins partout (`SuperAdminController::deleteTenant:139`, `createTenantAdmin:161`).

**Recommandation** : créer un guard/middleware dédié `super-admin` vérifiant `instanceof SuperAdmin` ou `tokenCan('super-admin')`, et cesser d'émettre `['*']` aux `AdminUser`.

### 3.5 🔴 Système de permissions jamais appliqué

Le middleware `CheckPermission` (`app/Http/Middleware/CheckPermission.php`) n'est **ni aliasé** dans `bootstrap/app.php` (seul `tenant` l'est) **ni référencé** dans `routes/api.php`. L'autorisation par rôle/permission n'est donc **jamais enforced côté API** — tout utilisateur authentifié accède à tous les endpoints de son tenant quel que soit son rôle. Le RBAC est purement cosmétique (y compris côté front, cf. §5).

**Recommandation** : aliaser et brancher `CheckPermission` sur les routes.

### 3.6 🔴 Secrets de production commités en clair

- `DEPLOYMENT_INFO.md` : mot de passe MySQL `PriseInv2026!`, mot de passe admin `Admin123`, IP serveur, user SSH `root` (l.48, 63, 72).
- Historique git (commit `518d570`) : `.env` de l'API avec `APP_KEY=base64:vKZ...` et `DB_PASSWORD=PriseInv2026!` — exposés même si le fichier est aujourd'hui gitignoré.
- `prise-inventaire-api/.env.example:24-28` : credentials RDS de prod (host `prise-inventaire.c2dai848u8x4.us-east-1.rds.amazonaws.com`, `admin` / `PriseInv2026!`).
- `prise-inventaire-web/.env` : `VITE_API_URL=http://143.110.210.158/api` (IP prod, HTTP clair) — `.gitignore` n'ignore que `*.local`, pas `.env`.
- `prise-inventaire-android/app/build.gradle:22-24` : `storePassword 'tircis'`, `keyPassword 'tircis'`, alias `tircis` (le keystore `Tircis.key` lui-même n'est PAS commité — point positif).

**Recommandation** : **rotation immédiate** de tous les secrets (DB, admin, `APP_KEY`), purge de l'historique git (git-filter-repo/BFG), externalisation des mots de passe keystore, passage du repo en privé. ⚠️ La rotation de l'`APP_KEY` invalide sessions/tokens chiffrés — à planifier.

### 3.7 🔴 Absence de TLS + CORS ouvert

- API prod servie en **HTTP simple** : `http://143.110.210.158/api` — tokens Sanctum et credentials transitent en clair.
- `config/cors.php` : `allowed_origins => ['*']`, `allowed_methods => ['*']`, `allowed_headers => ['*']`. `amplify.yml` racine force aussi `Access-Control-Allow-Origin: '*'`.

**Recommandation** : installer Certbot sur le droplet (forcer HTTPS), restreindre `allowed_origins` aux domaines Amplify connus.

### 3.8 🔴 Token JWT passé en query string

`getCommandePdfUrl` (`api.ts:421-433`) et `BonCommandePdfController` (`routes/api.php:72-73`) construisent `.../pdf?token=${token}`. Le token transite en query string → journalisé dans les logs serveur/proxy, l'historique navigateur, le `Referer`. De plus `authenticateFromToken` accepte n'importe quel token valide puis `findOrFail($id)` sans scope tenant → PDF cross-tenant.

**Recommandation** : `fetch` avec header `Authorization` + `blob`, ou token PDF à usage unique ; scoper la requête par tenant.

---

## 4. API Laravel — détail

**Chemin** : `prise-inventaire-api/` · **Stack** : Laravel 12, PHP 8.2+, MySQL, Sanctum

Monolithe multi-tenant **par colonne `tenant_id` sur une base MySQL unique**. Le multi-tenant a été greffé tardivement (migrations d'avril 2026) sur une base initialement mono-tenant (mars 2026), d'où les trous d'isolation systémiques.

> **Point d'architecture à clarifier** : `TenantService` (`app/Services/TenantService.php:38-73`) implémente une isolation par **base de données dédiée** (`db_host`, `db_name`, connexion `tenant`), mais **aucun modèle ne l'utilise** — tous forcent `protected $connection = 'mysql'` (ex. `ProduitTenant.php:14`, `EmployeTenant.php:13`, `ScanTenant.php:12`). L'isolation réelle repose donc uniquement sur les `where('tenant_id', ...)`. La connexion `tenant` dynamique est du **code mort trompeur**.

### Architecture & organisation

- 🟡 Services quasi absents : seule la logique dashboard est extraite (`app/Services/Dashboard/*`). Toute la logique métier (facturation, workflow commandes, calculs TVA) est dans les controllers — ex. `Api/FactureController.php:73-120`.
- 🟡 Incohérence de dossiers : 11 controllers dans `Api/`, 18 à la racine `Controllers/`, sans critère clair.
- 🟠 Deux sources de vérité pour le tenant : `auth()->user()->tenant_id` (`ClientController.php:16`) vs `$request->attributes->get('tenant')->id` (`ClientController.php:68`) dans le même fichier.
- 🟠 Code mort / doublons : `TenantController.php` (non routé, remplacé par `SuperAdminController`), `ProduitController`/`EmployeController`/`ScanController` (héritage Oracle, non routés), coexistence `MouvementInventaireController` + `MouvementTenantController` (deux tables au nom quasi identique).

### Base de données

- 🟠 **Aucune FK sur `tenant_id`** : ajouté en `nullable()->index()` sans `->foreign()` (`2026_04_10_200001_add_tenant_id_to_remaining_tables.php:24-26`). Pas d'intégrité référentielle, `tenant_id` peut rester NULL.
- 🟠 **Contraintes `unique` globales au lieu de scoped tenant** : `factures.numero unique()` (`2026_03_06_100007:13`), `ProduitTenantController.php:26` (`unique:produits,numero`), `SecteurController.php:25`, `CamionController.php:51` (immatriculation), emails admin, etc. Deux tenants ne peuvent pas réutiliser un même code/numéro → collisions de compteurs `Configuration`. **Reco** : `unique(['tenant_id','numero'])`.
- 🟠 Références croisées non vérifiées à la création : `FactureController::store` valide `client_id`/`produit_id` avec `exists:` sans `,tenant_id` (`FactureController.php:53,58`) ; idem `ProduitTenantController::store:32`.
- 🟡 Deux migrations partagent le préfixe `..._000007_` (`create_audit_logs` et `create_inventaire_scan`) → ordre non déterministe.
- 🟠 Migration hors framework : `database/tenant_migrations/create_mouvement_relocalisation_table.sql` (SQL brut) → dérive de schéma non tracée.
- 🟡 Risques N+1 : boucles `find()` par ligne dans `BonLivraisonController:76,149` et `CommandeFournisseurController:91,150`.
- ✅ Index de perf bien faits : `2026_04_10_300000_add_dashboard_indexes.php` crée des composites `(tenant_id, X)` de façon idempotente.

### Sécurité (hors cluster critique)

- 🟠 Config guard Sanctum incohérente : `config/sanctum.php` `guard=['web']` + provider `users` → `App\Models\User` (modèle non utilisé pour l'auth réelle, qui passe par `AdminUser`/`SuperAdmin`).
- 🟠/🟡 Fuite d'infos en erreur : `ScanController::validerProduit:41` renvoie `$e->getMessage()` au client, combiné à `APP_DEBUG=true` par défaut (`.env.example:4`).
- ✅ Mots de passe hashés, `db_password` casté `encrypted` (`Tenant.php:41`).
- ✅ **Pas d'injection SQL** : les `DB::select`/`selectOne` utilisent des bindings paramétrés ; `whereRaw`/`selectRaw` sur colonnes constantes sans input utilisateur.
- ✅ Tokens à durée limitée (7j AdminUser, 1j SuperAdmin).

### Tests

- 🔴 **Couverture métier nulle**. Seuls subsistent `tests/Feature/ExampleTest.php` (health → 200) et `tests/Unit/ExampleTest.php` (`assertTrue(true)`). Config de test correcte (SQLite `:memory:`), donc le socle existe. **Manque critique** : tests d'isolation cross-tenant, d'autorisation super-admin, des workflows commandes/factures/BL.

### Top 5 API

1. 🔴 Escalade super-admin + permissions non appliquées (§3.4, §3.5)
2. 🔴 IDOR cross-tenant généralisé → Global Scope tenant (§3.1)
3. 🔴 Endpoints sans scope tenant : Notification, ScanTenant, RolePermission, BonCommandePdf (§3.2, §3.8)
4. 🔴 Secrets de production exposés + `APP_DEBUG` (§3.6)
5. 🔴 Routes `/mobile/*` non authentifiées + absence totale de tests (§3.3)

---

## 5. Web React — détail

**Chemin** : `prise-inventaire-web/` · **Stack** : React 19, TypeScript, Vite 7, Tailwind 4, React Router 7, Recharts
`npm run build` passe (0 erreur TS), `npm run lint` passe avec **12 warnings**.

### Architecture & organisation

- 🔴 **Double architecture d'accès API contradictoire.** Un service centralisé `src/services/api.ts` (1094 lignes, ~130 fonctions typées) existe, mais **21 fichiers font du `fetch` direct** en le contournant, avec `getAuthHeaders()` réimplémenté localement dans **18 fichiers** (`Dashboard.tsx:28`, `RolesPage.tsx:8`, `ProfilPage`, `AlertesPage`, `AuditPage`, `PlanificationPage`…).
- 🟠 **Composants réutilisables créés mais non adoptés** : `ConfirmModal` dans 3 pages (contre **25 `confirm()` natifs**), `useToast`/`Toasts` dans 13 pages (contre **40 `alert()` natifs**), `EmptyState` dans 10 pages, `PageSkeleton` dans 11.
- 🟡 `services/api.ts` = fichier fourre-tout de 1094 lignes mêlant types métier et appels → à découper par domaine.
- 🟡 Imports incohérents : 32 fichiers en alias `@/`, 9 en relatif `../`.

### Gestion d'état & données

- 🔴 **Aucune gestion du 401 / expiration de token.** `fetchApi` (`api.ts:65`) et tous les fetch dispersés se contentent de `throw new Error(...)`. Un token expiré bloque l'utilisateur sur des erreurs silencieuses, sans logout/redirect. **Reco** : wrapper unique qui, sur 401, purge `localStorage` et redirige vers `/login`.
- 🟠 **Erreurs API massivement avalées** : `catch { /* ignore */ }` (`SuperAdminDashboard.tsx:107,123`) ou `console.error` sans état UI. Un échec de sauvegarde ferme le modal comme si tout allait bien (`ClientsPage.tsx:93`). **Seules 7 pages sur 39** ont un état `error`. 66 `console.error/log` en prod.
- 🟠 **Fallback API dupliqué 24 fois** : `import.meta.env.VITE_API_URL || 'http://localhost:8000/api'`.
- 🟡 Aucun cache ni déduplication (pas de React Query/SWR) ; polling `setInterval` 30 s dans `Dashboard.tsx:26`.
- 🟡 Multi-tenant via en-tête `X-Tenant-Slug` lu depuis `localStorage` — cohérent mais dépend de la logique dupliquée.
- 🟡 `MOCK_MODE` incohérent : présent pour employés/produits/secteurs/scans, absent pour clients/factures/tournées → code mort semi-appliqué.

### Qualité TypeScript — point fort

- ✅ `tsconfig.app.json` en `strict: true` + `noUnusedLocals/Parameters`. Build TS sans erreur. **0 `@ts-ignore`.**
- 🟡 5 `any` seulement (`BonsLivraisonPage.tsx:93`, `TourneesPage.tsx:150`, `CommandesClientPage.tsx:83,86,107`).

### Sécurité front

- 🔴 Token JWT dans l'URL PDF (§3.8).
- 🔴 `.env` de prod versionné en HTTP clair (§3.6).
- 🟠 Routes super-admin non protégées côté routeur : `App.tsx:114-117` hors `ProtectedRoute`, protection reposant sur un `useEffect` + `navigate` dans chaque composant.
- 🟠 Token en `localStorage` (`AuthContext.tsx:58`) — vulnérable au vol par XSS (atténué : **aucun `dangerouslySetInnerHTML`** dans tout le code ✅).
- 🟡 Filtrage de permissions cosmétique (`Layout.tsx:144,151,158`) : fallback « afficher tout » en cas d'erreur `canView` — la sécurité effective doit être garantie côté API.

### Performance

- 🟠 **Aucun code splitting / lazy loading.** `App.tsx:7-45` importe statiquement les 39 pages → **un seul chunk JS de 1 128 kB (283 kB gzip)**, avertissement Vite « chunks larger than 500 kB ». **Reco** : `React.lazy` + `Suspense` par route + `manualChunks` (Recharts, lucide-react).
- 🟡 0 `React.memo` ; `useMemo` (17) et `useCallback` (25) rares. Grosses pages tableau à risque de re-renders.

### UX / accessibilité

- 🟠 40 `alert()` + 25 `confirm()` natifs au lieu des composants existants → UX incohérente, bloquante.
- 🟠 **i18n branchée mais quasi inutilisée** : `translations.ts` (fr/en, 304 l.) consommée dans **aucune des 39 pages** ; seul le `Layout` l'utilise, avec **23 labels de nav en dur** (`Layout.tsx:79-119`). Le sélecteur de langue ne change quasiment rien.
- 🟡 `index.html` : `lang="en"` (app FR), titre = défaut Vite `prise-inventaire-web`, favicon `vite.svg`.

### Dette technique

- 🟠 Duplication systémique : `getAuthHeaders` (18×), fallback API URL (24×), `MODULE_LABELS` dupliqué (`RolesPage.tsx:36` vs Layout).
- 🟠 Pages trop volumineuses : `SuperAdminTenantPage` 698 l., `CommandesFournisseurPage` 695 l., `RelocalisationPage` 675 l., `Dashboard` 646 l., `PlanificationPage` 608 l., `ScansPage` 591 l.
- 🟡 12 warnings ESLint `react-hooks/exhaustive-deps`, contournés par 7 `eslint-disable`. ✅ Aucun TODO/FIXME/HACK.

### Top 5 Web

1. 🔴 Centraliser l'accès API + gérer le 401 (logout/redirect)
2. 🔴 Corriger les fuites de secrets (token URL, `.env`, HTTPS)
3. 🔴 Arrêter d'avaler les erreurs (feedback UI + état `error`, purge des `console.*`)
4. 🟠 Code splitting par route (casser le bundle de 1,1 Mo)
5. 🟠 Uniformiser l'UX (`ConfirmModal`/`Toasts` vs 65 `alert`/`confirm`), brancher réellement l'i18n ou l'assumer non prioritaire

---

## 6. Mobile Android — détail

**Chemin** : `prise-inventaire-android/` · **Stack** : Kotlin, Jetpack Compose + Material 3, OkHttp + Gson
Package réel `com.telipso.fripandroid` (héritage projet « frip »), `applicationId = com.prise.inventaire`.

### Architecture

- MVVM partiel via Compose : chaque écran = une `AppCompatActivity` + un `ViewModel` dans le même `.kt`. Exception XML : `ConfigActivity`.
- 🟠 **Couche data éclatée en 3 systèmes non unifiés** : (1) `InventaireApiService` (OkHttp direct, le flux réel), (2) `SQLiteDb` (stocke URL serveur + langue ; tables `employe`/`produit` définies mais **mortes**), (3) `offline/` (Room + WorkManager, **quasi non branché**).
- 🟡 Pas de vrai Repository pattern ; état exposé en `var` public mutable (pas de `StateFlow`).

### Communication API

- Client **OkHttp 4.12 brut + Gson** (pas de Retrofit malgré la doc), timeouts 30 s, appels synchrones enveloppés dans `withContext(Dispatchers.IO)` — correct.
- 🔴 **Tenant/slug totalement absent** ; **aucun token JWT/Sanctum** (§3.3).
- URL API configurable via `ConfigActivity` → SQLite ; défaut `http://10.0.2.2:8000/api` (`InventaireApiService.kt:17`).
- 🟠 **Mode hors ligne implémenté mais non branché** : `OfflineRepository.saveScanOffline()` jamais appelé (le scan principal appelle directement l'API et échoue en coupure), `SyncWorker.schedule()` jamais appelé, `HistoriqueLocalActivity` inaccessible depuis la navigation. Promesse « offline » non tenue.

### Sécurité

| Sévérité | Problème | Emplacement |
|---|---|---|
| 🔴 | Mots de passe keystore en clair (`'tircis'`) | `app/build.gradle:22-24` |
| 🔴 | Aucune authentification (sélection employé sans credential) | `EmployeLoginActivity.kt:61-115` |
| 🟠 | Cleartext HTTP autorisé sur 5 domaines/IP de dev | `res/xml/network_security_config.xml:3-9` |
| 🟠 | `allowBackup="true"` (config sauvegardée en cloud/adb) | `AndroidManifest.xml:10` |
| 🟠 | Logs verbeux en prod (corps requête/réponse en clair, non strippés par ProGuard) | `InventaireApiService.kt:107,124-125,207-213` |
| 🟡 | `upload.sh` commité (IP interne + chemins serveur) | `upload.sh` |
| 🟡 | Aucune règle ProGuard/R8 alors que `minifyEnabled true` | `app/proguard-rules.pro` (vide) |

### Qualité

- Gestion d'erreurs réseau générique (pas de distinction timeout/401/500/offline, aucun retry).
- 🟡 `catch (_: Exception) {}` silencieux (`InventaireScanActivity.kt:220,227,234`, `RelocalisationActivity.kt:214,221`).
- 🟡 Accès SQLite sur le main thread (`EmployeLoginActivity:97`, `ConfigActivity:51`) → risque ANR (faible).
- 🟡 Concaténation d'URL non encodée (`getHistorique`, `InventaireApiService.kt:233`).
- ✅ Null-safety globalement bonne (Kotlin).

### Fonctionnalités réelles

Inventaire d'entrepôt : sélection employé (sans mdp) → sélection secteur (dropdown + scan QR ZXing) → saisie/scan produit (champ texte, pensé douchette) → quantité → enregistrement (feedback sonore/vibration) → historique (consult/modif/suppr) → relocalisation (arrivage/transfert/sortie). Le scan QR ne sert **que** pour le secteur, pas pour le produit.

### Build & versioning

- Gradle 9.1.0, AGP 9.0.0, Kotlin 2.2.10, `compileSdk 36`, `minSdk 26`, `targetSdk 34`.
- 🟠 **`versionCode`/`versionName` figés à `1`/`"1.0"`** (`build.gradle:32-33`) alors qu'un mécanisme `version.properties` (`VERSION_CODE=4036`) s'incrémente à chaque config Gradle mais **n'est jamais réinjecté** → tous les APK sortent en `versionCode 1` (**mise à jour store impossible**).
- 🟠 Compose compiler figé `1.4.6` (`build.gradle:67`), obsolète avec Kotlin 2.2.
- 🟡 Dépendances inutilisées embarquées (`bonsai-core`, `signature-view`, calendar, `coil-compose`, `guava`…) ; doublons de déclarations (`:97,100`, `:80,125`).

### Divers

- 🟡 `TestChangeDate.kt` référence une classe `SQLUtil` inexistante → test non compilable.
- 🟡 Package legacy `com.telipso.fripandroid` ; tables SQLite `employe`/`produit` mortes ; `SQLiteDb` `onUpgrade` DROP toutes les tables (perte de config à l'upgrade).

### Top 5 Mobile

1. 🔴 Absence totale d'authentification/token (§3.3)
2. 🔴 Secrets de signature en clair (`app/build.gradle:22-24`)
3. 🟠 Versioning cassé (`versionCode` bloqué à 1 → aucune MàJ possible)
4. 🟠 Mode offline non branché (perte de saisie en coupure réseau)
5. 🟠 Cleartext HTTP + logs verbeux + backup activé en config release

---

## 7. Marketing — détail

**Chemin** : `prise-inventaire-marketing/` · **Stack** : React 19 / Vite 8

- 🟠 **Service worker cache-first naïf** (`public/sw.js`, enregistré dans `index.html:20-22`). Il intercepte toutes les requêtes et sert d'abord le cache (`caches.match` avant réseau), y compris `index.html`. Après un déploiement Amplify, les visiteurs restent **bloqués sur l'ancienne version** : `index.html` en cache pointe vers des assets hashés supprimés → écran blanc / bugs. L'`activate` ne purge que les caches d'autres noms, pas l'entrée courante. Aucun `skipWaiting`/`clients.claim`/`unregister`. **C'est la cause des bugs de cache observés en dev et en prod.**
- **Reco** : pour un site vitrine, le SW n'apporte aucune valeur → **le supprimer et le désenregistrer** (publier un `sw.js` qui appelle `self.registration.unregister()` + `caches.delete`). Le SW n'existe pas dans l'app web admin (vérifié) — problème isolé au marketing.

---

## 8. Documentation & infrastructure — détail

### Infrastructure réelle (constatée)

- **Backend** : droplet DigitalOcean `143.110.210.158`, Ubuntu 24.04, nginx + php8.3-fpm, MySQL local, `/var/www/prise-api/`. Déploiement automatisé via `.github/workflows/backend-cd.yml` (push `main` → tests → SSH `git reset --hard` + migrations + maintenance mode + `config:cache` + health check). Secrets en GitHub Secrets (`DO_HOST`, `DO_SSH_KEY`) — correct.
- **Web + Marketing** : AWS Amplify (auto sur push) + CI GitHub Actions (lint + build).
- **Monitoring** : `.github/workflows/health-monitor.yml` ping `/api/health` + login toutes les 15 min, crée une issue GitHub auto si down.

### Écarts documentation vs code

- 🟠 **`docs/05` et `docs/06` décrivent une infra AWS jamais déployée** (Elastic Beanstalk + RDS + PHP 8.2, ~$30/mois) alors que la réalité est DigitalOcean $8/mois, MySQL local, PHP 8.3, déploiement SSH. Instructions trompeuses. **Reco** : marquer obsolète ou réécrire pour DigitalOcean.
- 🟡 Doc de déploiement Android obsolète (`docs/05` §4.2-4.3 : constante `DEFAULT_BASE_URL` et keystore `prise-inventaire.keystore` ; réalité : `baseUrl` var + `Tircis.key`).
- 🟡 Le CI/CD réel n'est documenté nulle part (`.github/workflows/`).
- ✅ `docs/flows/*` fiables et à jour (modules ventes/achats/finance/inventaire réellement présents). Docs 01-04, 08-12 cohérentes.

### Cohérence inter-composants

- 🟠 **Enveloppe de réponse API non standardisée** : contrat théorique `{data, message, status}` mais le code renvoie selon les endpoints objet direct, `{employe}`, `{produit}`, `{scan}`, `{success, message, scan}`, `{data, total}`. Le front s'y adapte au cas par cas. **Reco** : format unique via Laravel Resources + type `ApiResponse<T>` côté TS.
- 🟡 Divergence de versions front : Web = Vite 7 / React 19.2.0 ; Marketing = Vite 8 / React 19.2.4.
- 🟡 Périmètre mobile restreint (inventaire/scan/relocalisation uniquement) → pas de duplication de logique métier front↔mobile (périmètres disjoints).

### Déploiement & infra — problèmes

- 🔴 API en HTTP sans TLS (§3.7).
- 🟠 CORS totalement ouvert (§3.7).
- 🟠 **Deux fichiers Amplify contradictoires** : `amplify.yml` racine (multi-app, customHeaders CORS) vs `prise-inventaire-web/amplify.yml` (mono-app, sans headers) ; marketing sans `amplify.yml`. Comportement CORS/build ambigu.
- 🟡 `php artisan down`/`up` = fenêtre de downtime à chaque déploiement (mono-serveur, acceptable au stade actuel).

### Gouvernance & hygiène repo

- 🟠 **APK binaire de 4 Mo commité** (`prise-inventaire-v1.0.apk`, + historique via `7d3b709`). **Reco** : GitHub Releases / artefact CI.
- 🟡 `.gitignore` incomplet (marketing `.env`, `*.apk`, `phpinfo.txt` global) ; `.claude/agents/*.md` trackés (à confirmer si voulu).
- 🟡 `README.md` racine ne mentionne pas le composant marketing.
- ✅ `vendor/`, `node_modules/`, vrais `.env` non trackés. `phpinfo.txt` et `Tircis.key` sur disque mais **ni trackés ni dans l'historique** (`.gitignore` a fonctionné).

---

## 9. Points positifs

À conserver et valoriser :

- ✅ **Pipeline CI/CD complet** : lint (Pint), tests PHPUnit sur MySQL, déploiement SSH avec health check, monitoring 15 min + création d'issue auto.
- ✅ **TypeScript strict côté web** : `strict: true`, 0 `@ts-ignore`, build sans erreur.
- ✅ **Aucune injection SQL** côté API (bindings paramétrés partout).
- ✅ **Aucun `dangerouslySetInnerHTML`** côté web (surface XSS réduite).
- ✅ Mots de passe hashés, `db_password` chiffré, tokens à durée limitée.
- ✅ Index de performance dashboard bien conçus (composites idempotents).
- ✅ Documentation fonctionnelle (`docs/flows`) fiable et à jour.
- ✅ Endpoint `/health` propre (test PDO) + secrets de déploiement en GitHub Secrets.

---

## 10. Feuille de route de remédiation

### Phase 0 — Sécurité (bloquant production, ~1-2 semaines)

1. **Global Scope tenant** (trait `BelongsToTenant`) pour éliminer tous les IDOR d'un coup (§3.1).
2. **Sécuriser `/mobile/*`** (auth + tenant) et le **super-admin** (guard dédié) ; **brancher `CheckPermission`** (§3.2, §3.3, §3.4, §3.5).
3. **Rotation de TOUS les secrets** + purge historique git + repo privé + externalisation keystore Android (§3.6).
4. **HTTPS (Certbot)** + **CORS restreint** + **token PDF hors URL** (§3.7, §3.8).
5. **Filet de sécurité d'abord** : écrire les tests d'isolation multi-tenant AVANT de refactorer.

### Phase 1 — Robustesse

- Client API web unique + gestion du 401 (logout/redirect) + arrêt des erreurs avalées.
- Brancher/valider le mode offline mobile + corriger le `versionCode`.
- Supprimer et désenregistrer le `sw.js` marketing.

### Phase 2 — Qualité / dette

- Code splitting web, découpage des pages géantes.
- Standardisation de l'enveloppe API (Laravel Resources).
- Nettoyage du code mort (backend + mobile), correction de la doc infra (05/06 → DigitalOcean + doc CI/CD).
- Retirer l'APK du repo, compléter `.gitignore`, aligner les versions front.

---

## 11. Index des fichiers de référence

**API**
- `prise-inventaire-api/routes/api.php` (l.72-73, 79-100, 105)
- `prise-inventaire-api/bootstrap/app.php`
- `prise-inventaire-api/app/Http/Middleware/CheckPermission.php`
- `prise-inventaire-api/app/Http/Controllers/NotificationController.php`
- `prise-inventaire-api/app/Http/Controllers/ScanTenantController.php`
- `prise-inventaire-api/app/Http/Controllers/Api/BonCommandePdfController.php`
- `prise-inventaire-api/app/Http/Controllers/AuthController.php` (l.59)
- `prise-inventaire-api/app/Services/TenantService.php`
- `prise-inventaire-api/config/cors.php` (l.9)
- `prise-inventaire-api/.env.example` (l.24-28)

**Web**
- `prise-inventaire-web/src/services/api.ts` (l.65, 421-433)
- `prise-inventaire-web/src/App.tsx` (l.7-45, 114-117)
- `prise-inventaire-web/src/contexts/AuthContext.tsx` (l.58)
- `prise-inventaire-web/src/components/Layout.tsx` (l.79-119, 144-158)
- `prise-inventaire-web/src/i18n/translations.ts`
- `prise-inventaire-web/.env` · `prise-inventaire-web/.gitignore` · `prise-inventaire-web/vite.config.ts`

**Mobile**
- `prise-inventaire-android/app/build.gradle` (l.12-24, 32-33, 67)
- `prise-inventaire-android/app/src/main/java/com/telipso/fripandroid/api/InventaireApiService.kt`
- `prise-inventaire-android/app/src/main/java/com/telipso/fripandroid/EmployeLoginActivity.kt`
- `prise-inventaire-android/app/src/main/java/com/telipso/fripandroid/InventaireScanActivity.kt`
- `prise-inventaire-android/app/src/main/java/com/telipso/fripandroid/offline/` (SyncWorker, OfflineRepository)
- `prise-inventaire-android/app/src/main/res/xml/network_security_config.xml`
- `prise-inventaire-android/app/src/main/AndroidManifest.xml` (l.10)
- `prise-inventaire-android/version.properties` · `prise-inventaire-android/upload.sh`

**Marketing / transverse**
- `prise-inventaire-marketing/public/sw.js`
- `amplify.yml` (racine) + `prise-inventaire-web/amplify.yml`
- `docs/05-DEPLOIEMENT-AWS.md` + `docs/06-CONFIGURATION-AWS-TECHNIQUE.md`
- `.github/workflows/` (CI/CD réel, non documenté)
- `DEPLOYMENT_INFO.md` (l.48, 63, 72) · `prise-inventaire-v1.0.apk`

---

*Rapport généré à partir de 4 audits experts en lecture seule (backend, frontend, mobile, architecture). Aucune modification de code effectuée durant l'audit.*
