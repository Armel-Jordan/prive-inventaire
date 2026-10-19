# Onglet Gestion, bouton « Base » : ce qui manque dans PI par rapport à fripv2

> Partie de [la liste du reste à faire](../17-RESTE-A-FAIRE.md), état au 19 octobre 2026 (après la PR #131).
> Pour chaque écran : **ce que fait fripv2** (le comportement de référence), **ce que PI a aujourd'hui**, puis
> **ce qu'il faut faire** (taille **P** petite, **M** moyenne, **G** grande).

Sur les 28 options du bouton « Base », 5 sont faites, 13 sont partielles et 10 manquent. Aucune n'est propre au poste de bureau ; seuls deux accessoires le sont (l'utilitaire séparé d'import des départements et le choix de l'imprimante Windows), et ils sont notés à leur option.

**Sources lues :**
- les pages d'aide fripv2 `f_*.html` et `fr_*.html` ;
- le schéma `FripStructure.sql` de fripv2 ;
- le code PI (après la fusion de la PR #131).

**Fichiers PI de référence :**
- `prise-inventaire-api/app/Support/Referentiels.php`, `Permissions.php`, `Droits.php`, `ConfigurationACompleter.php`
- `prise-inventaire-api/app/Services/EntetesVente.php`, `FraisDocuments.php`, `TaxesDocument.php`, `StocksEntrepots.php`, `Etiquettes.php`, `DevisesVente.php`
- `prise-inventaire-api/app/Http/Controllers/Api/TauxChangeController.php`, `LocalisationController.php`
- `prise-inventaire-api/routes/api.php`
- `prise-inventaire-web/src/pages/ReferentielsPage.tsx`, `src/navigation.ts`, `src/pages/ProduitsPage.tsx`

**Conventions PI, valables pour toutes les propositions ci‑dessous (elles ne sont pas répétées à chaque option) :**
- **Ajouter un référentiel générique.**
  - Une entrée dans `Referentiels::definitions()` : `modele`, `regles`, `ordre`, `avec`, `majuscules`, `controle`, `suppression`, `unique_par_defaut`, `apres`.
  - Cela donne les routes `/api/referentiels/{type}`. Les écritures sont contrôlées par le module de droits `referentiels`.
  - Côté écran : un onglet déclaratif dans `ONGLETS` de ReferentielsPage, le type ajouté à `TypeReferentiel` (`services/api.ts`), les libellés FR/EN dans `t.referentiels.tabs/fields/options`.
- **Migrations.** Additives et gardées (`Schema::hasTable/hasColumn`). `tenant_id` indexé, `softDeletes`, index uniques préfixés par `tenant_id` avec un nom explicite de moins de 64 caractères. Modèle avec `BelongsToTenant`. Toute référence validée par `TenantRule::exists('table')->whereNull('deleted_at')`.
- **Suppression.** Les référentiels sont en soft delete, donc les `nullOnDelete` ne se déclenchent jamais.
  - Tout refus « utilisé ailleurs » doit être écrit dans la clé `suppression` du catalogue.
  - Une valeur retirée du référentiel doit rester acceptée sur la fiche qui la porte (modèle : `EntetesVente::regles`).
- **Nouveau module de droits.** Quatre endroits à toucher :
  - `Permissions::MODULES` : onglet, libellés FR/EN, CRUD, actions nommées avec leur base et leur aide FR/EN ;
  - `Droits::MODULES` (préfixe d'adresse) et `Droits::PARTICULIERES` (routes d'action) ;
  - une migration qui règle les droits des groupes existants d'après un module voisin (modèle : `2026_10_17_100072_creer_formats_etiquettes::reglerDroits`) ;
  - une entrée dans `navigation.ts`.
- **Activation.** Par module d'entreprise (`config/modules.php` : achats, ventes, finance) et/ou par une colonne booléenne de `tenant_parametres` dont le défaut reproduit le comportement actuel. Règle maison : « tant qu'aucune fiche n'existe, les écrans restent tels quels ».
- **Langues.** Messages de l'API en français dans `__()`, anglais dans `lang/en.json` (contrôlé par `tests/Unit/TraductionsAnglaisesTest`). Écrans FR/EN, aide `src/aide/ecrans/*.ts`, documentation `docs/flows/*.md`.
- **Tests.** Compléter les fournisseurs de données de `tests/Feature/Referentiels/ReferentielsTest` (cycle complet, champs obligatoires) pour chaque nouveau type ; tests d'isolation entre tenants ; `tests/Feature/Tenant/BelongsToTenantTest`.
- **Traduction des libellés.** fripv2 traduit presque toutes les descriptions de référentiels (table `traduction`, 46 types). Ce point est traité une seule fois, à l'option Langues.

---

### 1. Villes — Manquant

**1. Ce que fait fripv2**
- **Liste des localités.** Un seul champ, **Nom** (100 caractères, obligatoire, accents conservés). Aucune unicité n'est imposée : l'aide la déconseille seulement. Le nom n'est pas traduit.
- **Liste.** Recherche sur le nom, tri naturel, filtre par colonne, copier.
- **Suppression.** Refusée si la ville sert à un code postal ou à la fiche de la compagnie. Renommer se répercute partout (lien par id).
- **Utilisation :**
  - `code_postal.id_ville` (obligatoire) et `compagnie.id_ville` ;
  - dans les adresses clients et fournisseurs, la ville est une liste éditable. Taper une ville inconnue propose « Ajouter « X » », et la ville est créée à l'enregistrement de l'adresse ;
  - la recherche ignore les majuscules et les accents, et compare « par sonorité » : « Lac-Delage » retrouve « Lac Delage » ;
  - une notification nomme les villes et les codes postaux créés ;
  - l'adresse garde la ville en **texte** (une copie), pas un id.
- **Droits :** Voir la liste des villes, Ajouter, Modifier, Supprimer une ville. Historique avant/après.

**2. Ce que PI a aujourd'hui**
- Aucun référentiel. La ville est du texte libre dans `clients.ville`, `client_adresses.ville`, `fournisseurs.ville`, `fournisseur_adresses.ville`, `entrepots.ville` et `employes.ville`.
- `tenant_parametres.adresse` est un seul champ texte.

**3. Ce qu'il faut faire dans PI** (dans un lot « Géographie » avec les options 2 à 4)
- **Table `villes`.**
  - Colonnes : `tenant_id`, `nom` (100), `nom_normalise` (100, minuscules sans accents ni ponctuation), `region_id` (facultatif), `pays_code` (char 2, facultatif), `actif`, `softDeletes`.
  - Index unique (`tenant_id`, `nom_normalise`, `region_id`) : plus strict que fripv2, mais deux « Saint-Pierre » dans deux régions restent possibles.
- **Référentiel `villes`.** Contrôle anti-doublon sur `nom_normalise`. Suppression refusée si un code postal ou les paramètres de l'entreprise l'utilisent.
- **Service `App\Services\Adresses`**, commun aux quatre listes géographiques : `normaliser()`, `trouverOuCreerVille()`.
- **Écran.** Onglet « Villes » dans un nouveau groupe « Géographie » de ReferentielsPage. Liste éditable de la ville dans le composant d'adresse décrit à l'option 4.
- **Droits.** Module `referentiels`. La création implicite depuis une adresse suit le droit de modifier la fiche (client, fournisseur), comme dans fripv2, et laisse une trace au journal d'audit.
- **Activation.** Rien ne change tant que la liste est vide. L'enrichissement automatique depuis les adresses passe par `tenant_parametres.enrichir_repertoire_adresses` (défaut : faux).
- **Tests.** Cycle complet ; doublon refusé malgré les accents ou la casse ; suppression refusée si utilisée ; isolation.
- **Taille** P. **Dépend de** Régions et Pays.

### 2. Provinces (en PI : « Régions ») — Manquant

**1. Ce que fait fripv2**
- **Champs :**
  - **Code** : 5 caractères, mis en majuscules, **unique parmi toutes les provinces**, quel que soit le pays ;
  - **Nom** : 40 caractères, traduisible.
- **Pas de lien vers le pays** dans le schéma : l'aide conseille seulement de créer le pays d'abord.
- **Suppression.** Refusée si un code postal la référence ; elle est logique et retire aussi les traductions.
- **Utilisation :**
  - codes postaux (obligatoire) et `compagnie.id_province` ;
  - liste déroulante des adresses clients et fournisseurs, valeur enregistrée en texte ;
  - champ texte de l'entrepôt.
- **Valeur par défaut.** La province de la compagnie est proposée sur toute nouvelle adresse, tout nouveau code postal et tout nouvel entrepôt.
- **Création depuis une adresse.** Possible : le nom tapé est pré-rempli et la nouvelle province est sélectionnée.
- **Droits :** Voir, Ajouter, Modifier, Supprimer une province.

**2. Ce que PI a aujourd'hui**
- `region` en texte libre (`client_adresses`, `fournisseur_adresses`, `entrepots`).
- Rien sur les fiches principales des clients et fournisseurs, ni sur l'entreprise.

**3. Ce qu'il faut faire dans PI**
- **Table `regions`** (nom générique, valable pour tous les pays).
  - Colonnes : `tenant_id`, `pays_code` (char 2, **obligatoire**), `code` (10, majuscules), `nom` (100), `type` libre et facultatif (province, État, région, département, canton…), `actif`, `softDeletes`.
  - Index unique (`tenant_id`, `pays_code`, `code`) : unique **par pays**, et non global comme fripv2. Plusieurs pays emploient les mêmes codes courts.
- **Référentiel `regions`.**
  - Code en majuscules, filtre par pays.
  - Suppression refusée si une ville ou un code postal l'utilise.
  - Bouton facultatif « Importer les régions de ce pays » depuis un fichier fourni `resources/data/regions/{CC}.json` (ISO 3166-2 : CA, US, FR, CM, SN, CI…).
- **Entreprise.** `tenant_parametres.region_code` sert de défaut aux adresses.
- **Écran.** Libellé « Régions / provinces / États ».
- **Droits, tests et activation :** comme Villes.
- **Taille** P.
- **Piège.** Ne pas appeler la table `provinces` : ce serait spécifique au Canada.

### 3. Pays — Manquant

**1. Ce que fait fripv2**
- **Champs :** **Code** (5 caractères, majuscules, unique) et **Nom** (30 caractères, traduisible).
- **Suppression.** Refusée si un code postal l'utilise. La FAQ parle aussi des provinces et des adresses.
- **Utilisation :** codes postaux (obligatoire), `compagnie.id_pays`, liste des adresses (valeur en texte), entrepôt (texte, défaut = pays de la compagnie).
- Création possible depuis une adresse.
- **Droits :** Voir, Ajouter, Modifier, Supprimer un pays.

**2. Ce que PI a aujourd'hui**
- `pays` en texte libre sur `client_adresses`, `fournisseur_adresses`, `fournisseurs`, `entrepots` et `employes`.
- Ni la fiche principale du client ni l'entreprise n'ont de pays.

**3. Ce qu'il faut faire dans PI**
- **Table `pays`.**
  - Colonnes : `tenant_id`, `code` (char 2, ISO 3166‑1 alpha‑2, majuscules), `nom` (100), `indicatif_tel`, `format_code_postal` (masque, par exemple « A9A 9A9 » ou « 99999 »), `devise_code`, `actif`.
  - Index unique (`tenant_id`, `code`).
- **Liste ISO.** Préchargée à la première visite, comme les formats d'étiquette, depuis `resources/data/pays.json` (noms FR et EN). L'entreprise désactive les pays qu'elle n'utilise pas.
- **Colonne `pays_code` (char 2, facultative)** ajoutée à `clients`, `fournisseurs`, `client_adresses`, `fournisseur_adresses`, `entrepots` et `tenant_parametres`. `tenant_parametres` reçoit aussi `ville`, `region_code` et `code_postal`.
- **Rattrapage des données.** Une migration remplit `pays_code` en comparant les textes existants aux noms FR/EN normalisés. Les colonnes texte restent en place.
- **Utilisation générique du pays :** PDF des documents, document de transport des matières dangereuses, fichiers SEPA (pays du bénéficiaire), taxes à l'export.
- **Suppression** refusée si une région, une ville, un code postal ou l'entreprise l'utilise.
- **Taille** P.
- **Piège.** `fournisseurs.pays` existe déjà en texte : ne pas la renommer, ajouter `pays_code`.

### 4. Codes postaux — Manquant

**1. Ce que fait fripv2**
- **Répertoire, champs :**
  - **Code** : 20 caractères, mis en majuscules, unique. Il s'affiche « G9P 4C4 » avec une espace au milieu (format canadien). L'aide est contradictoire : la FAQ de la liste admet plusieurs rues pour un même code, mais la règle d'enregistrement exige un code unique ;
  - **Rue** : 150 caractères, facultative ;
  - **Ville** : obligatoire ;
  - **Province** : obligatoire, défaut = province de la compagnie ;
  - **Pays** : obligatoire, défaut = pays de la compagnie.
- **Vérifications, dans l'ordre :** code vide, code déjà utilisé, ville, province, pays.
- **Suppression** toujours permise : les adresses gardent leur propre copie en texte.
- **Dans les adresses clients et fournisseurs :**
  - à la sortie du champ code postal (Tab ou Entrée), un code connu remplit la rue, la ville, la province et le pays **seulement s'ils sont vides** ;
  - la province et le pays de la compagnie, inscrits d'office, sont remplacés tant que l'usager n'y a pas touché ;
  - à l'enregistrement de l'adresse, les espaces sont retirés du code ;
  - un code inconnu est **ajouté automatiquement au répertoire** avec sa ville, sa province et son pays ; une ville nouvelle est créée ; une notification l'indique.
- **Droits :** Voir, Ajouter, Modifier, Supprimer un code postal.

**2. Ce que PI a aujourd'hui**
- Rien. `code_postal` est du texte : 10 caractères dans `clients`, 20 dans `client_adresses` et `entrepots`.

**3. Ce qu'il faut faire dans PI**
- **Table `codes_postaux`.**
  - Colonnes : `tenant_id`, `pays_code`, `code` (20, tel que saisi en majuscules), `code_normalise` (sans espaces), `rue` (150, facultative), `ville_id`, `region_id`, `actif`, `softDeletes`.
  - Index unique (`tenant_id`, `pays_code`, `code_normalise`, `rue`).
  - Normalisation : majuscules et espaces retirées. **Garder les tirets** (Pologne, Japon, Brésil). Affichage selon `pays.format_code_postal`.
- **API.**
  - Référentiel `codes_postaux`.
  - Recherche libre `GET /api/adresses/code-postal?pays=&code=` (lecture seule).
  - `Adresses::completer()` remplit seulement les champs vides.
  - `Adresses::enrichir()` est appelé à l'enregistrement des adresses clients, fournisseurs, entrepôts et entreprise si `enrichir_repertoire_adresses` est vrai.
- **Composant web `ChampAdresse`.**
  - Contenu : adresse, code postal, ville (liste avec « Ajouter »), région (liste filtrée par pays), pays.
  - Défauts : le pays et la région de l'entreprise.
  - Message « Ajouté au répertoire : … » après un enrichissement.
  - Utilisé par la fiche client (adresses), la fiche fournisseur, l'onglet Entrepôts et Configuration → Entreprise.
- **Import CSV** du répertoire, facultatif.
- **Droits.** Module `referentiels` pour l'écran. La recherche est libre. L'enrichissement suit le droit de la fiche modifiée.
- **Tests.** La complétion n'écrase pas un champ déjà saisi ; enrichissement activé ou non ; normalisation ; isolation.
- **Taille** du lot Géographie (options 1 à 4) : **M**.
- **Pièges.** Ne pas transformer les colonnes texte des adresses en clés étrangères : les documents doivent garder leur copie. Laisser `clients.code_postal` à 10 caractères.

### 5. Territoires — Partiel

**1. Ce que fait fripv2**
- **Champs :** **Description** (40 caractères, traduisible) et **Actif** (coché par défaut). Pas de code.
- **Liste.** Bascule Actif / Inactif ; un double-clic dans la colonne Actif active ou désactive.
- **Suppression.** Refusée si un client y est rattaché. Un territoire désactivé disparaît des nouvelles sélections mais reste sur les fiches et dans les rapports.
- **Utilisation :**
  - **fiche client** (`client.id_territoire`) : un territoire par client, **exigé** sauf licence « comptabilité seule » ;
  - colonne, recherche et filtre de la liste des clients ; export Excel des clients d'un territoire ;
  - colonne facultative « territoire du client » dans les listes de documents ;
  - analyses de ventes par territoire.
- **Droits :** Voir, Ajouter, Modifier ou désactiver, Supprimer un territoire.

**2. Ce que PI a aujourd'hui**
- Référentiel `territoires` : code (20, unique), nom, description, actif.
- Utilisé seulement par `itineraires.territoire_id` et `representants.territoire_id`, deux liens propres à PI. La suppression est refusée s'ils l'utilisent.
- **Absent des clients, des listes de documents et des rapports.**

**3. Ce qu'il faut faire dans PI**
- **Donnée.** `clients.territoire_id` : nullable, indexé, sans clé étrangère, comme `representant_id`. Validation `TenantRule::exists`, en acceptant la valeur actuelle même si le territoire est désactivé.
- **API.**
  - `ClientController` : filtre `?territoire_id=` et recherche.
  - Ajouter « clients rattachés » à la clé `suppression`.
  - Import et export CSV des clients : colonne territoire (par code).
- **Écrans.**
  - Fiche client, onglet commercial.
  - Colonne et filtre dans `ClientsPage` et `ListeClientsFiche`.
  - Colonne facultative « Territoire du client » dans les listes de devis, commandes et factures. Elle passe par une jointure et n'est pas figée, comme dans fripv2.
- **Rapport `VentesParTerritoire`** (bibliothèque, catégorie ventes) : factures émises de la période par territoire **actuel** du client, moins les notes de crédit, sur le modèle de `VentesParRepresentant`. Paramètre « territoire » dans `VentesParClient` et `ClientsInactifs`.
- **Aide à la saisie** (pas une règle fripv2) : proposer le territoire du représentant choisi.
- **Activation.** Rien n'apparaît sans territoire actif. Option `tenant_parametres.territoire_obligatoire` (défaut : faux) pour reproduire l'exigence fripv2.
- **Droits.** Module clients pour l'affectation.
- **Tests.** Affectation, filtre, rapport, territoire d'un autre tenant refusé, suppression refusée.
- **Taille** P.

### 6. Routes — Partiel

**1. Ce que fait fripv2**
- **Champs :** **Code** (10 caractères, majuscules, unique) et **Description** (30 caractères, traduisible).
- **Suppression** refusée si une adresse client la référence.
- **Sur chaque adresse de livraison** du client : **Route** et **Séquence route** (6 chiffres), toutes deux facultatives. Vider la route retire l'adresse de la tournée.
- **Documents.** Le BL et la facture figent `route_code_origine`, le code de route au moment du document. Le BL imprime la route et la séquence.
- **Écran « Séquence de route »** (menu Expédition) : range les bons par route, avec une colonne « Sans route ».
- **Droits :** Voir, Ajouter, Modifier, Supprimer une route de livraison, et **Organiser les séquences des routes**.

**2. Ce que PI a aujourd'hui**
- Référentiel `itineraires` : code, nom, territoire, description, actif.
- `clients.itineraire_id` et `sequence_livraison` : **au niveau du client**, pas de l'adresse.
- `tournees.itineraire_id`, ajout des bons de la route, tri par séquence, feuille de route (`SequencesLivraison`, `FeuilleDeRoutePdf`).
- Pas de contrôle de suppression. Rien n'est figé sur le BL ni sur la facture.

**3. Ce qu'il faut faire dans PI**
- **Route par adresse.** `client_adresses.itineraire_id` et `sequence_livraison`. La route de l'adresse de livraison du bon l'emporte ; à défaut, celle du client (comportement actuel). `SequencesLivraison::ajouterRoute()` et `ordonner()` lisent la route de cette adresse.
- **Instantané sur les documents.** `itineraire_code` et `sequence_livraison` sur `bons_livraison`, et `itineraire_code` sur `factures`, figés à la création. Affichés sur le PDF du BL et sur la feuille de route.
- **Suppression** refusée si un client, une adresse ou une tournée planifiée l'utilise.
- **Menu.** Libellé « Routes (itinéraires) ».
- **Droits.** Inchangés : « Organiser les séquences » correspond déjà à `tournees.piloter`.
- **Taille** P.

### 7. Unités — Fait

- **Couverture.**
  - Référentiel `unites` : code, libellé, symbole, décimales.
  - La mesure du produit se choisit dans ce référentiel (ProduitsPage).
  - Unités de vente `produit_unites`. Unité et facteur figés sur les lignes de devis, commandes, factures, BL, notes de crédit, retours et reliquats.
  - Unité d'achat par fournisseur (`produit_fournisseur.unite_achat`).
- **Petits écarts :**
  - la suppression ne vérifie que les conversions. fripv2 la refuse aussi si l'unité est la mesure d'un produit, une unité de vente, une unité d'achat, ou figure sur des lignes : compléter la clé `suppression` ;
  - libellé non traduisible (voir Langues) ;
  - fripv2 a une unité de poids et de volume par produit ; PI utilise kg et m³ fixes, ce qui est acceptable.

### 8. Conversions — Fait

- **Couverture.** `unite_conversions` (facteur supérieur à 0, une conversion par couple) et `App\Support\ConversionsUnites` : conversions dans les deux sens, enchaînées. Le facteur est proposé dans la fenêtre des unités du produit.
- **Écart cosmétique.** fripv2 ajoute une description traduisible (30 caractères).

### 9. Formats — Manquant

**1. Ce que fait fripv2**
- **« Formats de produit » :** **Nom** (30 caractères, traduisible) et **Code** (10 caractères, majuscules, unique parmi les formats actifs).
- **Rôle.** Le format décrit la présentation ou le conditionnement : paquet, boîte, palette, caisse de 12, sac de 5 kg. C'est une étiquette descriptive : aucun calcul de quantité ni de prix.
- **Utilisation.** Fiche produit, onglet Spécification, section **Matières dangereuses** : « contenant ou conditionnement réglementaire », à côté du numéro UN et de la classe de risque.
- **Suppression** refusée si un produit, même inactif, l'utilise.
- **Droits :** Voir, Ajouter, Modifier, Supprimer un format de produit.

**2. Ce que PI a aujourd'hui**
- Rien.
- Les produits ont `numero_onu`, `classe_danger`, `groupe_emballage`, `poids_kg`, `volume_m3` et `unite_achat` (texte, par exemple « Carton de 12 »).
- Le document de transport des matières dangereuses (`MatieresDangereusesPdf`) ne précise pas le type de contenant.

**3. Ce qu'il faut faire dans PI**
- **Table `formats_produit`** : `tenant_id`, `code` (10, majuscules, unique), `nom` (100), `actif`, `softDeletes`. Référentiel `formats_produit` dans le groupe Produits.
- **`produits.format_id`** facultatif.
- **Écrans.** Champ dans la fiche produit (section Transport / matières dangereuses). Colonne et filtre dans la liste des produits. Colonne `format` dans l'import et l'export CSV.
- **Impression** du type de contenant sur le document de transport des matières dangereuses et sur la fiche de chargement.
- **Suppression** refusée si des produits l'utilisent.
- **Taille** P.
- **Pièges.** Ne pas confondre avec la colonne « Format » des lignes de facture fripv2, qui est l'unité de vente (déjà couverte par `produit_unites`), ni avec les formats d'étiquette.

### 10. Formats d'étiquette — Partiel

**1. Ce que fait fripv2**
- **Champs :**
  - **Code** : 30 caractères, unique **par famille** ;
  - **Description** : traduisible ;
  - **Type d'étiquette** : Produit ou **Boîte** (étiquette de colis du BL) ;
  - **Gabarit** : fichier de mise en page ;
  - **Mode d'ajustement** : Fixe, ou Compacter (l'espace libéré par une option décochée est absorbé) ;
  - **Par défaut**, **Actif**, **Ordre** ;
  - **Options** cochées, lues dans le gabarit : prix, code-barres, numéro de produit, description, date d'impression, chiffres sous le code-barres.
