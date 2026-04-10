# FINANCE — Flow complet & détail de chaque étape

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-04-10

---

## TABLE DES MATIÈRES

1. [Vue d'ensemble du module](#1-vue-densemble-du-module)
2. [Comptabilité — suivi financier](#2-comptabilité--suivi-financier)
3. [Gestion des prix](#3-gestion-des-prix)
4. [Prévisions de stock](#4-prévisions-de-stock)
5. [Alertes financières](#5-alertes-financières)
6. [Taux de change](#6-taux-de-change)
7. [Taxes et TVA](#7-taxes-et-tva)
8. [Encours clients](#8-encours-clients)
9. [Tableau de bord financier](#9-tableau-de-bord-financier)

---

## 1. Vue d'ensemble du module

Le module **Finance** consolide toutes les données financières de l'application. Il n'est pas un système comptable complet (pas de plan de comptes, pas de grand livre) mais une **vue financière** de l'activité commerciale.

```
FINANCE
├── Comptabilité     ← synthèse des encaissements et factures
├── Prévisions Stock ← valeur du stock et projections
├── Gestion Prix     ← prix de vente et marges
└── Alertes          ← alertes sur les créances, délais, encours
```

**Sources de données :**
Le module Finance lit les données créées dans les modules Achats et Ventes :
- Les **factures** (montants, paiements, échéances)
- Les **commandes** (CA prévisionnel)
- Les **encours clients** (créances en cours)
- Les **achats** (coûts d'achat, valeur stock)

---

## 2. Comptabilité — suivi financier

### 2.1 Ce que c'est

La page Comptabilité offre une synthèse financière de l'activité :
- Chiffre d'affaires facturé
- Encaissements reçus
- Créances en attente
- Factures en retard

### 2.2 Indicateurs clés

| Indicateur | Calcul | Source |
|------------|--------|--------|
| CA total facturé | Somme `montant_ttc` des factures émises | Table `factures` |
| CA encaissé | Somme de tous les paiements | Table `facture_paiements` |
| Reste à encaisser | `CA total - CA encaissé` | Calculé |
| Factures en retard | Factures avec `date_echeance < aujourd'hui` et `reste_a_payer > 0` | Calculé |

### 2.3 Filtres disponibles

- Par période (mois, trimestre, année)
- Par client
- Par statut de paiement

### 2.4 Enregistrement des paiements

Les paiements s'enregistrent depuis la page **Factures** (module Ventes) :

```
Endpoint: POST /factures/{id}/paiement
Body: {
  montant: 1500.00,
  date_paiement: "2026-04-10",
  mode_paiement: "virement",
  reference: "VIR-2026-042"
}
```

**Modes de paiement supportés :**
- `virement` — virement bancaire
- `cheque` — chèque
- `carte` — carte bancaire
- `especes` — espèces
- `autre` — tout autre mode

**Après enregistrement :**
1. `montant_paye` est recalculé (somme de tous les paiements)
2. `reste_a_payer = montant_ttc - montant_paye`
3. Si `reste_a_payer = 0` → statut `payee`
4. Si `reste_a_payer > 0 && montant_paye > 0` → statut `partiellement_payee`
5. L'encours du client est recalculé automatiquement

### 2.5 Historique des paiements

Pour chaque facture, on peut voir l'historique de tous les paiements avec dates et références. Cela permet de tracer :
- Qui a payé quand
- Par quel mode
- En combien de fois

---

## 3. Gestion des prix

### 3.1 Prix d'achat

Le prix d'achat est défini dans la relation **Fournisseur ↔ Produit** :

| Champ | Description |
|-------|-------------|
| `prix_achat` | Prix d'achat habituel chez ce fournisseur |
| `fournisseur_principal` | Ce fournisseur est le principal pour ce produit |

Le prix d'achat sert de référence pour calculer la marge brute.

### 3.2 Prix de vente

Le prix de vente est libre — il est saisi ligne par ligne dans :
- Les **Devis** (`prix_unitaire` par ligne)
- Les **Commandes Client** (`prix_unitaire_ht` par ligne)

Il n'y a pas de catalogue de prix de vente fixe pour l'instant — chaque devis/commande peut avoir des prix différents.

### 3.3 Remises

Il y a deux niveaux de remise dans le module Ventes :

**Remise par ligne** (sur chaque article) :
```
montant_ht = quantite × prix_unitaire_ht × (1 - remise_ligne/100)
```

**Remise globale** (sur toute la commande) :
```
total_ht = somme_ht × (1 - remise_globale/100)
```

**Remise client** :
- `taux_remise_global` sur la fiche client peut être appliqué automatiquement lors de la création d'une commande

### 3.4 Calcul de marge

La marge brute par produit peut être calculée si le prix d'achat est renseigné :
```
marge_brute = prix_vente_ht - prix_achat
marge_brute_% = (marge_brute / prix_achat) × 100
```

---

## 4. Prévisions de stock

### 4.1 Ce que c'est

Les prévisions de stock permettent d'anticiper les besoins d'achat en croisant :
- Le stock actuel
- Les commandes clients à venir (besoins futurs)
- Les commandes fournisseurs en cours (stock attendu)

### 4.2 Calcul prévisionnel

```
Stock projeté =
  stock_actuel
  + quantite_commandée_fournisseur (commandes envoyées non encore reçues)
  - quantite_commandée_client (commandes acceptées non encore livrées)
```

### 4.3 Valeur du stock

La valeur du stock est calculée par :
```
valeur_stock = quantite_en_stock × prix_achat_moyen
```

---

## 5. Alertes financières

### 5.1 Types d'alertes

| Type | Déclenchement | Action recommandée |
|------|--------------|-------------------|
| Facture en retard | `date_echeance < aujourd'hui` et `reste_a_payer > 0` | Relancer le client |
| Encours dépassé | `encours_actuel > encours_max` | Bloquer nouvelles commandes |
| Paiement partiel ancien | Paiement partiel + ancienneté > X jours | Relancer pour solde |

### 5.2 Notification par email

Configurable dans **Paramètres → Configuration → Alertes** :
- `stock_alerte_email` : email pour les alertes de stock bas
- Les alertes financières utilisent le même email de contact

### 5.3 Endpoint alertes

```
GET  /alertes       → liste des alertes actives
GET  /alertes/stats → statistiques (nb alertes par type)
PUT  /alertes       → modifier seuils
```

---

## 6. Taux de change

### 6.1 Principe

L'application supporte les achats en devise étrangère. Le taux de change est utilisé quand :
- Un fournisseur facture en USD, GBP, XAF, CAD... alors que l'entreprise est en EUR
- La commande fournisseur est dans la devise du fournisseur

### 6.2 Source du taux

L'API externe **Frankfurter** (`api.frankfurter.app`) fournit les taux en temps réel.

```
Endpoint interne: GET /taux-change?from=USD&to=EUR
Réponse: { taux: 0.9234, date: "2026-04-10" }
```

### 6.3 Stockage

Le taux est **figé au moment de la création de la commande** et stocké dans `comfour_entete.taux_change`. Il ne change pas rétroactivement — la commande garde le taux du jour de sa création.

### 6.4 Devises supportées

Toutes les devises ISO 4217 supportées par Frankfurter :
`EUR`, `USD`, `GBP`, `CAD`, `XAF` (Franc CFA), `JPY`, `CHF`...

---

## 7. Taxes et TVA

### 7.1 Configuration des taxes

Les taxes sont configurées dans **Paramètres → Configuration → Devise & TVA** :

| Champ | Description |
|-------|-------------|
| `nom` | Ex: "TVA 20%", "TVA réduite 10%", "TPS Canada" |
| `taux` | Taux en % (ex: 20, 10, 5.5) |
| `defaut` | La taxe par défaut pour les nouvelles lignes |

### 7.2 Application sur les lignes

Sur chaque ligne de commande ou facture :
```
montant_ht  = quantite × prix_unitaire_ht × (1 - remise_ligne/100)
montant_tva = montant_ht × (taux_tva / 100)
montant_ttc = montant_ht + montant_tva
```

Le taux TVA est choisi par ligne — on peut avoir plusieurs taux sur la même facture (ex: certains produits à 20%, d'autres à 5.5%).

### 7.3 Récapitulatif TVA sur facture

Sur une facture avec plusieurs taux de TVA :
```
Base HT 20% : 1000€  → TVA 200€
Base HT 5.5%:  500€  → TVA  27.5€
─────────────────────────────────
Total HT    : 1500€
Total TVA   :  227.5€
Total TTC   : 1727.5€
```

### 7.4 Endpoints

```
GET    /taxes        → liste toutes les taxes
POST   /taxes        → créer une taxe
PUT    /taxes/{id}   → modifier
DELETE /taxes/{id}   → supprimer
```

---

## 8. Encours clients

### 8.1 Principe

L'**encours** d'un client représente le montant total qu'il doit actuellement. Il est central pour la gestion du risque crédit.

### 8.2 Calcul

```
encours_actuel = SUM(reste_a_payer)
                 pour toutes les factures du client
                 dont le statut ≠ 'payee' et ≠ 'annulee'
```

Méthode backend : `Client::updateEncours()`

### 8.3 Mise à jour automatique

L'encours est recalculé automatiquement :
- À chaque enregistrement de paiement
- À chaque émission de facture
- À chaque livraison partielle (nouvelle facture de reliquat)

### 8.4 Plafond de crédit

Le `encours_max` définit le montant maximum que l'entreprise accepte de laisser impayé pour ce client.

```
Vérification: Client::peutCommander($montantNouvelleCommande)
  → true  si encours_actuel + montant ≤ encours_max
  → true  si encours_max = 0 (pas de limite)
  → false sinon
```

### 8.5 Affichage

Sur la page Clients :
- `encours_actuel` affiché en rouge si > 80% du plafond
- `encours_max` affiché pour comparaison
- Barre de progression visuelle optionnelle

---

## 9. Tableau de bord financier

### 9.1 Données du dashboard

`GET /dashboard/stats` retourne un ensemble de KPIs :

| KPI | Description |
|-----|-------------|
| Chiffre d'affaires du mois | Somme des factures émises ce mois |
| Encaissements du mois | Paiements reçus ce mois |
| Factures en attente | Nb et montant des factures non payées |
| Clients en dépassement | Clients dont encours > encours_max |
| Commandes fournisseur en attente | Nb commandes envoyées non reçues |
| Valeur du stock | Calculée sur base des localisations |

### 9.2 Périodes

Le dashboard peut être filtré par :
- Aujourd'hui
- Cette semaine
- Ce mois
- Ce trimestre
- Cette année

---

## Points d'attention globaux

1. **Les montants sont stockés en décimal** dans la base de données mais retournés comme **strings** par l'API Laravel. Dans le frontend, toujours utiliser `Number(valeur ?? 0).toFixed(2)` pour éviter les erreurs `toFixed is not a function`.

2. **La TVA est gérée ligne par ligne** — une facture peut avoir plusieurs taux de TVA différents sur ses lignes.

3. **Le taux de change est figé** à la création de la commande fournisseur. Il n'est pas recalculé si le taux change après.

4. **L'encours client est recalculé dynamiquement** — il n'est pas stocké comme une valeur fixe mais recalculé à la demande depuis les factures ouvertes.

5. **Il n'y a pas de comptabilité générale** — pas de journal, pas de grand livre, pas d'intégration avec un logiciel comptable. Ce module est un suivi commercial financier, pas un outil comptable certifié.
