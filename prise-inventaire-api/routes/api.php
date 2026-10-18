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

use App\Http\Controllers\AccesCompteController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AlerteStockController;
use App\Http\Controllers\Api\AbonnementClientController;
use App\Http\Controllers\Api\AcompteController;
use App\Http\Controllers\Api\AvoirController;
use App\Http\Controllers\Api\BaliseController;
use App\Http\Controllers\Api\BonCommandePdfController;
use App\Http\Controllers\Api\BonLivraisonController;
use App\Http\Controllers\Api\CaisseController;
use App\Http\Controllers\Api\CamionController;
use App\Http\Controllers\Api\CarteCadeauController;
use App\Http\Controllers\Api\ChampPersonnaliseController;
use App\Http\Controllers\Api\ChargementTourneeController;
use App\Http\Controllers\Api\ChequeController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientFicheAdresseController;
use App\Http\Controllers\Api\ClientFicheCategorieController;
use App\Http\Controllers\Api\ClientFicheContactController;
use App\Http\Controllers\Api\ClientFicheController;
use App\Http\Controllers\Api\ClientFicheReferenceController;
use App\Http\Controllers\Api\ClientFicheRemarqueController;
use App\Http\Controllers\Api\ColisLivraisonController;
use App\Http\Controllers\Api\CommandeClientController;
use App\Http\Controllers\Api\CommandeFournisseurController;
use App\Http\Controllers\Api\CompteClientController;
use App\Http\Controllers\Api\ConfigurationACompleterController;
use App\Http\Controllers\Api\ConfigurationController;
use App\Http\Controllers\Api\ConsignationController;
use App\Http\Controllers\Api\ContratLocationController;
use App\Http\Controllers\Api\CourrielEnvoiController;
use App\Http\Controllers\Api\CourrielModeleController;
use App\Http\Controllers\Api\CourrielParametresController;
use App\Http\Controllers\Api\DashboardController as ApiDashboardController;
use App\Http\Controllers\Api\DebourseController;
use App\Http\Controllers\Api\DemandeAcompteController;
use App\Http\Controllers\Api\DevisController;
use App\Http\Controllers\Api\DisponibiliteController;
use App\Http\Controllers\Api\DocumentPdfController;
use App\Http\Controllers\Api\EncaissementController;
use App\Http\Controllers\Api\FactureController;
use App\Http\Controllers\Api\FactureFournisseurController;
use App\Http\Controllers\Api\FournisseurController;
use App\Http\Controllers\Api\FournisseurFicheAdresseController;
use App\Http\Controllers\Api\FournisseurFicheCompteController;
use App\Http\Controllers\Api\FournisseurFicheContactController;
use App\Http\Controllers\Api\FournisseurFicheController;
use App\Http\Controllers\Api\FournisseurFicheRemarqueController;
use App\Http\Controllers\Api\FraisController;
use App\Http\Controllers\Api\GroupeTaxeController;
use App\Http\Controllers\Api\ImportPrixFournisseurController;
use App\Http\Controllers\Api\LocalisationController;
use App\Http\Controllers\Api\PieceJointeController;
use App\Http\Controllers\Api\PointDeVenteController;
use App\Http\Controllers\Api\PortailClientController;
use App\Http\Controllers\Api\ReceptionController;
use App\Http\Controllers\Api\ReferentielController;
use App\Http\Controllers\Api\RelanceController;
use App\Http\Controllers\Api\ReliquatClientController;
use App\Http\Controllers\Api\RemarqueRapideController;
use App\Http\Controllers\Api\RetourClientController;
use App\Http\Controllers\Api\RetourFournisseurController;
use App\Http\Controllers\Api\SuggestionAchatController;
use App\Http\Controllers\Api\TauxChangeController;
use App\Http\Controllers\Api\TenantParametresController;
use App\Http\Controllers\Api\TenantTaxeController;
use App\Http\Controllers\Api\TourneeController;
use App\Http\Controllers\Api\VirementController;
use App\Http\Controllers\Api\ZonePreparationController;
use App\Http\Controllers\ApprobationController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Banque\CompteBancaireController;
use App\Http\Controllers\Banque\RapprochementBancaireController;
use App\Http\Controllers\Banque\ReleveBancaireController;
use App\Http\Controllers\Banque\TransactionBancaireController;
use App\Http\Controllers\Comptabilite\BudgetController;
use App\Http\Controllers\Comptabilite\ComptabilisationController;
use App\Http\Controllers\Comptabilite\CompteComptableController;
use App\Http\Controllers\Comptabilite\EcritureComptableController;
use App\Http\Controllers\Comptabilite\EtatComptableController;
use App\Http\Controllers\Comptabilite\ExerciceComptableController;
use App\Http\Controllers\Comptabilite\JournalComptableController;
use App\Http\Controllers\Comptabilite\RapportTaxesController;
use App\Http\Controllers\Comptabilite\RecurrenceComptableController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeTenantController;
use App\Http\Controllers\EtiquetteController;
use App\Http\Controllers\FiltreMemoriseController;
use App\Http\Controllers\Inventaire\LotController;
use App\Http\Controllers\Inventaire\NumeroSerieController;
use App\Http\Controllers\Inventaire\PriseInventaireController;
use App\Http\Controllers\Inventaire\StockEntrepotsController;
use App\Http\Controllers\Inventaire\ValorisationInventaireController;
use App\Http\Controllers\InventaireTournantController;
use App\Http\Controllers\MouvementInventaireController;
use App\Http\Controllers\MouvementTenantController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Portail\DevisEnLigneController;
use App\Http\Controllers\Portail\PortailAuthController;
use App\Http\Controllers\Portail\PortailCommandeController;
use App\Http\Controllers\Prix\CalculController as PrixCalculController;
use App\Http\Controllers\Prix\GrilleController as PrixGrilleController;
use App\Http\Controllers\Prix\ListeController as PrixListeController;
use App\Http\Controllers\Prix\RegleController as PrixRegleController;
use App\Http\Controllers\ProduitCodeBarreController;
use App\Http\Controllers\ProduitLienController;
use App\Http\Controllers\ProduitTenantController;
use App\Http\Controllers\ProduitUniteController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RapportBibliothequeController;
use App\Http\Controllers\RapportController;
use App\Http\Controllers\RapportIaController;
use App\Http\Controllers\RapportProgrammeController;
use App\Http\Controllers\RechercheController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\ScanTenantController;
use App\Http\Controllers\SecteurController;
use App\Http\Controllers\Securite\DeuxFacteursController;
use App\Http\Controllers\Securite\MotDePasseController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\SuperAdminSupportController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TachePlanifieController;
use App\Http\Controllers\TracabiliteController;
use App\Http\Controllers\TransfertPlanifieController;
use App\Http\Controllers\VerrouController;
use App\Http\Middleware\AuthentifierPortail;
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

