# Plan de parité fonctionnelle avec fripv2

**Objectif** : offrir dans Prise Inventaire toutes les fonctionnalités de fripv2 (Fonxtio), sans changer la logique existante de Prise Inventaire ni renoncer à son approche générale.

**Date** : 15 octobre 2026
**Référence** : fripv2 compte environ 137 écrans au menu (Gestion 58, Inventaire 11, Vente 17, Achats 7, Comptabilité 37, Aide 7) et 229 tables.
**Suite** : ce qui reste à faire au 19 octobre 2026, écran par écran, est dans [17-RESTE-A-FAIRE.md](./17-RESTE-A-FAIRE.md).

---

## 1. Principes

1. **Additif.** Ce qui existe ne change pas. Les nouveautés arrivent dans de nouvelles tables, de nouveaux écrans et de nouvelles routes. Aucune règle actuelle n'est réécrite.
2. **Activable par entreprise.** Chaque nouveauté est rattachée à un module ou à un paramètre du tenant. Désactivée, elle laisse Prise Inventaire se comporter exactement comme aujourd'hui.
3. **Générique.** fripv2 a été taillé pour des métiers précis : distribution de gaz, télécom, au Québec. Rien de spécifique à un métier n'est codé en dur. Chaque spécificité devient un réglage (voir §2).
4. **Multi-pays.** Taxes, plans comptables, formats bancaires, devises et langues sont configurables : OHADA, France, Canada…
5. **Isolation des tenants.** Toute table métier porte `tenant_id` et le trait `BelongsToTenant`. Chaque fonctionnalité est livrée avec ses tests d'isolation.

## 2. Ce qui est généralisé

| Spécificité de fripv2 | Équivalent générique dans Prise Inventaire |
|---|---|
| Cylindres en circulation, loyers de cylindres | **Actifs retournables** : tout article prêté, loué ou consigné, suivi chez le client, facturé périodiquement |
| TPS + TVQ | **Groupes de taxes** : n taxes par groupe, cumulées ou composées, par produit ou par client |
| Fichiers TFE Desjardins, impression de chèques | **Fichiers de paiement bancaires** au format configurable, et chèques |
| Facturation à l'usage VoiceMeUp / ITCloud | **Facturation à l'usage** : import CSV ou connecteur API générique |
| Synchronisation bancaire Plaid | **Flux bancaires** : import de relevés (CSV, OFX, CAMT), connecteurs optionnels |
| Imports Celibec, Zoho | **Assistant d'import générique**, avec correspondance des colonnes |
| Rapport de dangerosité | Attribut produit **« classe de danger »** et rapport associé |
| Synchronisation CRM dédiée | **Module CRM générique** et webhooks |

## 3. Hors périmètre

Ces fonctions sont propres à un logiciel de bureau installé : mise à jour et lanceur, licences par poste, relai, capture d'écrans documentaire, compatibilité exécutable/base. En SaaS, elles sont couvertes par les tenants et leurs modules, la CI/CD et le monitoring.

---

## 4. Phases

Taille : **P** = petite, **M** = moyenne, **G** = grande.

### Phase 0 — Socle transverse (prérequis des autres phases)

| # | Fonctionnalité | Origine fripv2 | Taille |
|---|---|---|---|
| 0.1 | Référentiels : unités et conversions, catégories (table hiérarchique), fabricants, modes de paiement, conditions de paiement, banques de l'entreprise, devises et taux de change administrables | Onglet Gestion | M |
| 0.2 | Référentiels logistiques et commerciaux : entrepôts (au-dessus des secteurs, facultatifs), territoires, itinéraires, représentants, transporteurs | Onglet Gestion | M |
| 0.3 | Raisons (mouvement, ajustement, retour) et statuts personnalisés | Raisons, Statuts personnalisés | P |
| 0.4 | Taxes configurables : taxes, groupes de taxes, taxe par défaut par produit ou client, exonérations. Appliquées aux documents si configurées, sinon calcul actuel conservé | Taxes, Groupe de taxes | M |
| 0.5 | Documents PDF : devis, commande, facture, bon de livraison, avec en-tête, pied de page et notes de bas de page par entreprise | Rapports de documents, Notes de bas de page | M |
| 0.6 | Courriel : gabarits, envoi des documents, file d'envoi, historique | En-têtes courriel, Envois courriel | M |
| 0.7 | Pièces jointes sur toute fiche, avec stockage disque ou S3 | Documents / Attachements | P |
| 0.8 | Champs personnalisés sur clients, fournisseurs, produits et documents | Champs personnalisés | M |
| 0.9 | Étiquettes de classement (balises) et remarques rapides réutilisables | Balises, Remarques rapides | P |
| 0.10 | Automatisation : planificateur, file de tâches, écran des tâches planifiées (exécution automatique des transferts planifiés, relances…) | Tâches planifiées | M |
| 0.11 | Mise en route : écran « Configuration à compléter » par entreprise | Configuration à compléter | P |

