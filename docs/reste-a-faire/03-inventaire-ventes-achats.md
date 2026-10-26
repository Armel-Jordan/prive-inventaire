# Prise Inventaire et fripv2 : ce qui manque encore dans les onglets Inventaire / Entrepôt, Vente / Facturation et Achats / Fournisseurs

> Partie de [la liste du reste à faire](../17-RESTE-A-FAIRE.md), état au 19 octobre 2026 (après la PR #131).
> Pour chaque écran : **ce que fait fripv2** (le comportement de référence), **ce que PI a aujourd'hui**, puis
> **ce qu'il faut faire** (taille **P** petite, **M** moyenne, **G** grande).

Ce document compare les écrans de ces trois onglets. Les sous-menus « Rapports » sont traités dans [05-rapports.md](05-rapports.md).

État constaté le 2026-10-19.

Sources :
- **fripv2** : son aide en ligne (une page par écran) et son schéma `FripStructure.sql`.
- **PI** : le code (API, application web) et `docs/flows/*.md`.

**Statuts :**
- **Fait** : à parité, sauf détails signalés.
- **Partiel** : l'écran existe, mais il manque des champs, des options ou des actions, listés un par un.
- **Manquant** : aucun équivalent.
- **Hors périmètre** : sans objet en SaaS, ou déjà couvert autrement.

**Tailles :** P = petite, M = moyenne, G = grande.

**Règles reprises dans chaque proposition :**
- Migrations additives, avec `tenant_id` et `BelongsToTenant`.
- Activation par entreprise, par une colonne de `tenant_parametres` ou par le module tenant (`module:ventes` / `module:achats`).
- Droits déclarés dans `App\Support\Permissions::MODULES`, et raccordés dans `App\Support\Droits` (`MODULES`, `PARTICULIERES`, `LECTURES`, `ECRITURES_ACTION`, `LIBRES`).
- Messages de l'API en `__()`, avec leur anglais dans `lang/en.json`.
- Écrans en français et en anglais (`src/i18n/translations.ts`).
- Tests fonctionnels avec isolation (deux entreprises : 404 ou 422 d'une entreprise à l'autre), droits (403) et réglage désactivé (comportement inchangé).

### Repères (chemins dans le dépôt)

| Élément | Chemin |
|---|---|
| Routes API | `prise-inventaire-api/routes/api.php` |
| Permissions / Droits | `prise-inventaire-api/app/Support/Permissions.php`, `prise-inventaire-api/app/Support/Droits.php` |
| Menu web | `prise-inventaire-web/src/navigation.ts` |
| Traductions | `prise-inventaire-web/src/i18n/translations.ts`, `prise-inventaire-api/lang/en.json` |
| Produits | `prise-inventaire-api/app/Http/Controllers/ProduitTenantController.php`, `prise-inventaire-web/src/pages/ProduitsPage.tsx`, `prise-inventaire-web/src/components/produits/*` |
| Stock calculé | `prise-inventaire-api/app/Services/DisponibiliteProduits.php` (stock = somme de `inventaire_scan.quantite`) |
| Relocalisation | `prise-inventaire-api/app/Http/Controllers/MouvementTenantController.php`, `prise-inventaire-web/src/pages/RelocalisationPage.tsx` |
| Prises d'inventaire | `prise-inventaire-api/app/Services/PrisesInventaire.php`, `prise-inventaire-api/app/Http/Controllers/Inventaire/PriseInventaireController.php`, `prise-inventaire-web/src/pages/PriseInventairePage.tsx` |
| Prix | `prise-inventaire-api/app/Services/CalculPrix.php`, `RevisionsPrix.php`, `ImportPrixFournisseurs.php` ; `prise-inventaire-web/src/components/prix/*` |
| Ventes | `prise-inventaire-api/app/Http/Controllers/Api/DevisController.php`, `CommandeClientController.php`, `FactureController.php`, `BonLivraisonController.php` ; `prise-inventaire-api/app/Services/FacturesVente.php`, `DocumentsVentePdf.php` ; `prise-inventaire-api/resources/views/pdf/document-vente.blade.php` |
| Clients / Fournisseurs | `prise-inventaire-api/app/Http/Controllers/Api/ClientController.php`, `ClientFiche*Controller.php`, `FournisseurController.php`, `FournisseurFiche*Controller.php` ; `prise-inventaire-web/src/pages/FicheClientPage.tsx`, `FicheFournisseurPage.tsx` |
| Achats | `prise-inventaire-api/app/Services/ReceptionsFournisseur.php`, `SuggestionsAchat.php`, `RetoursFournisseurs.php`, `ReliquatsClients.php` (`dernierPrix()`) ; `prise-inventaire-web/src/pages/CommandesFournisseurPage.tsx` |
| Logistique | `prise-inventaire-api/app/Services/ColisLivraisons.php`, `ChargementsTournee.php`, `SequencesLivraison.php`, `Consignations.php`, `ContratsLocation.php`, `AbonnementsClients.php` |

---

## 0. Lecture rapide : six constats

1. **PI n'a pas de stock perpétuel.**
   - Le stock est la somme des scans (`DisponibiliteProduits`). Rien n'est décrémenté.
   - L'« inventaire officiel » est la dernière prise validée par secteur.
   - Les relocalisations et les « ajustements » (`mouvement_relocalisation`) sont informatifs. Ils ne changent aucun solde.
   - Dans fripv2, chaque réception, vente, livraison, ajustement, relocalisation, boîte, chargement de camion, consignation ou prise terminée écrit `mouvement_inventaire` : quantité précédente, quantité ±, raison, coûtant, document, usager.
   - Ajustements, Mouvements, Relocalisation réelle, « Terminer » d'une prise, Chargement du camion, Boîtes et « Tout livrer » en dépendent. Voir **S1**.
2. **Les quantités sont entières dans tous les documents** : devis, commandes, factures, BL, commandes fournisseur, reliquats, retours, colis, abonnements. fripv2 accepte quatre décimales selon la précision de l'unité. Voir **S3**.
3. **L'en-tête et les lignes des documents de vente sont minimaux.**
   - Il manque : contact, adresse de livraison, transporteur, n° de commande client, n° de suivi, statut personnalisé et groupe de taxe d'en-tête.
   - Les lignes de titre et de texte n'existent pas. La description n'est modifiable que sur la facture.
   - Voir **S4**.
4. **Factures :**
   - Une facture brouillon ne se modifie pas : il n'y a pas de `PUT /factures/{id}`.
   - Le numéro est consommé dès le brouillon (`FacturesVente::creer` → `Configuration::consommerNumero`). fripv2 ne numérote qu'au passage « Facturée ».
   - Un brouillon supprimé laisse donc un trou dans la numérotation.
5. **BL :**
   - Un BL ne se crée que depuis une facture émise (`POST /factures/{id}/creer-bl`).
   - Une livraison partielle crée d'office une facture brouillon « du reste » (`BonLivraisonController::genererFactureReste`). La facture d'origine, déjà émise, porte pourtant toute la quantité : **risque de double facturation**.
   - fripv2 crée le BL depuis la commande ou à la main, puis renvoie le même BL ou abandonne le reste.
6. **Déjà au niveau, voire au-delà, de fripv2** : consignation, B/O client, abonnements, suggestions de commandes, retours fournisseurs, historique des réceptions, révisions de prix, colis.

---

## 1. Socles transverses (prérequis)

### S1 — Stock perpétuel par emplacement, activable (G)

**1. Ce que fait fripv2**
- **Une ligne de stock par emplacement** (`produit_code_barre`) : produit, localisation, quantité décimal(12,4), lot, n° de série, code-barres, spécification, fournisseur, client (obligatoire en consignation), coûtant décimal(14,6), prix de vente propre, quantité réservée, date reçue.
- **Emplacements spéciaux** : consignation, chez un client ; zone de retour fournisseur ; localisation de préparation des boîtes ; camion.
- **Journal immuable** `mouvement_inventaire` :
  - produit, localisation, ligne de stock, client, usager, date ;
  - quantité ± et quantité précédente ;
  - raison (référentiel `mouvement_raison`) ;
  - écran d'origine, n° de document ;
  - coûtant = coût moyen × quantité.
- **Ce qui écrit dans le journal** :
  - réception (+) et vente ou livraison (−) ;
  - ajustement ;
  - relocalisation, boîte et chargement : deux lignes chacun (sortie et entrée) ;
  - consignation : dépôt, retour, vente ;
  - prise d'inventaire terminée ;
  - note de crédit : retour au stock à l'emplacement d'origine.
- **Quantité négative refusée.**
- **Coût moyen pondéré** recalculé à chaque réception. Corriger le coûtant d'une réception réajuste le coût moyen ; la correction est refusée si ce coût devient négatif.

**2. Ce que PI a aujourd'hui**
- Le stock est la somme de `inventaire_scan` par numéro de produit. Les alertes, les disponibilités et les suggestions s'en servent.
- `PrisesInventaire::inventaireOfficiel` sert à la valorisation.
- `mouvement_relocalisation` est un journal sans solde : types arrivage, transfert, sortie, ajustement ; quantité > 0 ; secteurs en texte.
- Tables héritées partiellement écrites : `mouvements_inventaire` (modèle `MouvementVente`, écrit par les commandes, les BL et les tournées) et `produit_localisations`.
- `lot_mouvements` tient un journal par lot.
- Aucun coût moyen n'est stocké.

**3. Ce qu'il faut faire**
- **Réglages** dans `tenant_parametres` :
  - `stock_perpetuel` (booléen, faux par défaut) et `stock_perpetuel_depuis` (date-heure) ;
  - `stock_negatif_autorise` (faux) ;
  - `secteur_preparation_id` et `secteur_retour_fournisseur_id`.
- **Données :**
  - `stock_soldes` : `tenant_id`, `produit_id`, `emplacement_type` (secteur, camion, préparation, consignation, retour fournisseur), `emplacement_id` (id du secteur, du camion ou du client), `lot_id`, `numero_serie_id`, `quantite` décimal(15,4), `cout_unitaire` décimal(15,6). Clé unique sur le sextuplet.
  - `stock_mouvements`, immuable : les mêmes clés, plus `quantite_avant`, `quantite` signée, `quantite_apres`, `cout_unitaire`, `valeur`, `raison_code` (code système : reception, livraison, vente_comptoir, ajustement, relocalisation, prise_inventaire, retour_client, retour_fournisseur, consignation_depot / retour / vente, colis, chargement, dechargement, stock_initial), `raison_id` (référentiel `raisons` existant, types `mouvement` ou `ajustement`), `document_type`, `document_id`, `document_numero`, `client_id`, `user_id`, `origine` (web, mobile, tâche), `lien_id` (relie les deux lignes d'un déplacement), `note`.
  - Sur `produits` : `cout_moyen` et `dernier_cout` décimal(15,6), nullables.
- **Service** `App\Services\Stock\MouvementsStock` :
  - `entrer`, `sortir`, `deplacer`, `ajuster`, `soldes`, `disponible` ;
  - `lockForUpdate` sur la ligne de solde ;
  - refus d'un solde négatif (422), sauf si le réglage l'autorise ;
  - recalcul du coût moyen à chaque entrée chiffrée.
- **Branchements, seulement si le réglage est actif :**
  - `ReceptionsFournisseur` : enregistrement et annulation ;
  - `BonLivraisonController::enregistrerLivraison` : sortie depuis le camion si le BL y est chargé, sinon depuis le secteur choisi ;
  - `VentesComptoir` ;
  - `RetoursClients::traiter` : entrée en stock, pas d'entrée pour le rebut ;
  - `RetoursFournisseurs::expedier` et clôture « remplacement » ;
  - `Consignations` : dépôt, reprise, consommation ;
  - `PrisesInventaire::valider` : écritures d'écart ;
  - `ColisLivraisons`, `ChargementsTournee`, la relocalisation et l'exécution des transferts planifiés.
  - En mode perpétuel, `DisponibiliteProduits`, les alertes et les suggestions lisent `stock_soldes` au lieu des scans.
- **API :**
  - `POST /stock/initialiser`, au choix : inventaire officiel (dernière prise par secteur), somme des scans, ou zéro. Écrit des mouvements `stock_initial` et fixe `stock_perpetuel_depuis`.
  - `GET /stock/soldes?produit_id=&emplacement=`.
- **Écran** : Paramètres → Configuration, section « Stock perpétuel » (activation et initialisation), avec un avertissement irréversible.
- **Droits :**
  - module `stock` (onglet inventaire, crud `['voir']`, action `initialiser` sur la base `supprimer`) ;
  - `Droits::LECTURES` : `'stock' => 'stock'` ;
  - `Droits::PARTICULIERES` : `'POST api/stock/initialiser' => ['stock','initialiser']`.
- **Traductions** : « Stock perpétuel », « Quantité insuffisante à l'emplacement : :dispo disponible, :demande demandé » et les messages associés.
- **Tests** (`tests/Feature/Inventaire/StockPerpetuelTest.php`) :
  - réglage désactivé : aucun mouvement ;
  - réception → entrée et coût moyen ;
  - livraison → sortie ;
  - solde négatif → 422 ;
  - annulation de réception → contre-mouvement ;
  - deux sorties concurrentes ;
  - isolation (soldes et mouvements d'une autre entreprise invisibles, produit étranger → 422).
- **Taille G**, à découper :
  - S1a : registre, service et initialisation (M) ;
  - S1b : branchements achats et ventes (M) ;
  - S1c : consignation, préparation et camion (M).
- **Pièges :**
  - Garder les scans comme simples comptages.
  - Ne pas compter deux fois avec `lot_mouvements` : le référencer, ne pas le dupliquer.
  - Neutraliser `MouvementVente` et `produit_localisations` en mode perpétuel, ou les dériver.
  - Convertir les quantités avec le facteur d'unité.
  - Un ensemble sort ses composants. Un service ne bouge aucun stock (voir S2).
  - Les seuils d'approbation s'appliquent aux relocalisations.
  - Indexer `(tenant_id, produit_id, created_at)`.

### S2 — Nature et options du produit (M)

**1. Ce que fait fripv2** (`produit` et ses tables `produit_taxe`, `produit_frais`)
- **Cases :**
  - type Inventorié ou Non-inventorié (`produit_pni`) ;
  - code-barres multiple, spécifications étendues, lot ou série obligatoires ;
  - conserve B/O client ;
  - produit acheté, vente directe (vendu), fabrication ;
  - inclus dans la valeur d'inventaire, commissionnable ;
  - afficher sur le web, avec une quantité maximale affichée (décimal) ;
  - produit de service ;
  - location matériel ou location de contenant (cylindre), exclusives, avec un produit « contenant » associé (`id_produit_carcasse`).
- **Effets de « Produit de service »** :
  - après confirmation, décoche et désactive le code-barres multiple, le lot, la série, les spécifications, la conservation des B/O et le contenant ;
  - masque l'onglet Localisations ;
  - à la création, préremplit l'unité par défaut et le fournisseur par défaut de l'entreprise.
- **Verrous :**
  - le suivi ne change plus dès que des exemplaires sont en stock ;
  - la location ne change plus dès que des exemplaires sont chez des clients ;
  - l'unité de stock ne change plus s'il y a du stock ou une commande fournisseur en cours.
- **Taxes applicables par produit** : une case par taxe de l'entreprise. Groupes de taxes de vente et d'achat par défaut (`id_grp_taxe_vente`, `id_grp_taxe_achat`).
- **Frais applicables par produit**, avec une portée Vente ou Achat. Un seul frais d'achat par produit. Le frais « matière dangereuse » se coche seul dès qu'un n° UN ou une classe est saisi.
- **Classement** : département et popularité (obligatoires), format de matière dangereuse.

**2. Ce que PI a aujourd'hui**
- `produits.type` est un texte libre (PIECE, LIQUIDE…).
- Suivi par lot ou série (`suivi`, `peremption`) ; matière dangereuse (`numero_onu`, `classe_danger`, `groupe_emballage`).
- `frais_produits` se gère depuis l'écran Frais, portée vente seulement.
- `TaxesDocument::groupePour` prend le groupe de la ligne, sinon le groupe par défaut : il n'y a ni taxe par produit ni exemption.

**3. Ce qu'il faut faire**
- **Données** (colonnes sur `produits`) :
  - `stocke` (vrai), `service`, `achete` (vrai), `vendu` (vrai), `fabrique`, `valorise` (vrai), `commissionnable` (vrai) ;
  - `visible_portail` (vrai), `quantite_max_portail` décimal(15,4) ;
  - `conserve_reliquat` (vrai) ;
  - `location` (null, `materiel` ou `contenant`) et `produit_contenant_id` ;
  - `departement_id`, `popularite_id`, `format_md_id` (référentiels du lot Gestion) ;
  - `groupe_taxe_vente_id`, `groupe_taxe_achat_id`.
  - Table `produit_taxes` (`tenant_id`, `produit_id`, `tenant_taxe_id`, `applicable`).
  - Colonne `frais.portee` (`vente` ou `achat`).
- **Règles** (validation 422) :
  - un service ne peut pas avoir de suivi, de location ni de contenant ;
  - location matériel et contenant sont exclusives ;
  - un seul frais de portée achat par produit ;
  - verrous comme dans fripv2, avec la raison dans le message ;
  - « matière dangereuse » coché d'office (`FraisDocuments`).
- **Effets à câbler :**
  - Services et produits non stockés : aucun mouvement (S1), absents des réceptions, des commandes fournisseur et des suggestions, comptés « livrés » dès leur saisie.
  - `vendu=false` : produit masqué des sélecteurs de vente. `achete=false` : masqué des commandes fournisseur.
  - `valorise=false` : exclu de `ValorisationInventaire`.
  - `commissionnable=false` : exclu de la commission dans `App\Rapports\VentesParRepresentant`.
  - `visible_portail` et `quantite_max_portail` : appliqués dans `CommandesPortail::catalogue`, qui affiche « plus de X ».
  - Taxes : `groupePour` suit l'ordre ligne > groupe d'en-tête (S4.1) > groupe du client (§3.1) > groupe du produit > groupe par défaut ; on retire ensuite les taxes que `produit_taxes` déclare non applicables.
- **Écran** : onglet « Spécification » de la fiche produit (voir §2.1).
- **Droits** : ceux des produits (`produits.modifier`).
- **Tests** : effets par drapeau, exemption de taxe, verrous, isolation.
- **Dépendances** : référentiels Départements, Popularités et Formats (lot Gestion) ; S1.

### S3 — Quantités décimales dans les documents (M)

**1. Ce que fait fripv2** : quantités en décimal(12,4) partout. La saisie est limitée à la précision (0 à 4) de l'unité de stock, de l'unité de vente ou de l'unité fournisseur.

**2. Ce que PI a aujourd'hui** :
- Colonnes `integer` dans `devis_lignes`, `com_client_ligne`, `facture_lignes`, `bon_livraison_lignes`, `com_four_ligne`, `avoir_lignes`, `retours_*`, `reliquats_clients`, `bl_colis_lignes` et `abonnements_clients`.
- Validation `integer|min:1`.
- Le référentiel `unites.decimales` existe déjà.

**3. Ce qu'il faut faire**
- Élargir ces colonnes en `decimal(15,4)` (`->change()`). C'est une **exception documentée** au principe additif : aucune perte de données, compatible avec l'existant.
- Variante strictement additive, plus lourde : des colonnes `*_dec` en miroir.
- Validation `numeric|gt:0`, avec un nombre de décimales ≤ la précision de l'unité de la ligne. Un n° de série impose une quantité entière.
- Retirer les `(int)` des services (`DisponibiliteProduits`, `ClientFicheController::historique`…) et formater les quantités dans les PDF.
- Tests : saisie de 2,5 kg sur devis → commande → facture → BL ; refus de 2,555 si la précision est 2.
- Pièges : application Android, CSV, calculs en unités de stock (facteur).

### S4 — Socle commun des documents de vente (devis, commandes, BL, factures)

#### S4.1 En-tête logistique et commercial (M)

**1. Ce que fait fripv2** (`trans_*_entete`) :
- **Contact** du client, avec son courriel et ses téléphones cliquables. C'est le destinataire proposé à l'envoi.
- **Livrer à** : une adresse du client, avec un bouton + pour en créer une. La **note de livraison** de l'adresse est copiée sur le document et imprimée.
- **Transporteur** (obligatoire). Les frais de livraison du client ne sont ajoutés que si le transporteur coche « frais de livraison applicables ». Changer de transporteur retire ou repose la ligne de frais ; une ligne modifiée à la main n'est pas retouchée.
- **N° de commande client** (30 caractères). Obligatoire si la fiche client exige un numéro de commande, pour la commande, le BL et la facture, mais pas pour le devis ni la note de crédit.
- **No connaissement** (n° de suivi, 30 caractères) : repris de la commande vers la facture et le BL.
- **Condition de paiement** par document, reprise de la fiche client ou du document d'origine.
- **Groupe de taxe d'en-tête** : en changer refait les taxes de toutes les lignes.
- **Date requise**, **délai de livraison** (le plus long des lignes), **poids approximatif** calculé.
- **Statut personnalisé** (référentiel `statut_custom`) : modifiable même sur un document figé, enregistré aussitôt, et filtrable dans la liste.
- **Prioritaire** (étoile).

**2. Ce que PI a aujourd'hui** :
- Client, dates, notes, devise et taux, représentant, coordonnées du client de passage, `date_livraison_souhaitee` (commande), `condition_paiement_id` (facture seulement).
- Le référentiel `statuts_personnalises` existe mais n'est relié à aucun document.
- `transporteurs.url_suivi` existe (lien avec `{numero}`) mais ne sert nulle part.
- Le BL n'a pas de `client_id` : le client vient de sa facture.

**3. Ce qu'il faut faire**
- **Données** sur `devis`, `com_client_entete`, `bons_livraison` et `factures` :
  - `contact_id` ;
  - `adresse_livraison_id` et sa copie (`livraison_nom`, `livraison_adresse`, `livraison_ville`, `livraison_code_postal`, `livraison_pays`, `note_livraison`) ;
  - `transporteur_id`, `numero_commande_client` varchar(30), `numero_suivi` varchar(30) (commande, BL, facture) ;
  - `condition_paiement_id` (devis, commande, BL) ;
  - `groupe_taxe_id` ;
  - `date_requise`, `delai_livraison` varchar(20), `poids_estime` ;
  - `statut_personnalise_id`, `prioritaire`.
  - Sur `bons_livraison` : `client_id`, à reprendre depuis la facture.
  - Sur `transporteurs` : `frais_livraison_applicables` (vrai).
- **Services** : `EntetesVente::pourCreation` propose les valeurs par défaut du client ; les conversions et copies les reprennent ; `FraisDocuments::calculer` tient compte du transporteur.
- **API** : nouveaux champs dans `POST` et `PUT` des quatre documents, plus `PUT /{document}/{id}/statut-personnalise`, permis sur un document figé.
- **PDF** : blocs « Livrer à », « Contact », « Transporteur », « N° commande client », « N° de suivi », « Date requise » et la note de livraison ; lien de suivi dans le courriel.
- **Écran** : sections « Livraison » et « Conditions » dans les quatre formulaires.
- **Droits** :
  - `Droits::PARTICULIERES` : `PUT api/devis/{devis}/statut-personnalise` → `['devis','modifier']`, et de même pour `commandes-client`, `bons-livraison` et `factures`.
  - Le reste suit le CRUD existant.
- **Tests** :
  - valeurs par défaut du client ;
  - 422 sans n° de commande pour un client qui l'exige ;
  - reprise devis → commande → facture → BL ;
  - contact ou adresse d'un autre client ou d'une autre entreprise → 422 ;
  - statut personnalisé posé sur une facture émise.
- **Dépendances** : champs `transporteur_id`, `commande_client_obligatoire` et `groupe_taxe_id` de la fiche client (§3.1).

#### S4.2 Lignes enrichies (G, à découper en deux M)

**1. Ce que fait fripv2** (`trans_*_ligne`) :
- **Type de ligne** : Produit, Entête (gras) ou Texte (sans gras). Bouton « T », menu « Type de ligne », « Insérer une ligne après », touche Inser. Passer un produit en texte suit les règles de la suppression.
- **Description modifiable**, préremplie avec la description longue et la remarque du produit, imprimée.
- **Ordre** : monter ou descendre ; un ensemble se déplace en bloc.
- **% escompte lié au prix de liste** figé à la saisie (`prix_liste`) : saisir un prix recalcule le %, et inversement. Le prix saisi est conservé au cent ; un prix à 0 est respecté.
- **Taxes figées à la saisie.**
- **Note interne par ligne**, jamais imprimée. Deux droits (voir, modifier). Modifiable même sur un document clos. Reportée à la conversion, pas à la copie.
- **Compte de revenu par ligne**, et modification en lot.
- **Balises par ligne**, reprises du produit, et modification en lot.
- **Délai par ligne.**
- **Colonne « Disponible ».**
- **Coûtant et % profit**, sous droit « Voir les coûts et profits des ventes ».
- **« Historique client »** : cocher des produits déjà achetés pour les ajouter.
- **PNI** : créer un produit non inventorié à la volée.
- **Ensemble** : composantes en lignes enfants, prix réparti au prorata.

**2. Ce que PI a aujourd'hui** :
- Lignes : produit (obligatoire), quantité, prix, remise (commande et facture, pas le devis), unité et facteur, `description` (facture seulement).
- Annexes ajoutées d'office.
- Pas de ligne de texte, pas d'ordre, pas de note interne, pas de compte de revenu ni de balise par ligne, pas de disponible, pas de marge.

**3. Ce qu'il faut faire**
- **Données** sur `devis_lignes`, `com_client_ligne`, `facture_lignes` et `bon_livraison_lignes` :
  - `type_ligne` (`produit`, `entete`, `texte`), `gras`, `ordre`, `description` (là où elle manque) ;
  - `remise_ligne` (devis), `prix_liste` décimal(15,4) ;
  - `note_interne` text ;
  - `compte_revenu_id` (dépend de la Comptabilité) ;
  - `delai_livraison`.
  - `produit_id` nullable pour les lignes de texte : modification de colonne, **exception à valider**.
  - Balises de ligne : nouvelles entités `devis_ligne`, `commande_client_ligne` et `facture_ligne` dans `balises.entites`.
- **API** :
  - `PUT /{document}/{id}` accepte `type_ligne`, `ordre` et `description`.
  - `PUT /lignes-documents/{type}/{ligne}/note-interne`, `.../compte-revenu` et `.../balises`, permis sur un document figé.
  - `GET /clients/{id}/historique-achats` existe déjà : à brancher sur un sélecteur multiple.
  - `GET /disponibilites?produits[]=` pour la colonne.
- **Droits** :
  - nouveau module `notes_internes` (onglet ventes, crud `['voir','modifier']`), avec `Droits::MODULES` `'lignes-documents' => 'notes_internes'` pour la note ;
  - nouveau module `marges_ventes` (crud `['voir']`), réglé à sa création comme `valorisation.voir`. Le coût n'est renvoyé par l'API qu'avec ce droit (contrôleur) ;
  - PNI : `produits.creer`.
- **PDF** : lignes de titre en gras, texte, description sur plusieurs lignes coupée proprement en fin de page.
- **Tests** :
  - devis avec un titre, un texte et un produit ;
  - conversion qui conserve l'ordre et les notes internes ;
  - copie sans les notes ;
  - pas de coût sans `marges_ventes.voir` ;
  - isolation.
- **Pièges** : les totaux ignorent les lignes de texte ; les statuts de livraison et de facturation aussi (S4.7) ; l'application mobile.

#### S4.3 « Copier vers » n'importe quel type de document (M)

**1. Ce que fait fripv2** :
- Le menu **Copier ▾** propose Devis, Commande, Bon de livraison ou Facture, depuis n'importe quel document et à tout statut.
- Le document cible est **un brouillon non enregistré**, prérempli tel quel : prix, escomptes, prix de liste, coûtants, taxes, taux de change, délai.
- Les quantités livrées, facturées et en B/O repartent à zéro. Les notes internes ne suivent pas ; les champs personnalisés de même nom suivent.
- Liens « Copié de » et « Copie(s) » dans « Consulter ».
- Chaque option demande le droit du type cible.

**2. Ce que PI a aujourd'hui** : `POST /devis/{id}/copier` (devis vers devis, enregistré tout de suite) et les conversions devis → commande ou facture. Pas de copie de commande, de BL ni de facture.

**3. Ce qu'il faut faire**
- **API** : `GET /{document}/{id}/copie?vers=devis|commande|bon_livraison|facture` renvoie une charge utile sans rien écrire. La création se fait par le `POST` habituel du type cible, avec `copie_de: {type, id}`.
- **Données** : table `documents_copies` (`tenant_id`, `source_type`, `source_id`, `cible_type`, `cible_id`, `created_at`).
- **Écran** : menu Copier dans les quatre écrans ; « Consulter » liste les liens.
- **Droits** : lecture libre dans le module ; le `POST` cible est contrôlé par le module cible (`*.creer`).
- **Tests** : facture → nouvelle facture, même total ; commande → BL ; refus sans le droit cible ; isolation.

#### S4.4 Notes de bas de page par document (M ; l'écran de paramétrage relève du lot Gestion)

**1. Ce que fait fripv2** (`note_bas_page`) :
- Textes enrichis par type de document (devis, commande, BL, facture, état de compte, rappel, commande fournisseur, demande d'acompte, carte cadeau, contrat) et par langue. Une note par défaut par type et par langue. Case actif.
- Choisie à l'impression et dans la fenêtre du courriel : le texte s'insère avant la signature.
- **Mémorisée sur le document** (id et copie figée) : une réimpression ressort la même note.
- Imprimée sur une page ajoutée en fin de document.
- Choix automatique possible selon un champ personnalisé du document.

**2. Ce que PI a aujourd'hui** : une seule ligne `entreprise.mentions` en pied de page.

**3. Ce qu'il faut faire**
- **Données** :
  - `notes_bas_page` (`tenant_id`, `nom`, `document`, `langue`, `contenu`, `par_defaut`, `actif`) ;
  - sur chaque document : `note_bas_page_id` et `note_bas_page_texte`, copie figée au premier envoi ou à la première impression.
- **API** :
  - CRUD `/notes-bas-page`, avec `Droits::MODULES` `'notes-bas-page' => 'configuration'` ;
  - `GET /{document}/{id}/pdf?note_bas_page_id=` ;
  - sélection de la note dans `ComposerCourriel`.
- **PDF** : page supplémentaire dans `DocumentsVentePdf` et dans le bon de commande.
- **Tests** : note par défaut selon la langue du client ; choix mémorisé ; « (Aucune) » mémorisé aussi.
- **Dépendance** : langue du client (§3.1).

#### S4.5 Identité figée à l'émission et mention « Réimpression » (M)

**1. Ce que fait fripv2** :
- À l'émission, le document retient :
  - le n° du client et ses numéros de taxes ;
  - les libellés de la condition de paiement, du transporteur et du représentant ;
  - le contact ;
  - la route et la séquence ;
  - les coordonnées et numéros de taxes de l'entreprise (`compagnie_snapshot`).
- `nb_impressions` : à partir de la 2ᵉ copie réelle (papier, PDF ou courriel envoyé), le document porte la mention « Réimpression ».

**2. Ce que PI a aujourd'hui** : les PDF sont construits à partir des données courantes (`EntreprisePdf`, fiche client).

**3. Ce qu'il faut faire**
- Table `entreprise_snapshots` (`tenant_id`, `donnees` JSON, `empreinte` unique).
- Colonnes `entreprise_snapshot_id`, `client_code_origine`, `client_identifiants_origine` JSON, `condition_paiement_libelle`, `transporteur_libelle` et `representant_nom` : remplies à l'émission (facture, note de crédit) ou à l'envoi (devis, commande, BL).
- `factures.nb_impressions`.
- Le PDF lit la copie figée si elle existe, sinon les données courantes.
- Tests : renommer le client après l'émission ne change pas le PDF.

#### S4.6 Historique et commentaires (M)

**1. Ce que fait fripv2** :
- Bouton « Historique » sur chaque fiche et chaque document, sous le droit « Voir l'historique des modifications » : avant/après champ par champ.
- Panneau « Commentaires » : commentaires signés et horodatés, avec un badge des non-lus.

**2. Ce que PI a aujourd'hui** :
- `GET /audit/{modelType}/{modelId}` existe, alimenté par `JournalModifications`, mais seule `AuditPage` s'en sert.
- Pas de commentaires. Les remarques n'existent que sur les fiches client et fournisseur.

**3. Ce qu'il faut faire**
- Composant web `BoutonHistorique` (droit `historique.voir`) sur les fiches produit, client et fournisseur et sur tous les documents.
- **Commentaires** :
  - tables `commentaires` (`tenant_id`, `entite`, `entite_id`, `auteur_id`, `texte`) et `commentaire_lectures` ;
  - API `GET`, `POST` et `DELETE /commentaires?entite=&entite_id=` ;
  - le contrôleur vérifie le droit de voir l'entité, car le préfixe n'est pas dans `Droits::MODULES`.
- Tests : commentaire invisible depuis une autre entreprise ; non-lus par usager.

#### S4.7 Liste des documents : statuts calculés, filtres, aperçu des lignes, export (M)

**1. Ce que fait fripv2** (`f_Commande`, liste commune aux quatre types avec un filtre Type) :
- **Statut de livraison** calculé : Non-livré, En attente de chargement, En livraison, Livré partiel, Livré complet, Rien à livrer. Les services et les frais sont ignorés.
- **Statut de facturation** : Non-facturé, Facturé partiel, Facturé complet, Facturé (brouillon) ; Brouillon et Annulée pour une facture.
- Pour les factures : **statut de paiement**, **âge** (courant, 1 à 30, plus de 30, plus de 60, plus de 90 jours) et **type de montant**.
- Filtres : statut personnalisé, **produit** (documents qui le contiennent), panneau de **champs personnalisés**, commandes web, étoile prioritaire.
- Bouton **B/O client**.
- Ligne dépliable : lignes, sous-total, taxes, total, et « suite du document » (les documents issus de celui-ci).
- **Export Excel** de toutes les pages ; tri naturel.

**2. Ce que PI a aujourd'hui** : quatre pages distinctes, filtrées par statut, client, représentant, période et recherche. Pas de statuts calculés, pas de filtre produit ni champ personnalisé, pas d'export, pas d'aperçu des lignes.

**3. Ce qu'il faut faire**
- Service `StatutsDocumentsVente`, en agrégats SQL sur les quantités livrées et facturées (S3, §3.7).
- Paramètres de liste : `statut_livraison`, `statut_facturation`, `statut_paiement`, `age`, `produit_id`, `statut_personnalise_id`, `prioritaire`, `champs[id]=valeur`, `format=xlsx` (`App\Support\ClasseurXlsx`).
- `PUT /{document}/{id}/prioritaire`, contrôlé par `*.modifier`.
- Page unifiée facultative `/ventes/documents`.
- Tests : un document qui ne contient que des services → « Rien à livrer » ; le filtre produit ; l'export contient toutes les pages.

### S5 — Export Excel des listes (P, transverse)

**1. Ce que fait fripv2** : chaque liste exporte vers Excel toutes ses pages, filtres compris.

**2. Ce que PI a aujourd'hui** :
- Produits : CSV de 5 colonnes, construit dans le navigateur.
- Clients, fournisseurs, contrats, abonnements, B/O, retours, suggestions et escomptes : aucun export.

**3. Ce qu'il faut faire** : paramètre `format=xlsx` sur les `GET` de liste, avec `ClasseurXlsx`, et un bouton « Exporter » sur chaque liste. Mêmes droits que la lecture. Test : nombre de lignes égal au total filtré.

---

## 2. Inventaire / Entrepôt

### 2.1 Produits — **Partiel**

**Ce que PI a :**
- **Liste** `ProduitsPage`. Tous les produits sont chargés dans le navigateur.
  - Filtres : type texte, secteur, fabricant, actif ou désactivé.
  - Recherche : numéro, description, référence fabricant, codes-barres.
  - Export CSV de 5 colonnes ; import CSV par `POST` successifs.
- **Fiche en fenêtre modale** :
  - numéro (50), description (255), secteur (obligatoire), unité, type texte, prix de base ;
  - fiche enrichie : fabricant, référence, description longue, traductions fr/en, image principale ;
  - suivi par lot ou série, avec péremption ;
  - conditionnement : unité d'achat et quantité par unité ;
  - transport : poids, volume, n° ONU, classe, groupe d'emballage.
- **Fenêtres séparées** : codes-barres (`/produits/{id}/codes-barres`), unités de vente (`/unites`), ensembles, annexes et équivalents (`/liens`).
- **Boutons** : pièces jointes, champs personnalisés, balises, étiquettes, suggérer l'achat, désactiver ou réactiver.
- **Il n'y a pas de page `/produits/:id`.**

#### P1 — Fiche produit à onglets et onglet Général (M)

**1. fripv2**
- Une fiche à 14 onglets : Général, Quantités, Spécification, Prix, Fournisseurs, Équivalences, Client, Localisations, Ensembles/Annexes, Images, Champs personnalisés, puis trois onglets en consultation (Documents de vente, Commandes fournisseur, Réceptions). Chaque onglet peut être masqué par un droit.
- **Onglet Général :**
  - numéro (30, mis en majuscules, unique) ;
  - **catégorie** (obligatoire) et **sous-catégorie** (référentiel) ;
  - manufacturier ;
  - description (100) ;
  - **description courte** (30), générée d'après les 30 premiers caractères de la description sans couper de mot ;
  - **remarque** en texte enrichi, **imprimée sous la description des lignes de document**, une par langue ;
  - **type Inventorié ou Non-inventorié** (obligatoire). Changer le type d'un produit qui a du stock demande une confirmation.
- À l'enregistrement, les onglets sont validés dans l'ordre ; au premier échec, l'onglet fautif s'affiche avec le curseur sur le champ.

**2. PI** : une modale à une seule section. La catégorie est un texte libre (`produits.categorie`) alors qu'un référentiel `categories` hiérarchique existe. Pas de description courte, pas de remarque.

**3. À faire**
- **Données** :
  - `produits.categorie_id` et `sous_categorie_id` (référentiel `categories`), `description_courte` (30), `remarque` (text) ;
  - `produit_traductions.remarque` ;
  - l'ancienne colonne texte `categorie` est alimentée en miroir, parce que `regles_prix.categorie_produit`, les révisions et le filtre du portail la lisent.
- **API** : `GET /produits/{id}` enrichi (relations).
- **Écran** : page `/produits/:id` à onglets. La modale reste pour la création rapide.
- **Droits** : actions `produits.prix` et `produits.fournisseurs` (base `modifier`) pour masquer les onglets Prix et Fournisseurs à qui ne les a pas.
- **Tests** : description courte générée ; catégorie d'une autre entreprise → 422.
- **Pièges** : remplir `categorie_id` depuis le texte existant (correspondance par nom).

#### P2 — Nature, options, taxes et frais du produit

Couvert par **S2** : onglet « Spécification » de la fiche (département, popularité, format, cases, taxes applicables, frais applicables avec leur portée).

#### P3 — Quantités, seuils et verrous (M ; dépend de S1)

**1. fripv2**
- **Seuils** : quantité minimale et maximale d'inventaire décimal(12,4), avec une **saison du minimum** (« MM/JJ » de début et de fin).
- **Précision de l'unité** (0 à 4).
- **Poids et volume avec leur unité.**
- **Quantités calculées**, en lecture seule :
  - en main, réservée, en B/O client, en commande fournisseur, commandée par les clients ;
  - ajustée ;
  - disponible ;
  - valeur d'inventaire ;
  - dates de la dernière facture et de la dernière commande fournisseur ;
  - « En consignation ».
- Unité de stock verrouillée dès qu'il y a du stock ou une commande fournisseur en cours.
- Il faut au moins une unité de vente, toutes distinctes ; la première devient la principale par défaut.

**2. PI**
- `seuil_alerte` et `stock_maximum` se règlent dans Alertes, pas dans la fiche.
- Pas de saison, pas de précision par produit.
- Les quantités sont dispersées : `DisponibiliteProduits` donne le stock, le réservé, le disponible, l'« en commande » et les reliquats ; la valorisation est ailleurs.

**3. À faire**
- **Données** : `produits.seuil_min_debut` et `seuil_min_fin` (char(5) « MM-JJ »), `precision_quantite` (tinyint).
- **API** : `GET /produits/{id}/quantites`. Il agrège `DisponibiliteProduits`, S1 (ou l'inventaire officiel et les scans si S1 est inactif), les consignations et les dates des derniers documents.
- **Règles** : la saison s'applique dans `SuggestionsAchat` et dans les alertes ; verrou de l'unité (422).
- **Écran** : onglet Quantités, avec les unités de vente intégrées (la `FenetreUnitesProduit` existante).
- **Tests** : produit saisonnier hors période → pas de suggestion ; verrou de l'unité.

#### P4 — Prix par liste avec formules et coûtants (G)

**1. fripv2** (`description_prix`, `produit_prix`)
- **Listes** : ordre, précision de 0 à 6, libellé traduit.
- **Pour chaque produit et chaque liste** : un prix fixe décimal(14,6) **ou** une formule = référence (une autre liste, le **dernier coûtant** ou le **coûtant moyen**) + opérateur + facteur décimal(12,4) :

  | Opérateur | Calcul |
  |---|---|
  | + | réf × (1 + f/100) |
  | − | réf × (1 − f/100) |
  | × | réf × f/100 |
  | ÷ | réf ÷ (f/100) |

- Recalcul en cascade ; **référence circulaire refusée**.
- À l'ouverture de la fiche, recalcul si les coûtants ont changé.
- Les coûtants (dernier, moyen) s'affichent ; leur montant n'est modifiable qu'avec « Créer, modifier, supprimer des produits ».
- La fiche client indique sa liste de base (`prix_base`).

**2. PI** (FLOW-PRIX)
- `listes_prix` : nom, `ajustement_pourcentage` appliqué au prix de base, précision de 0 à 4.
- `listes_prix_produits` : prix saisi.
- Il n'existe ni liste calculée depuis une autre liste, ni liste fondée sur un coût, ni coûtant stocké. FLOW-PRIX §5 le note : « pas de chaîne de listes ».

**3. À faire**
- **Données** :
  - sur `listes_prix` : `reference` (`base`, `liste`, `dernier_cout`, `cout_moyen`), `reference_liste_id` et `operateur`. L'ajustement actuel devient le cas « base, opérateur + ».
  - sur `listes_prix_produits` : `formule_reference`, `formule_liste_id`, `formule_operateur`, `formule_facteur` et `prix` nullable quand une formule s'applique.
  - les coûtants viennent de S1 (`produits.cout_moyen`, `dernier_cout`). Sans S1, `dernier_cout` est mis à jour à la réception (coût réel) dans `ReceptionsFournisseur`.
- **Services** :
  - `CalculPrix::prixListe()` : saisi > formule du produit > formule de la liste ;
  - tri topologique et détection de cycle (422 « Référence circulaire entre listes »).
  - Tâche ou job `RecalculerPrixFormules`, déclenché par une réception, une modification de grille ou l'application d'une révision.
- **API** : `PUT /grille-prix` accepte les champs de formule ; `GET /produits/{id}/prix`.
- **Écran** : onglet Prix (liste, prix, référence, opérateur, facteur) et colonnes de formule dans `GrillePrix`.
- **Droits** : `gestion_prix.modifier` ; correction directe d'un coûtant par l'action `produits.couts` (base `modifier`).
- **Tests** : cascade A → B → C ; cycle refusé ; arrondi par précision ; recalcul après réception ; isolation.
- **Pièges** :
  - recalcul massif à mettre en file ;
  - devise (coûts en devise de l'entreprise) ;
  - prix par unité de vente (`produit_unites.prix`) ;
  - les révisions doivent marquer les lignes à formule en lecture seule (§2.5).

#### P5 — Fournisseurs vus depuis le produit (M)

**1. fripv2** (`produit_fournisseur`)
- Colonnes : fournisseur, référence (50), **SKU ou code-barres fournisseur** (50), **dates du dernier achat et de la dernière vente** (automatiques), **dernier coûtant unitaire** décimal(14,6), **dernière vente unitaire**, délai (jours), unité d'achat, **précision**, facteur, **frais à l'achat** (vide = montant du frais, 0 = aucun), principal (un seul).
- Au moins un fournisseur si le produit est acheté.
- Unicité fournisseur + unité d'achat.
- **Facteur verrouillé** tant qu'une commande fournisseur reste à recevoir.
- Si une réception change le dernier coûtant pendant la saisie, la fiche demande d'être rechargée.

**2. PI** : `produit_fournisseur` (référence, `prix_achat` convenu, délai, principal, unité d'achat, quantité par unité), géré **uniquement depuis la fiche fournisseur** (`FournisseurFicheController::produits`). Le dernier prix payé se calcule (`ReliquatsClients::dernierPrix`).

**3. À faire**
- **Données** sur `produit_fournisseur` : `code_barres_fournisseur` (50), `date_dernier_achat`, `date_derniere_vente`, `dernier_cout` décimal(15,6), `derniere_vente_prix`, `precision`, `frais_achat` décimal(12,4) nullable.
- **API** : `GET`, `POST`, `PUT` et `DELETE /produits/{id}/fournisseurs[/{lien}]`, partageant le service avec la fiche fournisseur.
- **Mises à jour automatiques** à la réception (date, coût) et à l'émission d'une facture (date et prix de dernière vente).
- **Droits** : `Droits::PARTICULIERES` `POST|PUT|DELETE api/produits/{produit}/fournisseurs[...]` → `['produits','modifier']`.
- **Tests** : facteur verrouillé si une commande est ouverte ; un seul fournisseur principal ; isolation.

#### P6 — Localisations possibles et stock par emplacement (M ; dépend de S1)

**1. fripv2**
- Tableau des **localisations possibles** du produit, dont une seule zone de retour fournisseur.
- Tableau des **endroits localisés** : quantité, code-barres, série, lot, fournisseur ; colonnes ajoutables (réception, réservé, client en consignation, prix de vente).
- Correction d'un exemplaire (code-barres unique, série, lot, spécification, coûtant), **enregistrée aussitôt et historisée**.
- Suppression refusée si la localisation est utilisée ou contient du stock.
- Onglet masqué pour un service.

**2. PI** : un seul `produits.secteur_id`, obligatoire. Le stock par secteur vient des scans (`StocksEntrepots`).

**3. À faire**
- **Données** : `produit_emplacements` (`tenant_id`, `produit_id`, `secteur_id`, `principal`). `produits.secteur_id` reste l'emplacement principal.
- **API** : `GET`, `POST` et `DELETE /produits/{id}/emplacements` et `GET /produits/{id}/stock` (S1). Si S1 est inactif : scans et inventaire officiel.
- **Écran** : onglet Localisations.
- **Droits** : `produits.modifier`.

#### P7 — Images multiples et ordonnées (P)

**1. fripv2** : plusieurs images (`produit_image` : fichier, description, ordre), aperçu de trois images, la première est l'image principale, diffusion sur le portail.

**2. PI** : une seule image principale (`produits.image_id`, une pièce jointe).

**3. À faire** :
- `produit_images` (`tenant_id`, `produit_id`, `piece_jointe_id`, `description`, `ordre`) ;
- `PUT /produits/{id}/images/ordre` ;
- galerie dans le catalogue du portail ;
- tests : un SVG refusé ; isolation.

#### P8 — Onglets de consultation et ligne dépliable de la liste (M)

**1. fripv2**
- Onglets en lecture seule de la fiche : **Documents de vente**, **Commandes fournisseur**, **Réceptions**, filtrés sur le produit. Le filtre produit est verrouillé ; ils se chargent à la demande.
- Dans la liste, chaque ligne se déplie en onglets :
  - Stock par localisation, avec le réservé, le disponible et la consignation ;
  - Fournisseurs, Unités, Prix (avec la formule), Escomptes ;
  - Équivalences, Composantes, Annexes ;
  - Mouvements ;
  - **Ventes 12 mois** : quantité, montant, nombre de factures par mois, mois vides affichés, total et moyenne sur 12 mois, ventes nettes des retours et des notes de crédit ;
  - **Ventes** (factures) ;
  - **Révisions de prix en attente**.

**2. PI** : rien de tel par produit. La Traçabilité montre les mouvements et les scans ; les historiques d'achat sont par client ou par fournisseur.

**3. À faire** :
- API `GET /produits/{id}/documents-vente`, `/commandes-fournisseur`, `/receptions`, `/ventes-mensuelles`, `/revisions-en-attente` et `/mouvements` (S1). Lecture contrôlée par les droits de chaque module source dans le contrôleur.
- Écran : onglets de la fiche et ligne dépliable de la liste.
- Tests : ventes nettes des notes de crédit ; isolation.

#### P9 — Copier un produit et produit générique de catégorie (M)

**1. fripv2**
- **Copier** ouvre une nouvelle fiche préremplie, non enregistrée.
  - Repris : descriptions dans toutes les langues, catégorie, remarque, type, unités, seuils, poids et volume, spécifications, taxes et frais, balises, prix et formules, fournisseurs (sans leurs dates ni coûts), équivalences, prix par client, ensembles, annexes, champs personnalisés. **Les coûtants sont repris exprès.**
  - Vidés : numéro, code-barres unique, stock (emplacements à 0), quantités calculées, images, historique.
- **Produit générique** : modèle configuré sur la catégorie ; à la création dans cette catégorie, fripv2 propose de recopier ce modèle.

**2. PI** : rien.

**3. À faire** :
- `GET /produits/{id}/copie` (charge utile) ;
- `categories.produit_modele_id` ;
- le formulaire propose la copie au choix de la catégorie ;
- droit `produits.creer` ;
- test : la copie n'emporte ni stock ni images ; numéro unique.

#### P10 — Liste : pagination, recherche, favoris, filtres, colonnes, export (M)

**1. fripv2**
- **Pagination serveur** (100, 500 ou 1000 lignes par page).
- Recherche multi-champs classée : exact, puis début, puis contenu ; un code-barres complet suffit.
- **Favoris par usager** et filtre « Favoris seulement ».
- Filtres : catégorie (sous-catégories comprises), Inventorié, Statut, « Services seulement », filtres par colonne.
- Tri multi-colonnes et naturel.
- Colonnes ajoutables : sous-catégorie, manufacturier, unité de vente, fournisseur principal et son dernier coûtant, prix par liste, champs personnalisés.
- **Export Excel complet.**

**2. PI** : tout le catalogue est chargé (`GET /produits` → `->get()`) ; filtres côté navigateur ; export CSV de 5 colonnes ; pas de favoris.

**3. À faire** :
- `GET /produits?page=&per_page=&recherche=&categorie_id=&stocke=&service=&favoris=&tri=&format=xlsx`, en gardant la réponse non paginée sans `page`, pour l'application mobile ;
- table `produit_favoris` et routes `POST` / `DELETE /produits/{id}/favori` (à ajouter à `Droits::LIBRES`) ;
- tests : pagination ; favoris par usager ; isolation.

#### P11 — Onglet « Client » : références par client (P)

**1. fripv2** : client, n° de produit chez lui (30), description (100) et **unité de vente propre au client**. Le numéro et la description sont obligatoires.

**2. PI** : `/clients/{id}/references-produits` (référence, description), depuis la fiche client seulement, sans unité.

**3. À faire** :
- `client_references_produits.unite` ;
- vue côté produit `GET /produits/{id}/references-clients` ;
- l'unité est reprise sur les lignes du client.

#### P12 — Prix des items spécifiques (M ; dépend des lots et séries, et de S1)

**1. fripv2** : pour un produit suivi pièce par pièce, une liste des exemplaires (code-barres, série, lot, spécification, emplacement, quantité, coûtant). Chaque exemplaire peut recevoir un **prix de vente propre** et un coûtant corrigé. Le tableau du bas montre les documents traversés (réception, commande, BL, facture).

**2. PI** : registres des lots et des n° de série, sans prix propre ni coûtant par exemplaire.

**3. À faire** :
- `numeros_serie.prix_vente`, `cout` ; `lots.prix_vente` ;
- `GET /produits/{id}/exemplaires` (documents traversés) ;
- le prix de l'exemplaire est proposé quand on le choisit sur une ligne (S4.2) ;
- droit `produits.modifier`.

#### P13 — Historique et navigation Précédent / Suivant (P)

Voir **S4.6**. Ajouter « Précédent / Suivant » qui parcourt la liste filtrée et garde l'onglet ouvert.

---

### 2.2 Ajustements d'inventaire — **Manquant**

**Preuves PI** : `MouvementTenantController::store`, type `ajustement`, dans `RelocalisationPage`. La quantité doit être > 0 (pas de retrait), le motif est libre, aucun solde n'est touché.

**1. fripv2** (`f_AjustementInventaire`)
- **Recherche** : produit (numéro ou code-barres ; sélecteur s'il y a plusieurs résultats), produit contenu (contenant), entrepôt. **Quantité** pour un produit unitaire (série, code-barres unique, lot ou spécification) : génère N lignes vides.
- **Grille** des lignes de stock :
  - localisation (choisie seulement sur une nouvelle ligne) ;
  - code-barres (unique, y compris entre lignes, boîtes et stock) ;
  - spécification, produit inclus, série, lot ;
  - fournisseur (le principal par défaut) ;
  - quantité, en lecture seule ;
  - **ajustement ±** (la touche « − » inverse le signe ; seulement 0 ou −1 sur un produit unitaire) ;
  - total ;
  - ligne Totaux.
- **Contrôles** : localisation obligatoire si l'ajustement ≠ 0 ; identifiants exigés selon le suivi ; **total jamais négatif** ; somme égale à la quantité saisie pour un produit unitaire.
- Une transaction par ligne : les lignes à 0 sont ignorées.
- **Chaque ligne** écrit un mouvement : raison « Ajustement d'inventaire », quantité avant, coûtant moyen × variation, usager, écran.
- La consignation et la zone de retour fournisseur sont exclues.
- **Droit** « Ajuster les quantités en stock ».

**2. PI** : voir les preuves ci-dessus. Pas de choix de raison issu du référentiel `raisons` (pourtant de type `ajustement`), pas de lot ni de série, pas de coût.

**3. À faire** (dépend de **S1** ; sans S1, l'écran est masqué ou n'écrit que dans `mouvement_relocalisation`)
- **API** :
  - `GET /ajustements-stock/lignes?produit_id=&entrepot_id=` : soldes par emplacement, lot et série ;
  - `POST /ajustements-stock` : `produit_id`, `lignes[]` (`emplacement_type`, `emplacement_id`, `lot_id` ou `lot_numero`, `numero_serie`, `fournisseur_id`, `ajustement`), `raison_id` (obligatoire si l'entreprise a des raisons de type `ajustement`), `note`.
  - Tout ou rien : c'est plus sûr que la transaction par ligne de fripv2.
- **Service** : `MouvementsStock::ajuster`. Un lot ou un n° de série inconnu est créé à la hausse (`LotsSeries`).
- **Écran** : `/ajustements-stock`, entrée de menu dans `navigation.ts` (catégorie inventaire, module `ajustements_stock`).
- **Droits** :
  - module `ajustements_stock` (onglet inventaire, crud `['voir','creer']`) ;
  - `Droits::MODULES` et `LECTURES` : `'ajustements-stock' => 'ajustements_stock'` ;
  - migration : chaque rôle reçoit les droits qu'il a sur `relocalisation`.
- **Traductions** : « Une quantité négative n'est pas autorisée. », « Veuillez choisir une localisation. », « Tous les codes-barres doivent être uniques. », etc.
- **Tests** : hausse et baisse ; négatif → 422 ; n° de série exigé ; raison obligatoire ; isolation ; 403.
- **Taille M.**

### 2.3 Relocalisation — **Partiel**

**Preuves PI** : `/relocalisation` (`MouvementTenantController` : `store`, `par-secteur`, `arrivage-lot`) et `RelocalisationPage`. Les seuils d'approbation et la planification existent : c'est un plus de PI.

**1. fripv2** (`f_RelocaliserInventaire`)
- **Mode « Par produit »** : toutes les localisations du produit sont listées ; destination par défaut, modifiable ligne par ligne ; fournisseur modifiable.
- **Mode « Par localisation »** : on vide un emplacement source vers une destination unique ; les quantités sont **préremplies au total**.
- Filtre entrepôt ; produit contenu ; passage automatique au contenant pour un contenu (gaz).
- **Contrôles** : destination obligatoire ; destination ≠ source ; déplacement ≤ quantité ; jamais de négatif. Consignation et retour fournisseur exclus.
- Deux mouvements par déplacement (raison « Relocalisation »), avec code-barres, lot, série et fournisseur conservés.
- Regroupement à la destination.
- Droit « Déplacer du stock et charger les camions ».

**2. PI**
- Saisie libre : produit, secteur source, secteur destination (texte), quantité.
- Aucun contrôle de quantité, aucune liste de ce que contient la source, aucun lot ni série, pas de destination par ligne, pas de pré-remplissage.

**3. À faire** (dépend de **S1**)
- **API** :
  - `GET /relocalisation/contenu?secteur_id=` et `?produit_id=` : soldes ;
  - `POST /relocalisation/deplacer` : `lignes[]` (source, destination, lot, série, quantité).
- **Service** : `MouvementsStock::deplacer`, en gardant le passage par les approbations (`SeuilsApprobation`).
- **Écran** : deux onglets « Par produit » et « Par emplacement » dans `RelocalisationPage`.
- **Droits** : module `relocalisation` existant (`Droits::MODULES` couvre déjà `relocalisation`).
- **Tests** : quantité insuffisante → 422 ; vider un emplacement ; lot conservé ; isolation.
- **Taille M.** Sans S1, l'écran reste tel quel.

### 2.4 Mouvements inventaire — **Partiel**

**Preuves PI** :
- historique de `mouvement_relocalisation` (`GET /relocalisation?page=`) ;
- `TracabiliteController`, qui fusionne mouvements et scans ;
- `GET /mouvements-inventaire` (`LocalisationController::mouvements`, sur `MouvementVente`) n'a **aucun écran**.

**1. fripv2** (`f_MouvementInventaire`)
- Écran **en consultation seule**.
- **Filtres** : recherche libre sur toutes les colonnes, raison, localisation, usager, produit (numéro ou code-barres), dates (30 derniers jours par défaut), filtres par colonne.
- **Colonnes** : date et heure, raison, n° de produit, description, code-barres, localisation, **quantité précédente**, **mouvement ±**, **quantité après**, **coûtant** (figé au moment du mouvement), **n° de document**, usager. Colonne ajoutable : client de consignation.
- Pagination ; export Excel complet ; annulation d'un chargement long.
- Droit « Voir les mouvements d'inventaire ».

**2. PI** : voir les preuves ci-dessus. Pas de raison issue d'un référentiel, pas de quantités avant et après, pas de coût, pas de document, pas d'export.

**3. À faire** (dépend de **S1**)
- `GET /mouvements-stock?raison=&emplacement=&user_id=&produit_id=&du=&au=&recherche=&format=xlsx`.
- La colonne coût n'est renvoyée qu'avec `valorisation.voir`.
- Écran `/mouvements-stock`.
- **Droits** : module `mouvements_stock` (inventaire, crud `['voir']`), `Droits::LECTURES` `'mouvements-stock' => 'mouvements_stock'`, migration calquée sur `historique`.
- **Tests** : filtres ; coût masqué sans le droit ; isolation.
- **Taille M.**

### 2.5 Révision de prix planifiée — **Fait (écarts mineurs)**

**Preuves PI** : `/revisions-prix*` (`PrixRevisionController`, `RevisionsPrix`, tâche `AppliquerRevisionsPrix`), onglet Révisions de `GestionPrixPage`, droit `gestion_prix.reviser`. PI conserve en plus le prix remplacé et accepte plusieurs ajustements par révision.

#### RV1 — Chaîne de formules (P ; dépend de P4)

1. **fripv2** : les lignes « Formule : Oui » sont en lecture seule et recalculées **en chaîne le jour de l'application**, à partir des prix les plus récents (liste de référence, coûtants).
2. **PI** : seules les listes « prix de base ± % » suivent la base (« Listes qui suivent la formule »).
3. **À faire** : une fois P4 fait, marquer `revision_prix_lignes.est_formule` et recalculer à l'application avec `CalculPrix`. Test d'une chaîne A → B → C.

#### RV2 — Révisions en attente visibles depuis le produit (P)

1. **fripv2** : onglet « Révisions de prix » de la ligne dépliable : nom, date d'effet, prix actuel, prix prévu.
2. **PI** : visibles seulement dans l'onglet Révisions.
3. **À faire** : `GET /produits/{id}/revisions-en-attente` (lecture `gestion_prix.voir`), affiché dans P8.

### 2.6 Importer une grille de prix — **Partiel**

**Preuves PI** : `POST /fournisseurs/import-prix` (`ImportPrixFournisseurs`), profils `/profils-import`, composant `fournisseurs/ImportGrillePrix`, droit `fournisseurs.importer_prix`. PI offre en plus une **simulation** préalable.

#### IG1 — Fichiers .xlsx (P)

1. **fripv2** : `.xlsx` ou `.csv`, séparateur détecté, aperçu des premières lignes.
2. **PI** : CSV seulement. `ClasseurXlsx` sait écrire mais pas lire.
3. **À faire** : un lecteur xlsx minimal (`ZipArchive`, `sharedStrings`, `sheet1`) dans `App\Support\LecteurXlsx`. Tests avec un fichier .xlsx et une erreur de format.

#### IG2 — « Mettre à jour aussi le coûtant du produit » (P ; dépend de P4 et S1)

1. **fripv2** : si la case est cochée **et** que le fournisseur de la ligne est le **principal**, le coûtant du produit est mis à jour et les prix à formule sont recalculés.
2. **PI** : seul `produit_fournisseur.prix_achat` change.
3. **À faire** : paramètre `maj_cout_produit` dans l'import, qui écrit `produits.dernier_cout` puis lance le recalcul P4. Test : un fournisseur non principal laisse le coût intact.

#### IG3 — Colonne « Référence » et accès depuis Inventaire (P)

1. **fripv2** : une colonne libre facultative, mémorisée sur la ligne et dans le journal. L'écran est rangé sous **Inventaire → Produits**.
2. **PI** : pas de colonne de référence. L'accès se fait par un bouton de la liste des fournisseurs.
3. **À faire** : `colonne_reference` dans le profil et le journal ; entrée de menu supplémentaire dans la catégorie inventaire (module `fournisseurs`, action `importer_prix`).

### 2.7 Prise d'inventaire — **Partiel**

**Preuves PI** :
- `/prises-inventaire*` (`PriseInventaireController`, `PrisesInventaire`), pages `PrisesInventairePage` et `PriseInventairePage`, droit `inventaires.prises` ;
- sessions par secteurs ou pour tous ; comptage = scans de la période ;
- correction de quantité avec une raison ; validation = inventaire officiel ; annulation ; export CSV.

#### PR1 — Théorique figé et ajustement du stock à la clôture (M ; dépend de S1)

1. **fripv2**
   - À la création, une ligne par produit ayant du stock dans la localisation (et ses sous-localisations si demandé), avec la **quantité originale figée**.
   - « Quantité mouvement » : mouvements survenus depuis le début.
   - « Différence » = réel − (original + mouvement).
   - **Terminer** applique les ajustements de stock et fixe la date de fin.
2. **PI** : la référence est le dernier inventaire validé ; les mouvements sont informatifs ; la validation ne touche aucun stock.
3. **À faire**
   - En mode S1 : `prises_inventaire_lignes.quantite_originale` (copie des soldes à la création) et `quantite_mouvements` (somme de `stock_mouvements` depuis le début).
   - `valider` appelle `MouvementsStock::ajuster` avec la raison « prise d'inventaire » et le numéro de la prise.
   - Sans S1 : comportement actuel.
   - Tests : vente pendant la prise, écart juste ; ajustements créés ; isolation.

#### PR2 — Écart valorisé (P)

1. **fripv2** : « Différence coûtant » = écart × coût moyen, par ligne et au total.
2. **PI** : écarts en quantités seulement.
3. **À faire** : `valeur_ecart` calculée avec `ValorisationInventaire` (méthode choisie) ; visible seulement avec `valorisation.voir`.

#### PR3 — Détail des scans par ligne et source (P)

1. **fripv2** : la grille du bas montre chaque scan de la ligne (date, usager, tablette, quantité). La colonne « Source » indique Scan ou Manuel. Commentaire par ligne.
2. **PI** : `nb_scans` seulement. La correction porte une raison.
3. **À faire** : `GET /prises-inventaire/{id}/lignes/{ligne}/scans` ; `source` dans la réponse ; `commentaire` sur les corrections.

#### PR4 — Comparer deux prises : prise de différences (M)

1. **fripv2** : sur une prise **terminée**, « Comparer » avec une autre prise de la même localisation montre un aperçu des produits différents, puis crée une prise « -D1, -D2… » limitée à ces produits pour les recompter.
2. **PI** : rien.
3. **À faire** :
   - `POST /prises-inventaire/{id}/comparer` (`autre_id`) ;
   - colonnes `prises_inventaire.parent_id` et `produits_limites` (JSON) ;
   - droit `inventaires.prises` ;
   - tests : seuls les produits différents ; même périmètre de secteurs.

#### PR5 — Supprimer une prise vide, filtres et ligne dépliable (P)

1. **fripv2**
   - Suppression d'une prise en cours **sans aucune quantité**, sinon refus.
   - Filtres : statut (En cours par défaut), localisation (sous-localisations comprises), dates.
   - Ligne dépliable : produit, localisation, attendu, compté, écart (en rouge si non nul).
2. **PI** : annulation seulement ; liste sans aperçu.
3. **À faire** : `DELETE /prises-inventaire/{id}` (422 dès qu'un scan ou une correction existe), routé vers `inventaires.prises` dans `Droits::PARTICULIERES` ; filtres de liste ; aperçu des lignes.

### 2.8 Rapport prise inventaire — **Partiel**

**Preuves PI** : `GET /prises-inventaire/{id}/export`, CSV des écarts avec filtres. Il n'y a pas de PDF.

#### RP1 — Feuille de comptage PDF (P)

1. **fripv2** :
   - en-tête : entreprise, date, n° de prise, rappel des critères ;
   - produits regroupés **par localisation**, chemin complet, puis par numéro ;
   - code-barres et « No série / No lot » ;
   - case **Quantité vide**, **sans la quantité théorique** ;
   - une ligne sur deux ombrée ;
   - dernière page « **Nouveau produit** » : 40 lignes vides (localisation, produit, code-barres, quantité) ;
   - sorties : écran, PDF, imprimante, courriel.
2. **PI** : rien.
3. **À faire** : `GET /prises-inventaire/{id}/feuille-comptage?format=pdf` (vue `pdf/feuille-comptage`). Sans S1, les produits viennent de l'inventaire officiel du secteur et des scans connus. Envoi par courriel avec `BoutonCourriel`. Lecture `inventaires.voir`.

#### RP2 — Rapport des différences en PDF ou Excel (P)

1. **fripv2** : regroupement par **localisation** ou par **produit** ; colonnes Qté orig., Qté mouv., Qté comptée, Source, Diff. qté, **Diff. $ coûtant**, commentaire ; filtres mémorisés ; Excel.
2. **PI** : CSV non regroupé, sans valeur.
3. **À faire** : `GET /prises-inventaire/{id}/differences?regroupement=secteur|produit&format=pdf|xlsx`, avec PR2 pour la valeur.

### 2.9 Consignations chez les clients — **Fait (écarts mineurs)**

**Preuves PI** : `GET /consignations/rapport` (client, quantités nulles, PDF paysage), `ConsignationsPdf`, `ConsignationsPage`.

**Écarts :**
1. **fripv2** : filtre **catégorie de produits** ; sorties **Excel** et **courriel** ; la marchandise consignée **compte dans la valeur d'inventaire et sort du disponible** (case dans la valeur d'inventaire).
2. **PI** : ni catégorie, ni Excel, ni courriel. La consignation est un registre à part : elle n'est ni dans `ValorisationInventaire` ni retirée du disponible.
3. **À faire** (P) :
   - paramètres `categorie_id` et `format=xlsx`, plus le courriel ;
   - option `inclure_consignation` dans la valorisation (somme des soldes `consignation_mouvements` × coût) ;
   - S1 : emplacement « consignation », retiré du disponible.

---

## 3. Vente / Facturation

### 3.1 Clients — **Partiel**

**Ce que PI a :**
- **Liste** `ClientsPage` : recherche, catégorie, favoris, représentant, filtres mémorisés.
- **Fiche** `FicheClientPage` : Synthèse, Historique d'achats, Contacts, Adresses, Remarques, Références produits, catégorie, favori.
- **Champs** :
  - code, raison sociale ;
  - adresses de facturation et de livraison, ville, code postal ;
  - téléphone, courriel, contact (nom, téléphone) ;
  - encours maximum, remise globale ;
  - condition de paiement, devise ;
  - frais de livraison ;
  - route et rang ;
  - catégorie, représentant ;
  - actif, de passage.
- **Liste de prix** : `PUT /clients/{id}/liste-prix`.
- **Comptes portail par client** : nom, courriel, langue. C'est l'équivalent des identifiants web par contact de fripv2 : **fait**.

#### C1 — Onglet « Dossier » et champs d'identité (M)

**1. fripv2** (`client`)
- **Prospect / Client** (obligatoire).
- **Client à facturer** : un autre client reçoit les factures (facturation au siège), vérifié à la sortie du champ.
- **Site web** (100), **télécopieur** (15).
- **Langue des documents** (obligatoire).
- **Groupe de taxes** (obligatoire : TPS+TVQ, TVH, exempt…).
- **N° de taxe provinciale et fédérale** (25 chacun).
- **Transporteur** et **territoire**.
- **Groupe d'escompte** : un autre client dont les ententes s'appliquent.
- **État de compte** : par courriel ou imprimé.
- **Date de début d'affaires** (aujourd'hui par défaut).
- **Rappel de paiement** (coché par défaut) et **fréquence** : hebdomadaire, aux 2 semaines, mensuelle (7, 14 ou 30 jours, avec la date du dernier rappel).
- **Commande client obligatoire**.
- **Mot de passe portail**, qui sert aussi à débloquer un dépassement de limite de crédit.
- Le tout historisé.

**2. PI** : aucun de ces champs. La catégorie de clients couvre déjà le « groupe d'escompte » au sens des règles de prix.

**3. À faire**
- **Données** sur `clients` :
  - `prospect` (faux), `client_facture_id` ;
  - `site_web`, `telecopieur`, `langue` (fr, en, et la liste de l'entreprise) ;
  - `groupe_taxe_id`, `transporteur_id`, `territoire_id` ;
  - `date_debut_affaires` ;
  - `etat_compte_envoi` (`courriel` ou `imprime`) ;
  - `rappel_paiement` (vrai), `frequence_rappel_jours` (7, 14 ou 30), `dernier_rappel_le` ;
  - `commande_client_obligatoire` (faux).
- **Identifiants fiscaux, de façon générique (multi-pays)** :
  - table `tiers_identifiants` (`tenant_id`, `entite` client ou fournisseur, `entite_id`, `type`, `valeur`) ;
  - `type` vient d'un référentiel `types_identifiants` : TPS, TVQ, SIRET, TVA intracommunautaire, NIU, RCCM…
- **Effets** :
  - facture adressée et rattachée au client à facturer (comptes clients) ;
  - langue des PDF et des courriels ;
  - groupe de taxe proposé (S4.1, S2) ;
  - la tâche `relances_paiement` respecte l'opt-out et la fréquence par client ;
  - S4.1 : n° de commande obligatoire.
- **Écran** : onglet Dossier de la fiche ; ces champs sortent du formulaire de la liste.
- **Droits** :
  - `clients.modifier` ;
  - masquage de l'onglet par l'action `clients.dossier` (base `voir`) si l'on veut le restreindre.
- **Tests** : facture d'une succursale envoyée au siège ; relances selon la fréquence ; groupe de taxe appliqué ; isolation (un client à facturer d'une autre entreprise → 422).

#### C2 — Prospects (M)

1. **fripv2** : un prospect est refusé partout (ventes, encaissements, contrats, escomptes, routes, commandes fournisseur, rapports) **sauf dans le devis**. Accepter son devis le fait passer client. Filtre Client / Prospect dans la liste.
2. **PI** : la notion n'existe pas.
3. **À faire** :
   - règle de validation partagée `ClientUtilisable` (422 « Ce client est un prospect : acceptez d'abord un devis. ») ;
   - `ReponsesDevis` et `DevisController::accepter` passent `prospect = false` ;
   - filtre de liste ;
   - tests sur chaque point d'entrée.

#### C3 — Coordonnées bancaires du client (P/M)

1. **fripv2** : « Transfert électronique activé », institution (3), transit (5), compte. **Chiffrées.** Historique sans les valeurs. Droit « Voir et modifier l'info bancaire client ».
2. **PI** : rien côté client. Côté fournisseur, `fournisseur_comptes_bancaires` existe : IBAN, BIC, local, chiffrés.
3. **À faire** :
   - table `client_comptes_bancaires`, sur le modèle des fournisseurs ;
   - contrôleur via `FicheElementController` ;
   - action `clients.comptes_bancaires` (base `modifier`) ;
   - `Droits::PARTICULIERES` pour les `POST`, `PUT` et `DELETE` ;
   - `Droits::LECTURES` sur le préfixe dédié `clients/{client}/comptes-bancaires`, par une règle particulière en `GET`.

#### C4 — Comptes GL des revenus par client (P ; dépend de la Comptabilité)

1. **fripv2** : grille de comptes de revenus, sans doublon, ligne vide refusée.
2. **PI** : la comptabilisation passe par des réglages généraux.
3. **À faire** : `client_comptes_revenus`, utilisé par `Comptabilisation` comme compte par défaut des lignes. Droit `comptabilite.plan`.

#### C5 — Onglets de consultation de la fiche (M)

1. **fripv2**
   - **Tableau de bord** : cinq tableaux (Devis, Commandes, Factures, Comptes à recevoir, Encaissements) sur les colonnes **En cours / Mois / Année / Tous**, plus un **camembert de l'âge** (courant, 30, 60, 90 jours et plus).
   - **Comptes à recevoir** : relevé combiné et export.
   - **Encaissements** : par facture et « sans facture », deux totaux, impression.
   - **Documents de vente.**
   - **Acomptes** : disponible, utilisé, remboursé, « réservé à » ; détail de l'utilisation ; demandes d'acompte.
   - **Habitudes de paiement** : nombre d'encaissements et délai moyen.
2. **PI** : la Synthèse donne le CA de l'année et de l'année précédente, le panier, l'encours, les retards, les commandes et devis en cours, 12 mois et le top 5. Le relevé est dans « Comptes clients ». Les acomptes et encaissements sont des écrans séparés.
3. **À faire** :
   - `GET /clients/{id}/tableau-bord`, `/releve` (réutilise `ComptesClients::releve`), `/encaissements`, `/documents`, `/acomptes` ;
   - délai moyen calculé à la volée (date de paiement − date d'émission, acomptes bornés à 0) ;
   - lectures contrôlées dans le contrôleur par les droits `comptes_clients`, `encaissements` et `acomptes` (données sensibles).

#### C6 — Contacts enrichis (P)

1. **fripv2** :
   - nom (50), téléphone (20) et **poste** (10), cellulaire (20), courriel (100), fonction (50) ;
   - **catégories de contact** (plusieurs choix, référentiel) ;
   - cases : envoi des factures, **envoi de l'état de compte**, envoi des commandes ;
   - actif ;
   - le contact principal ne se supprime pas tant qu'un autre ne l'a pas remplacé.
2. **PI** : fonction, deux téléphones, courriel, principal, reçoit les factures, reçoit les commandes, notes, actif.
3. **À faire** :
   - `client_contacts.poste_telephone`, `recoit_etats_compte` ;
   - table `contact_categories` (référentiel du lot Gestion) avec un pivot ;
   - destinataires de l'état de compte et des relances ;
   - refus de supprimer le contact principal.

#### C7 — Adresses enrichies (M)

1. **fripv2** :
   - description (30), n° civique (15), code postal (15, avec recherche automatique), rue (100), seconde ligne (100) ;
   - ville (créée à la volée), province, pays ;
   - **livraison** ;
   - **route** et **séquence de route** (6 chiffres) **par adresse** ;
   - **note** imprimée sur les documents et les commandes fournisseur ;
   - **traductions** de la description ;
   - l'adresse principale ne se supprime pas.
2. **PI** : libellé, adresse en un bloc, ville, code postal, région, pays, livraison, principale, instructions, actif. La route et le rang sont sur le **client**.
3. **À faire** :
   - `client_adresses.numero_civique`, `adresse_ligne2`, `itineraire_id`, `sequence_livraison` ;
   - copie de la note sur les documents (S4.1) ;
   - `SequencesLivraison` lit d'abord l'adresse de livraison du BL, puis celle du client (voir §3.14).

#### C8 — Liste : colonnes, type, export (P)

1. **fripv2** : colonnes adresse principale, représentant, **territoire**, devise ; filtre Type ; export Excel ; tri multi-colonnes.
2. **PI** : pas de type, pas de territoire, pas d'export.
3. **À faire** : paramètres `prospect` et `format=xlsx` (S5), colonnes ajoutées.

#### C9 — Synthèse IA du client (P, facultatif, module `ia`)

1. **fripv2** : résumé rédigé par l'IA (profil, habitudes, finances, opportunités, vigilance) : régénérer, imprimer, PDF, courriel.
2. **PI** : le module `ia` existe pour les rapports.
3. **À faire** : `POST /clients/{id}/synthese-ia`, limité en débit ; action `clients.synthese_ia` (base `voir`) ; ne sort que les données que le compte peut voir.

#### C10 — Onglet « Prix client »

Voir **§3.2 E3**.

#### C11 — Historique et navigation

Voir **S4.6**.

### 3.2 Escomptes — **Partiel**

**Preuves PI** : `regles_prix` (Tarifs & Prix → Règles, `ReglesPrix.tsx`, `CalculPrix`).
- Portée : client, catégorie de clients, liste, produit, catégorie de produits en texte.
- Quantité minimale entière, période, prix fixe, remise en % ou en montant, actif.
- Le prix le plus bas l'emporte.

#### E1 — Cibles et quantité (M)

1. **fripv2** (`escompte`) : client, **fournisseur** (le fournisseur **principal** du produit), produit, catégorie (**y compris comme sous-catégorie**), **popularité**. « Tous » sur chaque axe. Quantité minimale décimal(12,4). Prospect et produit inactif refusés.
2. **PI** : ni fournisseur, ni popularité, ni sous-catégories ; quantité entière ; catégorie en texte.
3. **À faire** :
   - `regles_prix.fournisseur_id`, `popularite_id`, `categorie_id` (en plus du texte) ;
   - `quantite_min` en décimal (`->change()` ou une nouvelle colonne `quantite_min_dec`) ;
   - `CalculPrix` : correspondance par fournisseur principal et par lignée de catégorie ;
   - tests.

#### E2 — Prix par formule et prix coûtant négocié (M ; dépend de P4)

1. **fripv2** :
   - **Prix vendant** fixe (décimales des prix) **ou** formule : liste de référence + opérateur (+ − × ÷) + facteur en %, exclusifs l'un de l'autre.
   - Même chose pour un **prix coûtant** (coût d'achat négocié).
   - Contrôles : date de fin ≥ début ; prix ou formule obligatoire ; opérateur obligatoire avec une formule ; facteur ≠ 0 en division.
2. **PI** : prix fixe, % ou montant appliqué au prix **de la liste du client**. Pas de référence à une autre liste, pas de coûtant.
3. **À faire** :
   - `regles_prix.formule_liste_id`, `formule_operateur`, `formule_facteur` ;
   - `cout_fixe`, `cout_formule_*` ;
   - le coûtant négocié alimente la marge (S4.2) et le prix proposé en commande fournisseur (§4.5 CF1) quand l'escompte vise un fournisseur.

#### E3 — Écran « Escomptes » et onglet « Prix client » (P)

1. **fripv2** :
   - écran sous Vente → Clientèle : liste avec statut (Tous, Actif, Inactif), recherche (« tous » trouve les axes ouverts), export Excel, historique de chaque escompte ;
   - droits distincts **Ajouter / Modifier / Supprimer un escompte** ;
   - onglet « Prix client » de la fiche client : création avec le **client figé**.
2. **PI** : les règles sont dans Finance → Tarifs & Prix. Pas d'onglet dans la fiche client.
3. **À faire** :
   - entrée de menu `/escomptes` dans la catégorie Ventes (module `gestion_prix`) ;
   - onglet de la fiche client qui appelle `GET /regles-prix?client_id=`, avec une création préremplie ;
   - droits : `gestion_prix.creer`, `modifier` et `supprimer` (déjà mappés par `regles-prix`).

**Note** : le « groupe d'escompte » de fripv2 est déjà couvert par les **règles par catégorie de clients** de PI (FLOW-PRIX §3).

### 3.3 Contrats de location — **Partiel**

**Preuves PI** : `/contrats-location*` (`ContratsLocation`, `ContratLocationPdf`, tâches `FacturerLocations` et `RenouvelerContrats`), `ContratsLocationPage`, référentiel `types_location`, droits `contrats_location.facturer` et `renouveler`.

#### CT1 — Formules de prix par ligne et listes du type de location (M ; dépend de P4)

1. **fripv2** (`contrat_ligne`, `location_type`) :
   - le **prix de contrat** et le **prix de location** se calculent par formule (liste de référence + opérateur + facteur %), sinon ils sont saisis ;
   - ils sont préremplis depuis la **liste « prix du contrat »** et la **liste « prix de location »** du type, à défaut la liste « location contenant » de l'entreprise ;
   - le renouvellement recalcule les lignes à formule ;
   - un produit déjà au contrat garde son prix de location (un seul taux par produit) ;
   - quantité ≠ 0 ; prix de contrat ≠ 0 ; prix de location > 0 pour un contrat de contenants.
2. **PI** : prix de contrat et loyer saisis à la main ; types sans listes de prix.
3. **À faire** :
   - `types_location.liste_prix_contrat_id`, `liste_prix_location_id`, `nature` (`materiel` ou `contenant`) ;
   - `contrat_location_lignes.formule_*` pour le contrat et la location ;
   - préremplissage et recalcul au renouvellement ;
   - tests.

#### CT2 — Rythmes du type de location (M)

1. **fripv2** :
   - **fréquence de calcul** : journalière, hebdomadaire, 14 jours, mensuelle, trimestrielle, semestrielle, annuelle, horaire ;
   - **fréquence d'émission** : à la création, **au retour**, le ##ᵉ jour du mois, **tous les ### jours après le début**, le premier jour du mois, le dernier jour du mois ;
   - prochaine facturation calculée en conséquence ;
   - un contrat ne mélange pas les natures ;
   - seuls les produits marqués « location » (ou « location contenant ») sont acceptés.
2. **PI** : mensuelle, trimestrielle, semestrielle, annuelle ; jour 1 à 28 ; à échoir ou à terme échu. Les produits ne sont pas filtrés.
3. **À faire** :
   - `types_location.frequence_calcul` (énumération étendue), `mode_emission`, `ieme_jour`, `jours_apres_debut` ;
   - adapter `ContratsLocation::prochaineFacturation` ;
   - filtrer les produits par `produits.location` (S2) ;
   - tests par mode (dont la facturation « au retour » sur `POST .../retour`).

#### CT3 — Date de renouvellement distincte (P)

1. **fripv2** : `date_renouvellement`, qui reprend la date de fin quand celle-ci change. Vide : jamais proposé au renouvellement. Taux de hausse décimal(5,2).
2. **PI** : le renouvellement se fait à la date de fin.
3. **À faire** : `contrats_location.date_renouvellement`, lu par `RenouvelerContrats`.

#### CT4 — Renouvellement en lot avec grille de travail (M)

1. **fripv2** (`f_RenouvellementContratCylindre`) :
   - période, client, n° de contrat, **% d'ajustement contrat et location** ;
   - grille : qté contrat, **non facturé**, **qté en main**, **moyenne 12 mois**, qté exemptée, prix actuels et nouveaux, durée, auto, **écart de quantité** ;
   - lignes manuelles (clients sans contrat) ;
   - grilles **enregistrées sous un nom** puis « Création contrats », qui crée les contrats et leurs **factures brouillons** en tout ou rien ;
   - une grille déjà finalisée ne se rejoue pas ; export.
2. **PI** : renouvellement un par un, ou automatique.
3. **À faire** :
   - tables `renouvellements_contrats` et `renouvellement_contrat_lignes` ;
   - `POST /contrats-location/renouvellements`, `PUT`, `POST .../creer` ;
   - `Droits::PARTICULIERES` → `['contrats_location','renouveler']` ;
   - la « qté en main » dépend de §3.4 L1.

#### CT5 — Frais, annulation de la facture, liste (P)

1. **fripv2** :
   - la facture du contrat porte les frais additionnels ;
   - annuler la facture du contrat rend ses lignes modifiables et facturables ;
   - liste : filtre « date de fin d'ici le… », export Excel avec le détail, historique.
2. **PI** : la ligne facturée est figée ; aucun lien avec l'annulation, puisque les factures se suppriment. Filtre `fin_avant` présent.
3. **À faire** :
   - `FraisDocuments` sur la facture du contrat ;
   - quand une facture est supprimée ou annulée (§3.9 F2), remettre `facture_id = null` sur ses lignes de contrat ;
   - export (S5).

### 3.4 Facturation des loyers mensuels — **Partiel (modèle différent)**

**Preuves PI** : `POST /contrats-location/facturer-loyers` (`jusquau`, `client_id`, `emettre`) et la tâche `facturation_locations`. Le loyer est facturé par période : unités louées − exemptées − rendues.

#### L1 — Solde d'actifs retournables chez le client (M/G ; dépend de S1 et S2)

1. **fripv2** (`produit_circulation`, `client_solde_cylindre`, `surplus_location`) : un relevé **jour par jour** des contenants **livrés** et **retournés** et de la quantité **en main** chez chaque client, pour les produits « location contenant ». Il est alimenté par les livraisons et les retours.
2. **PI** : seuls les retours sont saisis sur la ligne de contrat. Les livraisons de contenants ne sont pas suivies par client.
3. **À faire** :
   - table `actifs_circulation` (`tenant_id`, `client_id`, `produit_id`, `date`, `livre`, `retourne`, `document_type`, `document_id`) ;
   - alimentée par la livraison d'un BL (produits `location = contenant`), les retours clients et le ramassage ;
   - vue des soldes par jour ;
   - tests.

#### L2 — Calcul mensuel du surplus et grille d'aperçu (M)

1. **fripv2** :
   - **Année / Mois**, le mois précédent par défaut, puis **Calculer**.
   - Grille : client, nom, produit, description, **balance précédente**, achat (livré), retourné, nouvelle balance, **quantité sous contrat** (moyenne des jours), **sous contrat O/N**, **facturé** = Σ des surplus journaliers (en main − (qté + exemptée des contrats « contenant » actifs ce jour-là)), **taux**, total, statut.
   - **Taux** : prix de location de la ligne de contrat. Si plusieurs lignes existent, celle du contrat commencé le plus tard, puis la ligne la plus récente. Sinon, la liste « location contenant » de l'entreprise comparée aux escomptes du client : le plus bas l'emporte. Sinon 0.
2. **PI** : facturation par contrat, sans surplus et sans aperçu.
3. **À faire** :
   - `GET /contrats-location/loyers-mensuels?annee=&mois=` (lecture `contrats_location.voir`) ;
   - service `LoyersMensuels` ;
   - réglage `tenant_parametres.liste_prix_location_id`.

#### L3 — Génération idempotente par mois (M)

1. **fripv2** :
   - « Générer factures » n'est actif qu'après le calcul ;
   - **une facture brouillon par client**, datée du **dernier jour du mois** ;
   - mois facturé mémorisé (la date requise) ;
   - un client **déjà facturé pour ce mois, même facture annulée**, est ignoré ;
   - les frais additionnels s'appliquent, sauf ceux décochés « s'applique aux loyers » ;
   - récapitulatif : créées, ignorées, erreurs ;
   - clic sur « Facturé » pour ouvrir la facture.
2. **PI** : bilan sans contrôle par mois.
3. **À faire** :
   - `factures.type_facture` (§3.9 F8) et `periode_facturee` (date) ;
   - `POST /contrats-location/loyers-mensuels/facturer` (`annee`, `mois`) ;
   - `Droits::PARTICULIERES` → `['contrats_location','facturer']`, plus `factures.creer` vérifié dans le contrôleur ;
   - `frais.sur_loyers` (vrai) ;
   - tests : relance sans doublon ; client sans contrat facturé au taux de la liste ; isolation.

### 3.5 Gestion des B/O client — **Fait (écarts mineurs)**

**Preuves PI** : `/reliquats-clients*` (`ReliquatsClients`, `ReliquatsClientsPage`) : noter, commander, recevoir, facturer, annuler, et le suivi automatique par les réceptions.

#### BO1 — Reliquat depuis une ligne de facture et colonne « Qté B/O » (M)

1. **fripv2** :
   - colonne Qté B/O sur les lignes de commande **et de facture**, qui crée ou retire le B/O ;
   - cellule verrouillée dès que le B/O est engagé ;
   - quantité exclue du reste à facturer ;
   - sans « facturer plus qu'en main », l'excédent commandé passe **d'office** en B/O.
2. **PI** : reliquats depuis une commande acceptée ou saisis à la main.
3. **À faire** :
   - `reliquats_clients.facture_ligne_id` ;
   - champ `quantite_bo` dans le `PUT` des commandes et factures (S4.2) ;
   - réglage `tenant_parametres.facturer_au_dela_stock` (faux) ;
   - tests.

#### BO2 — Saisie de la réception à la main, filtres, couleurs (P)

1. **fripv2** : la quantité reçue et la date reçue se modifient à la main ; filtre par **document** (commande ou facture) ; lignes colorées selon leur statut.
2. **PI** : seul un reliquat « inscrit » se modifie ; filtre par commande.
3. **À faire** : permettre de modifier `quantite_recue` et `date_recue` (`reliquats_clients.modifier`) ; filtre `facture_id` ; couleur de statut (référentiel).

### 3.6 Devis — **Partiel**

**Preuves PI** : `/devis*` (`DevisController`, `DevisPage`) : créer, modifier (brouillon), envoyer, accepter ou refuser, convertir en commande ou en facture, copier, lien de réponse en ligne, relances, PDF, courriel, représentant, client de passage.

#### DE1 — Taxes sur le devis (M)

1. **fripv2** : groupe de taxe d'en-tête (obligatoire), taxes par ligne, sous-total, chaque taxe, total TTC, au PDF comme à l'écran. Taxes figées à la saisie.
2. **PI** : « Un devis ne porte pas de taxe » (`DevisController`) : elles ne sont calculées qu'à la conversion.
3. **À faire** :
   - `devis_lignes.taux_tva`, `groupe_taxe_id`, `montant_tva` et `montant_ttc` ; `devis.montant_ht`, `montant_tva` et `montant_ttc` ;
   - `TaxesDocument::enregistrer('devis', …)` ;
   - la conversion reprend les taxes au lieu de les recalculer ;
   - PDF et page en ligne (`DevisEnLigneController`) ;
   - tests.

#### DE2 — Modification jusqu'à la réponse et total figé (P)

1. **fripv2** : un devis Brouillon **ou Envoyé** se modifie. Il est figé une fois accepté, refusé ou converti : prix, frais et total ne changent plus, avec un avertissement d'écart si un recalcul diffère. « Marquer comme envoyé » sans courriel.
2. **PI** : modification en brouillon seulement. Le bouton Envoyer fait déjà « marquer envoyé ».
3. **À faire** : permettre le `PUT` au statut `envoye`. Si une réponse en ligne arrive pendant la modification, l'enregistrement est refusé (422).

#### DE3 — Expiration non bloquante, au choix (P)

1. **fripv2** : « Expiré » est un badge calculé ; le devis reste convertible. Un avertissement unique s'affiche à l'ouverture d'un devis envoyé resté sans réponse. Durée 0 = jamais expiré. Durée par défaut dans la fiche entreprise.
2. **PI** : statut `expire` qui bloque l'acceptation (422).
3. **À faire** : réglages `tenant_parametres.devis_expiration_bloquante` (vrai, comportement actuel) et `devis_validite_jours`.

#### DE4 — En-tête, lignes, copie, notes, liens

- **S4.1** : contact, adresse, transporteur, condition de paiement, date requise, délai, statut personnalisé.
- **S4.2** : titres et textes, description, remise, prix de liste, note interne, disponible, historique client, PNI, item spécifique.
- **S4.3** : copier vers commande, BL ou facture.
- **S4.4, S4.5, S4.6.**
- **C2** : prospect promu client à l'acceptation.
- « Consulter » : commande, facture, demande d'acompte, copies.

### 3.7 Commandes — **Partiel**

**Preuves PI** :
- `/commandes-client*` (`CommandeClientController`, `CommandesClientPage`) ;
- statuts brouillon → en attente → acceptée ou refusée ;
- modification en brouillon ; acompte ; reliquats ; manques à l'acceptation ;
- facture depuis une commande acceptée : **une seule** (`creerDepuisCommande` refuse si `$commande->facture`) ;
- PDF et courriel.

#### CO1 — Cycle En cours, Complétée, Annulée (M)

1. **fripv2** :
   - « En cours » : la date et l'heure deviennent celles du moment ; les prix ne sont pas recalculés ; préférence « naître en cours ».
   - **Compléter** : la commande est figée, sauf la livraison du reste.
   - **Annuler commande** : fenêtre de raison, ajoutée à la note.
     - Refusée s'il existe un BL actif, une facture brouillon, des quantités livrées ou facturées (hors services) ou un B/O engagé.
     - Ne libère ni l'acompte ni les exemplaires.
     - Statut terminal.
2. **PI** : pas d'annulation ni de clôture ; refus seulement en attente.
3. **À faire** :
   - statuts `en_cours`, `completee`, `annulee` ; colonnes `motif_annulation`, `annulee_le`, `annulee_par`, `completee_le` ;
   - le circuit d'approbation de PI est conservé (`acceptee` vaut « en cours ») ;
   - `POST /commandes-client/{id}/annuler` et `/completer` ;
   - actions `commandes_client.annuler` et `completer` (base `modifier`) dans `Droits::PARTICULIERES` ;
   - tests des refus.

#### CO2 — Modifier une commande acceptée (M)

1. **fripv2** : une commande « En cours » se modifie jusqu'à ce qu'elle soit complétée ou annulée. Les contrôles portent sur les quantités livrées, facturées et en B/O.
2. **PI** : `PUT` refusé hors brouillon.
3. **À faire** :
   - `PUT` permis en `acceptee` si la commande n'est pas facturée en entier ;
   - on ne descend pas sous le livré ou le facturé ;
   - recalcul des réservations (`DisponibiliteProduits`) et des reliquats ;
   - verrou (`verrou:commande_client`) ;
   - tests.

#### CO3 — Suivi livré et facturé par ligne (M)

1. **fripv2** : colonnes Quantité, **Qté B/O**, **Déjà livrée**, **Qté livrée** (livraison directe depuis la commande s'il n'y a pas de BL actif), **Déjà facturée**, **Disponible** ; badges de livraison et de facturation.
2. **PI** : rien par ligne.
3. **À faire** :
   - `com_client_ligne.quantite_livree`, `quantite_facturee` ;
   - mises à jour par les BL (§3.8) et les factures émises ;
   - S4.7 pour les statuts ;
   - tests.

#### CO4 — Facturation partielle et multiple (M)

1. **fripv2** :
   - **En facture** propose « Le livré » ou « Tout » ;
   - facture brouillon de ce qui **reste à facturer** ;
   - **une seule facture brouillon à la fois** ; refus si la commande est payée en entier ;
   - total facturé ≤ commandé ;
   - commande « Complétée » quand tout (hors B/O) est facturé et porté.
2. **PI** : une seule facture par commande, et pour tout.
3. **À faire** :
   - `POST /commandes-client/{id}/facturer` (`mode`: `livre` ou `tout`), avec `Droits::PARTICULIERES` → `['factures','creer']` ;
   - `facture_lignes.com_client_ligne_id` ;
   - garder l'ancienne route ;
   - tests.

#### CO5 — Bon de livraison depuis la commande

Voir **§3.8 BL1**.

#### CO6 — « Tout livrer » sans BL (M ; dépend de S1)

1. **fripv2** : livre d'un coup le reste à livrer ; demande l'emplacement si le produit en a plusieurs ; nomme les lignes au stock insuffisant ; seulement s'il n'y a pas de BL actif.
2. **PI** : rien.
3. **À faire** : `POST /commandes-client/{id}/livrer-tout` → `['bons_livraison','livrer']`. Il crée un BL « livré complet » (traçabilité, lots et séries), et les sorties de stock si S1 est actif.

#### CO7 — En-tête, lignes, copie, liens

Voir **S4.1 à S4.7**. « Consulter » : devis, factures, BL, B/O, acomptes, demandes d'acompte, copies.

### 3.8 Bons de livraison — **Partiel**

**Preuves PI** :
- `/bons-livraison*` (`BonLivraisonController`, `BonsLivraisonPage`) ;
- création uniquement par `POST /factures/{id}/creer-bl` ;
- statuts créé → en préparation → prêt → en livraison → livré complet ou partiel → annulé ;
- colis, signature, lots et séries, courriel, PDF ;
- facture « du reste » automatique.

#### BL1 — BL depuis la commande et BL manuel (M/G)

1. **fripv2** :
   - Le BL se crée **depuis la commande** (Imprimer › Bon de livraison) au statut « En attente », avec ce qui reste à livrer.
   - **Un seul BL actif par commande.** En créer un nouveau annule les précédents s'ils sont encore en attente ; c'est refusé dès qu'un BL a dépassé ce stade.
   - **BL manuel** sans commande : client ou client de passage, produits, transporteur, condition.
   - Un BL manuel passe « En cours », puis **En facture** (facture brouillon), puis « Complété » à l'émission de la facture.
   - Un BL issu d'une commande ne se facture pas : c'est la commande qui porte la facturation.
2. **PI** : BL seulement depuis une facture émise. `bons_livraison` n'a pas de `client_id` ni de `commande_id`.
3. **À faire**
   - **Données** :
     - `bons_livraison.client_id` (reprise depuis la facture), `commande_id`, `origine` (`facture`, `commande`, `manuel`) ;
     - `bon_livraison_lignes.com_client_ligne_id` ;
     - statut `brouillon` pour un BL manuel.
   - **API** :
     - `POST /commandes-client/{id}/bons-livraison` → `['bons_livraison','creer']` ;
     - `POST /bons-livraison` (manuel) ;
     - `POST /bons-livraison/{id}/facturer` → `['factures','creer']`.
   - **Réglage** `tenant_parametres.flux_livraison` : `facture` (actuel) ou `commande`.
   - **Tests** : un seul BL actif ; refus après préparation ; facturation d'un BL manuel ; isolation.
   - **Pièges** : `LotsSeries::livrer` et `MouvementVente` lisent le client par `$bon->facture` : passer à `client_id`.

#### BL2 — Livraison partielle : relivrer ou abandonner le reste (M)

1. **fripv2** :
   - Un BL « Livré partiel » repart **En livraison** pour le reste, avec le **même bon**.
   - **Abandonner le reste à livrer** ramène les quantités du BL et de la commande au livré (taxes et total de la commande refaits) et passe le BL en « Livré complet ».
   - Cet abandon est refusé si le BL vient d'une facture ou si la commande est déjà facturée : il faut alors une note de crédit.
2. **PI** : une livraison partielle **crée automatiquement une facture brouillon du reste** (`genererFactureReste`). La facture d'origine, émise, porte déjà toute la quantité : **risque de double facturation**. De plus, le numéro est consommé au brouillon.
3. **À faire** :
   - `POST /bons-livraison/{id}/repartir` et `/abandonner-reste` → `['bons_livraison','livrer']` ;
   - avec `flux_livraison = commande`, pas de facture de reste (la commande facture le livré) ;
   - avec le flux actuel, remplacer la facture de reste par une **note de crédit proposée** (`AvoirController::proposition`) ou par un reliquat ;
   - tests de non-double facturation.

#### BL3 — Annulation avec raison (P)

1. **fripv2** : « Raison annulation », aux statuts Brouillon, En attente et En cours. Refusée tant qu'une quantité est livrée ou facturée : on la remet d'abord en négatif. Le contenu des boîtes retourne à sa place.
2. **PI** : annulation sans raison, aux statuts « créé » et « en préparation ».
3. **À faire** : `motif_annulation`, `annule_par` et `annule_le` ; les colis sont supprimés et, avec S1, leur contenu revient à l'origine.

#### BL4 — En-tête et colonnes (M)

1. **fripv2** : client, contact, adresse et note de livraison, transporteur (obligatoire), condition, n° de commande client, statut personnalisé, date requise, délai, poids, groupe de taxe et devise, note. Colonnes Quantité, **Déjà livrée**, **Qté livrée**, **Déjà facturée**, **Disponible**, format, délai, balises, note interne. Pas de prix.
2. **PI** : `mode_livraison`, notes de livraison, quantités à livrer, préparées et livrées.
3. **À faire** : S4.1 et S4.2, plus une colonne « Déjà facturée » (pour un BL manuel).

#### BL5 — Impressions et copie (P)

1. **fripv2** : Imprimer › Bon de livraison, **Rapport de dangerosité** (du camion), **Boîtes**, **Étiquettes**. Copier vers devis, commande, BL ou facture.
2. **PI** : PDF du BL et étiquettes de colis ; le document des matières dangereuses existe par tournée.
3. **À faire** : bordereau de contenu des colis (§3.12 PB3) ; `GET /bons-livraison/{id}/matieres-dangereuses` ; S4.3.

### 3.9 Factures — **Partiel**

**Preuves PI** : `/factures*` (`FactureController`, `FacturesVente`, `FacturesPage`) :
- créer (manuellement ou depuis une commande), émettre, supprimer un brouillon ;
- paiement, échéancier, escompte, relances ;
- notes de crédit (`/avoirs`), retours clients, acomptes ;
- BL ; PDF ; courriel ; devises ; représentant.

#### F1 — Modifier une facture brouillon (M) — demandé

1. **fripv2** :
   - **Un brouillon se modifie librement** : en-tête, lignes, quantités, prix, escomptes, échéance, champs personnalisés.
   - Une facture « Facturée » est **figée**, sauf :
     - les quantités livrées, s'il reste à livrer ;
     - la case « Payable par plateforme web », s'il reste un solde ;
     - le statut personnalisé et les champs personnalisés.
   - La devise et le groupe de taxe ne se changent qu'à la création.
2. **PI** : **pas de `PUT /factures/{id}`.** Pour corriger, il faut supprimer et refaire, ce qui laisse un trou de numérotation.
3. **À faire**
   - **API** : `PUT /factures/{id}`, brouillon seulement (422 sinon), sous `->middleware('verrou:facture,id')`.
     - Mêmes règles que le `store`, plus `lignes.*.id` pour mettre à jour, ajouter ou retirer des lignes.
     - Recalcul des taxes (`TaxesDocument`), des frais et de l'échéance.
   - **Droits** : rien à ajouter, `PUT` sur le préfixe `factures` → `factures.modifier` (`Droits::regle`).
   - **Service** : `FacturesVente::modifier(Facture, array)`, qui réutilise `calculerLignes`.
   - **Écran** : bouton « Modifier » sur un brouillon, qui ouvre le formulaire de création prérempli.
   - **Traductions** : « Seules les factures en brouillon peuvent être modifiées. »
   - **Tests** : modification des lignes et recalcul ; 422 si émise ; 403 sans `factures.modifier` ; 404 d'une autre entreprise ; verrou.
   - **Pièges** : protéger les lignes liées dans un brouillon :
     - une **échéance d'abonnement** (`abonnement_echeances.facture_id`) ;
     - un **loyer ou un prix de contrat** (`contrat_location_lignes.facture_id`) ;
     - un **reliquat facturé** (`reliquats_clients.facture_id`) ;
     - une **consommation de consignation** (le solde est **déjà déduit** à la création).
   - Pour ces lignes : refuser de changer la quantité (422 avec la raison), ou réajuster la source dans la même transaction.

#### F2 — Numéro et date à l'émission, annulation au lieu de suppression (M)

1. **fripv2** :
   - Un brouillon **n'a pas de numéro** : il est attribué au passage « Facturée », et la séquence reste continue.
   - La **date d'émission** devient la date de la facture et de son compte à recevoir.
   - L'**échéance** est recalculée depuis l'émission, sauf si l'usager l'a choisie (elle est alors conservée), puis figée ; jamais avant la date de la facture.
   - Une facture **ne se supprime jamais** : « Annuler la facture » (raison, statut Annulée, terminal), seulement avant le portage. Un acompte réservé redevient libre.
2. **PI** : numéro consommé à la création du brouillon ; brouillon supprimable (`DELETE /factures/{id}`) ; échéance fixée à la saisie.
3. **À faire** :
   - réglage `tenant_parametres.numero_facture_a_l_emission` (faux par défaut) ;
   - si actif : brouillon numéroté `BR-{id}` (unique par entreprise), vrai numéro à `emettre`, PDF marqué « BROUILLON » ;
   - `factures.date_emission`, `echeance_manuelle` ;
   - `POST /factures/{id}/annuler` (`motif`) → action `factures.annuler` (base `supprimer`), statut `annulee` ;
   - `DELETE` reste possible quand le réglage est inactif ;
   - tests : séquence continue ; échéance depuis l'émission ; isolation.
   - **Pièges** : recherche globale, relances et comptabilisation se basent sur le numéro ; il faut aussi corriger la facture « du reste » (BL2).

#### F3 — Limite de crédit bloquante sur facture (M)

1. **fripv2** :
   - Dès le brouillon, si solde dû + facture > limite, l'enregistrement est **bloqué**.
   - Un commis peut le débloquer avec le **mot de passe du client**, valable jusqu'à la fermeture de la facture, avec une notification.
   - Limite à 0 = pas de limite. Le client de passage n'est pas concerné.
2. **PI** : `Client::peutCommander` n'est vérifié qu'à l'**acceptation de la commande**.
3. **À faire** :
   - contrôle dans `FacturesVente::creer`, `modifier` et `emettre` (422 avec l'encours et la limite) ;
   - déblocage par l'action `factures.depasser_credit` (base `supprimer`) : paramètre `depasser_credit: true`, journalisé. C'est plus sûr qu'un mot de passe client partagé ;
   - tests.

#### F4 — Quantités à facturer, livrées, déjà facturées (M ; avec CO4)

1. **fripv2** :
   - colonnes Qté commandée, Qté B/O, Qté livrée, **Qté à facturer** ;
   - cases « Sélectionner ce qui n'est pas déjà facturé » et « ce qui a été livré et pas facturé » ;
   - contrôles : facturé ≤ commandé − B/O − déjà facturé ; sans « facturer plus qu'en main », ≤ livré.
2. **PI** : quantité = quantité facturée, sans lien avec la commande.
3. **À faire** : `facture_lignes.com_client_ligne_id` (CO4), validation, et réglage `facturer_au_dela_stock`.

#### F5 — « Tout livrer » et livraison depuis la facture (M ; dépend de S1)

Comme CO6, mais depuis la facture. Pas de livraison pour une facture de loyer ou de contrat, qui ne livre rien. Route `POST /factures/{id}/livrer-tout` → `['bons_livraison','livrer']`.

#### F6 — Cartes cadeaux vendues sur facture (M)

1. **fripv2** :
   - Le produit « carte cadeau » de l'entreprise est saisi sur une ligne : montant et n° (si la numérotation est manuelle), quantité 1.
   - **La carte est créée à l'émission** ; « Consulter › Cartes cadeaux ».
   - **Ni taxe ni revenu** : un passif.
   - Note de crédit → carte annulée.
   - Droit « Vendre une carte cadeau ».
2. **PI** : cartes vendues par leur écran et au point de vente seulement.
3. **À faire** :
   - `tenant_parametres.produit_carte_cadeau_id` ;
   - `facture_lignes.carte_cadeau_id`, `numero_carte_cadeau` ;
   - `CartesCadeaux::creerDepuisFacture` à l'émission ;
   - taxe forcée à 0 ;
   - droit `cartes_cadeaux.creer` vérifié dans le contrôleur ;
   - tests.

#### F7 — Facture payable en ligne (M ; dépend du paiement en ligne de l'espace client)

1. **fripv2** : case « Payable par plateforme web », grisée au-delà du **plafond** de l'entreprise ; modifiable après émission tant qu'il reste un solde ; historisée.
2. **PI** : l'espace client affiche les factures et leurs PDF, sans paiement.
3. **À faire** : `factures.payable_en_ligne`, `tenant_parametres.plafond_paiement_en_ligne`, puis le lot paiement en ligne (plan 1.11).

#### F8 — Nature de la facture (P)

1. **fripv2** : étiquette colorée fixée à la création : facture client, consignation, location matériel, contrat de contenant, loyer de contenant, contrat annuel. Filtrable dans la liste.
2. **PI** : seul `contrat_location_id` existe.
3. **À faire** : `factures.type_facture` (`vente`, `abonnement`, `contrat`, `loyer`, `consignation`, `reliquat`, `comptoir`), rempli par chaque service créateur ; badge et filtre.

#### F9 — Réimpression, identité figée, en-tête, lignes, copie, liste

Voir **S4.1 à S4.7** et **S3**.

**Couvert autrement :** « Retour sans facture d'origine » (ligne négative) et « Créditer avec retour en stock » sont couverts par les **Retours clients** et les **Notes de crédit** de PI, en documents séparés. **Fait.**

### 3.10 Relevé de consignation — **Fait**

**Preuves PI** : `ConsignationsPage` (Relevé, Reprise avec un lieu obligatoire, Facturer la consommation au prix du client, Historique sur 12 mois avec la facture) et `Consignations` : solde revérifié sous verrou, un geste à la fois.

**Écart** : intégration au stock et à la valeur d'inventaire (voir §2.9 et S1).

### 3.11 Abonnements — **Fait (écarts mineurs)**

**Preuves PI** : `/abonnements-clients*` : fréquences, jour de facturation, période, prix fixe ou tarif du client, texte à variables, factures gardées en brouillon, facturation à l'usage par relevés et import CSV, relier une échéance, tâche planifiée.

#### AB1 — Copier un abonnement, export (P)

1. **fripv2** : « Copier » reprend tout sauf les dates. Export Excel ; « Facturer tous ».
2. **PI** : pas de copie ni d'export.
3. **À faire** : `GET /abonnements-clients/{id}/copie` (`abonnements_clients.creer`) et S5.

#### AB2 — Fournisseur d'usage par API (dépend du plan 6.10)

1. **fripv2** : abonnement rattaché à un fournisseur d'usage (VoiceMeUp, ITCloud) : compte du client chez le fournisseur, type ou n° de produit chez lui ; consommation récupérée.
2. **PI** : import CSV ou saisie des relevés.
3. **À faire** : connecteur générique (`fournisseurs_api`, `abonnements_clients.fournisseur_api_id`, `compte_externe`, `produit_externe`). Les spécificités VoiceMeUp et ITCloud sont **hors périmètre**.

### 3.12 Préparation boîte — **Partiel**

**Preuves PI** : colis par BL (`/bons-livraison/{id}/colis*`, `ColisLivraisons`, `livraison/ColisBon`), numérotation `BL-…-001`, étiquettes 76 × 51 mm avec QR code, droit `bons_livraison.preparer`.

#### PB1 — Vue « Préparation boîte » (P)

1. **fripv2** : la liste des documents ouverte sur les BL, filtre Statut « Préparation boîte » (boîtes à créer ou à préparer).
2. **PI** : bouton Colis par BL, sans vue de travail.
3. **À faire** : filtre `a_preparer=1` sur `GET /bons-livraison` (statuts créé, en préparation ou prêt, reste à emballer > 0) et entrée de menu.

#### PB2 — Le contenu passe en localisation de préparation (M ; dépend de S1)

1. **fripv2** :
   - Mettre en boîte **déplace** le contenu vers la localisation de préparation, obligatoirement réglée.
   - Provenance demandée si plusieurs emplacements sont possibles ; jamais la préparation, un camion ou un emplacement vide.
   - Diminuer ou supprimer remet le contenu à l'origine.
   - Deux mouvements « Relocalisation » avec le n° de boîte.
2. **PI** : « Stock : il n'est pas touché ».
3. **À faire** : `bl_colis_lignes.secteur_origine_id`, `lot_id` ; déplacements via `MouvementsStock::deplacer` vers `secteur_preparation_id` ; tests.

#### PB3 — Bordereau de contenu et langue du client (P)

1. **fripv2** : Imprimer › Boîte (contenu à glisser dans le carton) et Étiquette, **dans la langue du client**.
2. **PI** : l'étiquette donne un résumé du contenu, en français ou en anglais selon l'écran.
3. **À faire** : `GET /bons-livraison/{id}/colis/{colis}/bordereau?format=pdf` dans la langue du client (C1).

#### PB4 — Droits propres aux boîtes (P)

1. **fripv2** : Voir, Ajouter, Modifier et Supprimer une boîte de livraison.
2. **PI** : un seul droit, `bons_livraison.preparer`.
3. **À faire** : actions `bons_livraison.colis_supprimer` (base `supprimer`) si l'on veut distinguer ; sinon, garder le droit actuel.

### 3.13 Chargement du camion — **Partiel**

**Preuves PI** : `/tournees/{id}/chargement*` (`ChargementsTournee`, `livraison/ChargementTournee`) : cocher colis et vrac, scan des étiquettes de colis, fiche de chargement, avertissement au départ.

#### CH1 — Chargement par déplacement vers le camion (M ; dépend de S1)

1. **fripv2** :
   - **Camion** (une localisation de type camion) et **route** facultative.
   - Liste des BL En attente, En attente de chargement et **Livré partiel**, avec la case « **Dans ce camion** ». Le BL est affecté au camion à l'enregistrement.
   - Marchandise de chaque BL :
     - **reste à charger** = commandé − B/O − livré − en boîte − déjà dans le camion ;
     - **emplacement d'origine** proposé (celui qui a le plus de stock) ;
     - quantité disponible à cet emplacement ;
     - **à déplacer** ;
     - déjà dans le camion.
   - Une boîte se charge d'un bloc avec son contenu.
   - La livraison saisie sur le BL **sort du camion**.
2. **PI** : on coche ce qui est chargé, sans stock ; tournée = camion + date.
3. **À faire** :
   - `bons_livraison.camion_id` ;
   - `POST /tournees/{id}/chargement/deplacer` (`lignes[]`) → `['tournees','charger']` ;
   - sorties à la livraison depuis le camion (S1) ;
   - tests : quantité insuffisante à l'origine ; isolation.

#### CH2 — Mode « Décharger » (M ; dépend de S1)

1. **fripv2** : « À décharger » = ce que plus aucun BL du camion ne réclame, à « remettre à » son emplacement d'origine (modifiable). On ne décharge pas plus que ce qui est dans le camion.
2. **PI** : rien.
3. **À faire** : `GET` et `POST /tournees/{id}/dechargement` → `['tournees','charger']`.

#### CH3 — Produits hors BL et lecture de codes-barres de produits (P)

1. **fripv2** : bouton + pour un produit qu'aucun BL ne demande (réserve du chauffeur). Lire le code-barres d'un produit ajoute une unité à la première ligne qui en réclame.
2. **PI** : scan des colis seulement.
3. **À faire** : `chargement/scan` accepte aussi un code de produit (`CodesBarresProduits::resoudre`) ; lignes libres (S1).

**Équivalence :** le « Départ du camion » de fripv2 correspond au « Démarrer » de la tournée PI (BL → en livraison) : **fait**.

### 3.14 Séquences des routes — **Partiel**

**Preuves PI** : `clients.itineraire_id` et `sequence_livraison` ; tournée suivant une route ; `POST /tournees/{id}/ajouter-route`, `/ordonner`, `PUT /ordre` ; feuille de route PDF (`SequencesLivraison`, `FeuilleDeRoutePdf`).

#### SQ1 — Route et séquence par adresse de livraison (M)

1. **fripv2** : la route et la séquence sont sur l'**adresse** du client. Le BL fige `route_code_origine` et `seq_route_origine`.
2. **PI** : sur la fiche client.
3. **À faire** : C7, puis `SequencesLivraison` lit l'adresse du BL (S4.1), sinon celle du client ; copie figée sur le BL ; tests. L'adresse choisie sur le BL et sa copie existent depuis le 26/10 (FLOW-VENTES §2.11) ; la feuille de route l'imprime déjà.

#### SQ2 — Écran de planification multi-routes (M)

1. **fripv2** :
   - un **panneau par route** (nom, nombre d'arrêts) et un panneau « **Sans route** » en premier ;
   - colonnes : n° BL, client, camion, séquence, adresse ;
   - **glisser-déposer** dans une route ou d'une route à l'autre, **sauvegardé aussitôt** ; renumérotation ;
   - glisser depuis « Sans route » assigne la route à l'adresse ;
   - filtres route, client, camion ; recherche multi-critères.
2. **PI** : ordre des arrêts dans une tournée seulement.
3. **À faire** :
   - `GET /sequences-routes?itineraire_id=&client_id=&camion_id=&recherche=` ;
   - `PUT /sequences-routes/deplacer` (`bon_id`, `itineraire_id`, `position`) ;
   - nouveau module `sequences_routes` (onglet ventes, crud `['voir','modifier']`), `Droits::MODULES` `'sequences-routes' => 'sequences_routes'` ;
   - page `/sequences-routes` ;
   - tests.

#### SQ3 — Carte de la tournée et suggestion d'itinéraire (M)

1. **fripv2** :
   - **Voir carte** (OpenStreetMap) : arrêts numérotés dans l'ordre enregistré, départ et retour à l'adresse de l'entreprise ; les arrêts non géocodés sont signalés.
   - **Suggérer** : ordre optimisé en prévisualisation, puis **Appliquer** ou **Annuler**. Un changement de filtre annule la suggestion.
2. **PI** : rien.
3. **À faire** :
   - géocodage mis en cache (`client_adresses.latitude`, `longitude`, `geocode_le`) ;
   - carte Leaflet dans le navigateur ;
   - optimisation par plus proche voisin puis 2-opt côté serveur (`POST /sequences-routes/suggerer`) ;
   - appliquer avec `PUT /sequences-routes/ordre` ;
   - pièges : quota du service de géocodage, confidentialité des adresses.

---

## 4. Achats / Fournisseurs

### 4.1 Fournisseurs — **Partiel**

**Preuves PI** :
- `/fournisseurs*`, `FournisseursPage`, `FicheFournisseurPage` : Synthèse, Historique d'achats, Produits fournis, Contacts, Adresses, Coordonnées bancaires (IBAN, BIC, chiffrées, droit `fournisseurs.comptes_bancaires`), Remarques.
- Champs : code, raison sociale, adresse, téléphone, courriel, contact, `conditions_paiement` (**texte libre**), devise, actif.

#### FO1 — Onglet Dossier (M)

1. **fripv2** (`fournisseur`) :
   - site web (255), télécopieur (15) ;
   - **langue** des documents (obligatoire) ;
   - **condition de paiement** (référentiel, obligatoire) ;
   - **limite de crédit** accordée ;
   - **groupe de taxes** (obligatoire) ;
   - **transporteur** ;
   - **n° de taxes** provinciale et fédérale (25) ;
   - **date de début d'affaires** ;
   - **comptes GL de dépenses** (grille) ;
   - historique de tout.
2. **PI** : condition en texte libre ; rien d'autre.
3. **À faire** :
   - `fournisseurs.site_web`, `telecopieur`, `langue`, `condition_paiement_id` (le texte reste, en miroir), `limite_credit`, `groupe_taxe_id`, `transporteur_id`, `date_debut_affaires` ;
   - `tiers_identifiants` (C1) ;
   - `fournisseur_comptes_depenses` (dépend de la Comptabilité) ;
   - effets : valeurs par défaut des commandes fournisseur (CF2), des factures fournisseurs et des déboursés ;
   - droits `fournisseurs.modifier` ;
   - tests.

#### FO2 — Onglets de consultation (M)

1. **fripv2** :
   - **Tableau de bord** : Commandes, Comptes à payer, Déboursés, sur En cours / Mois / Année / Tous, plus un camembert de l'âge des comptes à payer ;
   - **Comptes à payer** : relevé combiné ;
   - **Déboursés** : par facture, avec impression ;
   - **Commandes** et **Réceptions** : listes filtrées sur le fournisseur.
2. **PI** : Synthèse (achats, délais, ponctualité, retours) et historique des lignes.
3. **À faire** :
   - `GET /fournisseurs/{id}/tableau-bord`, `/releve`, `/debourses`, `/commandes`, `/receptions` ;
   - lectures contrôlées par `factures_fournisseurs.voir` et `debourses.voir` dans le contrôleur.

#### FO3 — Liste : favoris, export, recherche (P)

1. **fripv2** : étoile **favori** par usager et « Favoris seulement » ; colonne « Transfert de fonds » ; recherche par adresse, téléphone ou devise ; export Excel.
2. **PI** : rien de cela.
3. **À faire** : `fournisseur_favoris` (`POST` / `DELETE /fournisseurs/{id}/favori` dans `Droits::LIBRES`), S5, recherche étendue.

#### FO4 — Contacts et adresses enrichis (P)

1. **fripv2** : contact avec poste téléphonique, cellulaire, **envoi du relevé de paiement**, envoi des commandes, actif ; adresse avec la case **livraison** et des traductions.
2. **PI** : contact avec « reçoit les bons de commande » ; adresses sans case livraison.
3. **À faire** :
   - `fournisseur_contacts.poste_telephone`, `recoit_avis_paiement` (destinataires de l'avis de paiement des déboursés) ;
   - `fournisseur_adresses.livraison`.

#### FO5 — Historique et navigation

Voir **S4.6**.

### 4.2 Ajouter une réception — **Partiel**

**Preuves PI** :
- `POST /receptions/multiple` (`ReceptionsFournisseur`, `FenetreReception`) ;
- document numéroté, plusieurs commandes du même fournisseur ;
- BL fournisseur, connaissement, coût facturé, secteur avec répartition, lot et péremption, n° de série ;
- clôture par ligne, sur-réception confirmée, coûts annexes répartis.

#### RE1 — Réception hors commande (M)

1. **fripv2** :
   - Bouton **+** : ligne **manuelle** sans commande (produit, fournisseur, quantité > 0, coût > 0).
   - Tout le catalogue est proposé ; le produit est **ajouté à la fiche du fournisseur** à l'enregistrement.
   - Coût proposé : le dernier prix payé, sinon le dernier coûtant.
   - Unité au choix.
   - Les services sont exclus.
   - Une quantité négative fait un retour au fournisseur. Chez PI, c'est le document Retour fournisseur : **couvert autrement**.
2. **PI** : `com_four_ligne_id` est obligatoire.
3. **À faire** :
   - `reception_arrivages_ligne.produit_id`, `fournisseur_id`, `unite_achat`, `facteur` ;
   - `com_four_ligne_id` nullable : modification de colonne, **exception** à valider, ou une table `reception_lignes_libres` ;
   - lien automatique `produit_fournisseur` ;
   - effets sur les reliquats : aucun ;
   - droits `receptions.creer` ;
   - tests.

#### RE2 — Grille des lignes à recevoir, tous fournisseurs (M)

1. **fripv2** (`f_ReceptionCommandeFournisseur`) :
   - grille transversale filtrée par **fournisseur, commande, produit, client** (lignes liées à un B/O) et statut ;
   - « Afficher les déjà reçus » et filtre par BL fournisseur ;
   - colonnes : SKU fournisseur, date requise, date du B/O client, dernier coûtant, **nouveau coûtant**, qté non reçue, qté réception ;
   - « Accepter » ; « **Conserver** » (décoché = clôturer) ;
   - « Accepter tout », bloqué s'il faut choisir des emplacements ;
   - remarque ; export.
   - Choisir le seul fournisseur charge **tout son arriéré** à quantité zéro.
2. **PI** : réception commande par commande depuis l'onglet « À recevoir », avec « Ajouter une commande du même fournisseur ».
3. **À faire** :
   - `GET /receptions/lignes-a-recevoir?fournisseur_id=&commande_id=&produit_id=&client_id=&avec_recues=` ;
   - la fenêtre peut s'ouvrir sur le fournisseur (quantités à zéro) ;
   - tests.

#### RE3 — Détails de ligne (P)

1. **fripv2** : description de la ligne = celle de la commande, **corrigeable à la réception** (« robinet abîmé ») ; unité ; colonne **Client** (destinataire d'une commande passée pour un client) ; statut et facturation de la réception (non facturée, facturée, payée en partie, payée).
2. **PI** : une note par ligne.
3. **À faire** : `reception_arrivages_ligne.description` ; client via CF2 ; état de paiement via les factures fournisseurs.

#### RE4 — Stock et coût moyen à la réception (dépend de S1)

1. **fripv2** : chaque quantité reçue entre en stock à son emplacement et recalcule le coût moyen. Corriger le coût réajuste le coût moyen, avec un refus si le résultat est négatif. Corriger la date redate les articles.
2. **PI** : la réception compte comme une entrée « à la prochaine prise ».
3. **À faire** : S1. Correction de coût → `MouvementsStock::recalculerCout` ; correction de date → redater les mouvements.

### 4.3 Historique de réception — **Fait (écarts mineurs)**

**Preuves PI** :
- `GET /receptions/documents` (recherche, fournisseur, commande, produit, statut, dates), `HistoriqueReceptions` ;
- corriger (date, références, coûts) ; annuler avec un motif ; PDF ;
- « Facturer les réceptions » (facture fournisseur) ; filtre « à facturer » ou « facturées ».

**Écart P :**
1. **fripv2** : la colonne Facturation distingue « Payée partiellement » et « Payée », et reconnaît une commande facturée en bloc ; colonne Client ; « Imprimer » le journal (ce dernier relève du lot rapports).
2. **PI** : facturée ou non.
3. **À faire** : état de paiement issu de `factures_fournisseurs` ; colonne client (CF2).

### 4.4 Suggestions de commandes — **Fait (écarts mineurs)**

**Preuves PI** : `/suggestions-achat*` (`SuggestionsAchat`, `SuggestionsAchatPage`) : inventaire, reliquats, suggestions manuelles, accepter, modifier, commander par fournisseur, fusion dans une commande en préparation.

**Écart P :**
1. **fripv2** :
   - option « **Remplir B/O avec commande pour inventaire** » : réutiliser la commande de stock en préparation pour couvrir un B/O ;
   - colonnes **Format** (unité de commande) et **Délai de livraison** ;
   - ajout d'une ligne manuelle **dans la grille** ;
   - historique d'une suggestion ;
   - export Excel ;
   - saison du minimum (P3).
2. **PI** : absents.
3. **À faire** :
   - `POST /suggestions-achat/generer` accepte `remplir_reliquats_avec_stock` ;
   - colonnes `unite_achat` et `delai_livraison` ;
   - `POST /suggestions-achat` depuis la grille (droit `gerer`) ;
   - S5.

### 4.5 Commandes fournisseurs — **Partiel**

**Preuves PI** :
- `/commandes-fournisseur*` (`CommandeFournisseurController`, `CommandesFournisseur`, `CommandesFournisseurPage`) : brouillon modifiable, valider et envoyer, courriel, annuler, clôturer, PDF ;
- devise et taux ; « Livrer à » un entrepôt ; conditionnement du fournisseur ; reliquats rattachés.

#### CF1 — Prix proposé sur les lignes (P) — demandé

1. **fripv2** : le **coûtant prévu** est proposé d'après le **dernier prix payé à ce fournisseur** pour ce produit. À défaut, le **dernier coûtant de la fiche produit**, converti dans l'unité d'achat (une boîte de 12 = 12 × le coûtant unitaire). Modifiable ; jusqu'à 4 décimales (6 en base).
2. **PI** : la ligne démarre à `prix_unitaire: 0` (`CommandesFournisseurPage`). FLOW-ACHATS §2.3 : « Le formulaire de commande ne s'en sert pas encore pour pré-remplir le prix. » `ReliquatsClients::dernierPrix` (dernier prix payé, sinon `produit_fournisseur.prix_achat`) existe déjà et sert aux reliquats et aux suggestions.
3. **À faire**
   - `GET /commandes-fournisseur/prix-proposes?fournisseur_id=&devise=&produits[]=` : pour chaque produit, le prix par unité d'achat et son origine (`dernier_paye`, `convenu`, `cout_produit` (P4 et S1), `aucun`).
   - **Conversion** :
     - un dernier prix payé dans une autre devise que la commande est converti au taux (`DevisesVente` ou référentiel des devises) ;
     - un prix par unité de stock est multiplié par le facteur de l'unité d'achat (`UnitesAchat::pour`) ;
     - un escompte de type coûtant (E2) l'emporte s'il est plus bas.
   - **Écran** : appel au choix du produit et au changement de fournisseur ; pastille d'origine, comme `OriginePrix` côté vente ; un prix saisi à la main n'est jamais écrasé.
   - **Droits** : lecture dans le module achats, sans droit d'écriture.
   - **Tests** : dernier payé > convenu ; conversion d'unité et de devise ; isolation (prix d'une autre entreprise invisible).

#### CF2 — En-tête complet et commande pour un client (M/G)

1. **fripv2** (`trans_com_four_entete`) :
   - **contact du fournisseur** ;
   - **client à livrer** (« livraison directe ») avec contact et adresse du client, ou l'entrepôt ; **note de livraison** de l'adresse ;
   - **transporteur**, **condition de paiement**, **groupe de taxe** (obligatoires) ;
   - **date requise**, **date révisée** par le fournisseur (informative) ;
   - délai (le maximum des lignes) ; poids ; note.
   - **Commande passée pour un client** : chaque ligne lui est réservée, avec un **prix de vente** prérempli selon sa liste et obligatoire ; elle se facture depuis le B/O après réception.
   - Copie figée du fournisseur, des adresses et des libellés.
2. **PI** : fournisseur, dates (commande, livraison prévue), notes, entrepôt, devise. Pas de taxes : « les lignes d'achat ne portent pas de taxe ».
3. **À faire** :
   - `com_four_entete.contact_id`, `client_id`, `client_adresse_id`, `transporteur_id`, `condition_paiement_id`, `groupe_taxe_id`, `date_revisee`, `delai_livraison`, `poids_estime` et la copie figée ;
   - `com_four_ligne.prix_vente`, `client_id` ;
   - une commande pour un client crée des reliquats « commandés » (`ReliquatsClients`) ;
   - taxes d'achat (`TaxesDocument`, `document = commande_fournisseur`) reprises par la facture fournisseur ;
   - tests.

#### CF3 — Lignes (M)

1. **fripv2** (`trans_com_four_ligne`) :
   - **description modifiable**, reprise à la réception ;
   - **remarque** (100) ;
   - prix décimal(14,6) ; quantité décimale ;
   - unité choisie, ajoutée à la fiche du fournisseur ;
   - produit hors catalogue accepté et **rattaché au fournisseur** à l'enregistrement ;
   - services exclus ;
   - client, n° de document et date du B/O pour les lignes issues d'une demande.
2. **PI** : produit, quantité entière, prix, conditionnement.
3. **À faire** : `com_four_ligne.description`, `note` (100) ; S3 ; lien automatique `produit_fournisseur` ; filtre `achete` (S2).

#### CF4 — Modifier une commande envoyée jusqu'à la première réception (P/M)

1. **fripv2** : une commande « Commandé » reste modifiable (en-tête et lignes) jusqu'à la **première réception**.
2. **PI** : brouillon seulement (« Seules les commandes en brouillon peuvent être modifiées. »).
3. **À faire** : `PUT` permis en `envoyee` tant que `quantite_recue = 0` partout ; reliquats mis à jour ; tests.

#### CF5 — Copier, importer les suggestions (P)

1. **fripv2** : **Copier** (copie non enregistrée, mêmes lignes) ; **Importer les suggestions** depuis la commande, en brouillon ou commandée.
2. **PI** : absents. Les suggestions génèrent elles-mêmes des commandes.
3. **À faire** :
   - `GET /commandes-fournisseur/{id}/copie` (`commandes_fournisseur.creer`) ;
   - `POST /commandes-fournisseur/{id}/importer-suggestions` (`suggestions_achat.gerer`, plus `commandes_fournisseur.modifier`).

#### CF6 — Porter la commande au compte à payer (M)

1. **fripv2** : « Porter au compte à payer » crée la facture fournisseur préremplie (fournisseur, devise, condition) avec une répartition **par compte de dépense selon le département des produits**, les taxes et le solde. « Consulter le compte à payer ».
2. **PI** : facture fournisseur depuis des réceptions seulement (`GET /factures-fournisseurs/proposition?receptions[]=`).
3. **À faire** : `GET /factures-fournisseurs/proposition?commande_id=` ; lien `factures_fournisseurs.commande_fournisseur_id` ; refus de facturer deux fois ; droits `factures_fournisseurs.creer`.

#### CF7 — Frais de surcharge d'achat (M)

1. **fripv2** : frais de portée **achat** (par unité d'achat, par kilo ou par volume) ; tarif propre à chaque fournisseur (`produit_fournisseur.frais_achat` : vide = montant du frais, 0 = aucun) ; figé sur la ligne ; affiché sous le sous-total.
2. **PI** : FLOW-ACHATS §12 : « À venir : les frais d'achat de fripv2 ».
3. **À faire** :
   - `frais.portee = achat`, `frais.base` (`unite`, `kg`, `volume`) ;
   - `com_four_ligne.frais_achat`, `frais_id` ;
   - calcul dans `CommandesFournisseur` ;
   - report sur la facture fournisseur.

### 4.6 Gestion des retours — **Fait (écarts mineurs)**

**Preuves PI** : `/retours-fournisseurs*` (`RetoursFournisseurs`, `RetoursFournisseursPage`) : document, origine (commande, retour client ou aucune), types remboursement, remplacement ou consigne, RMA, expédier, clôturer (crédit, remplacement), PDF, courriel. C'est plus riche que fripv2.

**Écart P :**
1. **fripv2** : case « **Retourner tout** » (marque expédiées en lot toutes les lignes visibles non retournées, historisé) ; filtre Retourné / Non retourné ; export Excel ; fournisseur proposé limité à **ceux qui vendent le produit**.
2. **PI** : expédition une à une ; filtre par statut.
3. **À faire** : `POST /retours-fournisseurs/expedier` (`ids[]`) → `['retours_fournisseurs','expedier']` ; S5 ; filtre des fournisseurs du produit dans l'éditeur.

---

## 5. Hors périmètre (avec justification)

| Élément fripv2 | Pourquoi |
|---|---|
| Saisie du « numéro de commis » à l'ouverture d'un document | Les postes sont partagés dans fripv2 ; en SaaS, chaque compte est nominatif (`created_by`). |
| Poste caisse : facture née « Facturée », « Gestion de caisse » depuis la facture | Couvert par le Point de vente et la Gestion de caisse de PI. |
| Préférences d'écran (double-clic, « rester dans l'écran », largeurs de colonnes, imprimante) | Conventions d'interface de bureau ; les filtres mémorisés de PI couvrent l'essentiel. |
| Lecture d'un .xlsx qui exige Excel installé | Remplacé par un lecteur serveur (IG1). |
| VoiceMeUp et ITCloud nommément | Généralisés en connecteur d'usage (AB2, plan 6.10). |
| Cylindres et gaz, « carcasse » | Généralisés en « actifs retournables / contenants » (S2, §3.4). |
| Facture de consignation (type de facture) | Remplacée par le bon de dépôt de PI (§3.10). |

---

## 6. Tableau récapitulatif

| Onglet | Écran fripv2 | Statut PI | Preuve principale dans PI | Écarts (identifiants) |
|---|---|---|---|---|
| Inventaire | Produits | **Partiel** | `ProduitsPage` (modale), `/produits*` | P1–P13, S2, S3, S5 |
| Inventaire | Ajustements d'inventaire | **Manquant** | type `ajustement` de `mouvement_relocalisation` (quantité > 0, sans solde) | S1 + écran 2.2 |
| Inventaire | Relocalisation | **Partiel** | `/relocalisation*`, `RelocalisationPage` | contrôle de quantité, modes produit et emplacement, lots et séries (S1) |
| Inventaire | Mouvements inventaire | **Partiel** | `/relocalisation`, Traçabilité, `/mouvements-inventaire` sans écran | journal S1, écran 2.4 |
| Inventaire | Révision de prix planifiée | **Fait (mineurs)** | `/revisions-prix*` | RV1 (P4), RV2 |
| Inventaire | Importer une grille de prix | **Partiel** | `/fournisseurs/import-prix` | IG1 xlsx, IG2 coûtant produit, IG3 colonne référence et menu |
| Inventaire | Prise d'inventaire | **Partiel** | `/prises-inventaire*` | PR1 (S1), PR2–PR5 |
| Inventaire | Rapport prise inventaire | **Partiel** | export CSV seulement | RP1 feuille de comptage, RP2 rapport des différences |
| Inventaire | Consignations chez les clients | **Fait (mineurs)** | `/consignations/rapport` | catégorie, Excel, courriel, valorisation et disponible |
| Vente | Clients | **Partiel** | `ClientsPage`, `FicheClientPage` | C1–C11, S4.6, S5 |
| Vente | Escomptes | **Partiel** | `regles_prix` (Tarifs & Prix) | E1–E3 |
| Vente | Contrats de location | **Partiel** | `/contrats-location*` | CT1–CT5 |
| Vente | Facturation des loyers mensuels | **Partiel** (modèle différent) | `/contrats-location/facturer-loyers` | L1–L3 |
| Vente | Gestion des B/O client | **Fait (mineurs)** | `/reliquats-clients*` | BO1, BO2 |
| Vente | Devis | **Partiel** | `/devis*`, `DevisPage` | DE1–DE4, S3, S4.1–S4.7 |
| Vente | Commandes | **Partiel** | `/commandes-client*` | CO1–CO7, S3, S4.* |
| Vente | Bons de livraison | **Partiel** | `/bons-livraison*` (créés seulement depuis une facture) | BL1–BL5, S4.* |
| Vente | Factures | **Partiel** | `/factures*` (pas de `PUT`) | F1–F9, S3, S4.* |
| Vente | Relevé de consignation | **Fait** | `ConsignationsPage` | intégration S1 |
| Vente | Abonnements | **Fait (mineurs)** | `/abonnements-clients*` | AB1, AB2 |
| Vente | Préparation boîte | **Partiel** | `/bons-livraison/{id}/colis*` | PB1–PB4 |
| Vente | Chargement du camion | **Partiel** | `/tournees/{id}/chargement*` | CH1–CH3 (S1) |
| Vente | Séquences des routes | **Partiel** | `SequencesLivraison`, feuille de route | SQ1–SQ3 |
| Achats | Fournisseurs | **Partiel** | `FournisseursPage`, `FicheFournisseurPage` | FO1–FO5 |
| Achats | Ajouter une réception | **Partiel** | `/receptions/multiple` | RE1–RE4 |
| Achats | Historique de réception | **Fait (mineurs)** | `/receptions/documents*` | état de paiement, client |
| Achats | Suggestions de commandes | **Fait (mineurs)** | `/suggestions-achat*` | option B/O, format et délai, ligne manuelle, export |
| Achats | Commandes fournisseurs | **Partiel** | `/commandes-fournisseur*` | CF1 (prix proposé), CF2–CF7 |
| Achats | Gestion des retours | **Fait (mineurs)** | `/retours-fournisseurs*` | expédier en lot, filtre, export |

---

## 7. Ordre de réalisation conseillé (dépendances)

1. **Factures et BL, priorité haute** : F1 (`PUT /factures/{id}`), F2 (numéro à l'émission, annulation), BL2 (supprimer le risque de double facturation), CF1 (prix proposé). Ces lots sont indépendants et de taille P à M.
2. **S3** (quantités décimales) puis **S2** (nature du produit).
3. **S4.1 et S4.2** (en-tête et lignes des documents) puis S4.3 à S4.7. Ensuite DE1 (taxes du devis), CO1 à CO4, BL1, BL4 et BL5, F3, F4, F6, F8.
4. **Clients et fournisseurs** : C1, C2, C7, FO1, puis les onglets de consultation (C5, FO2).
5. **S1** (stock perpétuel). Ensuite : Ajustements (2.2), Mouvements (2.4), Relocalisation (2.3), PR1, PB2, CH1 et CH2, CO6 et F5, RE4, P3 et P6, la consignation intégrée.
6. **P4** (formules de prix). Ensuite : RV1, IG2, E2, CT1.
7. **Location** : L1 à L3, CT2 à CT4.
8. **Confort** : P7 à P13, SQ2 et SQ3, S5, PR2 à PR5, RP1 et RP2, et les écarts mineurs des écrans marqués « Fait ».
