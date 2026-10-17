# COMPTABILITÉ GÉNÉRALE — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-16 — bilan et compte de résultat (phase 4.8)
>
> Historique (2026-10-16) :
> - plan comptable, exercices et périodes (phase 4.1) ;
> - journal général, soldes d'ouverture, balance et grand livre (phase 4.2) ;
> - clôture et réouverture d'exercice.

---

## 1. Ce que c'est

Une comptabilité en partie double, dans le module **Finance** :

- **Plan comptable** (**Finance → Plan comptable**) : les comptes de l'entreprise et leur hiérarchie.
- **Exercices comptables** (**Finance → Exercices comptables**) : les années financières et leurs périodes ; de là, les **soldes d'ouverture**.
- **Journal comptable** (**Finance → Journal comptable**) : les écritures, classées par journal.
- **Écritures récurrentes** (**Finance → Écritures récurrentes**) : les écritures manuelles répétées à intervalle régulier.
- **Comptabilisation automatique** (**Finance → Comptabilisation automatique**) : les ventes, les achats et les paiements passent au journal (§4 ter).
- **Grand livre**, **Balance** et **États financiers** (bilan, compte de résultat) (**Finance**) : les états tirés des écritures.

Ce socle est générique. Une entreprise part d'un plan type (OHADA, France, Canada), d'un fichier, ou de rien, puis adapte son plan librement. Rien ne change pour les entreprises qui n'ont pas le module Finance.

Prochaines étapes du plan de parité :

| Phase | Contenu |
|---|---|
| 4.8b | États aux formats réglementaires (SYSCOHADA, liasse française), en PDF |

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

### Clôture d'exercice

Bouton **Clôturer** (administrateurs). Une fenêtre montre d'abord les produits, les charges, le bénéfice ou la perte, et le compte qui recevra le résultat.

**Conditions** :

| Condition | Message |
|---|---|
| Les exercices précédents sont clôturés | « Clôturez d'abord l'exercice 2025. » |
| S'il y a des produits ou des charges, un compte de type « Résultat de l'exercice » existe | « Désignez d'abord, dans le plan comptable, le compte qui reçoit le résultat… » |

**Effets** :
- **Écriture de clôture** :
  - datée du dernier jour de l'exercice, au journal des opérations diverses ;
  - elle solde chaque compte de produits et de charges ;
  - elle porte le résultat sur le compte de résultat : au crédit pour un bénéfice, au débit pour une perte ;
  - elle passe même si les périodes sont déjà fermées ou si un compte soldé a été désactivé.
- **Périodes** : toutes sont fermées, et l'exercice passe à « Clôturé », avec la date et l'auteur.
- **Exercice suivant** : il repart avec des comptes de résultat à zéro. Le résultat apparaît dans le solde d'ouverture du compte de résultat.
- **Écritures** : plus aucune n'est acceptée sur l'exercice. L'écriture de clôture ne se modifie pas et ne se contre-passe pas.

**Réouverture** (administrateurs) :
- elle porte sur le dernier exercice clôturé ; un exercice suivant déjà clôturé doit être rouvert d'abord ;
- l'écriture de clôture est supprimée ;
- les périodes restent fermées et se rouvrent une à une.

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

### Écritures récurrentes (Finance → Écritures récurrentes)

Plan de parité fripv2, phase 4.2 (« Récurrence » et « Transactions récurrentes ») : une écriture qui revient à intervalle régulier (loyer, amortissement, abonnement) se saisit une fois puis se répète.

- **Planifier** : bouton **Répéter** d'une écriture saisie à la main (ni contre-passation, ni écriture déjà récurrente). On indique :
  - la première date ;
  - le nombre de répétitions (1 à 120) ;
  - la fréquence : journalière, hebdomadaire, tous les 14 jours, mensuelle, trimestrielle, semestrielle ou annuelle.

  Les dates sont planifiées d'un coup. Un mois ou une année se compte depuis la première date : un 31 donne le dernier jour des mois plus courts, sans glisser ensuite (31/01, 28/02, 31/03, 30/04).
