# Flow — Bibliothèque des rapports

> Plan de parité fripv2, phase 5.1 (bibliothèque) et sorties de fichiers de la phase 5.2. L'envoi par courriel et la
> planification des rapports viendront ensuite.

Menu Inventaire → **Bibliothèque de rapports** (`/bibliotheque-rapports`). Les rapports sont rangés par domaine :
ventes, clients, achats, inventaire, finance. On choisit un rapport, on règle ses paramètres, puis on l'affiche ou on
le télécharge.

## Droits

- Il faut le droit « Rapports » (voir) pour ouvrir la bibliothèque et chacun de ses rapports.
- Chaque rapport demande en plus le droit de voir son module (colonne « Droit » ci-dessous). Un rapport non permis
  n'apparaît pas, et son adresse répond « introuvable ».
- Les domaines ventes, clients et finance demandent le module Ventes de l'entreprise ; les achats, le module Achats.

## Rapports

Les montants sont en devise de l'entreprise : montant de chaque document × son taux de change. Les ventes comptent
les factures émises (émises, payées en partie ou en totalité, créditées), pas les brouillons ni les annulées.
La période va par défaut du premier jour du mois à aujourd'hui ; une période inversée est remise dans l'ordre.

| Domaine | Rapport | Paramètres | Droit |
|---|---|---|---|
| Ventes | Ventes par période | période, regroupement (jour, semaine ISO, mois) | Factures |
| Ventes | Ventes par client (payé, reste à payer) | période, nombre de clients (50) | Factures |
| Ventes | Ventes par produit (quantités en unités de stock) | période, catégorie de produits, nombre de produits (100) | Factures |
| Ventes | Ventes par catégorie | période | Factures |
| Ventes | Devis et transformation (part des acceptés parmi les devis sortis) | période | Devis |
| Ventes | Commandes client en cours (âge, date souhaitée) | client | Commandes client |
| Clients | Clients sans achat récent | jours sans facture (90) | Clients |
| Achats | Achats par fournisseur (commandé, valeur reçue) | période | Commandes fournisseur |
| Achats | Commandes fournisseur en cours (part reçue, retard) | fournisseur | Commandes fournisseur |
| Inventaire | Stock des produits (somme des scans, seuils) | catégorie de produits, état (tous, sous le seuil, en stock, sans stock) | Inventaires |
| Inventaire | Produits dormants (stock sans vente) | jours sans vente (90) | Inventaires |
| Finance | Paiements reçus par mode | période | Encaissements |

## Écran et fichiers

- **À l'écran** : un tableau avec les totaux des colonnes qui en ont. Un clic sur un titre de colonne trie (les
  nombres du plus grand au plus petit d'abord).
- **CSV** : séparateur point-virgule, avec la ligne des totaux.
- **Excel** (`.xlsx`) : en-tête en gras, montants au format `#,##0.00`, ligne des totaux.
- **PDF** : en-tête de l'entreprise, paramètres du rapport, totaux, devise et heure de génération dans le fuseau de
  l'utilisateur. Le PDF est en paysage au-delà de six colonnes.
- **Langue** : les titres, paramètres, colonnes et libellés (statuts, modes de paiement) suivent la langue de l'écran,
  français ou anglais ; une semaine s'écrit `2026-S42` en français et `2026-W42` en anglais.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/rapports/bibliotheque?langue=` | Catalogue permis : domaines, rapports, paramètres (valeurs par défaut, options), catégories de produits |
| GET | `/rapports/bibliotheque/{code}?langue=&format=json\|csv\|xlsx\|pdf&fuseau=&…` | Un rapport : colonnes (clé, libellé, type, total), lignes, totaux ; ou le fichier |

## Ajouter un rapport

1. Une classe dans `app/Rapports` qui étend `Rapport` (ou `RapportVentes` pour les factures émises) : code,
   domaine, module du droit, titre et description en français et en anglais, paramètres, colonnes, lignes.
2. L'ajouter à `Catalogue::RAPPORTS`.

Types de paramètres : `date`, `entier` (min, max), `choix` (options), `client`, `fournisseur`, `categorie` (de
produits). Types de colonnes : `texte`, `date`, `entier`, `quantite`, `montant`, `pourcentage` ; `total => true` ajoute
la colonne aux totaux. Les règles de validation et les valeurs par défaut se déduisent des paramètres.

Code :
- API : `App\Rapports\*` (`Rapport`, `RapportVentes`, `Catalogue` et les rapports), `RapportBibliothequeController`,
  `App\Support\ClasseurXlsx` (classeur Excel minimal, sans dépendance), vue `pdf/rapport.blade.php` ;
- droits : `Droits::PARTICULIERES` (`rapports.voir` sur les deux adresses), droit du module de chaque rapport dans
  `Catalogue::pour` ;
- web : `BibliothequeRapportsPage`, `services/bibliothequeRapports.ts`, traductions `reportsLibrary` ;
- tests : `tests/Feature/Rapports/BibliothequeRapportsTest.php`.
