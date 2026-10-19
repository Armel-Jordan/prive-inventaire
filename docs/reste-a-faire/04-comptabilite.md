# Onglet « Comptabilité » de fripv2 : ce qui manque encore dans Prise Inventaire

> Partie de [la liste du reste à faire](../17-RESTE-A-FAIRE.md), état au 19 octobre 2026 (après la PR #131).
> Pour chaque écran : **ce que fait fripv2** (le comportement de référence), **ce que PI a aujourd'hui**, puis
> **ce qu'il faut faire** (taille **P** petite, **M** moyenne, **G** grande).

**Statuts :** **Fait** (équivalent, seuls des écarts mineurs restent) · **Partiel** · **Manquant** · **Hors périmètre**.

**Conventions appliquées à toutes les propositions :**
- migrations additives seulement ;
- activation par module du tenant (`module:finance|achats|ventes`) ou par paramètre ;
- aucun format, numéro ou taxe propre au Québec ;
- `tenant_id` et `BelongsToTenant` sur toute nouvelle table, **tables filles comprises** ;
- droits déclarés dans `App\Support\Permissions` (module, `crud`, actions nommées) et câblés dans `App\Support\Droits` (`MODULES`, `LECTURES`, `PARTICULIERES`, `ETATS_COMPTABLES`, `actionComptable()`), avec une migration rejouable qui recopie aux groupes existants les droits d'un module voisin, comme `2026_10_17_100060` ;
- messages en `__()` avec leur anglais dans `lang/en.json` (le test `tests/Unit/TraductionsAnglaisesTest` le vérifie) ;
- textes d'écran FR/EN dans `src/i18n/translations.ts`, menu dans `src/navigation.ts` ;
- tests fonctionnels, de droits (refus 403 avec le message nommé) et d'isolation entre deux tenants.

---

## 1. Verdict sur la phase 4 (« considérée complète »)

**Ce qui est solide.** Le socle est en place :
- plan comptable, exercices, clôture et réouverture ;
- journal en partie double, journaux, contre-passation ;
- écritures récurrentes (grand livre seulement) ;
- comptabilisation automatique différée ;
- banque : transactions, rapprochement, relevés OFX/CAMT/CSV ;
- budgets, balance, grand livre, bilan, résultat, rapport des taxes ;
- encaissements, déboursés, chèques, virements SEPA/CSV, paiements dus ;
- acomptes, demandes d'acompte, cartes cadeaux, caisses.

Sur plusieurs points PI fait mieux que fripv2 : journaux, réouverture d'exercice, budget comparé au réel, rapprochement au solde du relevé, contrôle des IBAN.

**La phase n'est pourtant pas complète face à fripv2 :**
1. **Pas de compte à recevoir ni de compte à payer « comptable »** ventilé par compte du grand livre :
   - une ligne de facture client exige un produit (`facture_lignes.produit_id NOT NULL`) ;
   - une ligne de facture fournisseur n'a pas de compte de charge (`facture_fournisseur_lignes`) ;
   - pas de note de crédit fournisseur (`quantite gt:0`, `prix_unitaire min:0`), pas de renversement.
2. **Récurrences** limitées au grand livre. fripv2 fait aussi les comptes à recevoir, les comptes à payer et la banque, avec correction occurrence par occurrence.
3. **Comptabilisation incomplète** (`Comptabilisation::TYPES`). Elle ne passe pas :
   - le stock ni le coût des ventes ;
   - la vente, la recharge et le remboursement des cartes cadeaux ;
   - les avoirs fournisseurs ;
   - les écarts de change (un encaissement en devise est passé au taux de la facture) ;
   - l'arrondi de caisse et les frais d'achat.
4. **Transactions bancaires** : sans tiers, sans taxes, non modifiables, non récurrentes. Pas de connexion bancaire directe. « Classer » ne crée qu'une transaction bancaire.
5. **Périodes** : aucun contrôle de période sur les documents, pas de période courante, pas de droit « saisir en période fermée ».
6. **États** : pas d'analytique (balises) sur les lignes ni dans les états ; pas de PDF, XLSX ni courriel ; pas de comparatif avec l'exercice précédent ; une seule profondeur de hiérarchie.
7. **Tiers** : pas de relevé fournisseur, pas d'analyse chronologique des comptes à payer, pas de comptes par défaut par client ou fournisseur, pas de prélèvement client.
8. **Frais d'achat** absents.
9. **Hygiène des données** :
   - tables filles sans `tenant_id` : `recurrence_echeances`, `facture_fournisseur_lignes`, `transaction_bancaire_lignes`, `encaissement_lignes`, `debourse_lignes`, `releve_bancaire_liens`, `frais_produits` ;
   - objets comptables absents du journal des modifications (`JournalModifications::MODELES`) et des verrous (`Verrous::ENTITES`).

---

## 2. Socle transverse (réutilisé par plusieurs options)

**T1 — Période courante et saisie en période fermée (M)**

*Ce que fait fripv2*
- Une période peut être cochée « Période courante » ; une seule à la fois.
- Une tâche quotidienne coche la période qui contient la date du jour et le note dans l'historique. Si aucune période ne couvre cette date, rien ne change et c'est signalé.
- Un employé ne peut enregistrer une transaction que dans la période courante. Un administrateur peut en sortir après confirmation. Cela vaut pour tout document comptable : factures, comptes à payer et à recevoir, encaissements, déboursés, réceptions, acomptes, écritures.
- Une date future demande confirmation à tout le monde.
- Rien ne se crée, ne se modifie ni ne se supprime dans une période fermée ou un exercice clôturé, sauf avec la permission « Saisir ou modifier en période fermée ».

*Ce que PI fait aujourd'hui*
- La période « En cours » est calculée d'après la date du jour.
- Une période fermée bloque seulement les écritures (`Ecritures`). Les documents ne sont jamais bloqués : FLOW-COMPTABILITE §4 dit « une période fermée ne bloque jamais une facture ». Le refus n'apparaît qu'à la comptabilisation.

*Ce qu'il faut faire*
- **Données**
  - `parametres_comptables.controle_periodes` : `aucun` (défaut, comportement actuel) | `fermees` | `courante` ;
  - `periodes_comptables.courante` (booléen).
- **Service `App\Services\Comptabilite\ControlePeriodes::verifier(date, compte, confirmerHorsPeriode)`**
  - Appelé à la création, à la modification et à l'annulation dans `FacturesVente` (émission), `NotesCredit`, `Encaissements`, `Debourses`, `FacturesFournisseurs`, `TransactionsBancaires`, `Acomptes`, `CartesCadeaux` et `ReceptionsFournisseur`.
  - Refus en 422 : « La période comptable du :date est fermée. » / « Hors de la période courante : seul un administrateur peut enregistrer à cette date. »
  - Paramètre `confirmer_hors_periode=1` côté administrateur.
- **Droit et routes**
  - Action nommée `comptabilite.periode_fermee` (« Saisir ou modifier en période fermée », base `supprimer`), lue par `Ecritures` et par `ControlePeriodes`.
  - `PUT /comptabilite/periodes/{id}/courante`. Dans `Droits::actionComptable()`, ajouter une règle `/periodes/` vers `plan` : sinon cette route tombe sur `ecrire`.
- **Tâche** `periode_courante` (quotidienne, 00:10) dans `App\Support\TachesPlanifiees`.
- **Écran** : sur `ExercicesComptablesPage`, une case « Période courante » et le réglage dans la page Comptabilisation. Côté web, un avertissement en cas de date future.
- **Tests** : chaque document refusé et le passage avec le droit ; isolation entre tenants.
- **Piège** : garder `aucun` par défaut pour ne rien changer aux entreprises existantes.

**T2 — Mode de taxes par compte et comptes par défaut des tiers (M)**

*Ce que fait fripv2*
- `compte_gl.calcul_auto_taxes` : *Pas de calcul*, *Calculer les taxes* (taxes en sus) ou *Taxes incluses* (taxes extraites du montant saisi).
- Il s'applique aux lignes ventilées des comptes à recevoir, des comptes à payer et des transactions bancaires, avec le groupe de taxes du tiers.
- Une case « Non taxable » par ligne exclut la ligne du calcul (exemple : transport facturé par un transporteur américain). La case n'est proposée que si le compte est en « Calculer ».
- `client_gl_revenu` (comptes de revenus du client) et `fournisseur_gl_depense` (comptes de dépense du fournisseur) pré-remplissent les lignes. Les comptes de taxes du groupe du tiers sont ajoutés automatiquement.

*Ce que PI fait aujourd'hui* : rien de cela. Les taxes ne se calculent que sur les lignes de documents produits (`TaxesDocument`).

*Ce qu'il faut faire*
- **Migration**
  - `comptes_comptables.mode_taxes` (`aucun|en_sus|incluses`) et `groupe_taxe_id` (nullable) ;
  - `clients.groupe_taxe_id` et `fournisseurs.groupe_taxe_id` (nullable) ;
  - tables `client_comptes_produits` et `fournisseur_comptes_charges` : `tenant_id`, tiers, `compte_comptable_id`, `ordre`, unicité (tiers, compte).
- **Service `TaxesLignesComptables`** : à partir de lignes `{compte_id, montant, non_taxable}`, du groupe et du sens (vente ou achat), il rend les lignes de taxes. Les comptes de taxes viennent de `parametres_comptables.correspondances.taxes`.
- **Écrans** : onglet « Comptabilité » des fiches client et fournisseur ; champs du compte dans `PlanComptablePage`.
- **Piège** : `RapportTaxes` doit lire ces nouvelles sources (lignes comptables de factures, factures fournisseurs ventilées, transactions bancaires taxées), sinon le rapport sous-estime les taxes.

**T3 — Balises analytiques sur les lignes comptables (G)**

*Ce que fait fripv2*
- Chaque ligne d'écriture, de compte à recevoir, de compte à payer, de transaction bancaire ou de récurrence porte des balises (axes : projet, succursale…). Une pastille compte les balises de la ligne.
- Elles restent modifiables à tout moment, même sur une écriture rapprochée, générée ou annulée. La modification est enregistrée aussitôt et tracée.
- Les balises par défaut du client ou du fournisseur sont posées sur les lignes. À la conversion, les récurrences les reportent.
- L'état des résultats se filtre par balises : seules comptent les écritures qui portent au moins une des balises choisies (les soldes de départ sont alors ignorés).

*Ce que PI fait aujourd'hui* : des balises sur les fiches seulement (`Fiches::ENTITES`, `balise_affectations`).

*Ce qu'il faut faire*
- **Table** `ecriture_ligne_balises` : `tenant_id`, `ecriture_ligne_id`, `balise_item_id`, unicité.
- **Propagation** : tables jumelles pour les lignes de facture fournisseur, de facture comptable (voir « Gestion comptes à recevoir ») et de transaction bancaire. La comptabilisation recopie leurs balises sur les lignes d'écriture.
- **Route** `PUT /comptabilite/ecritures/{id}/lignes/{ligne}/balises`, droit `comptabilite.ecrire`, autorisée même si la ligne est pointée ou générée.
- **Filtre** `balises[]` sur grand livre, balance et résultat.
- **Piège** : le filtre se fait par jointure sur les lignes et ne doit jamais inclure l'écriture de clôture.

**T4 — Traçabilité : historique, verrous, pièces jointes, lien vers l'écriture (M)**

*Ce que fait fripv2*
- Historique champ par champ, ligne par ligne, sur comptes, écritures, exercices, périodes, comptes à payer et à recevoir, encaissements, déboursés, transactions bancaires.
- Verrou d'édition partagé : une écriture ne se modifie pas à la fois depuis le journal et depuis la transaction bancaire. Une conciliation en cours verrouille la fiche du compte.
- Pièces jointes sur l'écriture, même générée.
- Bouton « Écritures » sur chaque document.

*Ce que PI fait aujourd'hui*
- `JournalModifications::MODELES` couvre `Encaissement`, `Debourse`, `TransactionBancaire`, `BudgetComptable`, `Acompte`, `Facture`… mais **pas** `CompteComptable`, `EcritureComptable` (ni ses lignes), `ExerciceComptable`, `PeriodeComptable`, `RecurrenceComptable`, `FactureFournisseur`, `CarteCadeau`, `ParametreComptable`, `RapprochementBancaire`.
- `Verrous::ENTITES` ne contient aucun objet comptable.
- `PiecesJointes::ENTITES` n'a pas `ecriture_comptable`.
- Aucun lien du document vers son écriture.

*Ce qu'il faut faire*
- Ajouter ces modèles à `JournalModifications::MODELES`. Les lignes d'écriture se journalisent dans `Ecritures` (ancienne et nouvelle liste de lignes).
- Ajouter aux verrous `ecriture_comptable`, `transaction_bancaire`, `facture_fournisseur`, `encaissement`, `debourse`, `compte_comptable`.
  - **Piège** : `Verrous::module()` suppose un droit `modifier` ; pour `comptabilite` il faut l'action `ecrire`. Étendre `ENTITES` à `[module, modèle, action]`.
- Ajouter `ecriture_comptable` à `PiecesJointes::ENTITES` (module `finance`).
- Route `GET /comptabilite/ecritures/source?type=&id=` : l'écriture et ses contre-passations, d'après `source`/`source_id`. Sur chaque détail de document, un bouton « Écriture comptable » qui ouvre le journal filtré.

**T5 — Sorties des états : PDF, XLSX, courriel, filtres mémorisés (M)**

- **fripv2** : chaque état (balance, bilan, résultat, plan comptable, taxes, relevés, analyses chronologiques) sort à l'écran, en PDF, à l'imprimante, en Excel et par courriel (PDF joint). Il a des filtres enregistrés par usager (le dernier jeu est rechargé), un raccourci de période (Année, Semestre, Trimestre, Mensuel), une ligne de rappel des critères en tête de page et une trace dans l'historique (qui, quel format).
- **PI** : JSON et CSV seulement ; le PDF existe pour le rapport des taxes et le rapprochement.
- **À faire** : `format=pdf|xlsx` sur `EtatComptableController`, avec des vues dompdf `pdf/etat-*` et `App\Support\ClasseurXlsx`. Envoi par courriel via `EnvoiCourriel`. Réutiliser `FiltresMemorises` (FLOW-FILTRES-MEMORISES). Ajouter un `AuditLog` « export ».

**T6 — Devise des comptes et écarts de change (G)**

- **fripv2** :
  - chaque compte a sa devise ;
  - un seul compte de comptes à recevoir et un seul de comptes à payer par devise ;
  - la devise porte les comptes par défaut : à recevoir, à payer, acompte, escompte sur achat, escompte sur vente, transport, arrondi de caisse ;
  - une écriture n'a que des comptes de sa devise ;
  - la conciliation se fait dans la devise du compte.
- **PI** :
  - tout est en devise de l'entreprise au taux du document ;
  - un encaissement ou un déboursé en devise est passé au taux de la facture (`ecritureEncaissement`, `ecritureDebourse`), donc aucun écart de change ;
  - le rapprochement et l'import de relevé sont refusés ou convertis pour un compte bancaire en devise.
- **À faire** (méthode générique, plutôt que la tenue par devise de fripv2) :
  - colonnes `comptes_comptables.devise` (nullable : devise de l'entreprise), `ecritures_comptables_lignes.devise`, `montant_devise`, `taux` ;
  - `parametres_comptables.compte_gain_change_id` et `compte_perte_change_id` ;
  - `correspondances.devises` : par devise, les comptes clients, fournisseurs, acomptes, escomptes et arrondi ;
  - la comptabilisation inscrit l'argent au taux de l'encaissement ou du déboursé et porte l'écart en gain ou en perte de change ;
  - rapprochement et relevés comparés en `montant_devise` ;
  - en option, une réévaluation de fin de période, contre-passée le lendemain.
- **Pièges** : ne rien recalculer sur les écritures déjà passées ; les tests doivent couvrir un taux différent entre la facture et le paiement.

**T7 — Exports des listes et filtre « Âge » (P)**

- **fripv2** : chaque liste s'exporte en Excel sur toutes les pages filtrées. Les comptes à payer et à recevoir ont un filtre Âge (Courant, En retard 1 à 30 j, Plus de 30, Plus de 60, Plus de 90 ; les « Plus de » se cumulent) et la case « Afficher les soldes à 0 ».
- **PI** : pas d'export sur `FacturesFournisseursPage`, `DeboursesPage`, `EncaissementsPage`, `FacturesPage`. Pas de filtre d'âge sur `/factures`.
- **À faire** : `format=csv|xlsx` sur ces quatre listes ; paramètre `age=courant|1_30|plus_30|plus_60|plus_90` (calculé sur l'échéance) sur `/factures` et `/factures-fournisseurs`.

**T8 — Hygiène tenant (P)** : `tenant_id` nullable sur les tables filles citées au §1, rempli depuis la table parente, puis index et `BelongsToTenant`. Piège : le scope global ne doit pas casser les `with()` existants. Ajouter un test dans `tests/Feature/Tenant`.

---

## 3. Options du menu Comptabilité

### Grand livre › Plan comptable — **Partiel**
**Preuves PI :**
- routes `GET/POST/PUT/DELETE /comptabilite/comptes`, `/comptes/importer-plan`, `/comptes/importer-fichier`, `/comptes/export`, `/plans-types` ;
- écran `PlanComptablePage` : arbre, recherche, filtre actifs/inactifs, tout déplier ;
- `App\Services\Comptabilite\PlanComptable`, `App\Support\PlansComptables`, migration `2026_10_16_100025`.

1. **Ce que fait fripv2**
   - **Champs d'un compte :** numéro, nom (50 caractères, traduisible), compte parent, devise, Actif, Budget, notes, date de création affichée.
     - Le parent est obligatoire sauf pour un en-tête racine. Entre 10000 et 49999, le numéro de l'enfant doit dépasser celui du parent et commencer par le même chiffre (règle canadienne, à ne pas reprendre).
     - Classe : Standard, Comptes à recevoir, Comptes à payer, Banque, Bénéfices non répartis, En-tête, Bénéfice net en cours.
     - La devise est obligatoire pour Standard, Comptes à recevoir et Comptes à payer ; elle est vidée pour un en-tête.
     - Calcul automatique des taxes : *Pas de calcul* / *Calculer* / *Taxes incluses*.
   - **Règles :**
     - un seul compte à recevoir et un seul compte à payer par devise ;
     - un seul compte de bénéfices non répartis ;
     - un seul compte « Bénéfice net en cours », sans écriture ni solde de départ, calculé au bilan ;
     - on ne désactive pas un compte qui a des sous-comptes actifs ;
     - un compte actif ne dépend pas d'un parent inactif ;
     - la suppression est refusée si le compte sert dans une écriture, un solde de départ, un budget ou les comptes de revenus d'un client, ou s'il a des enfants.
   - **Autour du compte :** historique par champ (le changement de mode de taxes est tracé à part), verrou d'édition, traductions, clic droit « Ajouter » avec le parent présélectionné, « Préférences du grand livre » (comptes par défaut).
   - **Import :** XLSX ou CSV, 9 colonnes plus les notes (numéro, devise, parent, budget, classe, calcul des taxes, actif, solde initial, description). Tout ou rien, avec un aperçu.
2. **Ce que PI fait aujourd'hui**
   - Tout le noyau, sans la règle à 5 chiffres (c'est voulu).
   - Le type `resultat` couvre les bénéfices non répartis. Le « résultat non encore clôturé » calculé au bilan remplace le compte « Bénéfice net en cours ».
   - `budgetable` existe.
   - Manquent : la devise, le mode de taxes, les traductions, l'historique et le verrou, le XLSX, les colonnes budget, solde initial et taxes à l'import.
3. **Ce qu'il faut faire**
   - Devise et mode de taxes : voir T2 et T6. Historique et verrou : voir T4.
   - Table `compte_comptable_traductions` (`tenant_id`, `compte_id`, `langue`, `libelle`, unicité), sur le modèle de `produit_traductions`. Les états et les PDF utilisent le libellé dans la langue de l'utilisateur, sinon le libellé de base. Bouton « Traductions » sur la fiche du compte.
   - Import CSV : colonnes facultatives `devise;mode_taxes;budgetable;debit_ouverture;credit_ouverture`. Les soldes partent dans les soldes d'ouverture du premier exercice ; on refuse si la somme n'est pas équilibrée.
   - XLSX : il n'y a pas de bibliothèque de lecture (seul l'écrivain `ClasseurXlsx` existe). Écrire un petit lecteur (archive zip, `sharedStrings`, `sheet1`) ou ajouter `phpoffice/phpspreadsheet`.
   - Protection à la suppression, en plus des écritures et budgets : comptes utilisés dans `parametres_comptables` et ses correspondances, `comptes_bancaires`, comptes par défaut des tiers, frais d'achat.
   - Droit : `comptabilite.plan` (déjà routé par `actionComptable`).
   - Tests : import avec soldes, protections, isolation.
   - Taille : P, plus T2, T4 et T6.

### Grand livre › Transactions manuelles — **Partiel**
**Preuves PI :** `/comptabilite/ecritures` (index, store, update, destroy, `/contre-passer`, `/recurrence`, `/export`), `/comptabilite/journaux` ; écran `JournalComptablePage` ; service `Ecritures`.

1. **Ce que fait fripv2**
   - **Liste :** ne montre que les écritures manuelles, récurrentes et renversées.
     - Recherche sur le numéro, la date, la note ou le numéro de récurrence.
     - Filtre **Montant** au montant exact, sur le total de l'écriture ou sur une ligne, débit ou crédit. Il accepte un intervalle (`1000..1500`) ou une comparaison (`>1000`, `<=500`, `<>0`).
     - Chaque ligne se déplie sur ses écritures (N° compte, Compte, Note, Débit, Crédit, Concilié).
     - Export Excel, avec ou sans les lignes. Les derniers filtres sont mémorisés.
   - **Suppression :**
     - refusée si une ligne est conciliée ;
     - pour une écriture récurrente, une seconde question propose de supprimer aussi les suivantes de la série, épargnant celles qui sont conciliées ;
     - les dates encore planifiées ne sont pas touchées.
   - **Fiche :**
     - Date, **Devise** (tous les comptes de l'écriture dans cette devise), **Note obligatoire** ;
     - Source affichée : G/L, CAR, CAP, ENC, PAI, BAN, ACP, CC, DÉP ;
     - Différence en rouge tant que l'écriture n'est pas équilibrée ;
     - lignes Compte / Débit / Crédit / Note / Balises.
   - **Boutons :**
     - **Copier** : nouvelle écriture à la date du jour, sans numéro ;
     - **Renverser** : écriture inverse datée du jour, note « Renversement de #n » ;
     - **Récurrence** ;
     - Commentaires, Documents (pièces jointes, même sur une écriture générée), Historique.
   - **Contrôles :**
     - compte autorisant l'écriture manuelle (réglé par classe) ;
     - débit et crédit nuls refusés ; débit et crédit ensemble refusés ;
     - totaux égaux et non nuls ;
     - période courante (T1) ;
     - date future à confirmer ;
     - verrou ;
     - écriture conciliée pendant la modification : refus ;
     - pas de compte d'en-tête ;
     - pas de période fermée sans le droit.
   - Une écriture générée s'ouvre en lecture seule ; seules ses balises et ses pièces jointes restent modifiables.
   - Créée depuis le flux bancaire, l'écriture est pré-remplie : la ligne de banque au débit pour un dépôt, au crédit pour un retrait. Elle est reliée à la ligne du relevé à l'enregistrement, à condition de toucher la même banque dans le même sens.
2. **Ce que PI fait aujourd'hui**
   - Saisie complète : Équilibrer, contre-passation à la date choisie (mieux que fripv2), journaux, filtres (dates, journal, compte, origine, récurrence, texte), export CSV des lignes, verrou de pointage.
   - Manquent :
     - copier ;
     - filtre montant ;
     - export de la liste avec les détails ;
     - suppression de la série récurrente ;
     - confirmation d'une date future ;
     - devise ;
     - balises, pièces jointes, historique, verrou ;
     - création d'une écriture depuis une ligne de relevé ;
     - commentaires.
3. **Ce qu'il faut faire**
   - Route `POST /comptabilite/ecritures/{id}/copier` : renvoie un brouillon pré-rempli sans l'enregistrer, ou bien c'est le web qui pré-remplit le formulaire.
   - Filtre `montant` sur `GET /comptabilite/ecritures`, interprété par un analyseur commun `App\Support\FiltreMontant` (`=x`, `a..b`, `>`, `<`, `<>`) qui cherche dans `total` OU dans une ligne (`debit`/`credit`).
   - `GET /ecritures/export?format=xlsx&details=1`.
   - `DELETE /comptabilite/ecritures/{id}?suivantes=1` pour les écritures `source=recurrente` : supprime celles de la même récurrence, datées du même jour ou après, non pointées ni contre-passées ; renvoie la liste des écritures ignorées.
   - Date future : avertissement dans le web, et `confirmer_date_future` côté API si on veut l'imposer.
   - Devise : T6. Balises : T3. Pièces jointes, historique, verrou : T4. Écriture depuis une ligne de relevé : voir « Flux bancaire ».
   - Droits : `comptabilite.ecrire`.
   - Tests : filtre montant, suppression de série, isolation.
   - Taille : M.

### Grand livre › Cycle comptable — **Partiel**
**Preuves PI :** `/comptabilite/exercices` (CRUD, `/cloture`, `/cloturer`, `/rouvrir`), `/periodes/{id}/fermer|rouvrir` ; écran `ExercicesComptablesPage` ; services `Exercices` (`DECOUPAGES = mensuel|trimestriel|unique`) et `Clotures`.

1. **Ce que fait fripv2**
   - **Cycle :** date de début, date de fin, nombre de périodes (12, 13, 4, 52 ou n'importe quel nombre).
     - Les cycles doivent se suivre, sans trou ni chevauchement.
     - Valeurs proposées : un an après le cycle précédent, avec le même nombre de périodes.
     - Option « Dupliquer le cycle précédent » : recopie ses périodes décalées d'un an. Refusée si le nouveau cycle a déjà des périodes, si l'ancien cycle est incomplet, ou si les dates décalées sortent du nouveau cycle.
   - **Périodes saisies une à une** : numéro, début, fin, Courante, Fermée.
     - Valeurs proposées : numéro précédent + 1, début = lendemain de la fin précédente.
     - Contrôles : périodes consécutives, sans chevauchement, à l'intérieur du cycle ; la période 1 commence au début du cycle ; numéro au plus égal au nombre prévu et unique.
     - Suppression d'une période refusée si elle est fermée, si son cycle l'est, ou si un budget l'utilise.
     - Une seule période courante (T1).
   - **Fermeture du cycle :**
     - refusée si un cycle plus ancien est encore ouvert ;
     - si le cycle suivant n'existe pas, propose de le créer ;
     - confirmation qui montre : les dates, le nombre de périodes encore ouvertes, et le report des bénéfices non répartis en trois montants (solde actuel du compte, résultat du cycle, solde au début du cycle suivant) ;
     - ferme toutes les périodes et écrit les soldes de départ du cycle suivant ;
     - aucune réouverture possible.
   - **Permissions fines :** ajouter / modifier / supprimer / fermer un cycle ; ajouter / modifier-ou-fermer / supprimer une période.
2. **Ce que PI fait aujourd'hui**
   - Équivalent sur l'essentiel, et mieux sur deux points : réouverture possible, et une écriture de clôture au lieu de soldes de départ.
   - Manquent :
     - découpages libres (13 périodes de 4 semaines, 4-4-5, hebdomadaire) et modification ou ajout de périodes à la main ;
     - période courante (T1) ;
     - proposition de créer l'exercice suivant à la clôture ;
     - solde actuel et solde après report du compte de résultat dans l'aperçu ;
     - historique des exercices et périodes (T4).
3. **Ce qu'il faut faire**
   - `Exercices::DECOUPAGES` reçoit `quatre_semaines` (13 périodes), `quatre_quatre_cinq` et `personnalise`.
   - Routes :
     - `POST /comptabilite/exercices/{id}/periodes` (ajouter une période) ;
     - `PUT /comptabilite/periodes/{id}` (dates) ;
     - `DELETE /comptabilite/periodes/{id}` ;
     - toutes refusées si la période a des écritures, un budget ou est fermée ;
     - contrôle de consécutivité dans le service.
   - `Droits::actionComptable()` : `/periodes/` vers `plan`.
   - Dans `GET /exercices/{id}/cloture`, ajouter `solde_resultat_actuel`, `solde_resultat_apres` et `exercice_suivant_existe`. Le web propose alors « Créer l'exercice suivant ».
   - Pièges : le budget comparé au réel et la copie de budget rapprochent les périodes par leurs dates et doivent supporter des périodes non mensuelles ; le raccourci de fréquence des états (T5) doit regrouper par numéro de période.
   - Tests : chaque découpage, consécutivité, isolation.
   - Taille : M.

### Grand livre › Budgets — **Fait**
**Preuves PI :** `/budgets`, `/budgets/repartir`, `/budgets/copier`, `/budgets/comptes`, `/budgets/comparaison` ; écran `BudgetsPage` ; service `Budgets` ; droit `budgets.saisir`.

PI dépasse fripv2 : répartition, copie d'un exercice avec pourcentage, budget comparé au réel, journal des modifications. Seul écart : l'historique par compte (fripv2 l'ouvre depuis la ligne). PI a un journal global, ce qui suffit.

### Grand livre › Soldes de départ — **Partiel**
**Preuves PI :** `GET/PUT /comptabilite/soldes-ouverture` ; écran `SoldesOuverturePage` ; service `Soldes`.

1. **Ce que fait fripv2**
   - **Choix du cycle :** n'importe lequel ; celui qui suit le dernier cycle fermé est proposé.
   - **Comptes proposés :** **tous** les comptes actifs de détail, comptes de revenus et de dépenses compris. C'est ce qui permet une reprise en cours d'année.
   - **Saisie :**
     - débit ou crédit par compte, l'un vidant l'autre ;
     - totaux en direct ; refus si non équilibré (« Les soldes ne sont pas balancés. ») ;
     - écran en consultation par défaut, bouton « Modifier », confirmation avant d'annuler.
   - **Verrouillage :** non modifiable si le cycle est fermé ou si un cycle plus récent l'est.
   - **Historique** par compte.
   - Les soldes de départ alimentent la balance, le bilan et le résultat, sauf quand le résultat est filtré par balises.
2. **Ce que PI fait aujourd'hui**
   - Seulement le premier exercice et seulement les comptes de bilan.
   - Une seule écriture AN au premier jour, non modifiable après la fermeture de la première période.
   - Impossible donc de reprendre une activité en cours d'exercice avec son résultat cumulé. Pas d'import, pas d'historique (T4).
   - Pas de reprise des soldes de tiers (factures ouvertes par client ou fournisseur).
3. **Ce qu'il faut faire**
   - **Reprise en cours d'exercice**
     - `parametres_comptables.date_reprise` : facultative, à l'intérieur du premier exercice.
     - Si elle est réglée, les soldes d'ouverture acceptent les comptes de produits et de charges : leur écriture AN est datée du jour de reprise et vient avant toute autre écriture.
     - Pièges :
       - `Clotures` doit inclure ces soldes de résultat dans le résultat de l'exercice ;
       - le résultat d'une période qui commence avant la date de reprise les inclut ;
       - la balance doit rester équilibrée.
   - **Import CSV** `numero;debit;credit` via `POST /comptabilite/soldes-ouverture/importer`, tout ou rien. Il refuse les comptes en-têtes ou inactifs.
   - **Soldes des tiers** : facture comptable (voir « Gestion comptes à recevoir ») et facture fournisseur ventilée, avec la case « Reprise de solde » qui exclut le document de la comptabilisation (le solde est déjà dans l'écriture AN), d'où `exclure_comptabilisation` dans les deux tables.
   - Droit : `comptabilite.ecrire` (comme aujourd'hui).
   - Tests : reprise mi-exercice et clôture, import.
   - Taille : M.

### Grand livre › Rapports
Statut seulement ici ; voir le §4.

### Transactions récurrentes — **Partiel**
**Preuves PI :**
- routes `POST /comptabilite/ecritures/{id}/recurrence`, `GET /comptabilite/recurrences`, `/recurrences/convertir`, `PUT|DELETE /recurrences/{id}` ;
- tâche `ecritures_recurrentes` ; écran `EcrituresRecurrentesPage` ; service `Recurrences` ;
- tables `recurrences_comptables` (lignes en JSON au niveau de la récurrence) et `recurrence_echeances` (date et écriture créée, **sans `tenant_id`**).

1. **Ce que fait fripv2**
   - **Sources possibles :**
     - un compte à recevoir manuel, mais pas un compte issu d'une facture ni situé en période fermée ;
     - un compte à payer manuel, mais pas un compte issu d'une réception ou d'une commande (message : « pour une dépense périodique, créez un compte à payer manuel ») ;
     - une écriture manuelle du grand livre ;
     - une transaction bancaire saisie à la main.
   - **Fenêtre « Récurrence » :**
     - Nb répétitions, de 1 à 9999 (0 est refusé) ; la transaction d'origine n'est pas recopiée ;
     - Date de début : date passée acceptée après confirmation (« La date saisie est dans le passé. Continuer ? ») ;
     - Fréquence : Journalière, Hebdomadaire, 14 jours, Mensuelle (comptée depuis la date de début, ramenée au dernier jour des mois courts), Trimestrielle, Semestrielle, Annuelle (29/02 → 28/02), **Bi-mensuelle** ;
     - en bi-mensuelle, deux jours du mois au choix (1 à 28 ou « Dernier jour du mois » ; « Le 1er jour doit précéder le 2e jour. ») ; chaque date compte pour une répétition ;
     - toutes les occurrences reçoivent un numéro de récurrence commun.
   - **Contenu d'une occurrence :** copie complète, avec la devise, le tiers, la condition de paiement, le document, le type bancaire, les lignes, les notes et les balises.
   - **Écran Transactions récurrentes :**
     - n'affiche par défaut que les occurrences en attente (« No transaction » vide) ;
     - filtre Source (CAR, CAP, G/L, BAN, Toutes) et case « Inclure les récurrences déjà générées » ;
     - colonnes : case à cocher, Source, Date, Client/Fournisseur, Montant total, No transaction (lien), Trx originale (lien), No récurrence ;
     - chaque ligne se déplie sur ses lignes comptables.
   - **Fiche d'une occurrence :**
     - consultation, puis Modifier ; en-tête (tiers, devise, date, condition, document, no chèque, type bancaire, note) et lignes modifiables pour cette occurrence seulement ;
     - contrôles : équilibre pour une écriture du grand livre ou une transaction bancaire ; somme des lignes égale au montant pour un compte à recevoir ou à payer ;
     - historique propre : « Récurrence ajoutée / modifiée / convertie -> transaction #N / supprimée ».
   - **Actions :**
     - supprimer une seule occurrence ;
     - **Convertir en lot** les occurrences cochées, toutes sources mêlées : compte-rendu par occurrence (numéro créé ou raison de l'échec), les autres passent ;
     - les occurrences déjà converties sont écartées avec un avertissement ;
     - l'échéance d'un compte à recevoir ou à payer est calculée **à la conversion**, d'après la date de l'occurrence et sa condition de paiement ;
     - règles de période fermée et de période courante appliquées à la conversion ;
     - une transaction conciliée peut servir de modèle ;
     - permission unique « Gérer les transactions récurrentes ».
2. **Ce que PI fait aujourd'hui**
   - Le grand livre seulement, 7 fréquences (pas la bi-mensuelle), 120 répétitions au plus.
   - Pas de correction par occurrence, pas de suppression d'une seule date (seulement « supprimer les dates restantes »).
   - Pas de lien vers le document d'origine ni par source, pas d'historique par occurrence.
   - En plus, la conversion automatique planifiée, que fripv2 n'a pas.
3. **Ce qu'il faut faire**
   - **Migration :**
     - `recurrences_comptables.source` (`gl` par défaut | `piece_client` | `facture_fournisseur` | `banque`) ;
     - `modele_type` et `modele_id` (document d'origine) ; `jours_bimensuels` (JSON `[1,15]` ou `"fin"`) ;
     - `recurrence_echeances.tenant_id` (rempli depuis la récurrence) et `donnees` (JSON nullable : en-tête et lignes surchargés, balises) ;
     - `document_type` et `document_id` (document créé) ; `supprimee` (booléen).
   - **Service :** interface `ConvertisseurRecurrence` avec quatre implémentations (écriture, facture comptable client, facture fournisseur, transaction bancaire). `Recurrences::dates()` gère `bimensuelle` ; la limite passe à 9999.
   - **Routes** sous un nouveau préfixe `/recurrences` (l'ancien `/comptabilite/recurrences` reste pour compatibilité) :
     - `GET /recurrences?source=&inclure_generees=` ;
     - `GET|PUT|DELETE /recurrences/occurrences/{id}` ;
     - `POST /recurrences/convertir` (`occurrences[]`) ;
     - `POST /factures-fournisseurs/{id}/recurrence`, `POST /banque/transactions/{id}/recurrence`, `POST /pieces-clients/{id}/recurrence`.
   - **Droits :**
     - action `comptabilite.recurrences` (« Gérer les transactions récurrentes », base `creer`) ;
     - `Droits::LECTURES['recurrences'] = 'comptabilite'` et `PARTICULIERES` pour les écritures de `/recurrences` ;
     - à la conversion, le contrôleur vérifie aussi le droit de créer le document visé : `factures_fournisseurs.creer`, `banque.saisir`, `comptabilite.ecrire` ou le droit des factures comptables.
   - **Écran :** `EcrituresRecurrentesPage` devient « Transactions récurrentes », avec le filtre de source et une fiche d'occurrence modifiable. Bouton « Récurrence » sur les trois nouveaux documents.
   - **Tâche :** `ecritures_recurrentes` convertit toutes les sources, avec un réglage `sources[]`.
   - **Tests :** bi-mensuelle et 31 du mois, correction d'une occurrence, conversion en lot avec échec partiel, isolation.
   - **Pièges :** convertir deux fois en parallèle (garder le verrou par date déjà en place) ; une facture fournisseur créée par une récurrence ne doit jamais être liée à une réception.
   - Dépend de « Gestion comptes à payer », « Gestion comptes à recevoir » et « Transactions bancaires ».
   - Taille : G.

### Payables › Gestion comptes à payer — **Partiel**
**Preuves PI :**
- routes `/factures-fournisseurs` (index, `proposition`, store, update, `aligner-couts`, `paiements`, `annuler`) ;
- écran `FacturesFournisseursPage` ; service `FacturesFournisseurs` ;
- migration `2026_10_17_100060` (lignes : description, quantité, prix, groupe de taxes, ligne de réception ; **pas de compte**).

1. **Ce que fait fripv2**
   - **Liste :**
     - colonnes : No transaction, dates de création et d'échéance, fournisseur, No facture, Montant, Solde (montant − versé − escomptes), Statut, Âge, Note, No récurrence ;
     - statuts : Payé complet, Payé partiel, Impayé, En retard (prioritaire), Annulé ; pour un crédit : Crédit reçu, Crédit reçu en partie, Crédit à recevoir, jamais En retard ;
     - Âge : « En retard de N jours », « Dû aujourd'hui », « Dû dans X jours », « Courant » ;
     - filtres Statut, Âge (T7), « Afficher les soldes à 0 », recherche ; export Excel ;
     - ligne dépliable sur quatre onglets : Ventilation GL, Réceptions portées, Commandes liées, Paiements appliqués ;
     - suppression refusée s'il y a un paiement ; elle annule l'écriture ; pour une série récurrente, propose de supprimer les suivantes sauf les payées.
   - **Fiche, en-tête :**
     - fournisseur (charge la devise, la condition, le groupe de taxes et les balises par défaut) ;
     - **No facture** obligatoire et unique par fournisseur, sans tenir compte de la casse ni des espaces ; une facture renversée ou supprimée libère son numéro ;
     - Note, Devise (la changer vide les lignes après confirmation) ;
     - Condition de paiement obligatoire ; compte fournisseurs déduit de la devise ;
     - Date (période courante, date future à confirmer) ;
     - Montant calculé depuis les lignes tant qu'on ne le saisit pas ;
     - Solde et échéance calculés.
   - **Fiche, lignes :**
     - **ventilation par compte du grand livre** (compte en devise et ouvert à la saisie manuelle), montant, case **Non taxable**, note (100 caractères), Document (lien vers la réception ou la commande d'origine), balises ;
     - pré-remplies avec les **comptes de dépense habituels** du fournisseur et les comptes de taxes de son groupe ;
     - depuis des réceptions ou une commande : une ligne par produit, sur le compte de stock (inventaire permanent) ou d'achat (périodique) du département **figé à la réception** ;
     - **vue GL / vue articles** (quantité, prix unitaire réellement facturé à 4 décimales) ;
     - bandeau Sous-total / Taxes (détail au survol) / Total ;
     - ligne « Frais de transport – [transporteur] » ajoutée d'après le frais de livraison du transporteur ;
     - frais d'achat (voir la section suivante).
   - **Actions :**
     - **Renverser** : nouveau compte au montant opposé ; c'est la note de crédit ;
     - **Copier** : date du jour, sans numéro de facture ;
     - **Récurrence** : manuels seulement ;
     - Paiements, **Payer** (ouvre un déboursé avec ce compte coché) ;
     - Ajouter réceptions (seulement celles non facturées de ce fournisseur) ;
     - Écritures, Documents, Historique ;
     - proposition de payer après l'enregistrement ;
     - non modifiable dès qu'un déboursé est appliqué ;
     - **alignement des coûts** : propose d'ajuster le coût moyen et le dernier coût des produits et le coût du fournisseur.
   - **Permissions :** Voir, Ajouter, Modifier ou renverser, Supprimer, Saisir en période fermée.
2. **Ce que PI fait aujourd'hui**
   - Facture fournisseur saisie à la main ou depuis des réceptions, rapprochement de la valeur reçue, alignement des coûts, numéro du fournisseur unique, états calculés (dont en retard), paiements simples, déboursés, annulation avec motif, pièces jointes.
   - Manquent :
     - la ventilation par compte et les comptes de charge par fournisseur (T2) ;
     - Non taxable et le mode de taxes du compte ;
     - **les notes de crédit fournisseur et le renversement** (les avoirs de retour, clos dans `retours_fournisseurs.montant_credite`, ne sont pas imputables, voir FLOW-ACHATS §9) ;
     - copier, récurrence ;
     - filtre Âge, export, vue GL/articles ;
     - ligne de transport du transporteur ;
     - lien vers l'écriture ;
     - figer le compte au moment de la réception ;
     - balises.
3. **Ce qu'il faut faire**
   - **Migration :**
     - `facture_fournisseur_lignes` : `compte_comptable_id` (nullable : la règle actuelle catégorie → achats s'applique), `non_taxable`, `frais_achat_id`, `tenant_id` (T8) ;
     - `factures_fournisseurs` : `nature` (`facture` par défaut | `avoir`), `avoir_de_id`, `retour_fournisseur_id`, `recurrence_id`, `exclure_comptabilisation` ;
     - table `fournisseur_comptes_charges` (T2).
   - **Note de crédit fournisseur :**
     - route `POST /factures-fournisseurs/{id}/renverser` : avoir miroir, montants opposés figés, frais d'achat compris ;
     - `POST /factures-fournisseurs` avec `nature=avoir` : avoir saisi à la main ;
     - action « Créer l'avoir » sur un retour fournisseur clos (crédit obtenu).
     - Contrôles : quantité positive et montant négatif (créer une validation dédiée sans toucher à celle des factures) ; numéro du fournisseur unique.
     - Le déboursé impute les avoirs (voir « Entrée déboursé »).
     - Comptabilisation, type `avoir_fournisseur` : fournisseurs au débit ; achats ou charges et taxes déductibles au crédit.
   - **Copier :** `POST /factures-fournisseurs/{id}/copier` renvoie un brouillon. Récurrence : voir « Transactions récurrentes ».
   - **Saisie :**
     - sans réception, pré-remplir les lignes avec les comptes de charge du fournisseur et calculer les taxes avec `TaxesLignesComptables` selon le mode de chaque compte et Non taxable ;
     - depuis une réception, mettre le compte d'achat (ou de stock en inventaire permanent, voir « Comptabilisation automatique ») figé sur la ligne de réception (`reception_arrivages_ligne.compte_comptable_id`, renseigné à la réception).
   - **Comptabilisation (`ecritureAchat`) :** une ligne avec un compte va au débit de ce compte ; sinon la règle actuelle.
   - **Liste :** filtre `age`, export (T7), case « Afficher les soldes à 0 » (le filtre `etat=a_payer` existe déjà).
   - **Transport** (optionnel) : `transporteurs.frais_achat_montant` et `compte_comptable_id` ; ligne ajoutée et retirée à 0,00.
   - **Écran :** colonne Compte et case Non taxable dans l'éditeur, bascule vue GL/articles, boutons Renverser, Copier, Récurrence, Écriture.
   - **Droits :** `factures_fournisseurs.creer` pour l'avoir, la copie et la récurrence (préfixe déjà routé), et `PARTICULIERES` pour `/renverser` vers `creer`.
   - **Tests :** ventilation et taxes en sus ou incluses, Non taxable, avoir puis imputation, rapport des taxes, isolation.
   - **Pièges :** `RapportTaxes` doit soustraire les avoirs ; `PaiementsDus` et `Virements` doivent ignorer les montants négatifs ou les imputer.
   - Taille : G.

### Payables › Frais d'achat (ventilés au compte à payer) — **Manquant**
**Preuves PI :**
- `frais` / `frais_produits` (migration `2026_10_16_100037`) sont **seulement de vente** : `produit_id` obligatoire, champs `mode`, `valeur`, `application`, `repetition`, `sur_devis`, `sur_commandes`, `sur_factures` ;
- service `FraisDocuments`, écran `FraisPage` ;
- les « coûts annexes » de réception (`reception_frais`, `ReceptionsFournisseur::repartirFrais`, bases valeur / quantité / poids / volume) entrent dans le **coût de revient du stock**. C'est une autre notion.

1. **Ce que fait fripv2** (table `frais`, fiche « Frais additionnel », portée Achat)
   - **Portée :** Vente ou **Achat**. Un frais d'achat n'apparaît jamais sur un document de vente, et inversement.
     - À l'achat, le champ Produit est grisé ; la base de calcul et le **compte de dépense** deviennent actifs.
     - Le compte de dépense est obligatoire si le frais est actif (« Un frais d'achat actif doit avoir un compte de dépense » ; « Ce compte de dépense est introuvable »).
   - **Mode d'application :**
     - **Par produit** : le frais est déclenché par les produits associés (fiche produit, onglet Spécification, grille « Frais applicables », colonne Applicable) ; **un produit ne porte qu'un seul frais d'achat**. Il apparaît en ligne « Frais de surcharge » dans les totaux de la commande fournisseur et sur le bon de commande imprimé, puis devient une ligne dédiée du compte à payer construit depuis la commande (quantités commandées) ou les réceptions (quantités reçues) ;
     - **Par document** : ajouté **une seule fois** au compte à payer, même saisi entièrement à la main, avec la note « Frais d'achat sur le compte à payer ». Absent de la commande et du bon de commande.
   - **Base de calcul :** Quantité achetée (unité d'achat), **Poids acheté** ou **Volume acheté** (poids et volume d'achat de la fiche produit, dans leur unité ; absents, ils ne facturent rien).
   - **Type de montant :** Montant fixe (tarif par unité de la base quand on multiplie) ou **Pourcentage** (4 décimales, supérieur à 0).
     - Par document : le pourcentage porte sur les lignes de dépense, hors taxes et hors toute ligne de frais, et se recalcule à chaque saisie.
     - Par produit : il porte sur les seules lignes des produits associés.
   - **Plancher et plafond** : `montant_min` et `montant_max` ; 0 veut dire aucune limite ; maximum au moins égal au minimum ; appliqués au total du frais.
   - **Comptabilisation :**
     - Une fois par document ;
     - Une fois par ligne et **Par unité de la base de calcul** : pour un frais Par produit, ces deux choix multiplient le tarif par la quantité, le poids ou le volume ;
     - un frais Par document compte toujours une fois ;
     - un pourcentage est verrouillé sur Une fois par document.
   - **Exclusions :** liste « Retire ces frais » (table `frais_exclusion`), seulement entre frais de même portée et, à l'achat, de même mode d'application. Pas de cycle (A retire B, B retire C : C ne peut pas retirer A). Mention « Retiré par… ».
   - **Tarif par fournisseur :** `produit_fournisseur.frais_achat` (colonne « Frais à l'achat » de l'onglet Fournisseurs de la fiche produit). Vide : tarif du frais. **0 : produit exempté chez ce fournisseur**, et exclu de la base d'un pourcentage.
   - **Instantanés :** sur les lignes de commande et de réception : `frais_achat_origine` (taux), `id_frais_achat_origine`, `poids_achat_origine`, `id_compte_gl_achat_origine`, `id_departement_origine`.
   - **Au compte à payer :**
     - une ligne de ventilation dédiée sur le compte de dépense, **taxable** comme les autres ;
     - Document vide (la ligne peut couvrir plusieurs réceptions ; le numéro est dans la note) ;
     - la ligne est tenue par le système : supprimée, elle revient ; pour qu'un compte à payer n'en porte pas, il faut désactiver le frais ;
     - un retour fournisseur (ligne de réception négative) ne porte jamais de frais, et un compte fait uniquement de retours n'en a aucun ;
     - sur un crédit saisi à la main, le frais est **négatif** (plafond et plancher sur la valeur absolue : 6 % plafonné à 30 $ donne −30,00 $ sur un crédit de 1 000 $) ; si le compte mêle un achat et un crédit, un seul frais, du sens du solde ;
     - **renversement** : frais figé à l'opposé, jamais recalculé ;
     - sans compte de dépense : avertissement, montant à saisir à la main.
   - **Activation et suivi :** frais actif ou inactif (un frais inactif peut rester incomplet), description traduisible, historique champ par champ.
2. **Ce que PI fait aujourd'hui**
   - Rien pour l'achat.
   - Les coûts annexes d'une réception sont répartis dans le coût de revient du stock (base valeur, quantité, poids, volume), saisis par réception, sans compte de charge ni règle automatique par produit.
3. **Ce qu'il faut faire** (référentiel séparé, puisque `frais.produit_id` est obligatoire et qu'on ne modifie pas l'existant)
   - **Migration :**
     - `frais_achat` : `tenant_id`, `libelle`, `mode_application` (`document|produit`), `base_calcul` (`quantite|poids|volume`), `type_montant` (`fixe|pourcentage`), `montant`, `pourcentage`, `montant_min`, `montant_max`, `comptabilisation` (`document|ligne|unite`), `compte_comptable_id`, `traitement` (`charge` par défaut | `cout_revient`), `actif`, `softDeletes` ;
     - `frais_achat_produits` : `tenant_id`, `frais_achat_id`, `produit_id`, **unicité (tenant, produit)** ;
     - `frais_achat_exclusions` : `tenant_id`, `frais_achat_id`, `frais_retire_id` ;
     - `produit_fournisseur.frais_achat` (decimal nullable : null = tarif du frais, 0 = exempté) ;
     - instantanés sur `com_four_ligne` et `reception_arrivages_ligne` : `frais_achat_id`, `frais_achat_tarif`, `poids_unitaire`, `volume_unitaire` ;
     - `facture_fournisseur_lignes.frais_achat_id`.
   - **Service `FraisAchat` :**
     - `pourCommande(commande)` : ligne « Frais de surcharge » dans les totaux et le PDF du bon de commande ;
     - `pourFacture(facture, origine)` : recalcule les lignes `frais_achat_id` à chaque enregistrement (par produit, puis par document, exclusions, plancher et plafond, signe, retours exclus, frais figé si renversement) ;
     - contrôle d'absence de cycle dans les exclusions.
   - **Routes :**
     - `GET|POST|PUT|DELETE /frais-achat` et `POST /frais-achat/calculer` (aperçu, ajouté à `Droits::LIBRES`) ;
     - `PUT /produits/{id}/frais-achat` (association : droit `produits.modifier`) ;
     - colonne frais dans `PUT /fournisseurs/{id}/produits` (catalogue).
   - **Droits :** `Droits::MODULES['frais-achat'] = 'configuration'` (comme `frais`).
   - **Écrans :**
     - onglet « Achat » de `FraisPage` (portée Vente / Achat) ;
     - grille « Frais applicables » sur la fiche produit ;
     - colonne « Frais à l'achat » du catalogue fournisseur ;
     - totaux de `CommandesFournisseurPage` et lignes de facture fournisseur (non supprimables).
   - **Comptabilisation :** la ligne va au compte du frais (voir « Gestion comptes à payer »). Avec `traitement=cout_revient`, la ligne passe plutôt par les coûts annexes de la réception, pour éviter de compter deux fois.
   - **Tests :** les trois bases, pourcentage plafonné, exemption fournisseur, retour exclu, crédit négatif, renversement figé, isolation.
   - **Pièges :**
     - garder le calcul distinct des coûts annexes ;
     - poids et volume figés à la commande et à la réception ;
     - frais en devise : tarif dans la devise du document.
   - Dépend de « Gestion comptes à payer » (ventilation).
   - Taille : G.

### Payables › Transactions par fournisseur — **Manquant**
**Preuves PI :** aucune. `FicheFournisseurPage` n'a qu'une synthèse des achats ; il n'y a pas de pendant de `/comptes-clients`.

1. **Ce que fait fripv2**
   - **Relevé combiné** d'un fournisseur : chaque facture suivie des paiements qui lui ont été appliqués, avec un **solde progressif**.
   - **Filtres :** fournisseur (obligatoire), **date d'analyse** (aujourd'hui par défaut ; seules les factures et paiements jusqu'à cette date), « Inclure les transactions à zéro ».
   - **Colonnes :** No transaction, Date, No fournisseur, Nom, Document (numéro de facture, ou référence du paiement), Montant facturé, Montant paiement, Solde, Note.
   - **Pied :** totaux (facturé, payé, solde) et **âge du solde** : Courant 0–30, 30 (31–60), 60 (61–90), 90 et plus, calculés **sur la date de facture**, comme le rapport d'analyse chronologique des comptes à payer.
   - Double-clic : ouvre la facture ou le déboursé.
   - Même relevé dans l'onglet Comptes à payer de la fiche fournisseur.
   - Permission : Voir les comptes à payer.
2. **Ce que PI fait aujourd'hui :** rien. `PaiementsDus` raisonne sur l'échéance et ne fait pas de relevé.
3. **Ce qu'il faut faire**
   - **Nouveau module** `comptes_fournisseurs` (onglet `achats`, `crud ['voir']`), droits recopiés de `factures_fournisseurs.voir` ; `Droits::LECTURES['comptes-fournisseurs']`.
   - **Service** `ComptesFournisseurs`, calqué sur `ComptesClients` :
     - `GET /comptes-fournisseurs?date=&base=facture|echeance&format=csv|xlsx|pdf` : analyse chronologique par fournisseur, mode sommaire ou détaillé par facture (couvre le rapport d'analyse chronologique des comptes à payer) ;
     - `GET /comptes-fournisseurs/{fournisseur}/releve?date=&inclure_soldees=&format=` : factures, avoirs, paiements, parts de déboursé, escomptes, solde progressif, tranches.
   - **Écrans :** page « Comptes fournisseurs » (Achats) et onglet « Comptes à payer » de `FicheFournisseurPage`.
   - **Tests :** soldes à une date antérieure, tranches, avoirs, isolation.
   - Taille : M.

### Payables › Gestion déboursés — **Fait** (écarts mineurs)
**Preuves PI :** `GET /debourses` (recherche, fournisseur, mode, statut, période, totaux) ; écran `DeboursesPage` ; annulation avec motif (traçable, mieux qu'une suppression).

Écarts :
- export (T7) ;
- colonne et filtre « compte bancaire » ;
- montants négatifs (remboursements reçus, voir « Entrée déboursé ») ;
- modification complète tant que le déboursé n'est ni pointé ni en période fermée : PI corrige par annulation puis nouvelle saisie, ce qui est acceptable.

Taille : P.

### Payables › Entrée déboursé — **Partiel**
**Preuves PI :** `POST /debourses`, `GET /debourses/a-payer` (escompte calculé) ; composant `debourses/FenetreDebourse` ; compte bancaire, avis de paiement, pièces jointes.

1. **Ce que fait fripv2**
   - **En-tête :** fournisseur, devise, condition (la changer recalcule les escomptes), mode de paiement (porte son compte de banque et son **type d'émission** : aucun, TFE, chèque), référence, date (la changer recalcule les escomptes), montant (synchronisé tant qu'on ne le saisit pas).
   - **Tableau des factures** à payer : dans la devise, datées au plus tard aujourd'hui.
     - colonnes : No compte (lien), Date, No facture, Mnt facture, Escompte (calculé ligne par ligne, toujours négatif, modifiable), Mnt payé, Solde, Mnt à payer, Nouveau solde, case « tout » ;
     - **balance obligatoirement à 0** ;
     - signe du paiement identique à celui de la facture.
   - **Remboursement reçu du fournisseur :** un **déboursé négatif** appliqué à une note de crédit (compte à payer négatif).
     - le chèque et le TFE sont refusés dans ce cas ;
     - pas d'escompte sur un crédit (« remis à 0 avec explication ») ;
     - écriture inverse : banque au débit, compte à payer au crédit.
   - **TFE et chèque :**
     - TFE : la configuration bancaire du fournisseur doit être complète ; une transaction TFE en attente est créée, puis mise à jour ou supprimée si le mode change avant l'émission ;
     - bouton Imprimer chèque.
   - **Modification** tant que le déboursé n'est ni concilié ni en période fermée. Pré-remplissage depuis le flux bancaire. Verrou. Historique.
2. **Ce que PI fait aujourd'hui**
   - Tout le cas courant : escompte offert, case Tout, compte bancaire, numéro, avis de paiement PDF, annulation, virements et chèques séparés.
   - Manquent : le **remboursement reçu** (déboursé négatif imputé sur un avoir), l'imputation d'un avoir fournisseur dans le déboursé, le lien vers l'écriture, le verrou, la création depuis une ligne de relevé.
3. **Ce qu'il faut faire**
   - `GET /debourses/a-payer` renvoie aussi les avoirs fournisseurs ouverts (montant négatif).
   - `debourse_lignes` accepte des montants négatifs si la facture est un avoir.
   - Le déboursé peut être négatif (`montant < 0`) seulement en mode `especes|virement|autre`, jamais `cheque` : « Un remboursement reçu ne s'émet pas par chèque. »
   - Comptabilisation (`ecritureDebourse`) gérer le sens.
   - Un déboursé à solde nul (avoir imputé sur une facture, sans argent) est autorisé ; c'est un nouveau cas.
   - Lien vers l'écriture et verrou : T4.
   - Tests : avoir imputé, remboursement reçu, refus du chèque négatif, isolation.
   - Dépend des avoirs fournisseurs (« Gestion comptes à payer »).
   - Taille : M.

### Payables › TFE - Impression de chèques — **Partiel**
**Preuves PI :**
- chèques : `POST /debourses/cheques/numeroter`, `GET /debourses/cheques/pdf`, service `Cheques`, `MontantEnLettres` ;
- virements fournisseurs : `/debourses/virements`, `/virements/lots` (SEPA pain.001 ou CSV, lot refusé), écran `VirementsPage`, service `Virements`.

1. **Ce que fait fripv2**
   - **Un seul écran, deux modes :**
     - TFE : paiements fournisseurs (**retraits**, source PAI) **et encaissements clients (dépôts, source ENC = prélèvements)** ;
     - Chèque.
   - **Filtres :** Statut (En attente par défaut, Émis, Refusé, Complété, Annulé : **libellés et ordre configurables**, table `tfe_statut`), Devise, Type (dépôt ou retrait), Mode de paiement, recherche.
   - **Sélection :** toutes les lignes cochées par défaut ; Sélectionner tout / Désélectionner tout ; compteurs Nombre sélectionné, Montant sélectionné, Montant total.
   - **Générer le fichier :**
     - choix de l'**institution** et du numéro de lot (proposé = dernier + 1) ;
     - fichier au format de l'institution, nommé `[institution]-[AAAAMMJJ_HHMMSS]-[lot].ext`, dans son répertoire ;
     - lignes émises → « Émis », lignes en échec → « Refusé » ;
     - numéro de lot consommé seulement si le fichier est écrit ;
     - tiers sans coordonnées ou non éligible : ligne ignorée avec un message, les autres passent ;
     - formats contrôlés (longueurs, caractères) avant d'écrire ;
     - **talon TFE** produit pour chaque paiement fournisseur, dans la langue du fournisseur.
   - **Modifier statut** par lot, tracé (permission propre).
   - **Chèque :**
     - numéro attribué à la première génération, même en aperçu ;
     - montant en lettres dans la **langue du fournisseur** ;
     - talon de 20 factures sur deux colonnes ;
     - impression sur **formulaire préimprimé**.
   - **Données d'une transaction TFE** : institution, source, statut, type, devise, montant, tiers, date des fonds disponibles, date faite, référence, coordonnées du tiers chiffrées, lot, numéro de contrôle, émetteur, type d'opération.
2. **Ce que PI fait aujourd'hui**
   - Côté fournisseurs, mieux que fripv2 : tout vérifié avant d'écrire, fichier gardé tel qu'émis, alerte de fraude si les coordonnées ont changé depuis moins de 30 jours.
   - Manquent :
     - les **prélèvements clients** : ni coordonnées bancaires client, ni mandat, ni fichier ;
     - statuts configurables et changement de statut manuel par lot (seulement Refusé) ;
     - format générique configurable (CPA-005, NACHA, ou largeur fixe) ;
     - chèque dans la langue du fournisseur et gabarit préimprimé ;
     - talon groupé par lot.
3. **Ce qu'il faut faire**
   - **Prélèvements (G) :**
     - `client_comptes_bancaires` : `tenant_id`, `client_id`, `iban`, `bic`, numéro local, `titulaire`, `principal`, `mandat_reference`, `mandat_date`, `sequence` (`FRST|RCUR`), `actif` ;
     - action `clients.comptes_bancaires` (base `modifier`) ;
     - paramètre `encaissements.mode=prelevement` ;
     - table `prelevements_lots` (pendant de `virements_lots`) ;
     - `encaissements.prelevement_lot_id` et `prelevement_statut` ;
     - service `Prelevements` avec un format SEPA pain.008.001.02 (ICS de l'émetteur sur `comptes_bancaires.creancier_identifiant`) et un format CSV ;
     - routes `/encaissements/prelevements` et `/encaissements/prelevements/lots`, action `encaissements.prelevements` (base `creer`) ;
     - un lot refusé remet les encaissements « à transmettre » ; si l'encaissement est annulé, la facture redevient due.
   - **Formats configurables (M) :** interface `FormatFichierPaiement` (SEPA, CSV, largeur fixe paramétrable par gabarit JSON : positions, longueurs, remplissage), stockée sur `comptes_bancaires.virement_format`. Ne pas coder Desjardins en dur.
   - **Statuts (P) :** `virement_statuts` (`tenant_id`, `code`, `libelle`, `ordre`, système / modifiable) et `POST /debourses/virements/statut` (`debourses[]`, `statut`), tracé, action `debourses.virements`.
   - **Chèque (P) :** langue = `fournisseurs.langue` si elle existe, sinon celle de l'écran. Option `comptes_bancaires.cheque_preimprime` avec des décalages en mm (zones variables seulement).
   - **Écran (P) :** compteurs de sélection sur `VirementsPage`. En option, un menu « Émission des paiements » qui réunit chèques, virements et prélèvements.
   - **Tests :** pain.008 valide (XSD), lot refusé, isolation.
   - Taille : G pour les prélèvements, P/M pour le reste.

### Payables › Rapports
Statut seulement ici ; voir le §4.

### Recevables › Gestion comptes à recevoir — **Partiel**
**Preuves PI :** `/factures` (statut, recherche, client, dates), `/comptes-clients` (analyse chronologique), `/relances` ; écrans `FacturesPage`, `ComptesClientsPage`, `NotesCredit`.

1. **Ce que fait fripv2**
   - **Liste des comptes à recevoir**, qu'ils viennent d'une facture ou soient saisis à la main :
     - colonnes et pastilles comme pour les comptes à payer ;
     - statuts Payé complet, Payé partiel, Impayé, En retard, Annulé ; pour un crédit : Remboursé, Remboursé en partie, À rembourser ;
     - filtres Statut et Âge, « soldes à 0 », recherche ; export ;
     - ligne dépliable : Ventilation GL, Facture d'origine, Encaissements appliqués ;
     - bouton Paiements reçus, filtré sur le client.
   - **Compte à recevoir manuel** (sans facture : intérêts, ajustement, **solde d'ouverture**, refacturation) :
     - client (charge la devise, la condition, le groupe de taxes et les balises) ;
     - **No facture de référence obligatoire** ;
     - devise (compte clients de la devise), condition obligatoire, date (période courante), montant calculé ou saisi ;
     - lignes ventilées sur les **comptes de revenus habituels du client** et ses comptes de taxes, avec calcul en sus ou extraction selon le compte ;
     - Sous-total / Taxes / Total égal au montant.
   - **Actions :** Renverser, Copier, Récurrence, Paiements reçus, Payer (ouvre un encaissement avec ce compte coché), Écritures, Documents, Historique.
   - **Règles :**
     - non modifiable s'il vient d'une facture ou s'il a reçu un encaissement ;
     - suppression d'une série récurrente : les suivantes, sauf les encaissées ;
     - un compte issu d'une facture a une vue articles et porte, en inventaire permanent, le coût des ventes et le stock.
2. **Ce que PI fait aujourd'hui**
   - Factures, notes de crédit, encaissements, relevé, analyse chronologique, relances.
   - Manquent :
     - **la pièce client sans produit, ventilée par compte** (`facture_lignes.produit_id` obligatoire, quantité entière) ;
     - la liste transversale avec Âge, « En retard », export ;
     - copie et récurrence d'une telle pièce ;
     - lien vers l'écriture ;
     - comptes de revenus par défaut du client.
3. **Ce qu'il faut faire** : ajouter des **lignes comptables aux factures** plutôt qu'un nouveau document. Ainsi émission, encaissement, relances, relevé et PDF suivent sans rien changer.
   - **Migration :**
     - `facture_lignes_comptables` : `tenant_id`, `facture_id`, `compte_comptable_id`, `libelle`, `montant_ht`, `groupe_taxe_id`, `non_taxable`, `montant_tva`, `montant_ttc`, `ordre` ;
     - `factures.nature` (`standard` par défaut | `comptable`) et `exclure_comptabilisation` (reprise de solde) ;
     - `client_comptes_produits` (T2).
   - **Service :** `FacturesVente::calculerLignes` additionne aussi les lignes comptables ; taxes avec `TaxesLignesComptables`. Route `POST /factures` avec `lignes_comptables[]`. `POST /factures/{id}/copier`.
   - **Comptabilisation (`ecritureVente`) :** chaque ligne comptable au crédit de son compte.
   - **Pièges :**
     - `NotesCredit` doit pouvoir créditer une ligne comptable : `avoir_lignes_comptables` ou crédit en montant ;
     - le portail client, le PDF et `RapportTaxes` doivent les afficher ;
     - `ReliquatsClients`, les bons de livraison et le stock doivent les **ignorer**.
   - **Liste :** filtres `age` et `en_retard`, export (T7).
   - **Écran :** dans l'éditeur de facture, un onglet « Lignes comptables » visible avec le droit `comptabilite.ecrire` ou un nouveau `factures.lignes_comptables` (base `modifier`).
   - **Tests :** taxes, notes de crédit, encaissement, rapport des taxes, isolation.
   - Taille : G.

### Recevables › Transactions par client — **Partiel**
**Preuves PI :** `GET /comptes-clients/{client}/releve?du=&au=&format=csv` (solde d'ouverture, mouvements, solde progressif, factures dues, crédits) ; bouton sur `ComptesClientsPage`.

1. **Ce que fait fripv2** : même relevé que « Transactions par fournisseur », côté clients (factures suivies de leurs encaissements, date d'analyse, « inclure à zéro », tranches 0–30 / 31–60 / 61–90 / 90+ sur la date de facture, export Excel). Aussi dans l'onglet Comptes à recevoir de la fiche client.
2. **Ce que PI fait aujourd'hui :** le relevé existe, sous une présentation chronologique. Manquent : les tranches d'âge en pied, la présentation « facture suivie de ses paiements », le XLSX ou PDF, l'onglet dans `FicheClientPage`.
3. **Ce qu'il faut faire**
   - `releve?presentation=par_facture&inclure_soldees=` et un bloc `ages` (base facture ou échéance) ;
   - `format=xlsx|pdf` (T5) ;
   - onglet « Comptes à recevoir » de la fiche client, droit `comptes_clients.voir`.
   - Taille : P.

### Recevables › Gestion encaissements — **Fait** (écarts mineurs)
**Preuves PI :** `GET /encaissements` (recherche, client, mode, statut, période, totaux) ; écran `EncaissementsPage`.

Écarts :
- filtre **Type** déduit : Paiement, Acompte, Remboursement ou note de crédit, Paiement excédentaire, Escompte, Autre compte GL, Arrondi de caisse ; utile pour justifier les écarts avec les dépôts bancaires ;
- colonne et filtre compte bancaire ;
- export (T7) ;
- lignes dépliables : factures, cartes cadeaux, acomptes.

À faire : paramètre `type` calculé dans `Encaissements` (escompte > 0, surplus > 0, `difference_compte_id`, acompte, montant < 0). Taille : P.

### Recevables › Entrée encaissement — **Partiel**
**Preuves PI :** `POST /encaissements`, `GET /encaissements/a-regler` ; composant `encaissements/FenetreEncaissement` (Répartir automatiquement, escompte au prorata, surplus en acompte, caisse, compte bancaire, reçu PDF, pièces jointes, annulation).

1. **Ce que fait fripv2**
   - **Écart entre le montant et la répartition**, une seule case parmi trois :
     - **Escompte sur vente** : si l'écart est négatif ; compte de la devise ;
     - **Compte du grand livre** au choix, avec une loupe qui ne propose que des comptes de détail : écart dans les deux sens ;
     - **Acompte au client** : si l'écart est positif.
   - **Sans aucune facture** : tout le montant va en acompte **ou sur un compte du grand livre** (recette diverse). L'escompte n'ouvre pas cette exception.
   - **Montant négatif = remboursement** au client, après confirmation, appliqué à une note de crédit (signe identique au document).
     - une note créée par « Créditer » se rembourse par les modes de paiement de la facture d'origine, au plus ce qui reste à rendre par chaque mode ;
     - jamais sur carte cadeau depuis cet écran ;
     - ni escompte ni acompte sur un remboursement.
   - **Arrondi de caisse** au 0,05, activé par devise (« Compte arrondi de caisse »).
     - règle : 0,01–0,02 vers le bas, 0,03–0,04 vers le haut… ;
     - seulement en espèces, jamais avec un escompte ;
     - l'écart va au compte d'arrondi ;
     - même règle pour un remboursement en espèces.
   - **Autres comportements :**
     - **bouton $** par facture pour appliquer un acompte du client ; tableau « Acomptes utilisés » ;
     - « Payer facture » multi-modes (espèces avec monnaie rendue ; trop-perçu seulement en espèces) ;
     - **modification** tant que l'encaissement n'est ni concilié ni en période fermée : le compte de banque est conservé, avec confirmation s'il diffère de celui du mode actuel ;
     - pré-remplissage depuis le flux bancaire ;
     - verrou, historique ;
     - comptes figés à la création (un changement de réglages ne déplace pas les écritures passées).
2. **Ce que PI fait aujourd'hui**
   - Escompte et surplus en acompte. Le multi-modes, la monnaie rendue et la carte cadeau existent au point de vente.
   - Manquent :
     - la différence sur un compte du grand livre et la **recette diverse** sans facture ;
     - le remboursement par encaissement négatif (PI passe par « Rembourser » l'avoir : acceptable) ;
     - **l'arrondi de caisse** ;
     - l'application d'un acompte depuis l'encaissement ;
     - la modification hors référence et notes ;
     - les **écarts de change** (T6) ;
     - la création depuis le relevé ;
     - le lien vers l'écriture.
3. **Ce qu'il faut faire**
   - **Migration :**
     - `encaissements.difference_compte_id` et `difference_montant` (signé) ;
     - `parametres_comptables.correspondances.arrondi` : par devise, `{pas: 0.05, compte_id}` ; pas de 0,05, 0,10, 1 ou 5 ; vide = pas d'arrondi ;
     - `encaissements.arrondi` et `facture_paiements.arrondi`.
   - **API :** `POST /encaissements` accepte `difference: {mode: escompte|compte|acompte, compte_id}` (le compte doit être de détail et ouvert à la saisie manuelle) et `lignes_acomptes[]` (acompte appliqué dans le même geste).
   - **Comptabilisation :** ligne sur le compte de différence ; ligne d'arrondi.
   - **Arrondi :** dans `VentesComptoir` et `CalculTaxes`, uniquement pour le mode `especes`.
   - **Tests :** recette diverse, différence au débit et au crédit, arrondi, isolation.
   - Taille : M.

### Recevables › Demandes d'acompte — **Fait** (écarts mineurs)
**Preuves PI :** `/demandes-acompte` (CRUD, `/envoyer`, `/encaisser`, `/pdf`) ; écran `DemandesAcomptePage` ; service `DemandesAcompte` (statuts brouillon / envoyée / payée, puis partiellement appliquée / appliquée).

Écarts :
- « S'applique à » : fripv2 accepte **plusieurs** devis, commandes et **factures** (table `demande_acompte_source`) ; PI un seul devis et une seule commande ;
- colonne « Appliquée sur » (numéros des factures) ;
- après un PDF, fripv2 propose de marquer la demande envoyée.

À faire : table `demande_acompte_documents` (`tenant_id`, `demande_id`, `type` devis|commande|facture, `document_id`, unicité) ; à l'encaissement, réserver l'acompte sur ces documents (voir « Acomptes »). Taille : P.

### Recevables › Acomptes — **Partiel**
**Preuves PI :** `/acomptes` (CRUD, `/appliquer`, `/rembourser`) ; écran `AcomptesPage` ; service `Acomptes` (réservé à **une** commande ; états disponible / entamé / utilisé).

1. **Ce que fait fripv2**
   - Un acompte se **réserve en partie sur plusieurs documents** (commandes **et** factures, une part par document, table `transaction_acompte_reservation`).
     - Case Réserver avec un montant réservé ramené au disponible ; un acompte saisi depuis un document naît réservé à ce document ; on peut décocher pour le libérer.
     - À la facture, la part réservée est appliquée d'office et le surplus redevient un crédit libre.
   - Un acompte peut être saisi depuis une **facture**.
   - Le compte d'avances et le compte de banque sont **figés à la création** (`id_compte_gl_acompte`, `id_compte_gl_bnq`).
   - Liste : colonnes Disponible, Utilisé, Remboursé, Réservé à ; second onglet avec les demandes ; onglet Acomptes dans la fiche client.
   - Le remboursement se fait par le mode du dépôt.
2. **Ce que PI fait aujourd'hui :** une réservation unique à une commande, application et remboursement. Manquent la réservation multiple et partielle, la réservation sur une facture et l'onglet de la fiche client.
3. **Ce qu'il faut faire**
   - Table `acompte_reservations` : `tenant_id`, `acompte_id`, `type` (`commande|facture`), `document_id`, `montant`, `cree_par`. On garde `acomptes.commande_id` pour compatibilité et on le recopie à la migration.
   - Routes `POST /acomptes/{id}/reservations` et `DELETE /acomptes/{id}/reservations/{rid}`, droit `acomptes.appliquer`.
   - `FacturesVente` (émission ou paiement) applique les parts réservées.
   - Piège : le disponible devient montant − appliqué − remboursé − réservé ailleurs.
   - Onglet « Acomptes » de `FicheClientPage`.
   - Taille : M.

### Recevables › Cartes cadeaux — **Partiel**
**Preuves PI :** `/cartes-cadeaux` (vente, `/recharger`, `/rembourser`, `/certificat`), `/point-de-vente/carte-cadeau` ; écran `CartesCadeauxPage` ; service `CartesCadeaux`. Comptabilisation : seul l'**usage** est passé (`ecriturePaiementClient`, compte `compte_cartes_cadeaux_id`).

1. **Ce que fait fripv2**
   - Carte au porteur ; numéro automatique ou saisi (pré-imprimé) et jamais réutilisé ; devise figée ; expiration facultative.
   - Statuts Active / Épuisée / Expirée / Annulée ; mouvements vente, recharge, utilisation, remboursement, et **« Annulation d'utilisation »** quand un paiement par carte est supprimé (la carte est recréditée).
   - Remboursement total seulement, qui annule la carte. **Refusé pour une carte vendue sur facture** (« remboursez la facture »).
   - **Vente sur facture** (à crédit) : le produit « carte cadeau » de l'entreprise (non inventorié, sans taxe) sert de ligne de facture, et la carte garde la facture d'origine (lien).
   - Comptablement, un mode de paiement « carte cadeau » par devise porte le **compte de passif** : vente et recharge au crédit du passif, utilisation et remboursement au débit.
2. **Ce que PI fait aujourd'hui**
   - Vente directe, recharge, remboursement, paiement, certificat, solde à honorer.
   - **La vente, la recharge et le remboursement ne sont pas comptabilisés** : le passif ne reçoit que des débits.
   - Pas de vente sur facture.
3. **Ce qu'il faut faire**
   - **Comptabilisation**, types `carte_cadeau_vente`, `carte_cadeau_recharge`, `carte_cadeau_remboursement` (sur `carte_cadeau_mouvements`) :
     - vente et recharge : caisse ou banque selon le mode au débit, passif au crédit ;
     - remboursement : l'inverse.
   - **Vente sur facture :** `parametres.produit_carte_cadeau_id` (service, sans taxe) et `cartes_cadeaux.facture_id`. La carte est créée à l'émission de la facture. Pas de remboursement direct ; un avoir sur cette ligne annule la carte.
   - **Comptabilisation de la facture :** la ligne carte cadeau va au passif, pas aux ventes (règle par produit).
   - **Tests :** équilibre du passif après vente, usage et remboursement ; isolation.
   - **Piège :** les mouvements antérieurs à la date de début de comptabilisation ne se passent pas.
   - Taille : M.

### Recevables › Gestion de caisse — **Fait**
**Preuves PI :** `/caisses`, `/caisses/{id}/ouvrir`, `/caisses/sessions/{id}/mouvements|fermer|pdf` ; écran `CaissesPage` ; services `Caisses` et `SommaireCaissePdf`.

Équivalent : fond, entrées, sorties avec raison obligatoire, attendu par mode et par devise, fermeture figée, sommaire, encaissements par employé, encaissements hors caisse, encaissement bloqué si la caisse est fermée, ouverture proposée.

Optionnel (fripv2 ne le fait pas non plus) : comptabiliser les sorties et l'écart de caisse (compte « écarts de caisse »). Taille : P.

### Recevables › Rapports
Statut seulement ici ; voir le §4.

### Financier › Balance de vérification — **Partiel**
**Preuves PI :** `GET /comptabilite/balance?du=&au=&format=csv` ; écran `BalanceComptablePage` (solde d'ouverture, mouvements, solde final, sous-totaux par classe, « Balance équilibrée », lien vers le grand livre).

1. **Ce que fait fripv2**
   - **Choix :** cycle et dates (bornes du cycle par défaut) ; un compte ou tous ; « Inclure les comptes dont le solde est à zéro ».
   - **Vue simplifiée** (par défaut) : seulement le solde de clôture « Au [date] ».
   - **Avec détails** : chaque écriture sous son compte, avec numéro et **code source** (GL, CAR, CAP, ENC, PAY, BNQ, ACC, ACF, DEP).
   - Débits et crédits de la période séparés ; un compte d'en-tête, inactif ou supprimé qui porte un solde est affiché avec « (à corriger) ».
   - Sorties : écran, PDF, imprimante, Excel, courriel ; filtres enregistrés ; ligne de rappel des critères ; historique des sorties.
2. **Ce que PI fait aujourd'hui :** le cœur, en CSV. Manquent les détails, la vue simplifiée, le filtre d'un compte, les comptes à zéro, la mention « (à corriger) », le PDF, le XLSX et le courriel, les filtres enregistrés, le choix par exercice ou période.
3. **Ce qu'il faut faire**
   - `GET /comptabilite/balance` avec `compte_id`, `zero=1`, `details=1`, `simplifiee=1`, `format=pdf|xlsx` et l'envoi par courriel (T5).
   - `Soldes::balance` signale `a_corriger` (compte d'en-tête ou inactif qui porte un solde).
   - Tests : détails égaux au total, isolation.
   - Taille : M.

### Financier › Bilan — **Partiel**
**Preuves PI :** `GET /comptabilite/etats/bilan?date=&format=csv` ; écran `EtatsFinanciersPage` ; service `EtatsFinanciers` (regroupement **sous l'en-tête le plus proche**, résultat non clôturé, « Bilan équilibré »).

1. **Ce que fait fripv2**
   - Bilan arrêté à la fin d'une **période** (période courante par défaut), avec un raccourci Fréquence (Année, Semestre, Trimestre, Mensuel).
   - **Hiérarchie complète** : un sous-total « Total … » à chaque niveau.
   - Passif et capital affichés avec le signe inversé ; montants négatifs entre parenthèses.
   - Mode **« Entêtes seulement »** (grandes sections et totaux) ; option des comptes à zéro (les regroupements restent toujours) ; compte « Bénéfice net en cours » toujours affiché.
   - Avertissement « Le bilan ne balance pas : actif …, passif et capital …, écart … ».
   - Sorties PDF, Excel, courriel ; filtres enregistrés ; sections repliables à l'écran.
2. **Ce que PI fait aujourd'hui :** le bilan équilibré, sur une seule profondeur de regroupement, en CSV.
3. **Ce qu'il faut faire**
   - `EtatsFinanciers::bilan` construit l'arbre complet par `parent_id` avec un sous-total par nœud ;
   - paramètres `niveau=entetes`, `zero=1`, `periode_id`, `format=pdf|xlsx` (T5) ;
   - en option, une colonne N-1 (PI peut faire mieux que fripv2).
   - Tests : arbre à 4 niveaux, isolation.
   - Taille : M.

### Financier › État des résultats — **Partiel**
**Preuves PI :** `GET /comptabilite/etats/resultat?du=&au=&format=csv` (clôture exclue).

1. **Ce que fait fripv2**
   - Une période, ou des **périodes cumulées** (début et fin), avec le raccourci Fréquence. Les trimestres et semestres se découpent par **numéro** de période ; une 13ᵉ période va au dernier trimestre.
   - **Comparaison avec le cycle précédent** : colonne Précédent, pour les périodes de même numéro (zéro si la période n'existe pas).
   - **Filtre par balises** : au moins une des balises choisies ; les soldes de départ sont alors ignorés.
   - Hiérarchie complète, Entêtes seulement, comptes à zéro (un compte à zéro cette année mais avec un solde l'an passé reste affiché).
   - Revenus en positif ; Bénéfice net par colonne.
   - Sorties PDF, Excel, courriel ; filtres enregistrés ; historique des sorties (courriel avec ses destinataires).
2. **Ce que PI fait aujourd'hui :** une période par dates, une profondeur de regroupement, CSV.
3. **Ce qu'il faut faire**
   - paramètres `comparer=exercice_precedent` (périodes de même numéro, sinon mêmes dates moins un an), `balises[]` (T3), `niveau`, `zero`, `format` ;
   - en option, une colonne budget (le service `Budgets` existe).
   - Tests : comparatif, balises, isolation.
   - Taille : M, plus T3 pour les balises.

### Banque › Transactions bancaires — **Partiel**
**Preuves PI :** `GET|POST /banque/transactions`, `GET /banque/transactions/{id}`, `POST /banque/transactions/{id}/annuler` ; onglet Transactions de `BanquePage` ; service `TransactionsBancaires` (dépôt, retrait, virement ; lignes compte / libellé / montant ; numéro TB ; annulation avec contre-passation).

1. **Ce que fait fripv2**
   - **Types** (table `type_trans_bancaire` : `flux` 1 entrée / 2 sortie, `saisie_manuelle`) : Dépôt, Paiement par chèque, Retrait automatique, Paiement Internet, Transfert banque (sortant). Le type change les libellés « No client / No fournisseur » et « No chèque / No référence ».
   - **Tiers** facultatif (client si entrée, fournisseur si sortie ; un tiers inactif est refusé) : il **pré-remplit les lignes** avec ses comptes par défaut et ses taxes (avec confirmation si des lignes sont déjà saisies).
   - **Taxes calculées** selon le mode de chaque compte et le groupe du tiers ; Sous-total / Taxes / Total.
   - Transfert : toutes les lignes doivent être des comptes de banque.
   - Date de conciliation affichée.
   - **Modifier** (si saisie dans cet écran et non conciliée), **Renverser** (inverse datée du jour, « rappelle le numéro d'origine »), **Récurrence**, Commentaires, Documents, Historique, balises.
   - Contrôles : devise identique à celle du compte de banque ; saisie manuelle permise ; montant égal au total ; période courante ou fermée.
   - Les encaissements et déboursés apparaissent en lecture seule (sources ENC et PAI).
   - Liste : compte, dates, flèches de période, source, numéro de récurrence, export.
2. **Ce que PI fait aujourd'hui :** dépôt, retrait et virement simples, annulation, pièces jointes, journal des modifications. Manquent : le tiers, les taxes, des types plus fins, la modification, la récurrence, les balises, plusieurs comptes destinataires.
3. **Ce qu'il faut faire**
   - **Migration :**
     - `transactions_bancaires` : `nature` (`depot|cheque|prelevement|internet|transfert|autre`, libre), `client_id`, `fournisseur_id`, `groupe_taxe_id`, `recurrence_id` ;
     - `transaction_bancaire_lignes` : `non_taxable`, `groupe_taxe_id`, `tenant_id` (T8), et lignes de taxe marquées `taxe_id`.
   - **API :**
     - `PUT /banque/transactions/{id}` : modifiable si valide, non comptabilisée **ou** non pointée et période ouverte ; recomptabilisation par contre-passation puis nouvelle écriture ;
     - `POST /banque/transactions/{id}/recurrence` ;
     - `GET /banque/transactions/prefill?client_id=|fournisseur_id=` (comptes par défaut et taxes, T2).
   - **Droits (`PARTICULIERES`) :** `PUT api/banque/transactions/{id}` vers `banque.saisir` ; `POST …/recurrence` vers `comptabilite.recurrences`.
   - **Comptabilisation :** les lignes de taxe vont aux comptes de taxe ; `RapportTaxes` les inclut.
   - **Tests :** taxes en sus ou incluses, modification après pointage refusée, isolation.
   - Taille : M.

### Banque › Conciliation bancaire — **Fait**
**Preuves PI :** `/banque/comptes/{id}/rapprochements`, `/banque/rapprochements/{id}` (`pointer`, `terminer`, `rouvrir`, `pdf`, `pointer-releve`) ; services `Rapprochements` et `RapprochementPdf`.

PI fait mieux : solde du relevé imposé, écart nul exigé, réouverture, verrous.

Écarts :
- **cocher toute une journée** (ligne « Total du … ») : P ;
- vues Dépôts / Retraits / Combinée (PI a une liste unique filtrable) ;
- **compte en devise étrangère** rapproché dans sa devise : T6.

### Banque › Flux bancaire — **Partiel**
**Preuves PI :**
- relevés importés par fichier : `POST /banque/comptes/{id}/releves` (OFX, CAMT, CSV, aperçu, dédoublonnage), `GET /banque/comptes/{id}/releve`, `/releve-lignes/{id}/candidats|relier|delier|exclure|retablir|classer` ;
- services `RelevesBancaires` et `LecteurReleves`.

1. **Ce que fait fripv2**
   - **Connexion bancaire directe** (Plaid) : import par tâche planifiée, une fois par jour, 30 jours d'historique à la première connexion.
     - transactions en attente à la banque ignorées ;
     - **bandeau d'alerte** si la connexion demande une nouvelle authentification ou si le dernier import a échoué (avec la date du dernier import réussi) ;
     - **solde transmis par la banque** dans le pied de liste ;
     - statut « **Retiré par la banque** ».
   - **Compteur** de lignes à traiter dans le menu (rafraîchi toutes les 15 minutes) et **notification** « N transactions bancaires à traiter » avec un bouton Ouvrir (désactivable par usager).
   - **Correspondance :**
     - meilleure correspondance unique (même compte, même montant, même sens, ±5 jours, non revendiquée ailleurs) ;
     - correspondances possibles à ±30 jours, recherche sur toutes les dates ;
     - sélection multiple avec Sélection et Écart ;
     - statuts Non relié / Partiellement relié / Relié / Exclu / Retiré ;
     - document supprimé signalé « ⚠ » ; dépassement affiché.
   - **Classer manuellement** :
     - **Encaissement** (montant négatif pour un retrait : remboursement au client) ;
     - **Paiement fournisseur** (déboursé ; montant négatif pour un dépôt) ;
     - **Dépense** ou **Transaction bancaire** ;
     - **Écriture GL** (ligne de banque pré-remplie du bon côté) ;
     - tout est pré-rempli puis **relié d'office** si l'enregistrement touche la même banque dans le même sens ; sinon un message ;
     - chaque type n'est proposé qu'avec le droit de créer le document.
   - **Historique par ligne** (qui a relié, délié, exclu ou rétabli ; jamais le montant ni le libellé).
   - Permissions : « Traiter le flux bancaire » et « Configurer les comptes du flux bancaire ».
2. **Ce que PI fait aujourd'hui**
   - Le rapprochement par fichier est complet. PI fait mieux sur un point : il pointe le relié au rapprochement.
   - Manquent :
     - le **connecteur** d'agrégation et la tâche d'import ;
     - le bandeau de santé et le solde de la banque ;
     - le statut « retiré par la banque » ;
     - **classer en encaissement, déboursé ou écriture GL** (PI ne crée qu'une transaction bancaire) ;
     - le compteur et les notifications ;
     - l'historique des actions par ligne (`releve_bancaire_lignes` n'a pas d'auteur ; `releve_bancaire_liens` n'a pas de `tenant_id`).
3. **Ce qu'il faut faire**
   - **Connecteur générique (G) :**
     - interface `App\Services\Banque\Connecteurs\Connecteur` (`comptes()`, `transactions(depuis)`, `solde()`), avec des pilotes optionnels (Plaid, GoCardless Bank Account Data, Nordigen…) ;
     - tables `connexions_bancaires` (`tenant_id`, `fournisseur`, `jeton` **chiffré**, `statut`, `erreur`, `derniere_synchro`, `curseur`) et `comptes_connectes` (`tenant_id`, `connexion_id`, `identifiant_externe`, `nom`, `masque`, `type`, `devise`, `compte_bancaire_id`, `actif`) ;
     - `releves_bancaires.source` (`fichier|connecteur`) et `connexion_id` ;
     - tâche `releves_bancaires` (quotidienne) ;
     - action `banque.connecter` (base `supprimer`).
     - Hors périmètre : Plaid en dur.
   - **Classer en document (M) :**
     - `POST /banque/releve-lignes/{id}/classer-document` (`type=encaissement|debourse|ecriture|transaction`) renvoie un pré-remplissage ;
     - à l'enregistrement du document, paramètre `releve_ligne_id` : relier si même compte et même sens, sinon un avertissement ;
     - le contrôleur vérifie `encaissements.creer`, `debourses.creer`, `comptabilite.ecrire` ou `banque.saisir` ;
     - **piège** : la liaison exige que le document soit passé au journal : comptabiliser le document tout de suite, comme `classer` le fait déjà.
   - **Compteur (P) :** `GET /banque/releve/a-traiter` (total tous comptes) affiché sur l'entrée de menu ; notification par la cloche existante.
   - **Historique (P) :** `releve_bancaire_lignes.exclue_par` et `exclue_le`, `releve_bancaire_liens.relie_par`, `tenant_id` (T8), plus un `AuditLog` sur relier, délier, exclure, rétablir.
   - Taille : G pour le connecteur, M pour le reste.

### Banque › Comptes du flux bancaire — **Partiel**
**Preuves PI :** `GET /banque/comptes`, `PUT /banque/comptes/{id}` (compte comptable de trésorerie unique, journal, droit `banque.configurer`), référentiel des comptes bancaires.

1. **Ce que fait fripv2**
   - Liste des comptes **connectés** : Banque, Compte (nom et 4 derniers chiffres), Type et sous-type, Devise, Compte du grand livre ou « **À associer** », Institution.
   - Pas d'ajout ni de suppression : la liste suit la connexion.
   - La fiche ne propose que des comptes de banque actifs **dans la devise du compte**.
   - Messages :
     - « Les transactions déjà rattachées à l'ancien compte du grand livre y restent ; seules les prochaines iront au nouveau. » ;
     - retrait de l'association : les prochaines transactions sont « non associées » ;
     - « La devise du compte bancaire est différente de celle du compte du grand livre choisi. ».
   - Bandeau « Le flux bancaire n'est pas activé pour cette base. » ; rappel dans « Configuration à compléter ».
2. **Ce que PI fait aujourd'hui :** l'association compte bancaire ↔ compte comptable existe. Rien pour des comptes connectés.
3. **Ce qu'il faut faire**
   - avec le connecteur : un onglet « Comptes connectés » dans `BanquePage`, `PUT /banque/comptes-connectes/{id}` (`compte_bancaire_id`), contrôle de devise, messages ci-dessus ;
   - une entrée dans `ConfigurationACompleter` (« comptes connectés à associer »).
   - Taille : M.

### Clients (vue comptable) — **Partiel**
**Preuves PI :** `/clients`, `ClientFicheController`, écran `FicheClientPage` (Synthèse : encours, retard ; Historique ; Contacts ; Adresses ; Remarques) ; `encours_max` contrôlé à l'acceptation de commande seulement (`CommandeClientController`).

1. **Ce que fait fripv2**
   - Onglets en lecture seule : **Comptes à recevoir** (relevé, export), **Encaissements** (paiements par facture et « Sans facture » ; total appliqué et total reçu ; impression), **Acomptes** (disponible, utilisé, remboursé, réservé à, détail d'utilisation, demandes), **Tableau de bord** (sommaires et graphique d'âge).
   - Dossier :
     - **comptes de revenus** du client ;
     - limite de crédit **qui bloque l'enregistrement de la facture**, débloquée par le mot de passe du client ;
     - état de compte imprimé ou par courriel ;
     - rappel de paiement et fréquence par client.
   - **Info bancaire** et « Transfert électronique activé » (prélèvement).
   - Aperçu dépliable dans la liste : comptes à recevoir, encaissements, cartes cadeaux.
2. **Ce que PI fait aujourd'hui :** synthèse et encours. Manquent les onglets comptables, les comptes de revenus, les coordonnées bancaires et le mandat, la préférence d'envoi du relevé et le rythme des relances par client, le blocage à la facture.
3. **Ce qu'il faut faire**
   - Onglets « Comptes à recevoir » (voir « Transactions par client »), « Encaissements » (`/encaissements?client_id`), « Acomptes » et « Cartes cadeaux » dans `FicheClientPage`.
   - « Comptabilité » : comptes de revenus et groupe de taxes (T2).
   - « Coordonnées bancaires » : voir « TFE - Impression de chèques ».
   - Colonnes `clients.releve_envoi` (`courriel|imprime`), `relance_auto` et `relance_frequence_jours`, lues par la tâche `relances_paiement`.
   - **Option** blocage de la facture au-delà de l'encours, avec l'action `factures.depasser_credit` : à arbitrer avec l'agent Ventes.
   - Taille : M.

### Fournisseurs (vue comptable) — **Partiel**
**Preuves PI :** `/fournisseurs`, écran `FicheFournisseurPage` (Synthèse, Historique, Produits fournis, Contacts, Adresses, **Coordonnées bancaires**, Remarques).

1. **Ce que fait fripv2**
   - Onglets **Comptes à payer** (relevé et âge) et **Déboursés** (par facture, total, impression) ; **Tableau de bord** (commandes, comptes à payer, déboursés, graphique d'âge).
   - Dossier : **comptes de dépense**, groupe de taxes, limite de crédit, case TFE.
   - Balises par défaut posées sur les lignes de comptes à payer.
   - Aperçu dépliable dans la liste : comptes à payer et paiements.
2. **Ce que PI fait aujourd'hui :** les coordonnées bancaires existent (en mieux : alerte de fraude). Manquent le relevé, les déboursés par fiche, les comptes de charge, le groupe de taxes et les balises par défaut.
3. **Ce qu'il faut faire**
   - Onglets « Comptes à payer » (voir « Transactions par fournisseur ») et « Déboursés » (`/debourses?fournisseur_id`) ;
   - « Comptabilité » : comptes de charge et groupe de taxes (T2) ;
   - balises par défaut recopiées sur les lignes (T3).
   - Taille : M.

### Comptabilisation automatique (transverse, vérification de la phase 4.3) — **Partiel**
**Preuves PI :** `App\Services\Comptabilite\Comptabilisation` (`TYPES` : facture, avoir, paiement client, encaissement, acompte, remboursements, facture fournisseur, paiement fournisseur, déboursé, transaction bancaire), `parametres_comptables` (comptes uniques et correspondances mode / taxe / catégorie), tâche `comptabilisation`, écran `ComptabilisationPage`.

1. **Ce que fait fripv2**
   - L'écriture naît **à l'enregistrement** du document.
   - **Département** du produit : 4 comptes (revenu, **stock**, **coût des marchandises vendues**, dépense d'achat directe).
     - Le coût des ventes (débit coût des ventes, crédit stock) n'est passé que si le département a **à la fois** un compte de stock et un compte de coût des ventes ; sinon, avertissement d'inventaire périodique.
     - À l'achat : compte de stock en inventaire permanent, de dépense en périodique.
   - Comptes de la devise : clients, fournisseurs, acompte, escomptes, transport, arrondi.
   - Modes de paiement liés à un compte du grand livre.
   - Comptes **figés** sur les documents : compte d'achat de la réception, comptes de l'acompte.
2. **Ce que PI fait aujourd'hui :** comptabilisation différée et robuste. Manquent :
   - (a) stock et coût des ventes, ajustements et écarts d'inventaire ;
   - (b) cartes cadeaux (vente, recharge, remboursement) ;
   - (c) avoirs fournisseurs ;
   - (d) écarts de change ;
   - (e) arrondi de caisse et différence d'encaissement ;
   - (f) frais d'achat et lignes ventilées ;
   - (g) comptes déterminés **au moment de la comptabilisation** : si la catégorie d'un produit ou un réglage change entre-temps, le compte change ;
   - (h) pas d'option de comptabilisation immédiate.
3. **Ce qu'il faut faire**
   - **(a) Inventaire permanent (G) :**
     - `parametres_comptables.methode_inventaire` (`periodique` par défaut | `permanent`) ;
     - correspondances par catégorie : `stock`, `cmv` ;
     - comptes `compte_stock_id`, `compte_cmv_id`, `compte_ecarts_inventaire_id` ;
     - types `sortie_vente` (débit coût des ventes, crédit stock, au coût de `ValorisationInventaire`, à l'émission de la facture ou à la livraison, selon un réglage) et `ajustement_stock` (prises d'inventaire validées, mouvements avec raison) ;
     - en permanent, `ecritureAchat` débite le stock au lieu des achats ;
     - **pièges** : choisir une seule source du coût (coût moyen pondéré) ; éviter la double sortie facture / bon de livraison ; les retours clients remis en stock passent à l'inverse.
   - **(b) à (f)** : traités dans les sections Cartes cadeaux, Gestion comptes à payer, T6, Entrée encaissement, Frais d'achat.
   - **(g)** Figer le compte sur les lignes de document à leur création : `compte_comptable_id` facultatif sur `facture_lignes`, `reception_arrivages_ligne` et `acomptes`, renseigné quand la comptabilisation est réglée.
   - **(h)** Option `parametres_comptables.immediate` : après l'enregistrement du document, `comptabiliserDocument()` en tâche de fond ; en cas d'échec, il reste « à comptabiliser ».
   - **Tests :** chaque nouveau type, contre-passation à l'annulation, isolation.

---

## 4. Rapports des sous-menus (statut seulement)

| Rapport fripv2 | Statut dans PI | Ce qui existe / ce qui manque |
|---|---|---|
| Journal général | Fait | `JournalComptablePage` et `/comptabilite/ecritures/export` (CSV des lignes). Pas de PDF ni de XLSX avec détails. |
| Analyse par compte | Fait | `GrandLivrePage`, `/comptabilite/grand-livre` (CSV). |
| Plan comptable (rapport) | Partiel | Seulement `/comptabilite/comptes/export` (CSV) ; pas de PDF ni XLSX, pas de filtre actifs/inactifs à l'export. |
| Rapport des taxes | Fait | `RapportTaxesPage`, CSV et PDF. Ni courriel ni filtres enregistrés. |
| Rapport fournisseurs (liste avec solde à payer) | Manquant | Rien dans la bibliothèque `App\Rapports` (`AchatsParFournisseur` n'a pas les soldes). |
| Analyse chronologique des comptes à payer | Manquant | Voir « Transactions par fournisseur ». |
| Paiements dus | Fait | `PaiementsDusPage`, `/debourses/paiements-dus` (CSV), paiement d'une sélection. |
| Rapport clients (liste avec limite et solde) | Partiel | Soldes via `/comptes-clients` ; pas de liste avec la limite de crédit. |
| Analyse chronologique des comptes à recevoir | Partiel | `/comptes-clients` (tranches sur l'échéance, CSV) ; pas de détail par facture, pas de PDF. |
| Factures non portées au compte à recevoir | Équivalent | Dans PI une facture émise est due tout de suite. L'équivalent comptable est l'aperçu « à comptabiliser » (`/comptabilisation/apercu`). |
| État de compte | Partiel | Relevé écran et CSV ; pas de PDF, **pas d'envoi par courriel en lot** à chaque client (impression pour ceux sans courriel). |
| Liste des encaissements et déboursés d'un tiers | Partiel | `EncaissementsParMode` ; pas de liste par tiers reliée aux factures. |

---

## 5. Tableau récapitulatif

| # | Option fripv2 | Statut PI | Preuve PI | Écarts principaux | Taille |
|---|---|---|---|---|---|
| 1 | Plan comptable | Partiel | `/comptabilite/comptes*`, `PlanComptablePage` | devise, mode de taxes, traductions, historique et verrou, XLSX et colonnes d'import, protections | P (+T2/T4/T6) |
| 2 | Transactions manuelles | Partiel | `/comptabilite/ecritures*`, `JournalComptablePage` | copier, filtre montant, export détaillé, suppression de série, date future, devise, balises, pièces jointes, verrou, historique, écriture depuis le relevé | M |
| 3 | Cycle comptable | Partiel | `/comptabilite/exercices*`, `/periodes/*` | périodes libres (13, 4-4-5, hebdo), période courante et droit en période fermée (T1), création guidée de l'exercice suivant, solde du compte de résultat avant et après | M |
| 4 | Budgets | Fait | `/budgets*`, `BudgetsPage` | (PI fait mieux) | — |
| 5 | Soldes de départ | Partiel | `/comptabilite/soldes-ouverture` | reprise en cours d'exercice (comptes de résultat), import CSV, soldes des tiers | M |
| 6 | Transactions récurrentes | Partiel | `/comptabilite/recurrences*`, `EcrituresRecurrentesPage` | sources comptes à recevoir, comptes à payer et banque ; correction et suppression par occurrence ; bi-mensuelle ; 9999 ; conversion en lot avec compte-rendu ; `tenant_id` | G |
| 7 | Gestion comptes à payer | Partiel | `/factures-fournisseurs*` | ventilation par compte, Non taxable, comptes de charge du fournisseur, **avoirs et renversement**, copier, récurrence, Âge et export, vue GL/articles, transport | G |
| 7b | Frais d'achat | Manquant | `frais` (vente seulement), `reception_frais` (coûts annexes) | référentiel achat complet (portée, mode, base quantité/poids/volume, fixe ou %, min et max, comptabilisation, exclusions, compte de charge, tarif par fournisseur, instantanés, signe, renversement) | G |
| 8 | Transactions par fournisseur | Manquant | — | relevé fournisseur, âge, onglet de la fiche, analyse chronologique des comptes à payer | M |
| 9 | Gestion déboursés | Fait | `/debourses`, `DeboursesPage` | export, filtre par compte bancaire | P |
| 10 | Entrée déboursé | Partiel | `POST /debourses`, `FenetreDebourse` | remboursement reçu (négatif), imputation des avoirs, lien vers l'écriture, verrou | M |
| 11 | TFE - Impression de chèques | Partiel | `/debourses/cheques*`, `/debourses/virements*` | **prélèvements clients**, statuts configurables, formats génériques, chèque dans la langue du fournisseur et préimprimé, compteurs | G |
| 12 | Gestion comptes à recevoir | Partiel | `/factures`, `/comptes-clients` | **lignes comptables sans produit**, Âge, « En retard », export, copie, récurrence, lien vers l'écriture | G |
| 13 | Transactions par client | Partiel | `/comptes-clients/{id}/releve` | tranches d'âge, présentation par facture, XLSX et PDF, onglet de la fiche | P |
| 14 | Gestion encaissements | Fait | `/encaissements`, `EncaissementsPage` | filtre Type, filtre compte bancaire, export | P |
| 15 | Entrée encaissement | Partiel | `POST /encaissements`, `FenetreEncaissement` | différence et recette diverse sur un compte, **arrondi de caisse**, acompte dans l'encaissement, écarts de change, création depuis le relevé | M |
| 16 | Demandes d'acompte | Fait | `/demandes-acompte*` | plusieurs documents (dont des factures) dans « S'applique à » | P |
| 17 | Acomptes | Partiel | `/acomptes*` | réservations partielles multiples (commandes et factures), onglet de la fiche client | M |
| 18 | Cartes cadeaux | Partiel | `/cartes-cadeaux*` | **comptabilisation de la vente, de la recharge et du remboursement**, vente sur facture | M |
| 19 | Gestion de caisse | Fait | `/caisses*`, `CaissesPage` | (option : comptabiliser les écarts) | — |
| 20 | Balance de vérification | Partiel | `/comptabilite/balance` | détails avec sources, vue simplifiée, un compte, comptes à zéro, « (à corriger) », PDF, XLSX, courriel, filtres enregistrés | M |
| 21 | Bilan | Partiel | `/comptabilite/etats/bilan` | hiérarchie à plusieurs niveaux, Entêtes seulement, comptes à zéro, période et fréquence, PDF, XLSX, courriel | M |
| 22 | État des résultats | Partiel | `/comptabilite/etats/resultat` | comparatif N-1, balises, Entêtes seulement, hiérarchie, PDF, XLSX, courriel | M (+T3) |
| 23 | Transactions bancaires | Partiel | `/banque/transactions*` | tiers et pré-remplissage, **taxes**, types fins, modification, récurrence, balises | M |
| 24 | Conciliation bancaire | Fait | `/banque/rapprochements*` | coche par journée ; devise étrangère (T6) | P |
| 25 | Flux bancaire | Partiel | `/banque/comptes/{id}/releve(s)`, `/releve-lignes/*` | **connecteur** et tâche, alertes, solde de la banque, classer en encaissement, déboursé ou écriture, compteur et notifications, historique | G |
| 26 | Comptes du flux bancaire | Partiel | `GET/PUT /banque/comptes` | comptes connectés « À associer », contrôle de devise, messages | M |
| 27 | Clients (vue comptable) | Partiel | `FicheClientPage` | onglets comptes à recevoir, encaissements, acomptes ; comptes de revenus ; coordonnées bancaires et mandat ; préférences d'état de compte et de relance | M |
| 28 | Fournisseurs (vue comptable) | Partiel | `FicheFournisseurPage` | onglets comptes à payer et déboursés, comptes de charge, groupe de taxes, balises par défaut | M |
| — | Comptabilisation (phase 4.3) | Partiel | `Comptabilisation` | stock et coût des ventes, cartes, avoirs fournisseurs, change, arrondi, frais, comptes figés, option immédiate | G |
| — | Socle T1 à T8 | — | — | périodes, taxes et comptes par défaut, balises, traçabilité, sorties des états, devises, exports, hygiène tenant | M à G |

**Ordre conseillé :**
1. T8 (hygiène tenant), T4 (traçabilité) et T2 (taxes et comptes par défaut).
2. Gestion comptes à payer (avoirs, ventilation), puis Frais d'achat.
3. Lignes comptables des factures (Gestion comptes à recevoir) et Transactions bancaires taxées.
4. Transactions récurrentes multi-sources.
5. Relevés et âges des tiers (comptes à payer et à recevoir).
6. Comptabilisation : cartes cadeaux, avoirs, change, puis inventaire permanent.
7. États : T5, hiérarchie, comparatif, puis balises (T3).
8. Prélèvements clients, puis connecteur bancaire.