- **Trois formats livrés :** Complet, Sans prix, Code-barre seul.
- **Héritage.** Le format se choisit sur le **produit**, sinon sa **catégorie**, sinon la **compagnie**, sinon le format « Par défaut ».
- **Suppression** refusée si le format est référencé ; il faut le désactiver.
- **Droit unique :** « Gérer les formats d'étiquette ».

**2. Ce que PI a aujourd'hui**
- Table `etiquette_formats` : nom unique, support rouleau ou planche, dimensions, grille, contenu JSON, taille de police, par défaut, actif, ordre. Quatre formats d'origine. La mise en page calculée joue le rôle du mode « Compacter ».
- Page Étiquettes (`EtiquettesPage`) ; droits `etiquettes.voir` (imprimer) et `etiquettes.formats`.
- **Manque :**
  - la famille : seulement des produits ; les étiquettes de colis 76×51 mm sont codées en dur ; aucune étiquette d'emplacement ;
  - l'héritage produit → catégorie → entreprise.

**3. Ce qu'il faut faire dans PI**
- **Données.**
  - `etiquette_formats.famille` (`produit` par défaut, `colis`, `emplacement`) ; nom unique et format « par défaut » **par famille**.
  - `produits.etiquette_format_id` et `categories.etiquette_format_id`, facultatifs. Résolution : produit, puis catégorie et ses ancêtres, puis défaut de la famille.
