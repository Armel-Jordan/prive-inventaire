# Reste à faire — parité avec fripv2 et finitions

> **État au 19 octobre 2026**, après la fusion de la PR #131 (représentants commerciaux). Les PR #7 à #131 sont
> fusionnées et déployées ; il ne reste aucune PR ouverte.
>
> Cette liste sert à reprendre le travail **sans accès à la documentation de fripv2**. Chaque fonction manquante est
> décrite par le comportement de fripv2 (champs, règles, boutons, droits), puis par une proposition de mise en œuvre
> dans Prise Inventaire (PI). Elle complète [16-PLAN-PARITE-FRIPV2.md](./16-PLAN-PARITE-FRIPV2.md), le plan par phases,
> et les documents `docs/flows/FLOW-*.md`, qui décrivent ce qui est fait écran par écran.

Ce fichier donne la vue d'ensemble :
- l'installation ;
- les règles à garder ;
- le travail en cours ;
- les anomalies connues ;
- l'ordre conseillé.

Le détail, écran par écran, est dans six fichiers du dossier [reste-a-faire/](./reste-a-faire/) :

| Fichier | Périmètre |
|---|---|
| [01-gestion-configuration-usagers.md](./reste-a-faire/01-gestion-configuration-usagers.md) | Onglet Gestion, boutons Configuration et Usagers (36 options) |
| [02-base.md](./reste-a-faire/02-base.md) | Onglet Gestion, bouton Base : référentiels (28 options) |
| [03-inventaire-ventes-achats.md](./reste-a-faire/03-inventaire-ventes-achats.md) | Onglets Inventaire, Vente et Achats (29 écrans), avec les socles communs |
| [04-comptabilite.md](./reste-a-faire/04-comptabilite.md) | Onglet Comptabilité (29 options), comptabilisation automatique |
| [05-rapports.md](./reste-a-faire/05-rapports.md) | Les 61 rapports de fripv2 et la bibliothèque de rapports de PI |
| [06-plateforme-ia-crm-portail-mobile.md](./reste-a-faire/06-plateforme-ia-crm-portail-mobile.md) | Sécurité, verrous, recherche, IA, aide, traductions, CRM, intégrations, espace client, application mobile |

**Chaque fiche se lit en trois temps :**
- **Ce que fait fripv2** : le comportement de référence, tiré de son aide en ligne et de son schéma de base de données.
- **Ce que PI a aujourd'hui** : fichiers, routes et écrans existants (les « preuves »).
- **Ce qu'il faut faire** : migration, API, écrans, droits, tests et pièges, avec une taille (**P** petite, **M** moyenne,
  **G** grande).

**Statuts :**
- **Fait** ;
- **Partiel** : une partie manque ;
- **Manquant** ;
- **Hors périmètre** : propre à un logiciel de bureau installé (licences, imprimantes locales, gestion des fenêtres), ou
  écarté volontairement, comme le SQL écrit par l'IA.

Les fiches ont été établies en lisant le code. Les noms de colonnes et les tailles proposés sont des points de départ, à
vérifier au moment de coder.

---

## Sommaire

