# PARAMÈTRES — Flow complet & détail de chaque étape

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-04-10

---

## TABLE DES MATIÈRES

1. [Vue d'ensemble du module](#1-vue-densemble-du-module)
2. [Produits](#2-produits)
3. [Secteurs](#3-secteurs)
4. [Employés](#4-employés)
5. [Fiches Employés](#5-fiches-employés)
6. [Rôles & Permissions](#6-rôles--permissions)
7. [Configuration — Numérotation](#7-configuration--numérotation)
8. [Configuration — Entreprise](#8-configuration--entreprise)
9. [Configuration — Devise & TVA](#9-configuration--devise--tva)
10. [Configuration — Délais & Alertes](#10-configuration--délais--alertes)
11. [Ordre recommandé de configuration initiale](#11-ordre-recommandé-de-configuration-initiale)

---

## 1. Vue d'ensemble du module

Le module **Paramètres** est le socle de toute l'application. Tout le reste (Achats, Ventes, Finance) dépend de ce qui est configuré ici. Il faut le configurer **en premier**, avant de commencer à utiliser les autres modules.

```
PARAMÈTRES
├── Produits          ← le catalogue de tous les articles gérés
├── Secteurs          ← les zones physiques de l'entrepôt
├── Employés          ← les utilisateurs de l'application
├── Fiches Employés   ← détails RH complémentaires
├── Rôles             ← qui a le droit de faire quoi
└── Configuration     ← numérotation, entreprise, TVA, devise, délais, alertes
```

**Architecture multi-tenant :**
Chaque entreprise qui utilise l'application a ses propres données isolées (`tenant_id`). La configuration est donc par entreprise. Rien n'est partagé entre entreprises.

---

## 2. Produits

### 2.1 Ce que c'est

Un **Produit** est tout article qui peut être :
- acheté à un fournisseur
- vendu à un client
- stocké dans l'entrepôt
- scanné avec le téléphone

Le produit est l'entité centrale de tout le système.

### 2.2 Champs d'un produit

| Champ | Type | Description |
|-------|------|-------------|
| `numero` | string | Identifiant unique (ex: PRD-0001), généré automatiquement |
| `description` | string | Nom/description de l'article |
| `mesure` | enum | Unité de mesure : `UN` (unité), `KG`, `L`, `M`, `M²`, `M³` |
| `unite_achat` | string | Conditionnement fournisseur (ex: "Carton de 12") |
| `qte_par_unite_achat` | integer | Quantité par conditionnement (ex: 12) |
| `type` | string | Catégorie du produit |
| `secteur_id` | FK | Secteur de stockage par défaut |

### 2.3 Flow de création d'un produit

```
1. Cliquer sur "Nouveau produit"
2. Saisir la description (obligatoire)
3. Choisir l'unité de mesure
4. (optionnel) Définir le conditionnement fournisseur
5. (optionnel) Assigner un secteur par défaut
6. Sauvegarder → numéro auto-généré selon config numérotation
```

### 2.4 Numérotation automatique

Le numéro est généré par le système via la table `configurations` (entité = `produit`).
Format configurable : `[Préfixe][Séparateur][Numéro sur N chiffres][Suffixe]`
Exemple avec préfixe=`PRD`, séparateur=`-`, longueur=4 → `PRD-0001`, `PRD-0002`...

### 2.5 Import / Export CSV

- **Export** : télécharge tous les produits au format CSV
- **Import** : upload d'un fichier CSV pour créer/mettre à jour en masse

Format CSV attendu : `numero;description;mesure;type;secteur_code`

### 2.6 Relation avec les autres modules

```
Produit → utilisé dans les lignes de Commande Fournisseur
Produit → utilisé dans les lignes de Devis
Produit → utilisé dans les lignes de Commande Client
Produit → utilisé dans les lignes de Facture
Produit → utilisé dans les lignes de Bon de Livraison
Produit → scanné dans l'entrepôt (Scans, Relocalisation)
Produit → suivi en stock (Localisations, Mouvements)
```

### 2.7 Points d'attention

- Un produit ne peut pas être supprimé s'il est référencé dans des commandes ou des lignes de facturation.
- Le `secteur_id` du produit est son secteur "naturel" de rangement — il peut être stocké ailleurs en pratique.
- La `mesure` doit être cohérente avec la façon dont on achète et on vend (ex: acheter en `KG`, vendre en `KG`).

---

## 3. Secteurs

### 3.1 Ce que c'est

Un **Secteur** est une zone physique de l'entrepôt. Il peut représenter :
- Une allée (ex: "Allée A")
- Un rayon (ex: "Rayon Froid")
- Un emplacement (ex: "Zone B2")
- Une zone spéciale (ex: "Zone Réception", "Zone Quarantaine")

Les secteurs servent à deux choses :
1. **Localiser** les produits dans l'entrepôt
2. **Scanner** les produits via QR code sur le téléphone

### 3.2 Champs d'un secteur

| Champ | Type | Description |
|-------|------|-------------|
| `code` | string | Code court unique (ex: "A1", "COLD") |
| `nom` | string | Nom lisible (ex: "Allée A - Rangée 1") |
| `description` | string | Description complémentaire |

### 3.3 Flow de création d'un secteur

```
1. Cliquer sur "Nouveau secteur"
2. Saisir le code (unique, obligatoire)
3. Saisir le nom
4. (optionnel) Ajouter une description
5. Sauvegarder
6. (optionnel) Générer le QR code pour affichage physique
```

### 3.4 QR Code

Chaque secteur peut avoir un QR code unique :
- Généré via le bouton "Générer QR" sur la fiche secteur
- À imprimer et coller physiquement dans l'entrepôt
- Scannable avec l'application mobile
- Le QR code contient l'identifiant du secteur → permet de savoir où on est quand on scanne

**Endpoint :** `POST /secteurs/{id}/generate-qr`

### 3.5 Relation avec les autres modules

```
Secteur → les produits sont "localisés" dans des secteurs (table produit_localisation)
Secteur → les réceptions assignent un secteur de rangement
Secteur → les mouvements de stock trackent les secteurs source/destination
Secteur → les scans mobile se font "dans" un secteur
```

---

## 4. Employés

### 4.1 Ce que c'est

Un **Employé** est une personne qui utilise l'application. Chaque employé :
- A un compte de connexion (email + mot de passe)
- A un rôle qui définit ses droits
- Est tracé dans les logs d'audit

### 4.2 Champs d'un employé

| Champ | Type | Description |
|-------|------|-------------|
| `numero` | string | Identifiant unique auto-généré |
| `nom` | string | Nom de famille |
| `prenom` | string | Prénom |
| `email` | string | Email de connexion (unique) |
| `role` | enum | `user`, `manager`, `admin` |
| `password` | string | Mot de passe (hashé en base) |

### 4.3 Rôles disponibles

| Rôle | Droits |
|------|--------|
| `user` | Scans, lecture, opérations basiques |
| `manager` | + Validation, approbations, rapports |
| `admin` | Tout — gestion complète |

### 4.4 Flow de création d'un employé

```
1. Cliquer sur "Nouvel employé"
2. Saisir nom, prénom
3. Saisir l'email (sera l'identifiant de connexion)
4. Définir le mot de passe initial
5. Choisir le rôle
6. Sauvegarder → numéro auto-généré
7. Communiquer l'email + mot de passe à l'employé
```

### 4.5 Désactivation vs Suppression

- La suppression via l'API **désactive** l'employé (soft delete ou `actif = false`)
- Un employé désactivé ne peut plus se connecter
- Son historique (scans, logs) est conservé

### 4.6 Import / Export

Même logique que les produits : export CSV et import CSV en masse.

---

## 5. Fiches Employés

Section complémentaire aux Employés, dédiée aux informations RH détaillées :
- Poste occupé
- Date d'embauche
- Contrat
- Informations de contact d'urgence

> Ces données ne sont pas utilisées dans les workflows métier — elles sont à usage informatif/RH uniquement.

---

## 6. Rôles & Permissions

### 6.1 Système de rôles

L'application a **3 rôles de base** (user, manager, admin) plus la possibilité de créer des **rôles personnalisés**.

### 6.2 Rôles personnalisés

Via `GET/POST/PUT/DELETE /roles-custom/{id}`, on peut créer des rôles sur mesure et leur assigner des permissions granulaires par module.

**Exemple :** Créer un rôle "Préparateur BL" qui peut seulement :
- Voir les bons de livraison
- Démarrer la préparation
- Marquer un BL comme prêt

### 6.3 Assignation

`POST /users/{userId}/assign-role` pour changer le rôle d'un utilisateur.

---

## 7. Configuration — Numérotation

### 7.1 Ce que c'est

La numérotation automatique génère les numéros de documents dans tout le système. Elle est configurable pour chaque type de document :

| Entité | Exemple de numéro |
|--------|-------------------|
| `produit` | PRD-0001 |
| `employe` | EMP-0001 |
| `secteur` | SEC-001 |
| `fournisseur` | FRN-0001 |
| `client` | CLT-0001 |
| `devis` | DEV-2026-0001 |
| `commande` | CMD-2026-0001 |
| `facture` | FAC-2026-0001 |
| `bon_livraison` | BL-2026-0001 |
| `tournee` | TRN-2026-0001 |

### 7.2 Format configurable

```
[Préfixe][Séparateur][Numéro][Séparateur][Suffixe]
```

| Paramètre | Description | Exemple |
|-----------|-------------|---------|
| `prefixe` | Texte avant le numéro | `FAC` |
| `separateur` | Tiret ou autre | `-` |
| `longueur` | Nb de chiffres (avec zéros) | `4` → `0001` |
| `prochain_numero` | Prochain numéro à utiliser | `42` |
| `suffixe` | Texte après (optionnel) | `2026` |
| `auto_increment` | Activer/désactiver | `true` |

### 7.3 Comment ça fonctionne dans le code

```
Table: configurations
Méthodes:
  Configuration::pourEntite('facture', $tenantId) → récupère la config
  $config->genererNumero()                         → génère le numéro suivant
  $config->incrementer()                           → +1 au prochain_numero
```

### 7.4 Prévisualisation

La page de configuration affiche un aperçu du prochain numéro en temps réel pendant la saisie.

### 7.5 Points d'attention

- Si `auto_increment = false`, le numéro doit être saisi manuellement à la création de chaque document.
- Si deux documents sont créés en même temps, la transaction DB évite les doublons.
- Changer `prochain_numero` en cours de vie peut créer des trous dans la numérotation (ex: passer de 42 à 100 laissera les numéros 43-99 non utilisés). C'est intentionnel et légal.

---

## 8. Configuration — Entreprise

### 8.1 Champs

| Champ | Description | Utilisé dans |
|-------|-------------|--------------|
| `nom_entreprise` | Raison sociale | En-tête PDFs |
| `adresse` | Adresse complète | En-tête PDFs |
| `telephone` | Numéro de téléphone | En-tête PDFs |
| `email` | Email de contact | En-tête PDFs |
| `siret` | Numéro SIRET | Pied de page PDFs |
| `tva_numero` | Numéro TVA intracommunautaire | Pied de page PDFs |

### 8.2 À quoi ça sert

Ces informations apparaissent sur tous les documents imprimés : bons de commande, factures, bons de livraison.

**Endpoint :** `GET/PUT /parametres`

---

## 9. Configuration — Devise & TVA

### 9.1 Devise

| Champ | Description | Exemple |
|-------|-------------|---------|
| `devise_symbole` | Symbole affiché | `€`, `$`, `FCFA` |
| `devise_code` | Code ISO 4217 | `EUR`, `USD`, `XAF` |

La devise est affichée sur toutes les pages de montant : factures, commandes, devis...

### 9.2 Taxes

L'application supporte plusieurs taux de TVA ou taxes différentes.

| Champ | Description |
|-------|-------------|
| `nom` | Nom de la taxe (ex: "TVA 20%") |
| `taux` | Taux en % (ex: 20) |
| `defaut` | Si true, appliqué par défaut sur les lignes |

**Endpoints :** `GET/POST/PUT/DELETE /taxes`

**Exemple :** Une entreprise au Canada peut avoir TVA + TPS + TVQ comme trois taxes distinctes.

### 9.3 Comment les taxes s'appliquent

Sur chaque ligne de commande/facture :
```
montant_ht = quantite × prix_unitaire_ht × (1 - remise_ligne/100)
montant_tva = montant_ht × (taux_tva/100)
montant_ttc = montant_ht + montant_tva
```

---

## 10. Configuration — Délais & Alertes

### 10.1 Délais par défaut

| Paramètre | Description | Impact |
|-----------|-------------|--------|
| `delai_paiement_jours` | Délai standard paiement facture | Pré-remplit `date_echeance` sur les factures |
| `delai_livraison_jours` | Délai standard livraison fournisseur | Pré-remplit `date_livraison_prevue` sur les commandes fournisseur |

### 10.2 Alertes stock

| Paramètre | Description |
|-----------|-------------|
| `stock_seuil_defaut` | Quantité minimale déclenchant une alerte (ex: 5) |
| `stock_alerte_email` | Email qui reçoit les notifications de stock bas |

Les alertes sont vérifiées à chaque mouvement de stock.

---

## 11. Ordre recommandé de configuration initiale

Lors de la mise en place de l'application pour une nouvelle entreprise, voici l'ordre optimal :

```
Étape 1 → Configuration Entreprise (nom, adresse, SIRET...)
Étape 2 → Configuration Devise & TVA (quelle monnaie, quels taux)
Étape 3 → Configuration Numérotation (format des numéros de documents)
Étape 4 → Configuration Délais & Alertes
Étape 5 → Créer les Secteurs de l'entrepôt
Étape 6 → Créer les Produits du catalogue
Étape 7 → Créer les Employés et leurs rôles
Étape 8 → Commencer les Achats / Ventes
```

> Si on commence les Ventes sans avoir configuré la numérotation, les numéros seront générés avec les valeurs par défaut (ce qui peut ne pas correspondre à la charte de l'entreprise).