- **Contenus par famille.**
  - Colis : QR ou Code 128 du numéro, rang « 1/2 », client, adresse de livraison, transporteur, poids, note.
  - Emplacement : voir l'option 24.
- **Formats d'origine** : colis 76×51 mm et emplacement 76×51 mm.
- **`ColisLivraisons`** imprime désormais par `Etiquettes::pdf()` avec la famille colis.
- **Suppression** refusée si un produit ou une catégorie référence le format.
- **Droits** inchangés.
- **Taille** M. **Dépend de** Catégories (option 13) et Emplacements (option 23).

### 11. Taxes — Partiel

**1. Ce que fait fripv2**
- **Champs :**
  - Description courte (4) et Description (40, traduisible) ;
  - **Opérateur** : `*` = pourcentage, `+` = **montant fixe** ajouté à chaque ligne ;
  - Taux ;
  - **Compte GL** : obligatoire, uniquement les comptes 2xxxx ;
  - **Fournisseur de remise** : l'organisme à qui la taxe est versée ;
  - **Type** : perçue sur vente **ou** payée sur achats. Une même taxe (TPS par exemple) fait donc deux fiches ;
  - **Regroupement rapport** : numéro qui réunit des taxes dans le rapport de taxes ; 0 = hors rapport.
- **Rattachement aux produits.** Une nouvelle taxe est **rattachée à tous les produits** (`produit_taxe`) et se retire produit par produit.
- **Calcul.** Sur une ligne de vente, une taxe ne s'applique que si elle figure à la fois dans les taxes du produit **et** dans le groupe de taxes du document.
- **Suppression** refusée si la taxe est dans un groupe ou a servi sur un devis, une commande ou une facture.
- Changer le compte n'est pas rétroactif.
- **Droits :** Voir, Ajouter, Modifier, Supprimer une taxe.

**2. Ce que PI a aujourd'hui**
- `tenant_taxes` : nom, taux de 0 à 100 à 4 décimales, par défaut. Routes `/api/taxes` (`TenantTaxeController`, module configuration), écran Configuration → Devise & TVA.
- Suppression refusée si la taxe est dans un groupe.
- Comptes collectée et déductible **par taxe** dans les correspondances de la comptabilisation : une seule fiche sert aux deux sens, ce qui est mieux que fripv2.
- Rapport des taxes (ventes et achats).
- **Manque :** code court, portée (vente / achat / les deux), montant fixe, regroupement du rapport, organisme de remise, **exemptions par produit**, traduction.

