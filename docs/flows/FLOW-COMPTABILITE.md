# COMPTABILITÉ GÉNÉRALE — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-16 — journal général, soldes d'ouverture, balance et grand livre (phase 4.2)
>
> Historique : 2026-10-16 — plan comptable, exercices et périodes (phase 4.1)

---

## 1. Ce que c'est

Une comptabilité en partie double, dans le module **Finance** :

- **Plan comptable** (**Finance → Plan comptable**) : les comptes de l'entreprise et leur hiérarchie.
- **Exercices comptables** (**Finance → Exercices comptables**) : les années financières et leurs périodes ; de là, les **soldes d'ouverture**.
- **Journal comptable** (**Finance → Journal comptable**) : les écritures, classées par journal.
- **Grand livre** et **Balance** (**Finance**) : les états tirés des écritures.

Ce socle est générique. Une entreprise part d'un plan type (OHADA, France, Canada), d'un fichier, ou de rien, puis adapte son plan librement. Rien ne change pour les entreprises qui n'ont pas le module Finance.

Prochaines étapes du plan de parité :

| Phase | Contenu |
|---|---|
| 4.2b | Clôture d'exercice (report du résultat), écritures récurrentes |
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
  - les dates et le découpage ne changent que tant qu'aucune période n'est fermée et que l'exercice n'a pas d'écriture ; les périodes sont alors recréées.
- **Suppression** : impossible pour un exercice clôturé, dont une période est fermée, ou qui a des écritures.
- **Fermer une période** :
  - responsables et administrateurs ;
  - la période n'accepte plus d'écriture, ni nouvelle ni modifiée ;
  - la date et l'auteur sont conservés.
- **Rouvrir une période** : administrateurs seulement, et tant que l'exercice n'est pas clôturé.

---

## 4. Journal comptable

### Journaux

