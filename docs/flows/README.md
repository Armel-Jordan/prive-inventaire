# Documentation des Flows — Prise Inventaire

> **Documents évolutifs.** Mis à jour à chaque modification du code.

---

## Documents disponibles

| Document | Module | Contenu |
|----------|--------|---------|
| [FLOW-PARAMETRES.md](./FLOW-PARAMETRES.md) | Paramètres | Produits, Secteurs, Employés, Rôles, Configuration, Numérotation, TVA, Devise, Référentiels, Courriels |
| [FLOW-CONFIGURATION-A-COMPLETER.md](./FLOW-CONFIGURATION-A-COMPLETER.md) | Paramètres | Parcours de mise en route vérifié dans les données, qualité des données |
| [FLOW-SECURITE-COMPTES.md](./FLOW-SECURITE-COMPTES.md) | Plateforme | Mot de passe oublié, changement de mot de passe, double authentification |
| [FLOW-INVENTAIRE.md](./FLOW-INVENTAIRE.md) | Inventaire | Relocalisations, Planification, Approbations, Traçabilité, Alertes, Inventaire Tournant, Rapports, Audit |
| [FLOW-PRISE-INVENTAIRE.md](./FLOW-PRISE-INVENTAIRE.md) | Inventaire | Sessions de comptage, écarts, corrections, validation, inventaire officiel |
| [FLOW-ACHATS.md](./FLOW-ACHATS.md) | Achats | Fournisseurs, Commandes Fournisseur, Réceptions, Multi-devise |
| [FLOW-FICHE-FOURNISSEUR.md](./FLOW-FICHE-FOURNISSEUR.md) | Achats | Fiche fournisseur : synthèse des achats et des livraisons, historique, produits fournis, contacts, adresses, coordonnées bancaires, remarques |
| [FLOW-VENTES.md](./FLOW-VENTES.md) | Ventes | Clients, Devis, Commandes, Factures, Bons de Livraison, Tournées |
| [FLOW-FICHE-CLIENT.md](./FLOW-FICHE-CLIENT.md) | Ventes | Fiche client : synthèse, historique d'achats, contacts, adresses, remarques, catégories, favoris |
| [FLOW-PRIX.md](./FLOW-PRIX.md) | Ventes | Prix de base, listes de prix, règles (prix client, paliers, promotions), calcul du prix d'un client |
| [FLOW-FINANCE.md](./FLOW-FINANCE.md) | Finance | Paiements, Encours, Taxes, Taux de change, Prévisions |
| [FLOW-CHAMPS-PERSONNALISES.md](./FLOW-CHAMPS-PERSONNALISES.md) | Transverse | Champs ajoutés par l'entreprise aux fiches et documents, recherche par valeur |
| [FLOW-BALISES-REMARQUES.md](./FLOW-BALISES-REMARQUES.md) | Transverse | Balises de classement des fiches et documents, remarques rapides |
| [FLOW-PIECES-JOINTES.md](./FLOW-PIECES-JOINTES.md) | Transverse | Pièces jointes des fiches et documents, stockage, sécurité |
| [FLOW-TACHES-PLANIFIEES.md](./FLOW-TACHES-PLANIFIEES.md) | Transverse | Traitements automatiques, planificateur, historique des exécutions |
| [FLOW-RECHERCHE-GLOBALE.md](./FLOW-RECHERCHE-GLOBALE.md) | Transverse | Recherche globale de l'en-tête (Ctrl+K) sur les fiches et documents |
| [FLOW-RAPPORTS.md](./FLOW-RAPPORTS.md) | Transverse | Bibliothèque des rapports (ventes, clients, achats, inventaire, finance) à l'écran, en CSV, Excel et PDF, par courriel et en envois programmés |
| [FLOW-FILTRES-MEMORISES.md](./FLOW-FILTRES-MEMORISES.md) | Transverse | Filtres d'un écran enregistrés par chaque compte, filtre par défaut appliqué à l'ouverture |
| [FLOW-LANGUES.md](./FLOW-LANGUES.md) | Transverse | Français et anglais : écrans, messages de l'API selon la langue de l'écran, données traduisibles |
| [FLOW-AIDE.md](./FLOW-AIDE.md) | Transverse | Aide de chaque écran (bouton « ? », F1), carte des écrans, écrans dans la recherche globale, à propos et nouveautés |
| [FLOW-VERROUS.md](./FLOW-VERROUS.md) | Transverse | Fiche verrouillée pendant sa modification, qui la modifie, prendre la main, fiches en cours de modification |
| [FLOW-SUPPORT.md](./FLOW-SUPPORT.md) | Plateforme | Demandes d'aide et suggestions à l'éditeur, échange, statuts, console du super-administrateur |

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

Espace client (le site des clients des entreprises, distinct du back-office : commandes en ligne, suivi, factures) : [FLOW-ESPACE-CLIENT.md](./FLOW-ESPACE-CLIENT.md).
