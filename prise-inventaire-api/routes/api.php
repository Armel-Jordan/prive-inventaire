<?php

use Illuminate\Support\Facades\DB;

// Health check — utilisé par le CI/CD et le monitoring
Route::get('/health', function () {
    try {
        DB::connection()->getPdo();

        return response()->json([
            'status' => 'ok',
            'database' => 'ok',
            'timestamp' => now()->toISOString(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'database' => 'unreachable',
            'timestamp' => now()->toISOString(),
        ], 503);
    }
});

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AlerteStockController;
use App\Http\Controllers\Api\BaliseController;
use App\Http\Controllers\Api\BonCommandePdfController;
use App\Http\Controllers\Api\BonLivraisonController;
use App\Http\Controllers\Api\CamionController;
use App\Http\Controllers\Api\ChampPersonnaliseController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientFicheAdresseController;
use App\Http\Controllers\Api\ClientFicheCategorieController;
use App\Http\Controllers\Api\ClientFicheContactController;
use App\Http\Controllers\Api\ClientFicheController;
use App\Http\Controllers\Api\ClientFicheRemarqueController;
use App\Http\Controllers\Api\CommandeClientController;
use App\Http\Controllers\Api\CommandeFournisseurController;
use App\Http\Controllers\Api\ConfigurationACompleterController;
use App\Http\Controllers\Api\ConfigurationController;
use App\Http\Controllers\Api\CourrielEnvoiController;
use App\Http\Controllers\Api\CourrielModeleController;
use App\Http\Controllers\Api\CourrielParametresController;
use App\Http\Controllers\Api\DashboardController as ApiDashboardController;
use App\Http\Controllers\Api\DevisController;
use App\Http\Controllers\Api\DocumentPdfController;
use App\Http\Controllers\Api\FactureController;
use App\Http\Controllers\Api\FournisseurController;
use App\Http\Controllers\Api\GroupeTaxeController;
use App\Http\Controllers\Api\LocalisationController;
use App\Http\Controllers\Api\PieceJointeController;
use App\Http\Controllers\Api\ReceptionController;
use App\Http\Controllers\Api\ReferentielController;
use App\Http\Controllers\Api\RemarqueRapideController;
use App\Http\Controllers\Api\TauxChangeController;
use App\Http\Controllers\Api\TenantParametresController;
use App\Http\Controllers\Api\TenantTaxeController;
use App\Http\Controllers\Api\TourneeController;
use App\Http\Controllers\Api\ZonePreparationController;
use App\Http\Controllers\ApprobationController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Comptabilite\CompteComptableController;
use App\Http\Controllers\Comptabilite\ExerciceComptableController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeTenantController;
use App\Http\Controllers\Inventaire\PriseInventaireController;
use App\Http\Controllers\InventaireTournantController;
use App\Http\Controllers\MouvementInventaireController;
use App\Http\Controllers\MouvementTenantController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Prix\CalculController as PrixCalculController;
use App\Http\Controllers\Prix\GrilleController as PrixGrilleController;
use App\Http\Controllers\Prix\ListeController as PrixListeController;
use App\Http\Controllers\Prix\RegleController as PrixRegleController;
use App\Http\Controllers\ProduitTenantController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RapportController;
use App\Http\Controllers\RechercheController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\ScanTenantController;
use App\Http\Controllers\SecteurController;
use App\Http\Controllers\Securite\DeuxFacteursController;
use App\Http\Controllers\Securite\MotDePasseController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\TachePlanifieController;
use App\Http\Controllers\TracabiliteController;
use App\Http\Controllers\TransfertPlanifieController;
use App\Support\Referentiels;
use Illuminate\Support\Facades\Route;

// ============================================
// Routes publiques (sans authentification)
// ============================================
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/super-admin/login', [SuperAdminController::class, 'login']);

// Sécurité des comptes : mot de passe oublié et seconde étape de connexion (double authentification).
// Chaque limite a son préfixe : sans lui, toutes les routes limitées partagent un même compteur par IP.
Route::post('/auth/mot-de-passe-oublie', [MotDePasseController::class, 'oublie'])->middleware('throttle:5,1,mot-de-passe-oublie');
Route::post('/auth/reinitialiser-mot-de-passe', [MotDePasseController::class, 'reinitialiser'])->middleware('throttle:10,1,reinitialisation-mot-de-passe');
Route::post('/auth/login/deux-facteurs', [AuthController::class, 'connexionDeuxFacteurs'])->middleware('throttle:10,1,connexion-deux-facteurs');

// PDF routes — auth via ?token= query param
Route::get('/commandes-fournisseur/{id}/pdf', [BonCommandePdfController::class, 'generate']);
Route::get('/commandes-fournisseur/{id}/pdf/preview', [BonCommandePdfController::class, 'preview']);

// ============================================
// Routes Mobile (sans authentification JWT)
// Ces routes sont utilisées par l'app Android
// ============================================
Route::prefix('mobile')->middleware(['auth:sanctum', 'tenant', 'tenant.context'])->group(function () {
    // Employés (lecture seule)
    Route::get('/employes', [EmployeTenantController::class, 'index']);

    // Secteurs (lecture seule)
    Route::get('/secteurs', [SecteurController::class, 'index']);
    Route::post('/secteurs/validate-qr', [SecteurController::class, 'validateQrCode']);

    // Produits
    Route::get('/produits', [ProduitTenantController::class, 'index']);
    Route::post('/produit/valider', [ProduitTenantController::class, 'valider']);

    // Scans
    Route::post('/scan/enregistrer', [ScanTenantController::class, 'enregistrer']);
    Route::get('/scan/historique', [ScanTenantController::class, 'historique']);
    Route::put('/scan/{id}', [ScanTenantController::class, 'modifier']);
    Route::delete('/scan/{id}', [ScanTenantController::class, 'supprimer']);

    // Relocalisation
    Route::get('/relocalisation', [MouvementTenantController::class, 'index']);
    Route::post('/relocalisation', [MouvementTenantController::class, 'store']);
});

// ============================================
// Routes Super Admin (gestion des tenants)
// ============================================
Route::prefix('super-admin')->middleware(['auth:sanctum', 'super-admin', 'tenant.context'])->group(function () {
    Route::get('/stats', [SuperAdminController::class, 'getStats']);
    Route::get('/tenants', [SuperAdminController::class, 'getTenants']);
    Route::post('/tenants', [SuperAdminController::class, 'createTenant']);
    Route::put('/tenants/{id}', [SuperAdminController::class, 'updateTenant']);
    Route::post('/tenants/{id}/renew', [SuperAdminController::class, 'renewTenant']);
    Route::delete('/tenants/{id}', [SuperAdminController::class, 'deleteTenant']);
    Route::get('/modules-catalog', [SuperAdminController::class, 'modulesCatalog']);
    Route::put('/tenants/{id}/modules', [SuperAdminController::class, 'updateTenantModules']);
    Route::get('/tenants/{tenantId}/admins', [SuperAdminController::class, 'getTenantAdmins']);
    Route::post('/tenants/{tenantId}/admins', [SuperAdminController::class, 'createTenantAdmin']);
    Route::put('/tenants/{tenantId}/admins/{adminId}', [SuperAdminController::class, 'updateTenantAdmin']);
    Route::delete('/tenants/{tenantId}/admins/{adminId}', [SuperAdminController::class, 'deleteTenantAdmin']);
});

// ============================================
// Routes authentifiées (utilisateur connecté)
// ============================================
Route::middleware(['auth:sanctum', 'tenant.context'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/complete-profile', [AuthController::class, 'completeProfile']);
    Route::post('/auth/photo', [AuthController::class, 'uploadPhoto']);

    // Sécurité du compte connecté : mot de passe et double authentification.
    Route::put('/auth/mot-de-passe', [MotDePasseController::class, 'changer'])->middleware('throttle:10,1,changement-mot-de-passe');
    Route::prefix('auth/deux-facteurs')->middleware('throttle:20,1,gestion-deux-facteurs')->group(function () {
        Route::get('/', [DeuxFacteursController::class, 'etat']);
        Route::post('/preparer', [DeuxFacteursController::class, 'preparer']);
        Route::post('/activer', [DeuxFacteursController::class, 'activer']);
        Route::post('/desactiver', [DeuxFacteursController::class, 'desactiver']);
        Route::post('/codes-secours', [DeuxFacteursController::class, 'regenererCodes']);
    });
});

// Administrateurs : double authentification des utilisateurs de l'entreprise (téléphone perdu).
Route::middleware(['auth:sanctum', 'tenant', 'tenant.context', 'role:admin'])->prefix('securite')->group(function () {
    Route::get('/utilisateurs', [DeuxFacteursController::class, 'utilisateurs']);
    Route::delete('/utilisateurs/{id}/deux-facteurs', [DeuxFacteursController::class, 'reinitialiserUtilisateur'])->whereNumber('id');
});

// ============================================
// Routes Tenant (avec middleware tenant)
// ============================================
Route::middleware(['auth:sanctum', 'tenant', 'tenant.context'])->group(function () {
    // Employés CRUD
    Route::get('/employes', [EmployeTenantController::class, 'index']);
    Route::post('/employes', [EmployeTenantController::class, 'store']);
    Route::get('/employes/{id}', [EmployeTenantController::class, 'show']);
    Route::put('/employes/{id}', [EmployeTenantController::class, 'update']);
    Route::delete('/employes/{id}', [EmployeTenantController::class, 'destroy']);
    Route::post('/employes/{id}/photo', [EmployeTenantController::class, 'uploadPhoto']);

    // Produits CRUD
    Route::get('/produits', [ProduitTenantController::class, 'index']);
    Route::post('/produits', [ProduitTenantController::class, 'store']);
    Route::get('/produits/{id}', [ProduitTenantController::class, 'show']);
    Route::put('/produits/{id}', [ProduitTenantController::class, 'update']);
    Route::delete('/produits/{id}', [ProduitTenantController::class, 'destroy']);

    // Dashboard stats (legacy)
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Dashboard agrégé — ventes, achats, inventaire, finance
    Route::get('/dashboard', [ApiDashboardController::class, 'index']);

    // Profil utilisateur
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/photo', [ProfileController::class, 'uploadPhoto']);

    // Permissions utilisateur
    Route::get('/permissions/me', [RolePermissionController::class, 'userPermissions']);
    Route::get('/permissions/modules', [RolePermissionController::class, 'modules']);

    // Gestion des rôles (admin uniquement) — enforcement backend
    Route::middleware('role:admin')->group(function () {
        Route::get('/roles-custom', [RolePermissionController::class, 'index']);
        Route::post('/roles-custom', [RolePermissionController::class, 'store']);
        Route::get('/roles-custom/{id}', [RolePermissionController::class, 'show']);
        Route::put('/roles-custom/{id}', [RolePermissionController::class, 'update']);
        Route::delete('/roles-custom/{id}', [RolePermissionController::class, 'destroy']);
        Route::post('/users/{userId}/assign-role', [RolePermissionController::class, 'assignRole']);
    });

    // Scans
    Route::post('/scan/enregistrer', [ScanTenantController::class, 'enregistrer']);
    Route::get('/scan/historique', [ScanTenantController::class, 'historique']);
    Route::get('/scan/stats', [ScanTenantController::class, 'stats']);
    Route::put('/scan/{id}', [ScanTenantController::class, 'modifier']);
    Route::delete('/scan/{id}', [ScanTenantController::class, 'supprimer']);

    // Secteurs CRUD
    Route::get('/secteurs', [SecteurController::class, 'index']);
    Route::post('/secteurs', [SecteurController::class, 'store']);
    Route::get('/secteurs/{id}', [SecteurController::class, 'show']);
    Route::put('/secteurs/{id}', [SecteurController::class, 'update']);
    Route::delete('/secteurs/{id}', [SecteurController::class, 'destroy']);
    Route::post('/secteurs/validate-qr', [SecteurController::class, 'validateQrCode']);
    Route::post('/secteurs/{id}/generate-qr', [SecteurController::class, 'generateQrCode']);
    Route::put('/secteurs/{id}/qr-code', [SecteurController::class, 'updateQrCode']);

    // Mouvements d'inventaire (ancien système)
    Route::get('/mouvements', [MouvementInventaireController::class, 'index']);
    Route::post('/mouvements', [MouvementInventaireController::class, 'store']);
    Route::get('/mouvements/{id}', [MouvementInventaireController::class, 'show']);
    Route::get('/mouvements/scan/{scanId}', [MouvementInventaireController::class, 'getByScan']);

    // Relocalisation (nouveau système)
    Route::prefix('relocalisation')->group(function () {
        Route::get('/', [MouvementTenantController::class, 'index']);
        Route::post('/', [MouvementTenantController::class, 'store']);
        Route::get('/stats', [MouvementTenantController::class, 'stats']);
        Route::get('/{id}', [MouvementTenantController::class, 'show']);
        Route::post('/par-secteur', [MouvementTenantController::class, 'relocalisationParSecteur']);
        Route::post('/arrivage-lot', [MouvementTenantController::class, 'arrivageLot']);
        Route::get('/produit/{numero}', [MouvementTenantController::class, 'historyByProduit']);
        Route::get('/secteur/{secteur}', [MouvementTenantController::class, 'historyBySecteur']);
    });

    // Alertes de stock
    Route::prefix('alertes')->group(function () {
        Route::get('/', [AlerteStockController::class, 'index']);
        Route::get('/stats', [AlerteStockController::class, 'stats']);
        Route::get('/previsions', [AlerteStockController::class, 'previsions']);
        Route::put('/produit/{id}/seuil', [AlerteStockController::class, 'updateSeuil']);
        Route::post('/seuils-batch', [AlerteStockController::class, 'updateSeuilsBatch']);
    });

    // Audit logs (historique des modifications)
    Route::prefix('audit')->group(function () {
        Route::get('/', [AuditLogController::class, 'index']);
        Route::get('/stats', [AuditLogController::class, 'stats']);
        Route::get('/{modelType}/{modelId}', [AuditLogController::class, 'history']);
    });

    // Pièces jointes des fiches (clients, fournisseurs, produits, employés) et des documents.
    Route::prefix('pieces-jointes')->group(function () {
        Route::get('/', [PieceJointeController::class, 'index']);
        Route::get('/categories', [PieceJointeController::class, 'categories']);
        Route::post('/', [PieceJointeController::class, 'store'])->middleware('throttle:60,1,pieces-jointes-ajout');
        Route::get('/{id}/fichier', [PieceJointeController::class, 'fichier'])->whereNumber('id');
        Route::put('/{id}', [PieceJointeController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [PieceJointeController::class, 'destroy'])->whereNumber('id');
    });

    // Transferts planifiés
    Route::prefix('transferts-planifies')->group(function () {
        Route::get('/', [TransfertPlanifieController::class, 'index']);
        Route::get('/stats', [TransfertPlanifieController::class, 'stats']);
        Route::get('/upcoming', [TransfertPlanifieController::class, 'upcoming']);
        Route::post('/', [TransfertPlanifieController::class, 'store']);
        Route::get('/{id}', [TransfertPlanifieController::class, 'show']);
        Route::put('/{id}', [TransfertPlanifieController::class, 'update']);
        Route::post('/{id}/execute', [TransfertPlanifieController::class, 'execute']);
        Route::post('/{id}/cancel', [TransfertPlanifieController::class, 'cancel']);
        Route::delete('/{id}', [TransfertPlanifieController::class, 'destroy']);
    });

    // Tâches planifiées (traitements automatiques) : réglage, exécution manuelle, historique.
    Route::prefix('taches-planifiees')->middleware('role:admin')->group(function () {
        Route::get('/', [TachePlanifieController::class, 'index']);
        Route::get('/historique', [TachePlanifieController::class, 'historique']);
        Route::put('/{id}', [TachePlanifieController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/executer', [TachePlanifieController::class, 'executer'])->whereNumber('id');
    });

    // Approbations (workflow pour gros mouvements)
    Route::prefix('approbations')->group(function () {
        Route::get('/', [ApprobationController::class, 'index']);
        Route::get('/stats', [ApprobationController::class, 'stats']);
        Route::get('/settings', [ApprobationController::class, 'getSettings']);
        Route::put('/settings', [ApprobationController::class, 'updateSettings']);
        Route::post('/', [ApprobationController::class, 'store']);
        Route::post('/{id}/approve', [ApprobationController::class, 'approve']);
        Route::post('/{id}/reject', [ApprobationController::class, 'reject']);
    });

    // Traçabilité produit
    Route::prefix('tracabilite')->group(function () {
        Route::get('/search', [TracabiliteController::class, 'search']);
        Route::get('/produit/{numero}', [TracabiliteController::class, 'produitHistory']);
        Route::get('/timeline/{numero}', [TracabiliteController::class, 'timeline']);
    });

    // Champs personnalisés des fiches et documents : définitions, valeurs, recherche par valeur.
    Route::prefix('champs-personnalises')->group(function () {
        Route::get('/', [ChampPersonnaliseController::class, 'index']);
        Route::post('/', [ChampPersonnaliseController::class, 'store']);
        Route::put('/ordre', [ChampPersonnaliseController::class, 'ordonner']);
        Route::get('/valeurs', [ChampPersonnaliseController::class, 'valeurs']);
        Route::put('/valeurs', [ChampPersonnaliseController::class, 'enregistrerValeurs']);
        Route::get('/recherche', [ChampPersonnaliseController::class, 'recherche']);
        Route::put('/{id}', [ChampPersonnaliseController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [ChampPersonnaliseController::class, 'destroy'])->whereNumber('id');
    });

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
        Route::post('/cleanup', [NotificationController::class, 'cleanup']);
    });

    // Configuration à compléter : mise en route vérifiée dans les données ; écarter un réglage : administrateurs.
    Route::prefix('configuration-a-completer')->group(function () {
        Route::get('/', [ConfigurationACompleterController::class, 'index']);
        Route::post('/{cle}/ignorer', [ConfigurationACompleterController::class, 'ignorer'])->where('cle', '[a-z_]+')->middleware('role:admin');
        Route::delete('/{cle}/ignorer', [ConfigurationACompleterController::class, 'reprendre'])->where('cle', '[a-z_]+')->middleware('role:admin');
    });

    // Rapports
    Route::prefix('rapports')->group(function () {
        Route::get('/mouvements-secteur', [RapportController::class, 'mouvementsParSecteur']);
        Route::get('/activite-employe', [RapportController::class, 'activiteParEmploye']);
        Route::get('/evolution-annuelle', [RapportController::class, 'evolutionAnnuelle']);
        Route::get('/top-produits', [RapportController::class, 'topProduits']);
    });

    // Balises de classement des fiches et documents.
    Route::prefix('balises')->group(function () {
        Route::get('/', [BaliseController::class, 'index']);
        Route::post('/', [BaliseController::class, 'store']);
        Route::get('/affectations', [BaliseController::class, 'affectations']);
        Route::put('/affectations', [BaliseController::class, 'enregistrerAffectations']);
        Route::get('/recherche', [BaliseController::class, 'recherche']);
        Route::put('/items/{itemId}', [BaliseController::class, 'modifierItem'])->whereNumber('itemId');
        Route::delete('/items/{itemId}', [BaliseController::class, 'supprimerItem'])->whereNumber('itemId');
        Route::put('/{id}', [BaliseController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [BaliseController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/items', [BaliseController::class, 'ajouterItem'])->whereNumber('id');
    });

    // Remarques rapides : textes réutilisables pour les notes.
    Route::prefix('remarques-rapides')->group(function () {
        Route::get('/', [RemarqueRapideController::class, 'index']);
        Route::post('/', [RemarqueRapideController::class, 'store']);
        Route::put('/{id}', [RemarqueRapideController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [RemarqueRapideController::class, 'destroy'])->whereNumber('id');
    });

    // Inventaire tournant
    Route::prefix('inventaire-tournant')->group(function () {
        Route::get('/suggestions', [InventaireTournantController::class, 'suggestions']);
        Route::get('/stats', [InventaireTournantController::class, 'stats']);
        Route::get('/planning', [InventaireTournantController::class, 'planning']);
        Route::get('/secteur/{secteur}', [InventaireTournantController::class, 'historiqueSecteur']);
    });

    // Prises d'inventaire : sessions de comptage par-dessus les scans, écarts, validation (inventaire officiel).
    Route::prefix('prises-inventaire')->group(function () {
        Route::get('/', [PriseInventaireController::class, 'index']);
        Route::get('/officiel', [PriseInventaireController::class, 'officiel']);
        Route::get('/{id}', [PriseInventaireController::class, 'show'])->whereNumber('id');
        Route::get('/{id}/lignes', [PriseInventaireController::class, 'lignes'])->whereNumber('id');
        Route::get('/{id}/export', [PriseInventaireController::class, 'exporter'])->whereNumber('id');
        Route::middleware('role:admin,manager')->group(function () {
            Route::post('/', [PriseInventaireController::class, 'store']);
            Route::put('/{id}/corrections', [PriseInventaireController::class, 'corriger'])->whereNumber('id');
            Route::post('/{id}/valider', [PriseInventaireController::class, 'valider'])->whereNumber('id');
            Route::post('/{id}/annuler', [PriseInventaireController::class, 'annuler'])->whereNumber('id');
        });
    });

    // Utilisateurs admin CRUD (admin uniquement)
    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::put('/users/{id}', [AdminUserController::class, 'update']);
        Route::delete('/users/{id}', [AdminUserController::class, 'destroy']);
    });

    // Recherche globale (en-tête) : fiches et documents de l'entreprise.
    Route::get('/recherche', RechercheController::class)->middleware('throttle:60,1,recherche-globale');

    // Rôles et permissions
    Route::prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'roles']);
        Route::get('/permissions', [RoleController::class, 'allPermissions']);
        Route::get('/my-permissions', [RoleController::class, 'myPermissions']);
        Route::get('/check', [RoleController::class, 'check']);
        Route::get('/{role}/permissions', [RoleController::class, 'permissions']);
    });

    // ============================================
    // Fournisseurs
    // ============================================
    Route::prefix('fournisseurs')->middleware('module:achats')->group(function () {
        Route::get('/', [FournisseurController::class, 'index']);
        Route::get('/actifs', [FournisseurController::class, 'listActifs']);
        Route::post('/', [FournisseurController::class, 'store']);
        Route::get('/{fournisseur}', [FournisseurController::class, 'show']);
        Route::put('/{fournisseur}', [FournisseurController::class, 'update']);
        Route::delete('/{fournisseur}', [FournisseurController::class, 'destroy']);
    });

    // ============================================
    // Commandes Fournisseurs
    // ============================================
    Route::prefix('commandes-fournisseur')->middleware('module:achats')->group(function () {
        Route::get('/', [CommandeFournisseurController::class, 'index']);
        Route::post('/', [CommandeFournisseurController::class, 'store']);
        Route::get('/{commande}', [CommandeFournisseurController::class, 'show']);
        Route::put('/{commande}', [CommandeFournisseurController::class, 'update']);
        Route::post('/{commande}/valider', [CommandeFournisseurController::class, 'valider']);
        Route::post('/{commande}/annuler', [CommandeFournisseurController::class, 'annuler']);
        Route::post('/{commande}/cloturer', [CommandeFournisseurController::class, 'cloturer']);
        Route::delete('/{commande}', [CommandeFournisseurController::class, 'destroy']);
    });

    // ============================================
    // Réceptions / Arrivages
    // ============================================
    Route::prefix('receptions')->middleware('module:achats')->group(function () {
        Route::get('/', [ReceptionController::class, 'index']);
        Route::get('/commandes-en-attente', [ReceptionController::class, 'commandesEnAttente']);
        Route::get('/commande/{commande}/lignes', [ReceptionController::class, 'lignesEnAttente']);
        Route::post('/', [ReceptionController::class, 'store']);
        Route::post('/multiple', [ReceptionController::class, 'receptionMultiple']);
    });

    // ============================================
    // GESTION CLIENTS & VENTES
    // ============================================

    // Devis
    Route::prefix('devis')->middleware('module:ventes')->group(function () {
        Route::get('/', [DevisController::class, 'index']);
        Route::post('/', [DevisController::class, 'store']);
        Route::get('/{devis}/pdf', [DocumentPdfController::class, 'devis']);
        Route::get('/{devis}', [DevisController::class, 'show']);
        Route::put('/{devis}', [DevisController::class, 'update']);
        Route::post('/{devis}/envoyer', [DevisController::class, 'envoyer']);
        Route::post('/{devis}/accepter', [DevisController::class, 'accepter']);
        Route::post('/{devis}/refuser', [DevisController::class, 'refuser']);
        Route::post('/{devis}/convertir', [DevisController::class, 'convertirEnCommande']);
        Route::delete('/{devis}', [DevisController::class, 'destroy']);
    });

    // Clients
    Route::prefix('clients')->middleware('module:ventes')->group(function () {
        Route::get('/', [ClientController::class, 'index']);
        Route::get('/actifs', [ClientController::class, 'actifs']);
        Route::get('/{id}', [ClientController::class, 'show']);
        Route::post('/', [ClientController::class, 'store']);
        Route::put('/{id}', [ClientController::class, 'update']);
        Route::delete('/{id}', [ClientController::class, 'destroy']);
        Route::get('/{id}/conditions-paiement', [ClientController::class, 'getConditionsPaiement']);
        Route::post('/{id}/conditions-paiement', [ClientController::class, 'setConditionsPaiement']);
    });

    // Fiche client complète : synthèse, historique d'achats, contacts, adresses, remarques, favori, catégorie.
    Route::prefix('clients/{client}')->where(['client' => '[0-9]+'])->middleware('module:ventes')->group(function () {
        Route::get('/fiche', [ClientFicheController::class, 'show']);
        Route::get('/synthese', [ClientFicheController::class, 'synthese']);
        Route::get('/historique-achats', [ClientFicheController::class, 'historique']);
        Route::put('/categorie', [ClientFicheController::class, 'categorie']);
        Route::post('/favori', [ClientFicheController::class, 'ajouterFavori']);
        Route::delete('/favori', [ClientFicheController::class, 'retirerFavori']);
        foreach (['contacts' => ClientFicheContactController::class, 'adresses' => ClientFicheAdresseController::class, 'remarques' => ClientFicheRemarqueController::class] as $liste => $controleur) {
            Route::get("/{$liste}", [$controleur, 'index']);
            Route::post("/{$liste}", [$controleur, 'store']);
            Route::put("/{$liste}/{id}", [$controleur, 'update'])->whereNumber('id');
            Route::delete("/{$liste}/{id}", [$controleur, 'destroy'])->whereNumber('id');
        }
    });

    // Catégories de clients : lecture pour tous, gestion par les administrateurs.
    Route::prefix('categories-clients')->middleware('module:ventes')->group(function () {
        Route::get('/', [ClientFicheCategorieController::class, 'index']);
        Route::post('/', [ClientFicheCategorieController::class, 'store'])->middleware('role:admin');
        Route::put('/{id}', [ClientFicheCategorieController::class, 'update'])->whereNumber('id')->middleware('role:admin');
        Route::delete('/{id}', [ClientFicheCategorieController::class, 'destroy'])->whereNumber('id')->middleware('role:admin');
    });

    // Commandes Clients
    Route::prefix('commandes-client')->middleware('module:ventes')->group(function () {
        Route::get('/', [CommandeClientController::class, 'index']);
        Route::get('/{id}', [CommandeClientController::class, 'show']);
        Route::get('/{id}/pdf', [DocumentPdfController::class, 'commande']);
        Route::post('/', [CommandeClientController::class, 'store']);
        Route::put('/{id}', [CommandeClientController::class, 'update']);
        Route::post('/{id}/soumettre', [CommandeClientController::class, 'soumettre']);
        Route::post('/{id}/accepter', [CommandeClientController::class, 'accepter']);
        Route::post('/{id}/refuser', [CommandeClientController::class, 'refuser']);
        Route::delete('/{id}', [CommandeClientController::class, 'destroy']);
    });

    // Factures
    Route::prefix('factures')->middleware('module:ventes')->group(function () {
        Route::get('/', [FactureController::class, 'index']);
        Route::get('/{id}', [FactureController::class, 'show']);
        Route::get('/{id}/pdf', [DocumentPdfController::class, 'facture']);
        Route::post('/', [FactureController::class, 'store']);
        Route::post('/commande/{commandeId}', [FactureController::class, 'creerDepuisCommande']);
        Route::post('/{id}/emettre', [FactureController::class, 'emettre']);
        Route::post('/{id}/paiement', [FactureController::class, 'enregistrerPaiement']);
        Route::post('/{id}/creer-bl', [FactureController::class, 'creerBonLivraison']);
        Route::delete('/{id}', [FactureController::class, 'destroy']);
    });

    // Bons de Livraison
    Route::prefix('bons-livraison')->middleware('module:ventes')->group(function () {
        Route::get('/', [BonLivraisonController::class, 'index']);
        Route::get('/{id}', [BonLivraisonController::class, 'show']);
        Route::get('/{id}/pdf', [DocumentPdfController::class, 'bonLivraison']);
        Route::post('/{id}/preparer', [BonLivraisonController::class, 'demarrerPreparation']);
        Route::put('/{id}/lignes', [BonLivraisonController::class, 'updateLignes']);
        Route::post('/{id}/pret', [BonLivraisonController::class, 'marquerPret']);
        Route::post('/{id}/livrer', [BonLivraisonController::class, 'enregistrerLivraison']);
        Route::post('/{id}/annuler', [BonLivraisonController::class, 'annuler']);
    });

    // Camions
    Route::prefix('camions')->middleware('module:ventes')->group(function () {
        Route::get('/', [CamionController::class, 'index']);
        Route::get('/disponibles', [CamionController::class, 'disponibles']);
        Route::get('/{id}', [CamionController::class, 'show']);
        Route::post('/', [CamionController::class, 'store']);
        Route::put('/{id}', [CamionController::class, 'update']);
        Route::delete('/{id}', [CamionController::class, 'destroy']);
    });

    // Tournées
    Route::prefix('tournees')->middleware('module:ventes')->group(function () {
        Route::get('/', [TourneeController::class, 'index']);
        Route::get('/{id}', [TourneeController::class, 'show']);
        Route::post('/', [TourneeController::class, 'store']);
        Route::delete('/{id}', [TourneeController::class, 'destroy']);
        Route::post('/{id}/ajouter-bon', [TourneeController::class, 'ajouterBon']);
        Route::delete('/{id}/bon/{bonId}', [TourneeController::class, 'retirerBon']);
        Route::put('/{id}/ordre', [TourneeController::class, 'updateOrdre']);
        Route::post('/{id}/demarrer', [TourneeController::class, 'demarrer']);
        Route::post('/{id}/terminer', [TourneeController::class, 'terminer']);
    });

    // Zones de Préparation
    Route::prefix('zones-preparation')->middleware('module:ventes')->group(function () {
        Route::get('/', [ZonePreparationController::class, 'index']);
        Route::get('/{id}', [ZonePreparationController::class, 'show']);
        Route::post('/', [ZonePreparationController::class, 'store']);
        Route::put('/{id}', [ZonePreparationController::class, 'update']);
        Route::delete('/{id}', [ZonePreparationController::class, 'destroy']);
    });

    // Prix et remises : listes de prix, grille par produit, règles (prix client, quantités, promotions), calcul.
    // Lecture pour le module Ventes ; modifications par les administrateurs et responsables.
    Route::middleware('module:ventes')->group(function () {
        Route::post('/prix/calculer', PrixCalculController::class);
        Route::get('/listes-prix', [PrixListeController::class, 'index']);
        Route::get('/listes-prix/{id}/clients', [PrixListeController::class, 'clients'])->whereNumber('id');
        Route::get('/grille-prix', [PrixGrilleController::class, 'index']);
        Route::get('/regles-prix', [PrixRegleController::class, 'index']);

        Route::middleware('role:admin,manager')->group(function () {
            Route::post('/listes-prix', [PrixListeController::class, 'store']);
            Route::put('/listes-prix/{id}', [PrixListeController::class, 'update'])->whereNumber('id');
            Route::delete('/listes-prix/{id}', [PrixListeController::class, 'destroy'])->whereNumber('id');
            Route::put('/clients/{client}/liste-prix', [PrixListeController::class, 'rattacherClient'])->whereNumber('client');
            Route::put('/grille-prix', [PrixGrilleController::class, 'update']);
            Route::post('/regles-prix', [PrixRegleController::class, 'store']);
            Route::put('/regles-prix/{id}', [PrixRegleController::class, 'update'])->whereNumber('id');
            Route::delete('/regles-prix/{id}', [PrixRegleController::class, 'destroy'])->whereNumber('id');
        });
    });

    // Comptabilité générale (module Finance) : plan comptable, exercices et périodes. Lecture pour le module ;
    // modifications par les administrateurs et responsables, réouverture d'une période par les administrateurs.
    Route::prefix('comptabilite')->middleware('module:finance')->group(function () {
        Route::get('/comptes', [CompteComptableController::class, 'index']);
        Route::get('/comptes/export', [CompteComptableController::class, 'exporter']);
        Route::get('/plans-types', [CompteComptableController::class, 'plans']);
        Route::get('/exercices', [ExerciceComptableController::class, 'index']);

        Route::middleware('role:admin,manager')->group(function () {
            Route::post('/comptes', [CompteComptableController::class, 'store']);
            Route::put('/comptes/{id}', [CompteComptableController::class, 'update'])->whereNumber('id');
            Route::delete('/comptes/{id}', [CompteComptableController::class, 'destroy'])->whereNumber('id');
            Route::post('/comptes/importer-plan', [CompteComptableController::class, 'importerPlan']);
            Route::post('/comptes/importer-fichier', [CompteComptableController::class, 'importerFichier']);
            Route::post('/exercices', [ExerciceComptableController::class, 'store']);
            Route::put('/exercices/{id}', [ExerciceComptableController::class, 'update'])->whereNumber('id');
            Route::delete('/exercices/{id}', [ExerciceComptableController::class, 'destroy'])->whereNumber('id');
            Route::post('/periodes/{id}/fermer', [ExerciceComptableController::class, 'fermerPeriode'])->whereNumber('id');
        });
        Route::post('/periodes/{id}/rouvrir', [ExerciceComptableController::class, 'rouvrirPeriode'])->whereNumber('id')->middleware('role:admin');
    });

    // Mouvements d'inventaire et Localisations
    Route::prefix('mouvements-inventaire')->group(function () {
        Route::get('/', [LocalisationController::class, 'mouvements']);
    });

    Route::prefix('localisations')->group(function () {
        Route::get('/produits', [LocalisationController::class, 'produits']);
        Route::get('/secteur/{id}', [LocalisationController::class, 'parSecteur']);
        Route::get('/camion/{id}', [LocalisationController::class, 'parCamion']);
        Route::get('/zone-preparation/{id}', [LocalisationController::class, 'parZonePreparation']);
    });

    Route::get('/produits/{id}/localisation', [LocalisationController::class, 'produitLocalisation']);
    Route::get('/produits/{id}/mouvements', [LocalisationController::class, 'mouvementsProduit']);

    // Paramètres généraux du tenant
    Route::get('/taux-change', [TauxChangeController::class, 'getTaux']);

    Route::get('/parametres', [TenantParametresController::class, 'show']);
    Route::put('/parametres', [TenantParametresController::class, 'update']);

    Route::prefix('taxes')->group(function () {
        Route::get('/', [TenantTaxeController::class, 'index']);
        Route::post('/', [TenantTaxeController::class, 'store']);
        Route::put('/{id}', [TenantTaxeController::class, 'update']);
        Route::delete('/{id}', [TenantTaxeController::class, 'destroy']);
    });

    // Référentiels génériques (unités, catégories, devises…) : un seul contrôleur,
    // piloté par le catalogue App\Support\Referentiels.
    Route::get('/referentiels/devises/{id}/historique', [ReferentielController::class, 'historiqueDevise'])->whereNumber('id');
    Route::prefix('referentiels/{type}')->where(['type' => implode('|', Referentiels::types())])->group(function () {
        Route::get('/', [ReferentielController::class, 'index']);
        Route::post('/', [ReferentielController::class, 'store']);
        Route::put('/{id}', [ReferentielController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [ReferentielController::class, 'destroy'])->whereNumber('id');
    });

    // Groupes de taxes (TPS + TVQ, TVA + CAC…) et calcul du détail des taxes sur un montant.
    Route::prefix('groupes-taxes')->group(function () {
        Route::get('/', [GroupeTaxeController::class, 'index']);
        Route::post('/', [GroupeTaxeController::class, 'store']);
        Route::put('/{id}', [GroupeTaxeController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [GroupeTaxeController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/calculer', [GroupeTaxeController::class, 'calculer'])->whereNumber('id');
    });

    // Courriels : paramètres d'envoi (serveur réservé aux administrateurs) et modèles par type et par langue.
    Route::prefix('courriels')->group(function () {
        Route::get('/catalogue', [CourrielModeleController::class, 'catalogue']);
        Route::get('/parametres', [CourrielParametresController::class, 'show']);
        Route::put('/parametres', [CourrielParametresController::class, 'update'])->middleware('role:admin');
        Route::post('/parametres/test', [CourrielParametresController::class, 'tester'])->middleware(['role:admin', 'throttle:5,1,courriel-test']);
        Route::get('/modeles', [CourrielModeleController::class, 'index']);
        Route::post('/modeles', [CourrielModeleController::class, 'store']);
        Route::post('/modeles/apercu', [CourrielModeleController::class, 'apercu']);
        Route::put('/modeles/{id}', [CourrielModeleController::class, 'update'])->whereNumber('id');
        Route::delete('/modeles/{id}', [CourrielModeleController::class, 'destroy'])->whereNumber('id');

        // Envoi des documents, file d'envoi et historique.
        Route::get('/preparer', [CourrielEnvoiController::class, 'preparer']);
        Route::get('/envois', [CourrielEnvoiController::class, 'index']);
        // Envois et relances partagent la même limite (30 courriels par minute), distincte des autres routes.
        Route::post('/envois', [CourrielEnvoiController::class, 'store'])->middleware('throttle:30,1,courriel-envoi');
        Route::get('/envois/{id}', [CourrielEnvoiController::class, 'show'])->whereNumber('id');
        Route::post('/envois/{id}/reessayer', [CourrielEnvoiController::class, 'reessayer'])->whereNumber('id')->middleware('throttle:30,1,courriel-envoi');
    });

    // Configuration des numéros
    Route::prefix('configurations')->group(function () {
        Route::get('/', [ConfigurationController::class, 'index']);
        Route::get('/{entite}', [ConfigurationController::class, 'show']);
        Route::put('/{entite}', [ConfigurationController::class, 'update']);
        Route::get('/{entite}/generer', [ConfigurationController::class, 'genererNumero']);
        Route::post('/{entite}/consommer', [ConfigurationController::class, 'consommerNumero']);
    });
});
