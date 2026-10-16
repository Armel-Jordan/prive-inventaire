# COMPTABILITÉ GÉNÉRALE — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-16 — plan comptable, exercices et périodes (plan de parité fripv2, phase 4.1)

---

## 1. Ce que c'est

Le socle d'une comptabilité en partie double, dans le module **Finance** :

- **Plan comptable** (**Finance → Plan comptable**) : les comptes de l'entreprise et leur hiérarchie.
- **Exercices comptables** (**Finance → Exercices comptables**) : les années financières et leurs périodes.

Ce socle est générique. Une entreprise part d'un plan type (OHADA, France, Canada), d'un fichier, ou de rien, puis adapte son plan librement. Rien ne change pour les entreprises qui n'ont pas le module Finance.

Prochaines étapes du plan de parité :

| Phase | Contenu |
|---|---|
| 4.2 | Journal général : écritures manuelles et récurrentes, soldes d'ouverture, grand livre, balance, clôture d'exercice |
| 4.3 | Écritures générées automatiquement à partir des ventes, des achats et des paiements |
| 4.8 | États financiers : bilan, compte de résultat |

---

## 2. Plan comptable

### Comptes

| Champ | Règle |
|---|---|
| Numéro | 20 caractères au plus : chiffres, lettres, point, tiret. Unique dans l'entreprise. Les espaces sont retirés. |
| Libellé | Obligatoire, 150 caractères au plus. |
| Compte parent | Facultatif. Interdit : le compte lui-même ou l'un de ses sous-comptes, ce qui ferait boucler la hiérarchie. |
| Nature | Place du compte dans les états : actif, passif, capitaux propres (bilan), produit, charge (résultat). |
| Type | Voir le tableau suivant. |
| Saisie manuelle | Autorise les écritures saisies à la main. Toujours désactivée pour un en-tête. |
| Actif | Un compte inactif ne sert plus pour de nouvelles écritures. |

| Type | Rôle |
|---|---|
| Standard | Compte ordinaire. |
| En-tête | Regroupe des sous-comptes et cumule leurs soldes. Ne reçoit pas d'écriture. |
| Clients, Fournisseurs | Créances et dettes de tiers ; serviront aux comptes clients et fournisseurs. |
| Trésorerie | Banque, caisse, monnaie électronique. |
| Résultat de l'exercice | Reçoit le résultat à la clôture. Un seul par entreprise, de nature capitaux propres. |

Ces règles adaptent celles de fripv2 sans imposer de numérotation, puisque fripv2 suppose des comptes canadiens à 5 chiffres :

- un compte actif ne peut pas dépendre d'un compte inactif ;
- un compte qui a des sous-comptes actifs ne peut pas être désactivé ;
- un compte qui a des sous-comptes ne peut pas être supprimé.

### Plans types

| Plan | Contenu |
|---|---|
| SYSCOHADA révisé | Classes 1 à 8, comptes principaux (2 chiffres, en-têtes) et comptes divisionnaires usuels (3 chiffres) : 244 comptes, dont la monnaie électronique (552) |
| Plan comptable général (France) | Classes 1 à 7, comptes usuels d'une PME commerciale ou de services |
| Plan canadien à 5 chiffres | En-têtes 10000 à 50000, comptes usuels avec TPS et TVQ |

- **Hiérarchie** : elle se déduit du numéro. Un compte est rattaché au plus long numéro du plan qui le commence (401 sous 40, 40 sous 4). Dans le plan canadien, il est rattaché à l'en-tête de sa classe (10100 sous 10000).
- **Premier import** : il exige un plan vide.
- **Compléter** : n'ajoute que les numéros absents. Si l'entreprise a déjà un compte de résultat, celui du plan type est créé comme compte ordinaire.

### Import et export CSV

Format, avec une première ligne d'en-têtes :

```
numero;libelle;nature;type;parent
```

