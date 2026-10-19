# Onglet Gestion, boutons « Configuration » et « Usagers » : ce qui manque à Prise Inventaire (PI) par rapport à fripv2

> Partie de [la liste du reste à faire](../17-RESTE-A-FAIRE.md), état au 19 octobre 2026 (après la PR #131).
> Pour chaque écran : **ce que fait fripv2** (le comportement de référence), **ce que PI a aujourd'hui**, puis
> **ce qu'il faut faire** (taille **P** petite, **M** moyenne, **G** grande).

Sur les 36 options des boutons Configuration et Usagers : 2 sont faites, 23 partielles, 7 manquantes et 4 hors périmètre (tableau à la fin).

Sources : l'aide en ligne de fripv2 (une page par option) et son schéma `FripStructure.sql` ; côté PI, le code après la PR #131 (représentants) et les fichiers `docs/flows`.

Pour chaque option partielle ou manquante, la section décrit ce que fait fripv2, ce que PI a aujourd'hui, puis ce qu'il faut faire.

---

## 0. Constats transverses (à traiter une fois, ils servent plusieurs options)

**T1. Des droits ne sont pas contrôlés côté serveur, et des écrans sont réservés à l'administrateur sans délégation possible.**

Lectures non contrôlées :
- `GET /api/audit*` : le préfixe `audit` est absent de `Droits::LECTURES`. Tout compte de l'entreprise lit le journal, y compris les valeurs avant/après des comptes et des paramètres.
- `GET /api/courriels/envois` et `/{id}` : tout compte lit le corps de tous les courriels envoyés, même ceux d'un module qu'il ne peut pas voir.
- `GET /api/configuration-a-completer` : non contrôlé.

Écritures non contrôlées :
- `/api/remarques-rapides` et `/api/pieces-jointes` sont absents de `Droits::MODULES`. Un compte « Lecture seule » peut créer et supprimer des remarques et des pièces jointes.

Écrans réservés par le middleware `role:admin` : tâches planifiées, `/users`, `/roles-custom`, `/acces-comptes`, et « Ignorer » de la configuration à compléter. Aucun groupe ne peut recevoir ces droits, alors que fripv2 a une permission pour chacun.

Proposition générale :
- de nouveaux modules ou actions dans `App\Support\Permissions` ;
- leur branchement dans `Droits` (`LECTURES`, `MODULES`, `PARTICULIERES`) ;
- une migration de droits qui reproduit l'existant (sur le modèle de `2026_10_17_100086_creer_verrous`, qui recopie les droits du module `configuration`).

Seuls les correctifs de lecture du journal et des courriels changent un comportement. Recommandation : accorder `historique.voir` aux rôles qui ont ce menu aujourd'hui (**décision à valider**).

**T2. Les libellés des référentiels ne sont pas traduisibles.**
- fripv2 traduit presque toutes ses descriptions : balises et items, catégories de contact, sections, conditions et modes de paiement, raisons, statuts, types de location, groupes.
- PI ne traduit que les produits, les modèles de courriel et les rapports.
- Proposition : une table générique `traductions` (tenant_id, entite, entite_id, champ, langue, valeur ; unique sur les cinq premières). Un trait `Traduisible` renvoie le libellé dans la langue de la requête. Un composant front « Traductions » (bouton globe) se réutilise partout.
- Langues : celles de `DefinirLangue::LANGUES` (fr, en), en attendant l'écran Langues (bouton Base).
- Taille M. C'est un prérequis de plusieurs sections (noté « T2 »).

**T3. Le journal des modifications est incomplet.**
- `JournalModifications::MODELES` ne suit pas les référentiels, balises, champs personnalisés, remarques, modèles de courriel, groupes et droits, tâches, verrous libérés ni pièces jointes.
- fripv2 journalise tout cela, champ par champ. Voir la section « Logs ».

**T4. Les clients et fournisseurs n'ont pas de langue.**
- Les envois automatiques partent toujours en français (`RelancerFacturesEnRetard` : `'langue' => 'fr'`).
- fripv2 choisit le modèle de courriel et la note de bas de page selon la langue du destinataire.
- Il faut ajouter `clients.langue` et `fournisseurs.langue` (nullable ; par défaut, la langue de l'entreprise). Cela relève des fiches clients et fournisseurs ([03-inventaire-ventes-achats.md](03-inventaire-ventes-achats.md)), mais c'est un prérequis de trois sections ci-dessous.

**T5. On peut supprimer un référentiel déjà utilisé.**
- Le catalogue `Referentiels` n'a pas de règle `suppression` pour les conditions de paiement, les modes de paiement, les types de location, les statuts personnalisés ni les raisons. La suppression douce passe en silence.
- fripv2 refuse : « Suppression impossible : cet élément est utilisé dans d'autres entités ».
- Ajout des fermetures `suppression` au catalogue. Taille P.

**T6. L'écran Configuration n'est pas traduit et reste centré sur la France.**
- `ConfigurationPage.tsx` a ses libellés en français dans le code (seule la section matières dangereuses est traduite).
- Les champs « SIRET » et « N° TVA intracommunautaire » sont propres à la France. Voir « Configuration système ».

---

## 1. Bouton Configuration

### 1. Configuration système (fiche de la compagnie) — **Partiel**

**Ce que fait fripv2**
- Un écran pour l'unique compagnie, avec ces onglets : Informations, Facturation, Numérotation, Transfert électronique de fonds, Tableau de bord clients, Tableau de bord fournisseurs.
- « Enregistrer » couvre Informations et Facturation, avec des contrôles dans un ordre fixe : au premier échec, l'onglet en cause s'active.
- **Informations** :
  - Nom (100, obligatoire) ; nom court (10, obligatoire, pour les listes et les titres).
  - Adresse structurée : n° civique (10), adresse 1 et 2 (100), ville, province et pays choisis dans les listes, code postal (20, mis en majuscules et formaté).
  - N° de taxe provinciale et fédérale (20 chacun).
  - Devise par défaut et langue par défaut (obligatoires ; proposées aux nouveaux clients et fournisseurs).
  - Courriel (255, obligatoire, expéditeur des envois automatiques), « Répondre à » (255), téléphone et télécopieur (15, formatés).
  - Commis web par défaut (employé rattaché aux commandes du portail) ; domaine du portail ; chemins de fichiers (propres au poste installé).
- **Masques de numéro** (client et fournisseur : 20 caractères au plus ; produit : 30) :
  - Notation : `0 9 # L l A a C c > < \`.
  - Formats proposés : aucun, 4, 6 ou 8 chiffres, 3+3, 2 caractères + 4 chiffres, ou personnalisé, avec un aperçu « En tapant 25 → 000025 ».
  - Le complètement s'applique partout où l'on tape un numéro (documents, filtres des rapports).
  - Un numéro existant n'est jamais reformaté. Un ancien « 25 » se trouve encore s'il n'existe pas de « 000025 ».
  - Les saisies manuelles et les imports prennent la forme du masque, comme la numérotation automatique.
  - Un masque avec des lettres et la numérotation automatique donnent un avertissement.
  - Un masque est refusé s'il n'a aucune position à saisir, ou si le numéro produit est trop long.
- **Logo** :
  - PNG, JPG ou BMP, converti en PNG ; l'ancien est gardé (`ancien_logo.png`).
  - Sur les documents, 1,5 à 1,7 cm de haut, jusqu'à environ 8,7 cm de large ; les coordonnées se décalent pour lui faire place.
  - Il sert aussi de variable dans les courriels.
- **Facturation** :
  - Listes de coûtants (dernier coûtant, coûtant moyen : obligatoires) et listes de prix de référence (comptoir, détail, location).
  - Grille des listes de prix : description traduisible, précision de 0 à 4, ordre, droits « Ajouter » et « Supprimer une liste de prix ».
  - Client comptant, avec l'option d'afficher ses informations à la vente.
  - Durée des devis (jours).
  - Montant maximal d'une facture payable en ligne (0 = jamais).
  - Précision du prix unitaire (0 à 4, réellement stockée sur les lignes ; 0 = précision de la liste) et des quantités (0 à 4).
  - « Facturer plus que la quantité livrée ».
  - Localisations : gérer ou non, localisation par défaut, localisation de préparation des boîtes.
  - Fournisseur et unité par défaut, posés à la création d'un produit de service ou non inventorié (champs vides seulement).
- **Onglet TFE** : comptes bancaires d'émission, chiffrés, visibles avec « Voir les infos bancaires de l'entreprise ».
- **Tableaux de bord** :
  - Clients : en cours, 30 jours, 365 jours, tous ; âge des comptes à recevoir 0–30, 31–60, 61–90, 90 jours et plus.
  - Fournisseurs : les mêmes colonnes, avec l'âge des comptes à payer.
- **Instantané des coordonnées** (`compagnie_snapshot`, empreinte SHA-256) rattaché à chaque document émis. Une réimpression garde les coordonnées du jour de l'émission.
- **Droits** : « Modifier la fiche de l'entreprise » (sans lui, consultation), infos bancaires, listes de prix, historique. L'historique est champ par champ ; les données bancaires y sont tracées sans leur valeur.

**Ce que PI a aujourd'hui**
- `tenant_parametres` : nom_entreprise, adresse (texte libre), telephone, email, siret, tva_numero, logo_url, devise, tva_taux, délais, alertes, md_*.
- `GET/PUT /api/parametres` (`TenantParametresController`), onglet Entreprise de `/configuration` (`ConfigurationPage.tsx`, non traduit). La table est journalisée.
- `App\Support\EntreprisePdf` : aucun logo sur les PDF. `logo_url` ne sert qu'à l'espace client, et aucun écran ne permet d'envoyer un fichier.
- Couvert ailleurs : listes de prix avec précision (FLOW-PRIX), client de passage (`ClientsDePassage`), devises, comptes bancaires et virements, zones de préparation, entrepôt par défaut, tableau de bord, comptes clients, paiements dus.
- Absent :
  - nom court, adresse structurée, second téléphone ou fax, site web ;
  - langue par défaut des tiers (T4) ;
  - durée de validité des devis (le champ `date_validite` est vide dans DevisPage) ;
  - précision globale des prix et des quantités ;
  - masques de numéro ;
  - logo téléversé et imprimé ;
  - instantanés des coordonnées ;
  - identifiants légaux génériques.

**Ce qu'il faut faire dans PI**

- **Données** : une migration additive `identite_entreprise` ajoute à `tenant_parametres` ces colonnes nullable :
  - `nom_court` (20), `adresse_ligne1` et `adresse_ligne2` (150), `ville` (100), `region` (100), `code_postal` (20), `pays_code` (2, ISO 3166-1), `telephone_2` (30), `site_web` (255), `langue_defaut` (5, défaut `fr`) ;
  - `identifiants_legaux` (json : [{libelle ≤ 40, valeur ≤ 50, imprimer}] ; remplace l'affichage SIRET/TVA, les anciennes colonnes sont gardées) ;
  - `logo_chemin`, `logo_precedent_chemin` (500) ;
  - `devis_validite_jours` (1 à 365), `precision_prix` et `precision_quantite` (0 à 4) ;
  - `masque_numero_client`, `masque_numero_fournisseur`, `masque_numero_produit` (30).

  Elle crée aussi la table `entreprise_instantanes` (tenant_id, nom, adresse composée, telephone, email, identifiants json, logo_chemin, empreinte char(64) unique par tenant, created_at), et une colonne nullable `entreprise_instantane_id` sur factures, avoirs, devis, com_client_entete, bons_livraison, demandes_acompte et com_four_entete.
- **API** :
  - `PUT /parametres` : validation étendue.
  - `POST /parametres/logo` (multipart png/jpeg/webp, 2 Mo au plus, redimensionné à 1 500 px de large au plus, rangé en `logos/{tenant}/{uuid}.png` sur le disque des pièces jointes ; l'ancien passe dans `logo_precedent_chemin`).
  - `GET /parametres/logo` (flux ; aussi servi au portail), `POST /parametres/logo/restaurer`, `DELETE /parametres/logo`.
  - `POST /parametres/masques/apercu` (masque + saisie → résultat ou erreur).
- **Services** :
  - `App\Support\MasqueNumero` (appliquer, valider, longueur), utilisé à la création et modification des clients, fournisseurs et produits (numéro saisi), dans les imports et dans les recherches par numéro (valeur brute ou masquée).
  - `EntreprisePdf` : logo par chemin local, adresse composée, identifiants à imprimer, et instantané s'il existe. L'instantané est créé à l'émission d'une facture ou d'une note de crédit, et au premier PDF ou au premier envoi des autres documents.
  - Devis : `date_validite` = date du devis + `devis_validite_jours` quand le champ est vide.
  - `CalculPrix` : utilise `precision_prix` s'il est réglé, sinon la précision de la liste (comportement inchangé).
- **Écran** : `/configuration`.
  - Onglet Entreprise en sections : Identité, Adresse, Contact, Identifiants légaux (lignes répétables), Logo (aperçu, restaurer), Numéros (masques avec formats proposés et aperçu).
  - Nouvel onglet « Ventes et documents » : validité des devis, précisions.
  - Toute la page traduite (T6).
- **Droits** : module `configuration` existant. `Droits::PARTICULIERES` : `POST api/parametres/logo` et `POST api/parametres/logo/restaurer` → `['configuration','modifier']` (un POST sur une liste serait sinon lu comme « créer »). L'aperçu des masques va dans `LIBRES`.
- **Traductions** : écran en français et anglais. Nouveaux messages de l'API dans `lang/en.json` (« Le masque ne contient aucune position à saisir. », « Le numéro produit par ce masque dépasse :max caractères. »…).
- **Tests** :
  - unitaires de `MasqueNumero`, sur les exemples de fripv2 (25 → 000025 ; AB1234 → AB-1234 ; `\F\A\C-0000` + 0012 → FAC-0012 ; `>LLL-000` + abc123 → ABC-123 ; ce qui ne remplit pas le masque reste inchangé) ;
  - recherche de « 25 » et d'un ancien numéro « 25 » ;
  - logo : type, taille, remplacement, restauration ; le logo d'une autre entreprise répond 404 ;
  - PDF de facture avec le logo et l'adresse ;
  - instantané : une adresse modifiée après l'émission ne change pas la réimpression ;
  - validité par défaut des devis ;
  - isolation des instantanés et des logos entre entreprises.
- **Taille** : G. À découper en trois PR : identité + logo (M), masques (M), instantanés (P).
- **Pièges** :
  - dompdf ne lit une image que par chemin local ou data URI ; en courriel, il faut une image intégrée (CID).
  - Garder `adresse` en repli tant que les champs structurés sont vides.
  - `langue_defaut` n'a d'effet qu'avec T4.
  - Le montant maximal payable en ligne attend le paiement en ligne (phase 6.10).
  - Le « commis web » est à rapprocher du représentant du client (branche `feat/representants`).

### 2. Configuration à compléter — **Partiel**

**Ce que fait fripv2**
- Un parcours en 6 étapes (rail à gauche ; case cochée ou vide ; contour violet s'il reste de l'obligatoire ; Étape suivante et Précédent ; ouverture sur la première étape qui attend), plus une entrée non numérotée « Qualité des données » (compteurs qui disparaissent à zéro).
- Contenu des étapes :
  1. Identité : coordonnées, adresse, taxes, devise et langue, client comptant, logo, serveur de fichiers, numérotation.
  2. Comptabilité :
     - devises, leurs comptes du grand livre et le taux des devises étrangères ;
     - plan comptable, dont les comptes de bénéfices non répartis et de bénéfice net en cours ;
     - exercice ouvert, périodes, avance automatique de la période courante, exercice suivant quand la fin approche ;
     - taxes et groupes de taxes, conditions de paiement ;
     - modes d'encaissement et de déboursé pour chaque devise utilisée, et mode « carte cadeau » si l'entreprise en vend ;
     - institution TFE, départements, soldes de départ.
  3. Inventaire : entrepôt, localisations (ou localisation par défaut), localisation de retour fournisseur, localisation de préparation des boîtes, unités, catégories, produits.
  4. Clients et fournisseurs : au moins un client réel, un fournisseur et un transporteur ; des représentants si l'entreprise en a.
  5. Usagers : groupes, et au moins un usager non administrateur.
  6. Impression et courriel : imprimantes, gabarits de courriel dans chaque langue des clients (dont le rappel de paiement s'il est automatique), serveur d'envoi.
- Actions sur une ligne :
  - « Ouvrir » : il faut le droit de l'écran visé, sinon un message s'affiche.
  - « Ignorer » : raison obligatoire, décision valable pour toute l'entreprise, inscrite à l'historique ; « Reprendre » la remet en jeu.
  - « Voir » sur un compteur : la fiche s'il n'y en a qu'une, sinon la liste réduite aux fiches concernées, avec un titre explicatif (« 9 clients sans adresse »).
  - « Actualiser ».
- Un seul droit, « Voir la configuration à compléter », qui n'est pas réservé à l'administrateur.

**Ce que PI a aujourd'hui**
- `App\Support\ConfigurationACompleter` : 4 étapes (entreprise, inventaire, tiers, utilisateurs), 13 réglages et 6 compteurs. Rien ne se coche à la main.
- « Ignorer » est réservé à l'administrateur, raison obligatoire (table `configuration_taches_ignorees`). Un rappel s'affiche au tableau de bord.
- `/configuration-a-completer`, avec `ConfigurationACompleterPage` et `RappelConfiguration`.
- Manque :
  - les étapes Comptabilité, Ventes et logistique, Courriel et documents ;
  - plusieurs compteurs ;
  - la liste filtrée derrière « Voir » ;
  - un droit dédié (le `GET` n'est pas contrôlé : T1) ;
  - la journalisation des réglages ignorés.

**Ce qu'il faut faire dans PI**
- **Réglages à ajouter** dans `etapes()` (additif ; libellés dans `setup.tasks`) :
  - Étape « Comptabilité » (module finance) :
    - `plan_comptable` (au moins un compte actif ; obligatoire) ;
    - `exercice_ouvert` (un exercice ouvert contient aujourd'hui ; obligatoire) ;
    - `exercice_suivant` (recommandé dans les 60 derniers jours de l'exercice) ;
    - `comptabilisation` (`Comptabilisation::ESSENTIELS` réglés ; obligatoire si la tâche `comptabilisation` est active) ;
    - `devises_taux` (chaque devise utilisée par un tiers a un taux) ;
    - `conditions_paiement` (au moins une active, et une par défaut) ;
    - `modes_paiement` (par sens et par devise, une fois « Modes de paiement » faite) ;
    - `compte_bancaire_defaut` ;
    - `soldes_ouverture` (recommandé).
  - Étape « Ventes et logistique » (module ventes) : `transporteurs` (recommandé), `representants` (recommandé), `entrepot_defaut` (dès qu'il y a au moins 2 entrepôts), `zones_preparation` (si des colis ou des tournées sont utilisés).
  - Étape « Courriel et documents » :
    - `logo` ;
    - `serveur_courriel` (mode plateforme confirmé, ou dernier test SMTP réussi : ajouter `courriel_parametres.dernier_test_le` et `dernier_test_ok`) ;
    - `modeles_courriel` (un modèle par langue présente chez les clients, après T4) ;
    - `modele_relance` (si la tâche `relances_paiement` est active).
  - Étape « Utilisateurs » : `groupes` (au moins un groupe d'entreprise actif ; recommandé).
- **Compteurs à ajouter** : clients sans condition de paiement, clients sans adresse, produits sans catégorie, fournisseurs sans condition (une fois le lien fait, voir § 15).
- **« Voir »** : un paramètre `qualite=<compteur>` sur les index concernés (ClientController, ProduitTenantController, FournisseurController, EmployeTenantController). L'écran affiche un bandeau « Liste réduite : … » ; s'il n'y a qu'une fiche, elle s'ouvre.
- **Droits** :
  - module `configuration_a_completer` (crud `['voir','modifier']`) avec l'action `ignorer` (base `modifier`), réglée comme `configuration` ;
  - `Droits::LECTURES['configuration-a-completer']` ;
  - `PARTICULIERES` `POST|DELETE api/configuration-a-completer/{cle}/ignorer` → `ignorer`, à la place de `role:admin` (même effet par défaut, puisque l'administrateur a tous les droits) ;
  - côté écran, « Ouvrir » est désactivé si `/permissions/me` refuse l'écran visé.
- **Journal** : `ConfigurationTacheIgnoree` dans `JournalModifications` (raison, auteur).
- **Tests** :
  - chaque nouveau réglage se coche et se décoche selon les données ;
  - un module inactif masque ses réglages ;
  - le filtre `qualite` renvoie exactement les fiches comptées ;
  - ignorer sans le droit → 403 ;
  - isolation : réglages ignorés et compteurs propres à chaque entreprise.
- **Taille** : M.
- **Dépendances** : sections 15 et 16, T4, logo (§ 1), notes de bas de page (§ 8).

### 3. Balises — **Partiel**

**Ce que fait fripv2**
- **Balise** : description (40, traduisible), type « Choix » (un item par fiche) ou « Option » (plusieurs items), actif.
- **Item** : description (40, traduisible), actif.
- **Écran** : deux panneaux (balises et items). Un double-clic sur « Actif » bascule l'état, après confirmation. Filtre Statut : Actif ou Tous.
- **Suppression** : une balise est refusée tant qu'elle a des items ; un item est refusé s'il est posé quelque part. Un changement de type est tracé à part.
- **Pose** (fenêtre « Sélection des balises ») :
  - sur les fiches clients, fournisseurs et produits ;
  - sur les **lignes des devis, commandes, bons de livraison et factures** (bandeau de la ligne et colonne Balises), pré-cochées d'après le client et le produit ;
  - sur les **lignes d'écritures** : comptes à payer et à recevoir, transactions GL, bancaires et récurrentes.
  - Une pastille compte les balises renseignées (« 9+ » au-delà). Un bouton efface une balise. Seuls les balises et items actifs sont proposés. Sans le droit de modifier la fiche, la fenêtre est en lecture seule.
- **Usage principal : critères de filtre des rapports** (par exemple, les ventes des clients d'une région).
- **Droits** : Voir, Ajouter, Modifier et Supprimer une balise de rapport (communs aux balises et aux items) ; historique.

**Ce que PI a aujourd'hui**
- Tables `balises`, `balise_items` (libellé unique, couleur), `balise_affectations` ; 9 types de fiches (`App\Support\Fiches`).
- Pastilles dans 9 listes, recherche de fiches par items, écran `/balises`. Les écritures suivent le module `configuration`.
- Manque :
  - les balises sur les lignes (ventes, achats, écritures) et leur héritage ;
  - le filtre des rapports par balises (`Rapport::TYPES_PARAMETRES` n'a pas de type balise) ;
  - les traductions ;
  - le refus de supprimer un item posé (PI retire les poses) ;
  - le journal.

**Ce qu'il faut faire dans PI**
- **Données** : ajouter des entités de ligne au catalogue `Fiches` : devis_ligne, commande_client_ligne, facture_ligne, bon_livraison_ligne, commande_fournisseur_ligne, facture_fournisseur_ligne, ecriture_ligne, transaction_bancaire_ligne. `balise_affectations` (entite, entite_id) suffit, sans nouvelle table. Colonne additive `balises.heritage_lignes` (booléen).
- **Règles** :
  - à la création d'une ligne de vente, poser les items des balises héritables, pris sur le client puis sur le produit. Pour une balise à un seul choix, en cas de conflit, le client l'emporte (décision à confirmer) ;
  - recopier les items des lignes à chaque conversion devis → commande → facture → bon de livraison.
- **Rapports** : nouveau type de paramètre `balises` dans `App\Rapports\Rapport` (items, mode « un » ou « tous », porte sur le client, le produit, le fournisseur ou la ligne). À appliquer dans VentesParClient, VentesParProduit, VentesParCategorie, VentesParPeriode, VentesParRepresentant, AchatsParFournisseur, StockProduits et ClientsInactifs ; côté écran, `ChampParametre`. L'outil `executer_rapport` de l'IA en profite aussi.
- **Réglage d'entreprise** « Refuser la suppression d'un item posé ». Par défaut, comportement actuel, avec une confirmation qui annonce le nombre de poses.
- **API** : `GET/PUT /balises/affectations` (existe déjà) accepte les entités de ligne. Le contrôleur vérifie le droit de modifier le document parent.
- **Écran** : sur les lignes des documents, une icône balise et des pastilles ; dans la bibliothèque des rapports, le paramètre « Balises ».
- **Droits** : nouveau module `balises` (crud complet), réglé comme `configuration` par migration ; `Droits::MODULES['balises']` passe de `configuration` à `balises`.
- **Traductions** : T2 sur les balises et les items.
- **Tests** : héritage client + produit, copie à la conversion, filtres « un » et « tous », ligne d'un document d'une autre entreprise → 404, module inactif → 403, isolation des rapports.
- **Taille** : M pour les rapports, M pour les lignes.
- **Pièges** : charger les affectations des lignes en une seule requête ; l'index de `balise_affectations` existe déjà.

### 4. Catégories de contact — **Manquant**

**Ce que fait fripv2**
- Une liste de rôles (Administration, Achats, Comptabilité…) qui se cochent sur les **contacts des clients**, plusieurs par contact.
- Champs : description (100, obligatoire, unique sans tenir compte de la casse, espaces retirés ; il n'y a pas de code) et actif (coché par défaut).
- Une catégorie désactivée n'est plus proposée, mais reste cochée et affichée sur les contacts qui la portent.
- La suppression est refusée si un contact la porte.
- Visible dans la fiche du contact (cases à cocher), dans la colonne « Catégories » des contacts de la fiche client et dans la liste des clients.
- Distincte du champ « Poste » (texte libre). Traduisible, journalisée.
- Droits : Voir, Ajouter, Modifier, Supprimer une catégorie de contact.

**Ce que PI a aujourd'hui**
- `client_contacts` et `fournisseur_contacts` ont un champ `fonction` (texte libre), `principal`, `recoit_factures` et `recoit_commandes` (`ClientFicheContactController`, `FournisseurFicheContactController`). Aucune catégorie.

**Ce qu'il faut faire dans PI**
- **Données** :
  - référentiel `categories_contact` (tenant_id, libelle 100, actif, ordre, timestamps, softDeletes) ;
  - pivot `contact_categories` (tenant_id, contact_type `client` ou `fournisseur`, contact_id, categorie_contact_id ; unique). Il couvre aussi les fournisseurs, ce qui va au-delà de fripv2.
- **Catalogue `Referentiels`** : entrée `categories_contact` (libellé obligatoire, 100 au plus, unicité sans casse par contrôle ; suppression refusée si le pivot l'utilise ; famille « Commercial »).
- **API** :
  - routes génériques `/referentiels/categories_contact` ;
  - les contrôleurs de contacts acceptent `categories` (ids des catégories actives, ou déjà posées), synchronisent le pivot après l'enregistrement et renvoient `categories` [{id, libelle, actif}] ;
  - en option, `GET /clients?categorie_contact_id=`.
- **Écran** :
  - onglet « Catégories de contact » de `/referentiels` ;
  - onglet Contacts des fiches client et fournisseur : cases à cocher (une catégorie inactive déjà posée reste grisée et cochée) et pastilles dans la liste ;
  - en option, au-delà de fripv2 : `ComposerCourriel` suggère les contacts d'une catégorie.
- **Droits** : la gestion suit `referentiels` (existant) ; la pose suit `clients.modifier` ou `fournisseurs.modifier` (déjà contrôlés par préfixe).
- **Traductions** : T2.
- **Tests** : unicité sans casse ; suppression refusée si utilisée ; une catégorie désactivée n'est plus proposée mais reste sur le contact ; catégorie d'une autre entreprise refusée (`TenantRule::exists`) ; contact d'une autre entreprise → 404 ; isolation de la liste.
- **Taille** : P. Activable de fait : tant que la liste est vide, rien ne change.

### 5. Champs personnalisés — **Partiel**

**Ce que fait fripv2**
- **Modules** : Clients (onglets Dossier, Détails, ou un onglet dédié), Produits (Général ou onglet dédié), Fournisseurs, et les documents Devis, Commandes, Bons de livraison, Factures (champs placés dans le cadre du document ou le cadre de droite de l'en-tête). L'onglet dédié est masqué s'il est vide.
- **Types** :
  - zone de texte (avec masque), multiligne (nombre de lignes), texte mis en forme, courriel, URL, téléphone (avec masque) ;
  - date (format date, heure ou date et heure) ;
  - nombre (décimales ; nombre, montant ou pourcentage) ;
  - case à cocher (avec libellé) ;
  - menu déroulant (en liste ou en boutons radio) et sélection multiple ;
  - **numéros générés** (préfixe, suffixe, longueur, départ) ;
  - **pièce jointe**.
  - Les numéros générés et les pièces jointes ne sont pas offerts sur les documents.
- **Options** : libellé traduisible, valeur par défaut, largeur, obligatoire, sans doublon. Disposition par glisser-déposer, ordre de tabulation, mode Aperçu.
- **Comportements** :
  - saisie **dans l'en-tête** du document, enregistrée avec lui ;
  - un champ obligatoire **bloque l'enregistrement** du document (sauf sur un document figé, tant qu'on ne touche pas aux champs) ;
  - formats vérifiés, doublons refusés ;
  - les valeurs **suivent copies et conversions** (même nom et même type ; choix repris par libellé) ;
  - à la création d'un champ de document, « Ajouter aux autres documents » ;
  - panneau « **Filtrer par champs personnalisés** » dans les listes de documents ;
  - **impression** sur les documents et dans le PDF envoyé par courriel (case → Oui/Non, symbole des montants) ;
  - historique des valeurs dans la fiche ou le document ;
  - un champ qui a déjà servi ne se supprime pas (le renommer « (obsolète) »).
- **Droits** : Ajouter, Modifier, Supprimer un champ personnalisé.

**Ce que PI a aujourd'hui**
- `ChampPersonnaliseController`, `App\Support\ChampsPersonnalises` : 9 entités (dont employé et commande fournisseur), 10 types, défaut, aide, obligatoire, sans doublon, actif, ordre, recherche par valeur. Écran `/champs-personnalises`.
- Saisie dans une **fenêtre séparée** (`BoutonChampsPersonnalises`). Un champ obligatoire ne bloque pas l'enregistrement de la fiche.
- Aucun report à la conversion, aucune impression, aucun filtre dans les listes. Supprimer un champ supprime aussi ses valeurs.

**Ce qu'il faut faire dans PI** (en lots)
- **Lot A — Report et filtres (M)** :
  - `ValeursChampsPersonnalises::recopier(source, cible)`, appelé par les conversions et copies (devis → commande ou facture, commande → bon de livraison ou facture, facture → bon de livraison, copie de devis) : même `code` et même `type`, choix repris par libellé, choix inexistant ignoré ;
  - les index des 9 entités acceptent `cp[<champ_id>]=valeur` (sémantique de `/champs-personnalises/recherche`) ;
  - côté écran, un panneau « Filtrer par champs personnalisés » avec un compteur.
- **Lot B — Saisie dans le formulaire et obligatoire bloquant (M)** :
  - composant `ChampsPersonnalisesFormulaire` intégré aux formulaires ;
  - les `store/update` acceptent `champs_personnalises` et valident ;
  - colonne additive `champs_personnalises.bloquant` (défaut false = comportement actuel), avec l'exception du document figé.
- **Lot C — Impression (M)** : colonne `imprimer`. Un bloc « Informations complémentaires » dans `DocumentsVentePdf` et `BonCommandePdf`, dans la langue du document.
- **Lot D — Types (M)** :
  - `heure`, `date_heure`, `texte_riche` (Markdown léger assaini) ;
  - `options.affichage = radio` pour les listes ;
  - `numero_auto` (compteur atomique dans la définition) ;
  - `fichier` (pièce jointe de la fiche ; non offert sur les documents) ;
  - masque de saisie, en option.
- **Lot E (P)** :
  - « Ajouter aux autres documents » (même code ; document grisé s'il a déjà le champ) ;
  - réglage d'entreprise « Refuser la suppression d'un champ qui a des valeurs » (défaut actuel) ;
  - valeurs avant/après dans l'historique de la fiche (T3).
- **Droits** :
  - module `champs_personnalises` réglé comme `configuration` ; `Droits::MODULES['champs-personnalises']` pointe vers lui ;
  - `PUT api/champs-personnalises/valeurs` reste dans `LIBRES`, mais le contrôleur vérifie le droit de modifier la fiche ou le document.
- **Traductions** : libellé et aide traduisibles (T2) ; nouveaux types.
- **Tests** : report (code/type, choix par libellé), filtre de liste, 422 si un champ bloquant est vide (sauf document figé), valeur imprimée dans le PDF, unicité de `numero_auto` en concurrence, isolation (champ ou valeur d'une autre entreprise → 404, le filtre ne fuit pas).
- **Taille** : G au total, en lots de taille P à M.
- **Pièges** : index `(champ_personnalise_id, valeur(191))` pour les filtres ; valeurs stockées en texte, donc caster pour les nombres et les dates.

### 6. En-têtes courriel — **Partiel**

**Ce que fait fripv2**
- Un modèle par type et par langue (combinaison unique) :
  - De (255, obligatoire), Objet (255, obligatoire), Répondre à (255, obligatoire) ;
  - Nom du PDF (255, variables admises, extension ajoutée, caractères interdits remplacés par « _ » ; vide = nom horodaté ; absent pour le rappel) ;
  - confirmation de réception ; contenu riche (obligatoire).
- Variables propres au type, plus celles de l'entreprise (nom, adresse, ville, province, pays, code postal, téléphone, télécopieur, courriel, site, numéros de taxes) et le **logo** (image intégrée, 200 px de large au plus ; rien sans logo).
- Aperçu avec le document le plus récent (ou un autre choisi), note de bas de page comprise, variables inconnues surlignées. Type et langue figés en modification. Historique champ par champ.
- **10 types** : Devis, Commande, Bon de livraison, Facture, **État de compte**, **Confirmation de paiement**, Commande fournisseur, Général, Demande d'acompte, Rappel de paiement.
  - Les variables de l'état de compte : solde total et par tranche (courant, 31–60, 61–90, 90+), plus ancienne échéance impayée.
  - La « date d'échéance » se calcule selon la condition de paiement.
- Le modèle se choisit **selon la langue du destinataire**. Droits : Voir, Ajouter, Modifier, Supprimer.

**Ce que PI a aujourd'hui**
- `courriel_modeles` (unique sur tenant, type et langue) ; catalogue `App\Support\Courriels`, 12 types : devis, commande_client, facture, avoir, demande_acompte, relance, relance_devis, bon_livraison, commande_fournisseur, retour_fournisseur, rapport, general.
- Variables, aperçu, modèles par défaut, nom du PDF, réponse, accusé de lecture. Onglet Courriels de `/configuration`. Une signature globale (`courriel_parametres`).
- Manque :
  - les types « Confirmation de paiement » et « État de compte » ;
  - la variable logo ;
  - l'expéditeur par modèle ;
  - le choix automatique de la langue (T4) ;
  - le journal des modèles.

**Ce qu'il faut faire dans PI**
- **Catalogue (additif)** :
  - type `encaissement` : variables numero, date, client_nom/code, montant, mode, reference, factures_reglees, solde_client ; PDF du reçu `EncaissementPdf` (`recu-{numero}.pdf`). Bouton « Envoyer par courriel » dans Encaissements, et réglage d'entreprise `courriel_confirmation_paiement` (envoi automatique à l'enregistrement ; défaut non) ;
  - type `etat_compte`, déclaré mais activé seulement quand l'état de compte existera (Comptabilité) ;
  - en option : `contrat_location`, `retour_client` (leurs PDF existent).
- **Variable `{entreprise_logo}`** : `RenduCourriel` la remplace par une image intégrée (CID, 200 px au plus). Rien dans la version texte ni sans logo.
- **Colonnes** `courriel_modeles.expediteur_nom`, et `expediteur_adresse` (utilisée seulement avec le SMTP de l'entreprise, ignorée en mode plateforme pour respecter SPF/DKIM).
- **Langue** : `EnvoiCourriel::preparer` propose la langue du tiers, sinon celle de l'entreprise. Les tâches (relances) l'utilisent au lieu de `fr`.
- **Journal** : `CourrielModele` dans `JournalModifications` (T3).
- **Droits** : `configuration` (existant). En option, un module `modeles_courriel` si l'on veut séparer.
- **Tests** : rendu avec et sans logo ; type encaissement (préparation, PDF joint, envoi automatique selon le réglage) ; choix de langue automatique ; le modèle d'une autre entreprise n'est jamais utilisé.
- **Taille** : M.
- **Dépendances** : logo (§ 1), T4, état de compte (Comptabilité).

### 7. Imprimantes — **Hors périmètre**

fripv2 règle chaque poste et chaque usager Windows :
- rapport → imprimante Windows, nombre de copies, ordre, mention « Original/Copie », type d'imprimante ;
- état vert ou rouge, changement en lot, cas du bureau à distance.

C'est propre au logiciel installé ; PI imprime des PDF par le navigateur. Un équivalent web serait facultatif : nombre de copies et mention de copie sur le PDF, impression directe des étiquettes et des reçus par un agent (QZ Tray, PrintNode), à voir avec Étiquettes et Point de vente.

### 8. Notes de bas de page — **Manquant**

**Ce que fait fripv2**
- Des textes riches réutilisables (conditions de vente, garantie, politique de retour…), choisis à l'impression ou à l'envoi.
  - La note s'imprime **sur une page neuve après la dernière page** et se poursuit sur plusieurs pages si besoin.
  - Elle s'insère dans le corps du courriel, **avant la signature** ; le PDF joint la contient aussi.
- Champs :
  - Nom (100, unique par document et par langue) ; le même nom dans l'autre langue associe les deux versions ;
  - Document : devis, commande client, bon de livraison, facture, état de compte, rappel de paiement, commande fournisseur, demande d'acompte, carte cadeau, contrat ;
  - Langue ;
  - Actif ;
  - « Proposer par défaut » : une seule par document et par langue (en cocher une décoche la précédente) ;
  - Contenu riche (obligatoire) ;
  - **Appliquer automatiquement** (devis, commandes, bons de livraison et factures seulement) : un champ personnalisé de type liste ou sélection multiple du document, les options qui font sortir la note, et une case « Quand plusieurs options sont cochées ». Un seul champ pilote les notes d'un type de document.
- **Choix d'une note**, dans cet ordre :
  1. le champ personnalisé, s'il en désigne une ;
  2. sinon, le choix déjà fait sur le document (y compris « Aucune ») ;
  3. sinon, la note par défaut.
- Le choix fait à l'impression ou dans la fenêtre du courriel **est enregistré sur le document** ; la réimpression le reprend. Changer de note dans la fenêtre du courriel réécrit le message, avec confirmation s'il avait été retouché.
- La version imprimée est **figée** (`note_bas_page_snapshot`, empreinte), pour réimprimer exactement la note reçue.
- Cas particuliers :
  - les envois automatiques (loyers, renouvellements, tâches) prennent la note retenue sur le document, sinon la note par défaut ;
  - le rappel de paiement prend la note par défaut de la langue du client ;
  - l'état de compte en lot choisit une note par son nom, puis la version de la langue de chaque client (aucune note si cette langue manque).
- Écran : liste ou **vignettes** (aperçu de la page de la note, groupées par document). Un clic ouvre un document d'exemple (choisi parmi les 50 plus récents) avec la note en dernière page, sans rien enregistrer.
- Droits : Voir, Ajouter, Modifier, Supprimer une note. Le choix à l'impression est libre.

**Ce que PI a aujourd'hui**
- Rien. Les PDF (`resources/views/pdf/document-vente.blade.php`…) n'ont que les notes du document et un pied de page. Les courriels ont une signature globale.

**Ce qu'il faut faire dans PI**
- **Données** :
  - table `notes_bas_page` : tenant_id, nom (100), document (enum), langue (5), contenu (Markdown léger, comme les modèles de courriel), actif, par_defaut, champ_personnalise_id (nullable), options (json), si_plusieurs_options, timestamps, softDeletes ; unique (tenant, document, langue, nom) ;
  - table `notes_bas_page_versions` : tenant_id, note_id, nom, contenu, empreinte char(64) ; unique (tenant, empreinte) ;
  - sur devis, com_client_entete, factures, bons_livraison, demandes_acompte, com_four_entete, contrats_location et cartes_cadeaux, trois colonnes nullable : `note_bas_page_id`, `note_bas_page_fixee` (bool ; vrai dès la première production, et alors un id vide veut dire « Aucune »), `note_bas_page_version_id`.
- **Service `NotesBasPage`** :
  - `resoudre(document, langue)` applique l'ordre : règle du champ personnalisé, puis choix fixé, puis note par défaut, puis aucune ;
  - `fixer(document, note)` enregistre le choix et crée la version ;
  - `html(note)`.
- **PDF** : une page avec `page-break-before: always` dans `DocumentsVentePdf`, `BonCommandePdf`, `ContratLocationPdf` et `CertificatCadeauPdf`.
- **Courriel** : `RenduCourriel` insère la note après le contenu et avant la signature, dans les versions HTML et texte.
- **API** :
  - CRUD `/notes-bas-page`, `GET /notes-bas-page/choix?document=&document_id=&langue=` (notes proposées et note présélectionnée), `GET /notes-bas-page/{id}/apercu?document_id=` (PDF, rien n'est enregistré) ;
  - les routes PDF acceptent `note_bas_page_id` ; `POST /courriels/envois` accepte `note_bas_page_id`.
- **Écran** :
  - Paramètres → **Notes de bas de page** (`/notes-bas-page`) : liste, vue en vignettes, éditeur avec aperçu ;
  - une liste « Note de bas de page » dans `BoutonPdf` et dans `ComposerCourriel`.
- **Droits** : module `notes_bas_page` (crud ; onglet parametres) réglé comme `configuration` ; `Droits::MODULES['notes-bas-page']`. Choisir une note à l'impression ou à l'envoi est libre.
- **Activable** : sans note, rien ne change.
- **Traductions** : écran, messages de l'API, libellés des types de document.
- **Tests** :
  - unicité du nom, une seule note par défaut ;
  - ordre de résolution, y compris « Aucune » fixée qui persiste ;
  - le PDF a une page de plus avec le texte ; le courriel a la note avant la signature ;
  - après modification de la note, une réimpression garde la version figée ;
  - une relance automatique prend la note de la langue du client ;
  - une note d'une autre entreprise est refusée ; module inactif → 403.
- **Taille** : M, et G avec la règle par champ personnalisé et les vignettes.
- **Dépendances** : T4, champs personnalisés de type liste (existent), état de compte (plus tard).

### 9. Remarques rapides — **Partiel**

**Ce que fait fripv2**
- Catégorie libre (60 caractères, suggestions des catégories existantes, aucune restriction) et texte simple multiligne (obligatoire).
- Sélecteur (touche F4 ou clic droit) :
  - recherche sur le texte, filtre par catégorie (« Toutes » au départ), aperçu du texte complet ;
  - **Insérer** (au curseur, ou sous la description d'une ligne) ou **Remplacer**.
- Points d'insertion :
  - notes des documents, notes internes des lignes, remarques des fiches client, fournisseur et produit ;
  - **description des lignes** des devis, commandes, factures et bons de livraison (bouton « … » de la ligne) ;
  - grilles des commandes et réceptions fournisseur.
  - L'insertion est bloquée si le document n'est plus modifiable.
- Droits : Voir, Ajouter, Modifier, Supprimer, et **Insérer une remarque rapide** ; insérer dans une note interne demande en plus « Modifier la note interne des lignes ».

**Ce que PI a aujourd'hui**
- Table `remarques_rapides` (catégorie 60, texte 2 000), `RemarqueRapideController` (**aucun contrôle de droits** : T1), gestion dans un onglet de `/balises`.
- `BoutonRemarquesRapides` insère **à la suite** des notes d'en-tête de 5 écrans : DevisPage, CommandesClientPage, FacturesPage, CommandesFournisseurPage, BonsLivraisonPage.

**Ce qu'il faut faire dans PI**
- **Écran** :
  - un hook `useRemarqueRapide(ref)` réutilisable, avec les boutons « Insérer au curseur » et « Remplacer », un filtre par catégorie et un raccourci (F4 ou Alt+R) ;
  - à brancher sur les descriptions de ligne des devis, commandes, factures, bons de livraison et commandes fournisseur, sur les remarques des fiches client et fournisseur, sur la description longue du produit, et sur les notes des retours et des réceptions ;
  - désactivé si le document n'est plus modifiable.
- **Droits** : module `remarques_rapides` (crud) avec l'action `inserer` (base `voir`) ; `Droits::MODULES['remarques-rapides']` corrige T1. Par défaut, l'écriture est réglée comme `configuration`, et `voir` et `inserer` sont accordés à tous les rôles (usage actuel).
- **Journal** : T3.
- **Tests** : 403 sans le droit (les droits par défaut reproduisent l'existant), insertion et remplacement (tests de composant), isolation (existe).
- **Taille** : P.

### 10. Sections de documents — **Partiel**

**Ce que fait fripv2**
- Rubriques de classement des pièces jointes **par type d'entité** (client, fournisseur, produit), présentées en arbre.
- Champs : description (50, traduisible, espaces retirés), type (obligatoire), ordre. Le même nom peut exister sous deux types.
- Suppression refusée si une pièce est rangée dans la section. Renommer ne casse aucun lien.
- Lors de l'ajout d'une pièce, la section est obligatoire (préremplie et verrouillée selon le contexte). Les listes de pièces se filtrent par section.
- Droits : Voir, Ajouter, Modifier, Supprimer une section ; historique.

**Ce que PI a aujourd'hui**
- `pieces_jointes.categorie` : texte libre (100), avec suggestion des catégories déjà utilisées (`GET /pieces-jointes/categories`). Ni liste gérée, ni traduction.

**Ce qu'il faut faire dans PI**
- **Données** :
  - référentiel `sections_pieces_jointes` : tenant_id, entite (une clé de `PiecesJointes::ENTITES`, ou null pour toutes), libelle (50), ordre, actif, softDeletes ;
  - colonne nullable `pieces_jointes.section_id`. `categorie` est gardée ; l'affichage montre la section si elle existe, sinon la catégorie.
- **Réglage d'entreprise** `pieces_jointes_sections_obligatoires` (défaut faux, donc comportement inchangé).
- **Action « Convertir les catégories en sections »** : crée les sections à partir des catégories distinctes et relie les pièces.
- **API** : `/referentiels/sections_pieces_jointes` (générique ; suppression refusée si utilisée) ; `POST /pieces-jointes` accepte `section_id`, validé contre l'entité ; filtre `section_id`.
- **Écran** : onglet « Sections » de `/pieces-jointes` (ou de `/referentiels`) ; liste de choix dans l'envoi de fichiers ; filtre par section.
- **Droits** : `referentiels` pour la gestion.
- **Traductions** : T2.
- **Tests** : section d'une autre entité refusée (422) ; section obligatoire selon le réglage ; suppression refusée si utilisée ; conversion ; isolation.
- **Taille** : P.

### 11. Documents / Attachements — **Partiel**

**Ce que fait fripv2**
- Deux contextes :
  - ciblé (une fiche ou un document) ;
  - global (menu), avec « Ajouter » qui fait d'abord choisir l'entité ou le document.
- Entités : client, fournisseur, produit, et **les documents de leur dossier** (factures, devis, commandes, bons de livraison, réceptions, encaissements, déboursés, comptes à payer et à recevoir, transactions GL et bancaires).
- Filtres : recherche (description, nom de fichier, entité, n° de document, auteur), client ou fournisseur, n° de document, section, type d'entité. Colonnes : Ajouté par, Date. Pagination.
- **Fichiers nouveaux** depuis la dernière consultation : surlignés « Nouveau ». Le bouton Documents porte une **pastille** avec le nombre de pièces (bleue s'il y a des nouveautés, grise sinon).
- Ajout : plusieurs fichiers, glisser-déposer, presse-papiers (image collée en PNG nommé « Image collée … » ; texte riche, HTML ou texte, avec la bonne extension) ; extensions refusées (`.exe`, `.iso`, `.bkp`, `.bat`).
- Modification : la description seulement (traduisible). Une pièce ne se déplace pas.
- Ajouts, modifications et suppressions vont dans **l'historique de l'entité parente**.
- Droit : « Ajouter une pièce jointe ».

**Ce que PI a aujourd'hui**
- `App\Support\PiecesJointes::ENTITES` : 13 entités (client, fournisseur, produit, employé, devis, commande client, facture, encaissement, bon de livraison, commande fournisseur, facture fournisseur, déboursé, transaction bancaire).
- `PieceJointeController` : envoi multiple, collage d'image, stockage disque ou S3, sécurité, `ajoute_par`. Liste globale `/pieces-jointes`.
- Manque :
  - les écritures comptables, réceptions, retours, contrats, abonnements, avoirs, consignations ;
  - la pastille de comptage et les « Nouveau » ;
  - la vue d'ensemble d'un client (sa fiche et ses documents) ;
  - les **droits** (T1 : aucun contrôle de module) ;
  - le journal.

**Ce qu'il faut faire dans PI**
- **Entités** : ajouter à `ENTITES` ecriture_comptable, reception, retour_client, retour_fournisseur, avoir, contrat_location, abonnement_client, consignation, demande_acompte (une ligne chacune, plus `BoutonPiecesJointes` dans la page).
- **Compteurs** : `GET /pieces-jointes/compteurs?entite=&ids=` (en lot, 200 au plus) → `{id: {total, non_vues}}`, et une pastille sur `BoutonPiecesJointes`.
- **Lus et non lus** : table `pieces_jointes_lectures` (tenant_id, admin_user_id, entite, entite_id, vu_le ; unique). Ouvrir la liste d'une fiche met `vu_le` à jour. Une pièce est « nouvelle » si `created_at > vu_le` et qu'elle a été ajoutée par un autre compte.
- **Vue d'ensemble** : `GET /pieces-jointes?client_id=` renvoie les pièces de la fiche et de ses devis, commandes, factures et bons de livraison (`fournisseur_id` : commandes, réceptions, factures fournisseurs), avec une colonne « Rattachée à ».
- **Droits** : module `pieces_jointes` (crud : voir = liste globale, creer, modifier, supprimer) ; `Droits::MODULES['pieces-jointes']`. Le contrôleur exige aussi le droit `voir` du module de la fiche. Par défaut, tous les rôles reçoivent les droits actuels ; restreindre la lecture seule est une décision à prendre.
- **Journal** : `PieceJointe` journalisée sous le libellé de son parent (T3).
- **Tests** : compteurs (total et non vus) ; vue d'ensemble d'un client ; droits ; isolation des compteurs ; aucun fichier orphelin (existe).
- **Taille** : M.

### 12. Type d'imprimante — **Hors périmètre**

C'est une liste fixe de catégories d'imprimantes Windows (reçus, cuisine, étiquettes, laser), au libellé traduisible, qui ne sert qu'à l'écran Imprimantes (§ 7).

### 13. Tâches planifiées — **Partiel**

**Ce que fait fripv2**
- Liste : description, actif, récurrence, heure, prochaine et dernière exécution, **code retour** (Succès, Erreur, Action inconnue, Verrou occupé), statut (Inactive, En attente, En cours).
- Filtres (Actif, Récurrence, recherche) ; dépliage des 20 dernières exécutions.
- Actions : Modifier, Activer/Désactiver, Démarrer maintenant (avec confirmation), Historique de la tâche (avec export CSV), **Historique global avec statistiques** (succès, erreurs, durée moyenne, durée maximale).
- Récurrences : une fois, quotidienne, hebdomadaire (plusieurs jours), mensuelle (1 à 31, ramené au dernier jour). La prochaine exécution se force à la main. Modes de déclenchement : planifié, manuel, direct.
- Tâches propres à fripv2 :
  - période comptable courante (00 h 15) ;
  - surplus de location (03 h) ;
  - renouvellement des contrats (04 h) ;
  - **téléchargement quotidien des taux de change** (06 h) ;
  - loyers mensuels (le 1er à 02 h) ;
  - **envoi automatique des états de compte** (le 1er à 05 h).
- **Droits** : Voir les tâches, Modifier ou activer, **Démarrer maintenant**, Voir l'historique.

**Ce que PI a aujourd'hui**
- `App\Support\TachesPlanifiees` (18 tâches, paramètres, modules), `Planificateur`, `TachePlanifieController`. Écran `/taches-planifiees` et réglage des alertes.
- Les mêmes récurrences, la prochaine exécution, l'exécution immédiate (409 si déjà en cours) et l'historique filtrable.
- Les routes sont réservées par **`role:admin`** : aucune permission.
- Manque : les statistiques et l'export CSV de l'historique, les filtres de la liste, les tâches « taux de change » et « états de compte ».

**Ce qu'il faut faire dans PI**
- **Droits** :
  - module `taches_planifiees` (crud `['voir','modifier']`) avec les actions `executer` (base modifier) et `historique` (base voir) ; par défaut, administrateur seulement ;
  - `Droits::LECTURES['taches-planifiees']` ;
  - `PARTICULIERES` : `POST …/{id}/executer` → `executer` ; `GET …/historique` → `historique` ;
  - retirer `role:admin` une fois les droits en place (comportement identique).
- **Historique** : `GET /taches-planifiees/statistiques?du=&au=` (par tâche : nombre, succès, erreurs, durée moyenne et maximale), `GET /taches-planifiees/historique.csv`. Écran : filtres Actif, Récurrence, recherche ; dépliage des dernières exécutions.
- **Nouvelles tâches** (inactives par défaut) :
  - `taux_change` (quotidienne, 06 h ; module finance) : pour chaque devise active, récupère le cours (source configurable : Frankfurter/BCE par défaut, comme `TauxChangeController`) et l'historise dans `devise_taux` ; ne remplace pas un taux saisi le jour même ; paramètre `devises` ;
  - `etats_compte` (mensuelle, le 1er) : seulement quand l'état de compte existera (Comptabilité), et avec T4 ;
  - la période courante est inutile dans PI (une période se déduit de la date). Le surplus de location dépend des actifs retournables (contrats de location).
- **Traductions** : libellés des nouvelles tâches et des statistiques.
- **Tests** : droits (403 sans `executer`) ; statistiques et export ; `taux_change` avec une source simulée (historisation, aucun doublon dans la journée) ; isolation des historiques.
- **Taille** : M.

### 14. Fournisseurs API — **Manquant**

**Ce que fait fripv2**
- Un répertoire des partenaires de **facturation à l'usage** (VoiceMeUp, ITCloud), qui fournissent la consommation des clients.
- Champs :
  - Nom (50, obligatoire, unique) ;
  - Actif (défaut : non) ;
  - Visible dans les abonnements ;
  - « Le code ou compte se saisit sur la fiche client » ;
  - **5 paramètres de connexion** (libellé 50 + valeur 255 ; le 3e en texte long), **chiffrés en AES** et lus **par position**.
- Seuls les actifs et visibles sont proposés sur un abonnement ; les abonnements existants gardent leur fournisseur.
- Ajouter et supprimer : administrateur seulement. Modifier : « Modifier les fournisseurs API ». L'historique garde l'ancienne et la nouvelle valeur.

**Ce que PI a aujourd'hui**
- Facturation à l'usage par relevés saisis à la main ou importés en CSV (`abonnement_releves`, `POST /abonnements-clients/releves/import`). Aucun connecteur.

**Ce qu'il faut faire dans PI** (générique : « Connecteurs de consommation », plan 6.10)
- **Données** :
  - table `connecteurs_consommation` : tenant_id, nom (50, unique), pilote (code du catalogue), actif, visible_abonnements, parametres (json **chiffré** `Crypt::encryptString`, libellé + valeur), reference_sur_fiche_client, derniere_synchro_le, dernier_statut (255), softDeletes ;
  - colonnes `abonnements_clients.connecteur_id` et `reference_externe` (nullable) ;
  - table `connecteur_references_clients` : tenant_id, connecteur_id, client_id, reference ; unique.
- **Pilotes** : interface `App\Services\Connecteurs\PiloteConsommation` (`schema()`, `tester()`, `releves(debut, fin)` → [reference, code produit, date, quantité, identifiant externe]). Pilotes génériques livrés :
  - `json_http` : URL avec gabarit, en-tête d'authentification tiré des paramètres, chemins JSON ;
  - `csv_url` : CSV téléchargé, mêmes colonnes que l'import actuel.
  - Les pilotes propres à un fournisseur s'ajoutent comme classes.
- **Tâche** `releves_connecteurs` (quotidienne, 05 h, inactive par défaut) : importe dans `abonnement_releves`, sans doublon grâce à une colonne additive `identifiant_externe`, unique.
- **API** : CRUD `/connecteurs` (les valeurs ne sont **jamais renvoyées en clair** ; une valeur vide est conservée, comme le mot de passe SMTP), `POST /connecteurs/{id}/tester`, `POST /connecteurs/{id}/synchroniser`.
- **Écran** : Paramètres → **Connecteurs** (`/connecteurs`) ; un champ « Connecteur » et une référence sur la fiche d'abonnement ; une référence sur la fiche client si le connecteur l'exige.
- **Droits** : module `connecteurs` (crud) avec l'action `synchroniser`, administrateur par défaut ; `Droits::MODULES['connecteurs']`.
- **Activable** : sans connecteur, rien ne change.
- **Tests** : chiffrement (la valeur n'est pas lisible en base ni dans l'API) ; pilote simulé (HTTP faux) ; idempotence ; connecteur inactif non proposé ; isolation (connecteur ou référence d'une autre entreprise → 404).
- **Taille** : G.
- **Piège** : ne pas journaliser les valeurs secrètes. fripv2 les journalise ; PI ne doit pas le faire.

### 15. Conditions de paiement — **Partiel**

**Ce que fait fripv2**
- Champs :
  - Description (30, traduisible ; doublon admis) ;
  - **Nombre de jours** ou **Jour du mois suivant** (1 à 31, exclusifs ; ramené au dernier jour du mois s'il n'existe pas) ;
  - Jours d'escompte ;
  - Taux d'escompte (4 décimales, obligatoire, sans plafond).
- Une condition à 0 jour et 0 jour du mois suivant est « comptant ».
- Escompte :
  - proposé d'office au **premier paiement** d'une facture client, si le paiement est fait au plus tard à la date de la facture plus les jours d'escompte, et seulement s'il règle tout le reste ;
  - jamais sur une note de crédit ni sur une facture au comptant ;
  - aussi au déboursé fournisseur.
- Utilisée sur les **clients, fournisseurs, devis, commandes, commandes fournisseur, bons de livraison, factures, comptes à recevoir et à payer**. Un document émis garde la description et l'échéance de son émission.
- **Suppression refusée si la condition est utilisée.** Droits : Voir, Ajouter, Modifier, Supprimer.

**Ce que PI a aujourd'hui**
- Référentiel `conditions_paiement` : code, libellé, delai_jours, fin_de_mois, escompte (pourcentage, jours), par_defaut. Service `ConditionsPaiement` : échéance, escompte.
- Utilisée sur `clients`, `factures` et `factures_fournisseurs`. **`fournisseurs.conditions_paiement` est du texte libre.** Rien sur les devis, commandes et commandes fournisseur.
- Pas de « jour du mois suivant », pas de traduction, pas de contrôle à la suppression (T5).

**Ce qu'il faut faire dans PI**
- **Données (additif)** :
  - `conditions_paiement.jour_mois_suivant` (1 à 31, nullable ; exclusif de `delai_jours > 0` et de `fin_de_mois`) ;
  - `fournisseurs.condition_paiement_id` (le texte existant est gardé et affiché en repli) ;
  - `devis.condition_paiement_id`, `com_client_entete.condition_paiement_id`, `com_four_entete.condition_paiement_id`.
- **Règles** :
  - `ConditionsPaiement::echeance` gère `jour_mois_suivant` ;
  - la condition se préremplit depuis le client (ou le fournisseur) et se reprend à chaque conversion jusqu'à la facture ;
  - elle s'imprime sur les PDF ; la variable `{date_echeance}` est calculée pour les commandes et demandes d'acompte (§ 6).
- **Catalogue** : `suppression` refusée si utilisée (clients, fournisseurs, factures, factures_fournisseurs, documents) ; règle d'exclusion dans `controle`.
- **Écran** : `/referentiels` (champ « Jour du mois suivant ») ; listes de choix sur la fiche fournisseur et sur les devis, commandes et commandes fournisseur.
- **Droits** : `referentiels` (existant).
- **Traductions** : T2.
- **Tests** : échéance au 15 du mois suivant (et au 31 un mois de février) ; exclusion ; reprise à la conversion ; préremplissage depuis le fournisseur ; suppression refusée ; isolation.
- **Taille** : M.

### 16. Modes de paiement — **Partiel**

**Ce que fait fripv2**
- Champs :
  - Description (30, traduisible) ;
  - **Devise** (obligatoire) ;
  - **compte du grand livre** (dans la devise) ;
  - **Type de compte** : à recevoir (vente) ou à payer (achat) ;
  - pour un compte à payer, **type d'émission** : aucun, TFE ou impression de chèque ;
  - pour un compte à recevoir, mode spécial **carte cadeau** (passif des cartes ; un seul par devise) ;
  - actif.
- Seuls les modes **actifs, du bon sens et de la devise du document** sont proposés (encaissements, déboursés, acomptes, cartes cadeaux, caisse).
- Si l'on change le compte d'un mode, la modification d'un encaissement déjà passé demande si l'écriture reste sur l'ancien compte. Un mode désactivé reste affiché sur les transactions et sur la caisse ouverte.
- Droits : Voir, Ajouter, Modifier, Supprimer.

**Ce que PI a aujourd'hui**
- Référentiel `modes_paiement` (code, libellé, type parmi especes, cheque, virement, carte, mobile_money, prelevement, autre) **qu'aucun document n'utilise**.
- Les documents valident une liste fixe : `Encaissement::MODES`, `Debourse::MODES`, `FactureFournisseur::MODES`, `Acomptes::MODES`, `Caisses::MODES`, `Comptabilisation::MODES` = especes, cheque, virement, carte, autre. Mobile money ou prélèvement ne peuvent donc pas servir.
- La comptabilisation range les espèces en caisse et le reste en banque.

**Ce qu'il faut faire dans PI**
- **Données (additif)** :
  - sur `modes_paiement` : `sens` (encaissement, decaissement, les_deux), `devise_code` (nullable = toutes), `compte_comptable_id` (nullable), `emission` (aucune, cheque, virement), `carte_cadeau` (bool), `ordre` ;
  - colonne nullable `mode_paiement_id` sur encaissements, debourses, acomptes, facture_paiements, facture_fournisseur_paiements, caisse_mouvements et les paiements du point de vente.
- **Règles** :
  - un `mode_paiement_id` donné renseigne aussi `mode` = le type du référentiel (mobile_money et prelevement → `autre`). L'ancien champ reste valide : **sans mode défini, le comportement est inchangé** ;
  - `Comptabilisation` prend le compte du mode s'il est renseigné ;
  - les chèques et virements des déboursés suivent `emission` ;
  - une seule carte cadeau par devise.
- **API** : validation `mode_paiement_id` actif, du bon sens et de la bonne devise ; `GET /referentiels/modes_paiement?sens=&devise=`.
- **Écran** : listes de choix (encaissements, déboursés, acomptes, caisse, point de vente) alimentées par le référentiel s'il y a des modes, sinon par la liste fixe.
- **Droits** : `referentiels` ; T5 (suppression refusée si utilisé).
- **Traductions** : T2.
- **Tests** : mode d'une autre devise ou d'un autre sens refusé ; écriture sur le compte du mode ; repli sur la liste fixe ; isolation.
- **Taille** : M.
- **Dépendance** : configuration à compléter (« modes par devise »).

### 17. Classes de compte — **Fait (par un équivalent)**

fripv2 a une liste fixe de classes (Actif, Passif, Avoir, Revenus, Dépenses, Bénéfice net en cours). On peut changer le libellé (traduisible) et le drapeau « écriture manuelle » d'une classe, sans en ajouter ni en supprimer.

PI couvre le besoin :
- `comptes_comptables.nature` (actif, passif, capitaux_propres, produit, charge), `type` (dont `resultat`) ;
- `saisie_manuelle` **par compte**, plus fin que par classe ;
- natures traduites à l'écran ; plans types SYSCOHADA, PCG, Canada.

Écart facultatif : une action en lot « interdire la saisie manuelle sur tous les comptes d'une nature », et des libellés de nature propres à l'entreprise.

### 18. Types de location — **Partiel**

**Ce que fait fripv2**
- Champs :
  - **Durée en jours** (1 à 99 999 999 ; reprise dans le contrat, modifiable) ;
  - **liste de prix du contrat** (obligatoire ; donne le prix proposé pour chaque produit) ;
  - **liste du prix de location** (nature « cylindres » : prix par jour des unités gardées au-delà de la quantité prévue au contrat) ;
  - **fréquence de calcul** (journalière, hebdomadaire, 14 jours, mensuelle, trimestrielle, semestrielle, annuelle, horaire) ;
  - **émission de la facture** : à la création, **au retour**, le ##e jour du mois (1 à 31), **tous les ### jours** après le début (1 à 365), le premier jour du mois, le dernier jour du mois ;
  - description (30, traduisible) ;
  - nature (cylindres ou matériel ; pose la case « contrat cylindre » du contrat).
- Contrôles dans un ordre fixe. Le champ de jour qui ne correspond pas au mode d'émission est vidé. Suppression refusée si un contrat l'utilise.

**Ce que PI a aujourd'hui**
- Référentiel `types_location` : code, nom, duree_mois (1 à 120), frequence (de mensuelle à annuelle), jour_facturation (1 à 28), a_echoir.
- Choisi sur un contrat (FLOW-VENTES § 5.17), il préremplit le rythme et la fin.

**Ce qu'il faut faire dans PI**
- **Données (additif)** :
  - `duree_jours` (nullable ; prioritaire sur `duree_mois`) ;
  - `emission` (a_la_creation, au_retour, jour_du_mois, tous_les_n_jours, premier_jour_mois, dernier_jour_mois ; défaut `jour_du_mois` = comportement actuel) et `intervalle_jours` (1 à 365) ;
  - `liste_prix_id` (prix du contrat proposé) ;
  - `nature` (materiel, actif_retournable) et `liste_prix_depassement_id` (actifs retournables gardés au-delà de la quantité prévue).
  - Les fréquences quotidienne, hebdomadaire et 14 jours ne s'ajoutent qu'avec le moteur de facturation à la journée (voir dépendances).
- **Règles** : `ContratsLocation` calcule la prochaine facture selon `emission`. « Au retour » : facture créée au retour des actifs. La tâche `facturation_locations` suit.
- **Catalogue** : `suppression` refusée si `contrats_location.type_location_id` l'utilise.
- **Traductions** : T2.
- **Tests** : chaque mode d'émission (dates des prochaines factures) ; prix proposé depuis la liste ; suppression refusée ; isolation.
- **Taille** : M, et G avec la facturation à la journée.
- **Dépendances** : contrats de location (onglet Vente) et la généralisation « actifs retournables » du plan § 2.

### 19. Statuts transactions — **Manquant**

**Ce que fait fripv2**
- Les statuts système de chaque type de document : Devis, Commande, Bon de livraison, Facture, B/O, Commande fournisseur, Réception fournisseur, Demande d'acompte, Carte cadeau, Compte à recevoir, Compte à payer.
- Pour chacun : **libellé** (40, traduisible), **couleur du fond et du texte**, code (1 à 127, unique par type), case « Modifiable » (sans elle, un document qui porte ce statut ne se modifie plus).
- On peut **ajouter** des statuts aux devis, commandes fournisseur et B/O (proposés s'ils sont « Modifiable »).
- Pour les comptes à recevoir et à payer, l'état est **calculé** : Payé complet, Payé partiel, Impayé, **En retard** (prioritaire, affiché « En retard de N jours »), Annulé. Un montant négatif s'affiche « À rembourser » ou « Remboursé » (clients), « Crédit à recevoir » ou « Crédit reçu » (fournisseurs). Les couleurs **colorent toute la ligne** des listes et le badge de la fiche.
- Droits : Voir, Ajouter, Modifier, Supprimer un statut de document.

**Ce que PI a aujourd'hui**
- Les statuts sont des constantes de modèle (`Devis::STATUT_*`, `ComFourEntete::STATUT_*`, `VirementLot::EMIS/REFUSE`…). Libellés et couleurs sont codés dans les pages. Aucun réglage.

**Ce qu'il faut faire dans PI** (libellés et couleurs seulement ; les ajouts passent par les statuts personnalisés, § 20)
- **Catalogue** `App\Support\StatutsDocuments` : pour chaque document, ses codes de statut système avec libellés fr/en et couleurs par défaut. Il inclut les états calculés des comptes clients et fournisseurs, des demandes d'acompte, des cartes cadeaux et des lots de virement.
- **Données** : table `statuts_documents_reglages` (tenant_id, document, code, libelle_fr, libelle_en, couleur_fond, couleur_texte, ordre ; unique sur les trois premiers).
- **API** : `GET /statuts-documents` (catalogue fusionné avec les réglages, mis en cache par entreprise), `PUT /statuts-documents/{document}/{code}`, `DELETE` (retour au défaut).
- **Écran** :
  - Paramètres → **Statuts des documents** (`/statuts-documents`) : un onglet par famille, pipettes de couleur, aperçu du badge ;
  - un composant partagé `BadgeStatut` dans les listes ; option « colorer la ligne » pour les comptes clients et fournisseurs.
- **Droits** : module `statuts_documents` (crud `['voir','modifier']`) réglé comme `configuration`.
- **Non repris** : codes, ajouts de statuts et case « Modifiable ». Les cycles de PI sont codés, et les écrire dans une table réécrirait des règles existantes.
- **Tests** : réglage et retour au défaut ; un code inconnu → 422 ; fusion avec le catalogue ; isolation.
- **Taille** : M.

### 20. Statuts personnalisés — **Partiel**

**Ce que fait fripv2**
- Des étiquettes libres de repérage, **sans effet sur le cycle du document** : description (40, traduisible), **couleurs du fond et du texte**, actif.
- Une **seule** étiquette par document (devis, commande, bon de livraison, facture ; liste commune aux quatre). Choix « (Aucun) » pour la retirer.
- Sur un document clos, la liste reste modifiable et s'enregistre tout de suite. Étiquette en couleur en tête de fiche, colonne et **filtre** « Statut personnalisé » dans la gestion des commandes.
- Une étiquette désactivée n'est plus proposée, mais reste dans la colonne des documents qui la portent. **Suppression refusée si elle est utilisée.**
- Droits : Voir, Ajouter, Modifier, Supprimer.

**Ce que PI a aujourd'hui**
- Référentiel `statuts_personnalises` (document parmi devis, commande_client, bon_livraison, facture, commande_fournisseur, reception, autre ; code, libellé, couleur, ordre, actif). **Aucun document ne le porte.**

**Ce qu'il faut faire dans PI**
- **Données (additif)** :
  - `statuts_personnalises.couleur_texte` (#RRGGBB ; `couleur` sert de fond). `document = autre` veut dire « tous les documents » ;
  - colonne nullable `statut_personnalise_id` sur devis, com_client_entete, factures, bons_livraison, com_four_entete et receptions.
- **API** :
  - `PUT /{documents}/{id}/statut-personnalise` (droit de modifier le document, accepté **même sur un document clos**) ;
  - filtre `?statut_personnalise_id=` sur les index, et l'étiquette (libellé, couleurs) renvoyée dans les listes ;
  - `suppression` refusée si un document la porte (T5).
- **Écran** : pastille en couleur dans les listes, liste de choix dans la fiche ou la fenêtre du document (une étiquette inactive déjà posée reste affichée), filtre dans les listes. Gestion dans `/referentiels` (couleur du texte en plus).
- **Règle optionnelle** : recopier l'étiquette à la conversion si elle est valable pour le document cible.
- **Droits** : gestion dans `referentiels` ; la pose suit le module du document.
- **Traductions** : T2.
- **Tests** : pose sur un document clos ; filtre ; étiquette d'un autre type de document refusée ; suppression refusée ; isolation.
- **Taille** : M.

### 21. Raisons de mouvement — **Partiel**

**Ce que fait fripv2**
- Les raisons **attribuées par le système** à chaque mouvement de stock : réception, vente, location, retour fournisseur, décompte physique, ajustement manuel, relocalisation, note de crédit, consignation…
- Champs : numéro (entier unique ; les tablettes s'en servent : 2 = livraison, 4 = retour) et description (30, traduisible).
- On **change surtout le libellé**. Une raison ajoutée n'est jamais attribuée automatiquement.
- Visibles dans **Mouvements d'inventaire** (colonne et filtre « Raison ») et dans l'historique d'un produit. Suppression refusée si un mouvement l'utilise. Droits : Voir, Ajouter, Modifier, Supprimer.

**Ce que PI a aujourd'hui**
- Référentiel `raisons` de types `mouvement` et `ajustement` (`App\Models\Raison`) **non utilisé**.
- Les mouvements ont un `type_mouvement` (énumération) et un `motif` en texte libre (`mouvements_inventaire`). La relocalisation et les corrections passent par du texte.

**Ce qu'il faut faire dans PI**
- **Données** : colonne nullable `raison_id` sur `mouvements_inventaire` et `mouvement_relocalisation` (et sur les corrections de prise d'inventaire si besoin).
- **Règles** : comme pour les retours, la raison devient **obligatoire dès que l'entreprise a une raison active** du type concerné. Le `motif` libre est gardé.
- **API** : `raison_id` accepté sur la relocalisation (web et `/mobile`), les mouvements manuels et les corrections ; filtre `raison_id` sur l'historique des mouvements et la traçabilité.
- **Écran** : liste de choix dans Relocalisation, ajustements et approbations ; colonne et filtre dans l'historique et la traçabilité. Application Android : liste de choix, en option.
- **Libellés des types système** : en option, par le catalogue de libellés du § 19.
- **Rapport** : « Mouvements par raison » dans la bibliothèque (en option).
- **Tests** : raison d'un autre type refusée ; obligatoire selon les données ; filtre ; isolation.
- **Taille** : P à M.

### 22. Raisons de retour — **Fait**

Dans fripv2, les raisons de retour (code de 10 caractères en majuscules, description de 50, traduisible) sont posées par le système ou choisies parmi des choix fixes ; une raison ajoutée n'est proposée nulle part.

PI va plus loin : les raisons `retour_client` et `retour_fournisseur` se choisissent sur les lignes de retour et deviennent obligatoires dès qu'il en existe une (`RetourClientController`, `RetourFournisseurController`).

Écarts mineurs : traduction (T2), suppression refusée si utilisée (T5), rapport des retours par raison (en option).

### 23. Numérotation — **Partiel (mineur)**

**Ce que fait fripv2**
- Pour chaque type : numéro courant (le dernier utilisé), longueur (6 à 14 ; 6 à 24 pour le code-barres interne), préfixe (6 caractères).
- Types : clients et fournisseurs (numérotation automatique ou manuelle), devis, commandes, commandes fournisseur, réceptions, bons de livraison, factures, contrats, **codes-barres internes**, chèques, lots TFE, récurrences, cartes cadeaux (mode automatique, ou numéro saisi ou scanné).
- Écran verrouillé pendant sa modification ; enregistrement transactionnel ; historique avant/après.

**Ce que PI a aujourd'hui**
- Table `configurations` et `Configuration::DEFAUTS` (24 entités : préfixe, séparateur, longueur, suffixe, prochain numéro, automatique ou non). Onglet Numérotation de `/configuration`. Journalisée. Numéros propres à chaque entreprise ; incrément atomique.
- Seul manque le **compteur de codes-barres internes**.

**Ce qu'il faut faire dans PI**
- Entité `code_barre_interne` dans `DEFAUTS` (préfixe, longueur de 8 à 24) et un bouton « Générer un code interne » dans les codes-barres du produit (EAN-13 de préfixe interne avec sa clé, ou CODE128). À livrer avec les codes-barres produits.
- En option : remise à zéro annuelle (`remise_a_zero` : jamais ou annuelle ; variable `{AAAA}` dans le préfixe). fripv2 la fait à la main (son scénario 5).
- **Tests** : unicité et format EAN ; isolation.
- **Taille** : P.

### 24. Statut TFE — **Manquant (mineur, couvert par le § 19)**

fripv2 : une liste fixe de statuts des transferts électroniques (en attente, émis, complété, refusé, annulé). On change leur **libellé** (traduisible) et leur **ordre** (Remonter et Descendre, enregistré tout de suite), sans ajout ni suppression. Droits : Voir et Modifier les statuts des virements.

PI : `virement_lots` (statuts émis et refusé), libellés codés.

À faire : ajouter les lots de virement au catalogue `StatutsDocuments` du § 19 (libellé, couleur, ordre). Taille P, en plus du § 19.

### 25. Déverrouiller — **Partiel**

**Ce que fait fripv2**
- Liste en temps réel des verrous : Type, Identifiant lisible (nom ou numéro), Verrouillé par, **temps restant (compte à rebours à la seconde)**, échéance.
- Libérer un verrou (avec confirmation) et **« Tout déverrouiller »**.
- Fiches verrouillables : clients, fournisseurs, produits, comptes GL, devis, commandes, **factures, bons de livraison**, commandes fournisseur, **encaissements, déboursés, transactions bancaires**…
- Le compte libéré passe en consultation et reçoit une notification. Droit : « Voir et libérer les fiches verrouillées ».

**Ce que PI a aujourd'hui**
- `/fiches-en-cours` (`FichesEnCoursPage`), `VerrouController`, `App\Services\Verrous` (**6 entités**, 180 secondes, renouvelé chaque minute), « Libérer » avec l'action `verrous.liberer`, prise de main, bandeaux.

**Ce qu'il faut faire dans PI**
- `POST /verrous/liberer-tout` (action `liberer` ; confirmation ; sauf ses propres verrous) ; `PARTICULIERES`.
- Échéance et compte à rebours à l'écran ; le temps restant est déjà dans `expire_le`.
- Nouvelles entités dans `Verrous::ENTITES`, avec le middleware `verrou:` sur leur `PUT/DELETE` et `useVerrou` dans leur formulaire : facture (brouillon), bon_livraison, encaissement, debourse, facture_fournisseur, transaction_bancaire, contrat_location, abonnement_client, demande_acompte, ecriture (journal), numerotation.
- **Tests** : tout libérer (ses propres verrous restent) ; 409 sur chaque nouvelle entité ; isolation.
- **Taille** : P, plus P par lot d'entités.

### 26. Logs (historique des modifications) — **Partiel**

**Ce que fait fripv2**
- Journal de **tout** : chaque champ modifié, les cellules des lignes de documents (y compris leurs balises et notes internes), les lignes ajoutées ou supprimées, **les impressions, les connexions et déconnexions**, les **courriels envoyés ou échoués** (sur la fiche d'où ils partent), les actions des **tâches planifiées** et du service, les droits changés, les ajustements de stock et les prix révisés.
- Pour chaque action : poste, version, source, et regroupement par enregistrement.
- **Vue globale** :
  - histogramme d'**activité par jour** (personnes ou automatismes ; un clic filtre la journée) ;
  - filtres rapides avec leur nombre : Source (personnes, tâches, service), Action (modification, ajout, suppression, impression, envoi, connexion, déconnexion), **Seulement les échecs** ;
  - filtres De/Au, usager, entité, recherche ;
  - détail de la ligne « Champ : avant → après », avec les liens « Historique de cette fiche », « Ouvrir la fiche » et « Activité de X ce jour-là ».
- **Fil d'une fiche** (bouton Historique de chaque fiche) : entrées groupées par jour, connexions regroupées, recherche, chargement au défilement.
- Lecture seule, conservation sans limite. Droit : « Voir l'historique des modifications ».

**Ce que PI a aujourd'hui**
- `audit_logs` alimenté par `App\Observers\JournalModifications` : 24 modèles ; création, modification, suppression ; avant/après ; adresse IP et user-agent.
- `AuditLogController` (filtres action, modèle, usager, dates ; statistiques ; historique d'un élément). Écran `/audit` (menu Inventaire → Historique, module `historique`).
- **Aucun contrôle de lecture côté serveur** (T1).

**Ce qu'il faut faire dans PI**
- **Données (additif)** sur `audit_logs` :
  - `source` (personne, tache, systeme, portail), `processus` (code de la tâche), `succes` (bool, vrai par défaut), `operation_id` (uuid d'un même enregistrement), `entite_libelle` (nom ou numéro lisible, pour la recherche) ;
  - index (tenant_id, created_at) et (tenant_id, model_type, model_id).
- **Événements à journaliser** :
  - connexion, déconnexion et échec de connexion (`AuthController`) ;
  - PDF produits (action `impression`, sur le document) ;
  - courriels envoyés ou en échec (job `EnvoyerCourriel`, `succes=false` en cas d'échec) ;
  - exécutions de tâches (`Planificateur`) ;
  - modifications faites par une tâche (`source=tache` quand le contexte vient d'une tâche) ;
  - changements de droits (`RolePermissionController`, `AccesCompteController` : « droit X : avant → après ») ;
  - modèles du T3.
- **API** :
  - filtres `source`, `succes`, `recherche` sur `GET /audit` ;
  - `GET /audit/activite?du=&au=` (comptes par jour, personnes ou automatismes, et par filtre rapide) ;
  - `GET /audit/{type}/{id}` paginé (fil d'une fiche).
- **Écran** : `/audit` refait (histogramme, filtres rapides, panneau de détail). Composant `BoutonHistorique` (fil d'activité) sur les fiches et documents principaux, visible avec `historique.voir`.
- **Droits** : `Droits::LECTURES['audit'] = 'historique'`, avec une migration de droits (voir T1).
- **Tests** : connexion journalisée ; échec d'envoi `succes=false` ; source `tache` ; filtres et comptes par jour ; 403 sans le droit ; isolation.
- **Taille** : G. À découper : serveur M, écran M.

### 27. Envois courriel (historique) — **Partiel**

**Ce que fait fripv2**
- Historique global, ou ciblé sur un type, un document, **un client ou un fournisseur**. Le titre s'adapte et les colonnes redondantes se masquent.
- Statuts : En attente, En cours, Réussi, Erreur, **Annulé**.
- Actions :
  - **Envoyer** : relancer (bloqué si une pièce jointe manque) ;
  - **Modifier** avant de relancer (destinataires, objet, message) ; le bouton devient « Consulter » pour un envoi réussi ;
  - **Annuler** ;
  - **Pause** (un envoi en cours repasse en attente).
- Filtres : type, statut, « erreurs seulement », champ de date (création ou dernier essai) avec bornes ; ouverture du document ou de la fiche depuis la ligne.
- Droits :
  - menu global : « Voir l'historique des modifications » ; depuis un document ou un tiers : le droit de le voir ;
  - changer le statut d'un envoi : « Modifier un modèle de courriel ».

**Ce que PI a aujourd'hui**
- `courriel_envois` (statuts en_attente, en_cours, envoye, echec), `CourrielEnvoiController` (filtres type, document, rapport, statut ; « Réessayer » ; nouvel essai automatique avec `courriels:relancer`), page `/courriels`.
- Manque : annuler, pause, modification avant renvoi, filtres de dates et de tiers, liens vers le document ou la fiche, historique depuis une fiche client ou fournisseur, **contrôle de lecture** (T1).

**Ce qu'il faut faire dans PI**
- **Données (additif)** : statuts `annule` et `en_pause` (ignorés par `courriels:relancer`) ; colonnes `tiers_type` et `tiers_id` (renseignées à la création depuis le document ; index).
- **API** :
  - `POST /courriels/envois/{id}/annuler` (statuts en attente, en pause, échec) ;
  - `POST …/suspendre` et `…/reprendre` ;
  - `PUT /courriels/envois/{id}` (destinataires, copies, objet, texte → rendu de nouveau par `RenduCourriel`, si le statut n'est pas « envoyé ») ;
  - filtres `du`, `au`, `champ_date`, `tiers_type`, `tiers_id`, `erreurs_seulement`.
- **Droits** :
  - nouveau module `courriels_envoyes` (crud `['voir']`, action `gerer` : annuler, suspendre, modifier ; réessayer depuis le document reste libre) ;
  - le contrôleur exige `courriels_envoyes.voir` pour la liste globale, et le droit de voir le document ou le tiers pour une liste filtrée ;
  - par défaut, réglé comme `configuration`.
- **Écran** : filtres, liens, actions selon le statut ; onglet « Courriels » dans les fiches client et fournisseur.
- **Tests** : transitions de statut permises et refusées ; modification puis renvoi ; un envoi annulé n'est pas relancé ; droits ; isolation.
- **Taille** : M.

### 28. Délais d'expiration des verrous — **Manquant**

**Ce que fait fripv2**
- Un délai d'**inactivité** en minutes, **par type de fiche** (client, fournisseur, produit, facture, commande, devis, bon de livraison, commande fournisseur, compte GL, numérotation, transactions, réceptions, demandes d'acompte, cartes cadeaux, encaissements, déboursés…).
- Unique par type, 5 minutes au minimum, effet immédiat, y compris sur les verrous déjà posés. L'activité de l'usager (saisie, clic) repousse le délai.
- **30 secondes avant l'échéance : « Travaillez-vous toujours sur cette fiche ? »** (Oui prolonge). Sans réponse, l'écran passe en consultation, avec une notification, et l'enregistrement est refusé.
- Droits : Voir, Ajouter, Modifier, Supprimer un délai.

**Ce que PI a aujourd'hui**
- `Verrous::DUREE_SECONDES = 180`. `hooks/useVerrou.ts` renouvelle **chaque minute tant que la fenêtre est ouverte, quelle que soit l'activité** : un formulaire oublié garde la fiche indéfiniment.

**Ce qu'il faut faire dans PI**
- **Données** :
  - table `verrous_delais` (tenant_id, entite, delai_minutes de 5 à 1 440 ; unique sur tenant et entité) ;
  - colonne `verrous.derniere_activite_le`.
- **Règles** :
  - `POST /verrous` accepte `activite` (bool) ;
  - si un délai est réglé et que `now − derniere_activite_le ≥ délai`, le renouvellement est refusé (409, `motif: inactivite`, verrou expiré) ;
  - la réponse renvoie `inactivite_expire_le` ;
  - **sans délai, le comportement est inchangé.**
- **Écran** :
  - `useVerrou` écoute les saisies et clics du formulaire et envoie `activite: true` ;
  - fenêtre « Travaillez-vous toujours sur cette fiche ? » 30 secondes avant l'échéance, puis le bandeau rouge actuel ;
  - onglet « Délais d'inactivité » dans `/fiches-en-cours` (une entité, des minutes ; vide = pas de limite).
- **API** : `GET /verrous/delais`, `PUT` et `DELETE /verrous/delais/{entite}`.
- **Droits** : action `verrous.delais` (base modifier) ; `PARTICULIERES`.
- **Tests** : sans délai, rien ne change ; délai de 5 minutes sans activité → 409 et fiche libre pour un autre compte ; avec activité → verrou gardé ; minimum de 5 ; isolation.
- **Taille** : P à M.

### 29. Gestion des rapports › Rapports (catalogue) — **Hors périmètre**

fripv2 tient un catalogue « description + nom du fichier de gabarit » (40 et 30 caractères, traduisible), qui sert à associer une imprimante à un rapport. Droit : « Modifier les gabarits d'impression ». Il ne produit aucun rapport.

C'est propre aux gabarits installés sur le poste et aux imprimantes. La bibliothèque de rapports de PI, elle, est en place. Un équivalent SaaS serait facultatif : choisir parmi des gabarits PDF fournis par type de document (standard, compact…).

### 30. Rapport IA — **Partiel**

**Ce que fait fripv2**
- Une demande en langage naturel, puis un **rapport composé** : logo, bandeau titre et date, ligne de périmètre (période, documents, montants avec ou sans taxes), **cartes de chiffres clés**, graphique en barres au besoin, tableau.
  - Un tableau de plus de 7 colonnes passe en paysage. L'aperçu se limite à 100 lignes, mais les totaux portent sur tout.
- Sorties : imprimer, **PDF**, **Excel (toutes les lignes)**, **courriel**.
- **Enregistrer** en privé ou **partagé** : un rapport partagé est en lecture seule pour les collègues, et n'est visible que par qui a accès à toutes ses données.
- « Mes rapports sauvegardés » : les 100 plus récents. **Actualiser** relance la même recherche, avec des **périodes relatives** conservées (« le mois en cours » reste le mois en cours).
- **Planifier** (voir § 31). Lecture seule ; respect des droits. Droit : « Créer un rapport IA ».
- fripv2 fait écrire du **SQL** à l'IA (`rapport_ia.requete_sql`).

**Ce que PI a aujourd'hui**
- Onglet « Questions à l'IA » de `/bibliotheque-rapports` (`RapportIaController`, `App\Rapports\AssistantRapports`, table `questions_ia`).
- Une réponse en texte qui s'appuie sur les rapports de la bibliothèque (**sans SQL**, choix de sécurité à garder), avec un lien vers le rapport cité, et l'historique des 30 dernières questions.
- Manque : rapport composé, PDF et Excel de la réponse, enregistrement et partage, actualisation, planification.

**Ce qu'il faut faire dans PI**
- **Données** : table `rapports_ia` : tenant_id, admin_user_id, titre (200), question, langue, plan (json : rapports de la bibliothèque, paramètres, période relative), presentation (json : indicateurs, graphique, colonnes), resultat (json : 100 lignes et totaux), partage, modele, jetons, softDeletes.
- **IA** : nouvel outil `composer_rapport` dans `AssistantRapports`, qui renvoie la présentation structurée. Les données restent celles des rapports de la bibliothèque, exécutés avec les droits du compte.
- **Rendu** : vue `pdf/rapport-ia.blade.php` (logo, bandeau, cartes, graphique SVG simple, tableau ; paysage au-delà de 7 colonnes) ; Excel complet par `Production`.
- **API** : `POST /rapports/ia/rapports` (depuis une question : titre, partage), `GET` (les miens et ceux partagés qui me sont permis), `GET /{id}`, `POST /{id}/actualiser` (enregistré seulement si j'en suis l'auteur), `GET /{id}/export?format=pdf|xlsx`, `POST /{id}/courriel`, `DELETE`.
- **Droits** : `rapports.questionner` (existant) pour créer ; un rapport partagé n'est visible que si `Catalogue::pour` permet chacun de ses rapports au compte.
- **Écran** : sous-onglet « Rapports enregistrés », avec les boutons Enregistrer, Actualiser, PDF, Excel, Courriel et Planifier.
- **Tests** (IA simulée) : enregistrer, actualiser avec une période relative, exporter, envoyer ; visibilité d'un rapport partagé selon les droits ; isolation.
- **Taille** : G.

### 31. Rapports IA périodiques — **Partiel**

**Ce que fait fripv2**
- Planification d'un **rapport IA enregistré** :
  - titre (sert d'objet ; défaut = titre du rapport), actif ;
  - quotidien, hebdomadaire (**plusieurs jours**) ou mensuel (1 à 31) ;
  - **heure** (7 h par défaut) ;
  - destinataires en étiquettes, avec suggestions parmi les usagers ; **vides = l'adresse du propriétaire** ;
  - **formats combinables** : corps HTML, PDF joint, Excel joint (sans corps : « Veuillez trouver ci-joint votre rapport. »).
- Prochain et dernier envoi ; **dernier statut** : « Envoyé le … à N destinataire(s) », « Aucun destinataire », « Rapport source introuvable », « Erreur : … ». Pas de rattrapage après une suspension. Chacun ne voit que ses planifications.
- Droit : « Planifier l'envoi de rapports IA ».

**Ce que PI a aujourd'hui**
- `rapports_programmes` (code, paramètres, période relative, **un seul** format, langue, jusqu'à 20 destinataires, fréquence, `jour`, `question_ia`, `derniere_erreur`).
- Tâche `rapports_programmes` (quotidienne, 06 h 30) ; analyse de l'IA insérée dans le courriel ; action `rapports.programmer`.

**Ce qu'il faut faire dans PI**
- **Données (additif)** sur `rapports_programmes` : `rapport_ia_id` (nullable ; la source est alors le rapport IA enregistré, avec `code` à null), `formats` (json ; repli sur `format`), `jours_semaine` (json), `heure` (time), `dernier_statut` (255).
- **Règles** :
  - destinataires vides → adresse du responsable ;
  - corps HTML rendu (indicateurs et 100 lignes) ;
  - si le PDF échoue et que le corps n'était pas demandé, le rapport passe dans le corps.
- **Tâche** : avec une `heure`, il faut une exécution horaire. Garder la récurrence par défaut (aucun changement pour les entreprises) ; l'écran propose de passer la tâche en horaire dès qu'une planification a une heure.
- **Écran** : `FenetreProgramme` (source : bibliothèque ou rapport IA ; formats multiples ; jours multiples ; heure) et colonne « Dernier statut ».
- **Tests** : formats combinés ; destinataire par défaut ; source supprimée → « Rapport source introuvable » ; plusieurs jours par semaine ; isolation.
- **Taille** : M.
- **Dépendance** : § 30.

---

## 2. Bouton Usagers

### 32. Usagers — **Partiel**

**Ce que fait fripv2**
- Liste : Nom, Login, **Groupes** (un par ligne), n° de tablette, version Android, n° d'employé, Actif (le double-clic bascule). Recherche sur le nom, le login, les groupes et le n° d'employé ; filtre Actif ou Inactif.
- Fiche :
  - Nom (100), Courriel (255) ;
  - **N° d'employé** (10, unique ; c'est aussi le **numéro de commis** demandé à l'ouverture d'un document) ;
  - Groupes (plusieurs) ;
  - **Langue du logiciel** et **langue des données** ;
  - Login (100, majuscules, unique), mot de passe, n° de tablette ;
  - Actif ; Administrateur (on ne peut pas se le retirer) ; **Commis obligatoire** ;
  - **Signature de courriel** riche, avec les variables de l'entreprise.
- Onglet Permissions : trois états (hérité, accordé, refusé), case « Tous » par module, « Donner tous les accès » ou « selon le groupe », « Retirer tous » ou « selon le groupe ».
- Droits : Voir, Créer, Modifier ou désactiver, Supprimer un usager, **Modifier les permissions d'un usager** (jamais sur sa propre fiche, sauf administrateur).

**Ce que PI a aujourd'hui**
- Comptes créés depuis **Employés** (« Accès à l'application » : rôle de base, rôle personnalisé, mot de passe ; un manager n'attribue que les rôles user et readonly).
- `/acces/:compteId` (trois états, groupes supplémentaires, « Tout accorder »), `/users` (`AdminUserController`, `role:admin`), `admin_users` (nom, email, role, role_custom_id, actif).
- Manque :
  - **signature par usager** (une seule signature, dans `courriel_parametres`) ;
  - **langue de l'usager** enregistrée (aujourd'hui seulement dans le navigateur, `prise_language`) ;
  - numéro de commis ;
  - colonne et recherche par groupe ;
  - délégation des accès (`role:admin`).

**Ce qu'il faut faire dans PI**
- **Données (additif)** : `admin_users.signature_courriel` (texte en Markdown léger, variables de l'entreprise), `admin_users.langue` (fr ou en).
- **Règles** :
  - `RenduCourriel` utilise la signature de l'auteur de l'envoi, sinon la signature globale (comportement inchangé) ;
  - la langue est envoyée par `/auth/me` et appliquée à la connexion ; le sélecteur l'enregistre (`PUT /profile`).
- **Écran** :
  - Profil : signature (éditeur avec variables) et langue ;
  - Employés : colonne « Groupes », filtre `?groupe_id=`, dernière connexion, état de la double authentification.
- **Commis obligatoire** : facultatif ; utile pour un compte partagé entre plusieurs vendeurs, à rapprocher des représentants.
- **Droits** : délégation au § 33. La signature et la langue de son propre profil sont libres.
- **Tests** : la signature de l'usager prime sur la signature globale ; persistance de la langue ; filtre par groupe ; isolation.
- **Taille** : P à M.

### 33. Groupes — **Partiel**

**Ce que fait fripv2**
- Groupes : description traduisible, actif (le double-clic bascule, avec confirmation), suppression refusée s'ils sont utilisés (« il est lié à des usagers »), permissions du groupe par le bouton « Accès ». Les droits des groupes s'additionnent.
- Droits : Créer, Modifier, Supprimer un groupe ; **Modifier les permissions d'un groupe**. Ils sont délégables à un non-administrateur.

**Ce que PI a aujourd'hui**
- `/roles` (`RolesPage`) : `roles_custom` (actif, suppression refusée si utilisé, duplication des rôles système), `role_permissions`, `role_actions`, `admin_user_groupes`, `admin_user_permissions`, catalogue avec aide et recherche.
- Tout est **réservé par `role:admin`**. Ni traduction du nom, ni liste des membres.

**Ce qu'il faut faire dans PI**
- **Droits** :
  - actions du module `roles` : `groupes` (créer, modifier, désactiver et supprimer des groupes et régler leurs permissions ; base `voir`) et `acces` (groupes supplémentaires et exceptions d'un compte) ;
  - par défaut, administrateur seulement ;
  - `role:admin` remplacé par le middleware `droits` sur `/roles-custom*`, `/acces-comptes/*` et `/users/{id}/assign-role` (`PARTICULIERES`).
- **Garde-fous contre l'élévation de droits** (indispensables) :
  - un non-administrateur ne peut accorder **que des permissions qu'il a lui-même** ;
  - il ne peut ni modifier **ses propres accès** ni ses groupes ;
  - il ne peut pas toucher un compte ou un groupe administrateur ;
  - le refus l'emporte toujours.
- **API** : `GET /roles-custom/{id}/membres` ; `POST /roles-custom/{id}/dupliquer` (aussi pour les groupes de l'entreprise).
- **Traductions** : nom du groupe (T2).
- **Tests** : délégation accordée → l'action est permise ; tentative d'accorder un droit non détenu → 403 ; ses propres accès → 403 ; compte administrateur intouchable ; isolation (groupes de l'entreprise seulement).
- **Taille** : M.

### 34. Gestion des licences — **Hors périmètre**

fripv2 montre l'état de la licence du poste : validité, expiration, postes actifs sur le maximum, modules, paiement, période de grâce, « Rafraîchir maintenant ».

En SaaS, ce sont les entreprises, leurs modules, leur plan et `date_expiration`, gérés par le super-administrateur.

Facultatif : une page « Mon abonnement » en lecture seule pour l'administrateur de l'entreprise (plan, modules actifs, échéance, nombre de comptes).

### 35. Tablettes — **Manquant**

**Ce que fait fripv2**
- Comptes d'**appareils mobiles** : identifiant (100, majuscules, unique), mot de passe (obligatoire à la création ; vide en modification = conservé), active.
- La liste se filtre Actif ou Inactif. Une tablette perdue se désactive en un double-clic et ne peut plus se connecter.
- Suppression refusée si la tablette a des scans de prise d'inventaire ou des boîtes de bons de livraison.
- **Traçabilité** : quelle tablette a fait chaque scan ou chaque boîte. La version Android est affichée dans la liste des usagers.
- Droits : Voir, Ajouter, Modifier ou désactiver, Supprimer.

**Ce que PI a aujourd'hui**
- L'application Android se connecte avec un compte utilisateur (jeton Sanctum de 7 jours ; routes `/api/mobile/*`). Aucun registre d'appareils, aucune révocation par appareil, aucune trace de l'appareil.

**Ce qu'il faut faire dans PI** (« Appareils mobiles »)
- **Données** :
  - table `appareils` : tenant_id, uuid (généré par l'application), nom (100), plateforme, modele (100), os_version (30), app_version (30), admin_user_id (dernier compte), actif, derniere_connexion_le, softDeletes ; unique sur tenant et uuid ;
  - colonne nullable `personal_access_tokens.appareil_id` ;
  - colonne nullable `appareil_id` sur `inventaire_scan`, sur les mouvements faits depuis le mobile, et sur les colis, chargements et signatures saisis sur mobile.
- **Règles** :
  - la connexion mobile (en-têtes `X-Appareil-Id`, `X-App-Version`, `X-OS-Version`, `X-Appareil-Modele`) crée ou met à jour l'appareil ;
  - un appareil désactivé est refusé (403 « Cet appareil a été désactivé ») et ses jetons sont révoqués ;
  - les scans enregistrent `appareil_id`.
- **API** : `GET /appareils` (filtre actif), `PUT /appareils/{id}` (nom, actif), `DELETE` (refusé s'il est utilisé).
- **Écran** : Paramètres → **Appareils mobiles** (`/appareils`) ; colonne « Appareil » dans l'historique des scans.
- **Application Android** : envoyer les en-têtes et gérer le 403.
- **Droits** : module `appareils` (crud), réglé comme `employes`.
- **Tests** : enregistrement à la connexion ; appareil désactivé → jetons refusés ; scan tracé ; suppression refusée s'il est utilisé ; le même uuid dans deux entreprises donne deux appareils ; isolation.
- **Taille** : M (API et web), plus P pour Android.

### 36. Modifier mon mot de passe — **Partiel (mineur)**

**Ce que fait fripv2**
- Chaque employé change son propre mot de passe sans aucun droit (mot de passe actuel exigé). Un administrateur définit un mot de passe temporaire depuis la fiche.
- **Politique** : 10 caractères au moins, une minuscule, une majuscule, un chiffre et un caractère spécial. Indicateur de force en 5 niveaux (Très faible à Excellent), liste de critères cochés en direct, confirmation qui doit correspondre ; Enregistrer reste grisé tant que ce n'est pas conforme.
- Changement journalisé, sans la valeur.

**Ce que PI a aujourd'hui**
- `/securite` (`SecuriteComptePage`), `PUT /auth/mot-de-passe` (mot de passe actuel exigé, **8 caractères au moins**, différent de l'actuel ; ferme les autres sessions), mot de passe oublié, double authentification. PI fait déjà plus que fripv2 sur ce point.
- Manque : une politique de complexité réglable, l'indicateur de force, l'obligation de changer un mot de passe temporaire.

**Ce qu'il faut faire dans PI**
- **Données** :
  - table `securite_parametres` (tenant_id unique ; mdp_longueur de 8 à 64, défaut 8 ; mdp_majuscule, mdp_minuscule, mdp_chiffre, mdp_special, défaut faux) ;
  - colonne `admin_users.mot_de_passe_temporaire` (bool).
- **Règles** :
  - une règle `MotDePasseConforme` appliquée partout où un mot de passe est défini (changement, réinitialisation, `/users`, Employés, administrateurs d'entreprise créés par le super-administrateur) ;
  - un mot de passe défini par un administrateur pour un autre compte marque le compte « temporaire ». À la connexion, `/auth/me` le signale et l'écran impose le changement.
- **Écran** : indicateur de force et critères (Sécurité du compte, Réinitialiser, Employés) ; réglage de la politique dans la section administrateur de `/securite`.
- **Droits** : réglage de la politique réservé à l'administrateur (comme le reste de la sécurité), ou action `configuration.securite`.
- **Tests** : politique appliquée sur chaque route ; changement imposé ; isolation des réglages.
- **Taille** : P.

---

## Annexe — Transporteurs : « Livraison facturée » (résumé ; fiche complète : [02-base.md](02-base.md), option 21)

**Ce que fait fripv2**
- Un transporteur a une description traduisible et une case **« Frais de livraison applicables »**, affichée dans la colonne « Livraison facturée ».
  - Décochée, les frais de livraison de la **fiche client ne s'ajoutent plus** aux devis, commandes et factures des clients livrés par ce transporteur (par exemple une flotte interne). Les documents existants ne changent pas.
- Champ « **Frais de livraison commande fournisseur** » : sur le compte à payer d'un fournisseur livré par ce transporteur, la ligne de transport est ajoutée d'office (montant du frais, taxes calculées, compte de charge du département du produit).
- Le transporteur se choisit sur les fiches clients et fournisseurs (transporteur par défaut) et sur les devis, commandes, bons de livraison, factures et commandes fournisseur.
- Suppression refusée s'il est utilisé ; pas d'état actif.

**Ce que PI a aujourd'hui**
- Le référentiel `transporteurs` (avec `url_suivi`) n'est porté par aucun document. Les frais de livraison se choisissent sur la fiche client (`clients.frais_livraison_id`, `FraisDocuments`).

**À prévoir**
- `transporteurs.livraison_facturee` (bool, défaut vrai) et `transporteurs.frais_achat_id` ;
- `transporteur_id` sur les clients, les fournisseurs et les documents ;
- `FraisDocuments` qui saute les frais de livraison si le transporteur du document a `livraison_facturee = false`.

---

## Ordre de réalisation conseillé

1. Les correctifs de droits (T1) et le contrôle des suppressions (T5).
2. Traductions (T2) et langue des tiers (T4).
3. Configuration système (logo), en-têtes courriel, puis notes de bas de page.
4. Conditions et modes de paiement, statuts (personnalisés puis système).
5. Pièces jointes et sections, balises et champs personnalisés.
6. Logs, envois courriel, verrous.
7. Délégation des usagers et groupes, appareils mobiles.
8. Rapports IA, connecteurs.

---

## Tableau récapitulatif

| # | Option | Statut | Preuve dans PI | Taille |
|---|---|---|---|---|
| 1 | Configuration système | Partiel | `tenant_parametres`, `GET/PUT /api/parametres`, `/configuration` (onglet Entreprise), `EntreprisePdf` sans logo | G |
| 2 | Configuration à compléter | Partiel | `App\Support\ConfigurationACompleter` (4 étapes, 13 réglages), `/configuration-a-completer` | M |
| 3 | Balises | Partiel | `BaliseController`, `/balises`, 9 types de fiches ; pas de lignes ni de filtre de rapport | M + M |
| 4 | Catégories de contact | Manquant | `ClientFicheContactController` (champ `fonction` libre seulement) | P |
| 5 | Champs personnalisés | Partiel | `ChampPersonnaliseController`, `/champs-personnalises` ; fenêtre séparée, pas de report ni d'impression | G (lots) |
| 6 | En-têtes courriel | Partiel | `App\Support\Courriels` (12 types), onglet Courriels de `/configuration` | M |
| 7 | Imprimantes | Hors périmètre | — (impression PDF par le navigateur) | — |
| 8 | Notes de bas de page | Manquant | aucun (`pdf/document-vente.blade.php` : notes du document seulement) | M à G |
| 9 | Remarques rapides | Partiel | `RemarqueRapideController` (sans droits), `BoutonRemarquesRapides` sur 5 notes d'en-tête | P |
| 10 | Sections de documents | Partiel | `pieces_jointes.categorie` en texte libre | P |
| 11 | Documents / Attachements | Partiel | `App\Support\PiecesJointes` (13 entités), `/pieces-jointes` ; pas de droits, ni pastille, ni non-lus | M |
| 12 | Type d'imprimante | Hors périmètre | — | — |
| 13 | Tâches planifiées | Partiel | `App\Support\TachesPlanifiees` (18 tâches), `/taches-planifiees` (`role:admin`) | M |
| 14 | Fournisseurs API | Manquant | relevés manuels et import CSV (`abonnement_releves`) seulement | G |
| 15 | Conditions de paiement | Partiel | référentiel `conditions_paiement`, `ConditionsPaiement` ; pas de jour du mois suivant ni de lien fournisseur ou devis | M |
| 16 | Modes de paiement | Partiel | référentiel `modes_paiement` inutilisé ; `Encaissement::MODES`… en dur | M |
| 17 | Classes de compte | Fait (équivalent) | `comptes_comptables.nature`, `type`, `saisie_manuelle` | — |
| 18 | Types de location | Partiel | référentiel `types_location` (mois seulement, jour 1 à 28) | M |
| 19 | Statuts transactions | Manquant | statuts en constantes (`Devis::STATUT_*`…), couleurs dans les pages | M |
| 20 | Statuts personnalisés | Partiel | référentiel `statuts_personnalises`, porté par aucun document | M |
| 21 | Raisons de mouvement | Partiel | `raisons` (mouvement, ajustement) inutilisées ; `mouvements_inventaire.motif` libre | P à M |
| 22 | Raisons de retour | Fait | `raisons` retour client et fournisseur sur les lignes de retour | — |
| 23 | Numérotation | Partiel (mineur) | `configurations`, `Configuration::DEFAUTS` (24 entités) ; pas de code-barres interne | P |
| 24 | Statut TFE | Manquant (mineur) | `VirementLot` (émis, refusé), libellés en dur | P (avec 19) |
| 25 | Déverrouiller | Partiel | `/fiches-en-cours`, `App\Services\Verrous` (6 entités) | P |
| 26 | Logs | Partiel | `/audit`, `JournalModifications` (24 modèles), lecture non contrôlée | G |
| 27 | Envois courriel | Partiel | `/courriels`, `CourrielEnvoiController` (pas d'annulation, de modification ni de filtre de dates) | M |
| 28 | Délais d'expiration verrous | Manquant | `Verrous::DUREE_SECONDES = 180`, `useVerrou` sans notion d'activité | P à M |
| 29 | Rapports (catalogue de gabarits) | Hors périmètre | — (`/bibliotheque-rapports` couvre les rapports) | — |
| 30 | Rapport IA | Partiel | onglet Questions à l'IA, `RapportIaController`, `questions_ia` | G |
| 31 | Rapports IA périodiques | Partiel | `rapports_programmes.question_ia`, tâche `rapports_programmes` | M |
| 32 | Usagers | Partiel | Employés (accès), `/acces/:id`, `/users` (`role:admin`) ; pas de signature ni de langue par usager | P à M |
| 33 | Groupes | Partiel | `/roles`, `roles_custom` (`role:admin`, pas de délégation) | M |
| 34 | Gestion des licences | Hors périmètre | `tenants.plan`, modules, `date_expiration` (super-administrateur) | — |
| 35 | Tablettes | Manquant | aucun registre d'appareils (jetons Sanctum par compte) | M + P |
| 36 | Modifier mon mot de passe | Partiel (mineur) | `/securite`, `PUT /auth/mot-de-passe` (8 caractères, pas de politique) | P |
