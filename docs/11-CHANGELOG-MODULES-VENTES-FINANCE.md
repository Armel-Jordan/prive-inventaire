# Changelog - Modules Ventes & Finance

**Date:** 6 Mars 2026  
**Version:** 2.0.0

---

## 📋 Résumé des modifications

Cette mise à jour majeure ajoute **11 nouvelles pages frontend** pour compléter le système de gestion d'inventaire avec des fonctionnalités de ventes, finance et alertes.

---

## 🛒 Module Ventes

### 1. Commandes Client (`/commandes-client`)
**Fichier:** `src/pages/CommandesClientPage.tsx`

| Fonctionnalité | Description |
|----------------|-------------|
| Liste des commandes | Affichage avec filtres par statut et recherche |
| Création commande | Sélection client, ajout lignes produits avec quantités |
| Workflow complet | Brouillon → Soumise → Acceptée/Refusée |
| Conversion | Bouton pour convertir une commande acceptée en facture |

**Statuts disponibles:**
- `brouillon` - En cours de création
- `soumise` - En attente de validation
- `acceptee` - Validée, prête pour facturation
- `refusee` - Refusée
- `facturee` - Convertie en facture

---

### 2. Factures (`/factures`)
**Fichier:** `src/pages/FacturesPage.tsx`

| Fonctionnalité | Description |
|----------------|-------------|
| Liste factures | Filtres par statut, affichage montant et reste à payer |
| Détail facture | Lignes, totaux, échéances, paiements |
| Émission | Passage de brouillon à émise |
| Paiement | Enregistrement des paiements partiels ou complets |
| Création BL | Génération d'un bon de livraison depuis la facture |

**Note:** Les taxes (TVA/TTC) ont été retirées - affichage uniquement du montant HT.

---

### 3. Bons de Livraison (`/bons-livraison`)
**Fichier:** `src/pages/BonsLivraisonPage.tsx`

| Fonctionnalité | Description |
|----------------|-------------|
| Liste BL | Filtres par statut |
| Préparation | Saisie des quantités préparées (picking) |
| Marquage prêt | Validation de la préparation |
| Livraison | Enregistrement des quantités livrées avec notes |

**Workflow:**
```
Créé → En préparation → Prêt → En livraison → Livré (complet/partiel)
```

---

### 4. Tournées (`/tournees`)
**Fichier:** `src/pages/TourneesPage.tsx`

| Fonctionnalité | Description |
|----------------|-------------|
| Liste tournées | Filtres par date et statut |
| Création | Sélection date, camion disponible, zone |
| Gestion | Démarrage et terminaison de tournée |
| Détail | Visualisation des BL affectés et leur statut |

---

### 5. Devis (`/devis`)
**Fichier:** `src/pages/DevisPage.tsx`

| Fonctionnalité | Description |
|----------------|-------------|
| Liste devis | Filtres par statut |
| Création | Client, date validité, lignes produits |
| Envoi | Passage de brouillon à envoyé |
| Conversion | Transformation en commande client |

**Statuts:** `brouillon`, `envoye`, `accepte`, `refuse`, `expire`

---

## 💰 Module Finance

### 6. Comptabilité (`/comptabilite`)
**Fichier:** `src/pages/ComptabilitePage.tsx`

| Fonctionnalité | Description |
|----------------|-------------|
| Journal des ventes | Liste des factures émises avec totaux |
| Journal des achats | Liste des réceptions avec montants |
| Filtres période | Sélection date début/fin |
| Export CSV | Téléchargement des données pour comptabilité externe |

**Résumé visuel:**
- Total ventes (vert)
- Total achats (rouge)

---

### 7. Prévisions de Stock (`/previsions-stock`)
**Fichier:** `src/pages/PrevisionsStockPage.tsx`

| Fonctionnalité | Description |
|----------------|-------------|
| Analyse stock | Stock actuel vs stock minimum |
| Consommation | Calcul de la consommation moyenne journalière |
| Jours restants | Estimation avant rupture |
| Suggestions | Quantités recommandées à commander |

**Indicateurs:**
- 🔴 **Critique** - Stock ≤ 50% du minimum
- 🟡 **Bas** - Stock ≤ minimum
- 🟢 **OK** - Stock suffisant

---

### 8. Gestion des Prix (`/gestion-prix`)
**Fichier:** `src/pages/GestionPrixPage.tsx`

