import type { AidesEcrans } from '../types';

/** Aide des écrans de la catégorie Inventaire (phase 6.6). */
export const AIDES_INVENTAIRE: AidesEcrans = {
  "/scans": {
    fr: {
      resume: "Historique de tous les scans d'inventaire, de l'application mobile comme du web : recherchez-les, exportez-les, corrigez une quantité, supprimez un scan erroné ou saisissez un comptage à la main.",
      etapes: [
        "Cliquez sur « Afficher les filtres », puis choisissez le produit, le secteur, l'employé ou les dates : la liste et le nombre de résultats se mettent à jour.",
        "Pour saisir un comptage à la main, cliquez sur « Nouveau scan », choisissez le produit, la quantité, le secteur et l'employé, puis « Enregistrer ».",
        "Pour corriger une quantité, cliquez sur le crayon de la ligne, changez la « Quantité » puis cliquez sur « Enregistrer ».",
        "Pour supprimer plusieurs scans, cochez-les puis cliquez sur « Supprimer la sélection ».",
        "Cliquez sur « Exporter » pour télécharger en CSV tous les scans filtrés, pas seulement la page affichée.",
      ],
      astuces: [
        "« Mes filtres » enregistre vos filtres sous un nom ; l'étoile applique un filtre à chaque ouverture de l'écran.",
        "Modifier ou supprimer un scan change le compté d'une prise d'inventaire en cours, mais pas celui d'une prise déjà validée.",
        "Le stock des écrans Alertes Stock et Stock par entrepôt est la somme des scans : un scan supprimé n'y compte plus.",
        "Si vous avez des entrepôts, le filtre « Entrepôt » montre les scans d'un entrepôt et limite la liste des secteurs aux siens.",
      ],
      motsCles: ["scans", "comptage", "historique des scans", "saisie d'inventaire", "saisie manuelle"],
    },
    en: {
      resume: "History of every stock count scan, from the mobile app or the web: search and export them, correct a quantity, delete a wrong scan or enter a count by hand.",
      etapes: [
        "Click “Afficher les filtres”, then choose the product, sector, employee or dates: the list and the number of results update.",
        "To enter a count by hand, click “Nouveau scan”, choose the product, quantity, sector and employee, then click “Enregistrer”.",
        "To correct a quantity, click the pencil on the row, change the “Quantité” and click “Enregistrer”.",
        "To delete several scans, tick them and click “Supprimer la sélection”.",
        "Click “Exporter” to download all filtered scans as CSV, not just the page shown.",
      ],
      astuces: [
        "“My filters” saves your filters under a name; the star applies a filter every time the screen opens.",
        "Editing or deleting a scan changes the counted quantity of a stocktake in progress, but not that of a stocktake already validated.",
        "The stock shown in Stock Alerts and Stock by warehouse is the sum of scans: a deleted scan no longer counts there.",
        "If you have warehouses, the “Warehouse” filter shows the scans of one warehouse and narrows the sector list to its sectors.",
      ],
      motsCles: ["scans", "stock count", "scan history", "count entry", "manual count"],
    },
  },

  "/prises-inventaire": {
    fr: {
      resume: "Organisez des sessions de comptage par-dessus les scans : chaque prise regroupe les scans de ses secteurs pendant sa période, les compare au dernier inventaire validé, puis forme l'inventaire officiel une fois validée.",
      etapes: [
        "Cliquez sur « Nouvelle prise d'inventaire », puis saisissez le « Libellé » et le « Début du comptage ».",
        "Cochez les secteurs à compter, ou « Tous les secteurs » ; avec des entrepôts, un clic coche tous les secteurs d'un entrepôt. Cliquez sur « Créer ».",
        "Faites scanner les secteurs comme d'habitude (application mobile ou écran Inventaires) : les scans de la période sont repris automatiquement.",
        "Cliquez sur le numéro d'une prise pour suivre ses écarts et son avancement, corriger des lignes, puis la valider.",
        "Dans l'onglet « Inventaire officiel », consultez, secteur par secteur, les quantités de la dernière prise validée.",
      ],
      astuces: [
        "Deux prises en cours ne peuvent pas partager un secteur : validez ou annulez la première avant d'en créer une autre.",
        "Le « Début du comptage » vaut maintenant par défaut : les scans saisis avant ne comptent pas pour la session.",
        "L'inventaire officiel sert de référence à la prise suivante et à l'écran Valorisation.",
        "Créer, corriger, valider ou annuler une prise demande la permission « Gérer les prises d'inventaire ».",
      ],
      motsCles: ["inventaire physique", "comptage", "session de comptage", "inventaire annuel", "inventaire officiel"],
    },
    en: {
      resume: "Run counting sessions on top of your scans: each stocktake gathers the scans of its sectors during its period, compares them with the last validated inventory and, once validated, becomes the official inventory.",
      etapes: [
        "Click “New stocktake”, then enter the “Label” and the “Counting start”.",
        "Tick the sectors to count, or “All sectors”; with warehouses, one click ticks all of a warehouse's sectors. Click “Create”.",
        "Have the sectors scanned as usual (mobile app or Inventory screen): scans of the period are picked up automatically.",
        "Click a stocktake number to follow its variances and progress, correct lines, then validate it.",
        "In the “Official inventory” tab, see, sector by sector, the quantities of the last validated stocktake.",
      ],
      astuces: [
        "Two stocktakes in progress cannot share a sector: validate or cancel the first one before creating another.",
        "The “Counting start” defaults to now: scans entered before it do not count for the session.",
        "The official inventory is the reference for the next stocktake and for the Valuation screen.",
        "Creating, correcting, validating or cancelling a stocktake requires the “Manage stocktakes” permission.",
      ],
      motsCles: ["stocktake", "physical inventory", "stock count", "counting session", "annual inventory", "official inventory"],
    },
  },

  "/prises-inventaire/:id": {
    fr: {
      resume: "Détail d'une prise d'inventaire : comparez chaque produit compté au dernier inventaire validé, suivez l'avancement par secteur, corrigez les lignes, puis validez la prise pour former l'inventaire officiel.",
      etapes: [
        "Dans l'onglet « Écarts », filtrez par « Statut » (« Écart », « Non compté », « Nouveau »…), par « Secteur » ou par produit.",
        "Comparez « Compté », « Dernier inventaire » et « Écart » ; survolez un statut pour son explication, et « Mouvements depuis » aide à expliquer un écart.",
        "Pour retenir une autre quantité, cliquez sur « Corriger », saisissez la quantité et la « Raison », puis « Enregistrer ».",
        "Dans l'onglet « Avancement », vérifiez que chaque secteur a été scanné ; « Actualiser » reprend les scans arrivés entre-temps.",
        "Quand le comptage est terminé, cliquez sur « Valider la prise », lisez le résumé puis confirmez avec « Valider ».",
      ],
      astuces: [
        "À la validation, les produits « Non compté » sont validés à 0, sauf correction : vérifiez-les avant de valider.",
        "Une prise validée est figée : les scans saisis ensuite ne la changent plus.",
        "« Annuler la session » clôt la prise sans rien valider ; les scans ne sont pas supprimés et les secteurs sont libérés.",
        "« Exporter (CSV) » suit les filtres affichés ; « Revenir au comptage » retire une correction.",
      ],
      motsCles: ["écarts d'inventaire", "validation d'inventaire", "correction de comptage", "avancement du comptage", "non comptés"],
    },
    en: {
      resume: "One stocktake in detail: compare each counted product with the last validated inventory, follow progress by sector, correct lines, then validate the stocktake to form the official inventory.",
      etapes: [
        "In the “Variances” tab, filter by “Status” (“Variance”, “Not counted”, “New”…), by “Sector” or by product.",
        "Compare “Counted”, “Last inventory” and “Variance”; hover over a status for its meaning, and “Movements since” helps explain a variance.",
        "To keep a different quantity, click “Correct”, enter the quantity and the “Reason”, then click “Save”.",
        "In the “Progress” tab, check that every sector has been scanned; “Refresh” picks up the scans that arrived in the meantime.",
        "When counting is done, click “Validate stocktake”, read the summary and confirm with “Validate”.",
      ],
      astuces: [
        "On validation, “Not counted” products are validated at 0 unless corrected: check them before validating.",
        "A validated stocktake is frozen: scans entered afterwards no longer change it.",
        "“Cancel the session” closes the stocktake without validating anything; scans are not deleted and the sectors are freed.",
        "“Export (CSV)” follows the filters shown; “Back to the count” removes a correction.",
      ],
      motsCles: ["inventory variances", "validate stocktake", "count correction", "count progress", "uncounted products"],
    },
  },

  "/valorisation-inventaire": {
    fr: {
      resume: "Valeur de l'inventaire officiel : les quantités de la dernière prise d'inventaire validée de chaque secteur, multipliées par un coût unitaire (coût moyen pondéré, dernier coût d'achat ou prix de vente).",
      etapes: [
        "Choisissez la « Méthode » : « Coût moyen pondéré », « Dernier coût d'achat » ou « Prix de vente » ; son mode de calcul s'affiche sous les filtres.",
        "Limitez si besoin à un « Entrepôt » ou à un « Secteur » ; « Recherche » filtre le tableau « Par produit » (numéro, description, catégorie).",
        "Lisez la « Valeur totale », les « Produits valorisés » et les « Produits sans coût ».",
        "Consultez le détail « Par entrepôt » (si vos secteurs en ont), « Par secteur » (prise de référence, valeur) et « Par produit » (quantité, coût unitaire, valeur).",
        "Cliquez sur « Exporter » pour télécharger en CSV la valorisation de la méthode, de l'entrepôt et du secteur choisis.",
      ],
      astuces: [
        "Seules les prises validées comptent : sans prise validée, l'écran reste vide (voir Prises d'inventaire).",
        "Un produit sans coût est compté sans valeur : aucune commande fournisseur pour les méthodes d'achat, ou pas de prix unitaire sur sa fiche pour « Prix de vente ».",
        "Rien n'est enregistré : la valeur est recalculée à chaque consultation, avec les coûts du moment.",
        "Il faut le droit de voir « Valorisation de l'inventaire » (Rôles et permissions), car l'écran révèle les coûts d'achat.",
      ],
      motsCles: ["valeur du stock", "valorisation des stocks", "coût moyen pondéré", "dernier coût d'achat", "évaluation de l'inventaire"],
    },
    en: {
      resume: "Value of the official inventory: the quantities of each sector's last validated stocktake, multiplied by a unit cost (weighted average cost, last purchase cost or selling price).",
      etapes: [
        "Choose the “Method”: “Weighted average cost”, “Last purchase cost” or “Selling price”; how it is calculated appears below the filters.",
        "Narrow it down to a “Warehouse” or a “Sector” if needed; “Search” filters the “By product” table (number, description, category).",
        "Read the “Total value”, the “Valued products” and the “Products without cost”.",
        "Review the detail “By warehouse” (if your sectors have one), “By sector” (reference stocktake, value) and “By product” (quantity, unit cost, value).",
        "Click “Export” to download the valuation as CSV, for the method, warehouse and sector chosen.",
      ],
      astuces: [
        "Only validated stocktakes count: without one, the screen stays empty (see Stocktakes).",
        "A product without cost is counted with no value: no supplier order for the purchase methods, or no unit price on its record for “Selling price”.",
        "Nothing is stored: the value is recalculated each time you open the screen, with current costs.",
        "You need the right to view “Inventory valuation” (Roles and permissions), because the screen reveals purchase costs.",
      ],
      motsCles: ["stock value", "inventory valuation", "weighted average cost", "last purchase cost", "stock valuation"],
    },
  },

  "/stock-entrepots": {
    fr: {
      resume: "Le stock de chaque produit réparti par entrepôt : la somme de ses scans, répartie selon l'entrepôt du secteur où ils ont été faits, avec les totaux par entrepôt.",
      etapes: [
        "Tapez un numéro ou une description de produit dans « Recherche ».",
        "Choisissez un « Entrepôt » pour ne garder que les produits qui y ont du stock.",
        "Lisez une colonne par entrepôt (son code), puis « Sans entrepôt » et « Total » ; la ligne « Totaux » additionne les produits affichés.",
        "Cliquez sur « Exporter (CSV) » pour télécharger le tableau avec la recherche et l'entrepôt choisis.",
      ],
      astuces: [
        "Les scans d'un secteur rangé dans aucun entrepôt, ou inconnu des paramètres, comptent dans « Sans entrepôt ».",
        "Créez les entrepôts dans Référentiels (liste « Entrepôts »), puis rangez-y vos secteurs dans l'écran Secteurs.",
        "Un entrepôt désactivé garde sa colonne, en italique, tant qu'il a du stock ; survolez un code pour voir le nom de l'entrepôt.",
        "Ce stock vient des scans, pas d'une prise validée : pour la valeur de l'inventaire officiel, utilisez Valorisation.",
      ],
      motsCles: ["stock par entrepôt", "multi-entrepôts", "dépôt", "magasin", "niveau de stock", "stock disponible"],
    },
    en: {
      resume: "Each product's stock split by warehouse: the sum of its scans, assigned to the warehouse of the sector where they were made, with totals per warehouse.",
      etapes: [
        "Type a product number or description in “Search”.",
        "Choose a “Warehouse” to keep only the products that have stock there.",
        "Read one column per warehouse (its code), then “No warehouse” and “Total”; the “Totals” row adds up the products shown.",
        "Click “Export (CSV)” to download the table with the search and warehouse chosen.",
      ],
      astuces: [
        "Scans from a sector with no warehouse, or unknown in the settings, count under “No warehouse”.",
        "Create warehouses in Reference data (“Warehouses” list), then assign your sectors to them in the Sectors screen.",
        "A deactivated warehouse keeps its column, in italics, as long as it has stock; hover over a code to see the warehouse name.",
        "This stock comes from scans, not from a validated stocktake: for the value of the official inventory, use Valuation.",
      ],
      motsCles: ["stock by warehouse", "multi-warehouse", "depot", "store", "stock levels", "stock on hand"],
    },
  },

  "/lots": {
    fr: {
      resume: "Registre des lots reçus (péremption, quantité reçue et reste) et des numéros de série des produits suivis, avec la liste des clients qui ont reçu un lot en cas de rappel.",
      etapes: [
        "Dans l'onglet « Lots », recherchez un lot ou un produit, filtrez par « Péremption » (« Périmés », « À surveiller »…) ou cochez « Avec du stock seulement ».",
        "Cliquez sur un numéro de lot pour ouvrir son détail : ses mouvements (réceptions, livraisons, ventes au comptoir…) et les clients qui l'ont reçu.",
        "En cas de rappel, cliquez sur « Exporter le rappel (CSV) » pour obtenir la liste des clients avec leurs coordonnées.",
        "Pour corriger la péremption ou les notes d'un lot, modifiez-les dans son détail puis cliquez sur « Enregistrer ».",
        "Dans l'onglet « Numéros de série », recherchez un numéro ou un produit et filtrez par « Statut » (« En stock », « Livré », « Retourné », « Rebut »).",
      ],
      astuces: [
        "Le suivi par lot ou par numéro de série se règle sur la fiche du produit (écran Produits, « Suivi des exemplaires ») ; lots et numéros se saisissent dans Réceptions.",
        "« À surveiller » : péremption dans le préavis de la tâche « Péremption des lots », qu'un administrateur règle dans Réglage des alertes.",
        "Corriger un lot demande le droit de modifier « Lots et numéros de série ».",
        "« Exporter (CSV) » de l'onglet « Lots » suit la recherche et les filtres affichés.",
      ],
      motsCles: ["lot", "numéro de série", "péremption", "date limite", "rappel de lot", "traçabilité des lots"],
    },
    en: {
      resume: "Register of the lots received (expiry, quantity received and left) and of the serial numbers of tracked products, with the customers who received a lot in case of a recall.",
      etapes: [
        "In the “Lots” tab, search for a lot or product, filter by “Expiry” (“Expired”, “To watch”…) or tick “With stock only”.",
        "Click a lot number to open its details: its movements (receipts, deliveries, counter sales…) and the customers who received it.",
        "For a recall, click “Export the recall (CSV)” to get the list of customers with their contact details.",
        "To correct a lot's expiry date or notes, change them in its details and click “Save”.",
        "In the “Serial numbers” tab, search for a number or product and filter by “Status” (“In stock”, “Delivered”, “Returned”, “Scrapped”).",
      ],
      astuces: [
        "Lot or serial number tracking is set on the product record (Products screen, “Item tracking”); lots and numbers are entered in Réceptions.",
        "“To watch”: expiring within the notice period of the “Lot expiry” task, which an administrator sets in Alert settings.",
        "Correcting a lot requires the right to edit “Lots and serial numbers”.",
        "“Export (CSV)” in the “Lots” tab follows the search and filters shown.",
      ],
      motsCles: ["lot", "batch", "serial number", "expiry date", "lot recall", "batch tracking"],
    },
  },

  "/statistiques": {
    fr: {
      resume: "Activité de comptage sur les 7, 30 ou 90 derniers jours : nombre de scans, moyenne par jour, secteurs et employés actifs, et graphiques par jour, par secteur, par type et par employé.",
      etapes: [
        "Choisissez la période en haut à droite : « 7 derniers jours », « 30 derniers jours » ou « 90 derniers jours ».",
        "Lisez les indicateurs « Total scans », « Moyenne/jour », « Secteurs actifs » et « Employés actifs ».",
        "Suivez le rythme du comptage dans « Évolution des scans », puis la répartition dans « Scans par secteur », « Répartition par type » et « Top 10 employés ».",
      ],
      astuces: [
        "Les chiffres comptent des scans (des saisies), pas des quantités.",
        "« Moyenne/jour » divise le total par le nombre de jours où au moins un scan a été saisi.",
        "« Scans par secteur » montre les 8 secteurs les plus actifs.",
        "Pour le détail des scans d'une période, utilisez l'écran Inventaires et ses filtres de dates.",
      ],
      motsCles: ["statistiques des scans", "activité de comptage", "graphiques", "productivité", "suivi du comptage"],
    },
    en: {
      resume: "Counting activity over the last 7, 30 or 90 days: number of scans, daily average, active sectors and employees, with charts by day, sector, type and employee.",
      etapes: [
        "Choose the period at the top right: “7 derniers jours”, “30 derniers jours” or “90 derniers jours”.",
        "Read the “Total scans”, “Moyenne/jour”, “Secteurs actifs” and “Employés actifs” indicators.",
        "Follow the counting pace in “Évolution des scans”, then the breakdown in “Scans par secteur”, “Répartition par type” and “Top 10 employés”.",
      ],
      astuces: [
        "The figures count scans (entries), not quantities.",
        "“Moyenne/jour” divides the total by the number of days with at least one scan.",
        "“Scans par secteur” shows the 8 most active sectors.",
        "For the detail of a period's scans, use the Inventory screen and its date filters.",
      ],
      motsCles: ["scan statistics", "counting activity", "charts", "productivity", "count tracking"],
    },
  },

  "/comparaison": {
    fr: {
      resume: "Comparez, produit par produit, les quantités scannées à deux dates : augmentations, diminutions et produits stables, avec la différence et la variation en pourcentage.",
      etapes: [
        "Choisissez la « Première date » et la « Seconde date » : l'écran propose le jour du premier scan et celui du dernier.",
        "Lisez pour chaque produit la quantité de chaque date, la « Différence » et la « Variation ».",
        "Cliquez sur « Augmentations », « Diminutions » ou « Stables » pour ne garder que ces produits ; « Total produits » réaffiche tout.",
        "Cliquez sur « Exporter » pour télécharger en CSV les lignes affichées.",
      ],
      astuces: [
        "Chaque date additionne les scans de toute la journée, tous secteurs confondus ; un produit non scanné ce jour-là y vaut 0.",
        "La variation se calcule par rapport à la première date ; les plus grands écarts apparaissent en premier.",
        "Pour comparer un comptage au dernier inventaire validé, avec corrections et validation, utilisez Prises d'inventaire.",
      ],
      motsCles: ["comparer deux dates", "évolution des quantités", "variation", "augmentations", "diminutions"],
    },
    en: {
      resume: "Compare, product by product, the quantities scanned on two dates: increases, decreases and stable products, with the difference and the percentage change.",
      etapes: [
        "Choose the “Première date” and the “Seconde date”: the screen suggests the day of the first scan and that of the last one.",
        "For each product, read the quantity on each date, the “Différence” and the “Variation”.",
        "Click “Augmentations”, “Diminutions” or “Stables” to keep only those products; “Total produits” shows everything again.",
        "Click “Exporter” to download the rows shown as CSV.",
      ],
      astuces: [
        "Each date adds up the scans of the whole day, across all sectors; a product not scanned that day counts as 0.",
        "The change is calculated against the first date; the largest differences come first.",
        "To compare a count with the last validated inventory, with corrections and validation, use Stocktakes.",
      ],
      motsCles: ["compare two dates", "quantity change", "variation", "increases", "decreases"],
    },
  },

  "/alertes": {
    fr: {
      resume: "Les produits passés sous leur seuil d'alerte, du plus critique au moins urgent, et le réglage du seuil minimum et du maximum de réassort de chaque produit.",
      etapes: [
        "Lisez les indicateurs : « Produits surveillés », « Critiques », « Avertissements » et « Stock OK ».",
        "Cliquez sur « Critiques » ou « Avertissements » pour filtrer la liste ; « Effacer » retire le filtre.",
        "Comparez pour chaque produit le « Stock actuel », le « Seuil » et le « Déficit ».",
        "Pour régler un seuil, cliquez sur « Configurer seuils », saisissez le « Seuil minimum » et, si besoin, le « Maximum (réassort) », puis « Sauver » sur la ligne.",
        "Cliquez sur « Actualiser » après de nouveaux scans.",
      ],
      astuces: [
        "Le stock actuel est la somme de tous les scans du produit ; seuls les produits actifs dont le seuil dépasse 0 sont surveillés.",
        "Niveau : critique jusqu'à 25 % du seuil, avertissement jusqu'à 50 %, information au-delà.",
        "Le « Maximum (réassort) » est la quantité visée par les Suggestions d'achat ; vide, c'est le seuil. Il ne peut pas être inférieur au seuil.",
        "Pour recevoir une notification, un administrateur active « Alertes de stock » dans Réglage des alertes.",
      ],
      motsCles: ["seuil d'alerte", "rupture de stock", "stock minimum", "réapprovisionnement", "stock bas"],
    },
    en: {
      resume: "Products whose stock has fallen below their alert threshold, from most critical to least urgent, and the setting of each product's minimum threshold and restocking maximum.",
      etapes: [
        "Read the indicators: “Produits surveillés”, “Critiques”, “Avertissements” and “Stock OK”.",
        "Click “Critiques” or “Avertissements” to filter the list; “Effacer” removes the filter.",
        "For each product, compare the “Stock actuel”, the “Seuil” and the “Déficit”.",
        "To set a threshold, click “Configurer seuils”, enter the “Seuil minimum” and, if needed, the “Maximum (réassort)”, then click “Sauver” on that row.",
        "Click “Actualiser” after new scans.",
      ],
      astuces: [
        "Current stock is the sum of all the product's scans; only active products with a threshold above 0 are monitored.",
        "Level: critical up to 25% of the threshold, warning up to 50%, information above that.",
        "The “Maximum (réassort)” is the quantity Purchase suggestions aim for; left empty, the threshold is used. It cannot be below the threshold.",
        "To get a notification, an administrator turns on “Stock alerts” in Alert settings.",
      ],
      motsCles: ["alert threshold", "stockout", "minimum stock", "reorder point", "low stock"],
    },
  },

  "/audit": {
    fr: {
      resume: "Journal des créations, modifications et suppressions des fiches et des documents : qui a fait quoi et quand, avec les valeurs avant et après.",
      etapes: [
        "Filtrez par action (« Créations », « Modifications », « Suppressions ») et par type de fiche ou de document.",
        "Cliquez sur une ligne pour afficher les « Anciennes valeurs », les « Nouvelles valeurs » et l'adresse IP.",
        "Cliquez sur « Actualiser » pour voir les dernières actions.",
      ],
      astuces: [
        "L'écran montre les 100 actions les plus récentes qui répondent aux filtres.",
        "Les compteurs du haut portent sur aujourd'hui et sur le mois en cours.",
        "Les scans et les mouvements n'y figurent que lorsqu'ils sont modifiés ou supprimés.",
        "Les mots de passe et les codes de double authentification ne sont jamais enregistrés dans le journal.",
      ],
      motsCles: ["journal des modifications", "audit", "historique des modifications", "qui a modifié", "piste d'audit"],
    },
    en: {
      resume: "Log of creations, changes and deletions of records and documents: who did what and when, with the values before and after.",
      etapes: [
        "Filter by action (“Créations”, “Modifications”, “Suppressions”) and by type of record or document.",
        "Click an entry to show the “Anciennes valeurs”, the “Nouvelles valeurs” and the IP address.",
        "Click “Actualiser” to see the latest actions.",
      ],
      astuces: [
        "The screen shows the 100 most recent actions matching the filters.",
        "The counters at the top cover today and the current month.",
        "Scans and movements only appear when they are changed or deleted.",
        "Passwords and two-factor authentication codes are never written to the log.",
      ],
      motsCles: ["audit log", "change log", "audit trail", "who changed", "activity log"],
    },
  },

  "/tracabilite": {
    fr: {
      resume: "Retrouvez l'historique complet d'un produit : ses mouvements et ses scans dans un même fil chronologique, sa dernière localisation connue et les secteurs où il est passé.",
      etapes: [
        "Tapez au moins 2 caractères du numéro ou du nom du produit, puis choisissez-le dans la liste ou appuyez sur Entrée.",
        "Lisez « Dernière localisation », « Mouvements », « Inventaires » (nombre de scans) et « Secteurs visités ».",
        "Parcourez l'historique, du plus récent au plus ancien : type, secteurs, quantité, employé, motif et date.",
      ],
      astuces: [
        "La dernière localisation est le secteur du dernier mouvement vers un secteur ou du dernier scan, selon le plus récent ; elle est en bleu dans « Secteurs visités ».",
        "La liste ne propose que les produits qui ont déjà au moins un mouvement ou un scan.",
        "Pour suivre un lot ou un numéro de série jusqu'au client, utilisez Lots et numéros de série.",
      ],
      motsCles: ["historique produit", "localisation", "où est le produit", "parcours produit", "suivi produit"],
    },
    en: {
      resume: "Find a product's full history: its movements and scans in a single timeline, its last known location and the sectors it has been in.",
      etapes: [
        "Type at least 2 characters of the product number or name, then pick it from the list or press Enter.",
        "Read “Dernière localisation”, “Mouvements”, “Inventaires” (number of scans) and “Secteurs visités”.",
        "Browse the history from newest to oldest: type, sectors, quantity, employee, reason and date.",
      ],
      astuces: [
        "The last location is the sector of the latest movement into a sector or of the latest scan, whichever is more recent; it is shown in blue under “Secteurs visités”.",
        "The list only suggests products that already have at least one movement or scan.",
        "To follow a lot or serial number to the customer, use Lots and serial numbers.",
      ],
      motsCles: ["product history", "location", "where is the product", "product tracking", "track and trace"],
    },
  },

  "/relocalisation": {
    fr: {
      resume: "Enregistrez les mouvements de stock entre secteurs (arrivages, transferts, sorties, ajustements), un par un ou en lot, et consultez leur historique.",
      etapes: [
        "Cliquez sur « Arrivage » (entrée dans un secteur), « Transfert » (d'un secteur à un autre) ou « Mouvement » pour choisir aussi « Sortie » ou « Ajustement ».",
        "Saisissez le « N° Produit », le ou les secteurs demandés, la « Quantité », l'unité et le « Motif », puis cliquez sur « Enregistrer ».",
        "Pour plusieurs produits, cliquez sur « Scan en lot » : choisissez le type et les secteurs, ajoutez chaque produit avec « Ajouter », puis enregistrez le tout.",
        "Filtrez l'« Historique des mouvements » par type ; chaque mouvement est enregistré au nom du compte connecté.",
      ],
      astuces: [
        "Une quantité qui atteint le seuil d'approbation de son type attend une décision dans Approbations avant d'être enregistrée ; un ajustement n'y est jamais soumis.",
        "Un mouvement enregistré ne se modifie pas : pour corriger, enregistrez le mouvement inverse ou un ajustement.",
        "Un transfert exige deux secteurs différents.",
        "Les mouvements ne modifient pas le stock, qui vient des scans : ils aident à expliquer les écarts d'une prise d'inventaire (colonne « Mouvements depuis »).",
      ],
      motsCles: ["mouvement de stock", "transfert", "arrivage", "sortie de stock", "ajustement", "déplacement"],
    },
    en: {
      resume: "Record stock movements between sectors (arrivals, transfers, exits, adjustments), one at a time or in a batch, and review their history.",
      etapes: [
        "Click “Arrivage” (into a sector), “Transfert” (from one sector to another) or “Mouvement” to also choose “Sortie” or “Ajustement”.",
        "Enter the “N° Produit”, the sector(s) required, the “Quantité”, the unit and the “Motif”, then click “Enregistrer”.",
        "For several products, click “Scan en lot”: choose the type and sectors, add each product with “Ajouter”, then save them all at once.",
        "Filter the “Historique des mouvements” by type; each movement is recorded in the name of the signed-in account.",
      ],
      astuces: [
        "A quantity that reaches the approval threshold of its type waits for a decision in Approvals before being recorded; adjustments never need approval.",
        "A recorded movement cannot be edited: to correct it, record the reverse movement or an adjustment.",
        "A transfer requires two different sectors.",
        "Movements do not change the stock, which comes from scans: they help explain a stocktake's variances (“Movements since” column).",
      ],
      motsCles: ["stock movement", "transfer", "arrival", "stock issue", "adjustment", "move"],
    },
  },

  "/planification": {
    fr: {
      resume: "Programmez des arrivages, transferts ou sorties à une date future, puis exécutez-les le moment venu : l'exécution crée le mouvement dans Relocalisation.",
      etapes: [
        "Cliquez sur « Planifier un transfert ».",
        "Choisissez le « Type » et la « Date planifiée », saisissez le produit, les secteurs, la « Quantité » et l'« Employé responsable », puis cliquez sur « Planifier ».",
        "Filtrez la liste par statut : « Planifié » (à l'ouverture), « Exécuté », « Annulé » ou « Tous ».",
        "Le moment venu, cliquez sur l'icône « Exécuter » de la ligne ; « Annuler » abandonne le transfert sans créer de mouvement.",
      ],
      astuces: [
        "La date planifiée doit être dans le futur ; une ligne en rouge est un transfert planifié dont la date est passée.",
        "Seul un transfert « Planifié » se modifie, s'exécute ou s'annule ; un transfert exécuté ne peut pas être supprimé.",
        "Exécuter ou annuler demande la permission « Exécuter ou annuler un transfert planifié ».",
        "Si un administrateur active la tâche « Exécution des transferts planifiés » (Tâches planifiées), les transferts s'exécutent seuls à leur date.",
      ],
      motsCles: ["transfert planifié", "mouvement programmé", "planning des transferts", "programmation", "transfert futur"],
    },
    en: {
      resume: "Schedule arrivals, transfers or exits for a future date, then run them when the time comes: running one creates the movement in Relocation.",
      etapes: [
        "Click “Planifier un transfert”.",
        "Choose the “Type” and the “Date planifiée”, enter the product, sectors, “Quantité” and “Employé responsable”, then click “Planifier”.",
        "Filter the list by status: “Planifié” (when the screen opens), “Exécuté”, “Annulé” or “Tous”.",
        "When the time comes, click the “Exécuter” icon on the row; “Annuler” drops the transfer without creating a movement.",
      ],
      astuces: [
        "The planned date must be in the future; a red row is a planned transfer whose date has passed.",
        "Only a “Planifié” transfer can be edited, run or cancelled; a transfer that has been run cannot be deleted.",
        "Running or cancelling requires the “Run or cancel a planned transfer” permission.",
        "If an administrator turns on the “Run planned transfers” task (Scheduled tasks), transfers run by themselves on their date.",
      ],
      motsCles: ["planned transfer", "scheduled movement", "transfer schedule", "future transfer", "scheduling"],
    },
  },

  "/approbations": {
    fr: {
      resume: "Approuvez ou rejetez les mouvements dont la quantité atteint le seuil d'approbation de leur type : le mouvement n'est créé qu'après approbation.",
      etapes: [
        "La liste s'ouvre sur les demandes « En attente » ; les boutons de statut affichent aussi « Approuvé », « Rejeté » ou « Tous ».",
        "Vérifiez le produit, les secteurs, la quantité et le demandeur, puis cliquez sur « Approuver » ou « Rejeter ».",
        "Ajoutez un « Commentaire », obligatoire pour un rejet, puis cliquez sur « Confirmer ».",
        "Pour régler les seuils, cliquez sur « Paramètres » : une quantité par type de mouvement, cochez « Actif », puis « Enregistrer ».",
      ],
      astuces: [
        "Les seuils proposés au départ sont inactifs : cochez « Actif » pour les appliquer. Un ajustement n'est jamais soumis à approbation.",
        "Les demandes viennent de l'écran Relocalisation comme de l'application mobile ; le mouvement approuvé apparaît dans Relocalisation au nom du demandeur.",
        "Décider demande la permission « Approuver ou rejeter une demande » ; l'approbateur noté est toujours le compte connecté.",
        "Modifier les seuils demande le droit de modifier « Configuration ».",
      ],
      motsCles: ["validation des mouvements", "seuil d'approbation", "demande d'approbation", "autorisation", "workflow"],
    },
    en: {
      resume: "Approve or reject movements whose quantity reaches the approval threshold of their type: the movement is only created once approved.",
      etapes: [
        "The list opens on “En attente” requests; the status buttons also show “Approuvé”, “Rejeté” or “Tous”.",
        "Check the product, sectors, quantity and requester, then click “Approuver” or “Rejeter”.",
        "Add a “Commentaire”, required to reject, then click “Confirmer”.",
        "To set the thresholds, click “Paramètres”: one quantity per movement type, tick “Actif”, then click “Enregistrer”.",
      ],
      astuces: [
        "The thresholds offered at first are inactive: tick “Actif” to apply them. Adjustments never need approval.",
        "Requests come from the Relocation screen as well as from the mobile app; an approved movement appears in Relocation under the requester's name.",
        "Deciding requires the “Approve or reject a request” permission; the approver recorded is always the signed-in account.",
        "Changing the thresholds requires the right to edit “Configuration”.",
      ],
      motsCles: ["movement approval", "approval threshold", "approval request", "authorization", "workflow"],
    },
  },

  "/rapports": {
    fr: {
      resume: "Rapports mensuels des mouvements de stock : entrées et sorties par secteur, activité par employé, évolution sur l'année et produits les plus mouvementés.",
      etapes: [
        "Choisissez le mois et l'année en haut de l'écran.",
        "Ouvrez l'onglet voulu : « Par Secteur », « Par Employé », « Évolution Annuelle » ou « Top Produits ».",
        "Lisez les totaux, le graphique et le tableau de l'onglet.",
        "Cliquez sur « Exporter CSV » pour télécharger l'onglet affiché.",
      ],
      astuces: [
        "« Par Secteur » compte en entrées les arrivages et les transferts reçus, en sorties les sorties et les transferts partis ; les ajustements n'y figurent pas.",
        "« Évolution Annuelle » suit les mouvements et les scans mois par mois sur l'année choisie ; le mois choisi n'y compte pas.",
        "« Top Produits » classe les 10 produits qui ont eu le plus de mouvements dans le mois.",
        "Pour des rapports de ventes, d'achats ou de stock à envoyer par courriel, utilisez Bibliothèque de rapports.",
      ],
      motsCles: ["rapport mensuel", "mouvements par secteur", "activité des employés", "top produits", "évolution annuelle"],
    },
    en: {
      resume: "Monthly stock movement reports: incoming and outgoing by sector, activity by employee, trend over the year and the most moved products.",
      etapes: [
        "Choose the month and year at the top of the screen.",
        "Open the tab you need: “Par Secteur”, “Par Employé”, “Évolution Annuelle” or “Top Produits”.",
        "Read the tab's totals, chart and table.",
        "Click “Exporter CSV” to download the tab shown.",
      ],
      astuces: [
        "“Par Secteur” counts arrivals and transfers received as incoming, exits and transfers sent as outgoing; adjustments are not included.",
        "“Évolution Annuelle” follows movements and scans month by month over the chosen year; the month selected does not apply.",
        "“Top Produits” ranks the 10 products with the most movements in the month.",
        "For sales, purchasing or stock reports you can email, use the Report library.",
      ],
      motsCles: ["monthly report", "movements by sector", "employee activity", "top products", "yearly trend"],
    },
  },

  "/bibliotheque-rapports": {
    fr: {
      resume: "Les rapports de l'entreprise par domaine (ventes, clients, achats, inventaire, finance) : affichez-les, téléchargez-les en CSV, Excel ou PDF, envoyez-les par courriel ou programmez leur envoi.",
      etapes: [
        "Choisissez un rapport dans la liste, puis réglez ses paramètres (période, client, catégorie…).",
        "Cliquez sur « Afficher » ; cliquez sur un titre de colonne pour trier le tableau.",
        "Téléchargez le résultat avec « CSV », « Excel » ou « PDF », ou cliquez sur « Envoyer par courriel » et choisissez le format joint.",
        "Pour un envoi régulier, cliquez sur « Programmer l'envoi » : fréquence, jour, période, format, langue et destinataires, puis « Enregistrer ».",
        "Dans l'onglet « Questions à l'IA », posez une question sur votre activité : l'IA répond à partir des rapports et propose d'ouvrir celui qu'elle cite.",
      ],
      astuces: [
        "Seuls les rapports que vos droits permettent apparaissent ; les boutons d'envoi et l'onglet « Questions à l'IA » dépendent aussi de vos permissions.",
        "« Mes filtres » garde les paramètres d'un rapport sous un nom ; l'étoile les applique à l'ouverture du rapport.",
        "Dans « Envois programmés » : « Envoyer maintenant », modifier ou supprimer ; rien ne part si la tâche « Envoi des rapports programmés » est désactivée.",
        "La période d'un envoi programmé (la veille, le mois précédent…) est recalculée à chaque envoi ; l'envoi suit les droits du dernier compte à l'avoir réglé.",
      ],
      motsCles: ["rapports", "export excel", "export pdf", "envoi programmé", "questions à l'ia", "analyse"],
    },
    en: {
      resume: "Company reports by area (sales, customers, purchasing, inventory, finance): show them, download them as CSV, Excel or PDF, email them or schedule them to be emailed.",
      etapes: [
        "Pick a report from the list, then set its parameters (period, customer, category…).",
        "Click “Show”; click a column heading to sort the table.",
        "Download the result with “CSV”, “Excel” or “PDF”, or click “Send by email” and choose the attachment format.",
        "For a regular send, click “Schedule”: frequency, day, period, format, language and recipients, then click “Save”.",
        "In the “Ask the AI” tab, ask a question about your business: the AI answers from the reports and offers to open the one it cites.",
      ],
      astuces: [
        "Only the reports your rights allow are listed; the send buttons and the “Ask the AI” tab also depend on your permissions.",
        "“My filters” keeps a report's parameters under a name; the star applies them when the report opens.",
        "In “Scheduled sends”: “Send now”, edit or delete; nothing goes out if the “Scheduled report sends” task is turned off.",
        "A scheduled send's period (yesterday, the previous month…) is worked out again on each send; the send follows the rights of the last account that set it up.",
      ],
      motsCles: ["reports", "excel export", "pdf export", "scheduled report", "ai questions", "analytics"],
    },
  },

  "/inventaire-tournant": {
    fr: {
      resume: "Repérez les secteurs à recompter en priorité : un score combine l'ancienneté du dernier scan et les mouvements enregistrés depuis, et un planning répartit les secteurs sur les jours ouvrables du mois.",
      etapes: [
        "Consultez les indicateurs « Secteurs actifs », « Scannés ce mois », « Jamais scannés » et « Couverture mois » (part des secteurs scannés ce mois).",
        "Dans « Suggestions prioritaires », commencez par les secteurs en tête de liste (priorité « Critique » ou « Haute ») ; la raison est indiquée sous chacun.",
        "Faites scanner ces secteurs comme d'habitude (application mobile ou écran Inventaires), puis cliquez sur « Actualiser ».",
        "Dans « Planning mensuel », suivez la répartition proposée des secteurs, jour par jour.",
      ],
      astuces: [
        "Score = jours depuis le dernier scan + 2 points par mouvement depuis ; un secteur jamais scanné compte pour 365 jours.",
        "Priorité « Critique » à partir de 100 points, « Haute » à partir de 50, « Moyenne » à partir de 20, « Basse » en dessous.",
        "Le planning répartit tous les secteurs actifs du lundi au vendredi du mois en cours.",
        "Pour un comptage avec écarts, corrections et validation, créez une session dans Prises d'inventaire.",
      ],
      motsCles: ["inventaire tournant", "comptage cyclique", "inventaire permanent", "priorité de comptage", "planning de comptage"],
    },
    en: {
      resume: "Spot the sectors to recount first: a score combines how long ago each sector was last scanned and the movements recorded since, and a schedule spreads the sectors over the month's working days.",
      etapes: [
        "Check the “Secteurs actifs”, “Scannés ce mois”, “Jamais scannés” and “Couverture mois” (share of sectors scanned this month) indicators.",
        "In “Suggestions prioritaires”, start with the sectors at the top of the list (“Critique” or “Haute” priority); the reason is shown under each one.",
        "Have those sectors scanned as usual (mobile app or Inventory screen), then click “Actualiser”.",
        "In “Planning mensuel”, follow the suggested split of sectors, day by day.",
      ],
      astuces: [
        "Score = days since the last scan + 2 points per movement since; a sector never scanned counts as 365 days.",
        "“Critique” priority from 100 points, “Haute” from 50, “Moyenne” from 20, “Basse” below.",
        "The schedule spreads all active sectors over Monday to Friday of the current month.",
        "For a count with variances, corrections and validation, create a session in Stocktakes.",
      ],
      motsCles: ["cycle counting", "rotating inventory", "perpetual inventory", "count priority", "count schedule"],
    },
  },
};
