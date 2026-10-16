# Documentation des Flows — Prise Inventaire

> **Documents évolutifs.** Mis à jour à chaque modification du code.

---

## Documents disponibles

Sécurité des comptes (mot de passe oublié, changement de mot de passe, double authentification) : [FLOW-SECURITE-COMPTES.md](./FLOW-SECURITE-COMPTES.md).

| Document | Module | Contenu |
|----------|--------|---------|
| [FLOW-FICHE-CLIENT.md](./FLOW-FICHE-CLIENT.md) | Ventes | Fiche client : synthèse, historique d'achats, contacts, adresses, remarques, catégories, favoris |
| [FLOW-PARAMETRES.md](./FLOW-PARAMETRES.md) | Paramètres | Produits, Secteurs, Employés, Configuration, Numérotation, TVA, Devise |
| [FLOW-CHAMPS-PERSONNALISES.md](./FLOW-CHAMPS-PERSONNALISES.md) | Transverse | Champs ajoutés par l'entreprise aux fiches et documents, recherche par valeur |
| [FLOW-ACHATS.md](./FLOW-ACHATS.md) | Achats | Fournisseurs, Commandes Fournisseur, Réceptions, Multi-devise |
| [FLOW-BALISES-REMARQUES.md](./FLOW-BALISES-REMARQUES.md) | Transverse | Balises de classement des fiches et documents, remarques rapides |
| [FLOW-VENTES.md](./FLOW-VENTES.md) | Ventes | Clients, Devis, Commandes, Factures, Bons de Livraison, Tournées |
| [FLOW-CONFIGURATION-A-COMPLETER.md](./FLOW-CONFIGURATION-A-COMPLETER.md) | Transverse | Parcours de mise en route vérifié dans les données, qualité des données |
| [FLOW-FINANCE.md](./FLOW-FINANCE.md) | Finance | Paiements, Encours, Taxes, Taux de change, Prévisions |
| [FLOW-TACHES-PLANIFIEES.md](./FLOW-TACHES-PLANIFIEES.md) | Transverse | Traitements automatiques, planificateur, historique des exécutions |
| [FLOW-INVENTAIRE.md](./FLOW-INVENTAIRE.md) | Inventaire | Relocalisations, Planification, Approbations, Traçabilité, Alertes, Inventaire Tournant, Rapports, Audit |
| [FLOW-PIECES-JOINTES.md](./FLOW-PIECES-JOINTES.md) | Transverse | Pièces jointes des fiches et documents, stockage, sécurité |

Prix et remises : [FLOW-PRIX.md](./FLOW-PRIX.md) — prix de base, listes de prix, règles (prix client, paliers, promotions), calcul du prix d'un client.

---

Recherche globale (en-tête, Ctrl+K) : [FLOW-RECHERCHE-GLOBALE.md](./FLOW-RECHERCHE-GLOBALE.md).

## Le flow global de l'application

```
┌──────────────────────────────────────────────────────────────────┐
│                      PRISE INVENTAIRE                             │
└──────────────────────────────────────────────────────────────────┘

PARAMÈTRES (configurer en premier)
  Produits → Secteurs → Employés → Configuration (numérotation, TVA, devise)

         ┌────────────────┐              ┌────────────────┐
         │     ACHATS     │              │     VENTES     │
         └───────┬────────┘              └───────┬────────┘
                 │                               │
    Fournisseur  │                     Client    │
         ↓       │                         ↓    │
    Commande     │                       Devis   │
    Fournisseur  │                         ↓    │
         ↓       │                     Commande  │
    Réception    │                      Client   │
    (stock ++)   │                         ↓    │
                 │                      Facture  │
                 │                         ↓    │
                 │                   Bon Livraison
                 │                         ↓
                 │                      Tournée
                 │                         ↓
                 │                    Livraison
                 │
         ┌───────┴────────┐
         │    FINANCE     │
         └────────────────┘
    Paiements, Encours, Marges, Prévisions
```

---

## Ordre de mise en place recommandé

```
1. Paramètres → Configuration entreprise (nom, adresse, SIRET)
2. Paramètres → Devise et TVA
3. Paramètres → Numérotation des documents
4. Paramètres → Secteurs de l'entrepôt
5. Paramètres → Produits du catalogue
6. Paramètres → Employés et rôles
7. Achats → Fournisseurs
8. Ventes → Clients
9. Achats → Premières commandes fournisseur
10. Ventes → Premiers devis / commandes
```

Cet ordre est suivi dans l'application : **Paramètres → Configuration à compléter** (voir [FLOW-CONFIGURATION-A-COMPLETER.md](./FLOW-CONFIGURATION-A-COMPLETER.md)).

Portail client (les clients de l'entreprise commandent en ligne, suivent leurs commandes et leurs factures) : [FLOW-PORTAIL-CLIENT.md](./FLOW-PORTAIL-CLIENT.md).