Six journaux sont créés au premier accès : OD (opérations diverses), VE (ventes), AC (achats), BQ (banque), CA (caisse) et AN (à-nouveaux, pour les soldes d'ouverture).

- L'entreprise en ajoute, les renomme ou les désactive (bouton **Journaux**).
- Le code (lettres et chiffres, 10 au plus) préfixe le numéro des écritures. Il ne change plus, et le journal ne se supprime plus, une fois des écritures passées.

### Écritures

Une écriture a un journal, une date, un libellé, une référence facultative (n° de facture, de chèque…) et au moins deux lignes. Chaque ligne porte un compte, un libellé facultatif, et un débit ou un crédit.

| Contrôle | Message |
|---|---|
| La date tombe dans un exercice | « Aucun exercice ne couvre le 31/12/2025 : créez-le dans Exercices comptables. » |
| Sa période est ouverte et l'exercice n'est pas clôturé | « La période du 01/03/2026 au 31/03/2026 est fermée. » |
| Chaque ligne : un débit ou un crédit, positif | « Ligne 1 : un débit ou un crédit, pas les deux. » |
| Compte actif, qui n'est pas un en-tête | « Ligne 1 : le compte 60 est un en-tête : choisissez l'un de ses sous-comptes. » |
| Compte ouvert à la saisie manuelle (écriture saisie à la main) | « Ligne 1 : le compte 411 n'accepte pas la saisie manuelle. » |
| Débits = crédits, au centime | « L'écriture n'est pas équilibrée : débit 1 000,00, crédit 900,00 (écart 100,00). » |

- **Numéro** : code du journal, année de début de l'exercice, rang sur 5 chiffres (OD-2026-00001). Le rang suit le plus grand numéro existant : supprimer la dernière écriture ne laisse pas de trou, et un numéro supprimé au milieu n'est pas réattribué.
- **Modification et suppression** : tant que la période est ouverte. Les écritures générées (soldes d'ouverture, clôture) ne se modifient pas depuis le journal.
- **Contre-passation** : crée l'écriture inverse (débits et crédits échangés), datée dans une période ouverte, avec la référence de l'original. C'est ainsi qu'on corrige une écriture d'une période fermée. Une écriture ne se contre-passe qu'une fois ; l'original ne se supprime pas tant que sa contre-passation existe.
- **Saisie** : l'écran affiche les totaux et l'écart en direct. **Équilibrer** porte l'écart sur une ligne sans montant. Saisir un débit vide le crédit de la ligne, et inversement.
- **Liste** : filtres par dates (l'exercice en cours par défaut), journal, compte, origine (saisie, soldes d'ouverture, clôture, récurrente) et texte, 50 écritures par page. Une écriture se déplie pour montrer ses lignes.
- **Export** : toutes les lignes de la période en CSV (date, journal, numéro, compte, libellés, référence, débit, crédit), pour le cabinet comptable.

### Protections du plan et des exercices

- Un compte qui a des écritures ne se supprime pas (il se désactive) et ne devient pas un en-tête.
- Un exercice qui a des écritures garde ses dates et son découpage, et ne se supprime pas.

---

## 5. Soldes d'ouverture

Quand la comptabilité reprend une activité existante, les soldes des comptes de bilan au premier jour du **premier exercice** se saisissent dans un écran dédié (**Exercices comptables → Soldes d'ouverture**). Les exercices suivants n'en ont pas besoin : les soldes se cumulent d'un exercice à l'autre.

- Seuls les comptes de bilan (actif, passif, capitaux propres) y figurent. Les comptes de résultat sont refusés.
- Les débits doivent égaler les crédits ; l'écran affiche l'écart et n'enregistre qu'un ensemble équilibré.
- Ils forment une seule écriture du journal des à-nouveaux, datée du premier jour (AN-2026-00001). Chaque enregistrement la remplace ; des soldes tous nuls la suppriment.
- Ils ne changent plus une fois la première période fermée.

---

## 6. Balance et grand livre

Les deux états se calculent à partir des lignes d'écritures, sur une période choisie (l'exercice en cours par défaut) :

- **Solde d'ouverture d'une période** : cumul de toutes les écritures antérieures. Si la période commence au premier jour de l'exercice, les soldes d'ouverture saisis apparaissent dans les mouvements, puisque leur écriture est datée de ce jour.
- **Balance** :
  - pour chaque compte mouvementé : solde d'ouverture, débits et crédits de la période, solde final, chacun en débit ou en crédit ;
  - sous-totaux par classe (premier caractère du numéro), totaux, et indicateur « Balance équilibrée » ;
  - chaque compte mène à son grand livre ;
  - export CSV.
- **Grand livre** :
  - pour un compte : solde d'ouverture, écritures de la période avec leur solde progressif (D ou C), totaux et solde final ;
  - un en-tête cumule ses sous-comptes, avec une colonne compte ;
  - recherche dans les libellés, numéros et références ;
  - affichage limité à 5 000 lignes, export CSV complet.

---

## 7. Droits

| Action | Qui |
|---|---|
| Consulter (plan, exercices, journal, états) | Tout compte de l'entreprise, si elle a le module Finance (menu : droit « Comptabilité ») |
| Créer, modifier, importer, fermer une période, saisir des écritures et des soldes d'ouverture, gérer les journaux | Administrateurs et responsables |
| Rouvrir une période | Administrateurs |

Les données sont cloisonnées par entreprise, comme le reste de l'application.

---

## 8. API (module Finance)

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
| `GET /comptabilite/journaux`, `POST`, `PUT` et `DELETE /comptabilite/journaux/{id}` | Journaux (créés au premier accès) |
| `GET /comptabilite/ecritures?du=&au=&journal_id=&compte_id=&source=&recherche=&page=` | Écritures paginées, avec leurs lignes |
| `GET /comptabilite/ecritures/{id}`, `POST /comptabilite/ecritures`, `PUT` et `DELETE /comptabilite/ecritures/{id}` | `{journal_id, date, libelle, reference, lignes: [{compte_id, libelle, debit, credit}]}` |
| `POST /comptabilite/ecritures/{id}/contre-passer` | `{date}` |
| `GET /comptabilite/ecritures/export?du=&au=` | Lignes en CSV |
| `GET /comptabilite/balance?du=&au=[&format=csv]` | Balance |
| `GET /comptabilite/grand-livre?compte_id=&du=&au=&recherche=[&format=csv]` | Grand livre |
| `GET` et `PUT /comptabilite/soldes-ouverture` | Soldes d'ouverture du premier exercice, `{lignes: [{compte_id, debit, credit}]}` |

Code :
- **Modèles** : `CompteComptable`, `ExerciceComptable`, `PeriodeComptable`, `JournalComptable`, `EcritureComptable`, `EcritureComptableLigne`.
- **Services** : `App\Services\Comptabilite\{PlanComptable, Exercices, Ecritures, Soldes}`.
- **Plans types** : `App\Support\PlansComptables` ; export CSV : `App\Support\FichierCsv`.
- **Contrôleurs** : `App\Http\Controllers\Comptabilite\*`.
- **Écrans** : `PlanComptablePage`, `ExercicesComptablesPage`, `JournalComptablePage`, `GrandLivrePage`, `BalanceComptablePage`, `SoldesOuverturePage` ; service `src/services/comptabilite.ts`.
- **Migrations** : `2026_10_16_100025_creer_plan_comptable_et_exercices`, `2026_10_16_100026_creer_journal_comptable`.
