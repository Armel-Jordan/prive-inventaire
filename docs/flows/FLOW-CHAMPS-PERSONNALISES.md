# CHAMPS PERSONNALISÉS — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-15 — création (plan de parité fripv2, phase 0.8)

---

## 1. Ce que c'est

Chaque entreprise ajoute ses propres champs aux fiches et aux documents (numéro de contrat, type de client, chantier, date promise…), puis les remplit fiche par fiche. Rien n'est prévu pour un secteur en particulier : c'est l'entreprise qui décide de ses champs.

| Type de fiche (`entite`) | Écran | Module requis |
|---|---|---|
| `client` | Clients | ventes |
| `fournisseur` | Fournisseurs | achats |
| `produit` | Produits | — |
| `employe` | Employés | — |
| `devis` | Devis | ventes |
| `commande_client` | Commandes client | ventes |
| `facture` | Factures | ventes |
| `bon_livraison` | Bons de livraison | ventes |
| `commande_fournisseur` | Commandes fournisseur | achats |

---

## 2. Définir les champs — Paramètres → Champs personnalisés

Un onglet par type de fiche (seulement ceux dont le module est actif), puis **Nouveau champ** :

| Réglage | Rôle |
|---|---|
| Libellé | Nom affiché ; un code technique en est tiré (« No projet » → `no_projet`, unique par type de fiche) |
| Type | Texte court, texte long, nombre, date, case à cocher, liste (un choix), liste (plusieurs choix), courriel, adresse web, téléphone. **Ne change plus après la création** |
| Choix | Pour les listes : un choix par ligne (au moins un) |
| Affichage, décimales | Pour les nombres : nombre, montant ou pourcentage ; 0 à 6 décimales |
| Valeur par défaut | Proposée sur les fiches où rien n'est encore saisi ; vérifiée selon le type (un choix qui n'existe pas est refusé) |
| Aide | Texte affiché sous le champ |
| Obligatoire | La saisie des champs de la fiche est refusée tant qu'il est vide (sans effet sur une case à cocher) |
| Sans doublon | Deux fiches ne peuvent pas porter la même valeur (casse ignorée) ; textes, nombres, dates, courriels, adresses web, téléphones |
| Actif | Un champ inactif disparaît des fiches et n'est plus exigé ; ses valeurs sont gardées |

Les flèches règlent l'ordre d'affichage. Supprimer un champ supprime aussi toutes ses valeurs.

**Retrouver des fiches** (même écran) : choisir un champ et une valeur. Les textes se cherchent par « contient » (casse ignorée), les autres types par égalité ; pour une liste à plusieurs choix, les fiches qui ont ce choix.

---

## 3. Remplir les champs d'une fiche

Dans chacun des neuf écrans, un bouton **Champs personnalisés** apparaît en fin de ligne dès que l'entreprise a défini au moins un champ actif pour ce type de fiche. Il ouvre les champs de la fiche, avec leurs valeurs (ou les valeurs par défaut), et **Enregistrer** les valide :

- selon le type : nombre, date AAAA-MM-JJ, choix existant, adresse courriel, adresse web en http(s), numéro de téléphone ;
- les champs obligatoires ;
- les doublons.

Les erreurs s'affichent sous le champ concerné. Vider un champ efface sa valeur.

> Les champs se remplissent dans cette fenêtre, séparément du formulaire de la fiche : un champ obligatoire n'empêche pas d'enregistrer la fiche elle-même. La création des fiches et documents n'est pas modifiée.

---

## 4. Stockage

- `champs_personnalises` : définitions, par entreprise et type de fiche.
- `champs_personnalises_valeurs` : une valeur par champ et par fiche (`entite_id`), en texte : nombre avec point décimal, date AAAA-MM-JJ, case `1`/`0`, liste à plusieurs choix en JSON.

---

## 5. API

| Route | Rôle |
|---|---|
| `GET /champs-personnalises?entite=&actifs=1` | Définitions (toutes, ou d'un type de fiche) |
| `POST /champs-personnalises` | Création (`entite`, `type`, `libelle`…) |
| `PUT /champs-personnalises/{id}` | Modification (le type et le type de fiche restent) |
| `DELETE /champs-personnalises/{id}` | Suppression, valeurs comprises |
| `PUT /champs-personnalises/ordre` | Ordre d'affichage (`entite`, `ids`) |
| `GET /champs-personnalises/valeurs?entite=&entite_id=` | Champs actifs d'une fiche avec leur valeur |
| `PUT /champs-personnalises/valeurs` | Enregistrement (`entite`, `entite_id`, `valeurs` : `{id du champ: valeur}`) |
| `GET /champs-personnalises/recherche?champ_id=&valeur=` | Fiches qui portent la valeur |

Fiche ou champ d'une autre entreprise : 404. Module de la fiche inactif : 403.

Code : `App\Http\Controllers\Api\ChampPersonnaliseController`, `App\Services\ValeursChampsPersonnalises`, `App\Support\ChampsPersonnalises` ; front `ChampsPersonnalisesPage.tsx`, `ChampsPersonnalisesFiche.tsx`, `BoutonChampsPersonnalises.tsx`.