### Phase 1 — Ventes complètes

| # | Fonctionnalité | Origine fripv2 | Taille |
|---|---|---|---|
| 1.1 | Fiche client complète : contacts et adresses multiples, catégories, remarques, client de passage, favoris, historique d'achats, synthèse | Clients | M |
| 1.2 | Prix et remises : niveaux de prix par formule, prix par client ou catégorie, références produit du client, escomptes (quantité, période), révision de prix planifiée, import de grilles. Donne enfin un vrai fonctionnement à l'écran « Gestion des prix », qui n'affiche aujourd'hui que des données de démonstration | Escomptes, Révision de prix, Grille de prix | G |
| 1.3 | Devis : envoi PDF par courriel, acceptation en ligne par lien, relances | Devis | M |
| 1.4 | Commandes : reliquats (back-orders), réservation de stock, frais additionnels et de livraison | Gestion des B/O, Frais | M |
| 1.5 | Factures : notes de crédit, multi-devises, escomptes, acomptes et demandes d'acompte, rappels de paiement | Factures, Acomptes | G |
| 1.6 | Livraison : préparation des colis, chargement du camion, signature électronique, séquence de livraison, classe de danger | Préparation boîte, Chargement, Séquences | M |
| 1.7 | Retours clients avec raisons : remise en stock ou mise au rebut | Raisons de retour | M |
| 1.8 | Revenus récurrents : abonnements facturés automatiquement, location d'actifs retournables, facturation à l'usage | Abonnements, Contrats de location | G |
| 1.9 | Consignation chez le client : stock en dépôt et relevé | Consignations | M |
| 1.10 | Point de vente : caisses, sessions, fermeture, sommaire, cartes cadeaux | Caisses, Cartes cadeaux | G |
| 1.11 | Portail client : catalogue, commandes, paiement en ligne, documents | Commande web | G |

### Phase 2 — Inventaire avancé

| # | Fonctionnalité | Origine fripv2 | Taille |
|---|---|---|---|
| 2.1 | Plusieurs codes-barres par produit ; formats et impression d'étiquettes de produit et de localisation | Formats d'étiquette | M |
| 2.2 | Unités de vente et d'achat multiples, avec conversions | Unités, Conversions | M |
| 2.3 | Kits (ensembles), produits annexes ajoutés automatiquement, produits équivalents | Fiche produit | M |
| 2.4 | Fiche produit enrichie : images, descriptions multilingues, spécifications, fabricant | Fiche produit | M |
| 2.5 | Multi-entrepôts : entrepôt → secteur → localisation (les secteurs actuels restent valides) | Entrepôts, Localisations | M |
| 2.6 | Valorisation : coût moyen pondéré, frais d'achat répartis, valeur d'inventaire | Valeur d'inventaire | M |
| 2.7 | Prise d'inventaire formelle : sessions de comptage, rapport d'écarts, ajustement validé, par-dessus les scans actuels | Prise d'inventaire | M |
| 2.8 | Lots, numéros de série, dates de péremption (FIFO/FEFO, rappels) | — (prévu dans la roadmap PI) | G |

### Phase 3 — Achats avancés

| # | Fonctionnalité | Origine fripv2 | Taille |
|---|---|---|---|
| 3.1 | Fiche fournisseur complète : contacts, adresses, remarques, coordonnées bancaires, tableau de bord | Fournisseurs | M |
| 3.2 | Suggestions de commande automatiques (seuils, reliquats, prévisions) qui génèrent les bons de commande | Suggestions de commandes | M |
| 3.3 | Envoi du bon de commande par courriel ; réception avec localisation ; coûts annexes répartis | Commandes, Réceptions | M |
| 3.4 | Retours fournisseurs et avoirs | Gestion des retours | M |
| 3.5 | Factures fournisseurs, rapprochées avec la commande et la réception, versées aux comptes à payer | Comptes à payer | M |
| 3.6 | Import des prix et catalogues fournisseurs | Import prix fournisseur | P |