**3. Ce qu'il faut faire dans PI**
- **Colonnes ajoutées à `tenant_taxes` :**
  - `code` (10, facultatif, unique s'il est saisi) ;
  - `portee` : `les_deux` par défaut, ou `vente`, `achat` ;
  - `mode_calcul` : `pourcentage` par défaut, ou `montant_unitaire`, `montant_ligne` ;
  - `montant`, `regroupement_rapport`, `fournisseur_remise_id`, `actif`.
- **Exemptions.** Table `produit_taxes_exclues` (`tenant_id`, `produit_id`, `tenant_taxe_id`). C'est l'inverse de fripv2 et c'est additif : sans ligne, toutes les taxes s'appliquent. `TaxesDocument` retire de la ligne les taxes exclues du produit.
- **Calcul.** `CalculTaxes` gère le montant fixe (par unité ou par ligne). `document_taxes` garde le mode dans son instantané.
- **Rapport des taxes** regroupé par `regroupement_rapport`. En option : « Préparer la facture de remise » pour l'organisme (taxes collectées moins déductibles de la période).
- **Écrans.** Formulaire de taxe étendu ; section « Taxes applicables » dans la fiche produit.
- **Droits.** `configuration` pour les taxes ; `produits.modifier` pour les exclusions.
- **Tests.** Montant fixe, exemption, regroupement, isolation, et calcul identique à l'actuel avec les valeurs par défaut.
- **Taille** M.
- **Piège.** Le taux est plafonné à 100 : un montant fixe ne doit pas passer par `taux`.

### 12. Groupe de taxes — Partiel

**1. Ce que fait fripv2**
- **Champs :** Description (100 caractères, traduisible), **Type** (vente ou achat), taxes ordonnées du même type (chaque taxe une seule fois).
- **Calcul.** Pas de cascade : aucune taxe n'est calculée sur une autre. L'ordre sert seulement à l'extraction « taxes incluses » : l'écart d'arrondi va sur la dernière taxe en pourcentage.
- **Rattachement :**
  - **client** (`client.id_groupe_taxe`) ;
  - **fournisseur** ;
  - **produit** : `id_grp_taxe_vente` et `id_grp_taxe_achat` ;
  - **en-tête des documents** (`id_grp_taxe` sur devis, commande, BL, facture et commande fournisseur), obligatoire. Le groupe et la devise ne changent plus après la création de la facture.
- **Droits :** Voir, Ajouter, Modifier, Supprimer un groupe de taxes.

**2. Ce que PI a aujourd'hui**
- `groupes_taxes` (code, nom, par défaut, actif) et `groupe_taxe_lignes` (ordre ; application sur la base, composée, ou taxe sur la taxe). C'est plus riche que fripv2 pour le calcul.
- `groupe_taxe_id` sur les lignes de commande client, de facture, de note de crédit et de facture fournisseur.
- Résolution : groupe de la ligne, sinon taux saisi, sinon groupe par défaut, sinon taux de l'entreprise.
- **Manque :**
  - la portée vente / achat ;
  - un groupe par défaut **par client** (exonération, export, autre province), **par fournisseur** et **par produit** ;
  - un groupe en en-tête de document ;
  - les **devis n'ont aucune taxe par ligne** : `devis_lignes` n'a que le prix, et les taxes ne sont calculées qu'à la conversion.

**3. Ce qu'il faut faire dans PI**
- **Données.**
  - `groupes_taxes.portee` (`les_deux` par défaut).
  - `clients.groupe_taxe_id`, `fournisseurs.groupe_taxe_id`, `produits.groupe_taxe_vente_id`, `produits.groupe_taxe_achat_id`.
  - `groupe_taxe_id` en en-tête de `devis`, `com_client_entete`, `factures`, `com_four_entete` et `factures_fournisseurs`. Il est repris du tiers à la création (ajouter le champ à `EntetesVente::CHAMPS`) et figé après l'émission.
- **Résolution d'une ligne de vente** (`TaxesDocument::groupePour`), dans l'ordre :
  1. le groupe de la ligne ;
  2. un groupe d'exonération (vide) sur l'en-tête, qui l'emporte ;
  3. le groupe de vente du produit (taux réduit, 0 %) ;
  4. le groupe de l'en-tête ;
  5. le groupe par défaut ;
  6. le taux de l'entreprise ;
  
  puis on retire les taxes exclues du produit (option 11). Les achats suivent la même logique avec le fournisseur.
- **Devis.** `devis_lignes.groupe_taxe_id`, `montant_tva`, `montant_ttc` et `document_taxes` de type `devis`.
- **Écrans.** Fiches client, fournisseur et produit ; en-têtes des documents.
- **Activation.** Si toutes ces valeurs sont vides, le calcul est celui d'aujourd'hui.
- **Tests.** Priorités, exonération du client, produit à taux réduit, conversion devis → commande → facture, isolation.
- **Taille** M à G.
- **Piège.** Ne jamais recalculer les documents déjà émis.

### 13. Catégories de produits — Partiel

**1. Ce que fait fripv2**
- **Liste plate :** Description (30 caractères, traduisible) et **Produit générique** (facultatif, actif, et rattaché à cette catégorie).
- **Deux niveaux sur le produit.** La fiche produit choisit une **catégorie** et une **sous-catégorie** dans cette même liste (`id_produit_categorie`, `id_produit_sous_categorie`).
- **Suppression** refusée si un produit l'utilise, en catégorie ou en sous-catégorie.
- **Produit générique.**
  - Il sert de modèle à un nouveau produit de la catégorie.
  - Il alimente la saisie rapide d'un produit non inventorié : description, département, fournisseur principal et unité de vente inscrits d'office ; taxes et lignes de prix recopiées.
  - Il ne peut pas être supprimé tant qu'il est désigné.
- **Autres usages.**
  - Escomptes par catégorie (règles de prix).
  - Rapport de ventes regroupé ou filtré par catégorie.
  - Héritage du format d'étiquette par la catégorie.
- **Droits :** Voir, Ajouter, Modifier, Supprimer une catégorie de produits.

**2. Ce que PI a aujourd'hui**
- **Le référentiel existe et est riche :** `categories` hiérarchique (code, nom, parent, description, actif), protégé contre les boucles, nom unique entre sœurs, suppression refusée s'il a des sous-catégories.
- **Mais il n'est relié à aucun produit.**
  - `produits.categorie` est un texte libre de 100 caractères que **le formulaire produit n'édite même pas** : seul le champ « Type » est éditable.
  - Ce texte sert pourtant à : la grille et les révisions de prix (filtre), `regles_prix.categorie_produit` (texte), la comptabilisation (correspondance catégorie → comptes, par nom), le filtre du portail, la valorisation et le rapport `VentesParCategorie`.

**3. Ce qu'il faut faire dans PI**
- **Lier les produits.**
  - Ajouter `produits.categorie_id`.
  - Migration de données : pour chaque texte distinct de `produits.categorie` d'un tenant, trouver ou créer la catégorie (à la racine), puis lier. Dédoublonner en ignorant la casse et les accents.
  - **Garder `categorie` (texte) synchronisé** avec le nom de la catégorie, par un observateur de `ProduitTenant`, pour ne casser aucun usage existant.
- **Écrans.** Sélecteur arborescent dans la fiche produit (la sous-catégorie fripv2 devient un enfant). Filtre de la liste, enfants compris. Colonne `categorie` dans l'import et l'export CSV.
- **Produit modèle.** `categories.produit_modele_id` et un bouton « Nouveau produit dans cette catégorie » qui reprend du modèle : mesure, unités, fournisseur principal, groupes de taxes, fabricant, secteur, prix. Refuser de supprimer un produit modèle.
- **Autres liens.**
  - `categories.etiquette_format_id` (option 10).
  - `regles_prix.categorie_id`, enfants compris.
  - Correspondance comptable par id, avec le nom en repli.
  - `VentesParCategorie` : regroupement par catégorie racine ou feuille.
- **Suppression** refusée si des produits sont rattachés.
- **Tests.** Migration de données sur deux tenants qui ont les mêmes noms, synchronisation du texte, filtre avec enfants, isolation.
- **Taille** M.

### 14. Départements — Manquant

**1. Ce que fait fripv2**
- **Champs :**
  - Description (30 caractères, traduisible) ;
  - **Devise** : obligatoire ;
  - **4 comptes GL** de cette devise : revenu de vente, stock de marchandises (actif), CMV (dépense), dépense d'achat (charge directe). Ils sont facultatifs mais recommandés.
- **Règles.**
  - Changer la devise vide les quatre comptes.
  - Le **CMV** n'est passé (CMV débité, stock crédité à la vente) que si le stock **et** le CMV sont réglés ; sinon un avertissement signale un inventaire périodique.
- **Utilisation :**
  - **chaque produit doit avoir un département** ;
  - les lignes de devis, commande, BL et facture figent `id_departement` ;
  - le compte de revenu d'une ligne de facture vient par défaut du département et se change ligne par ligne ;
  - le rapport de ventes se regroupe et se filtre par département ;
  - la ligne de transport du compte à payer prend la dépense d'achat du département du produit du frais.
- **Suppression** refusée si un produit ou une ligne de document l'utilise.
- **Import des départements.** C'est un utilitaire séparé, propre au poste de bureau (fichier de connexion + clé) : **hors périmètre**. Sa fonction reste utile : un CSV « groupe ; numéro de produit ; code département », appliqué en tout ou rien.
- **Droits :** Voir, Ajouter, Modifier un département et ses comptes, Supprimer.

**2. Ce que PI a aujourd'hui**
- Rien. Le mot « département » n'existe que dans la fiche RH des employés.
- La comptabilisation affecte les ventes et les achats par catégorie (correspondances) ou aux comptes par défaut.
- Aucune écriture de stock ni de CMV.

**3. Ce qu'il faut faire dans PI**
- **Table `departements`** : `tenant_id`, `code` (20, unique), `libelle` (100), `devise_code`, `actif`, `softDeletes`. Référentiel générique, libellé « Départements (familles comptables) ».
- **Lien produit.** `produits.departement_id`.
- **Instantané `departement_id`** sur `facture_lignes`, `com_client_ligne`, `devis_lignes`, `bon_livraison_lignes`, `avoir_lignes`, `facture_fournisseur_lignes` et `reception_arrivages_ligne`, copié à la création de la ligne.
- **Comptes.** Ils se règlent dans l'écran Comptabilisation, pas dans le référentiel : nouvelle correspondance `departements` → {ventes, achats, stock, cmv} dans `ParametreComptable::correspondances` (déjà en JSON).
  - Compte d'une ligne : département, sinon catégorie, sinon défaut.
  - Option `inventaire_permanent` : écriture CMV / stock au coût moyen de la valorisation, seulement si stock et CMV sont réglés.
- **Compte de revenu par ligne de facture.** `facture_lignes.compte_vente_id`, facultatif.
- **Rapports.** `VentesParDepartement` et une marge par département.
- **Import CSV** « numéro de produit ; code département », en tout ou rien ; un produit introuvable n'est qu'un avertissement.
- **Droits.** Référentiels pour la liste ; `comptabilite.ecrire` pour les comptes (comme les réglages actuels) ; `produits.modifier` pour l'affectation.
- **Activation.** Module finance. Le champ n'apparaît sur le produit que si des départements existent.
- **Taille** M, ou G avec l'inventaire permanent.
- **Piège.** Ambiguïté avec le département RH des employés : soigner les libellés.

### 15. Frais de livraison — Partiel

**1. Ce que fait fripv2**
- **Champs :**
  - Description (40 caractères, traduisible) ;
  - 7 cases « Livrable dimanche » à « samedi » ;
  - **Heure tombée** (hh:mm:ss, obligatoire) et **Jour tombé** (Même jour / Jour précédent, obligatoire). Ces trois réglages sont **purement informatifs** : ils ne changent aucune date et ne bloquent rien ;
  - **Produit** : facultatif, mais sans produit aucune ligne n'est ajoutée ;
  - **Montant** : c'est le prix de la ligne, et non le prix du produit.
- **Côté ventes.** Choisi dans la fiche client, le frais ajoute la ligne (produit, quantité 1, montant) sur les devis, commandes et factures **si le transporteur du document l'accepte** (voir option 21). Un document sans transporteur reçoit le frais.
- **Côté achats.** Désigné dans la fiche d'un transporteur, il ajoute une ligne de transport au compte à payer.
- La ligne de livraison n'entre jamais dans la base d'un frais en pourcentage.
- **Suppression** refusée si un client l'utilise ; un transporteur ne la bloque pas.
- **Droits :** Voir, Ajouter, Modifier, Supprimer des frais de livraison.

**2. Ce que PI a aujourd'hui**
- `frais` avec `application = 'livraison'` et `clients.frais_livraison_id`.
- `FraisDocuments` propose la ligne pendant la saisie (`POST /frais/calculer`, route libre). Les lignes de frais n'entrent pas dans les autres frais.
- Supprimer un frais le retire des clients (fripv2 refuse : différence assumée).
- **Manque :** le blocage par le transporteur, la ligne de transport côté achats et les champs descriptifs.

**3. Ce qu'il faut faire dans PI** (après Transporteurs)
- **Blocage.** `FraisDocuments` reçoit `transporteur_id`. Si le transporteur a `frais_livraison_applicable = false`, aucun frais `livraison` n'est proposé. Le web relance le calcul quand le transporteur change. Une ligne de frais déjà modifiée ou retirée n'est pas reposée.
- **Champs descriptifs.** `heure_limite` (time), `veille` (booléen), `jours_livrables` (JSON). En option, une extension qui va au-delà de fripv2 : proposer une date de livraison prévue.
- **Côté achats.** `transporteurs.frais_livraison_achat_id`. À la création d'une facture fournisseur d'un fournisseur livré par ce transporteur, ajouter la ligne « Frais de transport – {transporteur} » :
  - au montant du frais, avec le groupe de taxes du produit ;
  - sur le compte de charge du produit du frais (département, sinon catégorie) ;
  - non enregistrée à 0,00 ; pas de doublon si ce compte est déjà proposé pour ce fournisseur.
- **Taille** P + P. **Dépend de** Transporteurs (et Départements pour le compte).

### 16. Frais additionnels — Partiel

**1. Ce que fait fripv2**
- **Champs :**
  - Description (40 caractères, traduisible) ;
  - **Portée** : Vente ou **Achat** ;
  - **Base de calcul** (achat seulement) : quantité, **poids** ou **volume** de la fiche produit ;
  - **Type de montant** : fixe (0 accepté, mais aucune ligne alors) ou pourcentage (4 décimales, supérieur à 0) ;
  - **Montant minimum** et **maximum** : 0 = aucune limite ; le maximum ne peut pas être inférieur au minimum ;
  - **Produit-frais** (vente) : obligatoire si le frais est actif, et doit être un service non inventorié ;
  - **Compte de dépense** (achat) : obligatoire si le frais est actif ;
  - **Mode d'application** : par document ou par produit (grille « Frais applicables » de la fiche produit) ;
  - **Comptabilisation** : une fois par document, une fois par ligne, ou par unité de la base ;
  - **« Retire ces frais »** : exclusions entre frais, sans cycle même indirect ;
  - Actif ; **s'applique aux loyers**.
- **Comportement en vente.**
  - La ligne de frais est recalculée à chaque changement : un prix corrigé à la main ne tient pas.
  - **Retirer la ligne exclut le frais de ce document de façon durable** (table `document_frais_exclu`).
  - La base d'un pourcentage est la marchandise hors taxes, sans aucune ligne de frais.
  - Sur une facture, seules les lignes réellement facturées comptent.
  - Minimum et maximum portent sur le total du frais. Un frais « par ligne » plafonné tombe en une seule ligne.
- **Comportement en achat.**
  - Un frais « par produit » apparaît comme « Frais de surcharge » dans les totaux de la commande fournisseur et sur le bon de commande imprimé, puis comme ligne de ventilation au compte à payer, sur le compte de dépense du frais.
  - Un frais « par document » va directement au compte à payer.
  - Un produit peut être exempté chez un fournisseur (« Frais à l'achat » = 0 ; vide = tarif du frais). Un produit ne porte qu'un seul frais d'achat.
- **Droits :** Voir, Ajouter, Modifier, Supprimer des frais additionnels.

**2. Ce que PI a aujourd'hui**
- **Côté ventes :** libellé, produit, montant ou pourcentage, application (document / produits / livraison), répétition, choix des documents (devis, commandes, factures), actif, matières dangereuses (page `FraisPage`, module configuration).
- Le retrait d'un frais **n'est gardé qu'en mémoire de l'écran** (`useFraisAutomatiques`) : il revient à la réouverture d'un brouillon.
- `frais_produits` n'a pas de `tenant_id`.
- La documentation des achats l'annonce : « À venir : les frais d'achat de fripv2 ».

**3. Ce qu'il faut faire dans PI**
- **Ventes.**
  - `frais.montant_min` et `montant_max`.
  - Table `frais_exclusions` (`tenant_id`, `frais_id`, `frais_retire_id`), avec refus des cycles.
  - Table `document_frais_exclus` (`tenant_id`, `document`, `document_id`, `frais_id`), alimentée quand l'utilisateur retire une ligne de frais et lue par `FraisDocuments`.
  - `sur_loyers` : factures de contrats de location et d'abonnements.
  - Seules les lignes facturées comptent.
- **Achats.**
  - Sur `frais` : `portee` (`vente` par défaut), `base_calcul`, `compte_charge_id`.
  - `produit_fournisseur.frais_achat` (vide = tarif du frais, 0 = exempté).
  - Commande fournisseur : ligne de totaux « Frais de surcharge », imprimée sur le bon de commande.
  - Facture fournisseur : ligne de ventilation sur `compte_charge_id`.
- **Écran `FraisPage`** : portée, base, minimum et maximum, « Retire ces frais », « Retiré par » (lecture seule).
- **Droits.** `configuration` pour le réglage, droits des documents pour l'application.
- **Tests.** Minimum et maximum, exclusions sans cycle, exclusion gardée par document, frais au poids, exemption par fournisseur, isolation.
- **Taille** M (ventes) + M (achats, module achats).
- **Piège.** Toute nouvelle table pivot doit porter `tenant_id`.

### 17. Manufacturiers — Fait

- **Couverture.** `fabricants` (nom et code uniques, site, contact) et `produits.fabricant_id` avec la référence chez le fabricant.
- **Écart.** Pas de contrôle de suppression. fripv2 refuse si un produit, même inactif, l'utilise : ajouter la clé `suppression`.

### 18. Caisses — Fait

- **Couverture.**
  - Référentiel `caisses` : code de 10 caractères en majuscules, nom, actif ; suppression refusée si la caisse a servi.
  - Sessions et mouvements (`CaissesPage`), point de vente.
  - `caisse_session_id` sur les encaissements, les paiements et les cartes cadeaux.
- **Écart.** Libellé non traduisible (voir Langues).

### 19. Popularités — Manquant

**1. Ce que fait fripv2**
- **Champs :** **Cote** (15 caractères, obligatoire) et **Ordre** (numérotation automatique, flèches ↑ ↓).
- **Enregistrement en bloc** : ajouts, retraits, renommages et ordre ne s'appliquent qu'au clic sur Enregistrer. La suppression est logique.
- **La fiche produit exige une cote.** Dans la fiche, la liste des cotes est triée par ordre alphabétique.
- **Utilisation :** critère des **escomptes** (règles de prix par popularité), filtres et rapports.
- fripv2 n'empêche pas de supprimer une cote utilisée.
- **Droits :** Voir les cotes, Ajouter une cote, Changer l'ordre des cotes, Supprimer une cote.

**2. Ce que PI a aujourd'hui**
- Rien.

**3. Ce qu'il faut faire dans PI**
- **Table `popularites`** : `tenant_id`, `libelle` (30), `ordre`, `actif`, `softDeletes` ; unique (`tenant_id`, `libelle`). Référentiel ordonné par `ordre`, avec flèches ou glisser-déposer.
- **`produits.popularite_id`.**
- **Écrans.** Fiche produit (classification) ; colonne, filtre et tri par ordre dans la liste des produits ; import et export CSV.
- **`regles_prix.popularite_id`**, comme critère des escomptes fripv2.
- **Rapports.** Paramètre « popularité » dans `StockProduits`, `ProduitsDormants` et `VentesParProduit`.
- **Option** `tenant_parametres.popularite_obligatoire` (défaut : faux).
- **Suppression** refusée si utilisée : plus sûr que fripv2.
- **Taille** P.

### 20. Représentants — Partiel (fusionné en #131)

**1. Ce que fait fripv2**
- **Champs :** **Nom** (100 caractères) et **Taux de commission** (4 décimales, obligatoire ; 0 si inutilisé).
- **Utilisation.** Assignable sur la **fiche client** (exigé sauf licence comptable) et présent sur les **devis, commandes, bons de livraison et factures** (`id_representant` sur `trans_bliv_entete` aussi).
- **Facture.** Elle fige le libellé du représentant à l'émission.
- **Produit.** La case `commission` indique si le produit compte pour la commission des vendeurs.
- **Suppression** refusée si un client, un BL, une commande, un devis ou une facture le référence.
- **Droits :** Voir, Ajouter, Modifier, Supprimer un représentant.

**2. Ce que PI a aujourd'hui** (PR #131)
- Référentiel : code, nom, courriel, téléphone, commission (% à 2 décimales), territoire.
- `representant_id` sur `clients`, `devis`, `com_client_entete` et `factures`. `factures.taux_commission` figé.
- `EntetesVente` : le défaut vient du client, la valeur suit les conversions et le reste à facturer.
- Filtres de listes, PDF, rapport `VentesParRepresentant` (notes de crédit déduites).
- Suppression refusée si des clients ou des documents l'utilisent.

**3. Ce qu'il faut faire dans PI**
- **Bon de livraison.** `bons_livraison.representant_id`, copié de la facture à la création du BL (`POST /factures/{id}/creer-bl`). Ajouter au PDF du BL et à la clé `suppression`.
- **Produits commissionnables.** `produits.commissionnable` (vrai par défaut). `VentesParRepresentant` calcule alors la commission sur les **lignes** commissionnables, et non sur `factures.montant_ht`.
- **Option** `representant_obligatoire` (défaut : faux).
- **Écart accepté.** Ne pas modifier la précision du taux (2 décimales au lieu de 4) : ce ne serait pas additif.
- **Taille** P.

### 21. Transporteurs — Partiel (le référentiel seul existe)

**1. Ce que fait fripv2**
- **Champs :**
  - **Description** (30 caractères, traduisible) ;
  - **« Frais de livraison applicables »** : coché par défaut ; c'est la colonne « Livraison facturée » Oui / Non de la liste ;
  - **« Frais de livraison commande fournisseur »** : un frais de livraison, facultatif.
- **Où il sert :**
  - fiches **client** et **fournisseur** (transporteur par défaut) ;
  - en-têtes de **devis, commande, BL, facture** et **commande fournisseur**, où il est **obligatoire**. Le défaut vient du client, ou du fournisseur pour les achats. L'assistant de configuration en exige au moins un, « même si vous ne livrez pas ».
- **Frais de livraison côté ventes :**
  - un transporteur dont la case est décochée **empêche l'ajout automatique** du frais de livraison de la fiche client ;
  - changer de transporteur pendant la saisie retire ou repose la ligne ; une ligne modifiée à la main n'est pas retouchée ;
  - la case ne vaut que pour les documents saisis après le changement ; on peut toujours ajouter une ligne à la main.
- **Frais de livraison côté achats.** Chaque nouveau compte à payer d'un fournisseur livré par ce transporteur reçoit une ligne :
  - au montant du frais (0,00 s'il n'en a pas), taxes calculées, avec la note « Frais de transport – [transporteur] » ;
  - sur la dépense d'achat du département du produit du frais ;
  - non enregistrée à 0,00 ; non ajoutée si ce compte est déjà un compte par défaut du fournisseur ou s'il est dans une autre devise ; un avertissement s'affiche si aucun compte n'est trouvé.
- **No connaissement** (30 caractères) sur la commande et la facture, reporté au BL. La facture fige le libellé du transporteur. Le BL l'imprime (« Expédié par »).
- **Suppression** refusée si un client, un fournisseur ou un document l'utilise. Pas d'état actif / inactif.
- **Droits :** Voir, Ajouter, Modifier, Supprimer un transporteur.

**2. Ce que PI a aujourd'hui**
- Référentiel `transporteurs` : code, nom unique, téléphone, courriel, lien de suivi avec `{numero}`, numéro de compte, notes, actif.
- **Utilisé nulle part** : aucune colonne `transporteur_id`, aucun écran hors Référentiels.
- Sur le BL : `mode_livraison` est un enum (entreprise, retrait_client) ; les colis n'ont pas de numéro de suivi.

**3. Ce qu'il faut faire dans PI**
- **Données.**
  - Sur `transporteurs` : `frais_livraison_applicable` (vrai par défaut), `frais_livraison_achat_id`.
  - `transporteur_id` (nullable, indexé, sans clé étrangère dure) sur `clients`, `fournisseurs`, `devis`, `com_client_entete`, `factures`, `bons_livraison` et `com_four_entete`.
  - `numero_suivi` (60) sur `com_client_entete`, `factures`, `bons_livraison` et `bl_colis`.
- **Services.**
  - `EntetesVente::CHAMPS` reçoit `transporteur_id`, et `regles()` la règle correspondante : défaut du client, suivi devis → commande → facture → BL et reste à facturer.
  - Commandes fournisseur : défaut = transporteur du fournisseur.
  - `FraisDocuments` : blocage décrit à l'option 15.
  - Factures fournisseurs : ligne de transport (option 15).
  - Lien de suivi : `url_suivi` dont `{numero}` est remplacé.
- **Sorties.**
  - PDF : « Expédié par : {transporteur} — suivi {numéro} » sur le BL, la facture, la commande et le bon de commande.
  - Variables de courriel `{transporteur}`, `{numero_suivi}`, `{lien_suivi}`.
  - Espace client : lien de suivi.
- **Écrans.** Fiches client et fournisseur ; en-têtes des documents ; colonne et filtre dans les listes ; dans le référentiel, la case, le frais d'achat et la colonne « Livraison facturée ».
- **Suppression** refusée si utilisé ; on le désactive.
- **Activation.** Champs masqués tant qu'aucun transporteur n'est actif. Option `transporteur_obligatoire` (défaut : faux).
- **Tests.** Héritage, conversions, blocage du frais, recalcul, ligne fournisseur, transporteur d'un autre tenant refusé, suppression refusée.
- **Taille** M.
- **Pièges.** Ne pas toucher l'enum `mode_livraison` ; masquer le transporteur en `retrait_client`.

### 22. Entrepôts — Fait

- **Couverture.**
  - Référentiel `entrepots` : code, nom, type, adresse, par défaut, actif.
  - `secteurs.entrepot_id` et `com_four_entete.entrepot_id` (« Livrer à », adresse imprimée).
  - Stock par entrepôt ; filtres dans les scans, la valorisation et les prises d'inventaire.
- **Petits écarts :**
  - pas de clé `suppression` : fripv2 refuse si une localisation ou une commande fournisseur y est rattachée ;
  - « Configuration à compléter » ne demande pas d'entrepôt (obligatoire dans fripv2) ;
  - l'écran Relocalisation n'a pas de filtre par entrepôt ;
  - adresse en texte (voir Géographie).

### 23. Localisations — Manquant (en PI : « Emplacements »)

**1. Ce que fait fripv2**
- **Modèle.**
  - Table `localisation` : `id_parent` (arbre de profondeur libre), `id_entrepot` (obligatoire), `code` (10 caractères), description (50 caractères, traduisible), `ordre`, `type`.
  - Les types : 1 entrepôt, 2 magasin, **3 roulant** (camion), 4 surstock, **5 retour fournisseur** (stock non vendable), **6 consignation client** (emplacement système unique ; la ligne de stock y porte le client).
  - Affichage : arbre « Description (CODE) », filtre par entrepôt, tout déplier ou tout replier.
- **Règles.**
  - Description, code et entrepôt sont obligatoires.
  - **Description et code sont uniques parmi les frères.** Au premier niveau, l'unicité porte sur tous les entrepôts.
  - **Le parent et l'entrepôt sont figés après la création** : pour déplacer, on crée ailleurs.
  - Un sous-emplacement prend l'entrepôt de son parent.
  - On réordonne en glissant parmi les frères seulement.
  - Le type ne change pas sur un emplacement de retour fournisseur ou de consignation.
  - **Suppression refusée :** si l'emplacement a des enfants (« Il y a d'autres localisations qui lui sont associées ») ; s'il est utilisé (mouvement, localisation d'un produit, prise d'inventaire, code-barres) ; et toujours pour les types 5 et 6.
- **Le stock.**
  - Il vit dans `produit_code_barre` : produit × localisation × code-barres, lot ou série, avec la quantité.
  - `source=1` marque un emplacement **autorisé** pour le produit (onglet Localisations de la fiche produit : une seule zone de retour fournisseur par produit, jamais la consignation).
  - L'emplacement est gardé même à quantité nulle.
- **Où l'emplacement intervient :**
  - **Réception** : emplacement par ligne, choisi parmi les emplacements autorisés ; répartition sur plusieurs ; choisi d'office s'il n'y en a qu'un ; une ligne par unité pour un article suivi ; jamais dans une zone réservée.
  - **BL, commande, facture** : la marchandise sort de l'emplacement choisi (le camion en premier s'il a été chargé). Une fenêtre de répartition s'ouvre s'il y a plusieurs possibilités. Une quantité négative revient dans un emplacement choisi.
  - **Boîtes** : la marchandise va dans l'« emplacement de préparation » de la compagnie et ne sort du stock qu'à la livraison.
  - **Chargement du camion** : relocalisation vers un emplacement « roulant ».
  - **Relocalisation** : par produit, ou par emplacement (on vide un emplacement, quantités pré-remplies). Deux mouvements « Relocalisation » par déplacement. Les zones réservées sont exclues.
  - **Ajustements** par emplacement.
  - **Prise d'inventaire** sur un emplacement, avec « inclure les sous-localisations ».
  - **Rapport d'inventaire** par emplacement (emplacements vides et consignation en option).
  - Filtre des mouvements d'inventaire.
