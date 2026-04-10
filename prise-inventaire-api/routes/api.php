<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\EmployeController;
use App\Http\Controllers\EmployeTenantController;
use App\Http\Controllers\MouvementInventaireController;
use App\Http\Controllers\MouvementTenantController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\ProduitTenantController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\ScanTenantController;
use App\Http\Controllers\SecteurController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\AlerteStockController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\TransfertPlanifieController;
use App\Http\Controllers\ApprobationController;
use App\Http\Controllers\TracabiliteController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RapportController;
use App\Http\Controllers\InventaireTournantController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\Api\FournisseurController;
use App\Http\Controllers\Api\CommandeFournisseurController;
use App\Http\Controllers\Api\ReceptionController;
use App\Http\Controllers\Api\BonCommandePdfController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CommandeClientController;
use App\Http\Controllers\Api\FactureController;
use App\Http\Controllers\Api\BonLivraisonController;
use App\Http\Controllers\Api\CamionController;
use App\Http\Controllers\Api\TourneeController;
use App\Http\Controllers\Api\ZonePreparationController;
use App\Http\Controllers\Api\LocalisationController;
use App\Http\Controllers\Api\DevisController;
use App\Http\Controllers\Api\ConfigurationController;
use App\Http\Controllers\Api\TenantParametresController;
use App\Http\Controllers\Api\TenantTaxeController;
use App\Http\Controllers\Api\TauxChangeController;
use Illuminate\Support\Facades\Route;

// ============================================
// Routes publiques (sans authentification)
// ============================================
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/super-admin/login', [SuperAdminController::class, 'login']);

// PDF routes — auth via ?token= query param
Route::get('/commandes-fournisseur/{id}/pdf', [BonCommandePdfController::class, 'generate']);
Route::get('/commandes-fournisseur/{id}/pdf/preview', [BonCommandePdfController::class, 'preview']);

// ============================================
// Routes Mobile (sans authentification JWT)
// Ces routes sont utilisées par l'app Android
// ============================================
Route::prefix('mobile')->group(function () {
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
Route::prefix('super-admin')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/stats', [SuperAdminController::class, 'getStats']);
    Route::get('/tenants', [SuperAdminController::class, 'getTenants']);
    Route::post('/tenants', [SuperAdminController::class, 'createTenant']);
    Route::put('/tenants/{id}', [SuperAdminController::class, 'updateTenant']);
    Route::post('/tenants/{id}/renew', [SuperAdminController::class, 'renewTenant']);
    Route::delete('/tenants/{id}', [SuperAdminController::class, 'deleteTenant']);
    Route::get('/tenants/{tenantId}/admins', [SuperAdminController::class, 'getTenantAdmins']);
    Route::post('/tenants/{tenantId}/admins', [SuperAdminController::class, 'createTenantAdmin']);
    Route::put('/tenants/{tenantId}/admins/{adminId}', [SuperAdminController::class, 'updateTenantAdmin']);
    Route::delete('/tenants/{tenantId}/admins/{adminId}', [SuperAdminController::class, 'deleteTenantAdmin']);
});

// ============================================
// Routes authentifiées (utilisateur connecté)
// ============================================
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/complete-profile', [AuthController::class, 'completeProfile']);
    Route::post('/auth/photo', [AuthController::class, 'uploadPhoto']);
});

