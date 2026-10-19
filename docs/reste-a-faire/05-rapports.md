# Rapports fripv2 (Fonxtio) et Prise Inventaire : ce qui manque dans PI

> Partie de [la liste du reste à faire](../17-RESTE-A-FAIRE.md), état au 19 octobre 2026 (après la PR #131).
> Pour chaque écran : **ce que fait fripv2** (le comportement de référence), **ce que PI a aujourd'hui**, puis
> **ce qu'il faut faire** (taille **P** petite, **M** moyenne, **G** grande).

## 1. Résumé

fripv2 compte 61 rapports ou variantes : ses 43 gabarits d'impression, les sous-menus « Rapports » de chaque onglet et les pages d'aide des rapports (journal général, analyse par compte, rapport IA, export Excel). Le détail de chaque rapport vient de son aide et de son code source.

| Statut | Nombre | Sens |
|---|---|---|
| Fait | 20 | PI a un équivalent. Les petits écarts sont notés dans le tableau. |
| Partiel | 29 | Il existe quelque chose dans PI, mais il manque des colonnes, des filtres, des sorties ou des variantes. |
| Manquant | 9 | Rien d'équivalent dans PI. |
| Sans objet | 3 | Le concept n'existe pas dans PI. |

**Côté PI aujourd'hui :**
- **Bibliothèque** (`app/Rapports`, 13 rapports) : `ventes-par-periode`, `ventes-par-client`, `ventes-par-produit`, `ventes-par-categorie`, `ventes-par-representant`, `devis-par-statut`, `commandes-client-en-cours`, `clients-inactifs`, `achats-par-fournisseur`, `commandes-fournisseur-en-cours`, `stock-produits`, `produits-dormants`, `encaissements-par-mode`.
  - Sorties : écran, CSV, XLSX et PDF, courriel, envois programmés, « Mes filtres ».
  - Limites : lignes à plat, pas de sous-totaux. Un total « pourcentage » est une simple somme.
- **Écrans hors bibliothèque, CSV seulement sauf mention :**
  - `/comptes-clients` (analyse chronologique et relevé) ;
  - `/paiements-dus`, `/valorisation-inventaire`, `/stock-entrepots`, `/disponibilites` ;
  - `/balance-comptable`, `/grand-livre`, `/etats-financiers`, `/journal-comptable` ;
  - `/rapport-taxes` (CSV et PDF) ;
  - `/consignations/rapport` (PDF) ;
  - export CSV des écarts d'une prise d'inventaire ;
  - PDF de documents et de tournées.
- **Rapports propres à PI, sans équivalent fripv2** (à garder) : devis par statut, commandes client en cours, clients inactifs, achats par fournisseur, produits dormants, paiements par mode, `/rapports` (mouvements par secteur, activité par employé, évolution annuelle, top produits), statistiques, comparaison.
- **Hors périmètre :** les écrans-listes de fripv2 (Mouvements inventaire, Historique de réception, Transactions par client ou par fournisseur, Flux bancaire) ne sont pas des rapports. Ils relèvent de l'export Excel des listes (§4.28).

Dans les spécifications, **Σ** signale une colonne totalisée (`'total' => true`).

## 2. Tableau récapitulatif

| # | Menu fripv2 | Rapport (gabarit) | Variante | Statut PI | Équivalent PI ou écart principal |
|---|---|---|---|---|---|
| 1 | Inventaire › Produits › Rapports › Inventaire | Inventaire (RepInventaireL) | Produits par localisation | Partiel | `/stock-entrepots` (une colonne par entrepôt) et `stock-produits` (total). Pas de liste par secteur, ni filtres, ni PDF → §4.3 |
| 2 | id. | Inventaire (RepInventaireP) | Localisations par produit | Partiel | id. → §4.3 |
| 3 | id. › Rapport étiquette produit | RepEtiquetteProduit | — | Fait | `/etiquettes` (plusieurs produits, code-barres, liste de prix, copies, formats) |
| 4 | id. › Valeur d'inventaire | Statut des stocks (RepStockInventaireStatut) | Tri n° / description / fournisseur / catégorie | Partiel | `/valorisation-inventaire` (quantité officielle × coût) et `/disponibilites`. Pas de rapport unique, pas de liste de prix → §4.4 |
| 5 | id. › Consignations chez les clients | RepConsignation | Tous les clients / un client | Fait | `GET /consignations/rapport?format=pdf`. Manquent le filtre catégorie et l'Excel → §4.25 |
| 6 | Inventaire physique › Rapport prise inventaire | Feuille de comptage (RepPriseInventaire) | — | Manquant | → §4.5 |
| 7 | Fiche d'une prise › Imprimer › Différences | RepDifferencesPriseInventaire | Par localisation / par produit | Partiel | CSV `/prises-inventaire/{id}/export` : pas de valeur en $, pas de regroupement, pas de PDF → §4.6 |
| 8 | Gestion › Base › Localisations › Étiquettes | RepEtiquetteLocalisation | — | Partiel | QR d'un seul secteur à la fois (SecteursPage) → §4.7 |
| 9 | Vente › Clientèle › Rapports › Rapport clients (aussi Compta › Recevables et Compta › Clients) | RepClient | Tous / Actif / Inactif | Manquant | → §4.2 |
| 10 | Vente › Clientèle › Rapports › Rapport vente | RepVente | Aucun axe (total de la période) | Partiel | `ventes-par-periode` (ligne de total) → §4.1 |
| 11 | id. | id. | Par client | Partiel | `ventes-par-client` : sans quantité, coûtant ni marge ; notes de crédit non déduites |
| 12 | id. | id. | Par document | Manquant | → §4.1 |
| 13 | id. | id. | Par date | Partiel | `ventes-par-periode` (jour, semaine, mois) : sans quantité, coûtant ni marge |
| 14 | id. | id. | Par commis | Manquant | Les factures PI n'enregistrent pas d'auteur |
| 15 | id. | id. | Par vendeur | Partiel | `ventes-par-representant` (notes de crédit déduites, commission) : sans coûtant ni marge |
| 16 | id. | id. | Par produit | Partiel | `ventes-par-produit` : sans coûtant ni marge ; notes de crédit non déduites |
| 17 | id. | id. | Par catégorie (sous-catégories comprises) | Partiel | `ventes-par-categorie` |
| 18 | id. | id. | Par département | Sans objet | Pas de départements dans PI |
| 19 | id. | id. | Par fournisseur principal | Manquant | → §4.1 |
| 20 | id. | id. | 2 ou 3 axes imbriqués avec sous-totaux | Manquant | La bibliothèque ne fait pas de sous-totaux → §3.2 et §4.1 |
| 21 | id. | id. | Filtres : client, plage de documents, commis, vendeur, balises ET/OU, produit, catégorie, département, fournisseur | Partiel | Seuls les filtres catégorie et représentant existent |
| 22 | Vente › Expédition › Rapport préparation camion | RepPreparationCamion | Camion et/ou route, ou tous les bons en attente | Partiel | Fiche de chargement par tournée (`/tournees/{id}/chargement/pdf`) → §4.8 |
| 23 | Vente › Expédition › Rapport de dangerosité | RepDangerositeBonLivraison | Par camion | Fait | `/tournees/{id}/matieres-dangereuses` (par tournée) |
| 24 | Vente › Expédition › Séquences des routes › Imprimer | RepSequenceRouteBL | Route, client, camion, recherche, tri | Fait | `/tournees/{id}/feuille-de-route` (par tournée et non par route) |
| 25 | Bon de livraison › Imprimer › Boîtes | RepBoite | Toutes les boîtes / une sélection | Partiel | Seule l'étiquette de colis résume le contenu ; pas de feuille A4 → §4.9 |
| 26 | Bon de livraison › Imprimer › Étiquettes | RepEtiquette | Toutes les boîtes / une sélection | Fait | `ColisBon.tsx` (76 × 51 mm). Manquent l'adresse de livraison, la route et la séquence → §4.9 |
| 27 | Devis › Imprimer | RepDevis | — | Fait | `/devis/{id}/pdf` |
| 28 | Commandes › Imprimer | RepCommande | — | Fait | `/commandes-client/{id}/pdf` |
| 29 | Bons de livraison › Imprimer | RepBonLivraison | — | Fait | `/bons-livraison/{id}/pdf` |
| 30 | Factures › Imprimer (et rappel de paiement) | RepFacture | — | Fait | `/factures/{id}/pdf` et relances |
| 31 | Contrats de location › Imprimer | RepContrat | — | Fait | `/contrats-location/{id}/pdf` |
| 32 | Facturation des loyers mensuels › Rapport | RepLoyerCylindre | — | Manquant | Le modèle de location de PI est différent → §4.26 |
| 33 | Achats › Fournisseurs › Rapports (aussi Compta › Payables et Compta › Fournisseurs) | RepFournisseur | Tous / Actif / Inactif | Manquant | → §4.10 |
| 34 | Achats › Réceptions › Rapport réception commande fournisseur | RepReceptionCommandeFournisseur | — | Partiel | Liste `/receptions` filtrable et PDF d'une réception → §4.11 |
| 35 | Commandes fournisseurs › Imprimer (liste) | RepListeCommandesFournisseur | Tri fournisseur ou date ; ouvertes sans réception ; sans facture | Partiel | `commandes-fournisseur-en-cours` → §4.12 |
| 36 | Commande fournisseur › Imprimer | RepCommandeFournisseur | — | Fait | `/commandes-fournisseur/{id}/pdf` |
| 37 | Compta › Grand livre › Journal général | Écran | — | Fait | `/journal-comptable` et `/comptabilite/ecritures/export` |
| 38 | id. › Analyse par compte | Écran | — | Partiel (mineur) | `/grand-livre` : un compte obligatoire, CSV → §4.22 |
| 39 | id. › Plan comptable | RepPlanComptable | Tous / Actif / Inactif | Partiel (mineur) | CSV `/comptabilite/comptes/export` → §4.23 |
| 40 | id. › Rapport des taxes | RepTaxes | Détail / par jour / période | Fait | `/rapport-taxes` (CSV, PDF). Écarts mineurs → §4.24 |
| 41 | Compta › Payables › Analyse chronologique CAP | RepAnalyseChronologiqueCAP | Sommaire ou détaillé ; tri fournisseur ou code postal | Manquant | → §4.14 |
| 42 | id. › Paiements dus | RepPaiementsDus | Tri fournisseur / échéance / devise × détaillé / sommaire | Partiel | `/paiements-dus` (fournisseur et devise, détaillé, CSV) → §4.15 |
| 43 | Compta › Recevables › Analyse chronologique CAR | RepAnalyseChronologiqueCAR | Sommaire ou détaillé ; tri client ou code postal | Partiel | `/comptes-clients` (sommaire, tranches comptées après l'échéance, CSV) → §4.13 |
| 44 | id. › Factures non portées au CAR | RepFacturesNonPorteesCAR | — | Sans objet (partiel) | PI n'a pas d'étape « porter au CAR ». Le plus proche est l'aperçu de la comptabilisation → §4.17 |
| 45 | id. et Compta › Clients › État de compte | RepEtatCompte | Un client / tous / envoi par courriel en lot | Partiel | Relevé `/comptes-clients/{id}/releve` (CSV, un client) → §4.16 |
| 46 | Compta › Financier › Balance de vérification | RepBalanceVerif | Simplifiée ou complète × avec ou sans détails | Partiel | `/balance-comptable` (CSV) → §4.19 |
| 47 | id. › Bilan | RepBilan | Détaillé / entêtes seulement | Partiel | `/etats-financiers` (CSV) → §4.20 |
| 48 | id. › État des résultats | RepEtatResultat | Période ou cumul ; comparaison N-1 ; entêtes ; balises | Partiel | `/etats-financiers` (CSV) → §4.21 |
| 49 | Compta › Banque › Conciliation › Imprimer | RepConcilBancaire | — | Fait | `/banque/rapprochements/{id}/pdf` |
| 50 | Fiche client › Encaissements, fiche fournisseur › Déboursés › Imprimer | RepEncaissementsDebourses | Client / fournisseur | Partiel | Listes `/encaissements` et `/debourses` filtrables, pas d'état imprimable → §4.18 |
| 51 | Encaissement › Imprimer | RepEncaissement (reçu) | — | Fait | `/encaissements/{id}/pdf` |
| 52 | Déboursé › Imprimer le chèque | RepCheque | — | Fait | `/debourses/cheques/pdf` |
| 53 | TFE › Générer le fichier | RepTFETalonPaiement | — | Fait | `/debourses/{id}/pdf` (avis de paiement) |
| 54 | Gestion de caisse › Fermer et imprimer | RepSommaireCaisse | — | Fait | `/caisses/sessions/{id}/pdf` |
| 55 | Cartes cadeaux › Certificat | RepCarteCadeau | — | Fait | `/cartes-cadeaux/{id}/certificat` |
| 56 | Demandes d'acompte › Imprimer | RepDemandeAcompte | — | Fait | `/demandes-acompte/{id}/pdf` |
| 57 | Compta › Recevables › Acomptes | Liste | — | Fait | `/acomptes` |
| 58 | Gestion › Gestion des rapports › Rapports | Catalogue de gabarits (f_Rapport) | — | Sans objet | PI n'a pas de gabarits ; la bibliothèque sert de catalogue |
| 59 | id. › Rapport IA | f_RapportIA | — | Partiel | « Questions à l'IA » → §4.27 |
| 60 | id. › Rapports IA périodiques | f_RapportIAPeriodique | — | Partiel (mineur) | Envois programmés avec question à l'IA → §4.27 |
| 61 | Toutes les listes › Exporter en Excel (avec les détails) | f_RapportExcel | — | Partiel | CSV sur une partie des listes ; XLSX seulement dans la bibliothèque → §4.28 |

## 3. Prérequis communs de la bibliothèque PI

### 3.1 Nouveaux types de paramètres

Chaque nouveau type demande de toucher :
- `Rapport::TYPES_PARAMETRES`, `regles()` et `valeurs()` (tableaux et booléens, plus un indicateur `'requis' => true` qui ajoute `required`) ;
- `Production::parametresLisibles()` ;
- `RapportBibliothequeController::catalogue()` : fournir la liste d'options seulement si un rapport permis l'utilise, comme `categories_produits` aujourd'hui ;
- `ChampParametre.tsx`, la valeur tableau dans `FiltresMemorises`, le schéma des outils dans `AssistantRapports`.

| Type | Validation | Options | Utilisé par |
|---|---|---|---|
| `booleen` | `nullable, boolean`, converti en bool | — | La plupart des cases « inclure… » |
| `texte` | `nullable, string, max:100` | — | Plage de documents, n° de BL ou de connaissement |
| `choix_multiple` | `array` et `.*` dans les options | `options` | Statuts de réception ou de commande |
| `produit` | `TenantRule::exists('produits')` | Autocomplétion (`/produits?recherche=`) | §4.1, 4.3, 4.4, 4.11 |
| `secteur`, `entrepot` | exists `secteurs` / `entrepots` | Actifs | §4.3, 4.5, 4.6 |
| `camion`, `itineraire`, `tournee` | exists `camions` / `itineraires` / `tournees` | Tournées récentes non terminées | §4.8 |
| `balises` (et `choix` `et`/`ou`) | `array`, exists `balise_items` | Balises actives dont `entites` contient l'entité visée | §4.1 |
| `liste_prix` | exists `listes_prix` | Listes actives | §4.4 |
| `prise_inventaire` | exists `prises_inventaire` | Prises récentes | §4.5, 4.6 |
| `devise`, `condition_paiement` | regex 3 lettres ; exists `conditions_paiement` | — | §4.15, 4.17 |
| `compte_comptable`, `exercice`, `periode_comptable` | exists `comptes_comptables` / `exercices_comptables` / `periodes_comptables` | Comptes actifs hors en-tête | §4.19 à 4.22 |
| `employe` | exists `admin_users` | — | Seulement si l'axe « commis » est ajouté |
| `transporteur` | exists `transporteurs` | — | Aucun rapport fripv2 n'en filtre ; utile seulement pour l'en-tête du §4.9 |

### 3.2 Regroupements et sous-totaux

fripv2 en a partout : vente sur trois niveaux ; réceptions (réception, commande, client) ; analyses chronologiques, consignations et paiements dus par tiers.

- Ajouter `regroupements(array $valeurs): list<string>`, qui renvoie 0 à 3 clés de colonnes.
- `Production` insère des lignes `_type = 'sous_total'` (avec `_niveau`) qui somment les colonnes Σ. Le total général se calcule sur les seules lignes de détail.
- À l'écran : groupes repliables. En PDF et XLSX : sous-totaux en gras. En CSV : lignes « Sous-total … ».
- L'IA ne reçoit que le détail et les totaux.

### 3.3 Totaux calculés et colonnes dynamiques

- Le total d'une colonne `pourcentage` est aujourd'hui une somme. C'est faux pour une marge ou une part reçue.
- Ajouter `'total' => 'ratio:profit/vendant'` (×100), valable aussi dans les sous-totaux, et éventuellement `'max'` (retard maximal).
- Libellés dynamiques, déjà possibles via `colonnes($valeurs)` : nom de la liste de prix, libellés des axes.

### 3.4 Droits et catégories

- Ajouter une méthode `action()` (défaut `'voir'`) lue par `Catalogue::pour` :
  - rapports comptables : module `comptabilite`, action `etats` (comme les routes `ETATS_COMPTABLES`) ;
  - coûts : module `valorisation`.
- Nouvelles catégories : `comptabilite` (module d'entreprise `finance`) et `expedition` (module `ventes`), dans `CATEGORIES`, `MODULES_ENTREPRISE` et les traductions.

### 3.5 Date « en date du » dans les envois programmés

`Programmation` ne remplit que `du` et `au`. Pour les rapports à date unique (analyses chronologiques, état de compte, balance, bilan, soldes), il faut un drapeau `'relative' => 'fin'` qui met la date au dernier jour de la période relative.

### 3.6 Traçabilité et navigation

fripv2 :
- note chaque production dans l'historique (qui, quand, format, filtres) ;
- ouvre la fiche (client, facture, produit) d'un clic sur une ligne de l'aperçu.

PI ne fait ni l'un ni l'autre. À prévoir :
- journaliser la production de fichier et l'envoi par courriel (code, paramètres, format) ;
- `_liens` facultatif par ligne pour ouvrir la fiche.

### 3.7 Règles de calcul communes

- **Devises :**
  - Tout montant est converti en devise de l'entreprise avec le `taux_change` de son document (factures, avoirs, `com_four_entete`, `factures_fournisseurs`, acomptes, encaissements). Un taux nul ou absent vaut 1.
  - Paiements, escomptes et imputations d'une facture : au taux de la facture (comme `ComptesClients`).
  - `PaiementsDus` reste en devise de la facture : il faut convertir, ou garder une colonne devise avec des sous-totaux par devise.
- **Ventes :**
  - Factures `emise`, `partiellement_payee`, `payee`, `creditee` ; jamais `brouillon` ni `annulee`.
  - Avoirs `emis` ou `solde`, à leur `date_avoir`, en négatif.
  - Ne pas se servir de `factures.montant_credite` (ce sont des imputations).
  - Les consignations ne sont pas des factures dans PI : il n'y a rien à exclure, contrairement à fripv2.
- **Quantités en unités de stock :** `quantite × COALESCE(facteur, 1)` pour les lignes de facture, d'avoir et de BL. `facture_lignes.quantite` est un entier.
- **Stock :**
  - Stock = Σ `inventaire_scan.quantite`, signée.
  - Le secteur d'un scan est un code (web) ou un nom (Android) : reconnaître les deux.
  - L'inventaire officiel (dernières prises validées) est un autre chiffre : ne pas mélanger les deux.
  - `produits.type` est un texte libre : PI n'a pas de notion de « service ».
- **Libellés :** utiliser `withTrashed()` (clients, produits, fournisseurs, représentants). Le client de passage est une seule fiche (`de_passage`) ; le vrai nom est dans `passage_nom` du document.
- **Bornes de dates :** incluses. fripv2 exclut parfois le jour de fin (Rapport vente) : ne pas reproduire, et l'écrire dans la description.

## 4. Spécifications

### 4.1 Rapport de vente multi-axes : `ventes-analyse` (Partiel ou Manquant selon la variante)

**Ce que fait fripv2 (RepVente)**

- **Période :**
  - Du/Au sur la date d'émission de la facture (à défaut, la date du document).
  - Défaut : d'il y a un mois à aujourd'hui.
  - La date « au » est exclue.
- **Factures retenues :**
  - Statut « Facturée », payées ou non.
  - Notes de crédit incluses en négatif.
  - Exclues : brouillons, annulées, supprimées et factures de consignation.
- **Regroupement :**
  - Jusqu'à trois axes, dans l'ordre coché : Client, Document, Date, Commis, Vendeur, Produit, Catégorie, Département, Fournisseur principal.
  - Sous-total par groupe et total général.
  - Groupes classés par nom, numéro ou date, jamais par montant.
  - Sans axe : une seule ligne de total. Un quatrième axe est refusé.
- **Filtres** (actifs quand la case « Tous » est décochée) :
  - un client ;
  - une plage de numéros de documents De–À ;
  - commis ; vendeur ;
  - balises posées sur les lignes de facture, logique ET/OU ;
  - produit actif ;
  - catégorie (sous-catégories comprises) ;
  - département ;
  - fournisseur principal du produit.
- **Colonnes et totaux :**
  - Axes, puis Trx (factures distinctes, une note de crédit compte pour 1) et Qte.
  - Vendant = Σ qté × prix net HT ; Coûtant = Σ qté × prix coûtant de la ligne ; Profit ; % = profit / vendant (0 si vendant nul).
  - Tout est totalisé ; le % du total est recalculé.
- **Sorties :**
  - Écran (groupes repliables), PDF, imprimante, Excel.
  - Bandeau des filtres répété sur chaque page ; filtres enregistrés.

**Spécification PI**

- **Identité :** code `ventes-analyse`, catégorie `ventes`, module `factures` (étend `RapportVentes`).
- **Paramètres :**
  - `du`, `au` (date).
  - `axe1`, `axe2`, `axe3` (choix) : `aucun`, `client`, `document`, `jour`, `mois`, `representant`, `produit`, `categorie`, `fournisseur`, plus `commis` et `departement` s'ils sont créés. Deux axes identiques sont refusés.
  - Filtres : `client_id` (client), `representant`, `produit_id` (**produit**), `categorie`, `fournisseur_id`, `document_de` et `document_a` (**texte**), `balises` (**balises**) et `balises_logique` (choix `et`/`ou`), `inclure_avoirs` (**booleen**, oui), `exclure_frais` (booleen, non).
- **Colonnes** (une colonne `texte` par axe, puis) : `transactions` entier Σ, `quantite` Σ, `vendant` montant Σ, `coutant` montant Σ, `profit` montant Σ, `marge` pourcentage (total en ratio, §3.3).
- **Sources :**
  - `factures` (statuts du §3.7, `date_facture` dans la période) jointes à `facture_lignes`.
  - `avoirs` (`emis`, `solde`, `date_avoir` dans la période) jointes à `avoir_lignes`, en négatif.
  - Vendant = `montant_ht` de la ligne × `taux_change` de son document. Quantité = `quantite × facteur`.
  - **Coûtant :** créer `cout_unitaire` (decimal 12,4, devise de l'entreprise, par unité de stock) sur `facture_lignes` et `avoir_lignes`. Le figer à l'émission avec `ValorisationInventaire::couts('cout_moyen')`. Pour l'historique, prendre le coût moyen actuel et le signaler dans la description.
- **Libellés des axes :**
  - client : `clients.code` et `raison_sociale` (de passage : `passage_nom`) ;
  - document : numéro de la facture ou de l'avoir ;
  - représentant : celui de `factures.representant_id`, celui de sa facture pour un avoir, sinon « (Sans représentant) » ;
  - produit : `produits.numero` et description ;
  - catégorie : `produits.categorie`, « — » si vide ;
  - fournisseur : `produits.fournisseur_principal_id`.
- **Trx :** COUNT DISTINCT (type et id du document) par groupe.
- **Tri :** sur les libellés des axes, croissant ; sous-totaux du §3.2.
- **Pièges :**
  - Une facture `creditee` reste comptée en entier ; c'est l'avoir qui la compense, à sa date.
  - Les frais sont des produits (`frais.produit_id`), d'où `exclure_frais`.
  - Les balises PI se posent sur les documents et les fiches (`facture`, `produit`, `client`), pas sur les lignes : filtrer sur la facture (ET = tous les items, OU = au moins un).
  - Commis : ajouter `factures.cree_par`, sinon abandonner l'axe.
  - `produits.categorie` est un texte : pour inclure les sous-catégories, résoudre `categories.parent_id` par le nom ou le code.

**Mise à niveau des 5 rapports existants**
- Ajouter `coutant`, `profit` et `marge` aux cinq.
- Déduire les avoirs de la période dans `ventes-par-periode`, `ventes-par-client`, `ventes-par-produit` et `ventes-par-categorie`.
- Ajouter la quantité à `ventes-par-client` et `ventes-par-representant`.

### 4.2 Rapport clients : `clients-soldes` (Manquant)

**Ce que fait fripv2 (RepClient)**
- Liste des clients, sans les prospects ni les supprimés.
- Filtre : Tous (défaut), Actif, Inactif.
- Colonnes : Numéro, Nom, Téléphone, Limite de crédit, Solde CAR, Relevé imprimé (case), Actif (case).
- Tri par numéro. Les soldes à zéro sont inclus. Pas de total.
- Solde CAR = montants portés au CAR − encaissements appliqués, recalculé à chaque production. Les acomptes non appliqués ne sont pas déduits.
- Un clic sur une ligne ouvre la fiche. Sorties : écran, PDF, imprimante, courriel.

**Spécification PI**
- **Identité :** code `clients-soldes`, catégorie `clients`, module `comptes_clients`.
- **Paramètres :** `statut` (choix `tous`/`actifs`/`inactifs`), `date` (date, aujourd'hui par défaut), `avec_solde` (booleen, non).
- **Colonnes :** `code`, `client`, `telephone`, `limite_credit` montant (`clients.encours_max`), `solde` montant Σ, `credits` montant Σ (notes de crédit et acomptes disponibles ; ajout PI), `actif` (Oui/Non).
- **Source :** `ComptesClients::facturesDues(null, date)` sommé par client ; `creditsDisponibles` et `acomptesDisponibles` pour la colonne crédits. Tri par `code`.
- **Pièges :**
  - Ne pas utiliser `clients.encours_actuel` : il est mis à jour par événements et n'est pas daté.
  - Garder la fiche du client de passage.
  - « Relevé imprimé » n'existe pas dans PI : omettre la colonne, ou ajouter sur la fiche un mode d'envoi de l'état de compte.

### 4.3 Inventaire par secteur ou par produit : `stock-par-secteur` (Partiel)

**Ce que fait fripv2 (RepInventaireL et RepInventaireP)**
- **Deux présentations :** tri Localisation (« produits par localisation », en-tête avec le chemin) ou tri Produit (« localisations par produit »).
- **Filtres :**
  - localisation (ses sous-localisations à tous les niveaux) ;
  - catégorie ; produit.
- **Cases :**
  - inclure les localisations vides (non) ;
  - inclure les quantités à 0 (non) ;
  - inclure la consignation (oui).
- **Contenu :**
  - Seulement les produits actifs qui ne sont pas des services.
  - Colonnes : n° de produit, description courte et longue, quantité. Pas de total.
- **Sorties :** écran, PDF, imprimante, Excel, courriel.

**Spécification PI**
- **Identité :** code `stock-par-secteur`, catégorie `inventaire`, module `inventaires`.
- **Paramètres :** `regroupement` (choix `secteur`, par défaut, ou `produit`), `entrepot_id` (**entrepot**), `secteur_id` (**secteur**), `categorie`, `produit_id` (**produit**), `inclure_vides`, `inclure_zero` (**booleen**, non), `inclure_consignation` (booleen, oui).
- **Colonnes :**
  - Par secteur : `secteur` (« Entrepôt › Secteur »), `numero`, `description`, `quantite` Σ (sous-total par secteur), `unite`.
  - Par produit : `numero`, `description`, `secteur`, `quantite` Σ (sous-total par produit), `unite`.
- **Sources :**
  - `inventaire_scan`, SUM(quantite) GROUP BY numero, secteur.
  - Secteur reconnu par code ou nom (voir `StocksEntrepots::entrepotsParAppellation`), sinon « (Secteur inconnu) ». Produits actifs.
  - Consignation : `Consignations::soldes()`, un pseudo-secteur « Consignation – client ».
  - Secteurs vides : secteurs actifs sans aucune ligne.
- **Pièges :**
  - Une somme de scans peut être négative : l'afficher (fripv2 ne masque que les zéros).
  - La localisation à plusieurs niveaux de fripv2 devient entrepôt puis secteur.
  - Ne pas mélanger avec l'inventaire officiel des prises validées.

### 4.4 Statut des stocks et valeur d'inventaire : `statut-stocks` (Partiel)

**Ce que fait fripv2 (RepStockInventaireStatut, menu « Valeur d'inventaire »)**
- **Colonnes, par produit :**
  - n°, description, catégorie, Qte min, Qte max ;
  - En main (sans la zone de retour fournisseur ; pour un ensemble, le nombre d'ensembles complets) ;
  - BO client, Comm. fournisseur (non reçu), Comm. client (hors brouillons) ;
  - Réservée (reste à livrer des commandes, BL et factures non livrés) ;
  - Ajustée (ajustements depuis le début de l'exercice) ;
  - Disponible (en main − engagements, peut être négatif) ;
  - prix de la liste choisie, Total = en main × prix.
- **Pied :** « Valeur d'inventaire » = Σ Total, calculée avant arrondi.
- **Filtres :**
  - liste de prix (la première par défaut) ;
  - catégorie, fournisseur principal, produit ;
  - Actif / Inactif (deux cases, toutes deux cochées par défaut) ;
  - tri : n°, description, fournisseur ou catégorie.
- **Cases :**
  - inclure les quantités à 0 (non) ;
  - inclure les quantités négatives (non) ;
  - inclure la consignation (oui).
- **Produits exclus :** les services. Un produit sans prix vaut 0.

**Spécification PI**
- **Identité :** code `statut-stocks`, catégorie `inventaire`, module `valorisation`. Une variante sans prix pourrait rester sous `inventaires`.
- **Paramètres :** `valorisation` (choix `liste`/`cout_moyen`/`dernier_achat`), `liste_prix_id` (**liste_prix**), `categorie`, `fournisseur_id`, `produit_id` (**produit**), `statut` (choix `tous`/`actifs`/`inactifs`), `tri` (choix), `inclure_zero` et `inclure_negatifs` (booleen, non), `inclure_consignation` (booleen, oui).
- **Colonnes :** `numero`, `description`, `categorie`, `seuil` (seuil_alerte), `maximum` (stock_maximum), `en_main` Σ, `reliquats`, `en_commande`, `commande_client`, `reserve`, `disponible`, `prix` montant (libellé = nom de la liste ou de la méthode), `valeur` montant Σ.
- **Sources :**
  - `DisponibiliteProduits::calculer()`, qui donne déjà stock, réservé, disponible, en commande, reliquats et ensembles.
  - Commandes client : Σ `com_client_ligne` des `com_client_entete` `en_attente` ou `acceptee`.
  - Consignation : `Consignations::soldes`, ajouté à En main.
  - Prix : `listes_prix_produits.prix`, ou `ValorisationInventaire::couts()`.
- **Colonne « Ajustée » :**
  - PI n'a pas d'ajustement explicite : son stock est la somme des scans.
  - Équivalent possible, en colonne facultative : écarts retenus des prises validées depuis le début de l'exercice (`quantite_retenue − quantite_reference`).
- **Pièges :**
  - Produit sans prix : laisser vide et compter « sans prix » (fripv2 compte 0).
  - Valeur calculée avant l'arrondi d'affichage.
  - Le module `valorisation` est réservé aux responsables.

### 4.5 Feuille de comptage d'une prise : `feuille-comptage` (Manquant)

**Ce que fait fripv2 (RepPriseInventaire)**
- **Paramètres :**
  - n° de prise obligatoire (verrouillé si le rapport est ouvert depuis la prise) ;
  - localisation et « sous-localisations » repris de la prise.
- **Contenu, pour chaque localisation :**
  - les produits qui y sont au moment de l'impression (actifs, hors services), une ligne par produit et localisation ;
  - colonnes : code-barres (« No série / No lot » si le produit est suivi) et Quantité vide ;
  - **jamais la quantité attendue**.
- **Dernière page :** « Nouveau produit », 40 lignes vides (localisation, n° produit, code-barres, quantité).
- **Sorties :** écran, PDF, imprimante, courriel.

**Spécification PI**
- **Identité :**
  - Un PDF dédié plutôt qu'une ligne de bibliothèque, à cause des cases à remplir et de la page vide : `GET /prises-inventaire/{id}/feuille?format=pdf`, droit `inventaires.prises`.
  - En option, un rapport `feuille-comptage` (catégorie `inventaire`) pour l'export XLSX.
- **Paramètres :** `prise_id` (**prise_inventaire**, requis), `secteur_id` (limité aux secteurs de la prise), `avec_attendu` (booleen, non).
- **Colonnes :** `secteur` (groupe), `numero`, `description`, `code_barre` (code principal, plus « Lot / Série »), `unite`, `compte` (vide).
- **Sources :** secteurs de `prises_inventaire_secteurs`, ou tous si `tous_secteurs`. Produits d'un secteur : stock non nul dans les scans de ce secteur, plus les produits dont `produits.secteur_id` est ce secteur ; seulement les actifs.
- **Pièges :**
  - Le papier sert ensuite à saisir les quantités par `PUT /prises-inventaire/{id}/corrections`.
  - Refuser la feuille, ou le signaler, pour une prise validée ou annulée.

### 4.6 Différences de prise d'inventaire : `ecarts-prise-inventaire` (Partiel)

**Ce que fait fripv2 (RepDifferencesPriseInventaire)**
- S'ouvre depuis une prise.
- **Regroupement :** par localisation (défaut, puis par produit) ou par produit (puis par localisation).
- **Colonnes :**
  - localisation, n° produit et description ;
  - Qte orig. ;
  - Qte mouv. (mouvements entre le début de la prise et le dernier scan) ;
  - Qte comptée (saisie, sinon Σ des scans) ;
  - date de prise, commentaire, Source (Scan ou Man) ;
  - Diff qte = comptée − (orig + mouv) ;
  - Diff $ = diff × coût moyen actuel.
- Les lignes d'un même produit dans une même localisation sont fusionnées.
- **Sorties :** écran, PDF, imprimante, Excel, courriel.

**Spécification PI**
- **Identité :** code `ecarts-prise-inventaire`, catégorie `inventaire`, module `inventaires`. Retirer les colonnes de coût sans le droit `valorisation.voir`.
- **Paramètres :** `prise_id` (requis), `regroupement` (choix `secteur`/`produit`), `secteur_id`, `seulement_ecarts` (booleen).
- **Colonnes :** `secteur`, `numero`, `description`, `reference` (Qte orig.), `entrees`, `sorties`, `comptee`, `retenue`, `ecart` Σ, `cout_unitaire`, `valeur_ecart` montant Σ, `source` (Scan / Correction), `raison`.
- **Sources :** `PrisesInventaire::lignes($prise)` (figé si la prise est validée), `prises_inventaire_corrections`, `ValorisationInventaire::couts('cout_moyen')`.
- **Piège :** PI calcule « écart = compté − référence » et montre entrées et sorties sans les compter ; fripv2 les ajoute à l'attendu. Choisir une règle et l'écrire dans la description.

### 4.7 Étiquettes de localisation (Partiel, document)

**Ce que fait fripv2 (RepEtiquetteLocalisation)**
- **Choix des emplacements :** multisélection avec recherche et case « Tout », plus « inclure les sous-localisations ».
- **Copies :** nombre de copies (une valeur ≤ 0 compte pour 1), avec le compteur « N étiquette(s) seront imprimées ».
- **Étiquette 76 × 51 mm :** code-barres = code de l'emplacement, description, chemin complet.
- Les emplacements de consignation sont exclus.

**Spécification PI**
- **Point d'entrée :** `POST /secteurs/etiquettes` avec `{secteur_ids[], entrepot_ids[] (= tous leurs secteurs), copies}`, droit `secteurs.voir`.
- **PDF 76 × 51 mm :**
  - QR et Code128 de `secteurs.code` ;
  - nom ;
  - « Entrepôt › Secteur ».
- **Écran :** multisélection et compteur.
- **Piège :** un secteur sans code n'aurait rien de lisible ; avertir.

### 4.8 Préparation camion : `preparation-camion` (Partiel)

**Ce que fait fripv2 (RepPreparationCamion)**
- **Paramètres :** camion et route, tous deux facultatifs.
  - Camion choisi : seulement les BL affectés à ce camion.
  - Route choisie : les BL dont l'adresse de livraison est sur cette route.
  - Rien de choisi : tous les BL, même sans route.
- **BL retenus :** « En attente », « En attente de chargement », et le reste des « Livré partiel ».
- **Groupes :** « Boîte N » ou « Sans boîte ».
- **Colonnes :** n° produit, description, séquence du client sur sa route, client, quantité.

**Spécification PI**
- **Identité :** code `preparation-camion`, catégorie `expedition`, module `bons_livraison`.
- **Paramètres :** `tournee_id` (**tournee**), ou `camion_id` (**camion**) et `itineraire_id` (**itineraire**). Tout vide = tous les bons à préparer.
- **Colonnes :** `colis` (groupe « Colis N » ou « Sans colis »), `numero`, `description`, `sequence` entier, `client`, `bon`, `quantite` Σ.
- **Sources :**
  - `bons_livraison` aux statuts `cree`, `en_preparation`, `pret`, `livre_partiel`.
  - Reste = `quantite_a_livrer − quantite_livree`.
  - Part en colis : `bl_colis` et `bl_colis_lignes` ; le reste va en « Sans colis ».
  - Camion : `tournee_bons` joint à `tournees.camion_id` (tournées `planifiee` ou `en_cours`).
  - Route : `clients.itineraire_id` du client de la facture du BL.
  - Séquence : `tournee_bons.ordre_livraison`, sinon `clients.sequence_livraison`.
- **Pièges :**
  - Un BL peut figurer dans plusieurs tournées : prendre la plus récente non terminée.
  - Exclure les BL en `retrait_client` quand un camion est choisi.
  - Les quantités peuvent être décimales.

### 4.9 Contenu des boîtes et étiquettes de colis (Partiel, documents)

**Ce que fait fripv2**
- **RepBoite :**
  - Pour un BL, une section par boîte cochée (aucune cochée = toutes), dans l'ordre des numéros.
  - Colonnes : n° produit, description en texte brut, quantité.
  - En-tête :
    - logo et adresse de l'entreprise ;
    - « Bon de livraison # » et sa date ;
    - adresses facturée et expédiée ;
    - n° de commande du client, contact, téléphones ;
    - transporteur, délai.
  - Produit dans la langue du client.
- **RepEtiquette :** code-barres et numéro de boîte, nom et adresse de livraison, route et séquence.

**Spécification PI**
- **Feuille de contenu :** `GET /bons-livraison/{id}/colis/pdf?colis[]=`.
  - Sources : `bl_colis`, `bl_colis_lignes` (joint à `bon_livraison_lignes`) et `produits`.
  - Adresse : celle de la facture ou de la commande, sinon celle du client.
- **Étiquette de colis (`ColisBon.tsx`) :** ajouter l'adresse de livraison, l'itinéraire et `sequence_livraison`.

### 4.10 Rapport fournisseurs : `fournisseurs-soldes` (Manquant)

**Ce que fait fripv2 (RepFournisseur)**
- Filtre : Tous, Actif ou Inactif.
- Colonnes : Numéro, Nom, Téléphone, Limite de crédit, Début d'affaire, Solde CAP, Actif.
- Tri par numéro. Les soldes à zéro sont inclus. Pas de total.
- Solde CAP = pièces CAP (les crédits négatifs viennent en déduction) − paiements − escomptes, sans les pièces supprimées.

**Spécification PI**
- **Identité :** code `fournisseurs-soldes`, catégorie `achats`, module `factures_fournisseurs`.
- **Paramètres :** `statut` (choix), `date` (date).
- **Colonnes :** `code`, `fournisseur`, `telephone`, `devise`, `factures_ouvertes` entier Σ, `solde` montant Σ, `actif`.
- **Source :**
  - `factures_fournisseurs` au statut `enregistree`, avec `date_facture` ≤ date.
  - Réglé = Σ `facture_fournisseur_paiements` (montant + escompte, `date_paiement` ≤ date).
  - Converti avec le `taux_change` de la facture.
- **Pièges :**
  - PI n'a ni limite de crédit ni date de début d'affaires pour un fournisseur : omettre, ou ajouter ces champs.
  - Les crédits fournisseurs (`retours_fournisseurs.montant_credite`) ne sont pas imputés aux factures : colonne `credits` facultative.

### 4.11 Réceptions de commandes fournisseur : `receptions-fournisseurs` (Partiel)

**Ce que fait fripv2 (RepReceptionCommandeFournisseur)**
- **Filtres :**
  - début et fin sur la date reçue, toutes deux décochables ; fin incluse ; défaut : du 1er du mois à aujourd'hui ;
  - commande, fournisseur actif, produit actif, client (actif ; en-tête ou B/O sur une ligne) ;
  - BL, n° de réception, connaissement : recherche partielle ;
  - statut (multiple) : reçu partiel, reçu complet, annulée ;
  - facturation (multiple) : non facturée, facturée, payée en partie, payée.
- **Présentation :**
  - En paysage, trois niveaux : réception (numéro, fournisseur, date, statut, BL, connaissement, commis, note), puis commande, puis client du B/O.
  - Sous-totaux à chaque niveau, avec le nombre de lignes, et total général.
  - Les lignes reçues hors commande vont sous « Réception manuelle ».
- **Colonnes :**
  - fournisseur ; n° produit (référence fournisseur entre parenthèses) ; description (avec lots et séries) ; BL ;
  - qté commandée ; qté reçue ;
  - qté facturée (toute la ligne ou 0) ;
  - écart reçu − commandé (en rouge s'il n'est pas nul ; vide hors commande) ;
  - prix unitaire ; total.

**Spécification PI**
- **Identité :** code `receptions-fournisseurs`, catégorie `achats`, module `receptions`.
- **Paramètres :**
  - `du`, `au` ; `fournisseur_id` ; `produit_id` (**produit**) ; `client_id` ;
  - `commande`, `reception`, `bon_livraison`, `connaissement` (**texte**) ;
  - `statuts` (**choix_multiple** : `enregistree`, `annulee`) ;
  - `facturation` (choix `toutes`/`facturee`/`non_facturee`).
- **Colonnes :** `reception`, `date`, `fournisseur`, `commande` (« Réception manuelle » si vide), `client`, `produit`, `description`, `bon_livraison`, `qte_commandee` Σ, `qte_recue` Σ, `qte_facturee` Σ, `ecart` Σ, `prix_unitaire` montant, `total` montant Σ.
- **Sources :**
  - `reception_arrivages_ligne` (`quantite_recue`, `cout_unitaire`, `frais_unitaire`, `annulee`), jointe à `receptions`.
  - Jointure gauche sur `com_four_ligne` et `com_four_entete` (`quantite_commandee`, `prix_unitaire`, `taux_change`).
  - Référence fournisseur : `produit_fournisseur.reference_fournisseur`.
  - Facturée : une ligne de `facture_fournisseur_lignes.reception_ligne_id` ou `receptions.facture_fournisseur_id`.
  - Client : `reliquats_clients.com_four_ligne_id`.
- **Pièges :**
  - PI n'a pas les statuts « reçu partiel » ou « reçu complet » : les déduire de la commande.
  - Une ligne de commande reçue en plusieurs fois donne un écart trompeur ligne à ligne : ne montrer la quantité commandée qu'une fois.
  - Prix = coût réel saisi, sinon prix de la commande, converti avec le `taux_change` de la commande.
  - Ignorer les lignes annulées.

### 4.12 Liste des commandes fournisseur : `commandes-fournisseur-liste` (Partiel)

**Ce que fait fripv2 (RepListeCommandesFournisseur)**
- **Tri :** par fournisseur (nom, puis date émise) ou par date.
- **Filtres :**
  - date : commandes émises jusqu'à cette date (celles sans date sont incluses) ;
  - fournisseur ;
  - « ouvertes sans réception » (statut Commandé, aucune réception) ;
  - « sans facture » (aucune facture, ni directe ni par une réception).
- **Colonnes :**
  - n°, fournisseur, client, date émise, date prévue, statut, commis ;
  - Montant = Σ qté × prix, HT, sans les surcharges ;
  - Reste à recevoir = Σ max(0, commandé − reçu) × prix.
- Totaux généraux sur Montant et Reste à recevoir.

**Spécification PI** : faire évoluer `commandes-fournisseur-en-cours` ou ajouter ce rapport.
- **Paramètres :** `date`, `fournisseur_id`, `tri` (choix), `statuts` (choix_multiple, défaut `envoyee` et `partielle`), `sans_reception`, `sans_facture` (booleen).
- **Colonnes :** `numero`, `fournisseur`, `client`, `date_commande`, `date_prevue`, `statut`, `auteur` (`created_by`), `montant` Σ, `reste_a_recevoir` Σ, `retard` entier.
- **Sources :** `com_four_entete` et `com_four_ligne`. Reste à recevoir = max(0, commandé − reçu − `quantite_abandonnee`) × prix × taux. Réceptions par `reception_arrivages_ligne.com_four_ligne_id`. Client par les reliquats.

### 4.13 Analyse chronologique CAR : `anciennete-clients` (Partiel)

**Ce que fait fripv2 (RepAnalyseChronologiqueCAR)**
- **Paramètres :**
  - date de référence (aujourd'hui) ; tous les clients ou un seul ;
  - mode Sommaire (défaut) ou Détaillé ;
  - tri par client ou par code postal (en détaillé) ;
  - « inclure les transactions soldées » (détaillé) ;
  - « inclure les clients à zéro » (sommaire).
- **Colonnes :**
  - Client, Nom, Facture et Date (en détaillé), Montant ;
  - Paiement (encaissements datés au plus tard à la date, y compris les acomptes appliqués) ;
  - Courant (0–30 j), 30 (31–60), 60 (61–90), 90 et + ;
  - Solde (les quatre tranches lui sont égales).
- **Âge :** compté depuis la **date de la transaction**, pas depuis l'échéance.
- **Exclusions :** acomptes non appliqués, prospects.
- **En sommaire,** Montant et Paiement additionnent toutes les transactions du client, soldées comprises.
- **En détaillé,** ligne « Acompte » sous la facture et total par client. Total général dans les deux modes.

**Spécification PI**
- **Identité :** code `anciennete-clients`, catégorie `clients` (ou `comptabilite`), module `comptes_clients`.
- **Paramètres :** `date`, `client_id`, `mode` (choix `sommaire`/`detail`), `base` (choix `date_facture`, pour la parité, ou `echeance`, comme PI aujourd'hui), `tri` (choix `nom`/`code_postal`/`solde`), `inclure_soldees`, `inclure_zero` (booleen).
- **Colonnes :** `code`, `client`, [`facture`, `date`, `echeance`, `jours` en détaillé], `montant` Σ, `paye` Σ, `courant`, `j30`, `j60`, `j90` Σ, `solde` Σ, `credits` Σ, `solde_net` Σ.
- **Sources :** `ComptesClients::facturesDues`, à étendre pour rendre le montant, le payé et les factures soldées ; `creditsDisponibles` ; `acomptesDisponibles` ; `clients.code_postal`.
- **Pièges :**
  - Les tranches PI actuelles (« non échu », puis jours de retard) ne sont pas celles de fripv2, d'où `base`.
  - Les imputations d'avoir comptent comme paiements.
  - Montants convertis avec le taux de la facture.

### 4.14 Analyse chronologique CAP : `anciennete-fournisseurs` (Manquant)

**Ce que fait fripv2 (RepAnalyseChronologiqueCAP)** : même structure que le CAR.
- Colonnes : Fournisseur, Nom, Facture et Date (en détaillé), Montant, Déboursé (paiements et escomptes au plus tard à la date), Courant, 30, 60, 90 et +, Solde.
- Tri par fournisseur ou par code postal de l'adresse principale. Mêmes deux options « zéro ».

**Spécification PI**
- **Identité :** code `anciennete-fournisseurs`, catégorie `achats` (ou `comptabilite`), module `factures_fournisseurs`.
- **Paramètres :** ceux du §4.13, plus `fournisseur_id` et `devise`.
- **Colonnes :** comme au §4.13 ; `debourse` remplace `paye`.
- **Source :** `factures_fournisseurs` au statut `enregistree`, avec `date_facture` ≤ date ; Σ `facture_fournisseur_paiements` (montant + escompte, ≤ date) ; converti avec le `taux_change` de la facture. Reprendre le calcul du « réglé » de `PaiementsDus`.
- **Pièges :**
  - Les fournisseurs PI n'ont pas de code postal distinct (`adresse` est un texte libre) : pas de tri par code postal sans ajout de champ.
  - Les crédits fournisseurs ne sont pas imputés aux factures.

### 4.15 Paiements dus (Partiel, écarts)

**PI a déjà** les dates du rapport, « payer le » et « échéance au », le fournisseur, la devise, les cases « échus », « escompte seulement » et « inclure les soldées », ainsi que l'escompte et l'échéance calculés comme fripv2.

**Il manque :**
- **Tris :**
  - par échéance, avec des sous-totaux mensuels ;
  - par devise.
- **Mode Sommaire :** une ligne par fournisseur et par devise (et par mois en tri échéance), avec l'échéance la plus proche et le plus grand retard.
- **Filtres et saisie :**
  - filtre par condition de paiement ;
  - raccourci « dans N jours ».
- **Sorties :**
  - PDF, XLSX, courriel ;
  - en fin de rapport, « Escompte à saisir si payé à cette date » ;
  - une note rappelant que le total général additionne les devises.

**Spécification** : un rapport `paiements-dus`, catégorie `achats`, module `debourses`, qui réutilise `PaiementsDus::rapport`.
- **Paramètres :** `date`, `payer_le`, `echeance_au`, `fournisseur_id`, `devise` (**devise**), `condition_paiement_id` (**condition_paiement**), `tri`, `mode` (choix), `echus`, `escompte_seulement`, `inclure_soldees` (booleen).
- **Colonnes :** `fournisseur`, `document`, `numero_fournisseur`, `date`, `echeance`, `jours`, `montant`, `regle`, `solde`, `limite_escompte`, `escompte`, `net`, `devise`.
- **Piège :** les montants sont en devise de la facture. Il faut soit des sous-totaux par devise, soit une conversion avec le `taux_change`.

### 4.16 État de compte client : `etat-compte` (Partiel)

**Ce que fait fripv2 (RepEtatCompte)**
- **Paramètres :** date (aujourd'hui), un client ou tous, tri par client ou par code postal, « inclure les transactions soldées », « inclure les états de compte à zéro », note de bas de page.
- **Contenu, par client :**
  - bandeau : n°, nom, adresse, téléphone ;
  - colonnes Facture, Date facture, Date du dernier paiement, Montant, Paiement, Solde ;
  - sous chaque facture, les acomptes appliqués ou « (réservé) » ;
  - au pied : Total, Courant, 30, 60, 90 et + ;
  - si le client a du crédit : « Acomptes non appliqués », puis « Crédit disponible » et « SOLDE NET ».
- **Courriel :**
  - Pour un client : PDF, avec les contacts « Envoi état de compte » préremplis.
  - Pour tous, **envoi en lot** : PDF dans la langue du client, modèle de courriel de cette langue, mise en file.
  - Un client sans contact reçoit une version imprimée.
  - Un client est sauté s'il n'existe pas de modèle dans sa langue (motif noté dans l'historique de sa fiche).
  - Résumé final : envoyés, imprimés, sautés, non traités. Annulable.

**PI aujourd'hui** : un relevé débit / crédit / solde entre deux dates, avec les factures dues et les crédits. CSV seulement, un client à la fois.

**Spécification PI**
- **Données :** service `EtatsCompte` (factures dues ou soldées au plus tard à la date ; `facture_paiements` dont `acompte_id`, d'où la date du dernier paiement ; tranches ; crédits ; acomptes), plus une vue `pdf/etat-compte.blade.php`.
- **Points d'entrée :**
  - `GET /comptes-clients/{client}/etat-compte?date=&format=pdf` ;
  - `POST /comptes-clients/etats-compte/envoyer` (lot, en file `EnvoyerCourriel`, avec le résumé) ;
  - en plus, un rapport de bibliothèque `etat-compte` (catégorie `clients`, module `comptes_clients`, mêmes paramètres) pour la liste.
- **À créer :**
  - le type de courriel `etat_compte` (modèle FR et EN dans Paramètres › Courriels) ;
  - dans `EnvoiCourriel::CONTACTS`, le type `etat_compte`, relié à une nouvelle case `client_contacts.recoit_etats_compte` (ou à `recoit_factures`).
- **Pièges :**
  - Distinguer les acomptes réservés (`commande_id`) des acomptes libres.
  - Montants convertis avec le taux de chaque document.
  - Respecter la date : ignorer ce qui est postérieur.

### 4.17 Factures non portées au CAR, pour PI « factures non comptabilisées » (Sans objet ; équivalent utile)

**Ce que fait fripv2** : liste les factures « Facturée » pas encore portées au CAR, donc sans encaissement possible.
- Paramètres : date (émises au plus tard ce jour), un client.
- Colonnes : n° facture, n° client, nom, montant total, date d'émission, condition de paiement.
- Tri par nom puis par date ; total général.

**Équivalent proposé pour PI** : une facture PI est due dès son émission. Le contrôle comparable est « émise mais non comptabilisée ».
- **Identité :** code `factures-non-comptabilisees`, catégorie `comptabilite`, module `comptabilite` (action `etats`).
- **Paramètres :** `date`, `client_id`, `type` (choix `facture`/`avoir`/`paiement_client`/…).
- **Colonnes :** `numero`, `client_code`, `client`, `montant_ttc` Σ, `date_facture`, `condition`, `reste_a_payer` Σ.
- **Source :** factures émises dont il n'existe pas de `ecritures_comptables` avec `source = 'facture'` et `source_id = factures.id`. On peut aussi réutiliser `Comptabilisation::apercu`.

### 4.18 Encaissements ou déboursés d'un tiers : `paiements-client`, `paiements-fournisseur` (Partiel)

**Ce que fait fripv2 (RepEncaissementsDebourses)**
- S'ouvre depuis la fiche du tiers, sans filtre : tout l'historique.
- Une ligne par couple paiement × facture.
- Colonnes : date du paiement, n° du paiement, référence, mode, n° de facture, date de la facture, montant de la facture, montant appliqué.
- Tri du plus récent au plus ancien ; total du montant appliqué.

**Spécification PI** : deux codes, car un rapport n'a qu'un module.
- **Identité :**
  - `paiements-client` : catégorie `finance`, module `encaissements`, paramètre `client_id` requis ;
  - `paiements-fournisseur` : catégorie `achats`, module `debourses`, paramètre `fournisseur_id` requis.
- **Paramètres :** `du` et `au`, facultatifs.
- **Colonnes :** `date_paiement`, `numero_paiement`, `reference`, `mode`, `facture`, `date_facture`, `montant_facture` montant, `montant_applique` Σ, `escompte` Σ.
- **Sources :**
  - Côté client : `encaissements` (statut `valide`) avec `encaissement_lignes`, plus les `facture_paiements` sans `encaissement_id` (caisse, paiements directs, acomptes : mode « Acompte »).
  - Côté fournisseur : `debourses` (statut `valide`) avec `debourse_lignes`, plus les `facture_fournisseur_paiements` sans `debourse_id`.
- **Pièges :** exclure les annulés ; convertir avec le taux du document.

### 4.19 Balance de vérification : `balance-verification` (Partiel)

**Ce que fait fripv2 (RepBalanceVerif)**
- **Paramètres :**
  - cycle (bornes préremplies, modifiables) ;
  - un compte ou tous (hors en-tête) ;
  - « inclure les comptes à zéro » (non) ;
  - « avec détails » (non) ;
  - « vue simplifiée » (oui).
- **Colonnes :**
  - Vue simplifiée : seulement « Au [date] » Débit / Crédit.
  - Vue complète : solde de début, mouvements de la période (débits et crédits bruts, séparés), solde final.
  - Avec détails : les écritures de chaque compte (n° de transaction, source).
- **Comptes affichés :**
  - Les comptes d'en-tête, inactifs ou supprimés sont cachés ;
  - sauf s'ils portent un montant : ils restent, marqués « (à corriger) ».
- Ligne Grand total.

**Spécification PI**
- **Identité :** code `balance-verification`, catégorie `comptabilite`, module `comptabilite` (action `etats`).
- **Paramètres :** `exercice_id` (**exercice**) ou `du` et `au`, `compte_id` (**compte_comptable**), `vue` (choix `simplifiee`/`complete`), `details`, `inclure_zero` (booleen).
- **Colonnes :**
  - Vue complète : `compte`, `libelle`, `ouverture_debit`, `ouverture_credit`, `mouvement_debit`, `mouvement_credit`, `solde_debit`, `solde_credit`, toutes Σ.
  - Vue simplifiée : `compte`, `libelle`, `debit` Σ, `credit` Σ.
- **Source :** `Soldes::balance` (le détail par `Soldes::grandLivre`), qui masque aujourd'hui les comptes à zéro. Marquer « (à corriger) » les comptes de type `en_tete` qui portent un montant.
- **Gain principal :** les sorties PDF et XLSX.

### 4.20 Bilan : `bilan` (Partiel)

**Ce que fait fripv2 (RepBilan)**
- **Paramètres :**
  - cycle ;
  - période (la date de fin) et raccourcis (année, semestres, trimestres, mois) ;
  - « inclure les comptes à zéro » ;
  - « entêtes seulement ».
- **Contenu :**
  - sections Actif, Passif, Capital, avec la hiérarchie des comptes et des lignes « Total… » ;
  - passif et capital affichés avec le signe inversé ; montants négatifs entre parenthèses ;
  - ligne finale « Total Passif & Capital », à comparer à l'actif.
- **Bénéfice net en cours :** calculé dans le compte de classe « Bénéfice net en cours ». S'il manque, un avertissement d'écart s'affiche.

**Spécification PI**
- **Identité :** code `bilan`, catégorie `comptabilite`, action `etats`.
- **Paramètres :** `date` (et `periode_comptable` en raccourci), `inclure_zero`, `entetes_seulement` (booleen), `comparer` (booleen, ajoute une colonne N-1 à la même date).
- **Colonnes :** `section`, `groupe`, `compte`, `libelle`, `montant` (et `precedent`), avec sous-totaux par section et par groupe.
- **Source :** `EtatsFinanciers::bilan(date)`, qui garde le groupe « résultat non clôturé ».

### 4.21 État des résultats : `etat-resultats` (Partiel)

**Ce que fait fripv2 (RepEtatResultat)**
- **Paramètres :**
  - cycle ;
  - une période, ou des périodes cumulées (début et fin) ;
  - raccourcis (année, semestre, trimestre, mois) ;
  - « comparer avec le cycle précédent » : colonne « Précédent », mêmes numéros de périodes ;
  - « inclure les comptes à zéro » ; « entêtes seulement » ;
  - balises : seulement les écritures qui en portent au moins une.
- **Contenu :**
  - revenus affichés en positif ;
  - ligne finale Bénéfice net = revenus − dépenses ;
  - soldes de départ comptés seulement si la période commence au début du cycle (jamais avec les balises).

**Spécification PI**
- **Identité :** code `etat-resultats`, catégorie `comptabilite`, action `etats`.
- **Paramètres :** `du` et `au` (ou `exercice_id` et des périodes), `comparer`, `inclure_zero`, `entetes_seulement` (booleen).
- **Colonnes :** `section`, `groupe`, `compte`, `libelle`, `courant` montant, `precedent` montant, `ecart` montant, `part_ca` pourcentage (facultative).
- **Source :** `EtatsFinanciers::resultat(du, au)`, avec la même fenêtre décalée d'un exercice pour la comparaison.
- **Piège :** pas de balises sur les écritures dans PI (les entités possibles sont client, fournisseur, produit, employé et documents) : filtre impossible sans cet ajout.

### 4.22 Analyse par compte : `analyse-par-compte` (Partiel, mineur)

**Ce que fait fripv2 (écran Analyse par compte)**
- Période : la période comptable en cours par défaut, avec les boutons précédente et suivante.
- Compte : « Tous » ou un seul. Filtres texte par colonne.
- Colonnes : compte, date, n° de transaction, note, concilié, débit, crédit.
- Totaux Débit, Crédit et Solde sur toutes les lignes filtrées. Export Excel.

**Écart avec PI** : `/grand-livre` exige un compte. Rendre `compte_id` facultatif dans `Soldes::grandLivre`, ou créer ce rapport de bibliothèque.
- **Paramètres :** `du`, `au`, `compte_id` (facultatif), `recherche` (texte).
- **Colonnes :** `compte`, `date`, `numero`, `journal`, `libelle`, `reference`, `debit` Σ, `credit` Σ, `solde` (seulement s'il y a un compte).

### 4.23 Plan comptable : `plan-comptable` (Partiel, mineur)

**Ce que fait fripv2 (RepPlanComptable)**
- Filtre : Tous, Actif ou Inactif.
- Colonnes : Numéro, Parent, Nom, Description, Classe, Budgétable, Actif.
- Aucun solde.

**Écart avec PI** : le CSV actuel ne donne ni actif, ni budgétable, ni notes, et n'a pas de PDF. Rapport à créer :
- **Identité :** catégorie `comptabilite`, module `comptabilite` (action `voir`).
- **Paramètre :** `statut` (choix).
- **Colonnes :** `numero`, `parent`, `libelle`, `notes`, `nature` et `type` traduits, `budgetable`, `saisie_manuelle`, `actif`.
- **Source :** `comptes_comptables`.

### 4.24 Rapport des taxes (Fait, écarts mineurs)

fripv2 :
- On choisit un ou deux regroupements de taxes (champ « Regroupement rapport » de chaque taxe).
- Une colonne perçue et une colonne payée par regroupement, avec les numéros de comptes sous les titres.
- En pied : un sommaire par compte du grand livre et la ligne Différence.

PI a les trois niveaux, le sommaire par taxe et les comptes de taxes du journal, en CSV et PDF. Il manque :
- le choix des taxes à afficher ;
- l'XLSX et le courriel ;
- le rappel des critères en tête.

### 4.25 Consignations chez les clients (Fait, écarts mineurs)

PI a le client, la case « quantités nulles », le coûtant, la valeur, le dernier dépôt, les sous-totaux par client, le total et le PDF.

Il manque :
- le filtre **Catégorie** de produits ;
- les sorties Excel et courriel.

### 4.26 Loyers de location (Manquant ; le modèle diffère)

**Ce que fait fripv2 (RepLoyerCylindre)**
- **Suivi quotidien :** cylindres livrés, retournés et en main, par client et par produit.
- **Calcul :**
  - quantité facturable = max(0, solde − quantité sous contrat, exemptés compris) ;
  - taux = celui de la ligne du contrat « cylindre » actif, à défaut le prix de la liste « location cylindre », avec l'escompte du client si plus bas.
- **Colonnes :** Date, Sous contrat, Exempté, Livraisons cumulées, Retours cumulés, Solde, Qté facturée, Taux, Total facturé, Loyer cumulé.
- **Groupes :** client et produit, avec balance précédente ; sous-totaux et grand total.
- **Paramètres :** client, produit, début et fin de facturation, « seulement les loyers à facturer ».

**PI** : le loyer se paie par unité louée et par période, sur la ligne de contrat (`quantite`, `quantite_exemptee`, `quantite_retournee`). Il n'y a pas de suivi quotidien des quantités chez le client.

**Spécification réaliste**
- **Identité :** code `loyers-a-facturer`, catégorie `ventes`, module `contrats_location`. C'est un aperçu à blanc de `facturerLoyers`.
- **Paramètres :** `du`, `au`, `client_id`, `produit_id`, `seulement_a_facturer` (booleen, oui).
- **Colonnes :** `contrat`, `client`, `produit`, `louee`, `exemptee`, `retournee`, `facturable` Σ, `taux` montant, `montant` Σ.
- **Parité complète :** elle exigerait une table de soldes quotidiens alimentée par les BL et les retours. Ne la faire que si le besoin des cylindres est confirmé.

### 4.27 Rapport IA et rapports IA périodiques (Partiel)

**Ce que fait fripv2**
- **Rapport IA :**
  - L'IA rédige un rapport mis en page à partir d'une demande libre (SQL en lecture seule, droits vérifiés).
  - L'aperçu montre 100 lignes, mais les totaux portent sur toutes.
  - Le rapport peut être sauvegardé, partagé avec des collègues (les 100 plus récents sont listés) et réactualisé.
  - Sorties : impression, PDF, Excel, courriel.
- **Rapports IA périodiques :**
  - Récurrence : quotidienne, hebdomadaire (plusieurs jours possibles) ou mensuelle, **à l'heure choisie**.
  - Destinataires : par défaut, l'utilisateur lui-même.
  - Formats : **corps HTML**, PDF ou Excel.
  - Colonnes « prochain envoi » et « dernier statut ».

**PI** : les questions passent par les rapports de la bibliothèque (choix volontaire, plus sûr), avec un historique de 30 questions et une analyse de l'IA dans les envois programmés.

**À ajouter si on vise la parité :**
- « Enregistrer cette réponse » : la question avec les rapports et les paramètres choisis, réexécutable et partageable.
- Dans les envois programmés : un format « tableau dans le corps du courriel », plusieurs jours de la semaine et une heure d'envoi (la tâche quotidienne de 6 h 30 deviendrait horaire).

### 4.28 Export Excel des listes (Partiel)

**Ce que fait fripv2 (f_RapportExcel)**
- Depuis toute grille : XLSX des colonnes affichées, dans leur ordre, avec en-têtes en gras et montants numériques.
- Les filtres sont respectés, toutes pages comprises.
- « Avec les détails » : les lignes dépliables vont dans des feuilles supplémentaires.

**PI** : CSV sur une partie des listes, XLSX seulement dans la bibliothèque.

**Spécification** : ajouter `format=xlsx` aux points d'entrée de liste (`App\Support\ClasseurXlsx`), sur toutes les lignes filtrées, avec un bouton commun dans le web. Priorité aux listes : clients, fournisseurs, produits, factures, commandes, réceptions, encaissements, déboursés, écritures, mouvements d'inventaire.

## 5. Ordre de réalisation conseillé

1. **Prérequis du §3 :** types `booleen`, `texte`, `produit`, `secteur`, `entrepot`, `liste_prix`, `prise_inventaire` ; sous-totaux ; total en ratio ; `action()` ; catégorie `comptabilite`.
2. **Ventes (§4.1) :** `cout_unitaire` figé sur les lignes de facture et d'avoir, puis `ventes-analyse`, puis mise à niveau des 5 rapports de ventes.
3. **Comptes clients et fournisseurs :** §4.13, 4.14, 4.16 (avec l'envoi en lot), 4.2, 4.10, 4.15.
4. **Inventaire :** §4.3, 4.4, 4.6, 4.5.
5. **Achats :** §4.11, 4.12, 4.18.
6. **États financiers en PDF et XLSX :** §4.19 à 4.23.
7. **Documents et étiquettes :** §4.7, 4.8, 4.9.
8. **Le reste :** §4.17, 4.26, 4.27, 4.28, 4.24, 4.25.
