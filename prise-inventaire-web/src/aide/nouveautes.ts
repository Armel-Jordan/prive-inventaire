/** Nouveautés de l'application, les plus récentes d'abord (aide : « À propos »). */
export interface Nouveaute {
  date: string;
  fr: string;
  en: string;
  /** Écran de la nouveauté. */
  lien?: string;
}

export const NOUVEAUTES: Nouveaute[] = [
  {
    date: '2026-10-26',
    fr: "Factures : un brouillon se modifie (crayon de la ligne) au lieu d'être supprimé puis refait ; il est verrouillé pendant sa modification.",
    en: 'Invoices: a draft can be edited (pencil on the row) instead of being deleted and recreated; it is locked while being edited.',
    lien: '/factures',
  },
  {
    date: '2026-10-26',
    fr: "Livraison partielle : le reste se livre avec un nouveau bon de la même facture (« Livrer le reste »), sans créer de facture qui le facturait une seconde fois.",
    en: 'Partial delivery: the rest is delivered with a new note from the same invoice (“Deliver the rest”), without creating an invoice that billed it a second time.',
    lien: '/factures',
  },
  {
    date: '2026-10-26',
    fr: "Droits : les pièces jointes suivent leur fiche (la voir pour les consulter, la modifier pour en ajouter ou en retirer) ; l'historique des courriels et le journal des modifications demandent leur droit.",
    en: 'Rights: attachments follow their record (view it to see them, edit it to add or remove them); the email history and the change log require their own right.',
    lien: '/pieces-jointes',
  },
  {
    date: '2026-10-18',
    fr: 'Représentants commerciaux : sur la fiche client, repris par ses devis, commandes et factures ; commission figée sur chaque facture et rapport des ventes par représentant.',
    en: 'Sales reps: on the customer record, carried over to their quotes, orders and invoices; commission frozen on each invoice and a sales-by-rep report.',
    lien: '/bibliotheque-rapports',
  },
  {
    date: '2026-10-18',
    fr: "Révisions de prix : de nouveaux prix préparés en lot (pourcentage ou montant, retouches), appliqués tout de suite ou à leur date d'entrée en vigueur.",
    en: 'Price revisions: new prices prepared in bulk (percentage or amount, manual edits), applied now or on their effective date.',
    lien: '/gestion-prix?onglet=revisions',
  },
  {
    date: '2026-10-18',
    fr: 'Règles de prix par catégorie de clients, et références des produits chez chaque client, reprises sur ses documents et dans son espace client.',
    en: "Price rules by customer category, and each customer's own product references, shown on their documents and in their customer area.",
    lien: '/gestion-prix',
  },
  {
    date: '2026-10-17',
    fr: "Aide contextuelle : bouton « ? » et touche F1 sur chaque écran, carte des écrans, recherche d'un écran dans la recherche globale.",
    en: 'Contextual help: “?” button and F1 key on every screen, screen map, screens in the global search.',
    lien: '/aide',
  },
  {
    date: '2026-10-17',
    fr: 'Fiches en cours de modification : une fiche ouverte en modification est verrouillée ; les autres comptes voient qui la modifie, un responsable peut prendre la main.',
    en: 'Records being edited: a record opened for editing is locked; other accounts see who is editing it, and a manager can take over.',
    lien: '/fiches-en-cours',
  },
  {
    date: '2026-10-17',
    fr: "Support et suggestions : écrivez à l'éditeur du logiciel depuis n'importe quel écran et suivez ses réponses.",
    en: 'Support and suggestions: write to the software publisher from any screen and follow the answers.',
    lien: '/support',
  },
  {
    date: '2026-10-17',
    fr: "Messages de l'application dans la langue de l'écran, en français ou en anglais.",
    en: 'Application messages in the language of the screen, French or English.',
  },
  {
    date: '2026-10-17',
    fr: "Bibliothèque de rapports : rapports à l'écran, en CSV, Excel ou PDF, envois par courriel et envois programmés, questions posées à l'IA.",
    en: 'Report library: reports on screen, as CSV, Excel or PDF, sent by email or on a schedule, and questions asked to the AI.',
    lien: '/bibliotheque-rapports',
  },
  {
    date: '2026-10-17',
    fr: "Filtres mémorisés : enregistrez les filtres d'un écran et appliquez-en un par défaut à l'ouverture.",
    en: 'Saved filters: save the filters of a screen and apply one by default when it opens.',
  },
  {
    date: '2026-10-17',
    fr: 'Lots et numéros de série : réception, livraison, retours, péremptions et rappel de lot.',
    en: 'Lots and serial numbers: receipt, delivery, returns, expiry dates and lot recall.',
    lien: '/lots',
  },
  {
    date: '2026-10-17',
    fr: "Entrepôts : stock par entrepôt, secteurs rattachés à un entrepôt, adresse de livraison des commandes fournisseurs.",
    en: 'Warehouses: stock by warehouse, sectors attached to a warehouse, delivery address of purchase orders.',
    lien: '/stock-entrepots',
  },
  {
    date: '2026-10-17',
    fr: "Produits : codes-barres, étiquettes, unités de vente et d'achat, ensembles, annexes et équivalents, fiche enrichie avec image et traduction.",
    en: 'Products: barcodes, labels, sales and purchase units, kits, add-ons and equivalents, richer record with image and translation.',
    lien: '/produits',
  },
  {
    date: '2026-10-17',
    fr: 'Finance : banque et rapprochement bancaire, relevés importés, budgets, rapport des taxes, chèques et virements fournisseurs.',
    en: 'Finance: bank and bank reconciliation, imported statements, budgets, tax report, supplier cheques and transfers.',
    lien: '/banque',
  },
  {
    date: '2026-10-17',
    fr: "Achats : suggestions d'achat, envoi des bons de commande, réceptions numérotées avec coûts annexes, factures fournisseurs, déboursés et paiements dus.",
    en: 'Purchasing: purchase suggestions, emailed purchase orders, numbered receipts with landed costs, supplier invoices, supplier payments and payments due.',
    lien: '/factures-fournisseurs',
  },
  {
    date: '2026-10-17',
    fr: 'Ventes : abonnements, contrats de location, consignation, point de vente, caisses et cartes cadeaux.',
    en: 'Sales: subscriptions, rental contracts, consignment, point of sale, cash registers and gift cards.',
    lien: '/point-de-vente',
  },
];