// ============================================
// Routes Tenant (avec middleware tenant)
// ============================================
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
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

    // Dashboard stats
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Profil utilisateur
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/photo', [ProfileController::class, 'uploadPhoto']);

    // Permissions utilisateur
    Route::get('/permissions/me', [RolePermissionController::class, 'userPermissions']);
    Route::get('/permissions/modules', [RolePermissionController::class, 'modules']);

    // Gestion des rôles (admin seulement)
    Route::get('/roles-custom', [RolePermissionController::class, 'index']);
    Route::post('/roles-custom', [RolePermissionController::class, 'store']);
    Route::get('/roles-custom/{id}', [RolePermissionController::class, 'show']);
    Route::put('/roles-custom/{id}', [RolePermissionController::class, 'update']);
    Route::delete('/roles-custom/{id}', [RolePermissionController::class, 'destroy']);
    Route::post('/users/{userId}/assign-role', [RolePermissionController::class, 'assignRole']);

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
        Route::put('/produit/{id}/seuil', [AlerteStockController::class, 'updateSeuil']);
        Route::post('/seuils-batch', [AlerteStockController::class, 'updateSeuilsBatch']);
    });

    // Audit logs (historique des modifications)
    Route::prefix('audit')->group(function () {
        Route::get('/', [AuditLogController::class, 'index']);
        Route::get('/stats', [AuditLogController::class, 'stats']);
        Route::get('/{modelType}/{modelId}', [AuditLogController::class, 'history']);
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

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
        Route::post('/cleanup', [NotificationController::class, 'cleanup']);
    });

    // Rapports
    Route::prefix('rapports')->group(function () {
        Route::get('/mouvements-secteur', [RapportController::class, 'mouvementsParSecteur']);
        Route::get('/activite-employe', [RapportController::class, 'activiteParEmploye']);
        Route::get('/evolution-annuelle', [RapportController::class, 'evolutionAnnuelle']);
        Route::get('/top-produits', [RapportController::class, 'topProduits']);
    });

    // Inventaire tournant
    Route::prefix('inventaire-tournant')->group(function () {
        Route::get('/suggestions', [InventaireTournantController::class, 'suggestions']);
        Route::get('/stats', [InventaireTournantController::class, 'stats']);
        Route::get('/planning', [InventaireTournantController::class, 'planning']);
        Route::get('/secteur/{secteur}', [InventaireTournantController::class, 'historiqueSecteur']);
    });

    // Utilisateurs admin CRUD
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::post('/users', [AdminUserController::class, 'store']);
    Route::put('/users/{id}', [AdminUserController::class, 'update']);
    Route::delete('/users/{id}', [AdminUserController::class, 'destroy']);

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
    Route::prefix('fournisseurs')->group(function () {
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
    Route::prefix('commandes-fournisseur')->group(function () {
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
    Route::prefix('receptions')->group(function () {
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
    Route::prefix('devis')->group(function () {
        Route::get('/', [DevisController::class, 'index']);
        Route::post('/', [DevisController::class, 'store']);
        Route::get('/{devis}', [DevisController::class, 'show']);
        Route::put('/{devis}', [DevisController::class, 'update']);
        Route::post('/{devis}/envoyer', [DevisController::class, 'envoyer']);
        Route::post('/{devis}/accepter', [DevisController::class, 'accepter']);
        Route::post('/{devis}/refuser', [DevisController::class, 'refuser']);
        Route::post('/{devis}/convertir', [DevisController::class, 'convertirEnCommande']);
        Route::delete('/{devis}', [DevisController::class, 'destroy']);
    });

    // Clients
    Route::prefix('clients')->group(function () {
        Route::get('/', [ClientController::class, 'index']);
        Route::get('/actifs', [ClientController::class, 'actifs']);
        Route::get('/{id}', [ClientController::class, 'show']);
        Route::post('/', [ClientController::class, 'store']);
        Route::put('/{id}', [ClientController::class, 'update']);
        Route::delete('/{id}', [ClientController::class, 'destroy']);
        Route::get('/{id}/conditions-paiement', [ClientController::class, 'getConditionsPaiement']);
        Route::post('/{id}/conditions-paiement', [ClientController::class, 'setConditionsPaiement']);
    });

    // Commandes Clients
    Route::prefix('commandes-client')->group(function () {
        Route::get('/', [CommandeClientController::class, 'index']);
        Route::get('/{id}', [CommandeClientController::class, 'show']);
        Route::post('/', [CommandeClientController::class, 'store']);
        Route::put('/{id}', [CommandeClientController::class, 'update']);
        Route::post('/{id}/soumettre', [CommandeClientController::class, 'soumettre']);
        Route::post('/{id}/accepter', [CommandeClientController::class, 'accepter']);
        Route::post('/{id}/refuser', [CommandeClientController::class, 'refuser']);
        Route::delete('/{id}', [CommandeClientController::class, 'destroy']);
    });

    // Factures
    Route::prefix('factures')->group(function () {
        Route::get('/', [FactureController::class, 'index']);
        Route::get('/{id}', [FactureController::class, 'show']);
        Route::post('/commande/{commandeId}', [FactureController::class, 'creerDepuisCommande']);
        Route::post('/{id}/emettre', [FactureController::class, 'emettre']);
        Route::post('/{id}/paiement', [FactureController::class, 'enregistrerPaiement']);
        Route::post('/{id}/creer-bl', [FactureController::class, 'creerBonLivraison']);
    });

    // Bons de Livraison
    Route::prefix('bons-livraison')->group(function () {
        Route::get('/', [BonLivraisonController::class, 'index']);
        Route::get('/{id}', [BonLivraisonController::class, 'show']);
        Route::post('/{id}/preparer', [BonLivraisonController::class, 'demarrerPreparation']);
        Route::put('/{id}/lignes', [BonLivraisonController::class, 'updateLignes']);
        Route::post('/{id}/pret', [BonLivraisonController::class, 'marquerPret']);
        Route::post('/{id}/livrer', [BonLivraisonController::class, 'enregistrerLivraison']);
    });

    // Camions
    Route::prefix('camions')->group(function () {
        Route::get('/', [CamionController::class, 'index']);
        Route::get('/disponibles', [CamionController::class, 'disponibles']);
        Route::get('/{id}', [CamionController::class, 'show']);
        Route::post('/', [CamionController::class, 'store']);
        Route::put('/{id}', [CamionController::class, 'update']);
        Route::delete('/{id}', [CamionController::class, 'destroy']);
    });

    // Tournées
    Route::prefix('tournees')->group(function () {
        Route::get('/', [TourneeController::class, 'index']);
        Route::get('/{id}', [TourneeController::class, 'show']);
        Route::post('/', [TourneeController::class, 'store']);
        Route::post('/{id}/ajouter-bon', [TourneeController::class, 'ajouterBon']);
        Route::delete('/{id}/bon/{bonId}', [TourneeController::class, 'retirerBon']);
        Route::put('/{id}/ordre', [TourneeController::class, 'updateOrdre']);
        Route::post('/{id}/demarrer', [TourneeController::class, 'demarrer']);
        Route::post('/{id}/terminer', [TourneeController::class, 'terminer']);
    });

    // Zones de Préparation
    Route::prefix('zones-preparation')->group(function () {
        Route::get('/', [ZonePreparationController::class, 'index']);
        Route::get('/{id}', [ZonePreparationController::class, 'show']);
        Route::post('/', [ZonePreparationController::class, 'store']);
        Route::put('/{id}', [ZonePreparationController::class, 'update']);
        Route::delete('/{id}', [ZonePreparationController::class, 'destroy']);
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

    // Configuration des numéros
    Route::prefix('configurations')->group(function () {
        Route::get('/', [ConfigurationController::class, 'index']);
        Route::get('/{entite}', [ConfigurationController::class, 'show']);
        Route::put('/{entite}', [ConfigurationController::class, 'update']);
        Route::get('/{entite}/generer', [ConfigurationController::class, 'genererNumero']);
        Route::post('/{entite}/consommer', [ConfigurationController::class, 'consommerNumero']);
    });
});