1. [Reprendre le travail sur un Mac](#1-reprendre-le-travail-sur-un-mac)
2. [Règles à garder](#2-règles-à-garder)
3. [Travail en cours et phases suivantes](#3-travail-en-cours-et-phases-suivantes)
4. [Anomalies connues](#4-anomalies-connues)
5. [Fonctions de fripv2 qui manquent](#5-fonctions-de-fripv2-qui-manquent)
6. [Qualité : textes en dur et mode sombre](#6-qualité--textes-en-dur-et-mode-sombre)
7. [Exploitation](#7-exploitation)
8. [Ordre conseillé](#8-ordre-conseillé)

---

## 1. Reprendre le travail sur un Mac

### 1.1 Installer

Outils nécessaires :
- PHP 8.3, avec les extensions mbstring, dom, fileinfo, pdo_sqlite et pdo_mysql ;
- Composer ;
- Node 20 ;
- MySQL 8, seulement pour tester dans les mêmes conditions que la CI.

Avec Homebrew : `brew install php@8.3 composer node@20`, et `mysql@8.0` au besoin.

```bash
git clone https://github.com/Armel-Jordan/prive-inventaire.git
cd prive-inventaire

# API (Laravel 12)
cd prise-inventaire-api
composer install
cp .env.example .env
php artisan key:generate
# Base locale SQLite (plus simple que MySQL pour développer)
touch database/database.sqlite
# Dans .env : DB_CONNECTION=sqlite, et commenter les autres lignes DB_*
php artisan migrate
php artisan db:seed --class=TenantIsolationDemoSeeder   # entreprises alpha et beta, comptes de démonstration
php artisan serve --port=8010

# Back-office (React 19 + Vite), dans un autre terminal
cd prise-inventaire-web
npm ci
VITE_API_URL=http://127.0.0.1:8010/api npx vite --port 5180

# Espace client (site à part), au besoin
cd prise-inventaire-client
npm ci
VITE_API_URL=http://127.0.0.1:8010/api npx vite --port 5185
```

Comptes de démonstration créés par `TenantIsolationDemoSeeder` (le formulaire de connexion demande le code entreprise) :
- code entreprise `alpha` : `admin@alpha.test` / `password`. Plan enterprise, tous les modules.
- code entreprise `beta` : `admin@beta.test` / `password`. Plan pro, sans le module Finance : utile pour vérifier qu'une
  fonction se masque quand son module est éteint.
- super-administrateur : `super@prise.test` / `password` (console `/super-admin`).

Les tâches planifiées tournent de deux façons :
- par `php artisan schedule:run`, lancé chaque minute par cron, ou à la main par `php artisan taches:executer` ;
- sans planificateur, elles passent aussi « au passage » des requêtes, au plus une fois par minute.

### 1.2 Tester

```bash
# API : PHPUnit (phpunit.xml utilise SQLite en mémoire)
cd prise-inventaire-api
php artisan test                      # toute la suite (585 tests au 19/10)
php artisan test --filter=RepresentantsTest
./vendor/bin/pint --test              # style (la CI échoue sinon) ; ./vendor/bin/pint pour corriger

# Web
cd prise-inventaire-web
npm run lint
npm run build                         # tsc + vite build
```

La CI (GitHub Actions) tourne sur chaque PR vers `main` :
- `backend-ci.yml` : Pint, puis PHPUnit sur **MySQL 8**, avec `.env.example` comme base de configuration ;
- `frontend-ci.yml` : lint et build du back-office, du site vitrine et de l'espace client.

Pièges connus :
- MySQL trie les clés des colonnes JSON : comparer ces colonnes avec `assertEquals`, pas `assertSame`.
- Un nombre à virgule envoyé en JSON peut revenir en entier (`5.0` devient `5`) : convertir avant de comparer.
- Une PR empilée sur une autre branche que `main` n'a pas de CI : viser toujours `main`.
- La fusion dans `main` **déploie automatiquement** (`backend-cd.yml`, `frontend-cd.yml`, `client-cd.yml`).
  - Le déploiement prend la tête de `origin/main`.
  - L'API et le web se déploient séparément.

### 1.3 Numéros de migration

La dernière migration est `2026_10_18_100089_representants_ventes.php` ; la suivante sera `…_100090_…`.

Les migrations sont **additives** : `Schema::hasColumn` / `Schema::hasTable` avant d'ajouter, et aucune colonne
existante modifiée ni supprimée.

---

## 2. Règles à garder

Elles valent pour toute nouvelle fonction.

- **Additif et activable par entreprise.** Rien d'existant n'est réécrit. Une nouveauté se range derrière un module
  de l'entreprise (`config/modules.php`, middleware `module:…`) ou derrière un paramètre.
- **Générique.** Rien de propre à un métier ou au Québec n'est codé en dur : taxes, formats bancaires, plans comptables,
  devises et langues se règlent.
- **Isolation des entreprises.**
  - Toute table métier a `tenant_id` et le trait `BelongsToTenant`.
  - Une écriture en masse (`insert`) pose `tenant_id` à la main.
  - Chaque fonction a un test d'isolation : une autre entreprise reçoit 404 ou une liste vide.
- **Rôles et permissions.** Pour chaque fonction :
  1. le module et ses actions nommées dans `App\Support\Permissions::MODULES` (chaque action a un droit de base) ;
  2. ses routes dans `App\Support\Droits` :
     - `MODULES` : écritures, par préfixe ;
     - `ECRITURES_ACTION` : toutes les écritures d'un préfixe, rattachées à une action ;
     - `PARTICULIERES` : une route précise ;
     - `LECTURES` : lectures sensibles ;
     - `LIBRES` : routes ouvertes à tout compte connecté ;
  3. un nouveau module copie, dans sa migration, les droits de son module le plus proche, et entre dans la liste rejouée
     de `RolesEtAccesTest` ;
  4. côté web : `peut(module, action)` pour les boutons, et `module` (avec `action` au besoin) sur l'entrée de
     `src/navigation.ts`.
- **Langues.**
  - Les messages de l'API sont en français dans `__()`, avec leur anglais dans `lang/en.json`. Le test
    `tests/Unit/TraductionsAnglaisesTest` échoue sinon, et il vérifie aussi les paramètres comme `:nom`.
  - Les tests envoient `Accept-Language: fr` par défaut.
  - Les écrans prennent leurs textes dans `src/i18n/translations.ts`, en fr et en.
- **Tests à mettre à jour** quand on ajoute :
  - une tâche planifiée : la liste exacte et le nombre dans `TachesPlanifieesTest` (18 au 19/10) ;
  - un type de courriel : la liste de `CourrielsConfigurationTest` ;
  - un rapport de la bibliothèque : le nombre dans `BibliothequeRapportsTest` et `QuestionsIaTest` (13 au 19/10).
- **Dates.** L'API tourne en UTC, sans fuseau d'entreprise.
  - Une règle « pas dans le futur » s'écrit `before_or_equal:tomorrow` (un jour de marge).
  - Les écrans envoient la date locale de l'usager (`aujourdhui()`).
- **Nom de l'application.** « Prise Inventaire » est provisoire : jamais codé en dur, il vient de `VITE_APP_NAME`.
- **Espace client.** C'est un site à part (`prise-inventaire-client`, port 8081 en production), jamais une route
  `/portail` du back-office.
- **Documentation.** Chaque fonction met à jour :
  - son `docs/flows/FLOW-*.md` ;
  - l'aide de son écran (`src/aide/ecrans/*.ts`, en français et en anglais) ;
  - `src/aide/nouveautes.ts`, si la fonction est visible.
- **Commits et PR.**
  - Messages en français.
  - Ajouter les fichiers par leur chemin ; ne jamais ajouter `debug.log`.
  - Pas de ligne `Co-Authored-By` ni de mention d'outil dans les commits et les PR.
  - Une PR par fonction, fusionnée quand la CI est verte.
- **Aucun secret dans le dépôt.** Clés d'API, mots de passe et clés de signature restent dans les variables
  d'environnement des serveurs ou de la CI.

---

## 3. Travail en cours et phases suivantes

### 3.1 Représentants commerciaux (phase 1.11a) — fusionnés (#131), à vérifier à l'écran

**Fait et testé par l'API** (`tests/Feature/Ventes/RepresentantsTest.php`, détail dans FLOW-VENTES §2.7) :
- représentant sur la fiche client, repris par les devis, les commandes et les factures, et suivi dans les conversions ;
- taux de commission figé sur la facture ;
- filtres des listes et ligne du PDF ;
- rapport « Ventes par représentant » ;
- suppression refusée quand le représentant est utilisé.

Déjà vérifié dans le navigateur : choix sur la fiche client, proposition sur un nouveau devis, filtre de la liste des
devis.

**Reste à vérifier dans le navigateur** (rien n'indique de défaut, mais ce n'a pas encore été vu à l'écran) :
- [ ] nouvelle facture : le représentant du client est proposé, et le détail de la facture affiche « Commission x % » ;
- [ ] commande : choix, modification d'un brouillon, filtre de la liste ;
- [ ] liste des clients : filtre par représentant ;
- [ ] Bibliothèque de rapports → « Ventes par représentant » : paramètre « Représentant », totaux, export PDF/Excel ;
- [ ] Paramètres → Référentiels → Représentants :
  - supprimer un représentant rattaché affiche « Ce représentant est rattaché à des clients ou à des documents de vente :
    désactivez-le plutôt. » ;
  - un représentant désactivé reste affiché (« inactif ») sur les fiches qui le portent.

**Reste de la phase** (détail dans [02-base.md](./reste-a-faire/02-base.md), option 20) :
- `bons_livraison.representant_id`, copié de la facture à la création du BL. Aujourd'hui le BL montre celui de sa
  facture ; fripv2 le met aussi sur le BL.
- `produits.commissionnable` (vrai par défaut) : la commission se calcule alors sur les lignes commissionnables.
- Option `representant_obligatoire` (faux par défaut).
- Idée utile, absente de fripv2 : un réglage « commission sur les factures payées seulement ».
  - Aujourd'hui la commission porte sur les ventes hors taxes, moins les notes de crédit.
  - fripv2 laisse ce calcul au réglage de l'entreprise.

**Code à réutiliser pour les phases suivantes :** `App\Services\EntetesVente`.
- Sa constante `CHAMPS` associe un champ de l'en-tête à la colonne du client qui donne sa valeur par défaut.
- Ajouter un champ dans `CHAMPS` le fait passer partout :
  - à la création : la valeur demandée, sinon celle du client ;
  - à la modification ;
  - à la copie d'un document à l'autre : devis → commande → facture, et reste à facturer.
- `EntetesVente::regles()` accepte la valeur actuelle même si la fiche est désactivée.

### 3.2 Transporteurs (phase 1.11b) — la prochaine phase

Le référentiel `transporteurs` existe (code, nom, téléphone, courriel, lien de suivi avec `{numero}`, numéro de compte,
notes, actif), **mais aucun tiers ni document ne le porte**.

Le comportement de fripv2 et la proposition complète sont dans [02-base.md](./reste-a-faire/02-base.md) : option 21,
ainsi que les options 15 et 16 pour les frais. En bref :
- **Données**
  - `transporteurs.frais_livraison_applicable` : vrai par défaut ; c'est la colonne « Livraison facturée » de la liste.
  - `transporteurs.frais_livraison_achat_id`.
  - `transporteur_id` (nullable, indexé, sans clé étrangère dure) sur `clients`, `fournisseurs`, `devis`,
    `com_client_entete`, `factures`, `bons_livraison` et `com_four_entete`.
  - `numero_suivi` (60) sur `com_client_entete`, `factures`, `bons_livraison` et `bl_colis`.
- **Report sur les documents**
  - Ajouter `'transporteur_id' => 'transporteur_id'` à `EntetesVente::CHAMPS` : défaut du client, puis suivi devis →
    commande → facture → BL.
  - Commandes fournisseur : défaut = transporteur du fournisseur.
- **Frais de livraison**
  - `FraisDocuments` ne propose plus le frais de livraison quand le transporteur du document a
    `frais_livraison_applicable = false`.
  - Le calcul est relancé quand le transporteur change, sans retoucher une ligne modifiée à la main.
- **Côté achats** : une ligne « Frais de transport – {transporteur} » sur la facture d'un fournisseur livré par ce
  transporteur.
- **Sorties**
  - PDF : « Expédié par … — suivi … ».
  - Variables de courriel `{transporteur}`, `{numero_suivi}`, `{lien_suivi}`.
  - Lien de suivi dans l'espace client.
- **Règles**
  - Suppression refusée si le transporteur est utilisé.
  - Champs masqués tant qu'aucun transporteur n'est actif.
  - Option `transporteur_obligatoire` (faux par défaut).
- **Pièges**
  - Ne pas toucher l'enum `mode_livraison` des BL.
  - Masquer le transporteur en `retrait_client`.
  - Le BL créé depuis une facture (`POST /factures/{id}/creer-bl`) doit reprendre le transporteur.

### 3.3 Statuts personnalisés (phase 1.11c) — ensuite

Le référentiel `statuts_personnalises` existe (document, code, libellé, couleur, ordre, actif), **mais aucun document
ne le porte**. Détail dans [01-gestion-configuration-usagers.md](./reste-a-faire/01-gestion-configuration-usagers.md),
section 20.

- **Dans fripv2**, ce sont des étiquettes libres de repérage, **sans effet sur le cycle du document** :
  - une seule étiquette par document (devis, commande, BL, facture) ;
  - couleurs du fond et du texte ;
  - modifiable même sur un document clos ;
  - colonne et filtre dans les listes ;
  - suppression refusée si l'étiquette est utilisée.
- **À faire**
  - `statuts_personnalises.couleur_texte`.
  - `statut_personnalise_id` (nullable) sur `devis`, `com_client_entete`, `factures`, `bons_livraison`,
    `com_four_entete` et `receptions`.
  - `PUT /{documents}/{id}/statut-personnalise`, accepté même sur un document clos.
  - Filtre `?statut_personnalise_id=` sur les listes.
  - Pastille en couleur dans les listes.
  - Suppression refusée si l'étiquette est utilisée.
- **Ensuite : « Statuts des documents »** (section 19). Libellés et couleurs des statuts système réglables par entreprise,
  avec un catalogue `App\Support\StatutsDocuments` et un composant `BadgeStatut` commun aux listes.

---

## 4. Anomalies connues

Relevées en lisant le code ou en écrivant l'aide ; aucune n'a encore été corrigée. Chacune mérite une petite PR avec
son test. A1 à A9 ont été notées pendant le travail. A10 à A15 viennent de l'analyse de parité, et leur cause a été
revue dans le code.

| # | Anomalie | Où | Effet | Correction proposée |
|---|---|---|---|---|
| A1 | La facture du **reste** d'une livraison partielle ne reprenait ni la devise, ni le taux de change, ni la condition de paiement — **sans objet depuis le 26/10** | `BonLivraisonController::genererFactureReste` (supprimée) | — | Cette facture n'est plus créée (voir A10). |
| A2 | Une **facture brouillon ne se modifiait pas** — **corrigé le 26/10** | `PUT /factures/{id}`, `FacturesVente::modifier` | Il fallait supprimer puis recréer la facture | Fait : crayon sur un brouillon (droit Factures → Modifier), formulaire prérempli, verrou pendant la modification, taxes et totaux recalculés ; refusé pour une facture issue d'un abonnement, d'un contrat, de reliquats ou d'une consignation (03, F1). |
| A3 | Le **statut des arrêts d'une tournée** n'était jamais mis à jour — **corrigé le 26/10** | `tournee_bons.statut`, `BonLivraisonController::enregistrerLivraison` | L'avancement d'une tournée ne se voyait pas arrêt par arrêt | Fait : à l'enregistrement de la livraison, l'arrêt passe « livré » (ou « échec » si rien n'est livré), avec l'heure ; l'écran Tournées l'affichait déjà. Reste (06, §7.1) : l'échec déclaré avec un motif depuis l'application du livreur. |
| A4 | Les **notifications** étaient partagées par toute l'entreprise — **corrigé le 26/10** | `notifications`, `NotificationsCompte`, cloche | Tout le monde voyait tout, et « lu » valait pour tous | Fait : `admin_user_id` (vide = toute l'entreprise), lecture et suppression par compte (`notification_lectures`), filtre par droit du module de chaque type ; la réponse du support vise son auteur (FLOW-NOTIFICATIONS). |
| A5 | Le **prix convenu avec le fournisseur** ne pré-remplissait pas les lignes de commande fournisseur — **corrigé le 26/10** | `PrixAchatProposes`, `CommandesFournisseurPage` | Le prix était ressaisi à chaque commande | Fait : dernier prix payé au fournisseur, sinon prix convenu, ramené à l'unité d'achat et à la devise de la commande ; origine affichée ; un prix tapé n'est jamais remplacé. Reste (03, CF1) : le coût de la fiche produit en dernier recours (avec P4 et S1) et l'escompte coûtant (E2). |
| A6 | La conversion **devis → commande** datait la commande en UTC — **corrigé le 26/10** | `DevisController`, `FactureController`, `ReliquatsClients`, `SuggestionsAchat` | Le soir, à l'ouest de Greenwich, le document prenait la date du lendemain | Fait : commande tirée d'un devis, facture tirée d'un devis ou d'une commande : date envoyée par l'écran (date locale), sinon le jour de l'entreprise (`App\Support\DateDuJour`, fuseau TACHES_FUSEAU) ; de même pour les commandes fournisseur des reliquats et des suggestions. |
| A7 | Un **bon de livraison** ne se crée que depuis une facture émise, avec `mode_livraison` forcé à « entreprise » et sans en-tête repris | `FactureController::creerBonLivraison` | Pas de livraison avant facturation ; ni transporteur ni adresse choisie | BL1 de [03](./reste-a-faire/03-inventaire-ventes-achats.md) §3.8 : BL depuis une commande acceptée, ou BL manuel |
| A8 | Les **frais additionnels et de livraison** ne sont proposés que sur les devis, commandes et factures | `FraisDocuments::DOCUMENTS` | Rien sur les achats, alors que fripv2 a des frais de transport et des frais d'achat | [02-base](./reste-a-faire/02-base.md) options 15 et 16 ; « Frais d'achat » dans [04-comptabilite](./reste-a-faire/04-comptabilite.md) |
| A9 | Le référentiel des transporteurs n'a pas le réglage « livraison facturée » | `Referentiels.php` (transporteurs) | Les frais de livraison du client s'ajoutent même quand le transporteur ne les facture pas | Phase 1.11b (§3.2) |
| A10 | **Livraison partielle : risque de double facturation** — **corrigé le 26/10** | `BonLivraisonController::enregistrerLivraison`, `FactureController::creerBonLivraison` | Une facture brouillon « du reste » refacturait les quantités non livrées, que la facture d'origine couvrait déjà | Fait : une livraison partielle ne crée plus de facture ; « Livrer le reste » (écran Factures) crée un nouveau bon avec les seules quantités non livrées ; un reste abandonné se règle par une note de crédit. Reste (03, BL2) : une action « Abandonner le reste » qui propose la note de crédit. |
| A11 | Le **numéro de facture était pris dès le brouillon** — **corrigé le 26/10 (réglage)** | `FacturesVente::numeroBrouillon`, `emettre` | Un brouillon supprimé laissait un trou dans la numérotation | Fait : réglage « Numéro attribué à l'émission » (Configuration → Numérotation → Factures, inactif par défaut) : brouillon numéroté `BR-{id}`, vrai numéro à l'émission. Reste (03, F2) : la date d'émission comme date de la facture et l'annulation avec motif au lieu de la suppression. |
| A12 | **Droits non contrôlés côté serveur** sur quelques routes — **corrigé en partie le 26/10** | `Droits.php`, `PieceJointeController`, `CourrielEnvoiController` ; écrans réservés par `role:admin` | Corrigé : le journal (`/audit`) demande Historique → Consulter ; les remarques rapides se gèrent avec la Configuration (la lecture reste libre) ; les pièces jointes suivent leur fiche (la voir pour les consulter, la modifier pour en ajouter ou en retirer) ; les courriels d'un document demandent de voir ce document, tout l'historique demande de voir la Configuration. Laissé ouvert volontairement : la configuration à compléter reste lisible par tous (liste de réglages, aucune donnée sensible). | **Reste** : la délégation des écrans réservés à l'administrateur (tâches planifiées, usagers, groupes, accès des comptes : 01, sections 13, 32 et 33), par des modules de droits et une migration qui reproduit l'existant. |
| A13 | Le `livreur_id` d'une tournée était validé sans `TenantRule` — **corrigé le 26/10** | `TourneeController` | Un identifiant d'employé d'une autre entreprise était accepté | Fait : `TenantRule::exists('employes')`, test d'isolation. |
| A14 | **Tables filles sans `tenant_id`** : `recurrence_echeances`, `facture_fournisseur_lignes`, `transaction_bancaire_lignes`, `encaissement_lignes`, `debourse_lignes`, `releve_bancaire_liens`, `frais_produits` | migrations de ces tables | L'isolation ne tient que par la fiche parente : toute requête directe sur ces tables (rapport, import) doit joindre le parent | T8 de [04-comptabilite](./reste-a-faire/04-comptabilite.md) : `tenant_id` rempli depuis le parent, index, `BelongsToTenant`, sans casser les `with()` existants |
| A15 | **Référentiels supprimables alors qu'ils servent** — **corrigé le 26/10** | `Referentiels::definitions()`, clé `suppression` | La suppression douce les retirait en silence des listes alors que des fiches les portaient | Fait : unités, fabricants, conditions de paiement, comptes bancaires, devises, entrepôts, itinéraires, types de location et raisons refusent la suppression tant qu'ils servent (FLOW-PARAMETRES §11.1). Les modes de paiement, transporteurs et statuts personnalisés ne sont encore portés par rien : à ajouter avec leurs phases. |

---

## 5. Fonctions de fripv2 qui manquent

### 5.1 Vue d'ensemble

| Fichier | Bilan | À retenir |
|---|---|---|
| [01 Gestion : Configuration et Usagers](./reste-a-faire/01-gestion-configuration-usagers.md) | 36 options : 2 faites, 23 partielles, 7 manquantes, 4 hors périmètre | **Constats transverses :** droits non contrôlés (T1), libellés traduisibles (T2), journal des modifications (T3), langue des clients et fournisseurs (T4), suppression des référentiels utilisés (T5). **Écrans :** fiche de l'entreprise avec logo ; notes de bas de page ; statuts des documents et statuts personnalisés ; conditions et modes de paiement ; tâches planifiées (droits, statistiques, taux de change) ; délais des verrous ; usagers et groupes délégables ; tablettes. |
| [02 Gestion : Base](./reste-a-faire/02-base.md) | 28 options : 5 faites, 13 partielles, 10 manquantes | Transporteurs ; territoires sur les clients ; routes par adresse ; catégories reliées aux produits ; géographie (villes, régions, pays, codes postaux) ; taxes (montant fixe, exemptions) et groupes de taxes par défaut ; départements ; emplacements et leurs étiquettes ; langues ; banques ; devises et taux de change. |
| [03 Inventaire, Ventes, Achats](./reste-a-faire/03-inventaire-ventes-achats.md) | 29 écrans : 8 faits, 20 partiels, 1 manquant ; 5 socles communs | **Socles :** stock perpétuel par emplacement (S1), nature du produit (S2), quantités décimales (S3), en-tête et lignes des documents de vente (S4), export Excel (S5). **Priorités :** facture brouillon modifiable (F1), numéro à l'émission (F2), livraison partielle sans double facturation (BL2), prix proposé sur la commande fournisseur (CF1). |
| [04 Comptabilité](./reste-a-faire/04-comptabilite.md) | 29 options : 6 faites, 21 partielles, 2 manquantes ; un socle T1 à T8 | Comptes à payer ventilés par compte (avoirs, renversement) ; frais d'achat ; lignes comptables sur les factures ; transactions bancaires taxées ; récurrences multi-sources ; relevés et âges des tiers ; comptabilisation du stock, du coût des ventes, des cartes cadeaux et du change ; états en PDF et XLSX. |
| [05 Rapports](./reste-a-faire/05-rapports.md) | 61 rapports : 20 faits, 29 partiels, 9 manquants, 3 sans objet | Prérequis de la bibliothèque (nouveaux types de paramètres, sous-totaux, totaux en ratio) ; coût figé sur les lignes de facture, pour les marges ; analyses chronologiques ; états de compte en lot ; états financiers en PDF et XLSX ; export Excel des listes. |
| [06 Plateforme, IA, CRM, espace client, mobile](./reste-a-faire/06-plateforme-ia-crm-portail-mobile.md) | 21 sujets : 2 faits, 13 partiels, 6 manquants | Connexion (limitation des tentatives, politique de mot de passe, 2FA exigible) ; verrous à délai d'inactivité ; assistant IA à outils (sans SQL) ; traductions ; CRM et webhooks ; socle des intégrations ; paiement en ligne ; espace client ; mobile (registre des appareils, hors-ligne, livreur, ramassage, inventaire). |

### 5.2 Sujets traités à plusieurs endroits

Plusieurs sujets touchent plusieurs onglets de fripv2. La fiche de référence est celle à suivre ; les autres donnent un
angle complémentaire, à relire avant de coder.

| Sujet | Fiche de référence | À relire aussi |
|---|---|---|
| Transporteurs | 02, option 21 | 01, annexe ; 03, S4.1 |
| Frais de livraison et frais additionnels de vente | 02, options 15 et 16 | 03, S4.2 |
| Frais d'achat | 04, « Frais d'achat » | 02, option 16 (partie achats) ; 03, CF7 |
| Langues, libellés traduisibles, langue des tiers | 02, option 25 | 01, T2 et T4 ; 06, §6.8 |
| Tablettes et appareils mobiles | 06, §7.0 | 01, section 35 |
| Facturation à l'usage par API | 06, §6.10-a, sur le socle §6.10-0 | 01, section 14 ; 03, AB2 |
| Verrous (délais d'inactivité, « tout libérer ») | 06, §6.3 | 01, sections 25 et 28 |
| Journal des modifications | 01, section 26 (T3) | 06, §6.2 ; 04, socle (traçabilité) |
| Catégories de contact, préférences d'envoi | 01, section 4 | 06, §6.9c |
| Prospects | 06, §6.9d | 03, C2 |
| Rapport IA et assistant | 06, §6.5 | 01, sections 30 et 31 ; 05, §4.27 |
| Taux de change (périodes, cours du jour) | 02, option 28 | 01, section 13 (tâche `taux_change`) |
| Stock perpétuel et emplacements | 03, S1 | 02, options 23 et 24 ; 06, §7.3 |
| Actifs retournables (cylindres) et loyers | 06, §7.2 | 03, S2 et L1 à L3 |
| Paiement en ligne | 06, §6.10-d | 03, F7 ; 06, §1.11 |
| Relevés et âges des clients et fournisseurs | 04, « Transactions par fournisseur » et « par client » | 05, comptes clients et fournisseurs |
| Notes de bas de page | 01, section 8 | 03, S4.4 |
| Bons de livraison | 03, §3.8 | 06, §7.1 ; anomalies A1, A7 et A10 |
| Départements (familles comptables) | 02, option 14 | 04, comptabilisation |
| Statuts des documents et statuts personnalisés | 01, sections 19 et 20 | §3.3 ci-dessus |

---

## 6. Qualité : textes en dur et mode sombre

### 6.1 Textes français écrits en dur dans les écrans (B20)

Les messages de l'API suivent déjà la langue de l'écran (fait en 6.8b). En revanche, beaucoup d'écrans plus anciens
écrivent encore leurs libellés en français dans le code : un usager en anglais voit un écran à moitié traduit. Il faut
déplacer ces textes dans `src/i18n/translations.ts` (fr et en) et les lire par `t.…`.

Comptage au 19/10, sur les textes JSX, `placeholder`, `title`, `aria-label`, `alert()` et `confirm()` en français :
**527 occurrences dans 38 fichiers**. Les plus touchés :

| Fichier | Occurrences | | Fichier | Occurrences |
|---|---|---|---|---|
| `pages/FacturesPage.tsx` | 51 | | `pages/ProduitsPage.tsx` | 18 |
| `pages/CommandesFournisseurPage.tsx` | 38 | | `pages/ConfigurationPage.tsx` | 18 |
| `pages/DevisPage.tsx` | 35 | | `pages/ComptabilitePage.tsx` | 17 |
| `pages/CommandesClientPage.tsx` | 31 | | `pages/RapportsPage.tsx` | 15 |
| `pages/EmployesPage.tsx` | 28 | | `pages/SuperAdminTenantPage.tsx` | 14 |
| `pages/BonsLivraisonPage.tsx` | 27 | | `pages/SecteursPage.tsx` | 14 |
| `pages/PlanificationPage.tsx` | 25 | | `pages/ClientsPage.tsx` | 13 |
| `pages/ScansPage.tsx` | 23 | | `pages/CamionsPage.tsx` | 10 |
| `pages/TourneesPage.tsx` | 19 | | `pages/SuperAdminNewTenantPage.tsx` | 9 |
| `pages/RelocalisationPage.tsx` | 18 | | autres (20 fichiers) | 1 à 8 chacun |

Les 20 autres fichiers :

| Occurrences | Fichiers |
|---|---|
| 8 | `ReceptionsPage`, `ProfilPage`, `Dashboard`, `AlertesPage` |
| 7 | `TracabilitePage`, `PrevisionsStockPage`, `FournisseursPage`, `FichesEmployesPage`, `ComparaisonPage` |
| 6 | `CompleteProfilePage` |
| 5 | `SuperAdminDashboard`, `StatsPage`, `InventaireTournantPage` |
| 4 | `SuperAdminSupportPage`, `AuditPage`, `ApprobationsPage` |
| 2 | `NotificationBell` |
| 1 | `PortailClientsPage`, `LoginPage` |

**Le menu lui-même** (`src/navigation.ts`) a aussi des libellés en dur :
- les groupes « Inventaire », « Achats », « Ventes », « Finance », « Paramètres » ;
- les entrées « Inv. Tournant », « Fournisseurs », « Commandes » (deux fois), « Réceptions », « Clients », « Devis »,
  « Factures », « Bons Livraison », « Camions », « Tournées », « Comptabilité », « Prévisions », « Tarifs & Prix »,
  « Fiches Employés », « Configuration ».

Ces libellés servent aussi à la carte des écrans de l'aide et à la recherche globale : les passer d'abord par `t.nav.…`.

**Méthode conseillée :**
- un écran par PR, en commençant par le menu, puis les écrans de vente (factures, devis, commandes) ;
- garder les mêmes textes français, que les pilotes de test et l'aide citent.

Le plan complet des traductions est dans [06](./reste-a-faire/06-plateforme-ia-crm-portail-mobile.md), §6.8 :
écrans, données, PDF dans la langue du client, application Android.

### 6.2 Mode sombre incomplet (B30)

Ces écrans posent des fonds ou des textes clairs (`bg-white`, `bg-gray-50`, `text-gray-900`…) sans leur variante
`dark:`. En mode sombre, ils restent blancs ou deviennent illisibles :

| Écran | Classes claires sans `dark:` |
|---|---|
| `StatsPage` | 17 |
| `TracabilitePage` | 16 |
| `CommandesFournisseurPage` | 16 |
| `RapportsPage` | 15 |
| `ApprobationsPage` | 14 |
| `AlertesPage` | 13 |
| `PlanificationPage` | 12 |
| `InventaireTournantPage` | 12 |
| `ComparaisonPage` | 11 |
| `AuditPage` | 11 |
| `ScansPage` | 10 |
| `RelocalisationPage` | 9 |
| `FournisseursPage` | 9 (une seule variante sombre) |
| `LoginPage`, `CompleteProfilePage`, `MotDePasseOubliePage`, `ReinitialiserMotDePassePage` | 2 à 4 |

Les fenêtres modales de `DevisPage` et de quelques écrans de vente ont aussi des `bg-white` sans variante.

Modèle à suivre : `GestionPrixPage` et `components/prix/*`, où chaque fond, bordure et texte a sa variante `dark:`.

---

## 7. Exploitation

Réglages à faire sur les serveurs. Aucun ne se met dans le dépôt : les valeurs secrètes restent dans les variables
d'environnement du serveur.

| # | À faire | Où | Pourquoi |
|---|---|---|---|
| E1 | Définir `ANTHROPIC_API_KEY` (et au besoin `IA_MODELE`) | `.env` du serveur de l'API | Sans clé, les questions à l'IA et l'analyse des rapports programmés répondent « indisponible » |
| E2 | Activer le module « Intelligence artificielle » pour les entreprises qui y ont droit | console super-administrateur → entreprise → modules | Le module est facultatif, éteint par défaut |
| E3 | Définir `SUPPORT_EMAIL` | `.env` de l'API | Adresse qui reçoit les demandes de support et les suggestions |
| E4 | Régler l'envoi de courriels (`MAIL_*`) et `ESPACE_CLIENT_URL` | `.env` de l'API | Liens de l'espace client et de réponse aux devis dans les courriels ; à défaut, l'adresse réglée dans Ventes → Espace client |
| E5 | Planificateur : une tâche cron `* * * * * php artisan schedule:run` | serveur de l'API | Sinon les tâches ne tournent qu'« au passage » des requêtes : une révision de prix planifiée à 0 h 05 attend la première requête du matin |
| E6 | `TACHES_FUSEAU` (par exemple `Africa/Douala`) | `.env` de l'API | Heures des tâches planifiées dans le fuseau de l'entreprise (vide : UTC) |
| E7 | Construire et tester l'APK Android | `prise-inventaire-android` | Dernière version testée inconnue. L'étape de double authentification est dans le code (`LoginActivity`), mais n'a pas été vérifiée sur un appareil. L'APK `prise-inventaire-v1.0.apk` à la racine du dépôt est antérieur aux jetons et ne se connecte plus : le retirer. Sortir la configuration de signature de `app/build.gradle`. Voir 06, §7.0. |
| E8 | Publier une nouvelle version du poste de travail (Tauri) | tag `desktop-vX.Y.Z` (workflow `desktop-build.yml`) | Le dernier installeur date de `desktop-v0.1.0` |
| E9 | Rappel : l'API compte « aujourd'hui » en UTC | — | Une saisie tard le soir, à l'ouest de Greenwich, peut tomber le lendemain (voir A6) |
| E10 | Domaine et HTTPS pour l'API, le back-office et l'espace client | serveurs (nginx), application Android | Prérequis du paiement en ligne, des webhooks (Stripe, Plaid), du flux bancaire et de l'application mobile. Voir l'en-tête de 06. |

---

## 8. Ordre conseillé

Chaque lot se découpe en PR d'une fonction chacune, fusionnées quand la CI est verte. Les numéros renvoient aux
fichiers du dossier [reste-a-faire/](./reste-a-faire/).

1. **Correctifs**, en petites PR indépendantes, chacune avec son test :
   1. A12 : fait en partie le 26/10 ; reste la délégation des écrans réservés à l'administrateur (01, T1) ;
   2. A10, A1 et A11 : faits le 26/10 ;
   3. A2 : fait le 26/10 ;
   4. A5 : fait le 26/10 ;
   5. A13 et A15 : faits le 26/10 ; A14 : `tenant_id` des tables filles ;
   6. A3, A4 et A6 : faits le 26/10 ;
   7. limitation des tentatives de connexion : faite le 26/10 (30 par minute et par adresse, compte bloqué 15 minutes après
      10 échecs) ; le reste de 06, §6.1 (journal des connexions, politique de mot de passe, 2FA exigible) reste à faire.
2. **Fin de la phase 1.11 (documents de vente)**
   - Représentants à vérifier à l'écran (§3.1), transporteurs (§3.2), statuts personnalisés (§3.3), puis statuts des
     documents (01, section 19).
   - Socles de 03 : S3 (quantités décimales), S2 (nature du produit), puis S4.1 à S4.7 (en-tête, lignes, copie, notes de
     bas de page, identité figée, historique, listes).
   - Ensuite : taxes du devis (DE1), cycle des commandes (CO1 à CO4), BL depuis la commande (BL1), puis F3, F4, F6 et F8.
3. **Référentiels, tiers et paramètres**
   - 02 : territoires, routes par adresse, fin des représentants, catégories reliées aux produits, popularités,
     formats ; groupes de taxes par défaut et exemptions ; géographie ; taux de change, devises, banques.
   - 01 : libellés traduisibles (T2) et langue des tiers (T4) ; fiche de l'entreprise et logo ; conditions et modes de
     paiement ; notes de bas de page ; balises, champs personnalisés, pièces jointes.
   - 03 : clients (C1, C2, C7) et fournisseurs (FO1), puis les onglets de consultation (C5, FO2).
4. **Comptabilité** : l'ordre est en fin de 04.
   - Hygiène tenant (T8), traçabilité (T4), taxes et comptes par défaut (T2).
   - Comptes à payer ventilés, puis frais d'achat.
   - Lignes comptables des factures ; transactions bancaires taxées ; récurrences.
   - Relevés des tiers ; comptabilisation ; états ; prélèvements ; connecteur bancaire.
5. **Stock perpétuel et emplacements**
   - 03, S1, puis ce qui en dépend : ajustements, mouvements, relocalisation, clôture d'une prise (PR1), boîtes, camion.
   - 02, options 23 et 24 : emplacements et leurs étiquettes.
   - Formules de prix (03, P4) et ce qui en dépend (RV1, IG2, E2, CT1).
6. **Rapports** : l'ordre est au §5 de 05.
7. **Plateforme et mobile** : l'ordre est en fin de 06.
   - Socle des intégrations (6.10-0) et socle mobile (7.0).
   - Paiement en ligne et espace client (6.10-d, 1.11).
   - Livreur (7.1), inventaire mobile (7.3), ramassage (7.2).
   - Assistant IA (6.5), CRM (6.9), imports (6.10-b), export comptable (6.10-e), recherche (6.4), e-commerce (6.10-f).

**En continu**, une PR par écran : textes en dur et mode sombre (§6).