- **Copie** : la récurrence copie le journal, le libellé, la référence et les lignes de l'écriture modèle. Le modèle peut ensuite changer ou disparaître sans toucher aux répétitions.
- **Convertir** : chaque date échue devient une écriture (source « récurrente », numérotée dans son journal), en trois façons :
  - le bouton **Convertir les dates échues** ;
  - date par date ;
  - la tâche planifiée **Écritures récurrentes** (Paramètres → Tâches planifiées, inactive par défaut, chaque jour à 5 h 30).

  Une date refusée (période fermée, compte désactivé…) reste à convertir, et son motif est rapporté. Deux conversions simultanées ne créent pas deux écritures pour la même date.
- **Suivi** :
  - la liste montre, pour chaque récurrence, son numéro, son libellé, sa fréquence, son montant, les dates converties et échues, la prochaine date et son état (active, suspendue, terminée) ;
  - une récurrence se déplie sur ses dates, avec l'écriture créée par chacune ;
  - au journal, les écritures récurrentes portent le badge « Récurrence n° X », et le filtre `recurrence` les retrouve.
- **Suspendre** et **reprendre** une récurrence. **Supprimer les dates restantes** retire les dates non converties. Une récurrence qui a déjà créé des écritures reste, arrêtée, pour que son numéro les désigne toujours ; sinon elle disparaît.
- **Droits** : consulter avec le droit de voir la comptabilité ; planifier, convertir, suspendre et supprimer avec celui de saisir des écritures.

### Comptabilisation automatique (Finance → Comptabilisation automatique)

Plan de parité fripv2, phase 4.3 : les ventes, les achats et les paiements passent au journal, une écriture par document, selon des réglages propres à l'entreprise. Rien n'est écrit pendant les ventes ou les achats : une période fermée ou un compte manquant ne bloque jamais une facture ou un paiement. La comptabilisation se lance depuis l'écran, ou chaque jour par la tâche planifiée **Comptabilisation automatique** (inactive par défaut, 23 h 30).

**Réglages** :
- **date de début** : seuls les documents datés à partir de ce jour se comptabilisent (les plus anciens ont pu être saisis à la main) ;
- **journaux** des ventes, des achats, de banque, de caisse et des opérations diverses ;
- **comptes** :
  - clients, fournisseurs, ventes, achats ;
  - taxes collectées, taxes déductibles ;
  - caisse, banque ;
  - acomptes clients, escomptes accordés, cartes cadeaux ;
- **« Proposer d'après le plan comptable »** remplit les réglages vides : journaux par défaut, comptes trouvés par leur type (clients, fournisseurs), puis par les numéros usuels des plans OHADA (411, 401, 701, 601, 443, 445, 571, 521, 419), français et canadien ;
- **correspondances** :
  - mode de paiement → caisse ou banque (espèces : caisse ; les autres : banque, par défaut) ;
  - taxe → comptes collectée et déductible (taxes des groupes de taxes) ;
  - catégorie de produit → comptes de vente et d'achat. Vide : les comptes par défaut.
- Un compte d'en-tête ne se règle pas, car il ne reçoit pas d'écriture.

**Écritures** (montants dans la devise de l'entreprise, au taux du document ; le compte du tiers prend la somme des contreparties arrondies, sans écart d'arrondi) :