### Phase 4 — Comptabilité

Moteur générique ; le plan comptable s'importe (SYSCOHADA, PCG, Canada…).

| # | Fonctionnalité | Origine fripv2 | Taille |
|---|---|---|---|
| 4.1 | Plan comptable, classes, exercices et périodes avec clôture, soldes de départ | Plan comptable, Cycle comptable | M |
| 4.2 | Journal général : écritures manuelles et récurrentes, analyse par compte | Transactions manuelles | M |
| 4.3 | Écritures générées automatiquement à partir des ventes, achats, paiements et du stock, selon des règles paramétrables | — | G |
| 4.4 | Comptes clients : encaissements sur plusieurs factures, analyse chronologique, états de compte, rappels | Comptes à recevoir | G |
| 4.5 | Comptes fournisseurs : déboursés, chèques, fichiers de paiement bancaires, paiements dus | Comptes à payer, TFE | G |
| 4.6 | Banque : comptes, transactions, import de relevés, rapprochement | Transactions bancaires, Conciliation | M |
| 4.7 | Budgets | Budgets | P |
| 4.8 | États : balance de vérification, bilan, état des résultats, grand livre, rapport des taxes | Rapports comptables | M |

### Phase 5 — Rapports et décisionnel

| # | Fonctionnalité | Origine fripv2 | Taille |
|---|---|---|---|
| 5.1 | Bibliothèque de rapports (fripv2 en compte plus de 40) : ventes, clients, fournisseurs, inventaire, préparation… | Rapports | G |
| 5.2 | Sorties PDF, Excel et CSV, envoi par courriel, planification | Gestion des rapports | M |
| 5.3 | Filtres mémorisés par usager | Préférences de filtres | P |
| 5.4 | Rapports en langage naturel (IA) et rapports IA périodiques | Rapport IA | M |

### Phase 6 — Plateforme

| # | Fonctionnalité | Origine fripv2 | Taille |
|---|---|---|---|
| 6.1 | Mot de passe oublié, changement de mot de passe, double authentification | Usagers | M |
| 6.2 | Permissions fines par écran et par action, groupes d'usagers | Groupes, Accès | G |
| 6.3 | Verrouillage d'une fiche pendant sa modification | Verrous | P |
| 6.4 | Recherche globale | Rechercher | M |
| 6.5 | Assistant IA sur les données, selon les droits de l'usager | Assistant | G |
| 6.6 | Aide en ligne contextuelle, en français et en anglais | Documentation | M |
| 6.7 | Demandes de support et suggestions, avec suivi | Support, Suggestions | M |
| 6.8 | Traduction complète : messages de l'API et données traduisibles | Traduction | M |
| 6.9 | CRM : activités, suivis, webhooks | CRM | G |
| 6.10 | Intégrations : paiement en ligne, banques, comptabilité externe, e-commerce | Fournisseurs API | G |

### Phase 7 — Mobile terrain

| # | Fonctionnalité | Origine fripv2 | Taille |
|---|---|---|---|
| 7.1 | Application livreur : tournées, navigation, signature, photos, scan à la livraison, hors-ligne | Tablettes de livraison | G |
| 7.2 | Ramassage et retour des actifs retournables | Ramassage | M |
| 7.3 | Inventaire mobile enrichi : lots, étiquettes, sessions de comptage | — | M |

---

## 5. Définition de « terminé » pour chaque fonctionnalité

- [ ] Migrations additives : aucune colonne existante modifiée ou supprimée ; `tenant_id` et `BelongsToTenant` sur les nouvelles tables
- [ ] Activation par module ou paramètre du tenant ; désactivée, aucun changement de comportement
- [ ] API et tests de fonctionnement et d'isolation entre tenants ; suite complète au vert en CI
- [ ] Écran web avec traductions françaises et anglaises
- [ ] Documentation mise à jour dans `docs/`
- [ ] Une PR par fonctionnalité, relue avant fusion
