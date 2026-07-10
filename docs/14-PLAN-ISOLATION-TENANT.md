# 14 — Plan d'implémentation : isolation multi-tenant (Phase 0 sécurité)

> **Statut** : plan proposé, en attente de validation + arbitrages.
> **Objectif** : refermer le cluster de failles d'isolation cross-tenant identifié dans `docs/13-AUDIT-TECHNIQUE.md` (§3.1–3.3, §3.8).
> **Méthode** : conçu via investigation parallèle + double revue adversariale (sécurité & régressions). Les corrections issues des revues sont intégrées ci-dessous.

---

## 0. Constat clé (issu des revues)

Le simple ajout d'un « Global Scope tenant » ferme les IDOR de **lecture par ID sur les modèles scopés**, mais **laisse ouvertes** des fuites que le scope Eloquent ne voit pas :

- Les règles de validation `exists:` (query builder brut) → on peut rattacher une entité à une FK d'un autre tenant.
- Les `update()` de lignes enfants par ID (`DevisLigne`, `ComFourLigne`…) → altération de données cross-tenant.
- Les requêtes `DB::table(...)` (Dashboard, `RolePermissionController`) → jamais scopées.
- Les règles `unique:` → fuite d'existence + blocage entre tenants.
- Les lignes existantes à `tenant_id = NULL` → deviennent invisibles dès le scope actif (régression de données).

**Conclusion** : Phase 0 = le trait **+ 7 chantiers compagnons obligatoires**. Le périmètre réel est plus large que « un trait ».

---

## 1. Mécanisme central

### 1.1 `TenantContext` (singleton container)
Source de vérité unique du tenant courant. Le scope ne lit **jamais** `auth()->user()->tenant_id` ni le slug directement — uniquement `TenantContext`. États : `tenantId` (int), `superAdmin` (bool), `resolved` (bool). Helpers `runAsTenant()` / `runWithoutTenant()` pour CLI/jobs.

### 1.2 `TenantScope` (classe nommée) + trait `BelongsToTenant`
- **Global scope en lecture** : filtre `WHERE tenant_id = :id` si tenant résolu ; désactivé si super-admin.
- **Hook `creating`** : injecte/écrase `tenant_id` (anti-spoofing du payload).
- **Règle NULL — DURCIE (correction revue, FAILLE 4/6)** : le scope consulte `isResolved()`.
  - Contexte **non résolu en HTTP** → **refus** (collection vide / 403), PAS « ne pas filtrer ». Évite les fuites sur routes non authentifiées.
  - Contexte non résolu marqué **CLI de confiance** (`runWithoutTenant`) → pas de filtre (voulu).
  - Écriture sans tenant résolu → `RuntimeException` (fail-loud, tue les inserts orphelins type `ScanTenant`).

### 1.3 Middleware `ResolveTenantContext` (après `auth:sanctum`)
- `SuperAdmin` → `markSuperAdmin()`.
- `AdminUser` (+ `tenant_id`) → `setTenantId(user->tenant_id)`. **Source = token, jamais le slug.**
- **Durcissement** : si `X-Tenant-Slug` présent et ≠ tenant du token → **403** (anti cross-slug).
- Appliqué aux groupes : web tenant, super-admin, **et `/auth/*` authentifié** (correction revue R/F : `/auth/me` en avait besoin).

---

## 2. Auth mobile réelle (décision : « vraie auth maintenant »)

Remplace le compromis « slug non authentifié ». On implémente une **authentification par token Sanctum scopé tenant** :

**API**
- L'`EmployeTenant` (ou un « device ») devient *tokenable* Sanctum, porteur de `tenant_id`.
- Migration : ajouter un secret de connexion à l'employé (PIN ou mot de passe hashé) — **à trancher (voir arbitrages)**.
- Endpoint `POST /mobile/auth/login` : `{ tenant_slug, identifiant_employe, secret }` → renvoie un token Sanctum.
- Groupe `/mobile/*` passe sous `['auth:sanctum', 'tenant.context']` (même mécanisme que le web ; le tenant vient du tokenable). Supprime le compromis slug.
- **Correction revue R2 (bloquant)** : réécrire le corps de `ProduitTenantController`/`EmployeTenantController` (`auth()->user()->tenant_id` → `TenantContext`), sinon 500 persistants.