| Document | Journal | Débit | Crédit |
|---|---|---|---|
| Facture de vente émise | Ventes | Clients (TTC) | Ventes par catégorie (HT), taxes collectées par taxe |
| Note de crédit émise | Ventes | Ventes (HT), taxes collectées | Clients (TTC) |
| Paiement d'un client | Caisse ou banque selon le mode | Caisse ou banque ; acompte appliqué : acomptes clients ; carte cadeau : cartes cadeaux (journal des opérations diverses) ; escompte accordé | Clients (paiement et escompte) |
| Encaissement réparti sur plusieurs factures | Caisse ou banque selon le mode | Caisse ou banque (argent reçu) ; escompte accordé | Clients (une ligne par facture) ; surplus : acomptes clients |
| Acompte reçu | Caisse ou banque | Caisse ou banque | Acomptes clients |
| Remboursement d'acompte | Caisse ou banque | Acomptes clients | Caisse ou banque |
| Remboursement de note de crédit | Caisse ou banque | Clients | Caisse ou banque |
| Facture fournisseur | Achats | Achats par catégorie (HT), taxes déductibles par taxe | Fournisseurs (TTC) |
| Paiement d'un fournisseur | Caisse ou banque | Fournisseurs | Caisse ou banque |

**Règles :**
- Une écriture générée porte le type de son document en source (filtre « Origine » du journal) et le numéro du document en référence.
- Elle ne se modifie pas depuis le journal : le document se corrige, ou l'écriture se contre-passe.
- **Un document se comptabilise une fois.** Une facture fournisseur annulée, un paiement fournisseur annulé, un acompte supprimé ou un encaissement annulé après coup font **contre-passer** leur écriture, à la date de l'annulation (aujourd'hui pour une suppression).
- **Encaissement** : ses paiements de factures et son surplus passent avec lui, en une seule écriture ; ils ne passent pas aussi comme paiements ou acomptes. Un encaissement annulé avant d'être comptabilisé ne passe pas au journal.
- **Refus** : un document dans une période fermée, ou qui demande un compte non réglé, reste à comptabiliser, et son motif est rapporté (« Compte « Taxes collectées » non réglé »). Les autres passent.
- **Aperçu** : nombre et montant des documents à comptabiliser par type sur la période, écritures à contre-passer, réglages manquants.
- Une seule comptabilisation à la fois par entreprise.
- **Droits** : consulter avec le droit de voir la comptabilité ; régler et lancer avec celui de saisir des écritures.

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

### États financiers (Finance → États financiers)

Les comptes sont regroupés sous leur en-tête le plus proche (60 Achats, 62 Services extérieurs…), dans l'ordre du plan. Les groupes se déplient pour montrer leurs comptes. Chaque état s'exporte en CSV.

**Bilan à une date** (aujourd'hui par défaut) : chaque compte de bilan à son solde cumulé.
- **Actif** : comptes de nature actif, au débit. Un compte au solde contraire, comme les amortissements, vient en déduction.
- **Capitaux propres** :
  - comptes de capitaux propres, au crédit ;
  - **résultat non encore clôturé** (produits moins charges des exercices pas encore clôturés), qui équilibre le bilan en cours d'exercice ; après la clôture, il est sur le compte de résultat.
- **Dettes** : comptes de nature passif, au crédit.
- **Totaux** : actif, et passif (capitaux propres et dettes), avec l'indicateur « Bilan équilibré ».

**Compte de résultat d'une période** (l'exercice en cours par défaut) :
- produits et charges de la période, par groupe ;
- bénéfice ou perte ;
- les écritures de clôture sont exclues : un exercice clôturé garde donc son compte de résultat.

---

## 7. Droits

