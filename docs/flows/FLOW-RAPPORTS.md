# Flow — Bibliothèque des rapports

> Plan de parité fripv2, phases 5.1 (bibliothèque), 5.2 (fichiers, envoi par courriel, envois programmés) et 5.4
> (questions à l'IA).

Menu Inventaire → **Bibliothèque de rapports** (`/bibliotheque-rapports`). Les rapports sont rangés par domaine :
ventes, clients, achats, inventaire, finance. On choisit un rapport, on règle ses paramètres, puis on l'affiche, on le
télécharge, on l'envoie par courriel ou on programme son envoi. L'onglet **Envois programmés** liste ces envois.

## Droits

- Il faut le droit « Rapports » (voir) pour ouvrir la bibliothèque et chacun de ses rapports.
- Chaque rapport demande en plus le droit de voir son module (colonne « Droit » ci-dessous). Un rapport non permis
  n'apparaît pas, et son adresse répond « introuvable ».
- Les domaines ventes, clients et finance demandent le module Ventes de l'entreprise ; les achats, le module Achats.
- Actions du module des rapports :
  - « Envoyer un rapport par courriel » suit au départ le droit de voir les rapports ;
  - « Programmer l'envoi des rapports » suit au départ celui de les modifier : administrateurs et responsables, pas
    la lecture seule.

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
- **Mes filtres** : les paramètres d'un rapport s'enregistrent sous un nom. Celui marqué par défaut s'applique à
  l'ouverture du rapport (voir [FLOW-FILTRES-MEMORISES.md](./FLOW-FILTRES-MEMORISES.md)).

## Envoi par courriel

Le bouton « Envoyer par courriel » ouvre la fenêtre d'envoi des documents :

- elle est pré-remplie avec le modèle « Rapport » de l'entreprise (Paramètres → Courriels), en français ou en
  anglais. Ses variables : `{rapport}` (titre), `{periode}`, `{parametres}` (une ligne par paramètre) et
  `{date_rapport}` ;
- l'adresse du compte est proposée ; copies et copies cachées sont possibles ;
- on choisit le format joint : PDF, Excel ou CSV.

L'envoi entre dans l'historique des courriels (type « Rapport »). Le fichier est refait à chaque tentative, sur les
mêmes paramètres, et un envoi en échec se relance comme les autres.

## Envois programmés

Un envoi programmé fait partir un rapport par courriel :

- **Fréquence** : chaque jour, chaque semaine (un jour de la semaine) ou chaque mois (un jour du mois ; 29, 30 ou 31
  donnent le dernier jour des mois plus courts).
- **Période** : relative, calculée au jour de l'envoi :
  - la veille, les 7 derniers jours, la semaine précédente (lundi à dimanche) ;
  - le mois en cours, le mois précédent, le trimestre précédent ;
  - l'année en cours, l'année précédente.

  Elle est proposée d'après la fréquence : la veille, la semaine précédente, le mois précédent. Les autres
  paramètres du rapport sont gardés tels quels.
- **Format, langue et destinataires** : jusqu'à 20 adresses ; le courriel suit le modèle « Rapport » de la langue
  choisie.
- **Premier envoi** : le premier jour prévu après aujourd'hui. « Envoyer maintenant » l'envoie tout de suite, sans
  changer cette date.
- **Responsable** : le compte qui règle l'envoi (création ou modification). À chaque envoi, il doit être actif,
  pouvoir encore ouvrir le rapport et programmer des envois. Sinon l'envoi est refusé :
  - le motif est noté sur l'envoi programmé ;
  - l'exécution de la tâche est en erreur ;
  - l'envoi est reporté à la date suivante.

  Le reprendre à son nom suffit à le relancer.
- **Visibilité** : on ne voit et ne règle que les envois des rapports que l'on peut ouvrir.

La tâche planifiée « Envoi des rapports programmés » (chaque jour à 6 h 30 par défaut) fait partir les envois du jour.
Après un arrêt du serveur, elle envoie aussi ceux qu'elle a manqués, une seule fois.

Le premier envoi programmé l'active si personne ne l'a encore réglée. Une tâche désactivée exprès le reste, et
l'onglet le signale.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/rapports/bibliotheque?langue=` | Catalogue permis : domaines, rapports, paramètres (valeurs par défaut, options), catégories de produits |
| GET | `/rapports/bibliotheque/{code}?langue=&format=json\|csv\|xlsx\|pdf&fuseau=&…` | Un rapport : colonnes (clé, libellé, type, total), lignes, totaux ; ou le fichier |
| GET | `/rapports/bibliotheque/{code}/courriel?langue=&parametres[…]=` | Pré-remplissage du courriel (droit d'envoyer) |
| POST | `/rapports/bibliotheque/{code}/courriel` | Envoi : `parametres`, `format`, `langue`, destinataires, copies, objet, contenu |
| GET | `/rapports/programmes?langue=` | Envois programmés visibles, périodes relatives, tâche active ou non |
| POST / PUT | `/rapports/programmes[/{id}]?langue=` | `code`, `nom`, `parametres`, `periode`, `format`, `langue`, `destinataires`, `frequence`, `jour`, `actif` |
| DELETE | `/rapports/programmes/{id}` | Suppression |
| POST | `/rapports/programmes/{id}/envoyer` | Envoi immédiat |
| GET | `/courriels/envois?type=rapport&rapport={code}` | Historique des envois d'un rapport |

## Questions à l'IA (phase 5.4)

L'onglet **Questions à l'IA** répond en langage naturel (« Quel est mon meilleur client depuis le début de
l'année ? ») avec le service d'IA d'Anthropic (Claude).

- **Pas d'accès direct à la base.** Contrairement au « Rapport IA » de fripv2, qui fait écrire du SQL à l'IA :
  - l'IA choisit des rapports de la bibliothèque et leurs paramètres ;
  - l'application les exécute avec les droits du compte, dans son entreprise ;
  - l'IA rédige ensuite sa réponse à partir de ces seuls résultats.

  Elle ne peut ni lire d'autres données, ni rien modifier. Un rapport non permis ou des paramètres invalides lui sont
  refusés.
- **La réponse** :
  - titre et texte (gras, listes) ;
  - les rapports consultés ;
  - un bouton pour ouvrir le rapport cité avec ses paramètres. Le filtre par défaut du rapport ne s'applique pas alors.
- **Historique** : les 30 dernières questions du compte. Chacun ne voit que les siennes.
- **Données transmises** : la question, la liste des rapports permis et les lignes des rapports exécutés (200 au plus
  par rapport, avec les totaux sur toutes les lignes). L'écran le signale.
- **Disponibilité** :
  - la clé de l'API sur le serveur (`ANTHROPIC_API_KEY`, jamais dans le dépôt). Sans elle, l'onglet dit que le service
    n'est pas configuré ;
  - le module « Intelligence artificielle » de l'entreprise, activé par le super-administrateur. Il est hors des plans
    par défaut ;
  - le droit « Poser des questions à l'IA sur les rapports » ; il suit au départ le droit de voir les rapports.
- **Réglages du serveur** :
  - `IA_MODELE` : `claude-opus-5-5` par défaut ;
  - `IA_DELAI` : 120 s par appel ;
  - `IA_TOURS` : 6 échanges au plus ;
  - `IA_LIGNES` : 200 lignes par rapport ;
  - `IA_QUESTIONS_PAR_JOUR` : 50 questions par compte et par jour ;
  - et 10 questions par minute au plus.
- **Suivi** : chaque question est gardée dans `questions_ia`, avec le statut, la réponse ou l'erreur, les rapports
  exécutés, le modèle, les jetons d'entrée et de sortie et la durée.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/rapports/ia/questions?langue=` | Disponibilité (`cle`, `module` ou rien), questions restantes du jour, historique du compte |
| POST | `/rapports/ia/questions` | `question` (1 000 caractères au plus), `langue` : la réponse |

## Ajouter un rapport

1. Une classe dans `app/Rapports` qui étend `Rapport` (ou `RapportVentes` pour les factures émises) : code,
   domaine, module du droit, titre et description en français et en anglais, paramètres, colonnes, lignes.
2. L'ajouter à `Catalogue::RAPPORTS`.

Types de paramètres : `date`, `entier` (min, max), `choix` (options), `client`, `fournisseur`, `categorie` (de
produits). Types de colonnes : `texte`, `date`, `entier`, `quantite`, `montant`, `pourcentage` ; `total => true` ajoute
la colonne aux totaux. Les règles de validation et les valeurs par défaut se déduisent des paramètres. Un rapport
qui a les paramètres `du` et `au` reçoit, programmé, sa période relative.

Code :
- API :
  - `App\Rapports\*` : `Rapport`, `RapportVentes`, `Catalogue` et les rapports ; `Production` (données et fichiers,
    les mêmes au téléchargement et en pièce jointe) ; `Programmation` (périodes relatives, dates d'envoi, envoi) ;
  - `RapportBibliothequeController`, `RapportProgrammeController` ;
  - `App\Support\ClasseurXlsx` (classeur Excel minimal, sans dépendance) ; vue `pdf/rapport.blade.php` ;
  - courriel de type `rapport` dans `EnvoiCourriel` (`preparerRapport`, `creerRapport`, fichier refait à l'envoi) ;
  - modèle `RapportProgramme` ; tâche `App\Taches\EnvoyerRapportsProgrammes` (`rapports_programmes`) ;
  - questions à l'IA : `App\Rapports\AssistantRapports` (consignes, outils `executer_rapport` et `repondre`, échanges),
    `App\Services\Ia\ClientClaude` (API Messages d'Anthropic), `RapportIaController`, modèle `QuestionIa`, `config/ia.php`,
    module `ia` de `config/modules.php`, migration `2026_10_17_100083_questions_ia`.
- Droits :
  - `Droits::PARTICULIERES` : `rapports.voir`, `rapports.envoyer`, `rapports.programmer` ;
  - droit du module de chaque rapport dans `Catalogue::pour`.
- Migration `2026_10_17_100081_rapports_courriel_programmes` :
  - colonne `rapport` de `courriel_envois` ;
  - table `rapports_programmes` ;
  - actions des groupes.
- Web :
  - `BibliothequeRapportsPage` ;
  - `components/rapports` : `ChampParametre`, `CourrielRapport`, `FenetreProgramme`, `EnvoisProgrammes`, `QuestionsIa` ;
  - `ComposerCourriel` (source d'envoi autre qu'un document) ;
  - `services/bibliothequeRapports.ts`, `lib/programmesRapports.ts`, traductions `reportsLibrary`.
- Tests : `tests/Feature/Rapports/BibliothequeRapportsTest.php`, `EnvoiRapportsTest.php`, `QuestionsIaTest.php` (API
  d'Anthropic simulée).