**Android** (périmètre élargi, confirmé par toi)
- Écran de login (slug + employé + secret) ; stockage du token en `EncryptedSharedPreferences`.
- `InventaireApiService` : envoyer `Authorization: Bearer <token>` sur tous les appels ; gérer 401 (retour login).

---

## 3. Les 7 chantiers compagnons obligatoires (issus des revues)

| # | Chantier | Fichiers clés | Correctif |
|---|----------|---------------|-----------|
| C1 | **Règles `exists:` scopées** (FAILLE 2) | DevisController:44, CommandeClientController:50, FactureController:53/233, CommandeFournisseurController:56/127, ProduitTenantController:32/92, ReceptionController:49/98 | `Rule::exists(...)->where('tenant_id',$ctx)` |
| C2 | **`update()` de lignes enfants scopé au parent** (FAILLE 1/8) | DevisController:136, CommandeFournisseurController:152, ReceptionController:55/116 | `$parent->lignes()->where('id',$x)->update(...)` |
| C3 | **`ReceptionController::index` + `LocalisationController::mouvements`** (FAILLE 3/8) | ReceptionController:17, LocalisationController:89-119 | scoper via parent (`whereHas`) ou ajouter `tenant_id` |
| C4 | **`DB::table` Dashboard** (§4.3) | DashboardController:13-62 | `->where('tenant_id',$ctx)` manuel |
| C5 | **Règles `unique:` scopées** (FAILLE R5) | Secteur:25, ProduitTenant:26, EmployeTenant:28, Fournisseur, Camion, ZonePreparation | `Rule::unique(...)->where('tenant_id',$ctx)` |
| C6 | **Garde de rôle SuperAdmin** (FAILLE 7/F2) | routes/api.php:105 | middleware `ability:super-admin` ou `abort_unless(instanceof SuperAdmin)` |
| C7 | **`RolePermissionController` + `CheckPermission`** (FAILLE 5) | RolePermissionController:106-244, CheckPermission:31 | scoper par tenant + vérifier appartenance `userId`/`role_id` (dépend de l'arbitrage roles_custom) |

Plus le **câblage PDF** (FAILLE R4) : contexte posé dans `authenticateFromToken()` (couvre `generate` **et** `preview`), gestion explicite SuperAdmin / AdminUser sans tenant.

---

## 4. Backfill des données existantes (correction revue R3 — bloquant)

Avant d'activer le trait sur une table, **auditer et backfiller** les lignes `tenant_id IS NULL` :
- Les tables déjà pourvues de `tenant_id` (nullable) n'ont besoin que du backfill.
- **Tables sans colonne `tenant_id` (migration à créer — CONFIRMÉ Étape 0)** : `produits`, `employes`, `secteurs`, `notifications`, `mouvements_inventaire` (pluriel / modèle `MouvementVente`), `roles_custom`. Note : après ajout sur `produits`, recréer l'index `produits_tenant_deleted_at_index` (sauté par `add_dashboard_indexes` faute de colonne à l'époque).
- `fournisseurs` **a déjà la colonne** (correction F1 confirmée) → backfill uniquement.
- Ambiguïté mouvement tranchée : `mouvement_inventaire` (singulier, `MouvementInventaire`) et `mouvement_relocalisation` (`MouvementTenant`) ont déjà `tenant_id` ; seule `mouvements_inventaire` (pluriel, `MouvementVente`, utilisée par `LocalisationController`) est à migrer.
- Pour chaque : `SELECT count(*) WHERE tenant_id IS NULL` → si mono-tenant en prod, `UPDATE ... SET tenant_id = <seul tenant>`. **Sinon les lignes disparaissent des lectures.**

---