- **Réglages de la compagnie :** « Gérer les localisations » (décoché : une localisation par défaut unique), localisation de préparation des boîtes, localisation de retour.
- **Import Excel.**
  - Ligne 1 = titres. Colonne A = nom d'un entrepôt existant (premier niveau), B = son code, puis une paire (description, code) par niveau (C-D, E-F…).
  - Lecture jusqu'à la première ligne dont A ou B est vide ; toutes les feuilles sont lues.
  - Une ligne à l'entrepôt inconnu est signalée et ignorée ; pas de doublon au même endroit.
- **Droits :** Voir les localisations et étiquettes, Ajouter ou importer, Modifier et réordonner, Supprimer.

**2. Ce que PI a aujourd'hui**
- La hiérarchie s'arrête à entrepôt → secteur (plat).
- **Le stock est la somme des scans par secteur** (`StocksEntrepots`, `PrisesInventaire::inventaireOfficiel`).
- Le secteur est souvent du **texte** :
  - `inventaire_scan.secteur` : le code sur le web, le **nom** sur Android ;
  - `mouvement_relocalisation.secteur_source` et `secteur_destination`.
- Les réceptions (`reception_arrivages_ligne.secteur_id`), les retours et les prises sont par secteur. Un QR par secteur.
- **Conflit de nom.** `produit_localisations` (secteur, zone de préparation, camion) et les routes `/api/localisations/*` (`LocalisationController`) existent déjà.

