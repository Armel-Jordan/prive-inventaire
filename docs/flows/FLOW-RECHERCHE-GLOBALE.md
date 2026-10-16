# RECHERCHE GLOBALE — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-15 — création (plan de parité fripv2, phase 6.4)

---

## 1. Ce que c'est

Un champ de recherche en tête de chaque écran (raccourci **Ctrl+K**, ou **Cmd+K** sur Mac) qui retrouve les fiches et documents de l'entreprise :

| Type | Champs cherchés | Module |
|---|---|---|
| Produits | numéro, description, catégorie | — |
| Clients | code, raison sociale, ville, courriel, téléphone | Ventes |
| Fournisseurs | code, raison sociale, courriel, téléphone | Achats |
| Devis, commandes clients, factures | numéro, nom du client | Ventes |
| Bons de livraison | numéro, numéro de facture, nom du client | Ventes |
| Commandes fournisseurs | numéro, nom du fournisseur | Achats |
| Secteurs | code, nom | — |
| Employés | numéro, nom, prénom, courriel | — |

- **2 caractères** au moins. Les majuscules ne comptent pas.
- **Ordre des résultats** : valeur exacte, puis commençant par le texte, puis le reste. On voit 5 résultats par type, avec le nombre des autres.
- Seuls les types **que l'utilisateur peut consulter** sont cherchés : mêmes droits que le menu. Ceux d'un **module inactif** (Ventes, Achats) sont ignorés.
- **Clavier** : ↑ et ↓ pour se déplacer, Entrée pour ouvrir, Échap pour fermer.
- Un résultat ouvre un **aperçu** : statut, dates, montants dans la devise de l'entreprise, coordonnées. **Ouvrir la page** mène à la fiche du client ou du fournisseur (`/clients/{id}`, `/fournisseurs/{id}`), et pour les autres types à la page du type, avec `?recherche=` dans l'adresse.

Lecture seule : rien n'est modifié. Les pages existantes ne lisent pas encore le paramètre `?recherche=` : elles s'ouvrent sur leur liste complète.

---

## 2. API

`GET /recherche?q=texte&types=produits,clients,…&limite=5`, pour tout utilisateur connecté, 60 requêtes par minute au plus.

- `types` : facultatif, tous les types par défaut. Les types inconnus sont ignorés.
- `limite` : 1 à 20, 5 par défaut.
- Moins de 2 caractères : réponse 422.

Réponse :

```json
{
  "q": "cim",
  "groupes": [
    {
      "type": "produits",
      "total": 7,
      "resultats": [
        {
          "type": "produits",
          "id": 12,
          "titre": "CIM-50 — Ciment 50 kg",
          "sous_titre": "Liants",
          "details": { "categorie": "Liants", "prix": 100, "mesure": "UN", "secteur": "A-01", "actif": true },
          "lien": "/produits?recherche=CIM-50"
        }
      ]
    }
  ]
}
```

Code :
- API : `App\Services\RechercheGlobale` (un type = sa requête et sa mise en forme), `App\Http\Controllers\RechercheController` ;
- front : `components/RechercheGlobale.tsx`, `services/recherche.ts`.

**Ajouter un type** : une entrée dans `RechercheGlobale::definitions()` et dans `TYPES`, puis son module de permission dans `services/recherche.ts` et son libellé dans les traductions (`globalSearch.types`).
