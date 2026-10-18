import type { AidesEcrans } from '../types';

/** Aide des écrans de la catégorie Achats (phase 6.6). */
export const AIDES_ACHATS: AidesEcrans = {
  "/fournisseurs": {
    fr: {
      resume: "La liste des fournisseurs de l'entreprise, avec leurs coordonnées, leurs conditions de paiement et leur devise. Créez, modifiez ou désactivez un fournisseur, et ouvrez sa fiche complète en cliquant sur son code.",
      etapes: [
        "Cliquez sur « Ajouter », puis saisissez la « Raison sociale » et le « Code » s'il n'est pas généré automatiquement.",
        "Complétez l'adresse, le téléphone, l'email, le contact, les « Conditions de paiement » et la « Devise » (code ISO : EUR, USD, XAF…), puis cliquez sur « Créer ».",
        "Pour modifier un fournisseur, cliquez sur le crayon de sa ligne, faites vos changements, puis cliquez sur « Modifier ».",
        "Retrouvez un fournisseur avec la recherche (code, nom ou email) et cliquez sur son code pour ouvrir sa fiche.",
        "Pour mettre à jour des prix d'achat en masse, cliquez sur « Importer une grille de prix », choisissez le fichier CSV, puis cliquez sur « Simuler » et sur « Importer ».",
      ],
      astuces: [
        "Un fournisseur qui a des commandes ne peut pas être supprimé : décochez plutôt « Fournisseur actif ». Il disparaît alors des listes de choix (commandes, factures, déboursés…) mais garde son historique.",
        "Une fiche ouverte en modification est verrouillée : si un autre compte la modifie déjà, un bandeau indique qui et depuis quand, et l'enregistrement est bloqué. Cliquez sur « Réessayer », ou sur « Prendre la main » si vous en avez le droit.",
        "La devise proposée est celle de l'entreprise : changez-la pour un fournisseur qui facture dans une autre devise, ses commandes seront passées dans cette devise. Le code, lui, ne se modifie plus après la création.",
        "L'import d'une grille de prix ne crée ni produit ni lien : il met à jour le prix des produits déjà au catalogue du fournisseur (onglet « Produits fournis » de sa fiche) et liste les lignes ignorées avec leur motif.",
      ],
      motsCles: ["vendeur", "prestataire", "créancier", "grille de prix", "tarif fournisseur"],
    },
    en: {
      resume: "The list of the company's suppliers, with their contact details, payment terms and currency. Create, edit or deactivate a supplier, and click its code to open its full record.",
      etapes: [
        "Click “Ajouter”, then enter the “Raison sociale” (company name) and the “Code” if it is not generated automatically.",
        "Fill in the address, phone, email, contact, “Conditions de paiement” (payment terms) and “Devise” (ISO currency code: EUR, USD, XAF…), then click “Créer”.",
        "To edit a supplier, click the pencil icon on its row, make your changes, then click “Modifier”.",
        "Find a supplier with the search box (code, name or email) and click its code to open its record.",
        "To update purchase prices in bulk, click “Import a price list”, choose the CSV file, then click “Simulate” and “Import”.",
      ],
      astuces: [
        "A supplier that has purchase orders cannot be deleted: untick “Fournisseur actif” (active supplier) instead. It then disappears from the selection lists (orders, invoices, disbursements…) but keeps its history.",
        "A record opened for editing is locked: if another account is already editing it, a banner shows who and since when, and saving is blocked. Click “Try again”, or “Take over” if you have the right to.",
        "The proposed currency is the company's: change it for a supplier that invoices in another currency, and its purchase orders will be placed in that currency. The code cannot be changed after creation.",
        "Importing a price list creates neither products nor links: it updates the price of the products already in the supplier's catalog (“Supplied products” tab of its record) and lists the ignored lines with their reason.",
      ],
      motsCles: ["vendor", "vendors", "creditor", "price list", "supplier price list"],
    },
  },
  "/fournisseurs/:id": {
    fr: {
      resume: "La fiche complète d'un fournisseur : synthèse de vos achats et de ses livraisons, historique des lignes commandées, catalogue de ses produits, contacts, adresses, coordonnées bancaires et remarques.",
      etapes: [
        "Ouvrez la fiche en cliquant sur le code du fournisseur dans l'écran « Fournisseurs », ou depuis la recherche globale (Ctrl K).",
        "Dans « Synthèse », suivez vos achats de l'année, le reste à recevoir, le délai moyen de livraison, la part livrée à temps et les produits les plus achetés.",
        "Dans « Historique d'achats », filtrez par période (« Du », « Au ») ou par produit pour voir les quantités commandées et reçues.",
        "Dans « Produits fournis », cliquez sur « Ajouter un produit » : référence chez le fournisseur, prix d'achat convenu, unité d'achat, délai et « Fournisseur principal ».",
        "Dans « Contacts », « Adresses » et « Coordonnées bancaires », cliquez sur « Ajouter un contact », « Ajouter une adresse » ou « Ajouter un compte ».",
        "Dans « Remarques », notez un appel, un litige ou une consigne, cliquez sur « Ajouter », puis épinglez les remarques importantes.",
      ],
      astuces: [
        "L'adresse, les conditions de paiement et la devise de l'en-tête se modifient depuis l'écran « Fournisseurs ».",
        "Un produit n'a qu'un fournisseur principal : le cocher ici le retire chez les autres. C'est ce fournisseur que proposent les « Suggestions d'achat ».",
        "Les contacts cochés « Reçoit les bons de commande » sont proposés comme destinataires quand une commande est envoyée par courriel.",
        "Les coordonnées bancaires ne se saisissent qu'avec le droit « Gérer les coordonnées bancaires ». Le « Compte principal » est celui que créditent les « Virements » : vérifiez par téléphone tout changement avant de payer.",
      ],
      motsCles: ["fiche fournisseur", "catalogue fournisseur", "fournisseur principal", "délai de livraison", "rib", "iban"],
    },
    en: {
      resume: "A supplier's full record: summary of your purchases and its deliveries, history of the ordered lines, catalog of its products, contacts, addresses, bank details and notes.",
      etapes: [
        "Open the record by clicking the supplier's code in the “Fournisseurs” screen, or from the global search (Ctrl K).",
        "In “Summary”, follow your purchases this year, what is still to receive, the average lead time, the share delivered on time and the most purchased products.",
        "In “Purchase history”, filter by period (“From”, “To”) or by product to see the quantities ordered and received.",
        "In “Supplied products”, click “Add a product”: supplier reference, agreed purchase price, purchase unit, lead time and “Main supplier”.",
        "In “Contacts”, “Addresses” and “Bank details”, click “Add a contact”, “Add an address” or “Add an account”.",
        "In “Notes”, record a call, a dispute or an instruction, click “Add”, then pin the important notes.",
      ],
      astuces: [
        "The address, payment terms and currency shown in the header are edited from the “Fournisseurs” screen.",
        "A product has a single main supplier: ticking it here removes it from the others. “Purchase suggestions” propose this supplier.",
        "Contacts ticked “Receives purchase orders” are offered as recipients when a purchase order is sent by email.",
        "Bank details can only be entered with the “Manage bank details” right. The “Main account” is the one credited by “Transfers”: check any change by phone before paying.",
      ],
      motsCles: ["supplier record", "supplier catalog", "main supplier", "lead time", "bank account", "iban"],
    },
  },
  "/suggestions-achat": {
    fr: {
      resume: "Les produits à commander, proposés par fournisseur : stock passé sous le seuil d'alerte, reliquats clients pas encore commandés et besoins signalés depuis un produit. Les suggestions retenues deviennent des commandes fournisseur.",
      etapes: [
        "Cochez les origines voulues (inventaire sous le minimum, reliquats clients), choisissez au besoin un fournisseur, puis cliquez sur « Calculer les suggestions ».",
        "Dans l'onglet « À commander », ajustez la quantité « À commander », choisissez un fournisseur pour les produits qui n'en ont pas et ajoutez une remarque au besoin.",
        "Cochez « Accepter » sur les lignes à garder, ou cliquez sur « Tout accepter » ; retirez celles à abandonner avec la corbeille.",
        "Cliquez sur « Générer les commandes » : une commande brouillon est créée par fournisseur, à valider et envoyer depuis l'écran « Commandes ».",
        "L'onglet « Commandées (30 jours) » montre les suggestions déjà commandées, avec le numéro de leur commande.",
      ],
      astuces: [
        "Un nouveau calcul remplace les suggestions en attente issues d'un calcul ; les suggestions acceptées et les manuelles restent.",
        "La quantité suggérée remonte le disponible jusqu'au maximum du produit, ou jusqu'au seuil s'il n'a pas de maximum. Minimum et maximum se règlent dans « Alertes Stock », bouton « Configurer seuils ».",
        "Le fournisseur proposé est le fournisseur principal du produit (fiche fournisseur, onglet « Produits fournis »). Avec « Ajouter aux commandes en préparation du fournisseur », les lignes complètent son dernier brouillon.",
        "Un besoin se signale aussi depuis l'écran « Produits » (bouton panier « Suggérer l'achat ») : il arrive ici comme suggestion « Manuelle ».",
      ],
      motsCles: ["réapprovisionnement", "réassort", "stock minimum", "rupture de stock", "point de commande", "besoin d'achat"],
    },
    en: {
      resume: "Products to order, grouped by supplier: stock that fell below the alert threshold, customer back orders not yet ordered and needs flagged from a product. Accepted suggestions become purchase orders.",
      etapes: [
        "Tick the sources you want (stock below minimum, customer back orders), pick a supplier if needed, then click “Compute suggestions”.",
        "In the “To order” tab, adjust the “To order” quantity, choose a supplier for products that have none and add a remark if needed.",
        "Tick “Accept” on the lines to keep, or click “Accept all”; remove the ones to drop with the bin icon.",
        "Click “Generate the orders”: one draft purchase order is created per supplier, to confirm and send from the “Commandes” screen.",
        "The “Ordered (30 days)” tab shows the suggestions already ordered, with their order number.",
      ],
      astuces: [
        "A new computation replaces the pending suggestions that came from a computation; accepted and manual suggestions stay.",
        "The suggested quantity brings the available stock back up to the product's maximum, or to its threshold if it has no maximum. Minimum and maximum are set in “Stock Alerts”, “Configurer seuils” button.",
        "The proposed supplier is the product's main supplier (supplier record, “Supplied products” tab). With “Add to the supplier's orders in preparation”, the lines go into its latest draft.",
        "A need can also be flagged from the “Products” screen (cart button “Suggest a purchase”): it arrives here as a “Manual” suggestion.",
      ],
      motsCles: ["replenishment", "reorder", "restock", "minimum stock", "stockout", "reorder point"],
    },
  },
  "/commandes-fournisseur": {
    fr: {
      resume: "Les bons de commande envoyés aux fournisseurs, du brouillon à la réception complète. Créez une commande, validez-la et envoyez-la, suivez son statut, puis annulez-la ou clôturez son reste au besoin.",
      etapes: [
        "Cliquez sur « Nouvelle commande », puis choisissez le « Fournisseur », la « Date commande » et, si vous la connaissez, la « Date livraison prévue ».",
        "Ajoutez les produits avec « + Ajouter une ligne » : quantité (dans l'unité d'achat du fournisseur) et prix unitaire, puis cliquez sur « Créer (Brouillon) ».",
        "Ouvrez la commande avec l'œil « Voir détails », vérifiez-la, puis cliquez sur « Valider et envoyer » : elle passe au statut « Envoyée ».",
        "Transmettez le bon au fournisseur avec l'enveloppe « Envoyer par courriel », ou ouvrez-le avec « Ouvrir le PDF » pour l'imprimer.",
        "Suivez vos commandes avec la recherche (numéro ou fournisseur) et le filtre de statut ; « Mes filtres » garde vos filtres habituels.",
      ],
      astuces: [
        "Seul un brouillon se supprime. Une commande envoyée sans rien de reçu s'annule ; une commande reçue en partie se ferme avec « Clôturer le reste », et le manquant est noté abandonné.",
        "Envoyer un brouillon par courriel le valide. Le courriel est proposé aux contacts du fournisseur cochés « Reçoit les bons de commande », sinon à son email.",
        "Pour un fournisseur dans une autre devise, le taux de change est proposé (référentiel des devises ou cours du jour) et reste modifiable ; s'il est introuvable, saisissez-le.",
        "Les livraisons s'enregistrent dans l'écran « Réceptions ». Une marchandise reçue à renvoyer part avec le bouton « Retour au fournisseur » de la commande.",
      ],
      motsCles: ["bon de commande", "commande fournisseur", "commande d'achat", "approvisionnement", "bc"],
    },
    en: {
      resume: "Purchase orders sent to suppliers, from draft to fully received. Create an order, confirm and send it, track its status, then cancel it or close its remainder if needed.",
      etapes: [
        "Click “Nouvelle commande”, then choose the “Fournisseur” (supplier), the “Date commande” (order date) and, if known, the “Date livraison prévue” (expected delivery).",
        "Add products with “+ Ajouter une ligne”: quantity (in the supplier's purchase unit) and unit price, then click “Créer (Brouillon)” to save a draft.",
        "Open the order with the eye icon (“Voir détails”), check it, then click “Valider et envoyer”: its status becomes “Envoyée” (sent).",
        "Send the order to the supplier with the envelope icon (“Send by email”), or open it with “Open PDF” to print it.",
        "Track your orders with the search box (number or supplier) and the status filter; “My filters” keeps your usual filters.",
      ],
      astuces: [
        "Only a draft can be deleted. A sent order with nothing received can be cancelled; a partly received order is closed with “Clôturer le reste”, and the shortfall is recorded as abandoned.",
        "Emailing a draft confirms it. The email is offered to the supplier's contacts ticked “Receives purchase orders”, otherwise to its email address.",
        "For a supplier in another currency, the exchange rate is proposed (currency reference data or daily rate) and can be changed; if none is found, enter it.",
        "Deliveries are recorded in the “Réceptions” screen. Received goods to send back leave with the order's “Return to supplier” button.",
      ],
      motsCles: ["purchase order", "po", "supplier order", "procurement", "buying"],
    },
  },
  "/receptions": {
    fr: {
      resume: "L'enregistrement des marchandises livrées par les fournisseurs : chaque livraison devient une réception numérotée, rangée dans un secteur, qui met à jour les quantités reçues et le statut des commandes.",
      etapes: [
        "Dans l'onglet « À recevoir », choisissez la commande dans « Commandes en attente », puis cliquez sur « Réceptionner ».",
        "Saisissez la « Date de réception » et, si vous les avez, le « Bon de livraison du fournisseur » et le « Connaissement ».",
        "Pour chaque ligne, ajustez la « Qté reçue » (pré-remplie avec le reste), le « Coût unitaire », le « Secteur », le « N° lot » et la « Péremption ».",
        "Cochez « Clôturer » sur une ligne dont le reste ne viendra pas ; ajoutez au besoin une autre commande du même fournisseur ou des « Coûts annexes » (transport, douane…).",
        "Cliquez sur « Enregistrer la réception » : elle reçoit son numéro et se retrouve dans l'onglet « Historique ».",
      ],
      astuces: [
        "Recevoir plus que le reste commandé demande une confirmation. Un produit suivi par lot exige son numéro de lot ; un produit suivi par numéro de série, un numéro par unité reçue.",
        "Le « Coût unitaire » est pré-rempli avec le prix commandé : saisissez le coût réellement facturé. S'il diffère, il met à jour le prix convenu au catalogue du fournisseur (commande dans sa devise).",
        "« Corriger » change la date, les références et les coûts, jamais les quantités : annulez la réception et refaites-la. Une réception facturée, ou dont la marchandise est déjà repartie au fournisseur, ne s'annule pas.",
        "Dans « Historique », cochez des réceptions d'un même fournisseur pas encore facturées, puis cliquez sur « Facturer les réceptions » : la facture fournisseur est proposée d'après ce qui a été reçu.",
      ],
      motsCles: ["arrivage", "bon de livraison", "connaissement", "livraison fournisseur", "frais d'approche", "coût de revient"],
    },
    en: {
      resume: "Recording the goods delivered by suppliers: each delivery becomes a numbered receipt, put away in a sector, which updates the quantities received and the status of the orders.",
      etapes: [
        "In the “To receive” tab, choose the order under “Commandes en attente” (pending orders), then click “Receive”.",
        "Enter the “Receipt date” and, if you have them, the “Supplier's delivery note” and the “Bill of lading”.",
        "For each line, adjust the “Qty received” (prefilled with what is left), the “Unit cost”, the “Sector”, the “Lot no.” and the “Expiry”.",
        "Tick “Close” on a line whose remainder will not come; add another order from the same supplier or “Extra costs” (freight, customs…) if needed.",
        "Click “Save the receipt”: it gets its number and appears in the “History” tab.",
      ],
      astuces: [
        "Receiving more than what is left on order asks for a confirmation. A lot-tracked product requires its lot number; a serial-tracked product, one serial number per unit received.",
        "The “Unit cost” is prefilled with the ordered price: enter the cost actually invoiced. If it differs, it updates the agreed price in the supplier's catalog (for an order in the supplier's currency).",
        "“Correct” changes the date, references and costs, never the quantities: cancel the receipt and enter it again. A receipt that is invoiced, or whose goods have already gone back to the supplier, cannot be cancelled.",
        "In “History”, tick receipts of the same supplier not invoiced yet, then click “Invoice the receipts”: the supplier invoice is proposed from what was received.",
      ],
      motsCles: ["receipts", "goods received", "receiving", "delivery note", "landed cost", "grn"],
    },
  },
  "/retours-fournisseurs": {
    fr: {
      resume: "La marchandise renvoyée aux fournisseurs (défectueuse, en trop, à remplacer) : préparez le retour, expédiez-le, puis clôturez-le quand le fournisseur a répondu (avoir, remplacement ou contenant rendu).",
      etapes: [
        "Cliquez sur « Nouveau retour », ou partez d'une commande reçue (« Retour au fournisseur » dans « Commandes ») ou d'un retour client traité (dans « Retours clients »).",
        "Choisissez le fournisseur, le « Type » (Remboursement, Remplacement ou Contenant consigné) et, si le fournisseur vous l'a donné, le « Numéro RMA ».",
        "Pour chaque produit, indiquez la quantité, le « Prix crédité », le secteur d'où il sort (« Sort de », ou « Hors stock ») et la raison.",
        "Cliquez sur « Enregistrer le brouillon », ou sur « Enregistrer et expédier » : à l'expédition, le retour reçoit son numéro et la marchandise sort de son secteur.",
        "Quand le fournisseur a répondu, cliquez sur « Clôturer le retour » : notez le crédit obtenu et la référence de son avoir, ou le secteur où entre le remplacement.",
      ],
      astuces: [
        "Seul un brouillon se modifie ou se supprime. Une fois expédié, le retour se transmet au fournisseur par son PDF ou par courriel.",
        "Les cartes du haut suivent les « Crédits attendus » (retours en remboursement expédiés, pas encore clos) et les « Crédits obtenus ».",
        "Depuis une commande, chaque produit repart au plus dans la quantité reçue, moins ce qui en est déjà reparti.",
        "Dès que des raisons de type « Retour fournisseur » existent dans « Référentiels » (Raisons), la raison devient obligatoire.",
      ],
      motsCles: ["retour marchandise", "rma", "avoir fournisseur", "note de crédit fournisseur", "renvoi fournisseur", "marchandise défectueuse"],
    },
    en: {
      resume: "Goods sent back to suppliers (defective, surplus, to replace): prepare the return, ship it, then close it once the supplier has responded (credit note, replacement or returned container).",
      etapes: [
        "Click “New return”, or start from a received order (“Return to supplier” in “Commandes”) or from a processed customer return (in “Customer returns”).",
        "Choose the supplier, the “Type” (Refund, Replacement or Returnable container) and, if the supplier gave you one, the “RMA number”.",
        "For each product, enter the quantity, the “Credited price”, the sector it leaves from (“Leaves from”, or “Not in stock”) and the reason.",
        "Click “Save draft”, or “Save and ship”: when shipped, the return gets its number and the goods leave their sector.",
        "Once the supplier has responded, click “Close the return”: record the credit obtained and its credit note reference, or the sector the replacement goes into.",
      ],
      astuces: [
        "Only a draft can be edited or deleted. Once shipped, the return is sent to the supplier as a PDF or by email.",
        "The cards at the top track “Credits expected” (refund returns shipped but not closed yet) and “Credits obtained”.",
        "From a purchase order, each product can go back at most in the quantity received, less what has already gone back.",
        "As soon as reasons of type “Supplier return” exist in “Reference data” (Reasons), the reason becomes required.",
      ],
      motsCles: ["return to vendor", "rtv", "rma", "supplier credit", "credit note", "defective goods"],
    },
  },
  "/factures-fournisseurs": {
    fr: {
      resume: "Les factures reçues des fournisseurs, rapprochées de la marchandise reçue, avec leurs taxes, leur échéance et leurs paiements. Suivez le reste à payer et les factures en retard.",
      etapes: [
        "Cliquez sur « Nouvelle facture », ou cochez des réceptions dans « Réceptions » › « Historique » puis cliquez sur « Facturer les réceptions ».",
        "Renseignez le « N° chez le fournisseur », la « Date » et la « Condition de paiement » : laissée vide, l'« Échéance » se calcule seule.",
        "Vérifiez les lignes (quantité, « Prix unitaire HT », « Taxe % ») et les totaux, puis cliquez sur « Enregistrer la facture ».",
        "Dans le détail, contrôlez la colonne « Écart » avec la marchandise reçue ; au besoin, cliquez sur « Aligner le coût des réceptions sur la facture ».",
        "Pour régler la facture, saisissez la date, le montant, le mode et la référence sous « Paiements », puis cliquez sur « Payer ».",
        "Filtrez la liste avec « À payer » pour voir tout ce qui reste dû (impayé, payé en partie ou en retard).",
      ],
      astuces: [
        "Une même facture du fournisseur (même numéro chez lui) ne s'enregistre pas deux fois.",
        "Une facture qui a reçu un paiement ne se modifie plus et ne s'annule pas : annulez d'abord ses paiements. Annulée, elle libère ses réceptions, qui redeviennent à facturer.",
        "Pour payer plusieurs factures d'un fournisseur en une fois, ou profiter d'un escompte, passez par « Déboursés » ou « Paiements dus ».",
        "Joignez la facture reçue (PDF, photo) avec le bouton « Pièces jointes » du détail.",
      ],
      motsCles: ["facture d'achat", "comptes à payer", "dettes fournisseurs", "échéance", "rapprochement", "facture reçue"],
    },
    en: {
      resume: "Invoices received from suppliers, matched with the goods received, with their taxes, due date and payments. Track the amount left to pay and overdue invoices.",
      etapes: [
        "Click “New invoice”, or tick receipts in “Réceptions” › “History”, then click “Invoice the receipts”.",
        "Fill in the “Supplier invoice no.”, the “Date” and the “Payment terms”: if left empty, the “Due date” is computed for you.",
        "Check the lines (quantity, “Unit price excl. tax”, “Tax %”) and the totals, then click “Save the invoice”.",
        "In the detail, check the “Gap” column against the goods received; if needed, click “Align the receipt costs on the invoice”.",
        "To settle the invoice, enter the date, amount, method and reference under “Payments”, then click “Pay”.",
        "Filter the list with “To pay” to see everything still due (unpaid, partly paid or overdue).",
      ],
      astuces: [
        "The same supplier invoice (same supplier invoice number) cannot be recorded twice.",
        "An invoice that has received a payment can no longer be edited or cancelled: cancel its payments first. Once cancelled, it releases its receipts, which are to invoice again.",
        "To pay several invoices of a supplier at once, or to take an early-payment discount, use “Disbursements” or “Payments due”.",
        "Attach the invoice received (PDF, photo) with the “Attachments” button in the detail.",
      ],
      motsCles: ["accounts payable", "purchase invoice", "vendor invoice", "bill", "due date", "three-way match"],
    },
  },
  "/debourses": {
    fr: {
      resume: "Les paiements aux fournisseurs : l'argent versé en une fois (chèque, virement, espèces…) et réparti sur une ou plusieurs de leurs factures, avec l'escompte accordé pour un paiement rapide.",
      etapes: [
        "Cliquez sur « Nouveau déboursé », puis choisissez le « Fournisseur », la « Date », le « Mode » et, au besoin, la « Référence » et le « Compte bancaire ».",
        "Dans « Factures à payer », cochez « Tout » pour régler le reste d'une facture (escompte offert compris), ou saisissez le montant « Payé » et l'« Escompte ».",
        "Vérifiez le « Montant versé » en bas de la fenêtre, puis cliquez sur « Enregistrer ».",
        "Ouvrez le déboursé pour l'avis de paiement (PDF) et les pièces jointes ; pour un chèque, cliquez sur « Imprimer le chèque ».",
        "Pour imprimer plusieurs chèques, choisissez le filtre « Chèques à imprimer », cochez-les, puis cliquez sur « Imprimer les chèques ».",
      ],
      astuces: [
        "Seules les factures du fournisseur dans la devise choisie sont proposées, l'échéance la plus ancienne d'abord. Un escompte seul ne fait pas un déboursé : il faut verser de l'argent.",
        "Le chèque prend le prochain numéro du chéquier de son compte bancaire, réglé dans « Banque » (Finance) ; une réimpression garde le même numéro.",
        "« Corriger » ne change que la référence et les notes. Sinon, annulez le déboursé (avec un motif) et refaites-le : ses paiements sont retirés des factures, qui redeviennent dues.",
        "Les déboursés par virement partent à la banque depuis l'écran « Virements » ; une fois leur lot transmis, ils ne s'annulent plus tant que le lot n'est pas marqué refusé.",
      ],
      motsCles: ["paiement fournisseur", "décaissement", "règlement fournisseur", "chèque", "escompte", "avis de paiement"],
    },
    en: {
      resume: "Supplier payments (disbursements): money paid in one go (cheque, bank transfer, cash…) and spread over one or more of their invoices, with the discount granted for early payment.",
      etapes: [
        "Click “New disbursement”, then choose the “Supplier”, the “Date”, the “Method” and, if needed, the “Reference” and the “Bank account”.",
        "Under “Invoices to pay”, tick “All” to settle what is left on an invoice (offered discount included), or enter the “Paid” amount and the “Discount”.",
        "Check the “Amount paid” at the bottom of the window, then click “Save”.",
        "Open the disbursement for the payment advice (PDF) and attachments; for a cheque, click “Print the cheque”.",
        "To print several cheques, choose the “Cheques to print” filter, tick them, then click “Print cheques”.",
      ],
      astuces: [
        "Only the supplier's invoices in the chosen currency are offered, oldest due date first. A discount alone is not a disbursement: some money must be paid.",
        "The cheque takes the next number of its bank account's chequebook, set in “Banking” (Finance); a reprint keeps the same number.",
        "“Correct” only changes the reference and notes. For anything else, cancel the disbursement (with a reason) and enter it again: its payments are removed from the invoices, which become due again.",
        "Disbursements by bank transfer go to the bank from the “Transfers” screen; once their batch is sent, they cannot be cancelled unless the batch is marked refused.",
      ],
      motsCles: ["supplier payment", "vendor payment", "payout", "cheque", "check", "payment advice"],
    },
  },
  "/paiements-dus": {
    fr: {
      resume: "Le plan de décaissement : chaque facture fournisseur encore due, son échéance, l'escompte encore accordé à la date où vous comptez payer et le net à payer, par fournisseur et par devise.",
      etapes: [
        "Réglez « Échéance au » (aujourd'hui par défaut) et, au besoin, choisissez un « Fournisseur » ou cochez « Seulement ce qui est échu ».",
        "Indiquez quand vous comptez payer avec « Payer le » ou « Dans N jours » : la colonne « Escompte » montre ce que vous gagnez à payer à cette date.",
        "Cochez les factures à régler, puis cliquez sur « Payer la sélection ».",
        "Choisissez la date, le mode, la référence et le compte bancaire, gardez « Appliquer les escomptes encore offerts », puis cliquez sur « Enregistrer les déboursés ».",
        "Cliquez sur « Exporter (CSV) » pour reprendre le rapport dans un tableur.",
      ],
      astuces: [
        "La « Date du rapport » arrête les soldes (factures et paiements datés après elle sont ignorés) ; « Payer le » décide seulement quels escomptes sont encore accordés.",
        "Un déboursé est créé par fournisseur et par devise, pour le solde de chaque facture choisie moins son escompte encore offert. C'est tout ou rien : si une facture n'est plus à payer, rien n'est enregistré.",
        "La colonne « Jours » compte le retard à la date du rapport ; un nombre négatif indique une échéance encore à venir.",
        "Les totaux sont donnés par devise, sans jamais les mélanger ; avec plusieurs devises, un total de contrôle est calculé dans la devise de l'entreprise.",
      ],
      motsCles: ["échéancier", "factures échues", "retard de paiement", "trésorerie", "comptes à payer", "escompte"],
    },
    en: {
      resume: "The disbursement plan: each supplier invoice still due, its due date, the discount still granted on the date you plan to pay and the net amount to pay, by supplier and currency.",
      etapes: [
        "Set “Due by” (today by default) and, if needed, choose a “Supplier” or tick “Overdue only”.",
        "Say when you plan to pay with “Pay on” or “In N days”: the “Discount” column shows what you save by paying on that date.",
        "Tick the invoices to settle, then click “Pay the selection”.",
        "Choose the date, method, reference and bank account, keep “Apply the discounts still offered” ticked, then click “Record the disbursements”.",
        "Click “Export (CSV)” to work on the report in a spreadsheet.",
      ],
      astuces: [
        "The “Report date” sets the balances (invoices and payments dated after it are ignored); “Pay on” only decides which discounts are still granted.",
        "One disbursement is created per supplier and currency, for the balance of each selected invoice less its discount still offered. It is all or nothing: if one invoice is no longer due, nothing is recorded.",
        "The “Days” column counts the days overdue at the report date; a negative number means the due date is still ahead.",
        "Totals are given per currency, never mixed; with several currencies, a control total is computed in the company currency.",
      ],
      motsCles: ["payables aging", "payment schedule", "overdue invoices", "cash planning", "bills to pay", "early payment discount"],
    },
  },
  "/virements": {
    fr: {
      resume: "Les déboursés payés par virement, transmis à la banque par lots : un fichier SEPA ou CSV à déposer sur son portail. Suivez les virements à transmettre, transmis ou refusés.",
      etapes: [
        "Dans l'onglet « À transmettre », vérifiez la colonne « À vérifier » : compte du fournisseur manquant, IBAN invalide, coordonnées modifiées depuis peu…",
        "Cochez les déboursés à envoyer (un même compte bancaire, une même devise), puis cliquez sur « Générer le fichier ».",
        "Choisissez le « Format » (SEPA ou CSV), la « Date d'exécution » et le « Numéro de lot », puis cliquez sur « Générer le fichier » : il se télécharge.",
        "Déposez le fichier téléchargé sur le portail de votre banque ; ses déboursés figurent désormais dans l'onglet « Transmis ».",
        "Si la banque refuse le lot, cliquez sur « Refusé par la banque » dans l'onglet « Lots » : ses déboursés redeviennent à transmettre.",
      ],
      astuces: [
        "Chaque virement crédite le compte principal du fournisseur (sa fiche, onglet « Coordonnées bancaires »).",
        "Des coordonnées bancaires modifiées depuis moins de 30 jours sont signalées : vérifiez-les par téléphone auprès du fournisseur avant d'envoyer, c'est le premier signe d'une fraude au virement.",
        "Seuls les déboursés au mode « Virement » apparaissent ici : saisissez-les dans « Déboursés » ou depuis « Paiements dus ».",
        "Le fichier d'un lot se retélécharge à l'identique depuis l'onglet « Lots » (bouton « Fichier »). S'il manque quelque chose à la génération, rien n'est créé et la liste des problèmes s'affiche.",
      ],
      motsCles: ["virement bancaire", "sepa", "lot de virements", "fichier bancaire", "remise de virements", "fraude au virement"],
    },
    en: {
      resume: "Disbursements paid by bank transfer, sent to the bank in batches: a SEPA or CSV file to upload on its portal. Track the transfers to send, sent or refused.",
      etapes: [
        "In the “To send” tab, check the “To check” column: missing supplier account, invalid IBAN, recently changed bank details…",
        "Tick the disbursements to send (same bank account, same currency), then click “Generate the file”.",
        "Choose the “Format” (SEPA or CSV), the “Execution date” and the “Batch number”, then click “Generate the file”: it downloads.",
        "Upload the downloaded file on your bank's portal; its disbursements now appear in the “Sent” tab.",
        "If the bank refuses the batch, click “Refused by the bank” in the “Batches” tab: its disbursements are to send again.",
      ],
      astuces: [
        "Each transfer credits the supplier's main account (its record, “Bank details” tab).",
        "Bank details changed less than 30 days ago are flagged: check them by phone with the supplier before sending, as this is the first sign of a transfer fraud.",
        "Only disbursements with the “Bank transfer” method appear here: enter them in “Disbursements” or from “Payments due”.",
        "A batch file can be downloaded again, unchanged, from the “Batches” tab (“File” button). If anything is missing when generating, nothing is created and the list of issues is shown.",
      ],
      motsCles: ["bank transfer", "wire transfer", "sepa", "payment file", "transfer batch", "eft"],
    },
  },
};