#### Tarifs Clients
| Champ | Description |
|-------|-------------|
| Client | Client bénéficiaire |
| Produit | Produit concerné |
| Prix spécial | Prix négocié |
| Période | Dates de validité (optionnel) |

#### Promotions
| Champ | Description |
|-------|-------------|
| Nom | Nom de la promotion |
| Type | Pourcentage ou montant fixe |
| Valeur | Réduction appliquée |
| Produit | Spécifique ou tous |
| Période | Dates début/fin |

---

### 9. Configuration Alertes (`/alertes-config`)
**Fichier:** `src/pages/NotificationsConfigPage.tsx`

| Type d'alerte | Description |
|---------------|-------------|
| Stock bas | Quand le stock descend sous le seuil |
| Commande en retard | Commandes non livrées après X jours |
| Facture impayée | Factures non payées après X jours |
| Livraison en retard | BL non livrés |
| CT à renouveler | Contrôle technique camion expirant |

**Options par alerte:**
- Seuil/Jours configurable
- Notification email (on/off)
- Notification push (on/off)
- Activation/désactivation

---

## 🧭 Navigation

### Nouvelle structure du menu

```
📁 Inventaire
   ├── Scans
   ├── Statistiques
   ├── Comparaison
   ├── Alertes
   ├── Historique
   ├── Traçabilité
   ├── Relocalisation
   ├── Planification
   ├── Approbations
   ├── Rapports
   └── Inv. Tournant

📁 Achats
   ├── Fournisseurs
   ├── Commandes
   └── Réceptions

📁 Ventes
   ├── Clients
   ├── Devis ← NOUVEAU
   ├── Commandes ← NOUVEAU
   ├── Factures ← NOUVEAU
   ├── Bons Livraison ← NOUVEAU
   ├── Camions
   └── Tournées ← NOUVEAU

📁 Finance ← NOUVELLE CATÉGORIE
   ├── Comptabilité ← NOUVEAU
   ├── Prévisions ← NOUVEAU
   ├── Tarifs & Prix ← NOUVEAU
   └── Alertes ← NOUVEAU

📁 Paramètres
   ├── Produits
   ├── Secteurs
   ├── Employés
   └── Rôles
```

---

## 🔐 Permissions

Nouveaux modules ajoutés dans la gestion des rôles (`RolesPage.tsx`):

| Module | Clé permission |
|--------|----------------|
| Commandes Client | `commandes_client` |
| Factures | `factures` |
| Bons de Livraison | `bons_livraison` |
| Tournées | `tournees` |
| Devis | `devis` |
| Comptabilité | `comptabilite` |
| Prévisions Stock | `previsions_stock` |
| Gestion des Prix | `gestion_prix` |
| Configuration Alertes | `alertes_config` |

---

## 📁 Fichiers modifiés

### Nouveaux fichiers
```
src/pages/
├── CommandesClientPage.tsx
├── FacturesPage.tsx
├── BonsLivraisonPage.tsx
├── TourneesPage.tsx
├── DevisPage.tsx
├── ComptabilitePage.tsx
├── PrevisionsStockPage.tsx
├── GestionPrixPage.tsx
└── NotificationsConfigPage.tsx
```

### Fichiers mis à jour
```
src/App.tsx              # Routes ajoutées
src/components/Layout.tsx # Menu navigation
src/pages/RolesPage.tsx   # Labels permissions
```

---

## 🚀 Déploiement

- **Repository:** GitHub `Armel-Jordan/prive-inventaire`
- **Branche:** `main`
- **Hébergement:** AWS Amplify (déploiement automatique)
- **Temps de build:** ~2-3 minutes après push

---

## 📝 Notes techniques

1. **Données de démo:** Les pages Prévisions, Devis, Gestion Prix et Alertes utilisent des données simulées pour la démonstration. L'intégration backend sera nécessaire pour la production.

2. **Types TypeScript:** Tous les composants utilisent des interfaces TypeScript strictes pour la sécurité des types.

3. **Dark mode:** Toutes les pages supportent le mode sombre via les classes Tailwind `dark:`.

4. **Responsive:** Les tableaux et formulaires s'adaptent aux écrans mobiles.

---

## 🔜 Prochaines étapes suggérées

1. **Backend API** - Créer les endpoints Laravel pour les nouvelles fonctionnalités
2. **Intégration** - Connecter les pages aux vraies API
3. **Tests** - Ajouter des tests unitaires et E2E
4. **PDF** - Génération de factures et devis en PDF
5. **Emails** - Envoi automatique des alertes configurées
