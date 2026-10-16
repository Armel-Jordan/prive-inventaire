# PARAMÈTRES — Flow complet & détail de chaque étape

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-15 — référentiels ; courriels : serveur d'envoi, modèles, envoi des documents et historique

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
11. [Référentiels](#11-référentiels)
12. [Configuration — Courriels](#12-configuration--courriels)
13. [Ordre recommandé de configuration initiale](#13-ordre-recommandé-de-configuration-initiale)

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
├── Configuration     ← numérotation, entreprise, TVA, devise, délais, alertes
└── Référentiels      ← unités, catégories, paiement, devises, entrepôts, territoires, transporteurs…
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

Chaque compte a un **rôle de base** : `admin`, `manager` ou `user`.
- Ce rôle fixe ce que l'API autorise : par exemple, seule une route `role:admin` est accessible à un admin.
- Le menu suit les **permissions par module** (voir, créer, modifier, supprimer) du rôle système du même nom, servies par `GET /permissions/me`.
- Un administrateur voit tout.

### 6.2 Rôles système et rôles personnalisés

L'écran « Rôles » (`GET/POST/PUT/DELETE /roles-custom/{id}`, réservé aux administrateurs) distingue deux sortes de rôles.

**Rôles système** (`admin`, `manager`, `user`, `readonly`) :
- Ils sont communs à toutes les entreprises.
- On peut consulter leurs permissions, mais pas les modifier : `PUT`/`DELETE` répond 403.
- Le bouton « Dupliquer » crée une copie modifiable.

**Rôles personnalisés** :
- Ils appartiennent à l'entreprise qui les crée et restent invisibles des autres (404).
- Leur nom est unique dans l'entreprise. Les noms des rôles système sont réservés, sans tenir compte des majuscules.

**Exemple :** dupliquer `user` en « Préparateur BL », puis ne garder que la consultation des bons de livraison.

### 6.3 Accès à l'application (fiche employé)

L'accès se règle dans **Employés → Accès à l'application** : rôle de base et mot de passe.
- La création, la modification et la suppression d'employés sont réservées aux rôles admin et manager. Tous les comptes peuvent lire la liste, qui alimente des listes de choix.
- Un **administrateur** attribue tous les rôles.
- Un **manager** n'attribue que le rôle `user`. Il ne touche pas aux comptes administrateur ou manager : ni leur rôle, ni leur mot de passe, ni leur adresse, qui sert à réinitialiser le mot de passe.
- Le **dernier administrateur actif** de l'entreprise ne peut être ni rétrogradé, ni désactivé, ni privé d'accès (422), y compris via `PUT /users/{id}`.

`POST /users/{userId}/assign-role` vise l'ancienne table `users` : un rôle personnalisé ne s'attribue pas encore à un compte.

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
| `taux` | Taux en %, jusqu'à 4 décimales (ex: 20, 9.975) |
| `defaut` | Si true, appliqué par défaut sur les lignes |

**Endpoints :** `GET/POST/PUT/DELETE /taxes`

**Exemple :** Une entreprise au Canada peut avoir TVA + TPS + TVQ comme trois taxes distinctes.

Une taxe utilisée dans un groupe de taxes (§9.4) ne peut pas être supprimée.

### 9.3 Comment les taxes s'appliquent

Sur chaque ligne de commande/facture :
```
montant_ht = quantite × prix_unitaire_ht × (1 - remise_ligne/100)
montant_tva = montant_ht × (taux_tva/100)
montant_ttc = montant_ht + montant_tva
```

### 9.4 Groupes de taxes

Un groupe combine, dans un ordre de calcul, des taxes déclarées au §9.2. Chaque taxe du groupe dit à quoi s'applique son taux :

| `applique_sur` | Base de calcul | Exemple |
|---|---|---|
| `base` | Le montant HT | TPS 5 % + TVQ 9,975 % (Québec) |
| `base_et_taxes_precedentes` | Le HT + les taxes déjà calculées (taxe composée) | Ancienne TVQ, calculée sur HT + TPS |
| `taxe_precedente` | Le montant de la taxe précédente (taxe sur la taxe) | CAC 10 % de la TVA (Cameroun) |

Chaque taxe est arrondie au centime. Le **taux effectif** du groupe est le taux global équivalent : TVA 17,5 % + CAC 10 % de la TVA = **19,25 %**. Un groupe sans taxe sert aux ventes exonérées. Un seul groupe peut être « par défaut ».

**Endpoints :** `GET/POST /groupes-taxes`, `PUT/DELETE /groupes-taxes/{id}`, `POST /groupes-taxes/{id}/calculer` (`montant_ht` → détail des taxes, total TTC).

L'écran se trouve dans **Configuration → Devise & TVA**, sous les taxes, avec un simulateur sur un montant.

### 9.5 Groupes de taxes dans les documents

Les lignes de **commande client** et de **facture** utilisent un groupe de taxes :

| Cas | Calcul |
|---|---|
| La ligne reçoit un `groupe_taxe_id` | Taxes du groupe |
| Pas de groupe ni de taux saisi, et un groupe **par défaut** existe | Taxes du groupe par défaut |
| Un `taux_tva` est saisi, ou aucun groupe par défaut n'existe | Calcul du §9.3, inchangé |

Avec un groupe, la ligne garde son `taux_tva` (taux effectif, pour information) et son total TTC = HT + somme des taxes, chacune arrondie au centime. Le détail de chaque taxe est enregistré dans `document_taxes`, en instantané (nom, taux, base, montant) : un document ne change pas si une taxe est modifiée ensuite. Une facture créée depuis une commande reprend ce détail.

Les fiches commande et facture (`GET /commandes-client/{id}`, `GET /factures/{id}`) renvoient `recapitulatif_taxes` : base et montant cumulés par taxe, affichés sous les totaux. La liste est vide pour le calcul au taux unique.

> Les écrans de saisie n'envoient pas de taux : définir un groupe **par défaut** suffit pour que toutes les nouvelles commandes et factures l'utilisent.

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

## 11. Référentiels

### 11.1 Ce que c'est

Listes de référence réutilisées par toute l'application, gérées dans **Paramètres → Référentiels** (un onglet par liste). Elles sont propres à chaque entreprise (`tenant_id`) et ne modifient aucun écran existant : les fiches (produits, documents, paiements) s'y brancheront au fil du [plan de parité avec fripv2](../16-PLAN-PARITE-FRIPV2.md).

| Onglet | Contenu | Règles |
|---|---|---|
| Unités | Unités de mesure : code, libellé, symbole, décimales | Code unique |
| Conversions | 1 unité source = facteur × unité cible | Deux unités différentes de l'entreprise, facteur > 0, pas de doublon. Une unité utilisée dans une conversion ne peut pas être supprimée |
| Catégories | Classement hiérarchique des produits | Parent de la même entreprise, pas de boucle, nom unique entre catégories sœurs. Une catégorie qui a des sous-catégories ne peut pas être supprimée |
| Fabricants | Fabricants et marques | Nom et code uniques |
| Modes de paiement | Espèces, chèque, virement, carte, mobile money, prélèvement, autre | Code unique |
| Conditions de paiement | Délai en jours, fin de mois, escompte pour paiement anticipé | Une seule condition « par défaut » |
| Comptes bancaires | Comptes de l'entreprise : banque, IBAN, BIC, devise | IBAN, BIC et devise mis en majuscules. Un seul compte « par défaut » |
| Devises | Code ISO, symbole, décimales, taux | Taux = valeur d'une unité dans la devise de référence (§9.1). Chaque nouveau taux est historisé |
| Entrepôts | Lieux de stockage : entrepôt, magasin, véhicule, dépôt, avec adresse et responsable | Code unique, un seul entrepôt « par défaut ». Les secteurs y seront rattachés avec la fonctionnalité multi-entrepôts |
| Transporteurs | Coordonnées, numéro de compte, lien de suivi des colis | Le lien peut contenir `{numero}`, remplacé par le numéro du colis ; il doit commencer par http:// ou https:// |
| Itinéraires | Itinéraires réguliers de livraison ou de visite, rattachables à un territoire | Code unique, territoire de la même entreprise |
| Territoires | Zones commerciales ou de livraison | Un territoire utilisé par un itinéraire ou un représentant ne peut pas être supprimé |
| Représentants | Représentants commerciaux : coordonnées, commission (%), territoire | Commission entre 0 et 100 % |
| Raisons | Raisons normalisées par type : mouvement, ajustement, retour client, retour fournisseur, annulation, refus, autre | Code unique par type (le même code peut servir à deux types) |
| Statuts personnalisés | Statuts de suivi propres à l'entreprise, par type de document, avec couleur et ordre d'affichage | Code unique par type de document, couleur au format `#RRGGBB` |

Les motifs saisis aujourd'hui en texte libre (mouvements, refus de commande) ne changent pas : les écrans proposeront ces raisons au fil des fonctionnalités.

Dans l'écran, les référentiels sont groupés par famille : Produits, Paiement et finance, Logistique, Commercial, Suivi des opérations.

Chaque fiche peut être désactivée (`actif`) plutôt que supprimée, pour garder l'historique.

### 11.2 API

Une seule API générique :

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/api/referentiels/{type}` | Liste (`?actif=1` : fiches actives seulement) |
| POST | `/api/referentiels/{type}` | Création |
| PUT | `/api/referentiels/{type}/{id}` | Modification |
| DELETE | `/api/referentiels/{type}/{id}` | Suppression (refusée si la fiche est utilisée) |
| GET | `/api/referentiels/devises/{id}/historique` | Historique des taux d'une devise |

`{type}` : `unites`, `unite_conversions`, `categories`, `fabricants`, `modes_paiement`, `conditions_paiement`, `comptes_bancaires`, `devises`, `entrepots`, `transporteurs`, `itineraires`, `territoires`, `representants`, `raisons`, `statuts_personnalises`. Les types et leurs règles sont déclarés dans `App\Support\Referentiels` : ajouter un référentiel = une entrée dans ce catalogue, avec son modèle et sa table.

### 11.3 Droits

Module de permission `referentiels` : l'admin a tous les droits, le manager peut consulter, créer et modifier. Les autres rôles s'ouvrent depuis l'écran des rôles.

---

## 12. Configuration — Courriels

Onglet **Courriels** de la page Paramètres, envoi des documents par courriel et historique (plan de parité fripv2, phase 0.6).

### 12.1 Serveur d'envoi

| Mode | Expéditeur | Usage |
|---|---|---|
| Serveur de la plateforme (par défaut) | Adresse de la plateforme (`MAIL_FROM_ADDRESS`), au nom de l'entreprise | Rien à configurer ; les réponses vont à l'adresse de réponse |
| SMTP de l'entreprise | Adresse d'expédition saisie | Envoyer depuis sa propre messagerie (hébergeur, Gmail, Outlook…) |

- SMTP : serveur, port (587 par défaut, 465 en SSL/TLS), chiffrement (STARTTLS exigé, SSL/TLS ou aucun), utilisateur, mot de passe.
- Le mot de passe est chiffré en base avec la clé de l'application et n'est jamais renvoyé par l'API. Laissé vide à l'enregistrement, il est conservé.
- Communs aux deux modes : nom de l'expéditeur (par défaut, le nom de l'entreprise), adresse de réponse (par défaut, le courriel de l'entreprise), copie cachée systématique pour l'archivage, signature ajoutée sous chaque courriel.
- **Tester l'envoi** : envoie un courriel de test avec les paramètres enregistrés. En cas d'échec, le message d'erreur du serveur est affiché.
- Modifier ou tester le serveur est réservé aux administrateurs ; le test est limité à 5 par minute.

### 12.2 Modèles de courriel

Un modèle par **type d'envoi** et par **langue** (fr, en). Sans modèle personnalisé, un modèle par défaut neutre s'applique.

| Type | Document | Pièce jointe par défaut |
|---|---|---|
| `devis` | Devis | `devis-{numero}.pdf` |
| `commande_client` | Commande client | `commande-{numero}.pdf` |
| `facture` | Facture | `facture-{numero}.pdf` |
| `bon_livraison` | Bon de livraison | `bon-livraison-{numero}.pdf` |
| `commande_fournisseur` | Commande fournisseur | `bon-commande-{numero}.pdf` |
| `general` | Courriel libre | — |

- Champs : objet, contenu, nom du PDF joint (facultatif, variables admises), adresse de réponse propre au modèle, accusé de lecture.
- **Variables** `{nom}` : celles du type (`{numero}`, `{client_nom}`, `{montant_ttc}`, `{date_echeance}`…) et celles de l'entreprise (`{entreprise_nom}`, `{entreprise_adresse}`…), insérées d'un clic. Montants écrits selon la langue, avec des espaces insécables, dans la devise de l'entreprise (ou celle du fournisseur pour une commande fournisseur).
- **Mise en forme** légère : `**gras**`, `*italique*`, listes. Le HTML saisi n'est jamais interprété et les valeurs insérées sont échappées.
- **Aperçu** : rendu avec le document le plus récent du type, ou des valeurs d'exemple ; les variables inconnues sont signalées et surlignées.
- Le type et la langue d'un modèle ne changent plus après sa création. « Revenir au modèle par défaut » supprime le modèle personnalisé.

| Route | Rôle |
|---|---|
| `GET /courriels/catalogue` | Types, variables de chaque type, modèles par défaut |
| `GET` / `PUT /courriels/parametres` | Paramètres d'envoi (modification : administrateur) |
| `POST /courriels/parametres/test` | Courriel de test (administrateur) |
| `GET` / `POST /courriels/modeles` | Liste, création |
| `PUT` / `DELETE /courriels/modeles/{id}` | Modification, suppression |
| `POST /courriels/modeles/apercu` | Aperçu d'un modèle, même non enregistré |

Code : catalogue `App\Support\Courriels`, rendu `App\Services\Courriels\RenduCourriel` (vue `emails.courriel`), serveur `App\Services\Courriels\MailerTenant`.

### 12.3 Envoi des documents, file d'envoi et historique

Les listes des devis, commandes client, factures, bons de livraison et commandes fournisseur ont un bouton **Envoyer par courriel** :

1. La fenêtre se pré-remplit avec le modèle de l'entreprise (ou le modèle par défaut) dans la langue choisie : destinataire (courriel du client, ou du fournisseur pour une commande fournisseur), objet et texte complétés. Tout reste modifiable ; Cc et Cci sont facultatifs.
2. Le PDF du document est joint sous le nom défini par le modèle (case à décocher pour ne pas le joindre).
3. **Envoyer** : le courriel est enregistré dans la file (`courriel_envois`) tel qu'il partira, signature et coordonnées comprises, avec la copie cachée systématique des paramètres. Il est ensuite envoyé par le job `EnvoyerCourriel` : tout de suite avec `QUEUE_CONNECTION=sync` (cas actuel), en arrière-plan avec une vraie file.

| Statut | Signification |
|---|---|
| `en_attente` | Enregistré, pas encore envoyé |
| `en_cours` | Envoi en cours ; la prise en charge est atomique, un même courriel ne part jamais deux fois |
| `envoye` | Remis au serveur d'envoi |
| `echec` | Refusé ou serveur injoignable ; l'erreur est conservée |

- **Relance manuelle** : bouton **Réessayer**, dans la fenêtre d'envoi et dans l'historique.
- **Relance automatique** : commande `courriels:relancer`, planifiée toutes les 5 minutes. Elle envoie les courriels en attente depuis plus de 5 minutes, relance les échecs (3 tentatives automatiques au plus, espacées de 15 minutes) et libère les envois interrompus. Elle ne tourne que si le planificateur est lancé (`php artisan schedule:work`, ou `schedule:run` chaque minute par cron).
- Le PDF est régénéré à chaque tentative, depuis l'état actuel du document.
- **Historique** : page **Paramètres → Courriels envoyés** (filtres par statut et par type, détail du courriel tel qu'il est parti), et envois précédents listés dans la fenêtre d'envoi de chaque document.
- Le module du document (ventes, achats) doit être actif ; un document d'une autre entreprise est introuvable. Envois et relances sont limités à 30 par minute.

| Route | Rôle |
|---|---|
| `GET /courriels/preparer?type=&document_id=&langue=` | Pré-remplissage de la fenêtre d'envoi |
| `POST /courriels/envois` | Mise en file et envoi |
| `GET /courriels/envois` | Historique paginé (filtres `type`, `document_id`, `statut`) |
| `GET /courriels/envois/{id}` | Détail, corps du courriel compris |
| `POST /courriels/envois/{id}/reessayer` | Relance d'un courriel en échec |

Code : `App\Services\Courriels\EnvoiCourriel`, `App\Jobs\EnvoyerCourriel`, `App\Console\Commands\RelancerCourriels`.

---

## 13. Ordre recommandé de configuration initiale

Lors de la mise en place de l'application pour une nouvelle entreprise, voici l'ordre optimal :

```
Étape 1 → Configuration Entreprise (nom, adresse, SIRET...)
Étape 2 → Configuration Devise & TVA (quelle monnaie, quels taux)
Étape 3 → Configuration Numérotation (format des numéros de documents)
Étape 4 → Configuration Délais & Alertes, puis Courriels (facultatif : serveur d'envoi et modèles)
Étape 5 → Référentiels (unités, catégories, devises, modes et conditions de paiement…)
Étape 6 → Créer les Secteurs de l'entrepôt
Étape 7 → Créer les Produits du catalogue
Étape 8 → Créer les Employés et leurs rôles
Étape 9 → Commencer les Achats / Ventes
```

> Si on commence les Ventes sans avoir configuré la numérotation, les numéros seront générés avec les valeurs par défaut (ce qui peut ne pas correspondre à la charte de l'entreprise).
