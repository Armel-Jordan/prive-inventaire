import type { AidesEcrans } from '../types';

/** Aide des écrans de la catégorie Ventes : clients, devis, commandes, factures… (phase 6.6). */
export const AIDES_VENTES: AidesEcrans = {
  "/clients": {
    fr: {
      resume: "Le carnet de vos clients : coordonnées, encours, conditions de vente et de livraison. Créez, cherchez et modifiez vos clients, puis ouvrez la fiche complète de chacun.",
      etapes: [
        "Cliquez sur « Ajouter », remplissez au moins la raison sociale, l'adresse de facturation, la ville et le code postal (et le code s'il n'est pas généré), puis « Créer ».",
        "Retrouvez un client par son nom, son code ou sa ville ; filtrez par catégorie, cochez « Mes favoris » ou rappelez une recherche avec « Mes filtres ».",
        "Cliquez sur le code d'un client pour ouvrir sa fiche : synthèse des ventes, historique d'achats, contacts, adresses et remarques.",
        "Pour changer ses coordonnées, son encours maximum, sa remise, sa devise ou sa condition de paiement, cliquez sur le crayon « Modifier » de sa ligne.",
        "Joignez des documents à la fiche avec le trombone « Pièces jointes ».",
      ],
      astuces: [
        "Un client qui a des devis, des commandes ou des factures ne peut pas être supprimé : décochez « Client actif » dans sa modification, il ne sera plus proposé.",
        "L'encours passe en rouge au-delà de 80 % de l'encours maximum ; une commande qui le ferait dépasser ne peut pas être acceptée. Laissé vide, l'encours maximum est illimité.",
        "La devise du client est reprise par ses devis, commandes et factures, où ses frais de livraison sont aussi proposés. Sa condition de paiement fixe l'échéance de ses factures.",
        "Si un autre compte modifie ce client, un bandeau dit qui et depuis quand, et l'enregistrement est bloqué : « Réessayer » plus tard, ou « Prendre la main » si votre rôle le permet.",
      ],
      motsCles: ["clientèle", "acheteur", "coordonnées", "encours", "client de passage", "favoris"],
    },
    en: {
      resume: "Your customer list: contact details, outstanding balance, sales and delivery terms. Create, find and edit customers, then open each one's full record.",
      etapes: [
        "Click “Ajouter”, fill in at least “Raison sociale”, “Adresse facturation”, “Ville” and “Code postal” (and “Code” if it is not generated), then click “Créer”.",
        "Find a customer by name, code or city; filter by category, tick “My favorites” or recall a saved search with “My filters”.",
        "Click a customer's code to open their record: sales summary, purchase history, contacts, addresses and notes.",
        "To change their details, credit limit, discount, currency or payment terms, click the pencil “Modifier” button on their row.",
        "Attach documents to the record with the paper clip “Attachments” button.",
      ],
      astuces: [
        "A customer with quotes, orders or invoices cannot be deleted: untick “Client actif” when editing it, and it will no longer be offered.",
        "The outstanding balance turns red above 80% of the credit limit; an order that would exceed the limit cannot be accepted. Left empty, the credit limit is unlimited.",
        "The customer's currency carries over to their quotes, orders and invoices, where their delivery fee is also suggested. Their payment terms set the due date of their invoices.",
        "If another account is editing this customer, a banner shows who and since when, and saving is blocked: “Try again” later, or “Take over” if your role allows it.",
      ],
      motsCles: ["customers", "buyer", "contact details", "credit limit", "walk-in customer", "favorites"],
    },
  },
  "/clients/:id": {
    fr: {
      resume: "La fiche complète d'un client : ses chiffres de vente, son encours, son historique d'achats, ses contacts, ses adresses et les remarques de l'équipe.",
      etapes: [
        "Ouvrez la fiche depuis la liste « Clients » en cliquant sur le code du client.",
        "Onglet « Synthèse » : ventes de l'année, panier moyen, encours et retards, commandes et devis en cours, produits les plus achetés.",
        "Onglet « Historique d'achats » : filtrez par période (« Du », « Au ») ou par produit ; le total de la sélection s'affiche en bas.",
        "Onglets « Contacts » et « Adresses » : cliquez sur « Ajouter un contact » ou « Ajouter une adresse », remplissez, puis « Enregistrer ».",
        "Onglet « Remarques » : notez un appel, une visite ou une consigne, puis « Ajouter » ; épinglez les remarques importantes.",
        "Dans l'en-tête, classez le client avec la liste « Catégorie » et marquez-le d'une étoile pour le retrouver dans « Mes favoris ».",
      ],
      astuces: [
        "Les coordonnées, l'encours maximum et la remise globale se modifient depuis la liste « Clients », avec le crayon « Modifier ».",
        "Les chiffres comptent les factures émises, partiellement payées ou payées, hors taxes ; les brouillons et les factures annulées n'y sont pas.",
        "Un seul contact principal et une seule adresse principale : en marquer un nouveau retire la marque du précédent.",
        "Vos favoris ne sont qu'à vous. Une remarque se modifie, s'épingle ou se supprime par son auteur, un administrateur ou un responsable.",
      ],
      motsCles: ["historique d'achats", "chiffre d'affaires client", "panier moyen", "contact", "adresse de livraison", "remarque"],
    },
    en: {
      resume: "A customer's full record: sales figures, outstanding balance, purchase history, contacts, addresses and the team's notes.",
      etapes: [
        "Open the record from the “Clients” list by clicking the customer's code.",
        "“Summary” tab: sales this year, average invoice, outstanding and overdue amounts, open orders and quotes, most purchased products.",
        "“Purchase history” tab: filter by period (“From”, “To”) or by product; the selection total shows at the bottom.",
        "“Contacts” and “Addresses” tabs: click “Add a contact” or “Add an address”, fill in the form, then “Save”.",
        "“Notes” tab: record a call, a visit or an instruction, then click “Add”; pin the important ones.",
        "In the header, classify the customer with the “Category” list and star them to find them under “My favorites”.",
      ],
      astuces: [
        "Contact details, credit limit and global discount are edited from the “Clients” list, with the pencil “Modifier” button.",
        "Figures count issued, partially paid and paid invoices, excluding tax; drafts and cancelled invoices are left out.",
        "Only one main contact and one main address: marking a new one removes the mark from the previous one.",
        "Your favorites are your own. A note can be edited, pinned or deleted by its author, an administrator or a manager.",
      ],
      motsCles: ["purchase history", "customer sales", "average invoice", "contact", "delivery address", "notes"],
    },
  },
  "/espace-client": {
    fr: {
      resume: "Les réglages et les accès de l'espace client : le site à part où vos clients commandent à leurs prix, suivent leurs commandes et retrouvent leurs factures.",
      etapes: [
        "Dans « Réglages », cochez « Espace client ouvert », écrivez au besoin un message d'accueil, cochez les catégories des « Produits proposés », puis « Enregistrer ».",
        "Copiez l'« Adresse de votre espace client » et donnez-la à vos clients ; « Ouvrir » montre l'espace tel qu'ils le voient.",
        "Cliquez sur « Nouvel accès », choisissez le client, saisissez le nom et le courriel du contact, puis « Créer et envoyer le lien ».",
        "Si le courriel ne part pas, copiez le lien affiché et transmettez-le vous-même au contact.",
        "Suivez l'état de chaque accès : « Renvoyer un lien » s'il a expiré, « Modifier » puis décochez « Accès actif » pour suspendre le contact.",
      ],
      astuces: [
        "Sans numérotation automatique des commandes client, l'espace client refuse les commandes : cliquez sur « Régler la numérotation ».",
        "Les commandes passées en ligne arrivent « En attente » dans « Commandes » (menu Ventes), avec une notification : acceptez-les ou refusez-les comme les autres.",
        "Aucune catégorie cochée : tous les produits actifs sont proposés. Fermer l'espace client bloque les connexions mais garde les accès.",
        "Le lien d'accès ne sert qu'une fois, et en renvoyer un annule le précédent. Supprimer un accès garde les commandes du contact dans l'historique du client.",
      ],
      motsCles: ["portail client", "commande en ligne", "accès client", "invitation", "extranet", "site client"],
    },
    en: {
      resume: "Settings and accesses for the customer area: the separate website where your customers order at their own prices, follow their orders and find their invoices.",
      etapes: [
        "In “Settings”, tick “Customer area open”, write a welcome message if you wish, tick the categories under “Products offered”, then click “Save”.",
        "Copy “Your customer area address” and give it to your customers; “Open” shows the area as they see it.",
        "Click “New access”, choose the customer, enter the contact's name and email, then click “Create and send the link”.",
        "If the email cannot be sent, copy the link shown and pass it on to the contact yourself.",
        "Follow each access's status: “Send a new link” if it has expired, or “Edit” and untick “Access active” to suspend the contact.",
      ],
      astuces: [
        "Without automatic numbering of customer orders, the customer area refuses orders: click “Set up numbering”.",
        "Orders placed online arrive as “En attente” (pending) in “Commandes” (“Ventes” menu), with a notification: accept or decline them like any other order.",
        "With no category ticked, all active products are offered. Closing the customer area blocks sign-ins but keeps the accesses.",
        "An access link works only once, and sending a new one cancels the previous one. Deleting an access keeps the contact's orders in the customer's history.",
      ],
      motsCles: ["customer portal", "online ordering", "customer access", "invitation", "extranet", "self-service"],
    },
  },
  "/devis": {
    fr: {
      resume: "Les propositions commerciales faites à vos clients : préparez un devis, envoyez-le, notez la réponse, puis transformez-le en commande ou en facture sans ressaisie.",
      etapes: [
        "Cliquez sur « Nouveau devis », choisissez le client (ou « Client de passage »), puis la « Date devis » et la « Date validité ».",
        "Ajoutez les produits avec « + Ajouter ligne » : le prix du client est proposé et reste modifiable. Cliquez sur « Créer ».",
        "Envoyez le devis avec « Envoyer par courriel », ou transmettez le « Lien de réponse » ; « Envoyer » le marque seulement comme envoyé.",
        "Sans réponse du client, relancez-le avec la cloche « Relancer le client ».",
        "Notez sa décision avec « Accepter » ou « Refuser » ; le client peut aussi répondre en ligne depuis le lien reçu.",
        "Devis accepté : « Convertir en commande », ou « Facturer » pour créer directement une facture brouillon.",
      ],
      astuces: [
        "Seul un brouillon se modifie ou se supprime. « Copier » reprend n'importe quel devis en nouveau brouillon daté du jour.",
        "Un devis envoyé dont la date de validité est passée devient « Expiré » et ne peut plus être accepté.",
        "Un devis ne se convertit qu'une fois, en commande ou en facture ; le numéro du document créé s'affiche sous son statut.",
        "Un devis est verrouillé pendant sa modification : un autre compte voit qui le modifie et ne peut pas l'enregistrer en même temps.",
      ],
      motsCles: ["offre", "proposition commerciale", "estimation", "cotation", "soumission"],
    },
    en: {
      resume: "Sales proposals for your customers: prepare a quote, send it, record the answer, then turn it into an order or an invoice without re-entering it.",
      etapes: [
        "Click “Nouveau devis”, choose the customer (or “Walk-in customer”), then the “Date devis” and “Date validité”.",
        "Add products with “+ Ajouter ligne”: the customer's price is suggested and can be changed. Click “Créer”.",
        "Send the quote with “Send by email”, or pass on the “Answer link”; “Envoyer” only marks it as sent.",
        "No answer from the customer? Follow up with the bell button “Follow up with the customer”.",
        "Record the decision with “Accepter” or “Refuser”; the customer can also answer online from the link they received.",
        "Accepted quote: “Convertir en commande”, or “Invoice” to create a draft invoice directly.",
      ],
      astuces: [
        "Only a draft can be edited or deleted. “Copy” turns any quote into a new draft dated today.",
        "A sent quote whose validity date has passed becomes “Expiré” and can no longer be accepted.",
        "A quote is converted only once, into an order or an invoice; the number of the document created shows under its status.",
        "A quote is locked while someone edits it: other accounts see who is editing it and cannot save it at the same time.",
      ],
      motsCles: ["quote", "quotation", "estimate", "proposal", "bid", "offer"],
    },
  },
  "/commandes-client": {
    fr: {
      resume: "Les commandes de vos clients, saisies ici, issues d'un devis ou passées dans l'espace client : validation, acceptation qui réserve la marchandise, puis facturation.",
      etapes: [
        "Cliquez sur « Nouvelle commande », choisissez le client (ou « Client de passage »), la date et, au besoin, la date de livraison souhaitée et une remise globale.",
        "Ajoutez les produits avec « + Ajouter ligne » (prix du client proposé, modifiable), puis « Créer » : la commande est en « Brouillon ».",
        "Cliquez sur « Soumettre » : la commande passe « En attente » de décision. Les commandes de l'espace client y arrivent directement.",
        "Cliquez sur « Accepter », ou sur « Refuser » en indiquant le motif du refus.",
        "Commande acceptée : « Créer facture » prépare une facture brouillon, à émettre dans « Factures ».",
      ],
      astuces: [
        "Seul un brouillon se modifie ou se supprime. Pendant sa modification, la commande est verrouillée : un autre compte voit qui la modifie et ne peut pas l'enregistrer.",
        "L'acceptation est refusée si la commande fait dépasser l'encours maximum du client. Si le stock manque, l'écran propose de noter les quantités en reliquat.",
        "« Nouvel acompte » (portefeuille) enregistre un acompte réservé à la commande : il s'applique de lui-même à l'émission de sa facture.",
        "« Noter des reliquats » (sablier), sur une commande acceptée ou facturée, inscrit ce que le client attend dans « Reliquats clients ».",
      ],
      motsCles: ["commande de vente", "bon de commande", "commande client", "valider une commande", "réservation de stock"],
    },
    en: {
      resume: "Your customers' orders, entered here, converted from a quote or placed in the customer area: approval, acceptance that reserves the goods, then invoicing.",
      etapes: [
        "Click “Nouvelle commande”, choose the customer (or “Walk-in customer”), the date and, if needed, the requested delivery date and a global discount.",
        "Add products with “+ Ajouter ligne” (customer's price suggested, can be changed), then “Créer”: the order is a “Brouillon” (draft).",
        "Click “Soumettre”: the order becomes “En attente” (pending a decision). Orders from the customer area arrive there directly.",
        "Click “Accepter”, or “Refuser” and give the reason for the refusal.",
        "Accepted order: “Créer facture” prepares a draft invoice, to be issued in “Factures”.",
      ],
      astuces: [
        "Only a draft can be edited or deleted. While being edited, the order is locked: other accounts see who is editing it and cannot save it.",
        "Acceptance is refused if the order would take the customer over their credit limit. If stock is short, the screen offers to record the quantities as back orders.",
        "“New deposit” (wallet) records a deposit reserved for the order: it is applied automatically when its invoice is issued.",
        "“Record back orders” (hourglass), on an accepted or invoiced order, lists what the customer is waiting for in “Customer back orders”.",
      ],
      motsCles: ["sales order", "customer order", "order entry", "order approval", "stock reservation"],
    },
  },
  "/reliquats-clients": {
    fr: {
      resume: "Les produits qu'un client attend faute de stock (back orders) : commandez-les au fournisseur, suivez leur réception, puis facturez ceux qui sont arrivés.",
      etapes: [
        "Notez un reliquat avec « Nouveau reliquat » (client, produit, quantité attendue, prix, date souhaitée), ou depuis une commande acceptée avec « Noter des reliquats ».",
        "Dans l'onglet « Inscrits », cochez les reliquats, puis « Commander au fournisseur » : une commande fournisseur brouillon par fournisseur.",
        "Vérifiez et validez ces commandes dans « Commandes » du menu Achats ; leurs réceptions font passer les reliquats « Reçus en partie », puis « Reçus ».",
        "Dans l'onglet « Reçus », cochez les reliquats, puis « Facturer » avec la date de facture : une facture brouillon par client, à émettre dans « Factures ».",
        "Retrouvez un reliquat par client, produit, commande ou facture ; ses documents s'ouvrent depuis la colonne « Documents ».",
      ],
      astuces: [
        "Seul un reliquat « Inscrit » s'annule (le client ne l'attend plus) ou se supprime.",
        "Sans fournisseur choisi, chaque produit est commandé à son fournisseur principal, au dernier prix payé.",
        "Une réception se répartit sur les reliquats du plus ancien au plus récent.",
        "Si la commande fournisseur est annulée ou supprimée, ses reliquats qui n'ont rien reçu redeviennent « Inscrits », prêts à repartir ailleurs.",
      ],
      motsCles: ["back order", "b/o", "rupture de stock", "produit manquant", "attente de stock", "livraison différée"],
    },
    en: {
      resume: "Products a customer is waiting for because they are out of stock (back orders): order them from the supplier, follow their receipt, then invoice those that have arrived.",
      etapes: [
        "Record a back order with “New back order” (customer, product, expected quantity, price, required date), or from an accepted order with “Record back orders”.",
        "In the “Open” tab, tick the back orders, then “Order from supplier”: one draft purchase order per supplier.",
        "Check and confirm these orders in “Commandes” (“Achats” menu); their receipts move the back orders to “Partly received”, then “Received”.",
        "In the “Received” tab, tick the back orders, then “Invoice” with the invoice date: one draft invoice per customer, to be issued in “Factures”.",
        "Find a back order by customer, product, order or invoice; its documents open from the “Documents” column.",
      ],
      astuces: [
        "Only an “Open” back order can be cancelled (the customer no longer wants it) or deleted.",
        "With no supplier chosen, each product is ordered from its main supplier, at the last price paid.",
        "A receipt is spread over the back orders from the oldest to the most recent.",
        "If the purchase order is cancelled or deleted, its back orders that received nothing go back to “Open”, ready to be ordered elsewhere.",
      ],
      motsCles: ["backorder", "b/o", "out of stock", "missing product", "awaiting stock", "deferred delivery"],
    },
  },
  "/disponibilites": {
    fr: {
      resume: "Pour chaque produit : le stock compté, ce que réservent les commandes client acceptées, le disponible qui en reste, ce qui est en commande chez les fournisseurs et ce que les clients attendent en reliquat.",
      etapes: [
        "Choisissez quoi « Afficher » : les produits engagés (par défaut), les produits en manque ou tous les produits actifs.",
        "Cherchez un produit par son numéro ou sa description.",
        "Lisez la colonne « Disponible » : stock compté moins réservé ; en rouge, avec « manque », la quantité qui fait défaut.",
        "Cliquez sur le nombre de la colonne « En reliquat » pour ouvrir les reliquats clients de ce produit.",
      ],
      astuces: [
        "« Stock compté » additionne les comptages du produit ; « Réservé » compte les commandes acceptées pas encore facturées, moins ce qui est noté en reliquat.",
        "Un produit en manque affiche ses équivalents et leur disponible ; un ensemble affiche le nombre d'ensembles que permettent ses composants.",
        "À l'acceptation d'une commande, l'écran « Commandes » propose de noter en reliquat les quantités qui manquent.",
        "Pour réapprovisionner, « Suggestions d'achat » (menu Achats) propose les produits à commander, reliquats compris.",
      ],
      motsCles: ["stock disponible", "réservation", "rupture", "manque", "stock réservé", "en commande"],
    },
    en: {
      resume: "For each product: the counted stock, what accepted customer orders reserve, the available quantity left, what is on order from suppliers and what customers are waiting for on back order.",
      etapes: [
        "Choose what to “Show”: committed products (default), products in shortage or all active products.",
        "Search for a product by its number or description.",
        "Read the “Available” column: counted stock minus reserved; in red, with “missing”, the quantity that is short.",
        "Click the number in the “Back ordered” column to open that product's customer back orders.",
      ],
      astuces: [
        "“Counted stock” adds up the product's counts; “Reserved” counts accepted orders not yet invoiced, minus what is recorded as back orders.",
        "A product in shortage shows its equivalents and their available quantity; a kit shows how many kits its components allow.",
        "When an order is accepted, the “Commandes” screen offers to record the missing quantities as back orders.",
        "To restock, “Purchase suggestions” (“Achats” menu) proposes the products to order, back orders included.",
      ],
      motsCles: ["available stock", "stock reservation", "shortage", "reserved stock", "on order", "out of stock"],
    },
  },
  "/factures": {
    fr: {
      resume: "Les factures de vente : créez-les depuis une commande ou à la main, émettez-les, enregistrez les paiements, puis créditez, reprenez la marchandise ou préparez la livraison.",
      etapes: [
        "Pour une commande acceptée, cliquez sur « Créer facture » dans « Commandes » ; sinon, cliquez ici sur « Nouvelle facture » (client, date, lignes).",
        "Vérifiez la facture brouillon avec « Voir détails », puis cliquez sur « Émettre » : elle entre dans l'encours du client.",
        "Envoyez-la au client avec « Envoyer par courriel » (PDF joint), ou ouvrez-la avec « Ouvrir le PDF ».",
        "À la réception d'un paiement, cliquez sur « Enregistrer paiement » : montant, date, mode et référence. Un acompte disponible du client s'applique d'un clic.",
        "Suivez la colonne « Reste à payer » et filtrez la liste par statut.",
        "Au besoin : « Créditer » (note de crédit), « Retour de marchandise » ou « Créer bon de livraison ».",
      ],
      astuces: [
        "Seul un brouillon s'émet ou se supprime. Une facture émise se corrige par une note de crédit (« Créditer »).",
        "Sans date d'échéance saisie, elle se calcule selon la condition de paiement : celle choisie, sinon celle du client, sinon celle par défaut.",
        "Avec une condition de paiement à escompte, la case « Escompte » apparaît au premier paiement fait dans le délai ; ce paiement doit alors régler tout le reste.",
        "À l'émission, les acomptes réservés à la commande de la facture s'appliquent d'eux-mêmes.",
      ],
      motsCles: ["facturation", "facture client", "paiement", "reste à payer", "échéance", "encaisser"],
    },
    en: {
      resume: "Sales invoices: create them from an order or by hand, issue them, record payments, then credit them, take goods back or prepare the delivery.",
      etapes: [
        "For an accepted order, click “Créer facture” in “Commandes”; otherwise click “Nouvelle facture” here (customer, date, lines).",
        "Check the draft invoice with “Voir détails”, then click “Émettre”: it now counts in the customer's outstanding balance.",
        "Send it to the customer with “Send by email” (PDF attached), or open it with “Open PDF”.",
        "When a payment comes in, click “Enregistrer paiement”: amount, date, method and reference. An available customer deposit applies in one click.",
        "Follow the “Reste à payer” (balance due) column and filter the list by status.",
        "If needed: “Credit” (credit note), “Return goods” or “Créer bon de livraison” (delivery note).",
      ],
      astuces: [
        "Only a draft can be issued or deleted. An issued invoice is corrected with a credit note (“Credit”).",
        "With no due date entered, it is computed from the payment terms: those chosen, otherwise the customer's, otherwise the default ones.",
        "With early-payment discount terms, an “Escompte” box appears on the first payment made in time; that payment must then settle the whole balance.",
        "When the invoice is issued, deposits reserved for its order are applied automatically.",
      ],
      motsCles: ["billing", "invoicing", "sales invoice", "payment", "balance due", "due date"],
    },
  },
  "/abonnements-clients": {
    fr: {
      resume: "Les produits ou services facturés à un client chaque mois, trimestre, semestre ou année : les échéances se calculent d'avance et deviennent de vraies factures quand vous les facturez.",
      etapes: [
        "Cliquez sur « Nouvel abonnement » : client, produit, « Fréquence », « Jour de facturation (1 à 28) », dates de début et de fin.",
        "Choisissez « Quantité fixe » ou « À l'usage (relevés) », et cochez « Prix fixe » pour figer le prix ; sinon, le tarif du client s'applique à chaque échéance.",
        "Vérifiez l'aperçu (première facture, période couverte, nombre d'échéances), puis cliquez sur « Enregistrer ».",
        "Cliquez sur « Facturer les échéances dues », choisissez la date limite, cochez au besoin « Émettre les factures », puis « Créer les factures ».",
        "Dépliez une ligne avec sa flèche pour voir ses échéances et leurs factures, ou saisir les relevés d'un abonnement à l'usage.",
      ],
      astuces: [
        "Une facture par client et par date d'échéance, datée de l'échéance ; une échéance déjà facturée n'est jamais refacturée.",
        "« Relier » rattache une échéance à une facture faite à la main : elle ne sera plus facturée.",
        "Dans « Texte sur la facture », {mois}, {annee}, {debut_periode} et {fin_periode} sont remplacés par la période facturée.",
        "« Importer des relevés » lit un fichier CSV (client, produit, date, quantité, note) ; à la moindre erreur, rien n'est importé.",
      ],
      motsCles: ["facturation récurrente", "échéance", "mensualité", "relevé de consommation", "facturation à l'usage", "contrat de service"],
    },
    en: {
      resume: "Products or services invoiced to a customer every month, quarter, half-year or year: billing dates are computed in advance and become real invoices when you invoice them.",
      etapes: [
        "Click “New subscription”: customer, product, “Frequency”, “Billing day (1 to 28)”, start and end dates.",
        "Choose “Fixed quantity” or “Usage (readings)”, and tick “Fixed price” to lock the price; otherwise the customer's price applies at each billing date.",
        "Check the preview (first invoice, period covered, number of billing dates), then click “Save”.",
        "Click “Invoice due dates”, choose the cut-off date, tick “Issue the invoices” if needed, then “Create the invoices”.",
        "Expand a row with its arrow to see its billing dates and their invoices, or to enter the readings of a usage subscription.",
      ],
      astuces: [
        "One invoice per customer and billing date, dated on the billing date; a billing date already invoiced is never invoiced again.",
        "“Link” attaches a billing date to an invoice made by hand: it will no longer be invoiced.",
        "In “Text on the invoice”, {mois}, {annee}, {debut_periode} and {fin_periode} are replaced by the invoiced period.",
        "“Import readings” reads a CSV file (customer, product, date, quantity, note); on any error, nothing is imported.",
      ],
      motsCles: ["recurring billing", "recurring invoice", "billing date", "usage billing", "meter reading", "service contract"],
    },
  },
  "/contrats-location": {
    fr: {
      resume: "Les produits loués à un client sur une période : le prix de contrat (caution, mise en service…) se facture une fois, le loyer à chaque période pour les unités encore louées.",
      etapes: [
        "Cliquez sur « Nouveau contrat » : client, « Type de location » (facultatif, il préremplit le rythme et la fin), dates, « Rythme des loyers » et jour de facturation.",
        "Ajoutez les produits : quantité, unités « Exemptées », « Prix de contrat » et « Loyer » par unité et par période, puis « Enregistrer ».",
        "Ouvrez le contrat (« Ouvrir ») et cliquez sur « Facturer le contrat » pour facturer ses prix de contrat.",
        "À chaque période, cliquez sur « Facturer les loyers dus », choisissez la date limite, puis « Créer les factures ».",
        "Notez les unités rendues avec « Retour » sur la ligne du produit : elles ne paient plus de loyer ensuite.",
        "En fin de contrat, « Renouveler » crée le contrat suivant ; « Imprimer » donne le contrat en PDF, prêt à signer.",
      ],
      astuces: [
        "Une ligne facturée ne change plus : pour louer davantage, ajoutez une ligne, qui fera une facture complémentaire.",
        "Un contrat jamais facturé se supprime ; sinon, décochez « Actif » : il n'est plus facturé ni renouvelé.",
        "Avec « Renouvellement automatique », le contrat suivant est créé à la fin, prix augmentés des hausses indiquées, une fois la tâche planifiée activée dans « Tâches planifiées ».",
        "Le filtre « Finissant avant le » retrouve les contrats bientôt à renouveler.",
      ],
      motsCles: ["location", "loyer", "location de matériel", "caution", "bail", "matériel loué"],
    },
    en: {
      resume: "Products rented to a customer over a period: the contract price (deposit, setup…) is invoiced once, the rent every period for the units still rented.",
      etapes: [
        "Click “New contract”: customer, “Rental type” (optional, it prefills the frequency and end date), dates, “Rent frequency” and billing day.",
        "Add the products: quantity, “Exempt” units, “Contract price” and “Rent” per unit and period, then click “Save”.",
        "Open the contract (“Open”) and click “Invoice the contract” to invoice its contract prices.",
        "Each period, click “Invoice due rents”, choose the cut-off date, then “Create the invoices”.",
        "Record returned units with “Return” on the product line: they pay no more rent afterwards.",
        "At the end of the contract, “Renew” creates the next one; “Print” gives the contract as a PDF, ready to sign.",
      ],
      astuces: [
        "An invoiced line no longer changes: to rent more, add a line, which makes an additional invoice.",
        "A contract never invoiced can be deleted; otherwise untick “Active”: it is no longer invoiced or renewed.",
        "With “Automatic renewal”, the next contract is created at the end, prices raised by the given increases, once the scheduled task is enabled in “Scheduled tasks”.",
        "The “Ending before” filter finds the contracts that will soon need renewing.",
      ],
      motsCles: ["rental", "rent", "lease", "equipment hire", "hire", "rented equipment"],
    },
  },
  "/consignations": {
    fr: {
      resume: "La marchandise déposée chez un client (prêt, démonstration, stock de dépannage) qui reste à l'entreprise : ce qu'il détient, ce qu'il rend et ce qu'il a consommé, à facturer.",
      etapes: [
        "Onglet « Bons de dépôt » : cliquez sur « Nouveau dépôt », choisissez le client, la date, les produits et leurs quantités, puis « Enregistrer ».",
        "Onglet « Relevé » : choisissez le client pour voir ce qu'il détient, avec sa valeur au coût.",
        "Marchandise utilisée : saisissez la colonne « Consommé », puis « Facturer la consommation » (cochez « Émettre la facture » au besoin).",
        "Marchandise rendue : saisissez la colonne « Retourné » et le « Lieu de retour », puis « Enregistrer la reprise ».",
        "Onglet « Historique » : les mouvements d'un client sur une période ; onglet « Consignations chez les clients » : le rapport de tous les clients, en PDF.",
      ],
      astuces: [
        "Un geste à la fois : facturez la consommation et enregistrez la reprise séparément.",
        "On ne sort jamais plus que ce que le client détient.",
        "La consommation est facturée aux prix du client, dans sa devise ; un produit sans prix est refusé.",
        "Le bon de dépôt n'a pas de montant : ce n'est pas une vente, et le stock compté n'est pas touché. « Imprimer » donne le bon.",
      ],
      motsCles: ["dépôt", "dépôt-vente", "prêt de marchandise", "démonstration", "stock chez le client", "bon de dépôt"],
    },
    en: {
      resume: "Goods left at a customer (loan, demo, emergency stock) that still belong to the company: what they hold, what they return, and what they used, to be invoiced.",
      etapes: [
        "“Deposit notes” tab: click “New deposit”, choose the customer, the date, the products and their quantities, then “Save”.",
        "“Statement” tab: choose the customer to see what they hold, with its value at cost.",
        "Goods used: fill in the “Consumed” column, then “Invoice the consumption” (tick “Issue the invoice” if needed).",
        "Goods returned: fill in the “Returned” column and the “Return location”, then “Save the take-back”.",
        "“History” tab: a customer's movements over a period; “Consignments at customers” tab: the report for all customers, also as a PDF.",
      ],
      astuces: [
        "One action at a time: invoice the consumption and save the take-back separately.",
        "You can never take out more than the customer holds.",
        "Consumption is invoiced at the customer's prices, in their currency; a product without a price is refused.",
        "A deposit note has no amounts: it is not a sale, and the counted stock is not affected. “Print” gives the note.",
      ],
      motsCles: ["consignment", "sale or return", "goods on loan", "demo stock", "customer stock", "deposit note"],
    },
  },
  "/notes-credit": {
    fr: {
      resume: "Les notes de crédit (avoirs) qui annulent tout ou partie d'une facture émise : retour, erreur de prix, geste commercial. Le crédit restant se rembourse ou s'impute sur une autre facture.",
      etapes: [
        "Cliquez sur « Nouvelle note de crédit » et choisissez la facture, ou cliquez sur « Créditer » dans « Factures ».",
        "Indiquez les quantités à créditer (« Tout créditer », « Tout effacer ») ; baissez le prix unitaire pour un geste commercial et précisez le « Motif ».",
        "Cliquez sur « Enregistrer le brouillon » ou « Enregistrer et émettre » : à l'émission, la note reçoit son numéro et réduit le reste dû de la facture.",
        "S'il reste un « Crédit disponible », cliquez sur « Imputer sur une facture » du même client, ou sur « Rembourser ».",
        "Cherchez une note par numéro, client, facture ou motif, et filtrez par statut.",
      ],
      astuces: [
        "Seul un brouillon se modifie, s'émet ou se supprime ; une note émise ne change plus.",
        "On ne crédite jamais plus que ce qui a été facturé sur chaque ligne ; la remise et les taxes sont celles de la facture.",
        "Une note de crédit ne remet rien en stock : pour la marchandise rapportée, passez par « Retours clients ».",
        "Une note passe « Soldée » quand tout son crédit a été imputé ou remboursé.",
      ],
      motsCles: ["avoir", "remboursement", "crédit client", "annulation de facture", "geste commercial", "facture d'avoir"],
    },
    en: {
      resume: "Credit notes that cancel all or part of an issued invoice: return, price error, goodwill gesture. Any remaining credit is refunded or applied to another invoice.",
      etapes: [
        "Click “New credit note” and choose the invoice, or click “Credit” in “Factures”.",
        "Enter the quantities to credit (“Credit everything”, “Clear all”); lower the unit price for a goodwill gesture and fill in the “Reason”.",
        "Click “Save draft” or “Save and issue”: once issued, the note gets its number and reduces what is still due on the invoice.",
        "If “Available credit” remains, click “Apply to an invoice” of the same customer, or “Refund”.",
        "Search for a note by number, customer, invoice or reason, and filter by status.",
      ],
      astuces: [
        "Only a draft can be edited, issued or deleted; an issued note no longer changes.",
        "You can never credit more than was invoiced on each line; the discount and taxes are those of the invoice.",
        "A credit note puts nothing back in stock: for goods brought back, use “Customer returns”.",
        "A note becomes “Settled” once all its credit has been applied or refunded.",
      ],
      motsCles: ["credit memo", "refund", "customer credit", "invoice cancellation", "goodwill gesture", "price adjustment"],
    },
  },
  "/retours-clients": {
    fr: {
      resume: "La marchandise rapportée par un client, avec la raison du retour : remise en stock dans un secteur ou mise au rebut. Le retour d'une facture donne ensuite sa note de crédit.",
      etapes: [
        "Cliquez sur « Retour d'une facture » et choisissez la facture, ou sur « Retour sans facture » ; ou partez de « Retour de marchandise » dans « Factures ».",
        "Pour chaque produit, indiquez la quantité « Remis en stock » avec son « Secteur », la quantité « Au rebut » et la « Raison ».",
        "Cliquez sur « Enregistrer le brouillon », ou sur « Enregistrer et traiter » : le retour reçoit son numéro et la marchandise rentre en stock ou part au rebut.",
        "Retour traité d'une facture : « Créer la note de crédit » prépare une note brouillon, à émettre dans « Notes de crédit ».",
        "Marchandise à renvoyer au fournisseur : sur un retour traité, « Renvoyer au fournisseur » prépare le retour fournisseur.",
      ],
      astuces: [
        "Un retour traité ne se modifie ni ne se supprime plus.",
        "La raison est obligatoire dès que l'entreprise a défini des raisons de type « Retour client » (« Référentiels », rubrique « Raisons »).",
        "On ne retourne pas plus que ce qui a été facturé, retours déjà traités déduits.",
        "Chaque remise en stock est un arrivage dans son secteur, compté comme une entrée par la prise d'inventaire suivante.",
      ],
      motsCles: ["retour de marchandise", "rma", "reprise", "rebut", "produit défectueux", "remise en stock"],
    },
    en: {
      resume: "Goods brought back by a customer, with the reason for the return: put back in stock in a sector or scrapped. A return from an invoice then gives its credit note.",
      etapes: [
        "Click “Return from an invoice” and choose the invoice, or “Return without invoice”; or start from “Return goods” in “Factures”.",
        "For each product, enter the quantity “Back in stock” with its “Sector”, the quantity “Scrapped” and the “Reason”.",
        "Click “Save draft”, or “Save and process”: the return gets its number and the goods go back into stock or are scrapped.",
        "Processed return from an invoice: “Create the credit note” prepares a draft credit note, to be issued in “Credit notes”.",
        "Goods to send back to the supplier: on a processed return, “Send back to supplier” prepares the supplier return.",
      ],
      astuces: [
        "A processed return can no longer be edited or deleted.",
        "The reason is required as soon as the company has defined reasons of type “Customer return” (“Reference data”, “Reasons” section).",
        "You cannot return more than was invoiced, minus the returns already processed.",
        "Each restock is an arrival in its sector, counted as an entry by the next stocktake.",
      ],
      motsCles: ["return of goods", "rma", "take-back", "scrap", "defective product", "restock"],
    },
  },
};