**3. Ce qu'il faut faire dans PI**
- **Nommage.** Appeler le niveau **« Emplacements »** pour éviter le conflit avec les `localisations` existantes.
- **Table `emplacements`.**
  - Colonnes : `tenant_id`, `secteur_id` (obligatoire), `parent_id` (même secteur), `code` (20, majuscules), `libelle` (100), `chemin_code` (191, calculé, par exemple `A1-03-02`, **unique par tenant** : c'est lui qu'on scanne), `niveau`, `ordre`, `usage` (stockage, réserve, quarantaine, préparation, véhicule), `vendable` (vrai par défaut), `actif`, `softDeletes`.
  - Libellé traduisible (voir Langues).
- **Colonnes facultatives `emplacement_id`** sur :
  - `inventaire_scan`, avec un `emplacement_code` instantané pour le hors-ligne mobile ;
  - `mouvement_relocalisation` (source et destination) ;
  - `reception_arrivages_ligne`, `retour_client_lignes`, `retour_fournisseur_lignes` ;
  - `prises_inventaire_lignes`, `lots`, `numeros_serie`, `bon_livraison_lignes`.
  
  Ajouter aussi `produits.emplacement_defaut_id` et une table `produit_emplacements` (emplacements autorisés).
- **Réglages.** `tenant_parametres.gerer_emplacements` (défaut : **faux**), `emplacement_preparation_id`, `emplacement_retour_fournisseur_id`.
- **Règles reprises de fripv2.** Code unique parmi les frères ; secteur et parent figés ; ordre entre frères ; suppression refusée si l'emplacement a des enfants, est utilisé ou est réservé dans les réglages.
- **Stock.**
  - Agréger par (produit, secteur, emplacement) ; nouvel état « Stock par emplacement ».
  - Les disponibilités et les ventes excluent `vendable = false`.
  - Prises d'inventaire avec « inclure les sous-emplacements ».
  - Relocalisation par produit et par emplacement.
  - Réception sur l'emplacement par défaut ou l'emplacement unique autorisé, avec répartition possible.
  - Sortie du BL depuis l'emplacement choisi.
