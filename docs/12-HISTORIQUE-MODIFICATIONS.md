# Historique des Modifications

Ce document trace toutes les modifications importantes apportées au système PRISE Inventaire.

---

## Format des entrées

Chaque modification suit ce format :

```
## [DATE] - Titre de la modification

**Auteur:** Nom  
**Type:** Feature | Fix | Refactor | Doc  
**Impact:** Frontend | Backend | Database | All  

### Description
Brève description de la modification.

### Fichiers modifiés
- `chemin/vers/fichier.ext` - Description du changement

### Migration requise
- [ ] Oui / Non
- Commande: `php artisan migrate`

### Tests
- [ ] Testé en local
- [ ] Testé en production
```

---

## Historique

---

## [2026-03-07] - Secteur obligatoire pour les produits

**Auteur:** Développeur  
**Type:** Feature  
**Impact:** All (Frontend + Backend + Database)  

### Description
Chaque produit doit maintenant être associé à un secteur. Le champ secteur est obligatoire lors de la création d'un produit.

### Fichiers modifiés

#### Frontend
- `src/types/index.ts` - Ajout `secteur_id?: number` et `secteur?: Secteur` au type Produit
- `src/pages/ProduitsPage.tsx` - Ajout du champ select "Secteur" obligatoire dans le formulaire, nouvelle colonne "Secteur" dans le tableau, mise à jour export/import CSV

#### Backend
- `database/migrations/2026_03_07_230000_add_secteur_id_to_produits.php` - Migration pour ajouter la colonne `secteur_id` avec clé étrangère vers `secteurs`
- `app/Models/ProduitTenant.php` - Ajout `secteur_id` dans `$fillable`, ajout relation `secteur()`
- `app/Http/Controllers/ProduitTenantController.php` - Validation `secteur_id` required à la création, chargement relation `with('secteur')` dans index/show

### Migration requise
- [x] Oui
- Commande: `php artisan migrate`
- Exécutée le: 2026-03-07 23:08

### Tests
- [x] Testé en local
- [ ] Testé en production

---

## [2026-03-06] - Modules Ventes & Finance

**Auteur:** Développeur  
**Type:** Feature  
**Impact:** Frontend  

### Description
Ajout de 11 nouvelles pages pour les modules Ventes et Finance : Commandes Client, Factures, Bons de Livraison, Tournées, Devis, Comptabilité, Prévisions Stock, Gestion des Prix, Configuration Alertes.

### Fichiers modifiés

#### Nouvelles pages
- `src/pages/CommandesClientPage.tsx` - Gestion des commandes client
- `src/pages/FacturesPage.tsx` - Gestion des factures (sans TVA)
- `src/pages/BonsLivraisonPage.tsx` - Préparation et livraison
- `src/pages/TourneesPage.tsx` - Planification des tournées
- `src/pages/DevisPage.tsx` - Création et conversion de devis
- `src/pages/ComptabilitePage.tsx` - Journal ventes/achats, export CSV
- `src/pages/PrevisionsStockPage.tsx` - Analyse et suggestions stock
- `src/pages/GestionPrixPage.tsx` - Tarifs clients et promotions
- `src/pages/NotificationsConfigPage.tsx` - Configuration des alertes

#### Fichiers mis à jour
- `src/App.tsx` - Ajout des routes
- `src/components/Layout.tsx` - Ajout menu Finance avec 4 items
- `src/pages/RolesPage.tsx` - Ajout labels permissions

### Migration requise
- [ ] Non (frontend uniquement)

### Tests
- [x] Testé en local
- [x] Déployé sur Amplify

---

## [2026-03-06] - Retrait des taxes sur les factures

**Auteur:** Développeur  
**Type:** Fix  
**Impact:** Frontend  

### Description
Suppression des références à la TVA et au TTC dans la page Factures. Affichage uniquement du montant HT.

### Fichiers modifiés
- `src/pages/FacturesPage.tsx` - Colonne "Montant TTC" → "Montant", suppression lignes TVA/TTC dans détail

### Migration requise
- [ ] Non

---

## [2026-03-06] - Navigation en catégories dépliables

**Auteur:** Développeur  
**Type:** Refactor  
**Impact:** Frontend  

### Description
Réorganisation du menu de navigation en catégories dépliables : Inventaire, Achats, Ventes, Finance, Paramètres.

### Fichiers modifiés
- `src/components/Layout.tsx` - Refactoring complet de la navigation

---

## [2026-03-06] - Module Clients & Ventes Backend

**Auteur:** Développeur  
**Type:** Feature  
**Impact:** Backend  

### Description
Création des API REST pour le module Clients & Ventes : clients, commandes client, factures, bons de livraison, camions, tournées.

### Fichiers modifiés
- `routes/api.php` - Ajout de toutes les routes
- `app/Http/Controllers/Api/*` - Nouveaux contrôleurs
- `app/Models/*` - Nouveaux modèles
- `database/migrations/*` - Nouvelles tables

### Migration requise
- [x] Oui
- Commande: `php artisan migrate`

---

## Comment ajouter une entrée

1. Copier le template ci-dessus
2. Remplir les informations
3. Ajouter en haut de la section "Historique" (ordre chronologique inverse)
4. Commit avec message: `docs: Add changelog entry for [feature]`

---

## Conventions

### Types de modification
- **Feature** - Nouvelle fonctionnalité
- **Fix** - Correction de bug
- **Refactor** - Restructuration du code sans changement fonctionnel
- **Doc** - Documentation uniquement
- **Perf** - Amélioration de performance
- **Security** - Correction de sécurité

### Impact
- **Frontend** - Uniquement le code React/TypeScript
- **Backend** - Uniquement le code Laravel/PHP
- **Database** - Modifications de schéma (migrations)
- **All** - Plusieurs composants affectés

### Checklist avant modification majeure
- [ ] Créer une branche si nécessaire
- [ ] Tester en local
- [ ] Mettre à jour ce document
- [ ] Commit avec message descriptif
- [ ] Push et vérifier le déploiement
- [ ] Exécuter les migrations si nécessaire
