# INVENTAIRE — Flow complet & détail de chaque étape

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-04-11

---

## TABLE DES MATIÈRES

1. [Vue d'ensemble du module](#1-vue-densemble-du-module)
2. [Relocalisation (Mouvements)](#2-relocalisation-mouvements)
3. [Planification (Transferts Planifiés)](#3-planification-transferts-planifiés)
4. [Approbations](#4-approbations)
5. [Traçabilité](#5-traçabilité)
6. [Alertes de Stock](#6-alertes-de-stock)
7. [Inventaire Tournant](#7-inventaire-tournant)
8. [Rapports](#8-rapports)
9. [Audit (Journal des modifications)](#9-audit-journal-des-modifications)
10. [Flow complet bout en bout](#10-flow-complet-bout-en-bout)
11. [Statuts et transitions](#11-statuts-et-transitions)
12. [Multi-tenant isolation](#12-multi-tenant-isolation)

---

## 1. Vue d'ensemble du module

Le module **Inventaire** gère tout ce qui se passe *à l'intérieur* de l'entrepôt après que la marchandise est entrée (via Achats/Réceptions) :

```
INVENTAIRE
├── Relocalisation       ← déplacer des produits entre secteurs
├── Planification        ← planifier des mouvements futurs
├── Approbations         ← workflow de validation pour les gros mouvements
├── Traçabilité          ← retrouver l'historique complet d'un produit
├── Alertes de stock     ← surveiller les seuils et ruptures
├── Inventaire tournant  ← prioriser quels secteurs compter en premier
├── Rapports             ← analyses et statistiques de mouvements
└── Audit                ← journal de toutes les modifications
```

**Principe général :**
```
Marchandise reçue (via Achats)
       ↓
Rangée dans un secteur
       ↓
Relocalisation si besoin (transfert entre secteurs)
       ↓
Sortie vers client (via Ventes/BL)
       ↓
Traçabilité à chaque étape
```

---

## 2. Relocalisation (Mouvements)

### 2.1 Ce que c'est

Un **mouvement de relocalisation** enregistre le déplacement physique d'un produit dans l'entrepôt. Chaque mouvement est irréversible — pour annuler, il faut créer un mouvement inverse.

### 2.2 Types de mouvements

| Type | Signification | Champs obligatoires |
|------|---------------|---------------------|
| `arrivage` | Entrée de produit dans un secteur | `secteur_destination`, `quantite` |
| `transfert` | Déplacement d'un secteur à un autre | `secteur_source`, `secteur_destination`, `quantite` |
| `sortie` | Retrait d'un produit d'un secteur | `secteur_source`, `quantite` |
| `ajustement` | Correction manuelle du stock | `secteur_destination`, `quantite` |

### 2.3 Champs d'un mouvement

| Champ | Description | Obligatoire |
|-------|-------------|-------------|
| `produit_numero` | Numéro du produit | Oui |
| `produit_nom` | Nom du produit | Non |
| `type` | Type de mouvement (voir tableau ci-dessus) | Oui |
| `secteur_source` | Secteur de départ | Selon type |
| `secteur_destination` | Secteur d'arrivée | Selon type |
| `quantite` | Quantité déplacée | Oui |
| `unite_mesure` | Unité (kg, L, pièce…) | Non |
| `motif` | Raison du mouvement | Non |
| `employe` | Qui a effectué le mouvement | Oui |
| `date_mouvement` | Date et heure du mouvement | Oui (auto) |

### 2.4 Modes de saisie

**Mouvement simple :**
```
Étape 1 : Choisir le type (arrivage / transfert / sortie / ajustement)
Étape 2 : Scanner ou saisir le produit
Étape 3 : Saisir secteur source et/ou destination
Étape 4 : Saisir la quantité
Étape 5 : Valider
```

**Scan en lot (Arrivage lot) :**
```
Étape 1 : Sélectionner le secteur de destination
Étape 2 : Scanner plusieurs produits en séquence
Étape 3 : Pour chaque produit : quantité + unité
Étape 4 : Valider tout le lot d'un coup
```

**Relocalisation par secteur :**
```
Étape 1 : Sélectionner le secteur source
Étape 2 : L'application affiche tous les produits présents dans ce secteur
Étape 3 : Choisir les produits à déplacer
Étape 4 : Définir le secteur destination
Étape 5 : Valider
```

### 2.5 Endpoints

```
GET  /relocalisation              → liste des mouvements (filtrable)
POST /relocalisation              → créer un mouvement
GET  /relocalisation/stats        → statistiques globales
GET  /relocalisation/{id}         → détail d'un mouvement
POST /relocalisation/par-secteur  → relocalisation de tout un secteur
POST /relocalisation/arrivage-lot → arrivage de plusieurs produits en lot
GET  /relocalisation/produit/{numero} → historique des mouvements d'un produit
GET  /relocalisation/secteur/{secteur} → historique des mouvements d'un secteur
```

---

## 3. Planification (Transferts Planifiés)

### 3.1 Ce que c'est

Un **transfert planifié** permet de programmer un mouvement futur. Il reste en statut `planifie` jusqu'à ce qu'on l'exécute (ce qui crée le mouvement réel) ou qu'on l'annule.

### 3.2 Champs d'un transfert planifié

| Champ | Description | Obligatoire |
|-------|-------------|-------------|
| `type` | `arrivage`, `transfert`, `sortie` | Oui |
| `produit_numero` | Numéro du produit | Oui |
| `secteur_source` | Secteur de départ | Selon type |
| `secteur_destination` | Secteur d'arrivée | Selon type |
| `quantite` | Quantité prévue | Oui |
| `employe` | Responsable de l'exécution | Oui |
| `date_planifiee` | Date prévue d'exécution (future) | Oui |
| `notes` | Notes internes | Non |

### 3.3 Statuts d'un transfert planifié

```
planifie ──→ execute
    └──────→ annule
```

| Statut | Signification | Actions possibles |
|--------|---------------|-------------------|
| `planifie` | En attente d'exécution | Modifier, Exécuter, Annuler, Supprimer |
| `execute` | Mouvement réel créé | Lecture seule (ne peut pas être supprimé) |
| `annule` | Annulé avant exécution | Lecture seule |

### 3.4 Exécution d'un transfert planifié

Quand on clique **Exécuter** :
1. Vérifie que le transfert est en statut `planifie`
2. Crée un enregistrement dans `mouvement_relocalisation` avec les mêmes données
3. Passe le statut à `execute`
4. Enregistre `execute_le` et `execute_par`

### 3.5 Endpoints

```
GET    /transferts-planifies           → liste (filtrable par statut, date)
GET    /transferts-planifies/stats     → statistiques (planifiés, exécutés, annulés ce mois)
GET    /transferts-planifies/upcoming  → transferts à venir dans les 48h
POST   /transferts-planifies           → créer
GET    /transferts-planifies/{id}      → détail
PUT    /transferts-planifies/{id}      → modifier (seulement si statut = planifie)
POST   /transferts-planifies/{id}/execute → exécuter → crée le mouvement réel
POST   /transferts-planifies/{id}/cancel  → annuler
DELETE /transferts-planifies/{id}     → supprimer (impossible si execute)
```

---

## 4. Approbations

### 4.1 Ce que c'est

Le système d'**approbations** est un workflow de validation pour les mouvements importants. Au-dessus d'un certain seuil de quantité ou de valeur, un mouvement doit être approuvé par un responsable avant d'être exécuté.

### 4.2 Paramétrage des seuils

Les seuils sont configurables par tenant :
- `seuil_quantite` : quantité à partir de laquelle une approbation est requise
- `seuil_valeur` : valeur monétaire à partir de laquelle une approbation est requise

Endpoint de configuration :
```
GET /approbations/settings  → lire les seuils actuels
PUT /approbations/settings  → modifier les seuils
```

### 4.3 Statuts d'une demande d'approbation

```
en_attente ──→ approuve ──→ (mouvement créé)
     └────────→ rejete
```

| Statut | Signification | Actions possibles |
|--------|---------------|-------------------|
| `en_attente` | En attente de décision | Approuver, Rejeter |
| `approuve` | Validé par le responsable | Lecture seule |
| `rejete` | Refusé | Lecture seule |

### 4.4 Endpoints

```
GET  /approbations          → liste des demandes (filtrable par statut)
GET  /approbations/stats    → comptages par statut
GET  /approbations/settings → seuils d'approbation
PUT  /approbations/settings → modifier les seuils
POST /approbations          → soumettre une demande
POST /approbations/{id}/approve → approuver
POST /approbations/{id}/reject  → rejeter
```

---

## 5. Traçabilité

### 5.1 Ce que c'est

La **traçabilité** permet de retrouver l'historique complet d'un produit : tous ses mouvements (relocalisations) et tous ses scans d'inventaire, triés chronologiquement.

### 5.2 Ce qu'on peut voir

Pour chaque produit, la traçabilité fournit :
- **Historique fusionné** : mouvements + scans d'inventaire dans le même fil chronologique
- **Statistiques** :
  - Nombre total de mouvements
  - Nombre total de scans
  - Liste des secteurs où le produit a été vu
  - Dernière localisation connue
  - Quantité totale entrée (arrivages)
  - Quantité totale sortie (sorties)
- **Timeline** : vue chronologique avec descriptions lisibles

### 5.3 Comment fonctionne la "dernière localisation"

L'algorithme compare :
1. Le dernier mouvement avec `secteur_destination` non nul
2. Le dernier scan d'inventaire

Il retourne la localisation du plus récent des deux.

### 5.4 Endpoints

```
GET /tracabilite/search              → recherche de produits (min 2 caractères)
GET /tracabilite/produit/{numero}    → historique complet + stats d'un produit
GET /tracabilite/timeline/{numero}   → timeline chronologique d'un produit
```

---

## 6. Alertes de Stock

### 6.1 Ce que c'est

Les **alertes de stock** surveillent automatiquement les niveaux de stock et signalent quand un produit passe en dessous de son seuil défini.

### 6.2 Deux niveaux d'alerte

| Niveau | Champ | Signification |
|--------|-------|---------------|
| Alerte normale | `seuil_alerte` | Stock faible — à réapprovisionner bientôt |
| Critique | `seuil_critique > 0` | Stock critique — rupture imminente |

### 6.3 Configuration des seuils

Les seuils sont configurables produit par produit :
- **Individuellement** : `PUT /alertes/produit/{id}/seuil`
- **En masse** : `POST /alertes/seuils-batch` (pour configurer plusieurs produits en une fois)

### 6.4 Endpoints

```
GET  /alertes                       → liste des produits en alerte (actif = 1)
GET  /alertes/stats                 → comptages (total alertes, critiques)
PUT  /alertes/produit/{id}/seuil    → modifier le seuil d'un produit
POST /alertes/seuils-batch          → modifier les seuils de plusieurs produits
```

---

## 7. Inventaire Tournant

### 7.1 Ce que c'est

L'**inventaire tournant** est une méthode de comptage continu : plutôt que de tout compter d'un coup (inventaire annuel), on compte régulièrement un sous-ensemble de secteurs. L'application aide à prioriser quels secteurs compter en premier.

### 7.2 Score de priorité

Chaque secteur reçoit un **score de priorité** basé sur :
- Ancienneté du dernier comptage (plus c'est vieux, plus la priorité est haute)
- Nombre de mouvements récents (plus il y a eu de mouvements, plus il faut recompter)
- Présence d'alertes de stock dans ce secteur

### 7.3 Ce que l'application propose

**Onglet Suggestions :**
- Liste des secteurs à compter en priorité, triés par score
- Indique quand le secteur a été compté pour la dernière fois
- Affiche `"Tous les secteurs sont à jour !"` si tout a été compté récemment

**Onglet Planning :**
- Calendrier des secteurs à compter dans les prochains jours

### 7.4 Endpoints

```
GET /inventaire-tournant/suggestions        → secteurs à compter en priorité
GET /inventaire-tournant/stats              → statistiques globales
GET /inventaire-tournant/planning           → planning des prochains comptages
GET /inventaire-tournant/secteur/{secteur}  → historique des comptages d'un secteur
```

---

## 8. Rapports

### 8.1 Ce que c'est

Le module **Rapports** produit des analyses agrégées sur les mouvements de stock, filtrables par période.

### 8.2 Rapports disponibles

**Mouvements par secteur (mensuel)**
- Pour chaque secteur : nombre et quantité d'entrées, de sorties, et solde net
- `GET /rapports/mouvements-secteur?mois=4&annee=2026`

**Activité par employé (mensuel)**
- Pour chaque employé : nombre de mouvements et de scans effectués
- `GET /rapports/activite-employe?mois=4&annee=2026`

**Évolution annuelle (12 mois)**
- Mois par mois sur une année : nombre de mouvements et de scans
- `GET /rapports/evolution-annuelle?annee=2026`

**Top produits du mois**
- Les produits les plus manipulés dans la période
- `GET /rapports/top-produits?mois=4&annee=2026&limit=10`

### 8.3 Source des données

Chaque rapport fusionne deux sources :
- `mouvement_relocalisation` — les relocalisations/mouvements
- `scans_inventaire` (via `ScanTenant`) — les scans d'inventaire

---

## 9. Audit (Journal des modifications)

### 9.1 Ce que c'est

Le **journal d'audit** enregistre automatiquement toutes les créations, modifications et suppressions importantes dans le système. Il permet de savoir qui a fait quoi et quand.

### 9.2 Ce qui est tracé

Chaque entrée d'audit contient :

| Champ | Description |
|-------|-------------|
| `action` | `create`, `update`, `delete`, `approve`, `reject`, `send`, `cancel` |
| `model_type` | Type de l'objet modifié (ex: `Produit`, `CommandeClient`) |
| `model_id` | ID de l'objet modifié |
| `changes` | JSON des champs avant/après (pour les mises à jour) |
| `user_id` | L'employé qui a fait l'action |
| `user_name` | Son nom |
| `ip_address` | Adresse IP |
| `tenant_id` | Isolation multi-tenant |

### 9.3 Endpoints

```
GET /audit                         → liste des logs (filtrable par action, user, date)
GET /audit/stats                   → comptages par action
GET /audit/{modelType}/{modelId}   → historique d'un objet spécifique
```

---

## 10. Flow complet bout en bout

```
┌─────────────────────────────────────────────────────────────────┐
│                MODULE INVENTAIRE — FLOW COMPLET                  │
└─────────────────────────────────────────────────────────────────┘

1. PRÉREQUIS
   ✓ Produits du catalogue créés (module Paramètres)
   ✓ Secteurs de l'entrepôt créés (module Paramètres)
   ✓ Employés et rôles configurés (module Paramètres)

2. ENTRÉE DU STOCK
   → Via Achats : Réception d'une commande fournisseur
     ⚡ Crée un mouvement de type "arrivage" dans mouvement_relocalisation
   → Via Relocalisation directe : Arrivage manuel / scan en lot

3. ORGANISATION EN ENTREPÔT
   → Consulter la localisation actuelle des produits
   → Créer des relocalisations (transferts entre secteurs)
   → Planifier des transferts futurs (Planification)
   → Grands mouvements → workflow Approbations si seuil dépassé

4. SUIVI ET CONTRÔLE
   → Alertes de stock : surveiller les niveaux bas
   → Inventaire tournant : compter régulièrement les secteurs prioritaires
   → Traçabilité : retrouver l'historique complet d'un produit

5. ANALYSE
   → Rapports mensuels : mouvements par secteur, activité par employé
   → Évolution annuelle : tendances sur 12 mois
   → Top produits : identifier les plus actifs

6. AUDIT
   → Toutes les actions sont enregistrées automatiquement
   → Journal consultable et filtrable
```

---

## 11. Statuts et transitions

### Transferts planifiés

```
           [Créer]
               ↓
           planifie
          ↙         ↘
    [Exécuter]   [Annuler]
        ↓              ↓
     execute         annule
```

### Approbations

```
       [Soumettre]
            ↓
        en_attente
       ↙          ↘
 [Approuver]   [Rejeter]
     ↓               ↓
  approuve          rejete
     ↓
[Mouvement créé]
```

---

## 12. Multi-tenant isolation

Toutes les tables du module Inventaire sont isolées par `tenant_id` :

| Table | tenant_id | Contrôle |
|-------|-----------|---------|
| `mouvement_relocalisation` | ✅ | Tous les read/write scopés |
| `transferts_planifies` | ✅ | Tous les read/write scopés |
| `approbations` | ✅ | Tous les read/write scopés |
| `parametres_approbation` | ✅ | Settings per tenant |
| `audit_logs` | ✅ | Logs isolés par tenant |
| `alertes_stock` | ✅ | Alertes per tenant |
| `scans_inventaire` | ✅ | Scans per tenant |

**Règle** : chaque contrôleur lit `auth()->user()->tenant_id` et l'applique sur **toutes** les requêtes. Un tenant ne peut jamais voir les données d'un autre.

---

## Points d'attention globaux

1. **Les mouvements sont irréversibles.** Pour corriger, il faut créer un mouvement inverse (ex: un ajustement).
2. **Un transfert planifié exécuté ne peut pas être supprimé** — l'historique doit rester intact.
3. **Les approbations bloquent les gros mouvements** — vérifier les seuils configurés si un mouvement ne passe pas directement.
4. **La traçabilité fusionne deux sources** (mouvements + scans) — un produit peut apparaître dans l'historique même sans mouvement formel, via un scan d'inventaire.
5. **Les alertes se basent sur `actif = 1`** (pas sur un champ `statut`) — une alerte sans seuil critique ne génère pas d'alerte critique même si le stock est à zéro.