- **API.**
  - `GET/POST/PUT/DELETE /api/emplacements` (arbre par secteur ou par entrepôt).
  - `POST /api/emplacements/ordre`, `POST /api/emplacements/import` (CSV/XLSX : `secteur_code`, puis des paires libellé / code).
  - `POST /api/emplacements/valider-code` et `/api/mobile/emplacements`.
  - Champ `emplacement` facultatif dans `/mobile/scan/enregistrer`.
- **Écrans.**
  - Paramètres → Emplacements : arbre, ajout d'un sous-emplacement, glisser-déposer, import.
  - Onglet Emplacements de la fiche produit, si la gestion est activée.
  - Champs d'emplacement dans Scans, Relocalisation, Réceptions et Prises, visibles seulement si la gestion est activée.
- **Droits.** Nouveau module `emplacements` : CRUD, plus `importer` (base : créer) et `ordonner` (base : modifier). Les droits initiaux sont copiés de `secteurs`. Ajouter le préfixe dans `Droits::MODULES` et les routes d'action dans `Droits::PARTICULIERES`.
- **Mobile.** L'étiquette donne le secteur et l'emplacement. Sans emplacement, rien ne change.
- **Tests.**
  - Arbre : pas de boucle, parent figé ; unicité entre frères et du chemin ; suppression refusée ; import idempotent.
  - Agrégation du stock ; réception ; relocalisation ; isolation.
  - Gestion désactivée = aucune différence de comportement.
- **Taille G**, en trois lots :
  1. le référentiel, l'écran et l'import ;
  2. les scans, le mobile, le stock et la relocalisation ;
  3. les réceptions, les retours, le BL et les prises.