- Séparateur : point-virgule, virgule ou tabulation ; BOM accepté.
- En-têtes tolérés : « Numéro », « Libellé », « Compte parent », « Nom »…
- Valeurs tolérées pour la nature et le type : « Capitaux propres », « En-tête »…
- Le type est facultatif (standard par défaut).
- Le parent est facultatif. Il peut être un compte du fichier ou un compte déjà au plan ; sans parent, il est déduit du numéro.
- Les comptes déjà au plan sont ignorés.
- Tout ou rien pour les autres : à la moindre erreur, rien n'est créé. Les erreurs sont listées avec leur numéro de ligne (nature inconnue, doublon, parent introuvable, second compte de résultat…).
- 5 000 comptes au plus.
- L'export produit le même format, avec BOM pour Excel : un plan exporté se réimporte tel quel dans une autre entreprise.

---

## 3. Exercices et périodes

- **Création** : début, fin, découpage (mensuel, trimestriel ou une seule période) et libellé facultatif (« 2026 », ou « 2026-2027 » à cheval sur deux années).
- **Valeurs proposées** : les douze mois qui suivent le dernier exercice, ou l'année civile en cours.
- **Contrôles** :
  - la fin suit le début ;
  - 24 mois au plus, pour un premier exercice long ;
  - aucun chevauchement avec un autre exercice.
- **Périodes** : elles suivent les mois civils. Le premier et le dernier mois peuvent être partiels (exercice commencé le 15 janvier : période du 15 au 31 janvier). Le trimestriel regroupe ces mois par trois. La période qui contient la date du jour est signalée « En cours ».
- **Modification** :
  - le libellé reste toujours modifiable ;
  - les dates et le découpage ne changent que tant qu'aucune période n'est fermée, et les périodes sont alors recréées.
- **Suppression** : impossible pour un exercice clôturé ou dont une période est fermée.
- **Fermer une période** :
  - responsables et administrateurs ;
  - la période n'acceptera plus d'écriture (phase 4.2) ;
  - la date et l'auteur sont conservés.
- **Rouvrir une période** : administrateurs seulement, et tant que l'exercice n'est pas clôturé.

---

## 4. Droits

| Action | Qui |
|---|---|
| Consulter | Tout compte de l'entreprise, si elle a le module Finance (menu : droit « Comptabilité ») |
| Créer, modifier, importer, fermer une période | Administrateurs et responsables |
| Rouvrir une période | Administrateurs |

Les données sont cloisonnées par entreprise, comme le reste de l'application.

---

## 5. API (module Finance)

| Route | Rôle |
|---|---|
| `GET /comptabilite/comptes` | Comptes, triés par numéro |
| `POST /comptabilite/comptes`, `PUT` et `DELETE /comptabilite/comptes/{id}` | Création, modification, suppression |
| `GET /comptabilite/plans-types` | Plans types et leur nombre de comptes |
| `POST /comptabilite/comptes/importer-plan` | `{plan, completer}` |
| `POST /comptabilite/comptes/importer-fichier` | Fichier CSV `fichier` ; 422 avec `erreurs` si le fichier est refusé |
| `GET /comptabilite/comptes/export` | Plan en CSV |
| `GET /comptabilite/exercices` | Exercices et périodes, période en cours |
| `POST /comptabilite/exercices`, `PUT` et `DELETE /comptabilite/exercices/{id}` | `{libelle, date_debut, date_fin, decoupage}` |
| `POST /comptabilite/periodes/{id}/fermer`, `/rouvrir` | Fermeture, réouverture |

Code :
- **Modèles** : `CompteComptable`, `ExerciceComptable`, `PeriodeComptable`.
- **Services** : `App\Services\Comptabilite\{PlanComptable, Exercices}`.
- **Plans types** : `App\Support\PlansComptables`.
- **Contrôleurs** : `App\Http\Controllers\Comptabilite\*`.
- **Écrans** : `PlanComptablePage`, `ExercicesComptablesPage` ; service `src/services/comptabilite.ts`.
- **Migration** : `2026_10_16_100025_creer_plan_comptable_et_exercices`.
