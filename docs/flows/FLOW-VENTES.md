# VENTES — Flow complet & détail de chaque étape

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-18 — représentants commerciaux et commissions (§2.7). 2026-10-17 : consignation chez le client (§5.18) ; facturation à l'usage (§5.16) ; contrats de location (§5.17) ; abonnements clients et facturation récurrente (§5.16)

---

## TABLE DES MATIÈRES

1. [Vue d'ensemble du module](#1-vue-densemble-du-module)
2. [Isolation multi-tenant](#2-isolation-multi-tenant)
3. [Clients](#3-clients)
4. [Devis](#4-devis)
5. [Commandes Client](#5-commandes-client)
6. [Factures](#6-factures)
7. [Bons de Livraison](#7-bons-de-livraison)
8. [Camions](#8-camions)
9. [Tournées](#9-tournées)
10. [Flow complet bout en bout](#10-flow-complet-bout-en-bout)
11. [Tous les statuts et transitions](#11-tous-les-statuts-et-transitions)
12. [Livraison partielle — cas particulier](#12-livraison-partielle--cas-particulier)
13. [Documents PDF](#13-documents-pdf)
14. [Écrans de vente](#14-écrans-de-vente--règles-daffichage-communes)
15. [Unités de vente](#15-unités-de-vente-paramètres--produits--unités)

---

## 1. Vue d'ensemble du module

Le module **Ventes** couvre tout le cycle commercial, de la proposition commerciale jusqu'à la livraison chez le client.

```
VENTES
├── Clients           ← qui achète
├── Devis             ← proposition commerciale
├── Commandes Client  ← confirmation d'achat
├── Factures          ← document de facturation + paiement
├── Bons de Livraison ← préparation des articles à livrer
├── Camions           ← véhicules de livraison
└── Tournées          ← regroupement de plusieurs BL pour un même camion
```

**Le flow principal :**

```
Client → Devis → Commande → Facture → Bon de Livraison → Tournée → Livraison
```

Chaque étape est optionnelle selon les cas :
- On peut créer une Commande sans passer par un Devis
- On peut créer une Facture directement
- Un Bon de Livraison peut être livré hors tournée

---

---

## 2. Isolation multi-tenant

Toutes les tables du module Ventes contiennent une colonne `tenant_id`. Chaque enregistrement appartient à un seul tenant (entreprise). Les données ne sont jamais partagées entre tenants.

### Tables concernées

| Table | tenant_id | Rôle |
|-------|-----------|------|
| `clients` | ✅ | Client appartient à un tenant |
| `com_client_entete` | ✅ | Commande appartient à un tenant |
| `factures` | ✅ | Facture appartient à un tenant |
| `bons_livraison` | ✅ | BL appartient à un tenant |
| `tournees` | ✅ | Tournée appartient à un tenant |
| `camions` | ✅ | Camion appartient à un tenant |
| `devis` | ✅ | Devis appartient à un tenant |

Les tables enfants (`com_client_ligne`, `facture_lignes`, `bon_livraison_lignes`, etc.) **n'ont pas** de `tenant_id` — elles sont toujours accédées via leur parent qui a déjà le filtre.

### Fonctionnement

```php
// Lecture → filtre automatique sur tenant_id
$tenantId = auth()->user()->tenant_id;
$query = Model::where('tenant_id', $tenantId);

// Création → tenant_id injecté automatiquement
Model::create(['tenant_id' => $tenantId, ...]);
```

Le `tenant_id` est déterminé par l'utilisateur connecté (`auth()->user()->tenant_id`), lui-même rattaché à son tenant via le middleware `IdentifyTenant`.

### Migration

Migration : `2026_04_10_100001_add_tenant_id_to_ventes_tables.php`

---

## TABLEAU DES ACTIONS PAR MODULE (mis à jour)

### Clients

| Action | Disponible | Condition |
|--------|-----------|-----------|
| Créer | ✅ | Toujours |
| Modifier | ✅ | Toujours |
| Supprimer/Désactiver | ✅ | Toujours |

### Devis

| Action | Disponible | Condition |
|--------|-----------|-----------|
| Créer | ✅ | Toujours |
| Modifier (lignes, prix) | ✅ | Statut `brouillon` |
| Supprimer | ✅ | Statut `brouillon` |
| Envoyer | ✅ | Statut `brouillon` |
| Accepter | ✅ | Statut `envoye` |
| Refuser | ✅ | Statut `envoye` |
| Convertir en commande | ✅ | Statut `accepte` |

### Commandes Client

| Action | Disponible | Condition |
|--------|-----------|-----------|
| Créer | ✅ | Toujours |
| Modifier (lignes, remises) | ✅ | Statut `brouillon` — bouton ✏️ |
| Supprimer | ✅ | Statut `brouillon` |
| Soumettre | ✅ | Statut `brouillon` |
| Accepter | ✅ | Statut `soumise` |
| Refuser | ✅ | Statut `soumise` |
| Créer facture | ✅ | Statut `acceptee` |

### Factures

| Action | Disponible | Condition |
|--------|-----------|-----------|
| Créer depuis commande | ✅ | Commande `acceptee` |
| Créer manuellement | ✅ | Toujours — bouton "Nouvelle facture" |
| Supprimer | ✅ | Statut `brouillon` |
| Émettre | ✅ | Statut `brouillon` |
| Enregistrer paiement | ✅ | Statuts `emise` ou `partiellement_payee` |
| Créditer (note de crédit) | ✅ | Statuts `emise`, `partiellement_payee` ou `payee` (§5.8) |
| Payer avec un acompte | ✅ | Statuts `emise` ou `partiellement_payee`, acompte disponible du client (§5.9) |
| Encaisser avec d'autres factures du client | ✅ | Statuts `emise` ou `partiellement_payee` : un encaissement réparti sur plusieurs factures (§7 quater) |
| Créer BL | ✅ | Statut `emise`, si aucun BL existant |

### Bons de Livraison

| Action | Disponible | Condition |
|--------|-----------|-----------|
| Créer | ✅ | Automatique depuis une facture |
| Annuler | ✅ | Statuts `cree` ou `en_preparation` — bouton 🚫 |
| Démarrer préparation | ✅ | Statut `cree` |
| Modifier quantités préparées | ✅ | Statut `en_preparation` |
| Marquer prêt | ✅ | Statut `en_preparation` |
| Préparer les colis | ✅ | Statuts `cree`, `en_preparation` ou `pret` — bouton Colis (§6.3d) |
| Enregistrer livraison | ✅ | Statut `en_livraison` |

### Tournées

| Action | Disponible | Condition |
|--------|-----------|-----------|
| Créer | ✅ | Toujours |
| Supprimer | ✅ | Statut `planifiee` — bouton 🗑️ |
| Ajouter BL | ✅ | Statut `planifiee`, BL en `pret` |
| Retirer BL | ✅ | Statut `planifiee` — bouton ➖ dans modal détail |
| Démarrer | ✅ | Statut `planifiee` + au moins 1 BL (avertissement si le chargement n'est pas fini, §8.9) |
| Charger le camion | ✅ | Statut `planifiee` — bouton Chargement (§8.9) |
| Terminer | ✅ | Statut `en_cours` |

---

## 2. Clients

### 2.1 Ce que c'est

Un **Client** est une entreprise ou personne qui achète nos produits. Il est le point central de tout le module ventes.

### 2.2 Champs d'un client

| Champ | Description | Obligatoire |
|-------|-------------|-------------|
| `code` | Identifiant unique (ex: CLT-0001) | Oui (auto) |
| `raison_sociale` | Nom de l'entreprise | Oui |
| `adresse_facturation` | Adresse pour les factures | Non |
| `adresse_livraison` | Adresse de livraison (si différente) | Non |
| `ville` | Ville | Non |
| `code_postal` | Code postal | Non |
| `telephone` | Téléphone | Non |
| `email` | Email | Non |
| `contact_nom` | Nom du contact principal | Non |
| `contact_telephone` | Téléphone du contact | Non |
| `encours_max` | Plafond de crédit accordé (€) | Non |
| `encours_actuel` | Montant actuellement dû (calculé auto) | - |
| `taux_remise_global` | Remise en % applicable à tous ses achats | Non |
| `actif` | Actif ou inactif | Oui |
| `de_passage` | Fiche du client de passage (§2.6) | - |
| `itineraire_id`, `sequence_livraison` | Route de livraison et rang de passage (§8.7b) | Non |

### 2.3 Encours client

L'**encours** est le montant total que le client doit actuellement (factures émises non entièrement payées).

- `encours_actuel` = somme des `reste_a_payer` de toutes ses factures non soldées
- Mis à jour automatiquement à chaque paiement enregistré (`updateEncours()`)
- Comparé à `encours_max` pour décider si une nouvelle commande est autorisée

**Vérification :** `Client::peutCommander($montant)` retourne `true` si `encours_actuel + montant ≤ encours_max` (ou si `encours_max = 0`, pas de limite).

### 2.4 Conditions de paiement

Via `GET/POST /clients/{id}/conditions-paiement`, on peut définir des échéances de paiement personnalisées pour ce client (ex: payer en 3 fois).

### 2.5 Endpoints

```
GET    /clients          → liste avec pagination
GET    /clients/actifs   → liste pour les selects
GET    /clients/de-passage → fiche du client de passage (créée à sa première utilisation)
POST   /clients          → créer
PUT    /clients/{id}     → modifier
DELETE /clients/{id}     → désactiver
GET    /clients/{id}/conditions-paiement  → voir les conditions
POST   /clients/{id}/conditions-paiement → définir les conditions
```

### 2.6 Client de passage (ventes au comptoir)

Plan de parité fripv2, phase 1.1 : le « client comptant » de fripv2. On vend à quelqu'un qui n'a pas de fiche sans en créer une à chaque vente.

**La fiche :**
- une seule par entreprise, marquée `de_passage`, avec le code `PASSAGE` (`PASSAGE-2`… si ce code est déjà pris) et la raison sociale « Client de passage » ;
- elle est créée à sa première utilisation par `GET /clients/de-passage`, qui la renvoie ensuite ;
- c'est une fiche comme les autres : devise, condition de paiement et frais de livraison se règlent dessus et valent pour toutes les ventes de passage. Les listes la signalent par la pastille « De passage ».

**Dans les formulaires de devis, de commande et de facture :**
- le bouton **Client de passage**, sous la liste des clients, choisit cette fiche ;
- quatre champs facultatifs apparaissent : nom, téléphone, courriel, adresse (`passage_nom`, `passage_telephone`, `passage_courriel`, `passage_adresse`).

**Ce que deviennent ces coordonnées :**
- elles suivent les documents : devis → commande (conversion) → facture (depuis la commande) ;
- sur le PDF, le destinataire est le nom noté, avec son adresse, son téléphone et son courriel ; une note de crédit et un bon de livraison reprennent ceux de leur facture ;
- dans le courriel, `{client_nom}` vaut le nom noté et le destinataire proposé est le courriel noté (celui de la facture pour une note de crédit, un bon de livraison ou une relance) ;
- les listes de devis, de commandes et de factures affichent le nom noté ; la recherche globale et celle des devis trouvent le document par ce nom.

**Règles :**
- les coordonnées ne sont retenues que pour la fiche de passage : pour un autre client, elles sont ignorées ;
- changer le client d'un document efface ses coordonnées ;
- une modification qui ne les transmet pas les conserve ; une modification qui en transmet une partie ne change que celles-là.

**Droits :** ceux des documents de vente. Lire la fiche, et la créer à sa première utilisation, est ouvert à qui travaille dans le module Ventes, comme `GET /clients/actifs`.

Code :
- API : service `App\Services\ClientsDePassage`, migration `2026_10_16_100039_client_de_passage` (colonne `clients.de_passage`, coordonnées sur `devis`, `com_client_entete` et `factures`) ;
- web : composants `ClientDePassage` (bouton, champs, détail, pastille) et `lib/clientDePassage`.

### 2.7 Représentants commerciaux et commissions

Plan de parité fripv2, phase 1.11a. Les représentants (Paramètres → Référentiels → Représentants : nom, taux de
commission, territoire) se rattachent aux clients et aux documents de vente.

**Sur le client :** champ « Représentant » de sa fiche (`clients.representant_id`). La liste des clients se filtre par
représentant, et sa fiche l'affiche.

**Sur les devis, commandes et factures :**
- à la création, le représentant du client est proposé (écran) et retenu par l'API si la demande n'en donne pas ; on peut
  en choisir un autre, ou aucun ;
- il suit le document d'origine : devis → commande (conversion) → facture, devis facturé directement, copie d'un devis.
  Une commande passée dans l'espace client prend celui du client ;
- les factures créées par l'application (reliquats, abonnements, loyers, consignation, comptoir) prennent celui du client ;
- un brouillon de devis ou de commande se modifie : le représentant se change ou se retire ;
- les listes se filtrent par représentant ; le détail et le PDF l'affichent (« Représentant » parmi les informations).

**Commission :** la facture fige le taux de commission du représentant à sa création (`factures.taux_commission`) ; un
changement de taux ne touche pas les factures déjà faites.

**Rapport « Ventes par représentant »** (Bibliothèque de rapports, ventes ; droit de voir les factures) : sur la période,
par représentant, le nombre de factures émises, leurs ventes hors taxes, les notes de crédit émises sur ces factures,
les ventes nettes et la commission (ventes × taux de chaque facture, moins les notes de crédit au taux de leur facture).
Les ventes sans représentant forment une ligne à part ; filtre facultatif sur un représentant.

**Référentiel :** un représentant rattaché à un client ou à un document de vente ne se supprime pas (on le désactive) :
il garde l'attribution des ventes et des commissions. Un représentant désactivé n'est plus proposé, mais reste affiché
sur les fiches et les documents qui l'ont.

Code :
- API : service `App\Services\EntetesVente` (création, modification, copie, taux de commission), rapport
  `App\Rapports\VentesParRepresentant`, migration `2026_10_18_100089_representants_ventes` ;
- web : `hooks/useRepresentants`, composant `ChoixRepresentant`.

### 2.8 Transporteurs et numéros de suivi

Plan de parité fripv2, phase 1.11b (première partie). Les transporteurs (Paramètres → Référentiels → Transporteurs :
nom, téléphone, courriel, lien de suivi avec `{numero}`, numéro de compte) se rattachent aux tiers et aux documents.
Tant que l'entreprise n'a aucun transporteur actif, les champs restent masqués (sauf sur une fiche qui en a déjà un).

**Sur le client et le fournisseur :** champ « Transporteur » de la fiche (`clients.transporteur_id`,
`fournisseurs.transporteur_id`). La liste des clients se filtre par transporteur, et la fiche client l'affiche.

**Sur les devis, commandes, factures et bons de livraison** (même mécanique que le représentant, §2.7, service
`EntetesVente`) :
- à la création, celui du client est proposé (écran) et retenu par l'API si la demande n'en donne pas ; on peut en
  choisir un autre, ou aucun ;
- il suit le document d'origine : devis → commande → facture, devis facturé directement, copie d'un devis, et le bon de
  livraison créé depuis la facture. Une commande passée dans l'espace client prend celui du client ; une vente au
  comptoir n'en a pas ;
- les listes (devis, commandes, factures, bons de livraison) se filtrent par transporteur.

**Numéro de suivi** (connaissement, 60 caractères) : saisi sur la commande ou la facture, il passe de la commande à la
facture puis au bon de livraison. Sur le bon, **Expédition** (détail du bon) change le transporteur et le numéro tant
que le bon n'est ni livré ni annulé (`PUT /bons-livraison/{id}/expedition`, droit « Modifier » des bons de livraison) ;
un bon retiré par le client n'a pas de transporteur. Chaque colis peut avoir son propre numéro de suivi (fenêtre des
colis, étiquette). Le lien « Suivre l'envoi » ouvre le lien de suivi du transporteur, `{numero}` remplacé par le numéro.

**Commandes fournisseur :** à la création, le transporteur du fournisseur, quelle que soit l'origine de la commande
(saisie, reliquats clients, suggestions d'achat) ; une valeur donnée, même vide, est gardée. La liste se filtre par
transporteur.

**Référentiel :** un transporteur rattaché à un client, un fournisseur ou un document ne se supprime pas (on le
désactive). Un transporteur désactivé n'est plus proposé, mais reste affiché sur les fiches et les documents qui l'ont.

**Frais** (fiche du transporteur, Paramètres → Référentiels → Transporteurs) :
- **Livraison facturée** (cochée par défaut, colonne de la liste) : décochée, le frais de livraison du client n'est pas
  proposé sur les devis, commandes et factures expédiés par ce transporteur (`POST /frais/calculer` reçoit
  `transporteur_id`). Changer de transporteur pendant la saisie relance le calcul ; un document sans transporteur reçoit
  le frais ; une ligne de frais retirée n'est pas reposée ;
- **Frais de transport (achats)** : un frais de livraison (Paramètres → Frais additionnels), proposé en ligne « Frais de
  transport – {transporteur} » sur les factures des fournisseurs livrés par ce transporteur (FLOW-ACHATS §12).
  Supprimer ce frais, ou le faire changer d'application, le retire de la fiche du transporteur.

**Reste de la phase** (docs/17, §3.2) : les sorties (PDF « Expédié par », variables de courriel, lien dans l'espace
client) et l'option « transporteur obligatoire ».

Code :
- API : `App\Services\EntetesVente` (champ `transporteur_id`), `Transporteur::regle()`, `ComFourEntete::booted()`,
  `BonLivraisonController::expedition`, migration `2026_10_26_100093_transporteurs_documents` ;
- web : `hooks/useTransporteurs` (`lienSuivi`), composants `ChoixTransporteur` et `livraison/ExpeditionBon`.

---

## 3. Devis

### 3.1 Ce que c'est

Un **Devis** est une proposition commerciale envoyée au client avant qu'il s'engage. Il décrit les produits, quantités et prix. Le client peut accepter ou refuser.

Un devis accepté peut être **converti en commande client** directement — sans ressaisie.

### 3.2 Champs d'un devis

**En-tête (Devis)**

| Champ | Description |
|-------|-------------|
| `numero` | Ex: DEV-2026-0001 (auto-généré) |
| `client_id` | Le client concerné |
| `date_devis` | Date de création |
| `date_validite` | Date d'expiration (après cette date → `expire`) |
| `statut` | État actuel |
| `montant_total` | Somme des lignes (calculé auto) |
| `notes` | Notes pour le client |
| `envoye_le` | Date d'envoi au client (bouton Envoyer, courriel, lien de réponse partagé) |
| `reponse_le`, `reponse_en_ligne`, `reponse_nom`, `reponse_commentaire` | Réponse du client : date, donnée en ligne ou notée à la main, nom et commentaire (§3.7) |

**Lignes (DevisLigne)**

| Champ | Description |
|-------|-------------|
| `produit_id` | Le produit proposé |
| `quantite` | Quantité proposée |
| `prix_unitaire` | Prix unitaire : celui du client est proposé (FLOW-PRIX §4.1), modifiable |
| `montant_ligne` | `quantite × prix_unitaire` |

### 3.3 Statuts du devis

| Statut | Signification | Actions disponibles |
|--------|---------------|---------------------|
| `brouillon` | En rédaction | Modifier ✏️, Envoyer ✉️, Supprimer 🗑️ |
| `envoye` | Envoyé au client (bouton, courriel ou lien de réponse) | Accepter ✅, Refuser ❌ ; le client peut répondre en ligne (§3.7) |
| `accepte` | Client a accepté | Convertir en commande 📄 ou facturer directement 🧾 (une seule fois) |
| `refuse` | Client a refusé | Lecture seule |
| `expire` | Date de validité dépassée | Lecture seule |

### 3.4 Flow du devis

```
Étape 1 : Créer le devis (statut: brouillon)
          → Sélectionner le client
          → Ajouter des lignes de produits avec prix
          → Le total se calcule automatiquement

Étape 2 : Envoyer le devis (statut: envoye)
          → Endpoint: POST /devis/{id}/envoyer
          → Enregistre la date d'envoi

Étape 3a : Client accepte (statut: accepte)
           → Endpoint: POST /devis/{id}/accepter

Étape 3b : Client refuse (statut: refuse)
           → Endpoint: POST /devis/{id}/refuser

Étape 4 : Convertir en commande (si accepté)
           → Endpoint: POST /devis/{id}/convertir
           → Crée une ComClientEntete avec les mêmes lignes
           → Datée du jour de l'usager (`date`, envoyée par l'écran), sinon du jour de l'entreprise (TACHES_FUSEAU) ;
             de même pour la facture tirée d'un devis ou d'une commande
           → Le devis reste dans l'historique
```

### 3.5 Conversion en commande

Quand on convertit un devis accepté :
1. Une **Commande Client** est créée avec les mêmes lignes et prix.
2. Les taxes sont calculées comme pour une commande saisie directement : le groupe de taxes par défaut de l'entreprise s'il existe, sinon le taux par défaut.
3. La commande est en statut `brouillon` : elle peut encore être modifiée.
4. Le devis garde son statut `accepte` et pointe vers sa commande (`commande_id`), affichée dans la liste (« → CMD-… »).
5. **Un devis ne se convertit qu'une fois.** Une seconde conversion répond 422 avec le numéro de la commande existante ; un verrou empêche deux conversions simultanées.

**Facturer directement** (comme dans fripv2, bouton 🧾 de la liste, `POST /devis/{id}/facturer`) : une **facture brouillon** est créée sans commande, avec les mêmes lignes et prix, la devise et le taux du devis, ses notes et les coordonnées du client de passage. Les taxes et l'échéance sont calculées comme pour une facture saisie. Le devis pointe vers sa facture (`facture_id`, « → FAC-… » dans la liste). Un devis ne se convertit qu'une fois, en commande **ou** en facture. Il faut le droit de convertir un devis **et** celui de créer une facture.

### 3.6 Points d'attention

- **Expiration.** Un devis envoyé dont la date de validité est passée devient `expire` au chargement de la liste, et on ne peut plus l'accepter (422 avec la date d'expiration).
- Les prix dans le devis sont libres — ils ne sont pas forcément les mêmes que les prix catalogues.

### 3.7 Réponse en ligne du client

Plan de parité fripv2, phase 1.3 : comme dans fripv2, le courriel du devis porte une **carte de réponse** avec deux boutons, **Accepter le devis** et **Refuser le devis**. Le client répond sur une page du site de l'espace client, sans compte, et le devis passe à Accepté ou Refusé.

**Envoi :**
- envoyer le devis par courriel fait passer un brouillon à `envoye` (comme dans fripv2) et note la date d'envoi (`envoye_le`), comme le bouton Envoyer ;
- la carte s'ajoute sous le texte du courriel tant que le devis peut recevoir une réponse (brouillon ou envoyé, pas expiré). Elle est absente si le texte donne déjà le lien (variable `{lien_reponse}`) ;
- le bouton **Lien de réponse** de la liste (droit « Envoyer un devis ») donne le même lien, à transmettre autrement (messagerie, SMS…). Le partager fait aussi passer le brouillon à `envoye`.

**Le lien :**
- il est le même à chaque envoi : un courriel déjà envoyé reste valable ;
- il mène à `<site de l'espace client>/devis/<jeton>`. L'adresse du site est `ESPACE_CLIENT_URL`, sinon celle que le back-office transmet (comme pour les invitations de l'espace client) ;
- seul le lien donne accès au devis. Le jeton (64 caractères) est enregistré chiffré, avec son empreinte pour le retrouver, et n'est jamais renvoyé par l'API ;
- la page fonctionne même si l'espace client de l'entreprise n'est pas ouvert. Elle ne répond plus si l'entreprise est désactivée ou n'a plus le module Ventes.

**La page du client :**
- entreprise, numéro, dates, client (nom noté pour un client de passage), lignes, total hors taxes, notes, téléchargement du PDF ;
- **Accepter** : nom obligatoire, commentaire facultatif ; **Refuser** : nom et raison facultatifs ;
- un devis déjà décidé affiche sa réponse ; un devis expiré le dit, et une réponse tardive le fait passer à `expire`.

**Au back-office :**
- la liste marque « en ligne » sous le statut ; le détail montre la date d'envoi, la réponse (en ligne ou notée à la main), le nom et le commentaire du client ;
- une **notification** (« Devis accepté en ligne » ou « Devis refusé en ligne ») mène à la liste des devis ;
- Accepter et Refuser notent aussi la date de réponse quand un employé les utilise.

**Réglage :** Paramètres → Courriels, « Réponse en ligne aux devis » (activé par défaut). Désactivé, les courriels n'ont plus de carte et le bouton Lien de réponse est refusé ; les liens déjà envoyés continuent de fonctionner.

| Route | Rôle |
|---|---|
| `POST /devis/{id}/lien-reponse` | Lien de réponse (`origine` : adresse du site de l'espace client connue du back-office) ; droit `devis.envoyer` |
| `GET /devis-en-ligne/{jeton}` | Page publique : entreprise, devis, réponse, `peut_repondre` (60 par minute) |
| `GET /devis-en-ligne/{jeton}/pdf` | PDF du devis (20 par minute) |
| `POST /devis-en-ligne/{jeton}/accepter` | `nom` (obligatoire), `commentaire` (10 réponses par minute) |
| `POST /devis-en-ligne/{jeton}/refuser` | `nom`, `commentaire` (facultatifs) |

Code :
- API : service `App\Services\ReponsesDevis`, contrôleur `App\Http\Controllers\Portail\DevisEnLigneController`, carte dans `RenduCourriel` et la vue `emails/courriel`, migration `2026_10_16_100040_reponse_en_ligne_devis` ;
- espace client : page `espace/pages/ReponseDevis` (route `/devis/:jeton`, hors de l'espace connecté) ;
- back-office : bouton Lien de réponse et détail de la réponse dans `DevisPage`.

### 3.8 Relances des devis

Plan de parité fripv2, phase 1.3 : relancer le client d'un devis envoyé resté sans réponse.

**À la main :** le bouton **Relancer le client** (cloche) d'un devis envoyé ouvre la fenêtre d'envoi avec le modèle « Relance de devis ». Le devis est joint et les boutons de réponse en ligne s'ajoutent (§3.7). Le bouton suit le droit « Envoyer un devis ».

**Automatiquement :** la tâche planifiée `relances_devis` (Paramètres → Tâches planifiées), **inactive par défaut**. Chaque jour, elle relance les devis :
- envoyés, sans réponse depuis au moins **N jours** (7 par défaut), comptés depuis l'envoi ou la dernière relance ;
- encore valables (date de validité pas dépassée) ;
- relancés moins de 3 fois ;
- dont le client a une adresse de courriel (celle notée pour un client de passage) ;
- sans relance déjà en file d'envoi.

**Suivi :** chaque relance envoyée est comptée sur le devis (`relances`, `derniere_relance_le`) ; le détail du devis affiche « Relancé N fois, dernière relance le … ». Une réponse du client (en ligne ou notée) arrête les relances.

**Modèle « Relance de devis » :** variables du devis, plus `jours_restants` (jours de validité restants) et `niveau` (numéro de la relance) ; personnalisable dans Paramètres → Courriels.

Code : service `App\Services\RelancesDevis`, tâche `App\Taches\RelancerDevisSansReponse`, migration `2026_10_16_100041_relances_devis`.

### 3.9 Copier un devis

Comme dans fripv2 : le bouton **Copier** de la liste (`POST /devis/{id}/copier`, droit « Créer un devis ») crée un **nouveau devis brouillon** repris de celui-ci, quel que soit son statut :
- même client (et coordonnées du client de passage), mêmes lignes et prix, mêmes notes ;
- daté du jour, avec la même durée de validité que l'original ;
- dans la même devise, au **taux du jour** (référentiel des devises) ;
- nouveau numéro ; ni réponse, ni relance, ni conversion reprises.

La copie s'ouvre aussitôt en modification.

Code : `DevisController::facturer` (création par `App\Services\FacturesVente`) et `DevisController::copier`, migration `2026_10_16_100042_facture_issue_du_devis`.

---

## 4. Commandes Client

### 4.1 Ce que c'est

Une **Commande Client** est la confirmation d'achat du client. Elle engage les deux parties. Elle peut venir d'un devis converti ou être créée directement.

### 4.2 Champs d'une commande client

**En-tête (ComClientEntete)**

| Champ | Description |
|-------|-------------|
| `numero` | Ex: CMD-2026-0001 (auto-généré) |
| `client_id` | Le client |
| `date_commande` | Date de la commande |
| `date_livraison_souhaitee` | Date de livraison demandée par le client |
| `remise_globale` | Remise globale en % (s'applique après les remises ligne) |
| `montant_ht` | Total HT calculé |
| `montant_tva` | Total TVA calculé |
| `montant_ttc` | Total TTC calculé |
| `statut` | État actuel |
| `notes` | Notes internes |
| `motif_refus` | Raison si refusée |

**Lignes (ComClientLigne)**

| Champ | Description |
|-------|-------------|
| `produit_id` | Le produit |
| `quantite` | Quantité commandée |
| `prix_unitaire_ht` | Prix HT unitaire : celui du client est proposé (FLOW-PRIX §4.1), modifiable |
| `taux_tva` | Taux TVA en % (ex: 20) |
| `remise_ligne` | Remise sur cette ligne en % |
| `montant_ht` | Calculé |
| `montant_tva` | Calculé |
| `montant_ttc` | Calculé |

### 4.3 Calcul des montants

```
Pour chaque ligne :
  montant_ht    = quantite × prix_unitaire_ht × (1 - remise_ligne/100)
  montant_tva   = montant_ht × (taux_tva/100)
  montant_ttc   = montant_ht + montant_tva

Total commande :
  total_ht      = somme(montant_ht) × (1 - remise_globale/100)
  total_tva     = somme(montant_tva) × (1 - remise_globale/100)
  total_ttc     = total_ht + total_tva
```

Méthode backend : `ComClientEntete::calculerMontants()`

### 4.4 Statuts de la commande client

| Statut | Signification | Actions disponibles |
|--------|---------------|---------------------|
| `brouillon` | En cours de rédaction | Modifier ✏️, Soumettre 📤, Supprimer 🗑️ |
| `soumise` | En attente d'approbation | Accepter ✅, Refuser ❌ |
| `acceptee` | Approuvée | Créer Facture 💰 |
| `refusee` | Refusée | Lecture seule |

### 4.5 Modifier une commande brouillon

Le bouton ✏️ (crayon) apparaît sur les commandes en statut `brouillon`. Il ouvre le même modal que la création, pré-rempli avec les données existantes :
- Client, dates, remise globale, notes
- Toutes les lignes (produits, quantités, prix)

L'enregistrement appelle `PUT /commandes-client/{id}` qui recalcule les montants automatiquement.

### 4.5 Flow de la commande client

```
Étape 1 : Créer la commande (statut: brouillon)
          → Sélectionner le client
          → Ajouter les lignes
          → Appliquer les remises si nécessaire

Étape 2 : Soumettre (statut: soumise)
          → Endpoint: POST /commandes-client/{id}/soumettre
          → La commande part en validation

Étape 3a : Accepter (statut: acceptee)
           → Endpoint: POST /commandes-client/{id}/accepter

Étape 3b : Refuser (statut: refusee)
           → Endpoint: POST /commandes-client/{id}/refuser

Étape 4 : Créer la Facture
           → Sur la page Factures → "Créer depuis commande"
           → Endpoint: POST /factures/commande/{commandeId}
```

---

## 5. Factures

### 5.1 Ce que c'est

La **Facture** est le document officiel de demande de paiement envoyé au client. Elle est créée à partir d'une commande acceptée.

### 5.2 Champs d'une facture

| Champ | Description |
|-------|-------------|
| `numero` | Ex: FAC-2026-0001 (auto-généré) |
| `commande_id` | Commande d'origine |
| `client_id` | Le client |
| `facture_mere_id` | ID de la facture d'origine (factures « du reste » créées avant le 26/10/2026 ; plus utilisé) |
| `date_facture` | Date d'émission |
| `date_echeance` | Date limite de paiement |
| `statut` | État actuel |
| `montant_ht` | Total HT |
| `montant_tva` | Total TVA |
| `montant_ttc` | Total TTC |
| `montant_paye` | Somme des paiements reçus (calculé) |
| `montant_credite` | Somme des crédits de notes de crédit imputés sur la facture (calculé, §5.8) |
| `montant_escompte` | Somme des escomptes accordés à ses paiements (calculé, §5.10) |
| `condition_paiement_id` | Condition de paiement (§5.10) |
| `reste_a_payer` | `montant_ttc - montant_paye - montant_credite - montant_escompte` |

### 5.3 Statuts de la facture

| Statut | Signification | Actions disponibles |
|--------|---------------|---------------------|
| `brouillon` | Créée mais pas encore envoyée | Émettre 📤, Supprimer 🗑️ |
| `emise` | Envoyée au client | Enregistrer paiement 💵, Créer BL 📦, Créditer |
| `partiellement_payee` | Paiement partiel reçu | Enregistrer paiement 💵, Créditer |
| `payee` | Intégralement payée, ou soldée par des paiements et des notes de crédit | Créditer |
| `creditee` | Soldée par des notes de crédit, sans aucun paiement | Lecture seule |

### 5.4 Création manuelle d'une facture

En plus de la création depuis une commande, on peut créer une facture directement depuis le bouton **"Nouvelle facture"**.

**Quand l'utiliser :**
- Facturer un client sans passer par le circuit Devis → Commande
- Facture de service ou prestation non liée à une commande
- Corrections, avoirs manuels

**Endpoint :** `POST /factures`

**Champs requis :**
- Client
- Date de facture
- Lignes (produit, quantité, prix HT)

**Champs optionnels :**
- Date d'échéance
- Notes

La facture est créée en statut `brouillon`. Elle suit ensuite le flow normal : émettre → paiement → BL.

**Numéro attribué à l'émission** (réglage de l'entreprise, Configuration → Numérotation → Factures,
`tenant_parametres.numero_facture_a_l_emission`, inactif par défaut) :
- inactif : le brouillon prend le numéro suivant de la séquence dès sa création (comportement d'origine) ;
- actif : le brouillon porte un numéro provisoire `BR-{id}` et ne consomme la séquence qu'à son émission
  (`FacturesVente::emettre`), si bien qu'un brouillon supprimé ne laisse plus de trou. Cela vaut pour toutes les
  factures créées par l'application (saisie, devis, commande, reliquats, abonnements, loyers, consignation, comptoir) ;
- un brouillon numéroté `BR-…` reçoit son vrai numéro à l'émission même si le réglage a été désactivé entre-temps.

**Modifier un brouillon** (`PUT /factures/{id}`, droit Factures → Modifier) : le crayon de la ligne rouvre le formulaire
rempli avec la facture. En-tête et lignes sont renvoyés en entier, comme à la création ; taxes, totaux et échéance (de la
condition de paiement, sauf échéance saisie) sont recalculés, et le numéro ne change pas.
- La facture est verrouillée pendant sa modification (`verrou:facture`) : un autre compte voit qui la modifie et ne peut
  ni l'enregistrer ni la supprimer.
- Refusé (422) pour une facture émise : elle se corrige par une note de crédit.
- Refusé pour une facture créée par un abonnement, un contrat de location, des reliquats clients ou une consignation :
  ses lignes y sont rattachées ; elle se corrige à sa source, ou se supprime et se refait.
- Le client d'une facture tirée d'une commande ne se change pas.
- Un nouveau représentant fige son propre taux de commission.

### 5.4 Flow de la facture

```
Étape 1 : Créer la facture depuis une commande acceptée
          → Page Factures → "Créer depuis commande"
          → Endpoint: POST /factures/commande/{commandeId}
          → Les lignes et montants sont copiés depuis la commande
          → Statut: brouillon

Étape 2 : Émettre la facture (statut: emise)
          → Endpoint: POST /factures/{id}/emettre
          → Enregistre la date d'émission

Étape 3 : Créer le Bon de Livraison
          → Endpoint: POST /factures/{id}/creer-bl
          → Crée un BonLivraison lié à cette facture (émise, partiellement payée ou payée)
          → Un seul bon en cours par facture ; un bon annulé ne compte pas, on peut en recréer un
          → Après une livraison partielle, un nouveau bon livre le reste : il reprend les quantités facturées
            moins celles déjà livrées (« Livrer le reste ») ; une facture entièrement livrée est refusée

Étape 4 : Enregistrer les paiements
          → Endpoint: POST /factures/{id}/paiement
          → Chaque paiement met à jour montant_paye et reste_a_payer
          → Si reste_a_payer = 0 → statut = "payee"
          → Si reste_a_payer > 0 → statut = "partiellement_payee"
```

### 5.5 Enregistrement d'un paiement

| Champ | Description |
|-------|-------------|
| `montant` | Montant du paiement |
| `date_paiement` | Date du paiement |
| `mode_paiement` | `virement`, `cheque`, `carte`, `especes`, `autre` |
| `reference` | Référence du virement/chèque |
| `escompte` | Escompte pour paiement rapide accordé avec ce paiement (§5.10) |

Après chaque paiement :
- `updateMontantPaye()` recalcule `montant_paye` et `reste_a_payer`
- Le statut de la facture est mis à jour automatiquement
- L'encours du client est mis à jour (`updateEncours()`)

### 5.6 Échéancier (FactureEcheance)

Si le client paye en plusieurs fois, on peut définir un échéancier :

| Champ | Description |
|-------|-------------|
| `date_echeance` | Date de cette échéance |
| `montant` | Montant à payer à cette date |
| `ordre` | Numéro d'ordre (1, 2, 3...) |

Exemple : Facture de 3000€ → 1000€ le 1er mai + 1000€ le 1er juin + 1000€ le 1er juillet.

Les paiements se répartissent sur les échéances dans leur ordre : chacune passe `payee`, `partiellement_payee` ou reste `en_attente`, avec son `montant_paye`.

### 5.7 Reste d'une livraison partielle

La facture couvre toute la quantité vendue. Quand un BL n'est livré qu'en partie, il passe en `livre_partiel` et **rien
n'est refacturé** :
- pour livrer le reste, « Livrer le reste » (écran Factures, `POST /factures/{id}/creer-bl`) crée un nouveau bon avec les
  seules quantités non livrées (quantité facturée moins quantités livrées par les bons de la facture, rapprochées par
  produit et unité) ; ce bon suit le circuit habituel ;
- un reste qui ne sera pas livré se règle par une **note de crédit** sur la facture.

Jusqu'au 26/10/2026, une livraison partielle créait une nouvelle facture brouillon « du reste » (`facture_mere_id`) :
émise, elle facturait une seconde fois les quantités non livrées. Les brouillons de ce type encore présents se
suppriment.

Une livraison **complète** passe le BL en `livre_complet` et ne crée aucune facture. (Avant le 2026-10-16, les quantités livrées étaient lues avant leur enregistrement : toute livraison était vue comme partielle et une facture de reliquat reprenait la totalité du bon.)

### 5.8 Notes de crédit (Ventes → Notes de crédit)

Plan de parité fripv2, phase 1.5 : le « Créditer » d'une facture. Une note de crédit annule tout ou partie d'une facture émise, payée ou non : retour de marchandise, erreur de prix, geste commercial.

**Créer une note :**
- bouton **Créditer** d'une facture émise, partiellement payée ou payée (liste des factures) ;
- ou **Nouvelle note de crédit** (écran Notes de crédit), puis le choix de la facture, par numéro ou par client.

**L'éditeur** reprend les lignes de la facture :
- pour chaque ligne : ce qui a été facturé, ce qui a déjà été crédité et ce qui reste à créditer ;
- par défaut, tout ce qui reste ; « Tout effacer » remet les quantités à zéro ;
- la **quantité** et le **prix unitaire HT** se modifient, au plus ceux de la facture (un prix réduit sert au geste commercial) ;
- la remise de la ligne est celle de la facture ;
- les **taxes** sont celles calculées sur la facture, au prorata du montant crédité : même nom et même taux, même si le groupe de taxes a changé depuis ;
- l'écran affiche une estimation ; l'API calcule les montants définitifs ;
- « Enregistrer le brouillon », ou « Enregistrer et émettre ».

**Limites :**
- sur chaque ligne, le montant HT crédité par toutes les notes émises ne dépasse jamais celui facturé (422 sinon) ;
- la note qui crédite tout ce qui restait reprend exactement les totaux restants de la facture : pas d'écart d'arrondi ;
- un brouillon est de nouveau contrôlé à son émission : une autre note a pu être émise entre-temps ;
- la date de la note ne précède pas celle de la facture.

**Statuts de la note :**

| Statut | Signification | Actions |
|---|---|---|
| `brouillon` | Sans numéro, sans effet sur la facture | Modifier, Émettre, Supprimer, PDF |
| `emis` | Émise ; il reste du crédit disponible | Imputer sur une facture, Rembourser, PDF, Courriel |
| `solde` | Émise ; tout le crédit a été imputé ou remboursé | PDF, Courriel |

**À l'émission :**
1. la note reçoit son numéro (numérotation `avoir`, `AV-00001` par défaut, voir FLOW-PARAMETRES §7) ;
2. elle s'**impute** sur ce qui reste dû de sa facture, au plus son montant ;
3. la facture recalcule `montant_credite`, `reste_a_payer`, ses échéances et l'encours du client ;
4. soldée sans aucun paiement, la facture passe **`creditee`** ; soldée avec des paiements, **`payee`** ;
5. un paiement ne peut plus dépasser le reste dû, crédits déduits.

**Crédit disponible** (facture déjà payée, ou crédit plus grand que le reste dû) :
- **Imputer sur une facture** : une facture due du même client. Le montant ne dépasse ni le crédit disponible ni le reste de la facture, et la date ne précède ni la note ni la facture.
- **Rembourser** : montant, date, mode (virement, chèque, espèces, carte, autre) et référence.
- Entièrement utilisée, la note passe `solde`.

**Ailleurs dans l'application :**
- **Détail d'une facture** : ligne « Crédité » et liste de ses notes de crédit.
- **Comptes clients** (§7 bis) :
  - la note est au crédit du relevé, un remboursement au débit ;
  - le crédit disponible est dû au client.
- **PDF** « Note de crédit » : facture créditée, motif, totaux, imputé, remboursé et crédit disponible.
- **Courriel** : type `avoir` avec les variables `{numero_facture}`, `{motif}` et `{credit_disponible}`.
- **Recherche globale** : notes émises, par numéro, client ou facture.
- **Journal des modifications** : création, modification et suppression des notes.
- **Espace client** : une facture `creditee` s'affiche comme soldée.

**Hors périmètre :** une note de crédit est **financière seulement** : elle ne remet rien en stock. Les retours avec remise en stock relèvent des retours clients (plan, phase 1.7).

**Permissions** (module `avoirs`, onglet Ventes) :

| Permission | Code | Ce qu'elle ouvre |
|---|---|---|
| Consulter | `avoirs.voir` | Écran Notes de crédit, détail, PDF |
| Créer | `avoirs.creer` | « Créditer », nouveau brouillon |
| Modifier | `avoirs.modifier` | Modifier un brouillon |
| Supprimer | `avoirs.supprimer` | Supprimer un brouillon |
| Émettre une note de crédit | `avoirs.emettre` | Émission (numéro, imputation sur la facture) |
| Rembourser ou imputer un crédit | `avoirs.regler` | Imputer le crédit disponible, ou le rembourser |

À la mise en place, chaque groupe reçoit sur les notes de crédit ses droits sur les factures :
- « Émettre une note de crédit » suit « Émettre une facture » ;
- « Rembourser ou imputer un crédit » suit « Enregistrer un paiement ».

Pour les rôles système, cela donne :
- administrateur et responsable : tout ;
- utilisateur et lecture seule : consultation.

| Route | Rôle |
|---|---|
| `GET /avoirs?statut=&recherche=&client_id=&facture_id=` | Liste paginée |
| `GET /avoirs/{id}` | Détail : lignes, détail des taxes, imputations, remboursements |
| `GET /factures/{id}/avoir` | Ce qui reste à créditer sur une facture, ligne par ligne |
| `POST /avoirs` | Nouveau brouillon (`facture_id`, `date_avoir`, `motif`, `notes`, `lignes[]` : `facture_ligne_id`, `quantite`, `prix_unitaire_ht`) |
| `PUT /avoirs/{id}` | Modifier un brouillon |
| `DELETE /avoirs/{id}` | Supprimer un brouillon (422 une fois émise) |
| `POST /avoirs/{id}/emettre` | Émettre |
| `GET /avoirs/{id}/factures-imputables` | Factures dues du client |
| `POST /avoirs/{id}/imputer` | Imputer (`facture_id`, `montant`, `date`) |
| `POST /avoirs/{id}/rembourser` | Rembourser (`montant`, `date_remboursement`, `mode`, `reference`) |
| `GET /avoirs/{id}/pdf` | PDF |

**Données :**

| Table | Contenu |
|---|---|
| `avoirs` | En-tête : numéro, facture, client, date, statut, motif, totaux, `montant_impute`, `montant_rembourse` |
| `avoir_lignes` | Lignes : ligne de facture créditée, quantité, prix, remise, taux, montants ; détail des taxes dans `document_taxes` (`document = avoir`) |
| `avoir_imputations` | Part d'une note imputée sur une facture, datée |
| `avoir_remboursements` | Remboursements au client |
| `factures.montant_credite` | Total des crédits imputés sur la facture |

Code :
- API : service `App\Services\NotesCredit`, contrôleur `App\Http\Controllers\Api\AvoirController`, migration `2026_10_16_100030_creer_notes_de_credit` ;
- web : écran `NotesCreditPage`, éditeur `components/avoirs/EditeurNoteCredit`, fenêtre `components/avoirs/ReglerCredit`, service `src/services/avoirs.ts`.

### 5.9 Acomptes et demandes d'acompte (Ventes → Acomptes, Demandes d'acompte)

Plan de parité fripv2, phase 1.5 : les acomptes et les demandes d'acompte de fripv2.

#### Acomptes

Un **acompte** est un paiement reçu d'un client avant sa facture : date, mode (virement, chèque, espèces, carte, autre), référence, montant et notes. Il appartient au client.

**Saisir un acompte :**
- **Ventes → Acomptes → Nouvel acompte** : un acompte libre, ou réservé à une commande du client ;
- bouton **Nouvel acompte** d'une commande brouillon, en attente ou acceptée (liste des commandes) : l'acompte est réservé à cette commande ;
- une **demande d'acompte** marquée payée (plus bas).

**Utiliser un acompte :**
- **Réservé à une commande** : à l'émission de la facture de cette commande, l'acompte s'applique de lui-même à son paiement, au plus ce qui reste dû. Ce qui en reste redevient un crédit libre du client.
- **Payer une facture** : la fenêtre « Enregistrer un paiement » d'une facture liste les acomptes disponibles du client, sauf ceux réservés à une autre commande. « Appliquer » paie la facture avec l'acompte, au plus ce qui en reste et ce qui reste dû.
- **Appliquer sur une facture**, depuis l'écran Acomptes : une facture due du même client, montant et date au choix.
- **Rembourser** ce qui reste : montant, date, mode et référence. Par défaut, le mode est celui de l'acompte : l'argent ressort par où il est entré.

**Effet sur la facture :** une application est un **paiement de la facture** rattaché à l'acompte (`facture_paiements.acompte_id`). Le reste à payer, le statut, les échéances et l'encours du client suivent donc comme pour tout paiement.

**Contrôles :**
- le montant ne dépasse ni ce qui reste de l'acompte, ni ce qui reste dû sur la facture ;
- la date ne précède ni l'acompte ni la facture ;
- la facture appartient au même client ;
- un acompte déjà appliqué ou remboursé ne change plus de date, de mode ni de montant, et ne se supprime plus.

**État d'un acompte :**

| État | Signification |
|---|---|
| `disponible` | Rien n'en a encore servi |
| `entame` | Appliqué ou remboursé en partie |
| `utilise` | Plus rien de disponible |

**L'écran Acomptes** liste tous les acomptes, tous clients confondus, du plus récent au plus ancien :
- **Recherche** par référence, client ou montant.
- **Filtre** « avec solde disponible » ou « utilisés ».
- **Totaux** de la sélection : montant et disponible.
- **Détail** d'un acompte : factures payées avec, et remboursements.

#### Demandes d'acompte

Une **demande d'acompte** demande un dépôt au client avant des travaux ou une commande. Ce n'est pas une facture : elle n'a aucun effet sur le compte du client tant qu'elle n'est pas payée.

**Contenu :**
- numéro, attribué à la création (numérotation `demande_acompte`, `DA-00001` par défaut) ;
- client, date ;
- description sur plusieurs lignes ;
- montant, sans taxe ;
- au besoin, un devis et une commande du client.

**Statuts :**

| Statut | Signification | Actions |
|---|---|---|
| `brouillon` | Modifiable | Modifier, Marquer envoyée, Marquer payée, Supprimer |
| `envoyee` | Envoyée au client ; ne se modifie plus | Marquer payée, Supprimer |
| `payee` | Dépôt reçu : l'acompte est enregistré | Lecture seule |

- **Envoyée** : marquée à la main, ou d'elle-même quand la demande part par courriel (type `demande_acompte`, PDF joint).
- **Marquer payée** : date, mode et référence du dépôt. L'acompte est créé au dossier du client, réservé à la commande de la demande si elle est toujours en cours.
- **Après le paiement**, l'usage de cet acompte donne l'état affiché : **partiellement appliquée**, puis **appliquée**.
- **Supprimer un acompte** inutilisé venant d'une demande remet la demande dans son état d'avant le paiement.

**PDF** « Demande d'acompte » : la description, le montant demandé et la mention « Aucune taxe : ce document n'est pas une facture ».

#### Ailleurs dans l'application

- **Comptes clients** (§7 bis) :
  - un acompte reçu est au crédit du relevé, son remboursement au débit ;
  - son application n'est pas un nouveau mouvement, car l'acompte est déjà au crédit ;
  - ce qui reste d'un acompte compte dans les crédits dus au client.
- **Recherche globale** : les demandes d'acompte, par numéro, description ou client.
- **Journal des modifications** : création, modification et suppression des acomptes et des demandes.
- **Hors périmètre** : aucune écriture comptable n'est passée, comme pour les autres documents de vente pour l'instant.

**Permissions :**

| Écran | Module | Actions nommées |
|---|---|---|
| Acomptes | `acomptes` | « Appliquer un acompte sur une facture » (`acomptes.appliquer`), « Rembourser un acompte » (`acomptes.rembourser`) |
| Demandes d'acompte | `demandes_acompte` | « Marquer une demande d'acompte envoyée » (`demandes_acompte.envoyer`), « Marquer une demande d'acompte payée » (`demandes_acompte.encaisser`) |

À la mise en place, chaque groupe reçoit sur ces deux écrans ses droits sur les factures :
- appliquer, rembourser et marquer payée suivent « Enregistrer un paiement » ;
- marquer envoyée suit « Émettre une facture ».

Pour les rôles système, cela donne :
- administrateur et responsable : tout ;
- utilisateur et lecture seule : consultation.

| Route | Rôle |
|---|---|
| `GET /acomptes?client_id=&commande_id=&disponibilite=avec_solde\|utilise&recherche=` | Liste paginée et totaux de la sélection |
| `GET /acomptes/{id}` | Détail : factures payées avec l'acompte, remboursements |
| `POST /acomptes` | Recevoir un acompte (`client_id`, `date_acompte`, `mode`, `reference`, `montant`, `commande_id`, `notes`) |
| `PUT /acomptes/{id}` | Modifier (référence, réservation et notes toujours ; date, mode et montant tant qu'il n'a pas servi) |
| `DELETE /acomptes/{id}` | Supprimer un acompte qui n'a pas servi |
| `POST /acomptes/{id}/appliquer` | Payer une facture (`facture_id`, `montant`, `date`) |
| `POST /acomptes/{id}/rembourser` | Rembourser (`montant`, `date_remboursement`, `mode`, `reference`) |
| `GET /demandes-acompte?statut=&client_id=&recherche=` | Liste paginée |
| `GET /demandes-acompte/{id}` (`/pdf`) | Détail, PDF |
| `POST /demandes-acompte`, `PUT /demandes-acompte/{id}` | Saisie (brouillon) |
| `DELETE /demandes-acompte/{id}` | Supprimer une demande non payée |
| `POST /demandes-acompte/{id}/envoyer` | Marquer envoyée |
| `POST /demandes-acompte/{id}/encaisser` | Marquer payée (`date`, `mode`, `reference`) : crée l'acompte |

**Données :**

| Table | Contenu |
|---|---|
| `acomptes` | Client, date, mode, référence, montant, commande réservée, demande d'origine, `montant_applique`, `montant_rembourse` |
| `acompte_remboursements` | Remboursements d'un acompte |
| `facture_paiements.acompte_id` | Paiement d'une facture fait avec un acompte |
| `demandes_acompte` | Numéro, client, date, description, montant, statut, devis, commande, acompte |

Code :
- API : services `App\Services\Acomptes` et `App\Services\DemandesAcompte`, contrôleurs `AcompteController` et `DemandeAcompteController`, migration `2026_10_16_100031_creer_acomptes` ;
- web : écrans `AcomptesPage` et `DemandesAcomptePage`, composants `components/acomptes/FormulaireAcompte` et `components/acomptes/UtiliserAcompte`, service `src/services/acomptes.ts`.

### 5.10 Conditions de paiement et escompte pour paiement rapide

Plan de parité fripv2, phase 1.5 : les conditions de paiement de fripv2 (« Net 30 », « 2/10 Net 30 », « 30 jours fin de mois ») appliquées aux clients et aux factures. Les conditions se définissent dans **Référentiels → Conditions de paiement** :
- délai en jours ;
- fin de mois ;
- taux et jours d'escompte ;
- une condition par défaut.

**Client :**
- la fiche client (liste des clients → Modifier) choisit sa **condition de paiement** ;
- sans choix, c'est la condition par défaut de l'entreprise qui s'applique.

**Facture :**
- **Sa condition** est celle choisie à la création. Sinon, c'est celle du client, puis la condition par défaut de l'entreprise.
- **Sans échéance saisie**, l'échéance est la date de la facture plus le délai.
- Pour une condition **fin de mois**, l'échéance tombe le dernier jour du mois atteint. Par exemple, 30 jours fin de mois depuis le 16/10 donne le 30/11.
- **Facture créée depuis une commande**, quand le client n'a pas d'échéancier propre (§5.6) : son échéance unique est celle de la condition, sinon 30 jours comme avant.
- **Sans condition** (ni choisie, ni du client, ni par défaut), rien ne change.

**Escompte pour paiement rapide**, par exemple 2 % si le client paie dans les 10 jours :
- **Quand il est proposé** : comme dans fripv2, au **premier paiement** de la facture, fait au plus tard à sa date plus les jours d'escompte.
- **Ce qu'il donne** : le taux appliqué à ce qui reste dû, notes de crédit déduites, arrondi au centime.
- **À une condition** : le paiement doit régler tout le reste (montant = reste − escompte).
- **Dans la fenêtre de paiement**, une case « Escompte » apparaît avec le montant et la date limite. Cochée, elle remplit le montant à payer ; au-delà de la date limite, elle est grisée.
- **À l'enregistrement**, le paiement porte son `escompte` et la facture cumule `montant_escompte`. Le reste à payer, le statut, les échéances et l'encours en tiennent compte.
- **Refus (422)** :
  - paiement en retard : « Escompte impossible : le paiement doit être fait au plus tard le … » ;
  - paiement partiel : « Avec l'escompte, le paiement règle tout le reste : … » ;
  - facture qui a déjà reçu un paiement : « Aucun escompte possible sur cette facture. »

**Ailleurs :**
- **Détail de la facture** : condition de paiement et ligne « Escompte ».
- **PDF de la facture** :
  - « Conditions de paiement » parmi les informations ;
  - lignes « Escompte » et « Crédité » au règlement quand elles existent.
- **Comptes clients** :
  - au relevé, l'escompte est une ligne au crédit, après le paiement qu'il accompagne ;
  - le reste dû d'une facture à une date déduit les escomptes accordés jusqu'à ce jour.

| Route | Rôle |
|---|---|
| `GET /factures/{id}/escompte?date=` | Escompte possible pour un paiement à cette date : `applicable`, `condition`, `pourcentage`, `jours`, `date_limite`, `escompte`, `a_payer` |
| `POST /factures/{id}/paiement` (`escompte: true`) | Paiement avec escompte |
| `POST /factures` (`condition_paiement_id`) | Condition d'une nouvelle facture |
| `PUT /clients/{id}` (`condition_paiement_id`) | Condition du client |

**Données :**
- `clients.condition_paiement_id` ;
- `factures.condition_paiement_id`, `factures.montant_escompte` ;
- `facture_paiements.escompte`.

Le code est dans le service `App\Services\ConditionsPaiement` et la migration `2026_10_16_100032_conditions_paiement_et_escompte`.

### 5.11 Ventes en devises étrangères

Plan de parité fripv2, phase 1.5 : un client étranger achète et paie dans sa devise, comme dans fripv2 où la devise est choisie sur la fiche du client et reprise par ses transactions.

**Devise d'un client :**
- choisie sur sa fiche (liste des clients → Modifier → « Devise du client ») ;
- parmi les devises du référentiel (Référentiels → Devises) ;
- sans choix, c'est la devise de l'entreprise.

**Devise d'un document de vente :**
- **Devis, commande, facture** : à la création, la devise du client est proposée avec son **taux de change** (valeur d'une unité dans la devise de l'entreprise). Le taux vient du référentiel, sinon du cours du jour (même service que les commandes fournisseur), et se corrige.
- **Prix** : ils se saisissent dans la devise du document ; le prix d'un produit proposé est converti au taux.
- **Devise de l'entreprise pour un client étranger** : on peut la choisir ; elle l'emporte alors sur celle du client.
- **Sans taux connu** (ni saisi, ni au référentiel), la création est refusée : 422 « Indiquez le taux de change GBP → XAF ». Rien n'est pris au taux 1 en silence.
- **Une fois créé**, le document garde sa devise et son taux.
- **Conversions** : le devis passe sa devise et son taux à la commande, la commande à la facture, la facture à ses notes de crédit.
- **Acomptes et demandes d'acompte** : dans la devise du client (ou de la commande réservée).
- **Même devise exigée** : un acompte ne paie qu'une facture de sa devise, une note de crédit ne s'impute que sur une facture de la sienne (422 sinon). La fenêtre de paiement ne propose que les acomptes de la devise de la facture.
- **Paiements, escomptes et remboursements** : dans la devise de leur document.

**Affichage :**
- **Montants des documents** dans leur devise (« 119,25 $US ») : listes, détails, détail par taxe, fenêtres de paiement, notes de crédit, acomptes, demandes, relances, espace client.
- **PDF** : montants dans la devise du document, avec la ligne « Devise : USD (1 USD = 600 XAF) ».
- **Courriels** : variables de montant dans la devise du document.
- **Recherche globale** : montants dans la devise du document.

**Cumuls dans la devise de l'entreprise**, chaque montant au taux de son document :
- **encours du client** et contrôle de l'encours maximal à l'acceptation d'une commande ;
- **comptes clients** :
  - le relevé indique le montant d'origine d'une facture en devise, par exemple « Facture FAC-00001 (120,00 USD) » ;
  - une facture due donne son reste dans les deux devises (`reste`, `reste_devise`) ;
- **fiche client** : chiffre d'affaires et encours ;
- **tableaux de bord** : commandes, factures impayées, chiffre d'affaires, encaissements ;
- **dépenses d'achat** : converties au taux des commandes fournisseur (elles ne l'étaient pas) ;
- **totaux** des relances et de la liste des acomptes.

**Sans devise étrangère, rien ne change** : les documents existants sont dans la devise de l'entreprise, au taux 1.

| Route | Champs |
|---|---|
| `POST /devis`, `POST /commandes-client`, `POST /factures`, `POST /acomptes`, `POST /demandes-acompte` | `devise` (code ISO ; celui de l'entreprise pour forcer sa devise), `taux_change` |
| `POST`, `PUT /clients/{id}` | `devise` |

**Données :**
- `clients.devise` ;
- `devise` et `taux_change` sur `devis`, `com_client_entete`, `factures`, `avoirs`, `acomptes` et `demandes_acompte`.

Code :
- API : service `App\Services\DevisesVente`, migration `2026_10_16_100034_ventes_multi_devises` ;
- web : composant `ChampDevise`, fonctions `useMontantDocument` et `tauxPropose` (`src/lib/documents.ts`).

### 5.12 Retours clients (Ventes → Retours clients)

Plan de parité fripv2, phase 1.7 : la marchandise qu'un client rapporte, avec la raison du retour. Dans fripv2, c'est une ligne à quantité négative sur une facture ; ici, c'est un document à part, qui gère le **stock**, la note de crédit (§5.8) gérant l'**argent**.

**Créer un retour :**
- bouton **Retour de marchandise** d'une facture émise, payée ou non, même créditée (liste des factures) ;
- ou, dans l'écran Retours clients :
  - **Retour d'une facture**, puis le choix de la facture ;
  - **Retour sans facture** : vente faite hors de l'application, prêt, échantillon… On choisit le client, puis les produits.

**L'éditeur**, pour chaque produit rapporté :
- la quantité **remise en stock**, et le **secteur** où la ranger (celui du produit est proposé) ;
- la quantité **mise au rebut** ;
- la **raison** du retour.

**Raison du retour :**
- choisie parmi les raisons du référentiel de type « Retour client » (Référentiels → Raisons) ;
- **obligatoire dès que l'entreprise en a défini au moins une** (422 « Choisissez la raison du retour. ») ;
- sans raison définie, elle est facultative.

**Limites :**
- sur une facture, un produit revient au plus dans la quantité facturée, moins les retours **traités** de cette ligne ;
- une ligne peut se partager entre stock et rebut : leur total compte ;
- 422 « Au plus 1 (quantité facturée, retours déjà traités déduits). » au-delà ;
- un brouillon est de nouveau contrôlé à son traitement : un autre retour de la facture a pu être traité entre-temps ;
- la date du retour ne précède pas la facture et n'est pas dans le futur.

**Statuts :**

| Statut | Signification | Actions |
|---|---|---|
| `brouillon` | Sans numéro, sans effet sur le stock | Modifier, Traiter, Supprimer |
| `traite` | Numéroté ; marchandise remise en stock ou au rebut | Créer la note de crédit (retour d'une facture) |

**Au traitement :**
1. le retour reçoit son numéro (numérotation `retour_client`, `RC-00001` par défaut, voir FLOW-PARAMETRES §7) ;
2. chaque **remise en stock** crée un **arrivage** dans son secteur (mouvement de stock) :
   - motif « Retour client RC-00001 : Produit défectueux » ;
   - daté du jour du retour ;
   - visible en Relocalisation et en Traçabilité ;
   - compté comme une **entrée** par la prise d'inventaire suivante (FLOW-PRISE-INVENTAIRE) ;
   - comme une réception, il ne passe pas par les seuils d'approbation (le traitement est déjà une action réservée) ;
3. le **rebut** ne fait rien entrer en stock : il reste noté sur le retour ;
4. un retour traité ne se modifie ni ne se supprime plus.

**Note de crédit d'un retour** (retour d'une facture, traité) :
- « Créer la note de crédit » prépare une note **brouillon** sur la facture d'origine : les quantités retournées (stock et rebut), au prix facturé ;
- au plus ce qui reste à créditer de chaque ligne ;
- motif « Retour RC-00001 » ;
- elle s'émet ensuite dans l'écran Notes de crédit, comme toute note ;
- **une seule** par retour ; si son brouillon est supprimé, le retour peut en donner une autre ;
- 422 :
  - « Traitez d'abord le retour. » ;
  - « Ce retour n'a pas de facture d'origine : il n'y a rien à créditer. » ;
  - « La facture FAC-00001 est déjà entièrement créditée. ».

**Ailleurs dans l'application :**
- **Détail d'une facture** : liste de ses retours ;
- **Détail d'une note de crédit** : lien vers le retour dont elle est issue ;
- **Liste des retours** : quantités traitées de la sélection (remises en stock, au rebut, par raison) ;
- **Recherche globale** : retours traités, par numéro, client ou facture.
- **Renvoyer au fournisseur** (avec le module Achats) : un retour traité prépare un retour fournisseur de ses produits, par exemple défectueux (FLOW-ACHATS §9).

| Route | Rôle |
|---|---|
| `GET /factures/{id}/retour` | Ce qui reste à retourner de chaque ligne, et le secteur proposé |
| `GET /retours-clients` | Liste (statut, client, facture, période, recherche), avec `resume` |
| `GET /retours-clients/{id}` | Détail |
| `POST /retours-clients` | Brouillon : `facture_id` ou `client_id`, `date_retour`, `notes`, `lignes[]` |
| `PUT /retours-clients/{id}` | Modifier un brouillon (client et facture restent ceux de sa création) |
| `POST /retours-clients/{id}/traiter` | Traiter (action nommée « Traiter un retour client ») |
| `POST /retours-clients/{id}/crediter` | Note de crédit brouillon (droit de créer les notes de crédit) |
| `DELETE /retours-clients/{id}` | Supprimer un brouillon |

**Une ligne** (`lignes[]`) :
- `facture_ligne_id` pour un retour d'une facture, ou `produit_id` sans facture ;
- `quantite` ;
- `traitement` : `remise_en_stock` (avec `secteur_id`) ou `rebut` ;
- `raison_id` (raison de type `retour_client`), `notes`.

**Droits** (module « Retours clients ») :
- à la mise à jour, chaque groupe reçoit ceux des factures ;
- **Traiter un retour client** reprend le réglage de « Émettre une facture ».

**Données :** tables `retours_clients` et `retour_client_lignes` (la ligne garde l'arrivage créé, `mouvement_id`).

Code :
- API : service `App\Services\RetoursClients`, migration `2026_10_16_100035_creer_retours_clients` ;
- web : page `RetoursClientsPage`, composant `retours/EditeurRetour`.

### 5.13 Frais additionnels et de livraison (Paramètres → Frais additionnels)

Plan de parité fripv2, phase 1.4 : les frais que fripv2 ajoute d'eux-mêmes aux devis, commandes et factures (manutention, matière dangereuse, frais de livraison d'un client).

**Un frais :**
- **libellé** et **produit de la ligne** (de préférence un service : il porte la ligne ajoutée au document, avec ses taxes) ;
- **calcul** :
  - **montant fixe** dans la devise de l'entreprise ;
  - ou **pourcentage** du montant HT des lignes concernées, remises de ligne déduites ;
- **application** :
  - **sur tout document** : dès qu'il contient une ligne de marchandise ;
  - **sur certains produits** : seulement si le document contient un des produits choisis ; un pourcentage porte sur leur montant ;
  - **frais de livraison** : pour les clients qui l'ont dans leur fiche (Clients → Modifier → « Frais de livraison ») ;
- **répétition** (montant fixe) : une fois par document, ou une fois par ligne concernée. Un pourcentage donne toujours une seule ligne ;
- **documents** : devis, commandes, factures (au moins un) ;
- **actif**.

**Pendant la saisie** d'un devis, d'une commande ou d'une facture :
- les frais applicables s'affichent sous les lignes, avec leur montant ; le total HT les compte ;
- ils sont **recalculés** quand le client ou les lignes changent ;
- l'utilisateur peut en **retirer** : un frais retiré ne revient pas pour ce document ;
- à l'enregistrement, ils deviennent des **lignes ordinaires** du document : PDF, taxes, conversions devis → commande → facture, notes de crédit.

**Règles :**
- les lignes d'un produit de frais n'en déclenchent pas d'autre ;
- un frais dont le produit est déjà sur le document n'est pas proposé de nouveau (brouillon modifié, ligne saisie à la main) ;
- dans un document en devise étrangère, un montant fixe est converti au taux du document ; un frais qui tombe à 0,00 n'est pas proposé ;
- un frais inactif, ou dont le produit est supprimé ou désactivé, n'est pas proposé ;
- rien n'est ajouté sans l'écran : un document créé par l'API ou par l'espace client ne reçoit pas de frais.

**Suppression d'un frais :**
- les clients qui l'avaient comme frais de livraison n'en ont plus ;
- de même quand un frais de livraison change d'application ;
- les documents déjà faits gardent leurs lignes.

| Route | Rôle |
|---|---|
| `GET /frais` | Liste (`application`, `actifs=1`) |
| `POST /frais`, `PUT /frais/{id}`, `DELETE /frais/{id}` | Réglage (droit de modifier la configuration) |
| `POST /frais/calculer` | Lignes de frais d'un document en cours : `document` (devis, commande, facture), `client_id`, `taux_change`, `lignes[]` (`produit_id`, `quantite`, `prix_unitaire_ht`, `remise_ligne`) |

**Données :** tables `frais` et `frais_produits` ; `clients.frais_livraison_id`.

Code : service `App\Services\FraisDocuments`, migration `2026_10_16_100037_creer_frais_additionnels` ; web : page `FraisPage`, crochet `useFraisAutomatiques`, composant `FraisDocument`.

### 5.14 Reliquats clients (Ventes → Reliquats clients)

Plan de parité fripv2, phase 1.4 : la gestion des B/O client (back orders). Un reliquat note qu'un client attend un produit faute de stock ; il est commandé au fournisseur, suivi à la réception, puis facturé.

**Noter un reliquat :**
- depuis une commande client **acceptée** (ou facturée) : action « Noter des reliquats » de la liste des commandes. Pour chaque ligne, la quantité qui manque, au plus la quantité commandée moins les reliquats déjà notés. Prix, remise, devise et date souhaitée viennent de la commande ;
- ou à la main : **Nouveau reliquat** (client, produit, quantité attendue, prix convenu, date souhaitée, notes).

La commande client elle-même ne change pas.

**États :**

| État | Signification | Actions |
|---|---|---|
| `cree` (inscrit) | Pas encore commandé au fournisseur | Commander au fournisseur, Annuler, Supprimer |
| `commande` | Sur une commande fournisseur | (suivi automatique) |
| `recu_partiel` | Une partie est arrivée | (suivi automatique) |
| `recu` | Tout est arrivé, ou la commande fournisseur a été close après une réception partielle | Facturer |
| `facture` | Sur une facture | — |
| `annule` | Le client ne l'attend plus | — |

**Commander au fournisseur** (reliquats inscrits cochés) :
- une **commande fournisseur brouillon par fournisseur** : celui choisi pour tous, sinon le **fournisseur principal** de chaque produit (422 si un produit n'en a pas) ;
- une ligne par produit, à la somme des quantités ;
- au **dernier prix payé** à ce fournisseur, sinon son prix d'achat convenu, sinon 0 ;
- la commande se vérifie et se valide ensuite comme toute commande fournisseur.

**Suivi automatique**, par la commande fournisseur :
- chaque **réception** de la ligne se répartit sur ses reliquats, du plus ancien au plus récent : reçu en partie, puis reçu ;
- commande **annulée**, **supprimée**, ou ligne retirée ou changée de produit dans le brouillon : ses reliquats qui n'ont rien reçu redeviennent **inscrits**, prêts à repartir chez un autre fournisseur ;
- commande **close** sans tout recevoir : les reliquats qui ont reçu quelque chose passent **reçus** (ils se facturent à la quantité reçue) ; les autres redeviennent inscrits.

**Facturer** (reliquats reçus cochés) :
- une **facture brouillon par client** (et par devise) ;
- une ligne par reliquat, à la **quantité reçue** et au prix convenu ;
- taxes et échéance comme une facture saisie ;
- elle s'émet ensuite depuis les factures ;
- 422 si un reliquat choisi n'est pas reçu.

| Route | Rôle |
|---|---|
| `GET /reliquats-clients` | Liste (`statut` ou `ouverts`, client, produit, commande, recherche), avec `resume` (nombre par état) |
| `GET /reliquats-clients/depuis-commande/{id}` | Lignes d'une commande, avec ce qui en est déjà en reliquat |
| `POST /reliquats-clients/depuis-commande/{id}` | Reliquats d'une commande : `lignes[]` (`ligne_id`, `quantite`) |
| `POST /reliquats-clients` | Reliquat saisi à la main |
| `PUT /reliquats-clients/{id}`, `POST /{id}/annuler`, `DELETE /{id}` | Modifier, annuler, supprimer un reliquat inscrit |
| `POST /reliquats-clients/commander` | `ids[]`, `fournisseur_id` facultatif (action nommée « Commander des reliquats au fournisseur ») |
| `POST /reliquats-clients/facturer` | `ids[]`, `date_facture` (action nommée « Facturer des reliquats reçus ») |

**Droits** (module « Reliquats clients ») :
- à la mise à jour, chaque groupe reçoit ceux des commandes client : un commercial note un reliquat comme il saisit une commande ;
- « Commander » reprend le droit de créer une commande fournisseur ;
- « Facturer » reprend le droit de créer une facture.

**Données :** table `reliquats_clients` (quantités attendue, commandée, reçue ; liens vers la commande client, la ligne fournisseur, la facture).

Code :
- API : service `App\Services\ReliquatsClients` et création des factures dans `App\Services\FacturesVente` (partagée avec la saisie d'une facture), migration `2026_10_16_100038_creer_reliquats_clients` ;
- web : page `ReliquatsClientsPage`, composant `reliquats/ReliquatsDepuisCommande`.

### 5.15 Disponibilité des produits et réservation (Ventes → Disponibilités)

Plan de parité fripv2, phase 1.4 : la réservation de stock. Dans fripv2, une commande réserve sa marchandise ; PI compte son stock par les scans et ne le décrémente pas : la réservation **se calcule**.

**Par produit :**

| Colonne | Calcul |
|---|---|
| Stock compté | Somme des scans du produit, comme les alertes de stock |
| Réservé | Quantités des commandes client **acceptées** (pas encore facturées), moins ce qui en est noté en reliquat (§5.14) |
| Disponible | Stock moins réservé ; **négatif = manque** |
| En commande | Reste à recevoir des commandes fournisseur envoyées ou reçues en partie |
| En reliquat | Ce que les clients attendent encore (reliquats inscrits, commandés, reçus en partie) |

**Filtres :**
- produits **engagés** (par défaut) : réservés, en commande ou en reliquat ;
- produits **en manque** ;
- **tous** les produits actifs.

**À l'acceptation d'une commande client :**
- la réponse de `POST /commandes-client/{id}/accepter` liste ses `manques`, l'acceptation n'étant pas bloquée : les produits dont le disponible, cette commande comprise, est négatif, avec la quantité qui manque (au plus celle commandée) ;
- l'écran propose alors de **noter ces quantités en reliquat**, en pré-remplissant la fenêtre des reliquats de la commande.

| Route | Rôle |
|---|---|
| `GET /disponibilites` | Liste (`recherche`, `manques=1`, `tous=1`), avec `resume` (produits, en manque) |

Code : service `App\Services\DisponibiliteProduits` ; web : page `DisponibilitesPage`.

### 5.16 Abonnements clients (Ventes → Abonnements)

Plan de parité fripv2, phase 1.8 (« Facturation des abonnements ») : un produit ou un service facturé à un client à intervalle régulier, sans refaire la facture à la main.

**Un abonnement :**

| Champ | Rôle |
|---|---|
| Client, produit, quantité | Ce qui est facturé à chaque échéance (une ligne de facture) |
| Prix fixe | Coché : le prix saisi (dans la devise du client). Décoché : le **tarif du client** à la date de chaque échéance (liste de prix, remise, règles de prix), converti dans sa devise |
| Fréquence | Mensuelle, trimestrielle, semestrielle ou annuelle |
| Jour de facturation | De 1 à 28, pour tomber le même jour tous les mois ; au-delà du mois, le **mois de facturation** (par défaut celui du début) |
| Début de la période | Jour (et mois, au-delà du mois) où commence la période couverte par la facture |
| Facturer la période écoulée | La période recule d'un cycle : facturer le 5 l'usage du mois précédent |
| Dates de début et de fin | Les échéances tombent entre les deux ; dix ans au plus (cinq ans proposés par défaut) |
| Texte sur la facture | Ajouté sous le produit sur la facture (`facture_lignes.description`) ; variables `{mois}`, `{annee}`, `{debut_periode}`, `{fin_periode}`, remplacées par la période facturée |
| Garder les factures en brouillon | Une facture qui contient cet abonnement reste en brouillon, même quand on émet les factures |

**Échéances :** elles sont calculées à l'enregistrement, de la date de début à la date de fin. La première tombe à la date de début ou juste après.

**Période couverte :** celle qui court à la date de l'échéance. Facturé le 1er, la période est le mois qui commence ; avec « période écoulée », c'est le mois précédent. L'aperçu de la saisie montre la première échéance, sa période, le texte rendu et le prix.

**Modifier :** la quantité, le prix et le texte valent pour les prochaines factures. Changer le calendrier (fréquence, jour, mois, dates) recalcule les échéances pas encore facturées, après la dernière échéance facturée ; les échéances facturées restent. Une fois une échéance facturée, la date de début et le client ne changent plus (422).

**Supprimer :** l'abonnement quitte la liste, ses échéances pas encore facturées aussi. Les factures déjà faites restent.

**Statut :** actif ; bientôt échu (fin dans les 30 jours) ; échu (fin passée). La liste filtre par statut et cherche par client ou par numéro de facture.

La liste donne, pour chaque abonnement :
- le prix du jour et les totaux ramenés au mois et à l'année ;
- la prochaine échéance et le nombre d'échéances en retard ;
- les échéances dépliées : en retard d'abord, puis à venir, puis facturées, chacune avec sa facture.

**Facturer les échéances dues** (tous les clients, ou « Facturer ce client » sur une ligne) : les échéances pas encore facturées jusqu'à la date choisie (aujourd'hui par défaut, un an au plus) deviennent de vraies factures.
- une facture par client et par date d'échéance, **datée de l'échéance**, une ligne par abonnement ;
- conditions de paiement et devise du client, taxes comme une facture saisie sans taux (groupe de taxes par défaut, sinon taux de l'entreprise), par `FacturesVente::creer` ;
- en brouillon, ou **émises** si la case est cochée (statut émise, encours du client), sauf une facture qui contient un abonnement « brouillon » ;
- une échéance facturée n'est jamais reprise ;
- un prix ou un taux de change manquant est rapporté dans le bilan (client, date, message) sans arrêter les autres factures ;
- la numérotation automatique des factures doit être active.

**Relier une échéance** à une facture faite à la main pour elle (une facture du même client) : elle n'est plus facturée. Une échéance déjà reliée ne se relie pas une seconde fois.

**Facturation à l'usage** (« Quantité facturée : à l'usage ») : au lieu d'une quantité fixe, chaque échéance facture la consommation relevée sur sa période, au-delà de la **quantité incluse**. La période écoulée est alors proposée : facturer le 5 la consommation du mois précédent.
- **Relevés :** une quantité consommée à une date, saisie dans la ligne dépliée de l'abonnement, ou importée d'un fichier CSV (« Importer des relevés »). Le fichier a pour colonnes client (code), produit (numéro), date (AAAA-MM-JJ ou JJ/MM/AAAA), quantité et note facultative ; le séparateur est ; , ou la tabulation. Chaque relevé va à l'abonnement à l'usage du client pour ce produit, en cours à cette date. L'import est tout ou rien : à la moindre erreur, rien n'est importé, et les erreurs sont listées avec leur numéro de ligne.
- **Facturation :** quantité = somme des relevés datés dans la période, moins la quantité incluse.
  - Consommation couverte par l'incluse : l'échéance se clôt **sans facture** (statut « Rien à facturer », compté « sans rien à facturer » dans le bilan).
  - Aucun relevé sur la période : l'échéance attend, l'erreur « Relevé manquant pour … : période du … au … » est rapportée.
- **Relevés figés :** un relevé dont la période est facturée (ou close) ne se retire plus. Une période facturée ne reçoit plus de relevé.
- Facturée après coup, un abonnement n'a pas d'échéance pour une période finie avant son début (cela vaut aussi pour la quantité fixe).

Routes : `GET`/`POST /abonnements-clients/{id}/releves`, `DELETE /abonnements-clients/{id}/releves/{releveId}`, `POST /abonnements-clients/releves/import` (`fichier`). Il faut le droit de modifier les abonnements ; la consultation est libre. Migration `2026_10_17_100050_facturation_a_l_usage` : `abonnements_clients.mode_quantite`, `quantite_incluse` ; table `abonnement_releves` ; `abonnement_echeances.rien_a_facturer`.

**Tâche planifiée** `facturation_abonnements` (inactive par défaut) : chaque jour, elle facture les échéances dues à ce jour, en brouillon ou émises selon son paramètre (FLOW-TACHES-PLANIFIEES).

**Droits :** module **Abonnements clients** (copié des factures à sa création). Facturer et relier demandent l'action **Facturer les abonnements** (`abonnements_clients.facturer`), plus le droit de créer des factures, et de les émettre si on les émet.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/abonnements-clients` | Liste (`statut` : actif par défaut, bientot_echu, echu, tous ; `recherche`, `produit_id`, `client_id`) |
| GET | `/abonnements-clients/{id}` | Abonnement et ses échéances |
| POST / PUT / DELETE | `/abonnements-clients[/{id}]` | Saisie, modification (calendrier recalculé), suppression |
| POST | `/abonnements-clients/apercu` | Première échéance, période, texte rendu, prix, nombre d'échéances |
| POST | `/abonnements-clients/facturer` | Facturer (`jusquau`, `client_id`, `emettre`) ; bilan : factures, échéances, erreurs |
| POST | `/abonnements-clients/{id}/echeances/{echeanceId}/relier` | Relier une échéance à une facture (`facture_id`) |

Code : service `App\Services\AbonnementsClients`, `AbonnementClientController`, tâche `App\Taches\FacturerAbonnements`, `FacturesVente::emettre` (émission partagée avec `POST /factures/{id}/emettre`) ; web : page `AbonnementsClientsPage` ; migration `2026_10_17_100048_creer_abonnements_clients`.

### 5.17 Contrats de location (Ventes → Contrats de location)

Plan de parité fripv2, phase 1.8 (« Contrats de location », « Types de location ») : des produits loués à un client sur une période. Bouteilles de gaz, palettes, échafaudages, matériel : tout ce qui se loue et se rend.

**Un contrat :**

| Élément | Rôle |
|---|---|
| Numéro | Automatique, `CT-00001` (Paramètres → Numérotation, entité « Contrats de location ») |
| Client, dates de début et de fin | La période du contrat |
| Type de location | Facultatif (référentiel, FLOW-PARAMETRES §11). Il préremplit le rythme, le jour, le mode de facturation et la fin (début + durée) |
| Rythme des loyers, jour (1 à 28), à l'avance ou après coup | Quand les loyers se facturent, et quelle période ils couvrent |
| Prochaine facturation | Calculée depuis la date de début et le jour ; modifiable |
| Lignes | Produit, quantité louée, unités exemptées (comprises dans un forfait), **prix de contrat** (facturé une fois : contenu, caution, mise en service) et **loyer** (par unité et par période), dans la devise du client |
| Actif | Un contrat inactif n'est plus facturé ni renouvelé |
| Renouvellement automatique, hausses (%) | Le contrat suivant est créé à la fin, prix de contrat et loyers augmentés des taux |

**Facturer le contrat :** une facture brouillon des prix de contrat pas encore facturés (texte « Contrat CT-… » sous chaque produit).
- Chaque ligne n'est facturée qu'une fois ; une ligne facturée ne change plus et ne se retire plus (422).
- Pour louer davantage, on ajoute une ligne ; elle fait une facture complémentaire.
- Un contrat jamais facturé se supprime ; sinon on le désactive.

**Loyers** (« Facturer les loyers dus », ou la tâche `facturation_locations`) : jusqu'à la date choisie, pour chaque contrat actif dont la prochaine facturation est arrivée (et pas après la fin), une facture par période, datée de la facturation prévue.
- Une ligne par produit : unités louées, moins les exemptées et les rendues, au loyer de la ligne.
- Texte sous chaque produit : « Location du … au … — contrat CT-… ».
- Période : celle qui commence à la date de facturation (à l'avance), ou celle qui vient de finir (après coup).
- La prochaine facturation avance alors d'une période ; une période sans unité à louer passe sans facture.
- En brouillon, ou émises si on le demande ; une erreur (taux de change manquant) est rapportée sans arrêter les autres contrats.

**Retour :** des unités rendues (quantité, date) ne paient plus de loyer à partir de la facturation suivante. On ne rend pas plus que ce qui est chez le client.

**Renouveler :** le contrat suivant reprend le client, le type, le rythme et les produits encore chez le client (un produit par ligne, aux prix de la ligne la plus récente). Il commence le lendemain de la fin, pour la durée du type, sinon la même durée. Il garde le lien avec le précédent (« Renouvelle » / « Renouvelé par »). Un contrat ne se renouvelle qu'une fois.
- À la main : mêmes prix, à revoir avant de le facturer.
- Automatique (tâche `renouvellement_contrats`, pour les contrats actifs marqués « renouvellement automatique » arrivés à leur fin) : prix augmentés des taux, et facture brouillon des prix de contrat du nouveau contrat.

**Factures du contrat :** `factures.contrat_location_id` relie les factures de prix de contrat et de loyers à leur contrat ; la fiche du contrat les liste.

**Imprimer :** le contrat en PDF : loueur et client, période, rythme, produits (quantités, prix de contrat, loyer), totaux, signatures.

**Droits :** module **Contrats de location**, copié des factures à sa création.
- Facturer le contrat et les loyers demandent **Facturer les contrats de location** (`contrats_location.facturer`), plus le droit de créer des factures (et de les émettre si on les émet).
- Renouveler à la main demande **Renouveler un contrat de location** (`contrats_location.renouveler`).

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/contrats-location` | Liste (`statut` : actifs par défaut, inactifs, tous ; `recherche`, `client_id`, `fin_avant`) avec loyer par période, unités louées, lignes à facturer |
| GET | `/contrats-location/{id}` | Contrat, lignes, factures, contrats précédent et suivant |
| POST / PUT / DELETE | `/contrats-location[/{id}]` | Saisie (lignes facturées figées), suppression d'un contrat jamais facturé |
| POST | `/contrats-location/{id}/facturer` | Facture des prix de contrat pas encore facturés |
| POST | `/contrats-location/facturer-loyers` | Loyers dus (`jusquau`, `client_id`, `emettre`) ; bilan |
| POST | `/contrats-location/{id}/lignes/{ligneId}/retour` | Retour d'unités (`quantite`, `date_retour`) |
| POST | `/contrats-location/{id}/renouveler` | Contrat suivant |
| GET | `/contrats-location/{id}/pdf` | Contrat imprimé |

Code : services `App\Services\ContratsLocation` et `ContratLocationPdf`, `ContratLocationController`, tâches `FacturerLocations` et `RenouvelerContrats`, vue `pdf/contrat-location` ; web : page `ContratsLocationPage`, référentiel `types_location` ; migration `2026_10_17_100049_creer_contrats_location`.

### 5.18 Consignation chez le client (Ventes → Consignations)

Plan de parité fripv2, phase 1.9 (« Relevé de consignation », « Consignations chez les clients ») : de la marchandise déposée chez un client (prêt, démonstration, stock de dépannage) reste à l'entreprise jusqu'à ce qu'il la consomme ou la rende.

**Bon de dépôt** (onglet Bons de dépôt) : un document **sans montant** (ce n'est pas une vente), numéroté `CS-00001` (Paramètres → Numérotation).
- Client, date, produits et quantités ; un même produit saisi deux fois fait une seule ligne.
- Chaque ligne garde le **coût unitaire** au dépôt : le coût moyen d'achat, celui de la valorisation de l'inventaire (FLOW-INVENTAIRE), inconnu sans achat.
- Imprimé : le bon (produits, quantités, avis de propriété, signatures).

**Relevé** (par client) : ce que le client détient, produit par produit.
- Quantité consignée, **coûtant** (moyenne pondérée des dépôts), **valeur au coût**, date du dernier dépôt.
- Total des unités et de la valeur.
- Le solde est la somme des mouvements (dépôts, retours, consommations).

**Reprendre** (colonne Retourné, puis « Enregistrer la reprise ») : le client rend de la marchandise.
- Le **lieu de retour** est obligatoire (secteur, entrepôt ou magasin ; il n'est jamais prérempli).
- La quantité est déduite du solde.

**Facturer la consommation** (colonne Consommé, puis « Facturer la consommation ») : le client a utilisé la marchandise.
- Facture brouillon, ou émise si demandé, **aux prix du client** (liste de prix, remise, règles), dans sa devise, texte « Marchandise en consignation consommée ».
- Le solde baisse aussitôt.
- Un produit sans prix est refusé.

**Règles :**
- On ne sort jamais plus que le solde : 422 « Quantité trop grande pour … : le client en détient … ». Le solde est revérifié sous verrou au moment d'écrire.
- Un geste à la fois : l'écran refuse de facturer s'il reste des retours saisis, et inversement.
- Changer de client efface les saisies.
- Le stock compté de PI (les scans) n'est pas touché : la consignation est un registre à part.

**Historique** (par client, sur une période, les 12 derniers mois par défaut) : dépôts (+), retours (−, avec le lieu) et consommations (−, avec la facture), du plus récent au plus ancien. Une période inversée est refusée.

**Consignations chez les clients** (rapport) : tous les clients ou un seul, avec ou sans les produits à quantité nulle. Un bloc par client (classés par nom, produits par numéro) : quantités, coûtant, valeur, dernier dépôt, sous-total ; total général. En PDF (paysage) aussi.

**Droits :** module **Consignations**, copié des factures à sa création.
- Créer un bon de dépôt : créer.
- Reprendre : modifier.
- Facturer la consommation : **Facturer la consommation en consignation** (`consignations.facturer`), plus le droit de créer des factures (et de les émettre si on les émet).
- Relevé, historique et rapport : lecture.

| Méthode | Route | Rôle |
|---|---|---|
| GET / POST | `/consignations` | Bons de dépôt (liste ; nouveau : `client_id`, `date_depot`, `notes`, `lignes[]`) |
| GET | `/consignations/{id}` (`/pdf`) | Bon de dépôt (imprimé) |
| GET | `/consignations/releve?client_id=` | Soldes du client, valeur au coût |
| POST | `/consignations/reprise` | Reprise (`client_id`, `lieu_retour`, `lignes[]`) |
| POST | `/consignations/facturer` | Consommation facturée (`client_id`, `lignes[]`, `emettre`) |
| GET | `/consignations/historique?client_id=&du=&au=` | Mouvements de la période |
| GET | `/consignations/rapport` | Consignations chez les clients (`client_id`, `nuls`, `format=pdf`) |

Code : services `App\Services\Consignations` et `ConsignationsPdf`, `ConsignationController`, vues `pdf/consignation-depot` et `pdf/consignations-rapport` ; web : page `ConsignationsPage` ; migration `2026_10_17_100051_creer_consignations` (tables `consignations`, `consignation_lignes`, `consignation_mouvements`).

### 5.19 Gestion de caisse (Ventes → Gestion de caisse)

Plan de parité fripv2, phase 1.10a (« Caisses », « Gestion de caisse », « Fermeture de caisse », « Sommaire de caisse ») : l'argent d'une caisse se suit du début à la fin de la journée.

**Caisses** : référentiel **Caisses** (Paramètres → Référentiels), un tiroir-caisse ou point d'encaissement par fiche (code court unique, en majuscules, et nom). Une caisse qui a déjà servi ne se supprime pas : on la désactive.

**Poste de caisse** : sur l'écran Gestion de caisse, « Caisse de ce poste » fait du navigateur un poste de caisse (réglage du navigateur, par entreprise, comme la configuration de démarrage d'un poste dans fripv2).
- Les **paiements de factures**, les **acomptes reçus** (aussi par une demande d'acompte payée) et les **remboursements d'acompte** saisis sur ce poste entrent dans la session ouverte de sa caisse ; la fenêtre le rappelle et propose les espèces.
- Caisse fermée, l'encaissement est refusé (422 sur `caisse_id`). Avec le droit de gérer la caisse, une fenêtre propose de l'ouvrir sur-le-champ (fond initial) et l'encaissement reprend.
- Un poste qui n'est pas une caisse encaisse comme avant, hors caisse.
- Appliquer un acompte sur une facture n'apporte pas d'argent : ce paiement-là n'entre dans aucune caisse. De même pour un paiement par carte cadeau ; en revanche, une carte cadeau vendue ou rechargée sur le poste est un encaissement, et son remboursement une sortie (§5.21).

**Session** : de l'ouverture à la fermeture ; une seule session ouverte par caisse.
- **Ouvrir** avec un fond initial (il peut être nul), en espèces par défaut.
- **Entrée** et **sortie d'argent** en cours de session ; la raison d'une sortie est obligatoire. Un remboursement d'acompte fait avec le bouton Rembourser sort déjà du tiroir : il ne se saisit pas en sortie.
- **Attendu**, par mode de paiement et par devise : fond + encaissements + acomptes − remboursements + entrées − sorties. Une facture ou un acompte en devise étrangère payé en espèces fait une ligne à part (« Espèces (USD) ») avec son propre total.
- **Fermer** : le montant compté de chaque mode (vide = 0) et l'écart en direct, en rouge s'il n'est pas nul ; une note de fermeture. L'attendu, le compté et l'écart sont alors **figés** : une transaction corrigée après coup ne change plus le sommaire. Un message confirme la fermeture et rappelle l'écart.
- Une session restée ouverte (depuis la veille) se ferme en tout temps : l'attendu couvre toute la période.

**Écran** : la caisse et sa session en cours (attendu dans le tiroir), l'historique des sessions d'une période (7 derniers jours par défaut, plus celles encore ouvertes) avec attendu, compté et écart, puis le détail de la session choisie :
- sommaire par mode de paiement (et par devise) ;
- chaque opération : date et heure, type (fond, encaissement, acompte reçu, remboursement d'acompte, entrée, sortie), document (numéro de facture, acompte), client, mode, montant (sorties et remboursements en négatif), employé, note ; filtre sur le document, le client ou la note, tri par montant ;
- encaissements par employé (acomptes compris, moins leurs remboursements) ;
- encaissements faits **hors caisse** pendant la session (postes qui ne sont pas des caisses).

**Sommaire de caisse** (PDF paysage, session fermée seulement : « Fermer et imprimer » ou « Imprimer le sommaire ») : en-tête (caisse, ouverture et fermeture, employés, note), sommaire par mode avec les totaux, détail des opérations, encaissements par employé et hors caisse. Les heures sont celles du fuseau de l'utilisateur.

**Droits :** module **Gestion de caisse** (`caisses`), copié des factures à sa création.
- Consulter (écran, sessions, sommaire) : `caisses.voir` ; les lectures de `/caisses` sont contrôlées.
- **Gérer les sessions de caisse** (`caisses.gerer`) : ouvrir, entrées et sorties d'argent, fermer ; à sa création, réglé comme le droit d'enregistrer un paiement.
- Encaisser sur un poste de caisse ne demande que le droit d'encaisser du document (paiement de facture, acompte, remboursement, demande d'acompte).
- Les caisses elles-mêmes se gèrent avec les droits des référentiels.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/caisses` | Caisses actives (et encore ouvertes) avec leur session en cours et son attendu |
| POST | `/caisses/{id}/ouvrir` | Ouvrir (`fond`, `mode`, `devise`, `note`) |
| GET | `/caisses/sessions?caisse_id=&du=&au=&fuseau=` | Sessions de la période et sessions ouvertes, avec leurs totaux |
| GET | `/caisses/sessions/{id}` | Sommaire : lignes par mode et devise, totaux, opérations, par employé, hors caisse |
| POST | `/caisses/sessions/{id}/mouvements` | Entrée ou sortie (`type`, `mode`, `devise`, `montant`, `note` obligatoire en sortie) |
| POST | `/caisses/sessions/{id}/fermer` | Fermer (`comptes[]` : `mode`, `devise`, `montant` ; `note`) |
| GET | `/caisses/sessions/{id}/pdf?fuseau=` | Sommaire imprimé (session fermée) |
| POST | `/factures/{id}/paiement`, `/acomptes`, `/acomptes/{id}/rembourser`, `/demandes-acompte/{id}/encaisser` | Paramètre `caisse_id` facultatif : la caisse du poste |

Code : services `App\Services\Caisses` et `SommaireCaissePdf`, `CaisseController`, vue `pdf/sommaire-caisse` ; web : page `CaissesPage`, `hooks/useCaissePoste` (caisse du poste), `hooks/useEncaissementCaisse` (encaisser, ouvrir la caisse fermée) et `components/caisse/*` ; migration `2026_10_17_100052_creer_caisses` (référentiel `caisses`, tables `caisse_sessions`, `caisse_mouvements`, colonne `caisse_session_id` sur `facture_paiements`, `acomptes` et `acompte_remboursements`).

### 5.20 Point de vente (Ventes → Point de vente)

Plan de parité fripv2, phase 1.10b (facturation sur un poste de caisse et fenêtre « Payer facture ») : une vente au comptoir se fait en un geste.

**Panier :**
- **Ajouter des produits** : on scanne un code ou on tape un numéro, puis Entrée (le produit au numéro exact, ou le seul trouvé) ; on peut aussi chercher par la désignation et choisir dans la liste. Un produit déjà au panier gagne une unité.
- **Quantités et prix** : quantités modifiables. Les prix sont **ceux du client** (liste de prix, remise, règles), convertis dans sa devise ; un prix saisi les remplace. Les totaux HT, taxes et TTC se recalculent à chaque changement, avec les mêmes règles de taxes que les factures.
- **Client** : le client de passage par défaut, dont le nom peut être noté sur la facture, ou un client de la liste.
- Un produit sans prix doit être chiffré avant d'encaisser.

**Encaisser :** un ou plusieurs modes de paiement. L'écran montre en direct le total, le reçu, le reste et la **monnaie à rendre**.
- Seules les **espèces** peuvent dépasser ce qui reste dû. L'excédent est la monnaie rendue : seul le dû est encaissé, et l'argent remis est noté pour le reçu (`facture_paiements.montant_recu`).
- Le **client de passage** paie toute la vente, et un paiement en espèces règle toute la vente.
- Un client en compte peut payer une partie, par chèque, carte ou virement, ou rien (« Porter au compte ») : le reste demeure à recevoir.
- La vente crée la **facture**, l'**émet** et enregistre les **paiements**, d'un seul tenant. Sur un poste de caisse, les paiements entrent dans la session ouverte de sa caisse (§5.19). Caisse fermée, rien n'est créé, pas même un numéro de facture, et l'ouverture est proposée comme pour tout encaissement.
- Une **carte cadeau** est un mode de paiement : on saisit ou scanne son numéro, « Vérifier » montre son solde et propose au plus ce solde (§5.21). Elle ne peut pas dépasser ce qui reste dû ; une vente payée seulement par carte cadeau n'a pas besoin de caisse ouverte.
- Le stock compté (les scans) n'est pas touché, comme pour toute facture.

**Reçu** : ticket de 80 mm (« Imprimer le reçu ») de toute facture émise, qui se réimprime à l'identique.
- En-tête : entreprise, numéro de facture, date et heure dans le fuseau de l'utilisateur, caisse et vendeur, client.
- Contenu : produits, totaux avec le détail des taxes, paiements (référence de la carte, argent remis, monnaie rendue), reste dû.

La facture PDF reste disponible.

**Droits :** module **Point de vente** (`point_de_vente`), copié des factures à sa création.
- **Vendre au comptoir** (`point_de_vente.vendre`) : suit le droit de créer une facture.
- Il faut aussi les droits de créer et d'émettre des factures, et d'encaisser si la vente est payée ; le contrôleur les vérifie.
- L'aperçu (calcul) est ouvert : rien n'est écrit.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/point-de-vente/produits?recherche=` | Produits actifs : numéro exact d'abord, puis numéro ou désignation (20 au plus) |
| POST | `/point-de-vente/calculer` | Aperçu : prix du client (ou saisi) et totaux avec les taxes (`client_id`, `lignes[]`, `date`) |
| POST | `/point-de-vente/ventes` | Vente : facture créée, émise et encaissée. Champs : `client_id` ou client de passage (`passage_nom`…), `date`, `lignes[]`, `paiements[]` (`mode`, `montant`, `reference`), `caisse_id`. Réponse : la facture et `monnaie` |
| GET | `/point-de-vente/recus/{factureId}?fuseau=` | Reçu (ticket de 80 mm) d'une facture émise |

Code : services `App\Services\VentesComptoir` et `RecuVentePdf` (le calcul des lignes est partagé avec la saisie des factures : `FacturesVente::calculerLignes`), `PointDeVenteController`, vue `pdf/recu-vente` ; web : page `PointDeVentePage`, service `pointDeVente.ts` ; migration `2026_10_17_100053_creer_point_de_vente` (`facture_paiements.montant_recu`, droits du module).

### 5.21 Cartes cadeaux (Ventes → Cartes cadeaux)

Plan de parité fripv2, phase 1.10c (« Cartes cadeaux », « Vente et gestion d'une carte cadeau », « Certificat cadeau ») : des cartes **au porteur**, vendues puis utilisées pour payer jusqu'à épuisement de leur solde.

**Vendre une carte** (écran Cartes cadeaux, ou « Vendre une carte cadeau » au point de vente) :
- **Montant, mode de paiement reçu, référence.** Le montant est encaissé, sur la caisse du poste s'il en a une (§5.19).
- **Client acheteur et date d'expiration**, facultatifs. Le client n'est noté qu'à titre indicatif : la carte s'utilise au porteur.
- **Devise** : celle de l'entreprise, ou une devise active du référentiel. Elle est figée à la vente.
- **Numéro** : attribué automatiquement (`CC-000001`, Paramètres → Numérotation). En numérotation manuelle, on saisit ou scanne le numéro de la carte pré-imprimée (lettres, chiffres, tirets).
  - Un numéro n'est jamais réutilisé, même sur une carte annulée.
  - Le compteur automatique saute un numéro déjà saisi à la main.

**Payer avec une carte :**
- **Où :** au point de vente (§5.20) et à l'encaissement d'une facture (mode « Carte cadeau »).
- **Montant :** au plus le solde de la carte et ce qui reste dû.
- **Refus :** carte introuvable, annulée, expirée (à la date du paiement), dans une autre devise que la facture, ou solde insuffisant (« Solde insuffisant sur la carte … (solde disponible : …) »).
- **Enregistrement :** le paiement de la facture est en mode « autre », rattaché à la carte (`facture_paiements.carte_cadeau_id`). Le mode de paiement est une énumération MySQL : rien n'y est ajouté. La carte reste verrouillée pendant le paiement.
- **Hors caisse :** ce paiement n'apporte pas d'argent dans la caisse.
- **Imprimés :** le reçu montre « Carte cadeau CC-… » et le solde restant sur la carte, et le relevé du client « Paiement par carte cadeau ».

**Fiche d'une carte :**
- **Consultation :** solde, statut et historique (vente, recharges, utilisations avec leur facture, remboursement), avec le solde après chaque mouvement.
- **Recharger :** montant et mode reçus. Une carte annulée ne se recharge plus.
- **Rembourser le solde :** tout le solde rendu par le mode choisi, et la carte est **annulée** ; il n'y a pas de remboursement partiel.
- **Expiration :** modifiable tant que la carte n'est pas annulée.

**Statuts** : Active, Épuisée (solde nul), Expirée (date dépassée), Annulée. La liste se cherche par numéro ou client et se filtre par statut. Elle montre le **solde à honorer** : ce que les cartes encore valables peuvent payer, dans la devise de l'entreprise.

**Certificat cadeau** (PDF A5) : l'entreprise, le numéro de la carte, le montant, le solde du moment, la validité et la date de vente.

**Droits :** module **Cartes cadeaux** (`cartes_cadeaux`), copié des acomptes à sa création.
- Consulter (liste, fiche, certificat ; les lectures de `/cartes-cadeaux` sont contrôlées).
- Vendre : créer.
- Recharger et changer l'expiration : modifier.
- **Rembourser le solde d'une carte cadeau** (`cartes_cadeaux.rembourser`) : suit le droit de rembourser un acompte, et demande aussi le droit de modifier.
- Payer avec une carte ne demande que le droit d'encaisser. La vérification d'une carte présentée (`/point-de-vente/carte-cadeau`) reste ouverte.

| Méthode | Route | Rôle |
|---|---|---|
| GET / POST | `/cartes-cadeaux` | Liste (`recherche`, `etat`, solde à honorer) ; vente (`numero` en numérotation manuelle, `client_id`, `devise`, `montant`, `mode`, `reference`, `date`, `expire_le`, `caisse_id`) |
| GET / PUT | `/cartes-cadeaux/{id}` | Fiche et mouvements ; client, expiration, notes |
| POST | `/cartes-cadeaux/{id}/recharger` | Recharge (`montant`, `mode`, `caisse_id`) |
| POST | `/cartes-cadeaux/{id}/rembourser` | Remboursement du solde, carte annulée (`mode`, `caisse_id`) |
| GET | `/cartes-cadeaux/{id}/certificat` | Certificat cadeau (PDF) |
| GET | `/point-de-vente/carte-cadeau?numero=` | Carte présentée pour payer : solde et statut |
| POST | `/point-de-vente/ventes`, `/factures/{id}/paiement` | Mode `carte_cadeau` avec le numéro (`paiements[].carte_cadeau`, `carte_cadeau`) |

Code : services `App\Services\CartesCadeaux` et `CertificatCadeauPdf`, `CarteCadeauController`, vue `pdf/certificat-cadeau` ; web : page `CartesCadeauxPage`, composants `cartesCadeaux/VendreCarte` et `cartesCadeaux/ChampCarteCadeau` ; migration `2026_10_17_100054_creer_cartes_cadeaux` (tables `cartes_cadeaux`, `carte_cadeau_mouvements`, colonne `facture_paiements.carte_cadeau_id`, droits du module).

---

## 6. Bons de Livraison

### 6.1 Ce que c'est

Un **Bon de Livraison (BL)** est le document qui accompagne la livraison physique des marchandises chez le client. Il est créé à partir d'une facture émise.

Il y a deux étapes clés :
1. **La préparation** : on prépare physiquement les articles dans l'entrepôt
2. **La livraison** : on livre les articles au client

### 6.2 Champs d'un BL

| Champ | Description |
|-------|-------------|
| `numero` | Ex: BL-2026-0001 (auto-généré) |
| `facture_id` | La facture d'origine |
| `mode_livraison` | `entreprise` (on livre) ou `retrait_client` (le client vient) |
| `statut` | État actuel |
| `date_preparation` | Quand la préparation a commencé |
| `date_pret` | Quand la préparation est terminée |
| `date_livraison` | Quand la livraison a eu lieu |
| `preparateur_id` | Qui a préparé |
| `signature_client`, `signataire_nom`, `signe_le` | Signature électronique du client à la livraison : image, nom du signataire, moment (§6.3c) |
| `notes_livraison` | Notes laissées à la livraison |

**Lignes (BonLivraisonLigne)**

| Champ | Description |
|-------|-------------|
| `produit_id` | Le produit |
| `quantite_a_livrer` | Quantité prévue (= quantité facturée) |
| `quantite_preparee` | Quantité effectivement préparée |
| `quantite_livree` | Quantité effectivement livrée |
| `statut_ligne` | État de la ligne |

### 6.3 Statuts du BL

| Statut | Signification | Actions disponibles |
|--------|---------------|---------------------|
| `cree` | BL créé, préparation pas encore commencée | Démarrer préparation ▶️, Annuler 🚫 |
| `en_preparation` | Préparation en cours | Modifier quantités préparées, Marquer prêt ✅, Annuler 🚫 |
| `pret` | Prêt à charger | Ajouter à une tournée 📦, Enregistrer livraison 🚚 (retrait par le client, par exemple) |
| `en_livraison` | Chargé dans un camion, en route | Enregistrer livraison 🚚 |
| `livre_complet` | Tout livré | Terminé |
| `livre_partiel` | Livraison partielle | « Livrer le reste » depuis la facture (nouveau bon) |
| `annule` | Annulé | Lecture seule |

### 6.3b Annulation d'un BL

Le bouton 🚫 (annuler) est disponible pour les statuts `cree` et `en_preparation`.

**Endpoint :** `POST /bons-livraison/{id}/annuler`

**Quand l'utiliser :**
- Client annule sa commande après la facturation
- Erreur de création du BL
- Doublon

Un BL annulé ne peut plus être modifié. Les mouvements de stock déjà créés pendant la préparation ne sont pas automatiquement annulés — prévoir un mouvement correctif si nécessaire.

### 6.3c Signature électronique à la livraison

Plan de parité fripv2, phase 1.6 : comme sur les tablettes de livraison de fripv2, le client signe à la réception.

**Recueillir la signature :** la fenêtre **Enregistrer livraison** propose un bloc de signature. Le client signe au doigt, au stylet ou à la souris ; le livreur note son nom. Les deux sont facultatifs, et **Effacer** recommence.

**Ce qui est enregistré :**
- l'image de la signature (PNG encodé, `signature_client`) ;
- le nom du signataire (`signataire_nom`) ;
- le moment de la signature (`signe_le`).

Seule une image PNG ou JPEG est acceptée (422 sinon).

**Où on la retrouve :**
- **Liste :** une plume verte à côté du statut d'un bon signé. L'image, lourde, ne sort pas dans les listes (`a_signature` seulement).
- **Détail :** l'image, le signataire et le moment.
- **PDF du bon :** l'image, le nom et la date, au-dessus de la ligne « Reçu par le client ». Sans signature, la case reste à remplir à la main.

Code : `BonLivraisonController::enregistrerLivraison` (validation), `DocumentsVentePdf::bonLivraison` (impression), composant `PadSignature` ; migration `2026_10_16_100043_signature_livraison`.

### 6.3d Colis (préparation des boîtes)

Plan de parité fripv2, phase 1.6 (« Préparation boîte ») : la marchandise d'un bon se range dans des colis, chacun avec son étiquette.

**Préparer les colis :** le bouton **Colis** (boîtes) d'un bon ouvre la liste de ses colis et ce qui reste à emballer, ligne par ligne. **Nouveau colis** demande une quantité par ligne, puis, si on veut, un poids et une note (« Fragile »).
- Un colis ne peut pas dépasser ce qui reste à emballer sur une ligne : le champ le refuse, l'API aussi (422 « Quantité trop grande : il reste N à mettre en colis sur cette ligne »).
- Un colis vide est refusé.
- **Nouveau colis** disparaît quand tout est emballé.

**Numéro :** celui du bon suivi d'un rang sur trois chiffres (`BL-00010-001`, `BL-00010-002`…). Le rang suit le plus grand déjà pris : un colis retiré libère son numéro seulement s'il était le dernier.

**Modifier, retirer :** le crayon change les quantités, le poids et la note. Toutes les quantités à 0 : le colis est retiré. La corbeille le retire aussi. Un colis coché comme chargé dans le camion (§8.9) ne se modifie plus : on le décoche d'abord.

**Quand :** les colis se préparent et se modifient tant que le bon n'est pas parti, aux statuts `cree`, `en_preparation` et `pret`. Ensuite ils restent consultables et imprimables, mais plus modifiables (422).

**Stock :** il n'est pas touché. Comme la préparation, c'est de la logistique ; les mouvements restent ceux de la préparation et de la livraison.

**Étiquettes :** **Imprimer les étiquettes** (ou l'imprimante d'un colis) ouvre une page d'étiquettes de 76 × 51 mm, une par colis :
- le QR code du numéro du colis ;
- le numéro et le rang (« Colis 1 / 2 ») ;
- le client et le bon ;
- le contenu et le poids.

**Liste des bons :** une pastille sur le bouton Colis donne le nombre de colis (`colis_count`).

**Droits :** consulter les colis suit la lecture des bons de livraison. Créer, modifier et retirer demandent **Bons de livraison → Préparer** (`bons_livraison.preparer`).

| Méthode | Route | Rôle |
|---------|-------|------|
| GET | `/api/bons-livraison/{id}/colis` | Colis, lignes du bon et quantités restantes |
| POST | `/api/bons-livraison/{id}/colis` | Nouveau colis (`lignes[]` : `bon_livraison_ligne_id`, `quantite` ; `poids_kg`, `notes`) |
| PUT | `/api/bons-livraison/{id}/colis/{colisId}` | Nouvelles quantités (toutes à 0 : colis retiré) |
| DELETE | `/api/bons-livraison/{id}/colis/{colisId}` | Retirer un colis |

Code : service `ColisLivraisons`, `ColisLivraisonController`, composant `livraison/ColisBon` ; tables `bl_colis` et `bl_colis_lignes`, migration `2026_10_16_100046_colis_livraison`.

### 6.4 Statuts des lignes

| Statut ligne | Signification |
|--------------|---------------|
| `a_preparer` | Pas encore commencé |
| `en_cours` | Préparation en cours |
| `prepare` | Quantité préparée = quantité à livrer |
| `charge` | Chargé dans le camion |
| `livre` | Livré au client |

### 6.5 Flow complet du BL

```
┌─────────────────────────────────────────────────────────────────┐
│                    BON DE LIVRAISON — FLOW                       │
└─────────────────────────────────────────────────────────────────┘

ÉTAPE 1 : CRÉATION
  → Depuis FacturesPage → bouton "Créer BL" sur une facture émise
  → Endpoint: POST /factures/{id}/creer-bl
  → Statut: cree
  → Les lignes du BL sont copiées depuis les lignes de la facture
  → quantite_a_livrer = quantite de la facture

ÉTAPE 2 : DÉMARRER LA PRÉPARATION
  → Endpoint: POST /bons-livraison/{id}/preparer
  → Statut: en_preparation
  → Toutes les lignes passent en statut_ligne = "en_cours"
  → Enregistre le preparateur_id et la date_preparation

ÉTAPE 3 : SAISIR LES QUANTITÉS PRÉPARÉES
  → Endpoint: PUT /bons-livraison/{id}/lignes
  → Pour chaque ligne, saisir la quantite_preparee
  → Si quantite_preparee = quantite_a_livrer → ligne = "prepare"
  → Si quantite_preparee < quantite_a_livrer → ligne = "en_cours"
  → Un mouvement de stock "sortie_preparation" est créé

ÉTAPE 4 : MARQUER PRÊT
  → Endpoint: POST /bons-livraison/{id}/pret
  → Statut: pret
  ⚠️ Si une ligne a quantite_preparee = 0, le système la remplit automatiquement
     avec quantite_a_livrer (pour ne pas bloquer le flow)

ÉTAPE 5 : AJOUTER À UNE TOURNÉE (optionnel)
  → Depuis TourneesPage → bouton 📦+ sur une tournée planifiée
  → Statut BL reste "pret" jusqu'au démarrage de la tournée

ÉTAPE 6 : DÉMARRER LA TOURNÉE
  → Tous les BL de la tournée passent automatiquement en "en_livraison"
  → Toutes les lignes passent en "charge"

ÉTAPE 7 : ENREGISTRER LA LIVRAISON
  → Endpoint: POST /bons-livraison/{id}/livrer
  → Pour chaque ligne, saisir la quantite_livree
  → Signature du client tracée à l'écran et nom du signataire (optionnels, §6.3c)
  → Notes de livraison (optionnel)
  → Un mouvement "livraison_client" est créé

  → Si tout livré (quantite_livree = quantite_a_livrer pour toutes les lignes) :
       Statut: livre_complet

  → Si livraison partielle :
       Statut: livre_partiel
       (rien n'est refacturé : un nouveau bon de la facture livrera le reste)
```

### 6.6 Création du BL — comment ça se passe

La création est déclenchée depuis la page Factures. Aucune saisie manuelle de quantité n'est nécessaire : les quantités sont automatiquement reprises depuis les lignes de la facture.

---

## 7. Camions

### 7.1 Ce que c'est

Un **Camion** est un véhicule de livraison. Il est associé à une tournée et transporte les BL jusqu'aux clients.

### 7.2 Champs d'un camion

| Champ | Description |
|-------|-------------|
| `immatriculation` | Plaque d'immatriculation (unique) |
| `marque` | Marque du véhicule |
| `modele` | Modèle |
| `capacite_kg` | Capacité de charge en kg |
| `actif` | Disponible ou non |

### 7.3 Disponibilité

`GET /camions/disponibles?date=YYYY-MM-DD` retourne les camions qui n'ont pas de tournée planifiée ou en cours ce jour-là. Utilisé lors de la création d'une tournée pour ne proposer que les camions libres.

---

## 7 bis. Comptes clients (Ventes → Comptes clients)

Plan de parité fripv2, phase 4.4 : ce que les clients doivent, à une date. C'est un état ; rien n'est enregistré.

- **Factures prises en compte** : les factures émises, partiellement payées, payées ou créditées, datées au plus tard ce jour. Les brouillons et les factures annulées sont exclus.
- **Reste dû à une date** : le montant TTC, moins les paiements et les crédits de notes de crédit imputés jusqu'à ce jour. Une facture payée depuis compte donc encore comme due à une date antérieure.
- **Crédits** : le crédit des notes de crédit qui n'est ni imputé ni remboursé à cette date est dû au client (§5.8), comme ce qui reste des acomptes ni appliqué ni remboursé (§5.9).
- **Analyse chronologique** :
  - le retard se compte depuis l'échéance, ou depuis la date de facture si la facture n'a pas d'échéance ;
  - tranches : non échu, 1 à 30 jours, 31 à 60, 61 à 90, plus de 90 ;
  - par client : total, tranches, nombre de factures, retard maximal ;
  - les clients sont triés par montant dû, avec les totaux, le montant en retard et le montant à plus de 90 jours ;
  - les cases se colorent selon l'ancienneté ; export CSV ;
  - quand des crédits existent, deux colonnes de plus : les crédits et le solde net (total dû moins crédits), aussi dans l'export.
- **Relevé de compte d'un client** (bouton de la ligne, du 1er janvier à la date choisie par défaut) :
  - solde d'ouverture (mouvements antérieurs) ;
  - au débit : les factures et les remboursements au client ;
  - au crédit : les paiements, les notes de crédit et les acomptes reçus ;
  - un paiement fait avec un acompte n'est pas un mouvement de plus : l'acompte est déjà au crédit ;
  - un escompte accordé avec un paiement est au crédit, après ce paiement ;
  - solde progressif ; le même jour, l'ordre est : facture, note de crédit, acompte, paiement, escompte, remboursement ;
  - solde final ;
  - factures encore dues au dernier jour, avec leur retard ;
  - crédits des notes de crédit et acomptes encore disponibles au dernier jour ;
  - export CSV.

| Route | Rôle |
|---|---|
| `GET /comptes-clients?date=[&format=csv]` | Analyse chronologique (aujourd'hui par défaut) |
| `GET /comptes-clients/{client}/releve?du=&au=[&format=csv]` | Relevé de compte |

Code : `App\Services\ComptesClients`, `App\Http\Controllers\Api\CompteClientController`, écran `ComptesClientsPage`, service `src/services/comptesClients.ts`.

---

## 7 ter. Relances de paiement (Ventes → Relances)

Plan de parité fripv2, phase 1.5 : relancer les clients dont les factures sont en retard. Dans fripv2, on repère les comptes en retard dans les comptes à recevoir pour relancer les clients ; ici, les relances sont faites et suivies dans l'application.

**Factures en retard :**
- ce sont les factures émises ou partiellement payées, avec un reste à payer, dont l'échéance est dépassée (ou la date de facture, sans échéance) ;
- elles sont classées du plus ancien retard au plus récent ;
- l'écran les filtre par retard (plus de 30, 60, 90 jours), par client ou facture, et par « jamais relancées » ;
- cartes : factures en retard, clients, reste dû, jamais relancées.

**Relancer :**
- **Par courriel** : le modèle « relance » (Paramètres → Courriels), avec la facture en PDF joint.
  - Variables : `{numero}`, `{date}`, `{date_echeance}`, `{jours_retard}`, `{niveau}`, `{reste_a_payer}`, en plus de celles du client et de l'entreprise.
  - La relance est notée à l'envoi du courriel, une seule fois par courriel.
- **Noter une relance** faite autrement : téléphone, courrier ou autre, avec date et notes (la réponse du client, par exemple). La date ne peut pas être dans le futur.
- **Niveau** : chaque relance prend le suivant (1re, 2e, 3e…).
- **Historique** : les relances d'une facture, avec leur niveau, date, moyen, auteur ou « automatique », et notes.

**Relances automatiques :** la tâche planifiée `relances_paiement` (Paramètres → Tâches planifiées). Elle est **inactive par défaut** : aucun courriel ne part sans activation.
- Chaque jour à 08:00, elle envoie le courriel de relance pour chaque facture en retard d'au moins **N jours** (15 par défaut) dont la dernière relance date d'au moins N jours.
- Au plus **3 relances automatiques** par facture.
- Seulement aux clients qui ont une adresse de courriel.
- La relance est notée dès la mise en file du courriel, marquée « automatique ».

**Permissions :** module `relances`, avec « Consulter » et « Créer » (relancer). À la mise en place, chaque groupe reçoit :
- **la consultation** de ses droits de consulter les factures ;
- **la relance** de ses droits de modifier les factures.

Pour les rôles système, cela donne :
- administrateur et responsable : consultent et relancent ;
- utilisateur et lecture seule : consultent seulement.

La liste (`GET /relances`) exige le droit de consulter les relances.

| Route | Rôle |
|---|---|
| `GET /relances?retard_min=&jamais=1&recherche=&client_id=&date=` | Factures en retard à une date et totaux |
| `GET /relances/historique?client_id=&facture_id=` | Historique des relances, paginé |
| `GET /factures/{id}/relances` | Relances d'une facture |
| `POST /factures/{id}/relances` | Noter une relance (`mode`, `date`, `notes`) |

**Données :** table `facture_relances` (facture, client, niveau, date, moyen, courriel envoyé, automatique, notes, auteur).

Code :
- API : service `App\Services\Relances`, contrôleur `RelanceController`, tâche `App\Taches\RelancerFacturesEnRetard`, migration `2026_10_16_100033_creer_relances_paiement` ;
- web : écran `RelancesPage`, service `src/services/relances.ts`.

---

## 7 quater. Encaissements (Ventes → Encaissements)

Plan de parité fripv2, phase 4.4 (« Encaissement d'un client ») : l'argent reçu d'un client en une fois (un chèque, un virement…) se répartit sur plusieurs de ses factures.

**Saisir un encaissement** (bouton **Nouvel encaissement**, ou bouton de la ligne d'un client dans Comptes clients) :
- **Client, montant reçu, date, mode** (chèque, virement, espèces, carte, autre) **et référence** (n° de chèque, de virement).
- **Devise** : celle du client par défaut. Seules ses factures dans cette devise sont proposées, avec leur taux de change.
- **Factures dues** : émises ou en partie payées, avec un reste à payer. Elles sont listées l'échéance la plus ancienne d'abord, avec leur retard.
- **Répartition** :
  - à la main, facture par facture, au plus son reste ;
  - case **Tout** : le reste entier de la facture ;
  - bouton **Répartir automatiquement** : le montant reçu va aux factures dans l'ordre, la plus ancienne d'abord.
- **Différence** entre le montant reçu et la répartition :
  - **surplus** : il reste au crédit du client, en **acompte** (§5.9), qu'il pourra appliquer sur une autre facture ou se faire rembourser ;
  - **sans aucune facture**, tout le montant est une **avance** du client ;
  - **répartition supérieure au montant reçu** : refusée, sauf si la différence est **accordée en escompte**. L'escompte se partage entre les factures au prorata de leur part, et les factures sont alors réglées en entier.
- **Crédits du client** : ses acomptes et notes de crédit encore disponibles sont rappelés ; ils s'appliquent depuis leurs écrans.
- **Caisse** : sur un poste de caisse, l'encaissement entre dans la session ouverte (§5.19). La caisse attend les paiements des factures et le surplus.
- **Compte bancaire** (sauf en espèces) : celui par lequel passe l'argent, le compte « par défaut » proposé. La comptabilisation le passe au compte comptable de ce compte bancaire (voir FLOW-COMPTABILITE, Banque).
- **Contrôles** :
  - factures du client, dues, dans la devise de l'encaissement, chacune une seule fois ;
  - pas plus que le reste d'une facture ;
  - pas de date avant celle d'une facture, ni plus tard que demain.
- **Numéro** : `ENC-00001` (Paramètres → Numérotation). Il n'est consommé qu'à l'enregistrement.

**Ce qui est enregistré :**
- Chaque part devient un **paiement de sa facture**, au mode et à la référence de l'encaissement (à défaut, son numéro). Le reste à payer, le statut, les échéances, l'encours, le relevé et la caisse suivent comme pour tout paiement.
- Le surplus devient un acompte du client, avec le numéro de l'encaissement en référence. Il suit l'encaissement : son montant, sa date et son mode ne se changent pas depuis l'écran Acomptes, et il ne s'y supprime pas.

**Détail d'un encaissement** :
- factures réglées (escompte compris) ;
- surplus et ce qui en reste ;
- **reçu** imprimable (PDF) : client, date, mode, référence, factures réglées, escompte, surplus, montant reçu ;
- pièces jointes (chèque numérisé, avis de virement) ;
- **corriger** : la référence et les notes (les paiements des factures suivent la référence). Le reste se corrige en annulant puis en refaisant l'encaissement.

**Annuler un encaissement** (avec un motif) :
- ses paiements sont retirés des factures, qui redeviennent dues d'autant (une facture sans plus aucun paiement redevient « émise ») ;
- son surplus est supprimé ;
- un encaissement dont le surplus a déjà été appliqué ou remboursé ne s'annule plus ;
- l'encaissement reste dans la liste, barré, avec la date, l'auteur et le motif.

**Liste** : recherche par numéro, référence, client ou facture ; filtres mode, statut et période ; totaux des encaissements valides (montant reçu, escompte, surplus) dans la devise de l'entreprise.

**Comptabilité** : la comptabilisation automatique passe un encaissement en **une écriture**. L'argent reçu va en caisse ou en banque, l'escompte au débit ; chaque facture réglée est au crédit du client et le surplus au crédit des acomptes clients. Un encaissement annulé fait contre-passer son écriture (voir FLOW-COMPTABILITE).

**Droits** : module **Encaissements** (`encaissements`), réglé pour chaque groupe comme l'enregistrement des paiements de factures.
- **Consulter** : liste, détail, reçu ; les lectures de `/encaissements` sont contrôlées.
- **Saisir** : créer.
- **Corriger** : modifier.
- **Annuler un encaissement** : `encaissements.annuler`.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/encaissements` | Liste (`recherche`, `client_id`, `mode`, `statut`, `date_debut`, `date_fin`) et `resume` |
| GET | `/encaissements/a-regler?client_id=&devise=` | Factures dues du client dans la devise, total dû, crédits disponibles |
| POST | `/encaissements` | Saisie (`client_id`, `date_encaissement`, `mode`, `reference`, `montant`, `devise`, `taux_change`, `notes`, `escompte`, `caisse_id`, `lignes[]` : `facture_id`, `montant`) |
| GET / PUT | `/encaissements/{id}` | Détail ; correction (`reference`, `notes`) |
| POST | `/encaissements/{id}/annuler` | Annulation (`motif`) |
| GET | `/encaissements/{id}/pdf` | Reçu |

Code : services `App\Services\Encaissements` et `EncaissementPdf`, `EncaissementController`, vue `pdf/recu-encaissement` ; web : page `EncaissementsPage`, composants `encaissements/FenetreEncaissement` et `encaissements/DetailEncaissement`, service `src/services/encaissements.ts` ; migration `2026_10_17_100063_creer_encaissements` (tables `encaissements`, `encaissement_lignes`, colonne `facture_paiements.encaissement_id`, droits du module).

---

## 8. Tournées

### 8.1 Ce que c'est

Une **Tournée** est un regroupement de plusieurs Bons de Livraison pour un même camion un même jour. Elle permet d'organiser la logistique : quel camion livre quoi, dans quel ordre.

### 8.2 Champs d'une tournée

| Champ | Description |
|-------|-------------|
| `numero` | Ex: TRN-2026-0001 |
| `date_tournee` | Date de la tournée |
| `camion_id` | Le camion affecté |
| `zone` | Zone géographique (ex: "Nord", "Centre-ville") |
| `statut` | État actuel |
| `heure_depart` | Heure de départ réelle |
| `heure_retour` | Heure de retour réelle |

**Liaison BL (TourneeBon)**

| Champ | Description |
|-------|-------------|
| `tournee_id` | La tournée |
| `bon_id` | Le BL associé |
| `ordre_livraison` | Ordre de passage chez les clients |
| `statut` | `en_attente`, `livre`, `echec` : à l'enregistrement de la livraison du bon, l'arrêt passe `livre` (quelque chose a été livré) ou `echec` (rien), avec l'heure (`heure_livraison`) |

### 8.3 Statuts d'une tournée

| Statut | Signification | Actions disponibles |
|--------|---------------|---------------------|
| `planifiee` | Créée, pas encore démarrée | Ajouter BL 📦, Retirer BL ➖, Démarrer ▶️, Supprimer 🗑️ |
| `en_cours` | En route | Terminer ⏹ |
| `terminee` | Retour dépôt | Lecture seule |
| `annulee` | Annulée | Lecture seule |

### 8.4 Flow d'une tournée

```
ÉTAPE 1 : CRÉER LA TOURNÉE
  → Endpoint: POST /tournees
  → Sélectionner la date → seuls les camions libres ce jour-là s'affichent
  → Sélectionner le camion
  → (optionnel) Définir la zone géographique
  → Statut: planifiee

ÉTAPE 2 : AJOUTER LES BONS DE LIVRAISON
  → Cliquer sur 📦+ dans la liste des tournées
  → Seuls les BL en statut "pret" s'affichent
  → Cliquer "Ajouter" sur chaque BL à inclure
  → Endpoint: POST /tournees/{id}/ajouter-bon
  → Chaque BL ajouté reçoit un numéro d'ordre automatique

ÉTAPE 3 : DÉMARRER LA TOURNÉE
  → Endpoint: POST /tournees/{id}/demarrer
  → Vérifie qu'il y a au moins 1 BL dans la tournée
  → Statut tournée: en_cours
  → Statut de tous les BL: en_livraison (automatique)
  → Statut de toutes les lignes des BL: charge (automatique)
  → Enregistre l'heure de départ

ÉTAPE 4 : ENREGISTRER LES LIVRAISONS (par BL)
  → Pour chaque BL, depuis BonsLivraisonPage :
    → Endpoint: POST /bons-livraison/{id}/livrer
    → Saisir les quantités livrées
    → Signature client (optionnel)

ÉTAPE 5 : TERMINER LA TOURNÉE
  → Endpoint: POST /tournees/{id}/terminer
  → Statut: terminee
  → Enregistre l'heure de retour
```

### 8.5 Retirer un BL d'une tournée

Le bouton ➖ apparaît sur chaque BL dans le modal détail de la tournée, si celle-ci est en statut `planifiee`.

- **Endpoint :** `DELETE /tournees/{id}/bon/{bonId}`
- Le BL repasse en statut `pret` — il peut être ajouté à une autre tournée
- Impossible si la tournée est déjà `en_cours` ou `terminee`

### 8.6 Supprimer une tournée

Le bouton 🗑️ est disponible dans le tableau des tournées, uniquement pour les tournées en statut `planifiee`.

- **Endpoint :** `DELETE /tournees/{id}`
- Supprime la tournée et tous ses liens avec les BL
- Les BL eux-mêmes ne sont pas affectés — ils restent en statut `pret`
- Impossible si la tournée est `en_cours` ou `terminee`

### 8.7 Réorganiser l'ordre de livraison

`PUT /tournees/{id}/ordre` permet de changer l'ordre des BL dans la tournée (pour optimiser le trajet). Seulement pour les tournées `planifiee`.

### 8.7b Séquences de livraison et feuille de route

Plan de parité fripv2, phase 1.6 (« Séquences des routes ») : comme dans fripv2, un client a une **route** et un **rang de passage** sur cette route. Une route est un itinéraire du référentiel logistique (Paramètres → Référentiels).

**Fiche client :**
- « Route de livraison » et « Rang de passage » (`itineraire_id`, `sequence_livraison`) ;
- 10, 20, 30… laisse de la place pour intercaler un nouveau client.

**Tournée :**
- à la création, une tournée peut **suivre une route** (`itineraire_id`) ;
- **Ajouter les bons de la route** (`POST /tournees/{id}/ajouter-route`) : les bons **prêts**, pas encore en tournée, des clients de cette route, dans leur ordre de passage ;
- **Trier par séquence** (`POST /tournees/{id}/ordonner`) : range les arrêts par rang de passage des clients. Les clients sans rang passent à la fin, dans l'ordre où ils ont été ajoutés ;
- les **flèches** de chaque arrêt le montent ou le descendent (`PUT /tournees/{id}/ordre`) ;
- tout cela tant que la tournée est planifiée, avec le droit « Piloter les tournées ».

**Feuille de route** (`GET /tournees/{id}/feuille-de-route`, PDF, format paysage) : la feuille du livreur, dans l'ordre de passage. Pour chaque arrêt :
- bon et facture ;
- client, avec son rang ;
- adresse : celle de livraison principale de la fiche client, avec ses instructions, sinon l'adresse du client ; pour un client de passage, celle notée sur sa facture ;
- téléphone et contact ;
- quantités ;
- des cases « Reçu par / signature » et « Remarques ».

En pied de feuille : départ et retour (heure, kilomètres) et signature du livreur.

Code : services `App\Services\SequencesLivraison` et `App\Services\FeuilleDeRoutePdf`, vue `pdf/feuille-de-route`, migration `2026_10_16_100045_sequences_livraison`.

### 8.8 Charge du camion et matières dangereuses

Plan de parité fripv2, phase 1.6 : le « rapport de dangerosité » d'un camion, sur les tournées.

**Charge estimée :**
- le détail d'une tournée (`GET /tournees/{id}`) donne `poids_total_kg`, le poids des produits encore à livrer de ses bons (quantité préparée, sinon prévue, moins livrée, multipliée par le poids unitaire du produit) ;
- l'écran l'affiche avec la capacité du camion, en rouge au-delà ;
- un produit sans poids compte pour zéro.

**Matières dangereuses :**
- un produit est une matière dangereuse quand il a un **numéro ONU** ou une **classe de danger** (fiche produit, FLOW-PARAMETRES §2.2) ;
- le détail de la tournée donne `matieres_dangereuses`, le nombre de lignes dangereuses encore à livrer.

**Document de transport** (`GET /tournees/{id}/matieres-dangereuses`, PDF) : le bouton « Document matières dangereuses » du détail de la tournée le produit. Il est à remettre au conducteur.

Il contient :
- l'entreprise (expéditeur), le téléphone d'urgence (bandeau), la date, le véhicule, la zone et les classes à bord ;
- une ligne par produit dangereux encore à livrer, dans l'ordre de livraison : N° ONU, désignation, classe, groupe d'emballage, bon et client, quantité, masse brute (quantité × poids unitaire) ;
- la masse brute totale, signalée incomplète si un produit n'a pas de poids ;
- la déclaration de l'expéditeur (celle de l'entreprise, sinon une déclaration générale) et les signatures de l'expéditeur et du conducteur.

Sans matière dangereuse à bord, la réponse est 422. Le document ne bloque rien et ne contrôle aucune quantité limite : comme dans fripv2, c'est un document, pas une règle.

Le téléphone d'urgence et la déclaration se règlent dans Paramètres → Entreprise, selon la réglementation du pays (au Canada, par exemple, le numéro de CANUTEC).

Code : service `App\Services\MatieresDangereusesPdf`, vue `pdf/matieres-dangereuses`, migration `2026_10_16_100044_matieres_dangereuses`.

### 8.9 Chargement du camion

Plan de parité fripv2, phase 1.6 (« Chargement ») : avant le départ, on coche ce qui monte dans le camion.

**Où :** le bouton **Chargement** d'une tournée planifiée (liste des tournées) ou du détail d'une tournée ouvre la fenêtre de chargement. Le bouton du détail donne l'avancement (« 3 / 4 chargé(s) »).

**Ordre de chargement :** les arrêts s'affichent dans l'ordre inverse des livraisons. Le dernier arrêt part au fond du camion, le premier reste près des portes.

**Ce qu'on coche, arrêt par arrêt :**
- chaque **colis** du bon (§6.3d) ;
- le **vrac** : la marchandise du bon qui n'est dans aucun colis (quantité préparée, sinon à livrer, moins ce qui est en colis). Il se coche d'un bloc par arrêt. Un bon sans colis n'a que du vrac.

Chaque pointage garde qui l'a fait et quand.

**Scanner :** le champ **Numéro du colis** reçoit ce que lit la douchette sur l'étiquette (QR code du numéro), ou une saisie suivie d'Entrée. Le colis est coché, et le champ se vide et reste actif pour le scan suivant. Les réponses possibles :
- « Colis BL-00013-001 chargé. » ;
- « … déjà chargé. » (le premier pointage est gardé) ;
- « Colis inconnu : … » ;
- « Le colis … n'est pas dans cette tournée. »

**Fiche de chargement** (`GET /tournees/{id}/chargement/pdf`) : les arrêts dans l'ordre de chargement, colis par colis puis le vrac. Pour chacun : contenu, poids et une case à cocher (✔ et le nom de qui a chargé, si c'est déjà fait). En pied de fiche : le total des colis et de leur poids, et les lignes « Chargé par » et « Contrôlé par le chauffeur ».

**Départ :** **Démarrer** ne dépend pas du chargement (l'API ne change pas). L'écran prévient seulement si le chargement n'est pas fini (« … élément(s) ne sont pas cochés comme chargés. Démarrer quand même ? »). Il ne prévient que si le chargement a commencé ou si la tournée a des colis : une entreprise qui ne s'en sert pas n'est pas gênée.

**Règles :**
- On ne coche qu'avant le départ (tournée `planifiee`), sinon 422 « Le chargement se coche avant le départ de la tournée. » Ensuite, la fenêtre est en lecture seule.
- Un colis coché comme chargé ne se modifie plus et ne se retire plus (422) : on le décoche d'abord.
- Un bon retiré de la tournée, ou une tournée supprimée, décharge ses colis.
- Le stock n'est pas touché : le mouvement « chargement camion » reste celui du départ de la tournée.

**Droits :** cocher et scanner demandent **Tournées → Charger un camion** (`tournees.charger`, accordé avec la modification des tournées). Consulter le chargement et imprimer la fiche suivent la lecture des tournées.

| Méthode | Route | Rôle |
|---------|-------|------|
| GET | `/api/tournees/{id}/chargement` | Arrêts dans l'ordre de chargement, colis, vrac, décompte (`resume`) |
| GET | `/api/tournees/{id}/chargement/pdf` | Fiche de chargement |
| POST | `/api/tournees/{id}/chargement/scan` | Colis scanné (`numero`) : coché comme chargé |
| POST | `/api/tournees/{id}/chargement/colis/{colisId}` | Cocher ou décocher un colis (`charge`) |
| POST | `/api/tournees/{id}/chargement/vrac/{bonId}` | Cocher ou décocher le vrac d'un arrêt (`charge`) |

Le détail de la tournée (`GET /tournees/{id}`) donne aussi le décompte, dans `chargement` : colis et lots de vrac, cochés ou non, `reste` et `commence`.

Code : services `ChargementsTournee` et `FicheChargementPdf`, `ChargementTourneeController`, vue `pdf/fiche-chargement`, composant `livraison/ChargementTournee` ; migration `2026_10_17_100047_chargement_camion` (`tournee_bons.vrac_charge_le`, `vrac_charge_par` ; les colis utilisent `bl_colis.charge_le`, `charge_par`).

---

## 9. Flow complet bout en bout

```
┌─────────────────────────────────────────────────────────────────┐
│                    MODULE VENTES — FLOW COMPLET                  │
└─────────────────────────────────────────────────────────────────┘

═══ PHASE COMMERCIALE ════════════════════════════════════════════

1. CLIENT
   → Créer la fiche client (une seule fois)
   → Définir son plafond de crédit si nécessaire

2. DEVIS (optionnel)
   → Créer le devis avec les produits et prix
   → Envoyer → Client accepte ou refuse
   → Si accepté → Convertir en commande

3. COMMANDE CLIENT
   → Créer ou récupérer depuis devis converti
   → Soumettre pour validation
   → Accepter

═══ PHASE FACTURATION ════════════════════════════════════════════

4. FACTURE
   → Créer depuis la commande acceptée
   → Émettre la facture

═══ PHASE LOGISTIQUE ═════════════════════════════════════════════

5. BON DE LIVRAISON
   → Créer depuis la facture émise (automatique) OU créer depuis une facture manuelle
   → Démarrer la préparation dans l'entrepôt
   → Préparer les articles (saisir quantités)
   → Marquer prêt
   ↳ Si erreur ou annulation client → Annuler le BL 🚫 (statuts cree ou en_preparation)

6. TOURNÉE
   → Créer la tournée pour la date de livraison
   → Affecter un camion disponible
   → Ajouter le(s) BL à la tournée
   ↳ Si ajout par erreur → Retirer le BL ➖
   ↳ Si tournée à annuler → Supprimer la tournée 🗑️
   → Démarrer la tournée

═══ PHASE LIVRAISON ══════════════════════════════════════════════

7. LIVRAISON
   → Livrer au client
   → Enregistrer les quantités livrées
   → Faire signer le client
   → Terminer la tournée

═══ PHASE PAIEMENT ═══════════════════════════════════════════════

8. PAIEMENT
   → Recevoir le paiement du client
   → Enregistrer le paiement dans la facture
   → Facture passe en "payée"
   → Encours client se met à jour
```

---

## 10. Tous les statuts et transitions

### Devis
```
brouillon → envoye → accepte → [converti en commande]
                   ↘ refuse
(expire si date_validite dépassée)
```

### Commande Client
```
brouillon → soumise → acceptee → [créer facture]
                    ↘ refusee
```

### Facture
```
brouillon → emise → partiellement_payee → payee
                  ↘ (créer BL)
```

### Bon de Livraison
```
cree → en_preparation → pret → en_livraison → livre_complet
                                             ↘ livre_partiel → [nouvelle facture reliquat]
```

### Tournée
```
planifiee → en_cours → terminee
```

---

## 11. Livraison partielle — cas particulier

### Ce qui se passe

Quand un livreur arrive chez le client mais ne peut livrer qu'une partie des articles (rupture de stock, client absent pour une partie, refus client...) :

1. Le livreur saisit les quantités réellement livrées (< quantités prévues)
2. Le BL passe en `livre_partiel` ; la facture, qui couvre déjà toute la quantité, ne change pas et rien n'est refacturé
3. Pour livrer le reste : écran Factures, « Livrer le reste » → un nouveau bon reprend les seules quantités non livrées
   et suit le circuit normal (préparation → nouveau transport → livraison)
4. Si le reste ne sera pas livré : une note de crédit sur la facture pour les quantités non livrées

### Mouvements de stock générés

| Étape | Type de mouvement |
|-------|------------------|
| Préparation articles | `sortie_preparation` (secteur → zone préparation) |
| Livraison au client | `livraison_client` (camion → client) |

---

## 13. Documents PDF

Chaque document de vente a son PDF, ouvert par le bouton **PDF** de sa ligne dans la liste :

| Document | Route | Contenu propre |
|---|---|---|
| Devis | `GET /devis/{id}/pdf` | Total HT, mention « Montants hors taxes », zone « Bon pour accord » |
| Commande client | `GET /commandes-client/{id}/pdf` | Sous-total HT, taxes, total TTC |
| Facture | `GET /factures/{id}/pdf` | Sous-total HT, taxes, total TTC, payé, reste à payer |
| Note de crédit | `GET /avoirs/{id}/pdf` | Facture créditée, motif, taxes au prorata de la facture, imputé, remboursé, crédit disponible |
| Bon de livraison | `GET /bons-livraison/{id}/pdf` | Adresse de livraison, quantités à livrer / préparées / livrées, signatures |

**Communs à tous :**
- En-tête et pied de page tirés des paramètres de l'entreprise (Configuration → Entreprise) : nom, adresse, téléphone, courriel, numéros d'entreprise et de taxe. Sans paramètres, le nom de l'entreprise (tenant) est utilisé.
- Montants dans la devise de l'entreprise (`devise_symbole`).
- Taxes : une ligne par taxe quand le document utilise des groupes de taxes (ex. TVA 17,5 % puis CAC 10 %), sinon une ligne « TVA » avec son taux s'il est unique.
- Gabarit unique `resources/views/pdf/document-vente.blade.php`, alimenté par `App\Services\DocumentsVentePdf`. Police en sous-ensemble : environ 25 Ko par document.

**Envoi par courriel :** le bouton à côté du PDF envoie le document avec son PDF joint (voir FLOW-PARAMETRES §12.3).

**Accès :** comme le reste de l'API (jeton dans l'en-tête `Authorization`, entreprise par `X-Tenant-Slug`), donc jamais de jeton dans l'URL. Un document d'une autre entreprise renvoie 404. Le PDF existant des bons de commande fournisseur n'est pas modifié.

---

## 14. Écrans de vente : règles d'affichage communes

- **Devise** : les montants (devis, commandes, factures, clients, comptabilité) s'affichent avec le symbole de
  devise des paramètres de l'entreprise, au format de la langue (« 1 234,50 FCFA »), et non en euros d'office.
- **Dates** : une date de document (`date_facture`, `date_commande`…) est lue telle quelle, sans passer par un
  instant UTC qui la décalait d'un jour à l'ouest de Greenwich. Une date du jour proposée par défaut est celle du
  fuseau de l'utilisateur.
- **Listes paginées** : devis, commandes, factures, bons de livraison, tournées, clients et fournisseurs affichent
  le nombre total et passent de page en page. Un changement de filtre revient à la première page.
- **Lignes** : le détail d'une facture ou d'un bon de livraison donne la désignation du produit (même retiré du
  catalogue) ; une ligne nouvelle reprend le prix du catalogue tant qu'aucun prix n'est saisi.
- **Facture** : la liste donne le montant TTC (comme le reste à payer) ; le détail donne HT, TVA (ou le détail
  par taxe) et TTC.
- **Erreurs** : le message renvoyé par l'API (encours dépassé, statut incompatible, champ invalide) est affiché
  au lieu d'un échec silencieux.
- **Tournées** : la liste « Ajouter un bon » ne propose que les bons prêts et pas encore placés
  (`GET /bons-livraison?statut=pret&sans_tournee=1`). Ajouter, retirer, démarrer ou terminer renvoie la tournée
  complète (camion, bons, factures, clients).

---

## 15. Unités de vente (Paramètres → Produits → Unités)

Plan de parité fripv2, phase 2.2 : un produit se compte dans son **unité de stock** (sa mesure, par exemple UN), et se
vend aussi dans d'autres unités : la caisse de 12, le carton, la palette.

**Les unités d'un produit** (bouton **Unités** sur chaque ligne de la liste des produits) :
- une unité vient du référentiel des unités (Paramètres → Référentiels) ; l'unité de stock vaut toujours 1 ;
- **facteur** : le nombre d'unités de stock dans une unité (12 pour la caisse de 12). Il est proposé d'après les
  conversions du référentiel, dans les deux sens et enchaînées (palette → caisse → unité). Sans conversion, on le saisit ;
- **prix propre**, au besoin : sinon, le prix d'une unité est celui du produit multiplié par le facteur ;
- **une unité proposée** d'office sur les lignes de vente (sinon l'unité de stock).

**Sur les documents** (devis, commandes, factures) :
- le choix de l'unité n'apparaît que pour un produit qui a des unités de vente : les autres lignes ne changent pas ;
- la ligne garde le **code de son unité et son facteur**, figés à la saisie : changer ensuite les unités du produit ne
  change aucun document ;
- **prix proposé** : celui de l'unité, puis le calcul du client (liste de prix, remise, règles). Les prix de l'unité
  de stock sont multipliés par le facteur ; la quantité minimale d'une règle se compte en unités de stock (2 caisses
  de 12 atteignent un seuil de 20) ; le prix propre de l'unité remplace le prix de base ;
- l'unité suit le document : devis → commande → facture → bon de livraison, note de crédit, retour client, reliquat.

**En unités de stock** (une caisse de 12 compte pour 12) :
- le **réservé** et les **reliquats** des disponibilités ;
- les **manques** signalés à l'acceptation d'une commande (proposés ensuite dans l'unité de la ligne) ;
- la **remise en stock** d'un retour client (le mouvement d'arrivée) ;
- la **masse** des matières dangereuses, et le journal des réservations, préparations, chargements et livraisons ;
- un **reliquat** reste dans l'unité de la ligne commandée, à son prix :
  - il est commandé au fournisseur en unités de stock ;
  - il ne reçoit que des unités entières : 18 unités reçues font 1 caisse, et les 6 autres attendent la réception suivante ;
  - il se facture dans son unité.

**Limite** : un retour client dans une autre unité ne se relie pas à un retour fournisseur. Ce dernier se compte en
unités de stock et se saisit sans lien. Les unités d'achat de chaque fournisseur sont décrites dans FLOW-ACHATS (section 14).

**PDF** : dès qu'une ligne du document est dans une unité de vente, chaque quantité dit son unité (« 2 CS », « 5 UN ») ;
sinon le document ne change pas.

**Droits** : ceux des produits. Consulter les unités demande le droit de voir les produits, les ajouter, les changer ou
les retirer le droit de les modifier.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/produits/{id}/unites` | Unité de stock, unités de vente, unités du référentiel qu'il peut prendre (facteur proposé) |
| POST / PUT / DELETE | `/produits/{id}/unites[/{unite}]` | Ajouter (`unite_id`, `facteur` facultatif, `prix`, `par_defaut`), modifier, retirer |
| POST | `/prix/calculer` | Accepte `lignes.*.unite` : prix de l'unité, avec `unite` et `facteur` dans la réponse |

Les lignes des devis, commandes et factures acceptent `lignes.*.unite` : vide ou égale à la mesure du produit, c'est
l'unité de stock ; sinon une unité de vente du produit, refusée si ce n'en est pas une.

Code :
- `App\Support\ConversionsUnites` (facteur entre deux unités) ;
- service `App\Services\UnitesProduits`, `ProduitUniteController`, modèle `ProduitUnite`, trait
  `App\Models\Concerns\AUneUnite` des lignes ;
- web : `produits/FenetreUnitesProduit`, `ChoixUnite`, `lib/unitesVente.ts`, `services/unitesProduits.ts` ;
- migration `2026_10_17_100073_creer_unites_produits` : table `produit_unites`, colonnes `unite` et `facteur` des lignes
  de vente et des reliquats.