| Action | Qui |
|---|---|
| Consulter (plan, exercices, journal, états) | Tout compte de l'entreprise, si elle a le module Finance (menu : droit « Comptabilité ») |
| Créer, modifier, importer, fermer une période, saisir des écritures et des soldes d'ouverture, gérer les journaux | Administrateurs et responsables |
| Rouvrir une période, clôturer ou rouvrir un exercice | Administrateurs |

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
| `GET /comptabilite/exercices/{id}/cloture` | Aperçu : produits, charges, résultat, compte de résultat, `bloquant` |
| `POST /comptabilite/exercices/{id}/cloturer`, `/rouvrir` | Clôture, réouverture (administrateurs) |
| `GET /comptabilite/journaux`, `POST`, `PUT` et `DELETE /comptabilite/journaux/{id}` | Journaux (créés au premier accès) |
| `GET /comptabilite/ecritures?du=&au=&journal_id=&compte_id=&source=&recurrence=&recherche=&page=` | Écritures paginées, avec leurs lignes et leur numéro de récurrence |
| `GET /comptabilite/ecritures/{id}`, `POST /comptabilite/ecritures`, `PUT` et `DELETE /comptabilite/ecritures/{id}` | `{journal_id, date, libelle, reference, lignes: [{compte_id, libelle, debit, credit}]}` |
| `POST /comptabilite/ecritures/{id}/contre-passer` | `{date}` |
| `POST /comptabilite/ecritures/{id}/recurrence` | Répéter une écriture manuelle : `{date_debut, nombre, frequence}` |
| `GET /comptabilite/recurrences` | Récurrences et leurs dates, nombre de dates échues |
| `POST /comptabilite/recurrences/convertir` | Convertir les dates échues (ou `{echeances: [ids]}`, `{jusqu_au}`) ; `converties`, `erreurs` |
| `PUT /comptabilite/recurrences/{id}`, `DELETE` | Suspendre ou reprendre (`{active}`) ; supprimer les dates restantes |
| `GET` et `PUT /comptabilite/comptabilisation/parametres` | Réglages de la comptabilisation automatique (avec les propositions, les journaux, comptes, taxes et catégories) |
| `GET /comptabilite/comptabilisation/apercu?du=&au=` | Documents à comptabiliser par type, contre-passations, réglages manquants |
| `POST /comptabilite/comptabilisation` | `{du, au}` : comptabiliser ; `creees`, `par_type`, `contre_passations`, `erreurs` |
| `GET /comptabilite/ecritures/export?du=&au=` | Lignes en CSV |
| `GET /comptabilite/balance?du=&au=[&format=csv]` | Balance |
| `GET /comptabilite/grand-livre?compte_id=&du=&au=&recherche=[&format=csv]` | Grand livre |
| `GET` et `PUT /comptabilite/soldes-ouverture` | Soldes d'ouverture du premier exercice, `{lignes: [{compte_id, debit, credit}]}` |
| `GET /comptabilite/etats/bilan?date=[&format=csv]` | Bilan |
| `GET /comptabilite/etats/resultat?du=&au=[&format=csv]` | Compte de résultat |

Code :
- **Modèles** : `CompteComptable`, `ExerciceComptable`, `PeriodeComptable`, `JournalComptable`, `EcritureComptable`, `EcritureComptableLigne`, `RecurrenceComptable`, `RecurrenceEcheance`, `ParametreComptable`.
- **Services** : `App\Services\Comptabilite\{PlanComptable, Exercices, Ecritures, Soldes, Clotures, EtatsFinanciers, Recurrences, Comptabilisation}` ; tâches `App\Taches\ConvertirEcrituresRecurrentes`, `App\Taches\ComptabiliserDocuments`.
- **Plans types** : `App\Support\PlansComptables` ; export CSV : `App\Support\FichierCsv`.
- **Contrôleurs** : `App\Http\Controllers\Comptabilite\*`.
- **Écrans** : `PlanComptablePage`, `ExercicesComptablesPage`, `JournalComptablePage`, `EcrituresRecurrentesPage`, `ComptabilisationPage`, `GrandLivrePage`, `BalanceComptablePage`, `SoldesOuverturePage`, `EtatsFinanciersPage` ; service `src/services/comptabilite.ts`.
- **Migrations** : `2026_10_16_100025_creer_plan_comptable_et_exercices`, `2026_10_16_100026_creer_journal_comptable`, `2026_10_17_100061_creer_recurrences_comptables`, `2026_10_17_100062_creer_parametres_comptables`.
