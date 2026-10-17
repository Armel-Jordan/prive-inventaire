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
13. [Prises d'inventaire](#13-prises-dinventaire-sessions-de-comptage)
14. [Historique des scans](#14-historique-des-scans-statistiques-comparaison-tableau-de-bord)
15. [Codes-barres des produits](#15-codes-barres-des-produits-inventaire--produits)
16. [Étiquettes des produits](#16-étiquettes-des-produits-paramètres--étiquettes)
17. [Ensembles, annexes et équivalents](#17-ensembles-annexes-et-équivalents-paramètres--produits--liens)

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
| `arrivage` | Entrée de produit dans un secteur (dont les retours clients remis en stock, motif « Retour client RC-… », et les remplacements reçus d'un fournisseur, « Remplacement fournisseur RF-… ») | `secteur_destination`, `quantite` |
| `transfert` | Déplacement d'un secteur à un autre | `secteur_source`, `secteur_destination`, `quantite` |
| `sortie` | Retrait d'un produit d'un secteur (dont les retours fournisseurs expédiés, motif « Retour fournisseur RF-… ») | `secteur_source`, `quantite` |
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

Les seuils se règlent par entreprise et par type de mouvement (arrivage, transfert, sortie) dans
**Approbations › Paramètres** :
- `seuil_quantite` : quantité à partir de laquelle une approbation est requise ;
- `actif` : le seuil ne s'applique que s'il est actif.

Les seuils proposés à la première ouverture (100, 50, 50) sont **inactifs** : l'entreprise choisit de les
activer. Un ajustement n'est jamais soumis à approbation.

**Où les seuils s'appliquent :** à tout mouvement enregistré, depuis l'écran Relocalisation (mouvement simple
ou lot) comme depuis l'application mobile (`POST /relocalisation`, `/relocalisation/par-secteur`,
`/relocalisation/arrivage-lot`, `/mobile/relocalisation`).
- Une quantité qui atteint le seuil de son type devient une demande d'approbation, et le mouvement n'est créé
  qu'à l'approbation.
- Un mouvement simple répond alors **202**, avec `requires_approval: true` et un message.
- Un lot enregistre les autres lignes et liste celles mises en attente.
- Les seuils n'étaient appliqués auparavant que par `POST /approbations`, que ni l'écran ni l'application
  n'appellent : un transfert de 5 000 unités passait sans approbation.

**Auteurs :**
- l'auteur d'un mouvement saisi sur le web est l'utilisateur connecté ; c'était « Admin Web » pour tout le
  monde ;
- l'approbateur est toujours le compte connecté qui décide. Le nom envoyé par l'écran était libre, donc
  usurpable ; il est désormais ignoré.

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
- Indique quand le secteur a été compté pour la dernière fois, et depuis combien de jours (toujours positif ;
  absent pour un secteur jamais scanné, qui compte pour 365 jours dans le score)
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
- `GET /rapports/evolution-annuelle?annee=2026` (MySQL et SQLite)

**Top produits du mois**
- Les produits les plus manipulés dans la période
- `GET /rapports/top-produits?mois=4&annee=2026&limit=10`

**Export CSV** : un fichier par onglet, Évolution comprise. Fichier UTF-8 avec BOM, pour qu'Excel affiche
les accents. Une erreur de chargement d'un rapport est signalée au lieu de laisser un tableau vide.

### 8.3 Source des données

Chaque rapport fusionne deux sources :
- `mouvement_relocalisation` — les relocalisations/mouvements
- `scans_inventaire` (via `ScanTenant`) — les scans d'inventaire

---

## 9. Audit (Journal des modifications)

### 9.1 Ce que c'est

Le **journal d'audit** (écran Historique) enregistre automatiquement les créations, modifications et
suppressions des fiches et des documents. Il permet de savoir qui a fait quoi et quand.

Il est alimenté par l'observateur `App\Observers\JournalModifications`, enregistré pour chaque modèle suivi
dans `AppServiceProvider`. Le journal existait auparavant (table, écran, `AuditLog::log`) sans que rien ne
l'écrive : il restait vide.

### 9.2 Ce qui est tracé

| Objet (`model_type`) | Actions |
|---|---|
| Produit, Secteur, Employé, Compte utilisateur, Client, Fournisseur | création, modification, suppression |
| Devis, Commande client, Commande fournisseur, Facture, Bon de livraison | création, modification, suppression |
| Paramètres, Taxe, Numérotation | création, modification, suppression |
| Scan, Mouvement (très nombreux, déjà historisés) | modification et suppression seulement |

Chaque entrée contient :

| Champ | Description |
|-------|-------------|
| `action` | `create`, `update`, `delete` |
| `model_type` / `model_id` | Objet concerné (libellé ci-dessus) et son identifiant |
| `old_values` / `new_values` | Valeurs avant / après. Pour une modification : seulement les champs modifiés |
| `user_id` / `user_name` | Utilisateur connecté qui a fait l'action |
| `ip_address`, `user_agent` | Origine de la requête |
| `tenant_id` | Entreprise : chaque entreprise ne voit que son journal |

Ne sont jamais journalisés : les mots de passe, les secrets de double authentification, les champs masqués
du modèle et les horodatages techniques. Une erreur d'écriture du journal n'empêche jamais l'opération ; hors
d'un contexte d'entreprise (commandes, initialisation des données), rien n'est journalisé.

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
   → Via Achats : Réception d'une commande fournisseur, rangée dans un secteur
     ⚡ Comptée comme une entrée de ce secteur par la prise d'inventaire suivante
   → Via Ventes : Retour client remis en stock (FLOW-VENTES §5.12)
     ⚡ Crée un mouvement de type "arrivage" dans mouvement_relocalisation (motif « Retour client RC-… »)
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

---

## 13. Prises d'inventaire (sessions de comptage)

Sessions de comptage par-dessus les scans : écarts avec le dernier inventaire validé, mouvements connus depuis, corrections, validation qui forme l'inventaire officiel. Voir [FLOW-PRISE-INVENTAIRE.md](./FLOW-PRISE-INVENTAIRE.md).

---

## 14. Historique des scans, statistiques, comparaison, tableau de bord

Les écrans travaillent sur **tout l'historique**, filtré par l'API. Ils voyaient auparavant les 100 derniers
scans ou mouvements : filtres de dates vides, export de 100 lignes, statistiques identiques sur 7, 30 et
90 jours.

**Dates et fuseau horaire :** une période choisie à l'écran (« du 15/10 au 15/10 ») est envoyée en instants,
du début du premier jour au début du lendemain du dernier, dans le fuseau du navigateur. Les jours des
graphiques sont regroupés dans ce même fuseau (paramètre `fuseau`, par exemple `America/Toronto`). La
planification envoie l'heure saisie avec son fuseau : 9 h saisi à Montréal reste 9 h à l'affichage.

**Secteur :** un filtre par secteur retrouve les scans enregistrés sous son code (web) comme sous son nom
(application mobile). Les statistiques comptent ce secteur une seule fois.

| Endpoint | Rôle |
|---|---|
| `GET /scan/historique?page=&per_page=&secteur=&employe=&produit=&depuis=&jusqu_a=` | Historique paginé (500 par page au plus). Sans `page` : liste des 100 derniers, format attendu par l'application mobile |
| `GET /scan/export?…&fuseau=` | CSV de tous les scans filtrés (BOM UTF-8, `;`, virgule décimale) |
| `GET /scan/statistiques?depuis=&jusqu_a=&fuseau=` | Total, jours d'activité, moyenne par jour, secteurs et employés distincts ; répartition par jour, secteur (8 premiers), employé (10 premiers) et type |
| `GET /scan/comparaison?a_depuis=&a_jusqu_a=&b_depuis=&b_jusqu_a=` | Quantités comptées par produit sur deux périodes, écart et variation |
| `GET /scan/stats` | Total, scans du jour, instants du premier et du dernier scan |
| `GET /relocalisation?page=&per_page=` | Historique des mouvements paginé (sans `page` : liste limitée, comme avant) |

**Comparaison :** elle met face à face les quantités **comptées** à chacune des deux dates. Elle additionnait
auparavant tous les scans jusqu'à chaque date : ce cumul ne faisait que croître, et « Diminutions » restait
à 0.

**Tableau de bord, section Inventaire :**
- produits actifs (les désactivés ne comptent plus) ;
- alertes et ruptures parmi les produits suivis (seuil d'alerte > 0), avec le stock calculé comme sur l'écran
  Alertes (somme des quantités scannées) ;
- secteurs et employés actifs ;
- mouvements de relocalisation de la période (arrivages = entrées, sorties).

Il lisait auparavant d'anciennes tables vides.

## 15. Codes-barres des produits (Inventaire → Produits)

Plan de parité fripv2, phase 2.1 : un produit se lit par son numéro, ou par l'un de ses **codes-barres**.

**Les codes d'un produit** (bouton **Codes-barres** sur chaque ligne de la liste des produits) :
- **Types** : EAN-13, EAN-8, UPC-A, Code 128 ou interne. Le type se devine d'après le code (13, 12 ou 8 chiffres avec une clé juste ; sinon Code 128).
- **Clé de contrôle** : celle d'un EAN ou d'un UPC est vérifiée. Le message donne le bon dernier chiffre.
- **Quantité par lecture** : un code compte pour une quantité du produit. Le code du carton de 12 compte pour 12.
- **Un code principal** par produit ; le premier code l'est d'office. Si l'on retire le principal, le plus ancien des autres le devient.
- **Refusés** : un code déjà porté par un produit de l'entreprise ; le numéro d'un autre produit, car la lecture serait ambiguë.
- La liste des produits se filtre aussi par code-barres, et la recherche globale (Ctrl+K) trouve un produit par l'un de ses codes.

**À la lecture** (application mobile et saisie des scans) :
- la validation d'un code lu (`POST /mobile/produit/valider`) répond par le numéro du produit actif, ou par celui du produit dont c'est un code-barres ;
  - elle donne aussi `code_lu` et `quantite_par_lecture` ;
  - le code d'un produit désactivé ne se lit pas ;
- un scan enregistré avec un code-barres (`POST /scan/enregistrer`) compte pour son produit :
  - le numéro du produit est noté, à la quantité multipliée par celle du code ;
  - le code lu est gardé (`code_lu`) ;
  - un numéro de produit reste tel quel ; un code inconnu aussi, comme avant.

**Droits** : ceux des produits. Consulter avec le droit de les voir ; ajouter, modifier ou retirer un code avec le droit de les modifier.

| Méthode | Route | Rôle |
|---|---|---|
| GET / POST | `/produits/{id}/codes-barres` | Codes du produit ; ajout (`code`, `type` facultatif, `quantite`, `principal`, `notes`) |
| PUT / DELETE | `/produits/{id}/codes-barres/{code}` | Modifier ; retirer |

Code :
- `App\Support\CodesBarres` (types, détection, clés) ;
- service `App\Services\CodesBarresProduits` (lecture, enregistrement), `ProduitCodeBarreController`, modèle `ProduitCodeBarre` ;
- web : composant `produits/FenetreCodesBarres`, service `src/services/codesBarres.ts` ;
- migration `2026_10_17_100071_creer_codes_barres_produits` (table `produit_codes_barres`, colonne `inventaire_scan.code_lu`).

## 16. Étiquettes des produits (Paramètres → Étiquettes)

Plan de parité fripv2, phase 2.1 (suite) : imprimer les étiquettes des produits en PDF.

**Formats** (onglet Formats) :
- **Rouleau** : une étiquette par page, à sa taille, pour une imprimante d'étiquettes (thermique ou autre) ;
- **Planche** : une grille sur une page A4 ou Letter, avec ses marges et ses espaces ;
  - la grille doit tenir dans la page (le message donne la largeur ou la hauteur atteinte) ;
  - l'aperçu de la fenêtre montre où tombent les étiquettes.
- **Contenu** : nom de l'entreprise, description, code-barres, chiffres sous le code-barres, numéro du produit, prix, prix taxes comprises, date d'impression ; taille du texte en points.
- La disposition se calcule d'après la taille de l'étiquette et du texte. Un format trop petit pour son contenu est refusé : il faut au moins 5 mm pour le code-barres.
- **Formats d'origine**, créés à la première visite (les trois de fripv2 en 38 × 22 mm, et une planche) :
  - Complet (par défaut) : description, code-barres, chiffres, numéro, prix ;
  - Sans prix ;
  - Code-barres seul ;
  - Planche A4 (3 × 7) : 21 étiquettes de 63,5 × 38,1 mm.
- **Un format par défaut**, et toujours au moins un format actif. Un format désactivé ou retiré cède sa place par défaut au premier format actif.

**Impression** (onglet Imprimer) :
- **Produits** :
  - un par un : recherche par numéro, description ou code-barres ;
  - lecture d'une douchette suivie d'Entrée : le code lu est celui imprimé ;
  - tout un secteur ;
  - depuis la liste des produits : bouton **Étiquettes** d'un produit, ou **Étiquettes** des produits affichés (200 au plus) ;
- **Pour chaque produit** : le code imprimé (le principal, un autre de ses codes, ou son numéro s'il n'en a pas) et le nombre d'exemplaires ; 2 000 étiquettes au plus par impression.
- **Prix** : celui d'une liste de prix (prix saisi pour le produit, sinon prix de base ajusté), sinon le prix de base.
  - Taxes comprises si le format le demande : avec le groupe de taxes choisi, sinon celui par défaut ; « TTC » suit le prix.
  - Montant à la précision de la devise de l'entreprise (sans décimales en XAF).
- **Planche entamée** : on clique la première étiquette libre. Le contour des étiquettes peut être tracé pour un essai sur papier ordinaire.
- **Code-barres** : EAN-13, EAN-8, UPC-A (comme un EAN-13 qui commence par 0), sinon Code 128.
  - Jeu C pour un nombre pair de chiffres, sinon jeu B.
  - Dessin en SVG, sans bibliothèque ; un code accentué, qu'aucune symbologie n'écrit, est imprimé en texte.
- **Textes ajustés** avec les métriques de la police du PDF :
  - description répartie entre les mots, coupée avec « … » ;
  - numéro arrêté avant le prix ; il ne répète pas les chiffres du code quand le code imprimé est le numéro ;
  - prix rapetissé s'il est long.

**Droits** : module **Étiquettes** (onglet Paramètres).
- **Consulter** : voir les formats et imprimer (l'impression n'écrit rien).
- **Gérer les formats** : créer, modifier, retirer.
- Pour chaque groupe, la migration règle ces droits d'après ceux des produits : consulter comme on voit les produits, gérer les formats comme on les modifie.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/etiquettes/formats` | Formats (ceux d'origine à la première visite), contenus possibles, pages, maximum |
| POST / PUT / DELETE | `/etiquettes/formats[/{id}]` | Créer, modifier, retirer un format (gérer les formats) |
| POST | `/etiquettes/imprimer` | Le PDF : `format_id`, `lignes` (`produit_id`, `code_barre_id`, `copies`), `liste_prix_id`, `groupe_taxe_id`, `depart`, `langue`, `contours` |

Code :
- `App\Support\SvgCodeBarre` (EAN-13, EAN-8, UPC-A, Code 128) ;
- service `App\Services\Etiquettes` (formats, disposition, textes, prix, PDF), `EtiquetteController`, modèle `EtiquetteFormat`, vue `pdf/etiquettes` ;
- web : page `EtiquettesPage`, composants `etiquettes/OngletImpression`, `etiquettes/OngletFormats`, `etiquettes/FenetreFormatEtiquette`, service `src/services/etiquettes.ts` ;
- migration `2026_10_17_100072_creer_formats_etiquettes` (table `etiquette_formats`, module de droits `etiquettes`).

## 17. Ensembles, annexes et équivalents (Paramètres → Produits → Liens)

Plan de parité fripv2, phase 2.3 : des produits liés entre eux (bouton **Ensemble, annexes, équivalents** sur chaque
ligne de la liste des produits).

**Ensemble (kit)** : un produit composé d'autres produits, chacun en une quantité.
- Il se vend en un article et consomme ses composants. Il porte le badge **Ensemble** dans la liste.
- Un ensemble peut en contenir un autre (le coffret contient le pack), sans boucle : un produit ne peut pas contenir
  un ensemble qui le contient déjà.
- **Disponibilités** :
  - une commande acceptée d'ensembles réserve ses composants (ensembles imbriqués dépliés) ;
  - le stock et le disponible d'un ensemble sont le nombre d'ensembles que permettent ses composants ;
  - un manque de composants se voit sur l'ensemble (manques à l'acceptation, reliquats) et sur les composants, que
    les suggestions d'achat proposent alors de commander.

**Annexes** : des produits ajoutés d'office sur les lignes de vente du produit (la pile fournie avec l'appareil).
- La quantité est donnée par unité vendue, en unités de stock, arrondie à l'unité supérieure.
- Les lignes sont ajoutées sous la ligne du produit, au moment où on le choisit sur un devis, une commande ou une
  facture. On peut les modifier ou les retirer.

**Équivalents** : des substituts possibles, dans les deux sens, avec une remarque (« même format, autre marque »).
- Ajouter ou retirer un équivalent le fait des deux côtés.
- Quand un produit manque, les disponibilités donnent ses équivalents et leur disponible.

**Refusés** : le produit lui-même, un doublon, un produit désactivé.

**Droits** : ceux des produits. Consulter les liens demande le droit de voir les produits ; les ajouter, les changer
ou les retirer, celui de les modifier.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/produits/{id}/liens` | Composants, annexes, équivalents, et les ensembles qui contiennent le produit |
| POST | `/produits/{id}/liens` | Lier (`type` composant, annexe ou equivalent ; `lie_id` ; `quantite` ; `remarque`) |
| PUT / DELETE | `/produits/{id}/liens/{lien}` | Quantité ou remarque ; retrait |

La liste des produits (`GET /produits`) donne leurs `annexes` et leurs `composants`. Les disponibilités
(`GET /disponibilites`) donnent `ensemble` et `composants` pour un ensemble, et `equivalents` pour un produit en manque.

Code :
- service `App\Services\LiensProduits` (liens, compositions dépliées, ensembles contenant un produit) ;
- `ProduitLienController`, modèle `ProduitLien` ; `App\Services\DisponibiliteProduits` (ensembles, équivalents) ;
- web : `produits/FenetreLiensProduit`, `services/liensProduits.ts`, `annexesALaVente` (`lib/unitesVente.ts`) ;
- migration `2026_10_17_100075_creer_liens_produits` (table `produit_liens`).

## 18. Fiche produit détaillée (Inventaire → Produits)

Plan de parité fripv2, phase 2.4 : la fiche d'un produit gagne une section **Fiche détaillée** dans son formulaire.

- **Fabricant** : choisi dans le référentiel des fabricants (Paramètres → Référentiels). Un fabricant retiré du
  référentiel depuis reste sur les fiches qui le portaient, et ces fiches se réenregistrent. La liste des produits
  montre le fabricant et sa référence sous la description, et se filtre par fabricant.
- **Référence fabricant** : le code du produit chez son fabricant. La recherche de la liste et la recherche globale
  la trouvent.
- **Description longue** : spécifications, composition, mode d'emploi.
- **Traductions** : une description et une description longue par langue (anglais, français). Une traduction vidée
  est retirée.
- **Image principale** : une image jointe à la fiche (JPEG, PNG, WebP ou GIF ; jamais un SVG, qui peut porter du
  script). On la choisit parmi les images jointes, ou on en ajoute une depuis le formulaire : elle est jointe aussitôt.
  À la création, l'image se choisit une fois le produit créé. Supprimer la pièce jointe retire l'image de la fiche.

**Espace client** : le catalogue montre la description dans la langue du visiteur (sa traduction, sinon celle de la
fiche), avec la description longue (« Détails »), le fabricant et sa référence, et l'image. La recherche du catalogue
trouve aussi les descriptions traduites. L'image se lit avec la session du portail, et seulement pour un produit
proposé au client.

**Droits** : ceux des produits (modifier la fiche) ; les images sont des pièces jointes de la fiche.

| Méthode | Route | Rôle |
|---|---|---|
| POST / PUT | `/produits[/{id}]` | `fabricant_id`, `reference_fabricant`, `description_longue`, `image_id` (modification seulement), `traductions[]` (`langue`, `description`, `description_longue`) |
| GET | `/espace-client/catalogue?langue=en` | Catalogue dans la langue du visiteur : `description_longue`, `fabricant`, `reference_fabricant`, `image` (vrai ou faux) |
| GET | `/espace-client/produits/{id}/image` | Image principale d'un produit proposé au client |

Code :
- `ProduitTenantController` (règles et contrôle de l'image), modèles `ProduitTenant` (`descriptionEn`) et
  `ProduitTraduction` ; `App\Services\Portail\CommandesPortail::catalogue`, `PortailCommandeController::image` ;
- web : `produits/ChampsFicheProduit`, `lib/ficheProduit.ts` ; espace client : `CataloguePortail` ;
- migration `2026_10_17_100076_fiche_produit_enrichie` (colonnes de `produits`, table `produit_traductions`).