## 5. Harnais de test (correction revue R1 — bloquant, Étape 0-bis)

Le §8 de la spec est **inapplicable en l'état** : `phpunit.xml` force SQLite `:memory:` mais 12 modèles hardcodent `$connection = 'mysql'`, il n'existe aucune factory métier, ni `RefreshDatabase`, ni helper `actingAsTenant`. À créer d'abord :
- Stratégie de connexion en test (neutraliser `$connection` ou base MySQL de test).
- Factories des modèles clés + `RefreshDatabase` + helper `actingAsTenant()`.
- Puis la suite d'isolation : scope unitaire, index/show/update/destroy 404 cross-tenant, PDF, super-admin bypass, cross-slug 403, mobile, anti-spoofing création.

---

## 6. Ordre d'implémentation sécurisé (révisé)

1. **Étape 0** — Vérif schéma réel (`SHOW COLUMNS`) → liste définitive des colonnes/migrations manquantes.
2. **Étape 0-bis** — Harnais de test (§5). *Bloquant.*
3. **Étape 1** — Infra sans effet : `TenantContext`, `TenantScope`, trait, middlewares, alias. Aucun modèle ne l'utilise encore. Zéro régression.
4. **Étape 2** — Câbler le contexte (groupes web/super-admin/auth) + PDF + **durcissement 403 cross-slug livré ici** (correction : pas en dernier). Scope encore inactif.
5. **Étape 3** — Migrations `tenant_id` manquantes + **backfill obligatoire** (§4) sur toutes les tables concernées.
6. **Étape 4** — Appliquer le trait par lots (lecture pure → inventaire → ventes → `AdminUser`), en lançant les tests d'isolation après chaque lot.
7. **Étape 5** — Chantiers compagnons C1–C5 (exists, update lignes, DB::table, unique) + C3.
8. **Étape 6** — Auth mobile réelle (API + Android) + réécriture contrôleurs mobiles (R2).
9. **Étape 7** — C6 (garde super-admin) + C7 (roles/permissions selon arbitrage).

---

## 7. Arbitrages — TRANCHÉS

1. **`roles_custom` → rôles par tenant.** Migration ajoutant `tenant_id` nullable (NULL = rôle système partagé, renseigné = rôle custom du tenant) + scoping de `CheckPermission` et `RolePermissionController` (dont `assignRole` : vérifier l'appartenance du `userId` et du `role_id` au tenant courant). *Raison : fermer l'escalade de privilèges cross-tenant.*
2. **Secret d'auth mobile → mot de passe.** Mot de passe hashé par employé (colonne dédiée). Concerne la 2e livraison (mobile).
3. **Table `mouvements_inventaire` vs `mouvement_inventaire`** : à confirmer par moi en Étape 0 (inspection schéma) avant scoping/migration.
4. **Livraison → noyau d'abord, mobile ensuite.**
   - **Livraison 1 (noyau)** : harnais de test → `TenantContext`/`TenantScope`/trait/middlewares → câblage contexte + durcissement 403 → migrations + backfill → application du trait par lots → chantiers compagnons C1–C6 + PDF + C7 (rôles).
   - **Livraison 2 (mobile)** : auth mobile réelle par mot de passe (API + Android) + réécriture des contrôleurs mobiles.

---

## 8. Risques résiduels connus (hors périmètre immédiat, à tracer)

- Lignes enfants sans `tenant_id` : sécurité dépend du scoping du parent (couvert par C2 pour les cas d'update trouvés ; audit contrôleur-par-contrôleur en suivi).
- Connexion `tenant` legacy de `TenantService` (multi-DB jamais utilisée) : à déprécier dans un chantier séparé.
- Modèle `User` (table Laravel) exempté : à traiter si des users applicatifs multi-tenant existent réellement.

---

*Plan consolidé à partir de : spec architecte + revue adversariale sécurité (8 failles) + revue adversariale régressions (5 régressions, 3 affirmations corrigées). Détail brut disponible dans le transcript du workflow `design-global-scope-tenant`.*
