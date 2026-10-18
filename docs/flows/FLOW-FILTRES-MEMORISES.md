# Flow — Filtres mémorisés

> Plan de parité fripv2, phase 5.3 (« Préférences de filtres » de fripv2).

Sur les écrans qui ont des filtres, le bouton **Mes filtres** garde les filtres du compte sous un nom :

- **Appliquer** : un clic sur le nom remet les filtres enregistrés. Un filtre vide ou absent est remis à vide (à sa
  valeur par défaut pour un rapport).
- **Enregistrer** les filtres actuels sous un nom. Un nom déjà pris remplace ce filtre, après confirmation.
- **Par défaut** (étoile) : ce filtre s'applique à l'ouverture de l'écran. Il n'y en a qu'un par écran.
- **Supprimer**, après confirmation.

Les filtres sont **personnels** : chaque compte ne voit et ne règle que les siens, dans son entreprise. Aucun droit
de module n'est demandé ; les données affichées restent celles que les droits du compte permettent.

| Écran | Filtres gardés |
|---|---|
| Bibliothèque de rapports (chaque rapport) | Les paramètres du rapport |
| Historique des scans (Inventaires) | Secteur, entrepôt, employé, produit, période |
| Clients | Recherche, catégorie, favoris |
| Commandes fournisseur | Recherche, statut |

Limites : 30 filtres par écran et par compte ; 40 valeurs par filtre, chacune un texte (500 caractères au plus), un
nombre, un booléen, un vide, ou une liste de ces valeurs.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/filtres-memorises?ecran=` | Filtres du compte pour l'écran, celui par défaut d'abord |
| POST | `/filtres-memorises` | `ecran`, `nom`, `valeurs`, `par_defaut` : enregistre (le même nom remplace) |
| PUT | `/filtres-memorises/{id}` | `nom` (unique par écran), `par_defaut` |
| DELETE | `/filtres-memorises/{id}` | Suppression |

Ajouter les filtres mémorisés à un écran : `<FiltresMemorises ecran="…" valeurs={…} onAppliquer={…} />`. On lui
donne les filtres actuels (`valeurs`) et la façon de les remettre (`onAppliquer`). `texteFiltre()` convertit une
valeur enregistrée pour un champ.

Code :
- API : `FiltreMemoriseController`, modèle `FiltreMemorise`, migration `2026_10_17_100082_filtres_memorises` ;
- web : `components/FiltresMemorises.tsx`, `services/filtresMemorises.ts`, traductions `savedFilters` ;
- tests : `tests/Feature/FiltresMemorises/FiltresMemorisesTest.php`.