// ============================================
// Routes Mobile : application Android (jeton Sanctum), mêmes droits que les adresses du back-office qu'elles
// reprennent (scans : module Inventaires, relocalisation : module Relocalisation).
// ============================================
Route::prefix('mobile')->middleware(['auth:sanctum', 'tenant', 'tenant.context', 'droits'])->group(function () {
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
    // Demandes de support et suggestions de toutes les entreprises.
    Route::get('/support', [SuperAdminSupportController::class, 'index']);
    Route::get('/support/{id}', [SuperAdminSupportController::class, 'show'])->whereNumber('id');
    Route::post('/support/{id}/messages', [SuperAdminSupportController::class, 'repondre'])->whereNumber('id');
    Route::put('/support/{id}', [SuperAdminSupportController::class, 'update'])->whereNumber('id');
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
// « droits » : écritures soumises à la matrice du rôle (créer, modifier, supprimer par module).
Route::middleware(['auth:sanctum', 'tenant', 'tenant.context', 'droits'])->group(function () {
    // Employés CRUD : lecture pour tous (listes de choix) ; écritures selon les droits du module Employés
    // (middleware « droits ») ; l'accès à l'application (rôle, mot de passe) est contrôlé dans le contrôleur.
    Route::get('/employes', [EmployeTenantController::class, 'index']);
    Route::get('/employes/{id}', [EmployeTenantController::class, 'show']);
    Route::post('/employes', [EmployeTenantController::class, 'store']);
    Route::put('/employes/{id}', [EmployeTenantController::class, 'update']);
    Route::delete('/employes/{id}', [EmployeTenantController::class, 'destroy']);
    Route::post('/employes/{id}/photo', [EmployeTenantController::class, 'uploadPhoto']);

    // Produits CRUD
    Route::get('/produits', [ProduitTenantController::class, 'index']);
    Route::post('/produits', [ProduitTenantController::class, 'store']);
    Route::get('/produits/{id}', [ProduitTenantController::class, 'show']);
    // Codes-barres d'un produit (EAN, UPC, Code 128, internes ; quantité par lecture).
    Route::get('/produits/{produit}/codes-barres', [ProduitCodeBarreController::class, 'index'])->whereNumber('produit');
    Route::post('/produits/{produit}/codes-barres', [ProduitCodeBarreController::class, 'store'])->whereNumber('produit');
    Route::put('/produits/{produit}/codes-barres/{id}', [ProduitCodeBarreController::class, 'update'])->whereNumber('produit')->whereNumber('id');
    Route::delete('/produits/{produit}/codes-barres/{id}', [ProduitCodeBarreController::class, 'destroy'])->whereNumber('produit')->whereNumber('id');
    // Unités de vente d'un produit (la caisse de 12…), avec leur facteur et leur prix.
    Route::get('/produits/{produit}/unites', [ProduitUniteController::class, 'index'])->whereNumber('produit');
    Route::post('/produits/{produit}/unites', [ProduitUniteController::class, 'store'])->whereNumber('produit');
    Route::put('/produits/{produit}/unites/{id}', [ProduitUniteController::class, 'update'])->whereNumber('produit')->whereNumber('id');
    Route::delete('/produits/{produit}/unites/{id}', [ProduitUniteController::class, 'destroy'])->whereNumber('produit')->whereNumber('id');
    // Liens d'un produit : composants (ensemble), annexes, équivalents.
    Route::get('/produits/{produit}/liens', [ProduitLienController::class, 'index'])->whereNumber('produit');
    Route::post('/produits/{produit}/liens', [ProduitLienController::class, 'store'])->whereNumber('produit');
    Route::put('/produits/{produit}/liens/{id}', [ProduitLienController::class, 'update'])->whereNumber('produit')->whereNumber('id');
    Route::delete('/produits/{produit}/liens/{id}', [ProduitLienController::class, 'destroy'])->whereNumber('produit')->whereNumber('id');
    Route::put('/produits/{id}', [ProduitTenantController::class, 'update'])->middleware('verrou:produit,id');
    Route::delete('/produits/{id}', [ProduitTenantController::class, 'destroy'])->middleware('verrou:produit,id');
    // Étiquettes des produits : formats (rouleau ou planche) et impression en PDF.
    Route::get('/etiquettes/formats', [EtiquetteController::class, 'formats']);
    Route::post('/etiquettes/formats', [EtiquetteController::class, 'store']);
    Route::put('/etiquettes/formats/{id}', [EtiquetteController::class, 'update'])->whereNumber('id');
    Route::delete('/etiquettes/formats/{id}', [EtiquetteController::class, 'destroy'])->whereNumber('id');
    Route::post('/etiquettes/imprimer', [EtiquetteController::class, 'imprimer']);

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
    Route::get('/permissions/catalogue', [RolePermissionController::class, 'catalogue']);

    // Gestion des rôles (admin uniquement) — enforcement backend
    Route::middleware('role:admin')->group(function () {
        Route::get('/roles-custom', [RolePermissionController::class, 'index']);
        Route::post('/roles-custom', [RolePermissionController::class, 'store']);
        Route::get('/roles-custom/{id}', [RolePermissionController::class, 'show']);
        Route::put('/roles-custom/{id}', [RolePermissionController::class, 'update']);
        Route::delete('/roles-custom/{id}', [RolePermissionController::class, 'destroy']);
        Route::post('/users/{userId}/assign-role', [RolePermissionController::class, 'assignRole']);
        // Accès d'un compte : groupes supplémentaires et exceptions, permission par permission.
        Route::get('/acces-comptes/{id}', [AccesCompteController::class, 'show'])->whereNumber('id');
        Route::put('/acces-comptes/{id}', [AccesCompteController::class, 'update'])->whereNumber('id');
    });

    // Scans
    Route::post('/scan/enregistrer', [ScanTenantController::class, 'enregistrer']);
    Route::get('/scan/historique', [ScanTenantController::class, 'historique']);
    Route::get('/scan/stats', [ScanTenantController::class, 'stats']);
    // Sur tout l'historique filtré (et non sur les 100 derniers scans) : export, statistiques, comparaison.
    Route::get('/scan/export', [ScanTenantController::class, 'exporter']);
    Route::get('/scan/statistiques', [ScanTenantController::class, 'statistiques']);
    Route::get('/scan/comparaison', [ScanTenantController::class, 'comparaison']);
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

    // Support et suggestions : écrire à l'éditeur, suivre ses demandes (module « support »).
    Route::prefix('support')->group(function () {
        Route::get('/', [SupportController::class, 'index']);
        Route::post('/', [SupportController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/{id}', [SupportController::class, 'show'])->whereNumber('id');
        Route::post('/{id}/messages', [SupportController::class, 'repondre'])->whereNumber('id')->middleware('throttle:30,1');
        Route::post('/{id}/fermer', [SupportController::class, 'fermer'])->whereNumber('id');
    });

    // Filtres mémorisés : ceux du compte connecté, par écran (données personnelles, sans droit de module).
    Route::prefix('filtres-memorises')->group(function () {
        Route::get('/', [FiltreMemoriseController::class, 'index']);
        Route::post('/', [FiltreMemoriseController::class, 'store']);
        Route::put('/{id}', [FiltreMemoriseController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [FiltreMemoriseController::class, 'destroy'])->whereNumber('id');
    });

    // Verrous des fiches en cours de modification : poser, renouveler et lever le sien (le contrôleur vérifie le droit
    // de modifier la fiche ; prendre la main : droit de libérer) ; les routes de mise à jour et de suppression des fiches
    // refusent un autre compte que celui qui la modifie (verrou:…). La liste et la libération suivent le module « verrous ».
    Route::get('/verrous', [VerrouController::class, 'index']);
    Route::post('/verrous', [VerrouController::class, 'poser'])->middleware('throttle:120,1');
    Route::delete('/verrous', [VerrouController::class, 'lever']);
    Route::delete('/verrous/{id}', [VerrouController::class, 'liberer'])->whereNumber('id');

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
        // Bibliothèque : catalogue des rapports (selon les droits du compte), un rapport à l'écran ou en fichier.
        Route::get('/bibliotheque', [RapportBibliothequeController::class, 'catalogue']);
        Route::get('/bibliotheque/{code}', [RapportBibliothequeController::class, 'executer'])->where('code', '[a-z0-9-]+');
        // Envoi d'un rapport par courriel (pré-remplissage, envoi) et envois programmés.
        Route::get('/bibliotheque/{code}/courriel', [RapportBibliothequeController::class, 'preparerCourriel'])->where('code', '[a-z0-9-]+');
        Route::post('/bibliotheque/{code}/courriel', [RapportBibliothequeController::class, 'envoyerCourriel'])->where('code', '[a-z0-9-]+')
            ->middleware('throttle:30,1,courriel-envoi');
        Route::get('/programmes', [RapportProgrammeController::class, 'index']);
        Route::post('/programmes', [RapportProgrammeController::class, 'store']);
        Route::put('/programmes/{id}', [RapportProgrammeController::class, 'update'])->whereNumber('id');
        Route::delete('/programmes/{id}', [RapportProgrammeController::class, 'destroy'])->whereNumber('id');
        Route::post('/programmes/{id}/envoyer', [RapportProgrammeController::class, 'envoyer'])->whereNumber('id')
            ->middleware('throttle:30,1,courriel-envoi');
        // Questions à l'IA sur les rapports (module « ia » de l'entreprise, clé de l'API sur le serveur).
        Route::get('/ia/questions', [RapportIaController::class, 'index']);
        Route::post('/ia/questions', [RapportIaController::class, 'store'])->middleware('throttle:10,1');
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
        // Valorisation de l'inventaire officiel (coûts) : droit « Valorisation » ; prises : « Gérer les prises d'inventaire ».
        Route::get('/valorisation', ValorisationInventaireController::class);
        Route::post('/', [PriseInventaireController::class, 'store']);
        Route::put('/{id}/corrections', [PriseInventaireController::class, 'corriger'])->whereNumber('id');
        Route::post('/{id}/valider', [PriseInventaireController::class, 'valider'])->whereNumber('id');
        Route::post('/{id}/annuler', [PriseInventaireController::class, 'annuler'])->whereNumber('id');
    });

    // Stock par entrepôt (multi-entrepôts) : droit « voir » des inventaires et scans.
    Route::get('/stock-entrepots', StockEntrepotsController::class);

    // Lots et numéros de série (registre, péremptions) : module « Lots et numéros de série ».
    Route::prefix('lots')->group(function () {
        Route::get('/', [LotController::class, 'index']);
        Route::get('/{id}', [LotController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [LotController::class, 'update'])->whereNumber('id');
        // Rappel : qui a reçu le lot (livraisons, ventes au comptoir) ; CSV.
        Route::get('/{id}/rappel', [LotController::class, 'rappel'])->whereNumber('id');
    });
    Route::get('/numeros-serie', [NumeroSerieController::class, 'index']);

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
        Route::put('/{fournisseur}', [FournisseurController::class, 'update'])->middleware('verrou:fournisseur,fournisseur');
        Route::delete('/{fournisseur}', [FournisseurController::class, 'destroy'])->middleware('verrou:fournisseur,fournisseur');
    });

    // Fiche fournisseur complète : synthèse des achats, historique, produits fournis, contacts, adresses,
    // coordonnées bancaires (saisie : permission « Gérer les coordonnées bancaires »), remarques.
    Route::prefix('fournisseurs/{fournisseur}')->where(['fournisseur' => '[0-9]+'])->middleware('module:achats')->group(function () {
        Route::get('/fiche', [FournisseurFicheController::class, 'show']);
        Route::get('/synthese', [FournisseurFicheController::class, 'synthese']);
        Route::get('/historique-achats', [FournisseurFicheController::class, 'historique']);
        Route::get('/produits', [FournisseurFicheController::class, 'produits']);
        Route::post('/produits', [FournisseurFicheController::class, 'ajouterProduit']);
        Route::put('/produits/{produit}', [FournisseurFicheController::class, 'modifierProduit'])->whereNumber('produit');
        Route::delete('/produits/{produit}', [FournisseurFicheController::class, 'retirerProduit'])->whereNumber('produit');
        foreach (['contacts' => FournisseurFicheContactController::class, 'adresses' => FournisseurFicheAdresseController::class, 'remarques' => FournisseurFicheRemarqueController::class] as $liste => $controleur) {
            Route::get("/{$liste}", [$controleur, 'index']);
            Route::post("/{$liste}", [$controleur, 'store']);
            Route::put("/{$liste}/{id}", [$controleur, 'update'])->whereNumber('id');
            Route::delete("/{$liste}/{id}", [$controleur, 'destroy'])->whereNumber('id');
        }
        Route::get('/comptes-bancaires', [FournisseurFicheCompteController::class, 'index']);
        Route::post('/comptes-bancaires', [FournisseurFicheCompteController::class, 'store']);
        Route::put('/comptes-bancaires/{id}', [FournisseurFicheCompteController::class, 'update'])->whereNumber('id');
        Route::delete('/comptes-bancaires/{id}', [FournisseurFicheCompteController::class, 'destroy'])->whereNumber('id');
    });

    // ============================================
    // Commandes Fournisseurs
    // ============================================
    Route::prefix('commandes-fournisseur')->middleware('module:achats')->group(function () {
        Route::get('/', [CommandeFournisseurController::class, 'index']);
        Route::post('/', [CommandeFournisseurController::class, 'store']);
        Route::get('/{commande}', [CommandeFournisseurController::class, 'show']);
        Route::get('/{id}/pdf', BonCommandePdfController::class)->whereNumber('id');
        Route::put('/{commande}', [CommandeFournisseurController::class, 'update'])->middleware('verrou:commande_fournisseur,commande');
        Route::post('/{commande}/valider', [CommandeFournisseurController::class, 'valider']);
        Route::post('/{commande}/annuler', [CommandeFournisseurController::class, 'annuler']);
        Route::post('/{commande}/cloturer', [CommandeFournisseurController::class, 'cloturer']);
        Route::delete('/{commande}', [CommandeFournisseurController::class, 'destroy'])->middleware('verrou:commande_fournisseur,commande');
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
        // Réceptions documentées (phase 3.3) : historique, détail, correction (date, références, coûts), annulation, PDF.
        Route::get('/documents', [ReceptionController::class, 'documents']);
        Route::get('/documents/{id}', [ReceptionController::class, 'document'])->whereNumber('id');
        Route::put('/documents/{id}', [ReceptionController::class, 'modifier'])->whereNumber('id');
        Route::post('/documents/{id}/annuler', [ReceptionController::class, 'annuler'])->whereNumber('id');
        Route::get('/documents/{id}/pdf', [ReceptionController::class, 'pdf'])->whereNumber('id');
    });

    // Factures fournisseurs (comptes à payer) : saisies à la main ou depuis des réceptions, rapprochées de la marchandise
    // reçue, payées jusqu'au reste dû.
    Route::prefix('factures-fournisseurs')->middleware('module:achats')->group(function () {
        Route::get('/', [FactureFournisseurController::class, 'index']);
        Route::get('/proposition', [FactureFournisseurController::class, 'proposition']);
        Route::post('/', [FactureFournisseurController::class, 'store']);
        Route::get('/{id}', [FactureFournisseurController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [FactureFournisseurController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/aligner-couts', [FactureFournisseurController::class, 'alignerCouts'])->whereNumber('id');
        Route::post('/{id}/paiements', [FactureFournisseurController::class, 'payer'])->whereNumber('id');
        Route::delete('/{id}/paiements/{paiement}', [FactureFournisseurController::class, 'annulerPaiement'])->whereNumber(['id', 'paiement']);
        Route::post('/{id}/annuler', [FactureFournisseurController::class, 'annuler'])->whereNumber('id');
    });

    // Déboursés : l'argent versé à un fournisseur en une fois, réparti sur plusieurs de ses factures (escomptes compris).
    Route::prefix('debourses')->middleware('module:achats')->group(function () {
        Route::get('/', [DebourseController::class, 'index']);
        Route::get('/a-payer', [DebourseController::class, 'aPayer']);
        Route::get('/paiements-dus', [DebourseController::class, 'paiementsDus']);
        Route::post('/lot', [DebourseController::class, 'payerLot']);
        // Chèques : numérotés sur le chéquier du compte bancaire, puis imprimés (un par page).
        Route::post('/cheques/numeroter', [ChequeController::class, 'numeroter']);
        Route::get('/cheques/pdf', [ChequeController::class, 'pdf']);
        // Virements : déboursés à transmettre, lots (fichier SEPA ou CSV), refus de la banque.
        Route::get('/virements', [VirementController::class, 'index']);
        Route::get('/virements/lots', [VirementController::class, 'lots']);
        Route::post('/virements/lots', [VirementController::class, 'store']);
        Route::get('/virements/lots/{id}/fichier', [VirementController::class, 'fichier'])->whereNumber('id');
        Route::post('/virements/lots/{id}/refuser', [VirementController::class, 'refuser'])->whereNumber('id');
        Route::post('/', [DebourseController::class, 'store']);
        Route::get('/{id}', [DebourseController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [DebourseController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/annuler', [DebourseController::class, 'annuler'])->whereNumber('id');
        Route::get('/{id}/pdf', [DebourseController::class, 'pdf'])->whereNumber('id');
    });

    // Retours fournisseurs : marchandise renvoyée (reçue d'une commande, rapportée par un client, ou sans origine),
    // expédiée puis close quand le fournisseur a répondu (crédit obtenu, remplacement reçu).
    Route::prefix('retours-fournisseurs')->middleware('module:achats')->group(function () {
        Route::get('/', [RetourFournisseurController::class, 'index']);
        Route::get('/depuis-commande/{id}', [RetourFournisseurController::class, 'depuisCommande'])->whereNumber('id');
        Route::get('/depuis-retour-client/{id}', [RetourFournisseurController::class, 'depuisRetourClient'])->whereNumber('id');
        // Produits suivis : lots et numéros de série qui peuvent repartir.
        Route::get('/exemplaires', [RetourFournisseurController::class, 'exemplaires']);
        Route::get('/{id}', [RetourFournisseurController::class, 'show'])->whereNumber('id');
        Route::get('/{id}/pdf', [RetourFournisseurController::class, 'pdf'])->whereNumber('id');
        Route::post('/', [RetourFournisseurController::class, 'store']);
        Route::put('/{id}', [RetourFournisseurController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/expedier', [RetourFournisseurController::class, 'expedier'])->whereNumber('id');
        Route::post('/{id}/cloturer', [RetourFournisseurController::class, 'cloturer'])->whereNumber('id');
        Route::delete('/{id}', [RetourFournisseurController::class, 'destroy'])->whereNumber('id');
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
        Route::put('/{devis}', [DevisController::class, 'update'])->middleware('verrou:devis,devis');
        Route::post('/{devis}/envoyer', [DevisController::class, 'envoyer']);
        Route::post('/{devis}/accepter', [DevisController::class, 'accepter']);
        Route::post('/{devis}/refuser', [DevisController::class, 'refuser']);
        Route::post('/{devis}/convertir', [DevisController::class, 'convertirEnCommande']);
        // Devis accepté facturé directement (sans commande), devis copié en nouveau brouillon.
        Route::post('/{devis}/facturer', [DevisController::class, 'facturer']);
        Route::post('/{devis}/copier', [DevisController::class, 'copier']);
        // Lien de réponse en ligne du devis (le partager revient à l'envoyer).
        Route::post('/{devis}/lien-reponse', [DevisController::class, 'lienReponse']);
        Route::delete('/{devis}', [DevisController::class, 'destroy'])->middleware('verrou:devis,devis');
    });

    // Clients
    Route::prefix('clients')->middleware('module:ventes')->group(function () {
        Route::get('/', [ClientController::class, 'index']);
        Route::get('/actifs', [ClientController::class, 'actifs']);
        // Fiche du client de passage, créée à sa première utilisation (ventes au comptoir).
        Route::get('/de-passage', [ClientController::class, 'dePassage']);
        Route::get('/{id}', [ClientController::class, 'show']);
        Route::post('/', [ClientController::class, 'store']);
        Route::put('/{id}', [ClientController::class, 'update'])->middleware('verrou:client,id');
        Route::delete('/{id}', [ClientController::class, 'destroy'])->middleware('verrou:client,id');
        Route::get('/{id}/conditions-paiement', [ClientController::class, 'getConditionsPaiement']);
        Route::post('/{id}/conditions-paiement', [ClientController::class, 'setConditionsPaiement']);
    });

    // Fiche client complète : synthèse, historique d'achats, contacts, adresses, remarques, favori, catégorie.
    Route::prefix('clients/{client}')->where(['client' => '[0-9]+'])->middleware('module:ventes')->group(function () {
        Route::get('/fiche', [ClientFicheController::class, 'show']);
        Route::get('/synthese', [ClientFicheController::class, 'synthese']);
        Route::get('/historique-achats', [ClientFicheController::class, 'historique']);
        Route::put('/categorie', [ClientFicheController::class, 'categorie']);
        // Références des produits chez le client : reprises sur ses documents et dans son espace client (droit de
        // modifier les clients, aussi pour en retirer une).
        Route::get('/references-produits', [ClientFicheReferenceController::class, 'index']);
        Route::post('/references-produits', [ClientFicheReferenceController::class, 'store']);
        Route::put('/references-produits/{id}', [ClientFicheReferenceController::class, 'update'])->whereNumber('id');
        Route::delete('/references-produits/{id}', [ClientFicheReferenceController::class, 'destroy'])->whereNumber('id');
        Route::post('/favori', [ClientFicheController::class, 'ajouterFavori']);
        Route::delete('/favori', [ClientFicheController::class, 'retirerFavori']);
        foreach (['contacts' => ClientFicheContactController::class, 'adresses' => ClientFicheAdresseController::class, 'remarques' => ClientFicheRemarqueController::class] as $liste => $controleur) {
            Route::get("/{$liste}", [$controleur, 'index']);
            Route::post("/{$liste}", [$controleur, 'store']);
            Route::put("/{$liste}/{id}", [$controleur, 'update'])->whereNumber('id');
            Route::delete("/{$liste}/{id}", [$controleur, 'destroy'])->whereNumber('id');
        }
    });

    // Catégories de clients : lecture pour tous, gestion avec la permission « Gérer les catégories de clients ».
    Route::prefix('categories-clients')->middleware('module:ventes')->group(function () {
        Route::get('/', [ClientFicheCategorieController::class, 'index']);
        Route::post('/', [ClientFicheCategorieController::class, 'store']);
        Route::put('/{id}', [ClientFicheCategorieController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [ClientFicheCategorieController::class, 'destroy'])->whereNumber('id');
    });

    // Commandes Clients
    Route::prefix('commandes-client')->middleware('module:ventes')->group(function () {
        Route::get('/', [CommandeClientController::class, 'index']);
        Route::get('/{id}', [CommandeClientController::class, 'show']);
        Route::get('/{id}/pdf', [DocumentPdfController::class, 'commande']);
        Route::post('/', [CommandeClientController::class, 'store']);
        Route::put('/{id}', [CommandeClientController::class, 'update'])->middleware('verrou:commande_client,id');
        Route::post('/{id}/soumettre', [CommandeClientController::class, 'soumettre']);
        Route::post('/{id}/accepter', [CommandeClientController::class, 'accepter']);
        Route::post('/{id}/refuser', [CommandeClientController::class, 'refuser']);
        Route::delete('/{id}', [CommandeClientController::class, 'destroy'])->middleware('verrou:commande_client,id');
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
        Route::get('/{id}/avoir', [AvoirController::class, 'proposition']);
        Route::get('/{id}/retour', [RetourClientController::class, 'proposition']);
        Route::get('/{id}/escompte', [FactureController::class, 'escompte']);
        Route::get('/{id}/relances', [RelanceController::class, 'deFacture']);
        Route::post('/{id}/relances', [RelanceController::class, 'store']);
        Route::delete('/{id}', [FactureController::class, 'destroy']);
    });

    // Notes de crédit (avoirs) : créditer une facture émise, imputer ou rembourser le crédit disponible.
    Route::prefix('avoirs')->middleware('module:ventes')->group(function () {
        Route::get('/', [AvoirController::class, 'index']);
        Route::get('/{id}', [AvoirController::class, 'show'])->whereNumber('id');
        Route::get('/{id}/pdf', [DocumentPdfController::class, 'avoir'])->whereNumber('id');
        Route::get('/{id}/factures-imputables', [AvoirController::class, 'facturesImputables'])->whereNumber('id');
        Route::post('/', [AvoirController::class, 'store']);
        Route::put('/{id}', [AvoirController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/emettre', [AvoirController::class, 'emettre'])->whereNumber('id');
        Route::post('/{id}/imputer', [AvoirController::class, 'imputer'])->whereNumber('id');
        Route::post('/{id}/rembourser', [AvoirController::class, 'rembourser'])->whereNumber('id');
        Route::delete('/{id}', [AvoirController::class, 'destroy'])->whereNumber('id');
    });

    // Disponibilité des produits : stock compté, réservé par les commandes acceptées, en commande, en reliquat.
    Route::get('/disponibilites', [DisponibiliteController::class, 'index'])->middleware('module:ventes');

    // Reliquats clients (back orders) : produits attendus faute de stock, commandés au fournisseur, facturés à réception.
    Route::prefix('reliquats-clients')->middleware('module:ventes')->group(function () {
        Route::get('/', [ReliquatClientController::class, 'index']);
        Route::get('/depuis-commande/{id}', [ReliquatClientController::class, 'lignesCommande'])->whereNumber('id');
        Route::post('/depuis-commande/{id}', [ReliquatClientController::class, 'depuisCommande'])->whereNumber('id');
        Route::post('/commander', [ReliquatClientController::class, 'commander']);
        Route::post('/facturer', [ReliquatClientController::class, 'facturer']);
        Route::post('/', [ReliquatClientController::class, 'store']);
        Route::put('/{id}', [ReliquatClientController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/annuler', [ReliquatClientController::class, 'annuler'])->whereNumber('id');
        Route::delete('/{id}', [ReliquatClientController::class, 'destroy'])->whereNumber('id');
    });

    // Abonnements clients : produits facturés à intervalle régulier, échéances, facturation des échéances dues.
    Route::prefix('abonnements-clients')->middleware('module:ventes')->group(function () {
        Route::get('/', [AbonnementClientController::class, 'index']);
        Route::post('/apercu', [AbonnementClientController::class, 'apercu']);
        Route::post('/facturer', [AbonnementClientController::class, 'facturer']);
        Route::post('/', [AbonnementClientController::class, 'store']);
        Route::get('/{id}', [AbonnementClientController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [AbonnementClientController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [AbonnementClientController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/echeances/{echeanceId}/relier', [AbonnementClientController::class, 'relier'])->whereNumber(['id', 'echeanceId']);
        // Facturation à l'usage : relevés de consommation (saisis ou importés).
        Route::post('/releves/import', [AbonnementClientController::class, 'importerReleves']);
        Route::get('/{id}/releves', [AbonnementClientController::class, 'releves'])->whereNumber('id');
        Route::post('/{id}/releves', [AbonnementClientController::class, 'ajouterReleve'])->whereNumber('id');
        Route::delete('/{id}/releves/{releveId}', [AbonnementClientController::class, 'supprimerReleve'])->whereNumber(['id', 'releveId']);
    });

    // Consignation chez le client : bons de dépôt, relevé, reprise, facturation de la consommation, historique, rapport.
    Route::prefix('consignations')->middleware('module:ventes')->group(function () {
        Route::get('/', [ConsignationController::class, 'index']);
        Route::post('/', [ConsignationController::class, 'store']);
        Route::get('/releve', [ConsignationController::class, 'releve']);
        Route::get('/historique', [ConsignationController::class, 'historique']);
        Route::get('/rapport', [ConsignationController::class, 'rapport']);
        Route::post('/reprise', [ConsignationController::class, 'reprise']);
        Route::post('/facturer', [ConsignationController::class, 'facturer']);
        Route::get('/{id}', [ConsignationController::class, 'show'])->whereNumber('id');
        Route::get('/{id}/pdf', [ConsignationController::class, 'pdf'])->whereNumber('id');
    });

    // Contrats de location : produits loués, prix de contrat et loyers, retours, renouvellement.
    Route::prefix('contrats-location')->middleware('module:ventes')->group(function () {
        Route::get('/', [ContratLocationController::class, 'index']);
        Route::post('/facturer-loyers', [ContratLocationController::class, 'facturerLoyers']);
        Route::post('/', [ContratLocationController::class, 'store']);
        Route::get('/{id}', [ContratLocationController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [ContratLocationController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [ContratLocationController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/facturer', [ContratLocationController::class, 'facturer'])->whereNumber('id');
        Route::post('/{id}/renouveler', [ContratLocationController::class, 'renouveler'])->whereNumber('id');
        Route::get('/{id}/pdf', [ContratLocationController::class, 'pdf'])->whereNumber('id');
        Route::post('/{id}/lignes/{ligneId}/retour', [ContratLocationController::class, 'retour'])->whereNumber(['id', 'ligneId']);
    });

    // Retours clients : marchandise rapportée, remise en stock ou mise au rebut, puis note de crédit du retour.
    Route::prefix('retours-clients')->middleware('module:ventes')->group(function () {
        Route::get('/', [RetourClientController::class, 'index']);
        // Produits suivis : lots et numéros de série que le client peut rapporter.
        Route::get('/exemplaires', [RetourClientController::class, 'exemplaires']);
        Route::get('/{id}', [RetourClientController::class, 'show'])->whereNumber('id');
        Route::post('/', [RetourClientController::class, 'store']);
        Route::put('/{id}', [RetourClientController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/traiter', [RetourClientController::class, 'traiter'])->whereNumber('id');
        Route::post('/{id}/crediter', [RetourClientController::class, 'crediter'])->whereNumber('id');
        Route::delete('/{id}', [RetourClientController::class, 'destroy'])->whereNumber('id');
    });

    // Acomptes : paiements reçus avant la facture, appliqués au paiement des factures ou remboursés.
    Route::prefix('acomptes')->middleware('module:ventes')->group(function () {
        Route::get('/', [AcompteController::class, 'index']);
        Route::get('/{id}', [AcompteController::class, 'show'])->whereNumber('id');
        Route::post('/', [AcompteController::class, 'store']);
        Route::put('/{id}', [AcompteController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [AcompteController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/appliquer', [AcompteController::class, 'appliquer'])->whereNumber('id');
        Route::post('/{id}/rembourser', [AcompteController::class, 'rembourser'])->whereNumber('id');
    });

    // Encaissements : l'argent reçu d'un client en une fois, réparti sur plusieurs de ses factures (surplus en acompte).
    Route::prefix('encaissements')->middleware('module:ventes')->group(function () {
        Route::get('/', [EncaissementController::class, 'index']);
        Route::get('/a-regler', [EncaissementController::class, 'aRegler']);
        Route::post('/', [EncaissementController::class, 'store']);
        Route::get('/{id}', [EncaissementController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [EncaissementController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/annuler', [EncaissementController::class, 'annuler'])->whereNumber('id');
        Route::get('/{id}/pdf', [EncaissementController::class, 'pdf'])->whereNumber('id');
    });

    // Demandes d'acompte : dépôt demandé au client ; la marquer payée enregistre l'acompte.
    Route::prefix('demandes-acompte')->middleware('module:ventes')->group(function () {
        Route::get('/', [DemandeAcompteController::class, 'index']);
        Route::get('/{id}', [DemandeAcompteController::class, 'show'])->whereNumber('id');
        Route::get('/{id}/pdf', [DocumentPdfController::class, 'demandeAcompte'])->whereNumber('id');
        Route::post('/', [DemandeAcompteController::class, 'store']);
        Route::put('/{id}', [DemandeAcompteController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [DemandeAcompteController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/envoyer', [DemandeAcompteController::class, 'envoyer'])->whereNumber('id');
        Route::post('/{id}/encaisser', [DemandeAcompteController::class, 'encaisser'])->whereNumber('id');
    });

    // Point de vente : recherche des produits, aperçu (prix du client, taxes), vente au comptoir encaissée, reçu.
    Route::prefix('point-de-vente')->middleware('module:ventes')->group(function () {
        Route::get('/produits', [PointDeVenteController::class, 'produits']);
        Route::post('/calculer', [PointDeVenteController::class, 'calculer']);
        Route::post('/ventes', [PointDeVenteController::class, 'vendre']);
        Route::get('/recus/{factureId}', [PointDeVenteController::class, 'recu'])->whereNumber('factureId');
        // Carte cadeau présentée pour payer : son solde.
        Route::get('/carte-cadeau', [PointDeVenteController::class, 'carteCadeau']);
    });

    // Cartes cadeaux : vente, recharge, remboursement du solde, certificat ; elles paient au point de vente et à l'encaissement.
    Route::prefix('cartes-cadeaux')->middleware('module:ventes')->group(function () {
        Route::get('/', [CarteCadeauController::class, 'index']);
        Route::post('/', [CarteCadeauController::class, 'store']);
        Route::get('/{id}', [CarteCadeauController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [CarteCadeauController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/recharger', [CarteCadeauController::class, 'recharger'])->whereNumber('id');
        Route::post('/{id}/rembourser', [CarteCadeauController::class, 'rembourser'])->whereNumber('id');
        Route::get('/{id}/certificat', [CarteCadeauController::class, 'certificat'])->whereNumber('id');
    });

    // Import d'une grille de prix fournisseur (simulation puis import) et profils d'import mémorisés.
    Route::post('/fournisseurs/import-prix', [ImportPrixFournisseurController::class, 'importer'])->middleware('module:achats');
    Route::prefix('profils-import')->middleware('module:achats')->group(function () {
        Route::get('/', [ImportPrixFournisseurController::class, 'profils']);
        Route::post('/', [ImportPrixFournisseurController::class, 'enregistrerProfil']);
        Route::delete('/{id}', [ImportPrixFournisseurController::class, 'supprimerProfil'])->whereNumber('id');
    });

    // Suggestions d'achat : stock sous le minimum et reliquats clients à commander, puis commandes fournisseur par fournisseur.
    Route::prefix('suggestions-achat')->middleware('module:achats')->group(function () {
        Route::get('/', [SuggestionAchatController::class, 'index']);
        Route::post('/', [SuggestionAchatController::class, 'store']);
        Route::post('/generer', [SuggestionAchatController::class, 'generer']);
        Route::post('/accepter', [SuggestionAchatController::class, 'accepter']);
        Route::post('/commander', [SuggestionAchatController::class, 'commander']);
        Route::put('/{id}', [SuggestionAchatController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [SuggestionAchatController::class, 'destroy'])->whereNumber('id');
    });

    // Gestion de caisse : ouverture avec le fond, entrées et sorties d'argent, fermeture (tiroir compté), sommaire.
    Route::prefix('caisses')->middleware('module:ventes')->group(function () {
        Route::get('/', [CaisseController::class, 'index']);
        Route::post('/{id}/ouvrir', [CaisseController::class, 'ouvrir'])->whereNumber('id');
        Route::get('/sessions', [CaisseController::class, 'sessions']);
        Route::get('/sessions/{id}', [CaisseController::class, 'show'])->whereNumber('id');
        Route::get('/sessions/{id}/pdf', [CaisseController::class, 'pdf'])->whereNumber('id');
        Route::post('/sessions/{id}/mouvements', [CaisseController::class, 'mouvement'])->whereNumber('id');
        Route::post('/sessions/{id}/fermer', [CaisseController::class, 'fermer'])->whereNumber('id');
    });

    // Relances de paiement : factures en retard, historique des relances.
    Route::prefix('relances')->middleware('module:ventes')->group(function () {
        Route::get('/', [RelanceController::class, 'index']);
        Route::get('/historique', [RelanceController::class, 'historique']);
    });

    // Comptes clients : analyse chronologique des sommes dues, relevé de compte d'un client.
    Route::prefix('comptes-clients')->middleware('module:ventes')->group(function () {
        Route::get('/', [CompteClientController::class, 'analyse']);
        Route::get('/{client}/releve', [CompteClientController::class, 'releve'])->whereNumber('client');
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
        // Lots et numéros de série à sortir à la livraison.
        Route::get('/{id}/exemplaires', [BonLivraisonController::class, 'exemplaires'])->whereNumber('id');
        Route::post('/{id}/annuler', [BonLivraisonController::class, 'annuler']);
        // Colis du bon (préparation des boîtes) : liste, préparation, modification, suppression.
        Route::get('/{id}/colis', [ColisLivraisonController::class, 'index'])->whereNumber('id');
        Route::post('/{id}/colis', [ColisLivraisonController::class, 'store'])->whereNumber('id');
        Route::put('/{id}/colis/{colisId}', [ColisLivraisonController::class, 'update'])->whereNumber(['id', 'colisId']);
        Route::delete('/{id}/colis/{colisId}', [ColisLivraisonController::class, 'destroy'])->whereNumber(['id', 'colisId']);
    });

    // Espace client, côté entreprise : réglages et accès des clients (droits du module Espace client).
    Route::prefix('portail-clients')->middleware('module:ventes')->group(function () {
        Route::get('/', [PortailClientController::class, 'index']);
        Route::put('/parametres', [PortailClientController::class, 'parametres']);
        Route::post('/comptes', [PortailClientController::class, 'store']);
        Route::put('/comptes/{id}', [PortailClientController::class, 'update'])->whereNumber('id');
        Route::post('/comptes/{id}/invitation', [PortailClientController::class, 'invitation'])->whereNumber('id');
        Route::delete('/comptes/{id}', [PortailClientController::class, 'destroy'])->whereNumber('id');
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
        // Document de transport des matières dangereuses à bord (PDF).
        Route::get('/{id}/matieres-dangereuses', [TourneeController::class, 'matieresDangereuses']);
        // Séquences de livraison : ranger les arrêts, ajouter les bons de la route, feuille de route du livreur.
        Route::post('/{id}/ordonner', [TourneeController::class, 'ordonner']);
        Route::post('/{id}/ajouter-route', [TourneeController::class, 'ajouterRoute']);
        Route::get('/{id}/feuille-de-route', [TourneeController::class, 'feuilleDeRoute']);
        // Chargement du camion : état, colis et vrac cochés, étiquette scannée, fiche de chargement.
        Route::get('/{id}/chargement', [ChargementTourneeController::class, 'show'])->whereNumber('id');
        Route::get('/{id}/chargement/pdf', [ChargementTourneeController::class, 'pdf'])->whereNumber('id');
        Route::post('/{id}/chargement/scan', [ChargementTourneeController::class, 'scanner'])->whereNumber('id');
        Route::post('/{id}/chargement/colis/{colisId}', [ChargementTourneeController::class, 'colis'])->whereNumber(['id', 'colisId']);
        Route::post('/{id}/chargement/vrac/{bonId}', [ChargementTourneeController::class, 'vrac'])->whereNumber(['id', 'bonId']);
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
    // Lecture pour le module Ventes ; modifications selon les droits du module Tarifs et prix.
    Route::middleware('module:ventes')->group(function () {
        Route::post('/prix/calculer', PrixCalculController::class);
        Route::get('/listes-prix', [PrixListeController::class, 'index']);
        Route::get('/listes-prix/{id}/clients', [PrixListeController::class, 'clients'])->whereNumber('id');
        Route::get('/grille-prix', [PrixGrilleController::class, 'index']);
        Route::get('/regles-prix', [PrixRegleController::class, 'index']);

        Route::post('/listes-prix', [PrixListeController::class, 'store']);
        Route::put('/listes-prix/{id}', [PrixListeController::class, 'update'])->whereNumber('id');
        Route::delete('/listes-prix/{id}', [PrixListeController::class, 'destroy'])->whereNumber('id');
        Route::put('/clients/{client}/liste-prix', [PrixListeController::class, 'rattacherClient'])->whereNumber('client');
        Route::put('/grille-prix', [PrixGrilleController::class, 'update']);
        Route::post('/regles-prix', [PrixRegleController::class, 'store']);
        Route::put('/regles-prix/{id}', [PrixRegleController::class, 'update'])->whereNumber('id');
        Route::delete('/regles-prix/{id}', [PrixRegleController::class, 'destroy'])->whereNumber('id');
    });

    // Comptabilité générale (module Finance) : plan comptable, exercices et périodes, journal général et états.
    // Lecture pour le module ; saisie et modifications par les administrateurs et responsables, réouverture d'une
    // période par les administrateurs.
    Route::prefix('comptabilite')->middleware('module:finance')->group(function () {
        Route::get('/comptes', [CompteComptableController::class, 'index']);
        Route::get('/comptes/export', [CompteComptableController::class, 'exporter']);
        Route::get('/plans-types', [CompteComptableController::class, 'plans']);
        Route::get('/exercices', [ExerciceComptableController::class, 'index']);
        Route::get('/exercices/{id}/cloture', [ExerciceComptableController::class, 'apercuCloture'])->whereNumber('id');
        Route::get('/journaux', [JournalComptableController::class, 'index']);
        Route::get('/ecritures', [EcritureComptableController::class, 'index']);
        Route::get('/ecritures/export', [EcritureComptableController::class, 'exporter']);
        Route::get('/ecritures/{id}', [EcritureComptableController::class, 'show'])->whereNumber('id');
        Route::get('/balance', [EtatComptableController::class, 'balance']);
        Route::get('/grand-livre', [EtatComptableController::class, 'grandLivre']);
        Route::get('/etats/bilan', [EtatComptableController::class, 'bilan']);
        Route::get('/etats/resultat', [EtatComptableController::class, 'compteResultat']);
        // Rapport des taxes : collectées sur les ventes, déductibles sur les achats (détail, par jour, total ; CSV, PDF).
        Route::get('/etats/taxes', RapportTaxesController::class);
        Route::get('/soldes-ouverture', [EtatComptableController::class, 'soldesOuverture']);
        Route::get('/recurrences', [RecurrenceComptableController::class, 'index']);
        Route::get('/comptabilisation/parametres', [ComptabilisationController::class, 'parametres']);
        Route::get('/comptabilisation/apercu', [ComptabilisationController::class, 'apercu']);

        // Écritures : permissions de la comptabilité (saisir des écritures, plan et exercices, clôturer), voir
        // App\Support\Droits. Lectures : droit de consulter la comptabilité, et de voir les états.
        Route::post('/journaux', [JournalComptableController::class, 'store']);
        Route::put('/journaux/{id}', [JournalComptableController::class, 'update'])->whereNumber('id');
        Route::delete('/journaux/{id}', [JournalComptableController::class, 'destroy'])->whereNumber('id');
        Route::post('/ecritures', [EcritureComptableController::class, 'store']);
        Route::put('/ecritures/{id}', [EcritureComptableController::class, 'update'])->whereNumber('id');
        Route::delete('/ecritures/{id}', [EcritureComptableController::class, 'destroy'])->whereNumber('id');
        Route::post('/ecritures/{id}/contre-passer', [EcritureComptableController::class, 'contrePasser'])->whereNumber('id');
        // Écritures récurrentes : planifier la répétition d'une écriture manuelle, convertir les dates échues.
        Route::post('/ecritures/{id}/recurrence', [RecurrenceComptableController::class, 'store'])->whereNumber('id');
        Route::post('/recurrences/convertir', [RecurrenceComptableController::class, 'convertir']);
        // Comptabilisation automatique des ventes, des achats et des paiements : réglages et lancement.
        Route::put('/comptabilisation/parametres', [ComptabilisationController::class, 'enregistrerParametres']);
        Route::post('/comptabilisation', [ComptabilisationController::class, 'comptabiliser']);
        Route::put('/recurrences/{id}', [RecurrenceComptableController::class, 'update'])->whereNumber('id');
        Route::delete('/recurrences/{id}', [RecurrenceComptableController::class, 'destroy'])->whereNumber('id');
        Route::put('/soldes-ouverture', [EtatComptableController::class, 'enregistrerSoldesOuverture']);
        Route::post('/comptes', [CompteComptableController::class, 'store']);
        Route::put('/comptes/{id}', [CompteComptableController::class, 'update'])->whereNumber('id');
        Route::delete('/comptes/{id}', [CompteComptableController::class, 'destroy'])->whereNumber('id');
        Route::post('/comptes/importer-plan', [CompteComptableController::class, 'importerPlan']);
        Route::post('/comptes/importer-fichier', [CompteComptableController::class, 'importerFichier']);
        Route::post('/exercices', [ExerciceComptableController::class, 'store']);
        Route::put('/exercices/{id}', [ExerciceComptableController::class, 'update'])->whereNumber('id');
        Route::delete('/exercices/{id}', [ExerciceComptableController::class, 'destroy'])->whereNumber('id');
        Route::post('/periodes/{id}/fermer', [ExerciceComptableController::class, 'fermerPeriode'])->whereNumber('id');
        Route::post('/periodes/{id}/rouvrir', [ExerciceComptableController::class, 'rouvrirPeriode'])->whereNumber('id');
        Route::post('/exercices/{id}/cloturer', [ExerciceComptableController::class, 'cloturer'])->whereNumber('id');
        Route::post('/exercices/{id}/rouvrir', [ExerciceComptableController::class, 'rouvrir'])->whereNumber('id');
    });

    // Banque : comptes bancaires reliés à leur compte comptable, mouvements, transactions bancaires sans facture.
    Route::prefix('banque')->middleware('module:finance')->group(function () {
        Route::get('/comptes', [CompteBancaireController::class, 'index']);
        Route::put('/comptes/{id}', [CompteBancaireController::class, 'update'])->whereNumber('id');
        Route::get('/comptes/{id}/mouvements', [CompteBancaireController::class, 'mouvements'])->whereNumber('id');
        Route::get('/transactions', [TransactionBancaireController::class, 'index']);
        Route::post('/transactions', [TransactionBancaireController::class, 'store']);
        Route::get('/transactions/{id}', [TransactionBancaireController::class, 'show'])->whereNumber('id');
        Route::post('/transactions/{id}/annuler', [TransactionBancaireController::class, 'annuler'])->whereNumber('id');
        // Rapprochement bancaire : relevés d'un compte, pointage des mouvements, fin, réouverture, rapport.
        Route::get('/comptes/{id}/rapprochements', [RapprochementBancaireController::class, 'index'])->whereNumber('id');
        Route::post('/comptes/{id}/rapprochements', [RapprochementBancaireController::class, 'store'])->whereNumber('id');
        Route::get('/rapprochements/{id}', [RapprochementBancaireController::class, 'show'])->whereNumber('id');
        Route::put('/rapprochements/{id}', [RapprochementBancaireController::class, 'update'])->whereNumber('id');
        Route::post('/rapprochements/{id}/pointer', [RapprochementBancaireController::class, 'pointer'])->whereNumber('id');
        Route::post('/rapprochements/{id}/terminer', [RapprochementBancaireController::class, 'terminer'])->whereNumber('id');
        Route::post('/rapprochements/{id}/rouvrir', [RapprochementBancaireController::class, 'rouvrir'])->whereNumber('id');
        Route::delete('/rapprochements/{id}', [RapprochementBancaireController::class, 'destroy'])->whereNumber('id');
        Route::get('/rapprochements/{id}/pdf', [RapprochementBancaireController::class, 'pdf'])->whereNumber('id');
        Route::post('/rapprochements/{id}/pointer-releve', [ReleveBancaireController::class, 'pointerRapprochement'])->whereNumber('id');
        // Relevés importés (CSV, OFX, CAMT) : fichiers, opérations, liens aux mouvements, classement.
        Route::get('/comptes/{id}/releve', [ReleveBancaireController::class, 'index'])->whereNumber('id');
        Route::get('/comptes/{id}/releves', [ReleveBancaireController::class, 'imports'])->whereNumber('id');
        Route::post('/comptes/{id}/releves', [ReleveBancaireController::class, 'store'])->whereNumber('id');
        Route::delete('/releves/{releve}', [ReleveBancaireController::class, 'destroy'])->whereNumber('releve');
        Route::post('/comptes/{id}/releve/relier-suggestions', [ReleveBancaireController::class, 'relierSuggestions'])->whereNumber('id');
        Route::get('/releve-lignes/{ligne}/candidats', [ReleveBancaireController::class, 'candidats'])->whereNumber('ligne');
        Route::post('/releve-lignes/{ligne}/relier', [ReleveBancaireController::class, 'relier'])->whereNumber('ligne');
        Route::post('/releve-lignes/{ligne}/delier', [ReleveBancaireController::class, 'delier'])->whereNumber('ligne');
        Route::post('/releve-lignes/{ligne}/exclure', [ReleveBancaireController::class, 'exclure'])->whereNumber('ligne');
        Route::post('/releve-lignes/{ligne}/retablir', [ReleveBancaireController::class, 'retablir'])->whereNumber('ligne');
        Route::post('/releve-lignes/{ligne}/classer', [ReleveBancaireController::class, 'classer'])->whereNumber('ligne');
    });

    // Budgets : feuille d'un exercice (comptes budgétables × périodes), saisie, répartition, copie, budget et réel.
    Route::prefix('budgets')->middleware('module:finance')->group(function () {
        Route::get('/', [BudgetController::class, 'index']);
        Route::put('/', [BudgetController::class, 'update']);
        Route::post('/repartir', [BudgetController::class, 'repartir']);
        Route::post('/copier', [BudgetController::class, 'copier']);
        Route::put('/comptes', [BudgetController::class, 'comptes']);
        Route::get('/comparaison', [BudgetController::class, 'comparaison']);
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

    // Frais additionnels et de livraison : réglage, et lignes de frais proposées pendant la saisie d'un document de vente.
    Route::prefix('frais')->middleware('module:ventes')->group(function () {
        Route::get('/', [FraisController::class, 'index']);
        Route::post('/calculer', [FraisController::class, 'calculer']);
        Route::post('/', [FraisController::class, 'store']);
        Route::put('/{id}', [FraisController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [FraisController::class, 'destroy'])->whereNumber('id');
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

// Portail client, accès public : page d'accueil, connexion, lien d'invitation ou de mot de passe oublié.
Route::prefix('portail/{slug}')->where(['slug' => '[A-Za-z0-9_-]+'])->group(function () {
    Route::get('/', [PortailAuthController::class, 'infos']);
    Route::post('/connexion', [PortailAuthController::class, 'connexion'])->middleware('throttle:10,1,portail-connexion');
    Route::post('/activation', [PortailAuthController::class, 'activation'])->middleware('throttle:10,1,portail-activation');
    Route::post('/mot-de-passe-oublie', [PortailAuthController::class, 'oublie'])->middleware('throttle:5,1,portail-oubli');
});

// Réponse en ligne à un devis, accès public par le lien du courriel : consulter, télécharger, accepter ou refuser.
Route::prefix('devis-en-ligne/{jeton}')->where(['jeton' => '[A-Za-z0-9]{64}'])->group(function () {
    Route::get('/', [DevisEnLigneController::class, 'show'])->middleware('throttle:60,1,devis-en-ligne');
    Route::get('/pdf', [DevisEnLigneController::class, 'pdf'])->middleware('throttle:20,1,devis-en-ligne-pdf');
    Route::post('/accepter', [DevisEnLigneController::class, 'accepter'])->middleware('throttle:10,1,devis-en-ligne-reponse');
    Route::post('/refuser', [DevisEnLigneController::class, 'refuser'])->middleware('throttle:10,1,devis-en-ligne-reponse');
});

// Espace du client connecté au portail : session du portail uniquement (un jeton d'employé n'y est pas reconnu).
Route::prefix('espace-client')->middleware(AuthentifierPortail::class)->group(function () {
    Route::post('/deconnexion', [PortailAuthController::class, 'deconnexion']);
    Route::get('/moi', [PortailAuthController::class, 'moi']);
    Route::put('/moi', [PortailAuthController::class, 'modifierProfil']);
    Route::put('/moi/mot-de-passe', [PortailAuthController::class, 'changerMotDePasse']);
    Route::get('/catalogue', [PortailCommandeController::class, 'catalogue']);
    Route::get('/produits/{id}/image', [PortailCommandeController::class, 'image'])->whereNumber('id');
    Route::post('/prix', [PortailCommandeController::class, 'prix']);
    Route::get('/commandes', [PortailCommandeController::class, 'index']);
    Route::post('/commandes', [PortailCommandeController::class, 'store'])->middleware('throttle:20,1,portail-commandes');
    Route::get('/commandes/{id}', [PortailCommandeController::class, 'show'])->whereNumber('id');
    Route::post('/commandes/{id}/annuler', [PortailCommandeController::class, 'annuler'])->whereNumber('id');
    Route::get('/commandes/{id}/pdf', [PortailCommandeController::class, 'pdfCommande'])->whereNumber('id');
    Route::get('/factures', [PortailCommandeController::class, 'factures']);
    Route::get('/factures/{id}/pdf', [PortailCommandeController::class, 'pdfFacture'])->whereNumber('id');
});
