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
