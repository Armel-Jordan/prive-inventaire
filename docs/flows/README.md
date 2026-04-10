# Documentation des Flows — Prise Inventaire

> **Documents évolutifs.** Mis à jour à chaque modification du code.

---

## Documents disponibles

| Document | Module | Contenu |
|----------|--------|---------|
| [FLOW-PARAMETRES.md](./FLOW-PARAMETRES.md) | Paramètres | Produits, Secteurs, Employés, Configuration, Numérotation, TVA, Devise |
| [FLOW-ACHATS.md](./FLOW-ACHATS.md) | Achats | Fournisseurs, Commandes Fournisseur, Réceptions, Multi-devise |
| [FLOW-VENTES.md](./FLOW-VENTES.md) | Ventes | Clients, Devis, Commandes, Factures, Bons de Livraison, Tournées |
| [FLOW-FINANCE.md](./FLOW-FINANCE.md) | Finance | Paiements, Encours, Taxes, Taux de change, Prévisions |

---

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