- **Pièges.**
  - Le secteur est en texte dans les scans (code ou nom selon l'appareil).
  - Un `parent_id` NULL ne protège pas un index unique MySQL : contrôler l'unicité dans l'application.
  - Charger l'arbre d'un secteur en une seule requête.
  - Le hors-ligne mobile.

### 24. Étiquettes de localisation — Manquant

**1. Ce que fait fripv2**
- **Format.** Une étiquette de **76 × 51 mm** par emplacement, avec :
  - un code-barres du code de l'emplacement (sans code, pas de code-barres) ;
  - la description, ou le code si la description manque dans la langue ;
  - le **chemin complet** « Entrepôt › Allée › Tablette » ; le premier niveau ne répète pas son propre nom.
- **Sélection.** Liste à cocher (chemin et code, recherche, case « Tout »), « **Inclure les sous-localisations** », nombre de copies (une valeur ≤ 0 compte pour 1), compteur « N étiquette(s) seront imprimées ».
- **Génération.**
  - Aucun doublon, même si un parent et son enfant sont cochés.
  - **Ordre de marche** de l'arbre, pas l'ordre des coches.
  - La consignation est exclue (emplacement virtuel).
  - Au moins un emplacement doit être coché.
- **Sorties.** Écran, PDF ou imprimante. Les filtres sont mémorisés par usager. Chaque sortie laisse une trace au journal.
- **Droit :** « Voir les localisations et étiquettes ».

**2. Ce que PI a aujourd'hui**
- Étiquettes de produits (`Etiquettes::pdf`, `SvgCodeBarre` en Code 128 / EAN).
- QR de secteur imprimé **un par un** par le navigateur (`SecteursPage`).
- Étiquettes de colis 76 × 51 mm en QR.

**3. Ce qu'il faut faire dans PI**
- **Écran.** Onglet « Emplacements » dans la page Étiquettes, et un bouton dans l'écran Emplacements. Les secteurs y figurent aussi, ce qui remplace l'impression un par un.
- **Comportement.** Reprendre les règles fripv2 : sélection multiple, inclusion des descendants, copies, compteur, dédoublonnage, ordre de l'arbre, libellé de repli.
- **Contenu.** Code 128 et/ou QR du `chemin_code`, libellé, chemin. Format de la famille `emplacement` (option 10), 76 × 51 mm par défaut ; une planche est possible.
- **Garde-fous.** Avertir si le code est trop long pour la largeur ; au plus 2 000 étiquettes.
- **Mémorisation des choix** par les filtres mémorisés existants.
- **Route** `POST /api/emplacements/etiquettes` : une lecture, donc droit `emplacements.voir`, à déclarer dans `Droits::PARTICULIERES` comme `etiquettes/imprimer`. Trace au journal.
- **Taille** P. **Dépend de** l'option 23.

### 25. Langues — Partiel (français et anglais figés)

**1. Ce que fait fripv2**
- **Champs :** **Code** (5 caractères, unique, espaces retirés ; par exemple `fr`, `en`, `fr-ca`) et **Description** (100 caractères).
- **Usages d'une langue :**
  - langue de l'interface et **langue des données** de chaque usager ;
  - langue de chaque **client**, **fournisseur**, contact du portail et de la **compagnie** (défaut des nouveaux tiers, rapports et documents) ;
  - modèles de courriel et notes de bas de page **par langue** ;
  - traduction de presque tous les référentiels : unités, taxes, devises, transporteurs, catégories, localisations…
- **Écran de traduction.**
  - Le bouton « globe » n'apparaît que si plus d'une langue est déclarée.
  - Une ligne par langue ; seule la langue de l'usager est obligatoire ; repli sur la langue principale.
  - Bouton « Traduire automatiquement » (IA).
- **Suppression** refusée si une traduction, un client, un fournisseur, un employé ou la compagnie utilise la langue.
- **Droits :** Voir, Ajouter, Modifier, Supprimer une langue.

**2. Ce que PI a aujourd'hui**
- Interface FR/EN (`translations.ts`) ; messages de l'API selon `Accept-Language`.
- `produit_traductions` par langue ; modèles de courriel par langue avec `Courriels::LANGUES = ['fr','en']` ; espace client FR/EN.
- **Manque :**
  - une liste de langues administrable ;
  - une langue sur les clients, fournisseurs, contacts et l'entreprise ;
  - la fenêtre d'envoi prépare le courriel en `fr` par défaut ;
  - les PDF et les CSV sont toujours en français ;
  - les référentiels ne sont pas traduisibles.

**3. Ce qu'il faut faire dans PI**
- **Table `langues`** : `tenant_id`, `code` (5), `libelle`, `actif`, `ordre`. `fr` et `en` sont créées d'office et ne se suppriment pas. Suppression refusée si la langue est utilisée.
- **Langue des tiers.** `tenant_parametres.langue_donnees` (`fr` par défaut), `clients.langue`, `fournisseurs.langue`, `client_contacts.langue`.
- **Courriels.** Les langues autorisées sont celles de l'entreprise. La langue proposée est celle du contact, sinon du client ou du fournisseur, sinon de l'entreprise.
- **PDF.** En FR ou EN selon le tiers, avec un fichier de libellés `lang/en/pdf.php`.
- **Traductions des référentiels.**
  - Table générique `traductions` (`tenant_id`, `entite`, `fiche_id`, `langue`, `champ`, `valeur`) et clé `traduisible` dans le catalogue Referentiels.
  - Routes `GET/PUT /api/referentiels/{type}/{id}/traductions` ; bouton globe dans ReferentielsPage.
  - Usage sur les PDF et le portail, avec repli sur le libellé de base. En option, traduction par le module IA.
- **Droits.** Module référentiels ; traduire suit le droit de modifier le référentiel.
- **Taille** M (liste, langue des tiers, courriels) ; **G** avec les PDF bilingues et les traductions de référentiels.
- **Piège.** Une langue « de données » n'ajoute aucune traduction d'écran : l'interface reste FR/EN.

### 26. Banques — Manquant (le répertoire)

**1. Ce que fait fripv2**
- **Champs :** **Code** (50 caractères, unique ; de préférence le numéro d'institution, par exemple `006`) et **Description** (30 caractères, traduisible).
- **Utilisation.** Champ « Banque » des comptes bancaires de la compagnie (« institutions » : transit, compte, réglages de transfert électronique). Le code y est recopié comme numéro d'institution.
- **Suppression** refusée si une institution l'utilise.
- **Droits :** Voir, Ajouter, Modifier, Supprimer une banque.

**2. Ce que PI a aujourd'hui**
- `comptes_bancaires` : les comptes de l'entreprise, avec le nom de la banque **en texte**, IBAN, BIC et devise. C'est l'équivalent des institutions fripv2.
- `fournisseur_comptes_bancaires` : banque en texte.
- Virements SEPA/CSV, chèques, relevés.
- **Pas de répertoire des banques.**

**3. Ce qu'il faut faire dans PI**
- **Table `banques`** : `tenant_id`, `code` (20 : numéro d'institution, code banque, sort code ou routing selon le pays), `nom` (100), `bic` (8 ou 11 caractères, validé), `pays_code`, `actif` ; unique (`tenant_id`, `code`).
- **Lien facultatif `banque_id`** sur `comptes_bancaires` et `fournisseur_comptes_bancaires`. Choisir une banque remplit le nom, et le BIC s'il est vide. Le texte reste la source des PDF et des fichiers.
- **Suppression** refusée si un compte utilise la banque.
- **Taille** P.

### 27. Devises — Partiel

**1. Ce que fait fripv2**
- **Champs :** **Code** (3 caractères, majuscules, unique), **Description** (30 caractères, traduisible), et **7 comptes GL de la devise**, pris seulement parmi les comptes actifs de cette devise :
  - à recevoir (1xxxx), à payer (2xxxx), acompte, escompte achat, escompte vente : **obligatoires en modification** ;
  - transport et **arrondi de caisse** : facultatifs.
- **Arrondi de caisse.** Choisir ce compte active l'arrondi au 0,05 des paiements **en espèces** de cette devise.
- **Non-rétroactivité.** Changer un compte ne touche pas le passé. Un acompte garde le compte en vigueur au moment de son encaissement.
- **Suppression** refusée si un client, un fournisseur, un compte GL ou un document l'utilise.
- **Droits :** Voir, Ajouter, Modifier une devise et ses comptes, Supprimer.

**2. Ce que PI a aujourd'hui**
- Référentiel `devises` (code ISO, libellé, symbole, décimales, taux, actif) avec historique `devise_taux`.
- Devise du client (par code), documents en devise (`DevisesVente`), cartes cadeaux, déboursés.
- La comptabilisation se fait en devise de l'entreprise.
- **Manque :** comptes par devise, arrondi des espèces, contrôle de suppression, traduction.

**3. Ce qu'il faut faire dans PI**
- **Comptes par devise.** Correspondance `devises` dans `ParametreComptable` : code → {clients, fournisseurs, acomptes, escomptes accordés, escomptes obtenus, transport, arrondi}. Le compte de la devise du document est utilisé s'il est réglé, sinon le compte général. La tenue du grand livre en devise d'origine est hors de ce lot.
- **Arrondi des espèces.** `devises.arrondi_especes` (par exemple 0,05) et `tenant_parametres.arrondi_especes` pour la devise de l'entreprise. Un paiement par un mode de type `especes` est arrondi ; l'écart va au compte « arrondi ». Concerne le point de vente, les caisses et les encaissements.
- **Suppression** refusée si la devise est utilisée.
- **Configuration à compléter.** Signaler toute devise utilisée par un tiers qui n'a pas de taux.
- **Droits.** Module référentiels pour la devise ; `comptabilite.ecrire` pour les comptes.
- **Taille** M.

### 28. Taux de change — Partiel

**1. Ce que fait fripv2**
- **Champs :** Devise, **Taux** (6 décimales ; les documents en gardent 4), **Date début** et **Date fin** (obligatoires, la date du jour par défaut).
- **Règles.** La fin doit être après ou égale au début. **Pas de chevauchement** pour une même devise : « Les dates des taux de change doivent être distinctes et ne pas se chevaucher pour une même devise ».
- **Choix du taux d'un document :**
  - le taux dont la période couvre **la date du jour** (et non la date du document) ; s'il y en a plusieurs, celui qui finit le plus tard ;
  - sinon le dernier taux connu ;
  - sinon **1, sans prévenir**. L'assistant de configuration signale les devises sans taux.
- La devise principale n'est jamais convertie. Un document tiré d'un autre garde son taux.
- **Droits :** Voir, Ajouter, Modifier, Supprimer un taux de change.

**2. Ce que PI a aujourd'hui**
- Un seul taux courant par devise (`devises.taux`), historisé automatiquement à chaque changement (`devise_taux.date_effet` = aujourd'hui ; consultation `GET /referentiels/devises/{id}/historique`).
- `TauxChangeController` : le taux de l'entreprise, sinon le cours du jour Frankfurter, **sinon une erreur 404**. PI ne tombe jamais à 1 en silence, ce qui est mieux que fripv2.
- **Manque :** périodes, saisie à l'avance ou rétroactive, correction et suppression d'un taux, écran de liste, alerte de configuration.

**3. Ce qu'il faut faire dans PI**
- **Données.** `devise_taux.date_fin` et `source` (`saisie` ou `automatique`).
- **API.** `POST/PUT/DELETE /api/referentiels/devises/{id}/taux[/{tauxId}]` (module référentiels), avec le contrôle de chevauchement de fripv2.
- **Taux courant.** Une tâche planifiée quotidienne met à jour `devises.taux`. `DevisesVente` choisit le taux qui couvre la date.
- **Import automatique.** Tâche facultative « Importer les cours du jour » (Frankfurter) pour les devises marquées `taux_automatique`.
- **Écran.** Onglet « Taux de change » : liste de tous les taux, filtre par devise, 6 décimales.
- **Tests.** Chevauchement refusé, choix du taux, repli, isolation.
- **Taille** P à M.

---

## Tableau récapitulatif

| # | Option | Statut | Ce qui existe dans PI | Écart principal | Taille |
|---|---|---|---|---|---|
| 1 | Villes | Manquant | Ville en texte libre dans les adresses | Répertoire, liste dans les adresses, création à la volée | P (lot Géographie M) |
| 2 | Provinces → Régions | Manquant | `region` en texte | Référentiel lié au pays, défaut de l'entreprise | P |
| 3 | Pays | Manquant | `pays` en texte | Référentiel ISO, `pays_code` sur les tiers et l'entreprise | P |
| 4 | Codes postaux | Manquant | Rien | Répertoire, complétion et enrichissement automatique des adresses | M (lot) |
| 5 | Territoires | Partiel | Référentiel `territoires`, utilisé par itinéraires et représentants | Absent des clients, des listes et des rapports | P |
| 6 | Routes | Partiel | `itineraires`, `clients.itineraire_id` et séquence, tournées | Route par adresse, code figé sur BL et facture, suppression | P |
| 7 | Unités | Fait | `unites`, `produit_unites`, unité sur les lignes | Contrôle de suppression, traduction | — |
| 8 | Conversions | Fait | `unite_conversions`, `ConversionsUnites` | Description traduite (cosmétique) | — |
| 9 | Formats | Manquant | Rien (`unite_achat` en texte) | Référentiel et `produits.format_id`, document matières dangereuses | P |
| 10 | Formats d'étiquette | Partiel | `etiquette_formats` (produits), page Étiquettes | Familles colis et emplacement, héritage produit → catégorie | M |
| 11 | Taxes | Partiel | `tenant_taxes` (nom, taux, défaut), comptes par taxe | Montant fixe, portée, regroupement, exemptions par produit, remise | M |
| 12 | Groupe de taxes | Partiel | `groupes_taxes` et lignes ; groupe sur les lignes de commande et facture | Défauts client, fournisseur, produit ; en-tête ; taxes des devis | M-G |
| 13 | Catégories de produits | Partiel | Référentiel hiérarchique **non relié** (`produits.categorie` en texte) | `categorie_id`, produit modèle, règles de prix, rapports | M |
| 14 | Départements | Manquant | Correspondance catégorie → comptes seulement | Familles comptables (4 comptes), instantané sur les lignes, CMV | M-G |
| 15 | Frais de livraison | Partiel | `frais` de type livraison, `clients.frais_livraison_id` | Blocage par le transporteur, ligne de transport côté achats | P+P |
| 16 | Frais additionnels | Partiel | Frais de vente, matières dangereuses | Minimum et maximum, exclusions, exclusion durable par document, frais d'achat | M+M |
| 17 | Manufacturiers | Fait | `fabricants`, `produits.fabricant_id` | Contrôle de suppression | — |
| 18 | Caisses | Fait | `caisses`, sessions, point de vente | Traduction | — |
| 19 | Popularités | Manquant | Rien | Référentiel ordonné, `produits.popularite_id`, règles de prix | P |
| 20 | Représentants | Partiel (fusionné en #131) | Clients, devis, commandes, factures, commission figée, rapport | BL, produits commissionnables, option obligatoire | P |
| 21 | Transporteurs | Partiel | Référentiel seul, **utilisé nulle part** | Tiers et documents, « Livraison facturée », frais côté achats, suivi | M |
| 22 | Entrepôts | Fait | Référentiel, secteurs, livraison des commandes fournisseur, stock par entrepôt | Suppression, configuration à compléter, filtre de la relocalisation | — |
| 23 | Localisations | Manquant | Entrepôt → secteur plat ; `/localisations` déjà pris | « Emplacements » en arbre sous le secteur, stock, réceptions, relocalisation, import | G |
| 24 | Étiquettes de localisation | Manquant | QR de secteur un par un | Étiquettes 76×51 en lot, chemin, descendants, copies | P |
| 25 | Langues | Partiel | Interface et données FR/EN figées | Liste de langues, langue des tiers, traductions des référentiels | M-G |
| 26 | Banques | Manquant | Comptes bancaires avec banque en texte | Répertoire (code, nom, BIC), `banque_id` | P |
| 27 | Devises | Partiel | Référentiel avec taux et historique, devises des documents | Comptes par devise, arrondi des espèces, suppression | M |
| 28 | Taux de change | Partiel | Un taux courant historisé, cours Frankfurter | Périodes, saisie et correction, écran de liste, alerte | P-M |

## Ordre de réalisation suggéré

1. **Transporteurs** (21), avec le blocage des frais de livraison et la ligne de transport côté achats (15).
2. **Territoires** (5), **Routes** (6) et la fin des **Représentants** (20).
3. **Catégories** (13), puis **Popularités** (19) et **Formats** (9).
4. **Défauts des groupes de taxes et exemptions** (11, 12).
5. **Géographie** (1 à 4).
6. **Taux de change** (28), **Devises** (27), **Banques** (26).
7. **Emplacements** et leurs étiquettes (23, 24, avec la famille d'étiquette de l'option 10).
8. **Départements** (14), **frais d'achat** (16), **Langues** (25).
