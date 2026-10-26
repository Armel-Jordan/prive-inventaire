import type { AidesEcrans } from '../types';

/** Aide des écrans de la catégorie Ventes : encaissements, caisse, livraisons… (phase 6.6). */
export const AIDES_VENTES_SUITE: AidesEcrans = {
  "/encaissements": {
    fr: {
      resume: "Enregistrez l'argent reçu d'un client en une fois (chèque, virement, espèces…) et répartissez-le sur ses factures dues ; retrouvez ensuite chaque encaissement et son reçu.",
      etapes: [
        "Cliquez sur « Nouvel encaissement », choisissez le client, puis saisissez le montant reçu, la date, le mode, la référence et, hors espèces, le compte bancaire.",
        "Dans « Factures dues », saisissez le montant « Encaissé » de chaque facture, cochez « Tout » pour la solder ou cliquez sur « Répartir automatiquement ».",
        "Vérifiez le bas de la fenêtre : un surplus reste au crédit du client en acompte ; une différence en sa faveur peut être accordée en escompte (case à cocher).",
        "Cliquez sur « Enregistrer » : le détail s'ouvre, avec le reçu en PDF et les « Pièces jointes » (chèque numérisé, avis de virement).",
        "Pour retrouver un encaissement, cherchez par numéro, référence, client ou facture, et filtrez par mode, statut et période (« Du », « Au »).",
      ],
      astuces: [
        "Un montant ne peut pas dépasser le reste dû de sa facture. Si la répartition dépasse le montant reçu, l'enregistrement n'est possible qu'en accordant la différence en escompte.",
        "« Corriger » ne change que la référence et les notes. Pour toute autre erreur, annulez l'encaissement avec un motif, puis saisissez-le de nouveau : ses factures redeviennent dues. Un encaissement dont le surplus a déjà servi ne s'annule plus.",
        "Sur un poste de caisse, l'argent entre dans la session ouverte de la caisse (écran « Gestion de caisse »). Vous pouvez aussi encaisser un client depuis l'écran « Comptes clients ».",
        "Les acomptes et notes de crédit encore disponibles du client sont rappelés dans la fenêtre : appliquez-les depuis les écrans « Acomptes » et « Notes de crédit ».",
      ],
      motsCles: ["paiement client", "règlement", "reçu", "chèque", "virement", "escompte"],
    },
    en: {
      resume: "Record money received from a customer in one go (cheque, transfer, cash…) and spread it over their invoices due; then find each payment and its receipt.",
      etapes: [
        "Click “New payment”, choose the customer, then enter the amount received, the date, the method, the reference and, except for cash, the bank account.",
        "Under “Invoices due”, enter the “Applied” amount for each invoice, tick “All” to settle it in full or click “Distribute automatically”.",
        "Check the bottom of the window: a surplus stays to the customer's credit as a deposit; a difference in their favour can be granted as a discount (tick box).",
        "Click “Save”: the detail opens, with the PDF receipt and the “Attachments” (scanned cheque, transfer advice).",
        "To find a payment, search by number, reference, customer or invoice, and filter by method, status and period (“From”, “To”).",
      ],
      astuces: [
        "An amount cannot exceed the balance due on its invoice. If the amounts applied exceed the amount received, you can only save by granting the difference as a discount.",
        "“Correct” only changes the reference and the notes. For any other mistake, cancel the payment with a reason, then enter it again: its invoices become due again. A payment whose surplus has already been used can no longer be cancelled.",
        "On a register workstation, the money goes into the register's open session (“Cash management” screen). You can also record a customer's payment from the “Receivables” screen.",
        "The customer's available deposits and credit notes are shown in the window: apply them from the “Deposits” and “Credit notes” screens.",
      ],
      motsCles: ["receipt", "payment received", "settlement", "cheque", "remittance", "discount"],
    },
  },
  "/acomptes": {
    fr: {
      resume: "Les paiements reçus d'un client avant sa facture, libres ou réservés à une commande : suivez ce qu'il reste de chacun, appliquez-le sur une facture ou remboursez-le.",
      etapes: [
        "Cliquez sur « Nouvel acompte », choisissez le client, puis saisissez la date, le montant, le mode et la référence (n° de chèque, de virement…).",
        "Au besoin, réservez-le à une commande du client (« Réservé à ») : il s'appliquera de lui-même à sa facture, à l'émission. Sinon, gardez « Aucune (acompte libre) ».",
        "Pour payer une facture due du même client, cliquez sur l'icône « Appliquer sur une facture », puis choisissez la facture, le montant et la date.",
        "Pour rendre ce qui reste au client, cliquez sur « Rembourser » : par défaut, l'argent ressort par le mode de paiement de l'acompte.",
        "Cherchez par référence, client ou montant, filtrez avec « Disponibilité », et cliquez sur l'œil « Voir » pour les factures payées et les remboursements.",
      ],
      astuces: [
        "Un acompte déjà appliqué ou remboursé ne change plus de date, de mode ni de montant, et ne se supprime plus : seuls la référence, la réservation et les notes se modifient.",
        "L'acompte né du surplus d'un encaissement se corrige en annulant cet encaissement (écran « Encaissements »).",
        "Vous pouvez aussi saisir un acompte depuis une commande (écran « Commandes » des Ventes) ou l'appliquer dans la fenêtre « Enregistrer un paiement » d'une facture (écran « Factures »).",
        "Sur un poste de caisse, l'acompte reçu entre dans la session ouverte de la caisse, et son remboursement en sort (écran « Gestion de caisse »).",
      ],
      motsCles: ["avance client", "dépôt", "arrhes", "crédit client", "remboursement d'acompte"],
    },
    en: {
      resume: "Payments received from a customer before their invoice, free or reserved for an order: track what is left of each one, apply it to an invoice or refund it.",
      etapes: [
        "Click “New deposit”, choose the customer, then enter the date, the amount, the method and the reference (cheque number, transfer reference…).",
        "If needed, pick one of the customer's orders under “Reserved for”: the deposit is applied automatically to its invoice when issued. Otherwise keep “None (free deposit)”.",
        "To pay an invoice due from the same customer, click the “Apply to an invoice” icon, then choose the invoice, the amount and the date.",
        "To give back what is left, click “Refund”: by default, the money goes back through the deposit's payment method.",
        "Search by reference, customer or amount, filter with “Availability”, and click the “View” eye to see the invoices paid and the refunds.",
      ],
      astuces: [
        "Once a deposit has been applied or refunded, its date, method and amount can no longer change and it can no longer be deleted: only the reference, the reservation and the notes can.",
        "A deposit created from a customer payment's surplus is corrected by cancelling that payment (“Customer payments” screen).",
        "You can also enter a deposit from an order (“Commandes” screen, Ventes menu) or apply it in an invoice's “Enregistrer un paiement” window (“Factures” screen).",
        "On a register workstation, a deposit received goes into the register's open session and its refund comes out of it (“Cash management” screen).",
      ],
      motsCles: ["advance payment", "prepayment", "down payment", "customer credit", "deposit refund"],
    },
  },
  "/demandes-acompte": {
    fr: {
      resume: "Demandez un dépôt à un client avant des travaux ou une commande (description et montant, sans taxe), envoyez la demande, puis marquez-la payée pour enregistrer l'acompte.",
      etapes: [
        "Cliquez sur « Nouvelle demande », choisissez le client, la date et le montant, puis décrivez les travaux ou la commande dans « Description ».",
        "Au besoin, choisissez le « Devis » et la « Commande » du client concernés, puis cliquez sur « Enregistrer » : la demande reste en brouillon, modifiable.",
        "Envoyez-la avec l'enveloppe « Envoyer par courriel » (PDF joint) : elle passe d'elle-même « Envoyée ». Si vous la remettez autrement, cliquez sur « Marquer envoyée ».",
        "Au dépôt reçu, cliquez sur « Marquer payée », saisissez la date, le mode et la référence, puis confirmez : l'acompte est enregistré au dossier du client.",
        "Suivez l'état dans la liste : « Payée », puis « Partiellement appliquée » et « Appliquée » à mesure que l'acompte paie des factures.",
      ],
      astuces: [
        "Une demande d'acompte n'est pas une facture : elle ne change rien au compte du client tant qu'elle n'est pas marquée payée.",
        "Une demande envoyée ne se modifie plus ; une demande payée ne se supprime plus.",
        "Payée, la demande crée l'acompte, réservé à sa commande si celle-ci est toujours en cours : retrouvez-le dans l'écran « Acomptes ».",
        "Sur un poste de caisse, le dépôt marqué payé entre dans la session ouverte de la caisse (écran « Gestion de caisse »).",
      ],
      motsCles: ["facture d'acompte", "demande de dépôt", "arrhes", "avance", "dépôt"],
    },
    en: {
      resume: "Ask a customer for a deposit before work or an order (description and amount, without tax), send the request, then mark it paid to record the deposit.",
      etapes: [
        "Click “New request”, choose the customer, the date and the amount, then describe the work or the order under “Description”.",
        "If needed, choose the customer's related “Quote” and “Order”, then click “Save”: the request stays a draft that you can still edit.",
        "Send it with the “Send by email” envelope (PDF attached): it becomes “Sent” automatically. If you hand it over another way, click “Mark as sent”.",
        "When the deposit arrives, click “Mark as paid”, enter the date, method and reference, then confirm: the deposit is recorded in the customer's account.",
        "Follow the status in the list: “Paid”, then “Partly applied” and “Applied” as the deposit pays invoices.",
      ],
      astuces: [
        "A deposit request is not an invoice: it changes nothing in the customer's account until it is marked paid.",
        "A sent request can no longer be edited; a paid request can no longer be deleted.",
        "Once paid, the request creates the deposit, reserved for its order if that order is still open: find it in the “Deposits” screen.",
        "On a register workstation, a deposit marked paid goes into the register's open session (“Cash management” screen).",
      ],
      motsCles: ["deposit invoice", "down payment request", "prepayment request", "advance", "retainer"],
    },
  },
  "/point-de-vente": {
    fr: {
      resume: "Vendez au comptoir : scannez ou cherchez les produits, encaissez en un ou plusieurs modes et rendez la monnaie ; la facture est créée, émise et payée d'un coup, avec son reçu à imprimer.",
      etapes: [
        "Scannez un code ou tapez un numéro, puis Entrée (ou « Ajouter ») ; vous pouvez aussi chercher par désignation et cliquer sur le produit trouvé.",
        "Ajustez les quantités dans le « Panier » ; un prix saisi remplace celui du client (hors taxes) et un produit sans prix doit être chiffré avant d'encaisser.",
        "Gardez « Client de passage » (son nom est facultatif) ou choisissez un client de la liste : ses prix et sa devise s'appliquent.",
        "Cliquez sur « Encaisser », saisissez le montant de chaque mode (« Ajouter un mode de paiement » pour en combiner plusieurs), puis « Valider la vente ».",
        "Rendez la « Monnaie à rendre », puis cliquez sur « Imprimer le reçu » ou « Facture PDF », et sur « Nouvelle vente ».",
      ],
      astuces: [
        "Seules les espèces peuvent dépasser le total (la monnaie est rendue). Le client de passage paie toute la vente ; un client en compte peut payer une partie (hors espèces), ou rien avec « Porter au compte ».",
        "Une carte cadeau est un mode de paiement : saisissez ou scannez son numéro, puis « Vérifier » propose au plus son solde.",
        "Sur un poste de caisse, les paiements entrent dans la session ouverte : caisse fermée, il faut l'ouvrir avant de valider la vente. Le poste se règle dans « Gestion de caisse » › « Caisse de ce poste ».",
        "Le bouton « Vendre une carte cadeau » vend une carte au comptoir sans quitter l'écran.",
      ],
      motsCles: ["caisse enregistreuse", "vente au comptoir", "ticket de caisse", "pdv", "panier", "vente rapide"],
    },
    en: {
      resume: "Sell at the counter: scan or search the products, take payment with one or more methods and give change; the invoice is created, issued and paid at once, with its receipt ready to print.",
      etapes: [
        "Scan a code or type a number, then press Enter (or “Add”); you can also search by description and click the product found.",
        "Adjust the quantities in the “Cart”; a price you type replaces the customer price (excluding tax), and a product without a price must be priced before payment.",
        "Keep “Walk-in customer” (the name is optional) or choose a customer from the list: their prices and currency apply.",
        "Click “Take payment”, enter the amount of each method (“Add a payment method” to combine several), then click “Complete the sale”.",
        "Give back the “Change due”, then click “Print the receipt” or “Invoice PDF”, and “New sale”.",
      ],
      astuces: [
        "Only cash can exceed the total (change is given). A walk-in customer pays the whole sale; an account customer can pay part of it (not in cash), or nothing with “Charge to account”.",
        "A gift card is a payment method: type or scan its number, then “Check” suggests an amount up to its balance.",
        "On a register workstation, payments go into the open session: if the register is closed, it must be opened before the sale can be completed. Set the workstation in “Cash management” › “This workstation's register”.",
        "The “Sell a gift card” button sells a card at the counter without leaving the screen.",
      ],
      motsCles: ["pos", "counter sale", "till", "cash sale", "checkout", "receipt"],
    },
  },
  "/caisses": {
    fr: {
      resume: "Suivez l'argent d'une caisse sur la journée : ouverture avec un fond, entrées et sorties d'argent, puis fermeture en comptant le tiroir, avec l'écart par mode de paiement et un sommaire imprimable.",
      etapes: [
        "Sur le poste qui encaisse, choisissez sa caisse dans « Caisse de ce poste » : les paiements saisis sur ce poste entreront dans la session ouverte de cette caisse.",
        "Cliquez sur « Ouvrir la caisse » et saisissez le « Fond initial », l'argent déposé dans le tiroir avant d'encaisser (il peut être nul).",
        "En cours de session, enregistrez une « Entrée d'argent » (appoint de monnaie…) ou une « Sortie d'argent » (petit achat, dépôt à la banque…) avec sa raison.",
        "En fin de journée, cliquez sur « Fermer la caisse », saisissez le montant compté de chaque mode et vérifiez l'écart, puis fermez (ou « Fermer et imprimer »).",
        "Choisissez la « Caisse » et la période des « Sessions », puis cliquez sur une session pour voir son sommaire, ses opérations et les encaissements par employé.",
      ],
      astuces: [
        "Les caisses se créent dans « Référentiels » › « Caisses ». Le réglage « Caisse de ce poste » est propre à ce navigateur.",
        "Caisse fermée, un encaissement sur le poste est refusé ; si vous gérez la caisse, une fenêtre propose de l'ouvrir sur-le-champ.",
        "La fermeture fige l'attendu, le compté et l'écart : expliquez un écart dans la « Note de fermeture ». « Imprimer le sommaire » n'est possible qu'une fois la caisse fermée.",
        "Un remboursement d'acompte fait avec « Rembourser » sort déjà du tiroir : ne le saisissez pas en sortie d'argent. Les paiements reçus pendant la session sur des postes hors caisse sont listés à part.",
      ],
      motsCles: ["fond de caisse", "tiroir-caisse", "fermeture de caisse", "écart de caisse", "session de caisse", "sommaire de caisse"],
    },
    en: {
      resume: "Follow a register's money through the day: open it with a float, record cash in and out, then close it by counting the drawer, with the gap per payment method and a printable summary.",
      etapes: [
        "On the workstation that takes payments, choose its register in “This workstation's register”: payments entered there will go into that register's open session.",
        "Click “Open the register” and enter the “Opening float”, the money put in the drawer before taking payments (it can be zero).",
        "During the session, record “Cash in” (extra change…) or “Cash out” (small purchase, bank deposit…) with its reason.",
        "At the end of the day, click “Close the register”, enter the counted amount for each method and check the gap, then close (or “Close and print”).",
        "Choose the “Register” and the period of the “Sessions”, then click a session to see its summary, its operations and the payments by employee.",
      ],
      astuces: [
        "Registers are created in “Reference data” › “Cash registers”. The “This workstation's register” setting is kept in this browser only.",
        "When the register is closed, a payment on the workstation is refused; if you manage the register, a window offers to open it on the spot.",
        "Closing freezes the expected, counted and gap amounts: explain a gap in the “Closing note”. “Print the summary” only works once the register is closed.",
        "A deposit refund made with “Refund” already leaves the drawer: do not record it as cash out. Payments received during the session on non-register workstations are listed separately.",
      ],
      motsCles: ["cash drawer", "till", "float", "cash count", "end of day", "register session"],
    },
  },
  "/cartes-cadeaux": {
    fr: {
      resume: "Vendez des cartes cadeaux au porteur et suivez leur solde : elles paient au point de vente ou sur une facture jusqu'à épuisement, se rechargent, et leur solde se rembourse.",
      etapes: [
        "Cliquez sur « Vendre une carte », saisissez le montant et le mode reçu (« Payée par »), et le numéro de la carte si vos cartes sont pré-imprimées.",
        "Au besoin, choisissez le « Client acheteur » (à titre indicatif) et cochez « Date d'expiration » pour en fixer une, puis cliquez sur « Vendre une carte ».",
        "Remettez au client la carte ou son « Certificat » (PDF), imprimable depuis la liste ou depuis la fiche de la carte.",
        "Ouvrez une carte (œil « Ouvrir ») pour voir son solde et son historique, la « Recharger », changer son « Expiration » ou « Rembourser le solde ».",
        "Cherchez par numéro de carte ou client et filtrez par « Statut » ; le « Solde à honorer » totalise ce que les cartes encore valables peuvent payer.",
      ],
      astuces: [
        "La carte s'utilise au porteur : au « Point de vente », ou dans la fenêtre « Enregistrer un paiement » d'une facture (mode « Carte cadeau »), au plus son solde.",
        "Le remboursement rend tout le solde et annule la carte : il n'y a pas de remboursement partiel. Une carte annulée ne se recharge plus.",
        "Au paiement, une carte annulée, expirée, au solde insuffisant ou dans une autre devise que la facture est refusée.",
        "Sur un poste de caisse, la vente et la recharge d'une carte entrent dans la session ouverte ; un paiement par carte cadeau n'apporte pas d'argent en caisse.",
      ],
      motsCles: ["bon cadeau", "chèque cadeau", "carte prépayée", "certificat cadeau", "recharge"],
    },
    en: {
      resume: "Sell bearer gift cards and follow their balance: they pay at the point of sale or on an invoice until used up, can be topped up, and their balance can be refunded.",
      etapes: [
        "Click “Sell a card”, enter the amount and the method received (“Paid by”), and the card number if your cards are pre-printed.",
        "If needed, choose the “Buyer” (for information only) and tick “Expiry date” to set one, then click “Sell a card”.",
        "Give the customer the card or its “Certificate” (PDF), printable from the list or from the card.",
        "Open a card (“Open” eye) to see its balance and history, “Top up” the card, change its “Expiry” or “Refund the balance”.",
        "Search by card number or customer and filter by “Status”; the “Outstanding balance” totals what the cards still valid can pay.",
      ],
      astuces: [
        "The card is a bearer card: it pays at the “Point of sale”, or in an invoice's “Enregistrer un paiement” window (“Gift card” method), up to its balance.",
        "A refund gives back the whole balance and cancels the card: there is no partial refund. A cancelled card can no longer be topped up.",
        "At payment, a card that is cancelled, expired, short of balance or in another currency than the invoice is refused.",
        "On a register workstation, selling or topping up a card goes into the open session; paying with a gift card brings no money into the register.",
      ],
      motsCles: ["gift voucher", "gift certificate", "prepaid card", "store credit", "top-up"],
    },
  },
  "/comptes-clients": {
    fr: {
      resume: "Voyez ce que chaque client doit à une date, réparti selon l'ancienneté du retard (non échu, 1 à 30 jours… plus de 90 jours), et ouvrez son relevé de compte.",
      etapes: [
        "Choisissez la date dans « Au » (aujourd'hui par défaut) : les sommes dues et les retards sont calculés à cette date.",
        "Lisez les cartes « Total dû », « En retard » et « Plus de 90 jours », puis le tableau par client ; cherchez un client par son code ou son nom.",
        "Cliquez sur l'icône « Relevé de compte » d'un client, réglez la période (« Du », « Au »), puis lisez ses mouvements, son solde, ses factures dues et ses crédits.",
        "Cliquez sur l'icône « Encaisser » d'un client pour saisir son paiement sans quitter l'écran.",
        "Cliquez sur « Exporter » pour obtenir l'analyse ou le relevé au format CSV, à ouvrir dans un tableur.",
      ],
      astuces: [
        "Le retard se compte depuis l'échéance de la facture, ou depuis sa date si elle n'en a pas. Les brouillons et les factures annulées ne comptent pas.",
        "Quand des crédits existent (notes de crédit et acomptes ni utilisés ni remboursés), les colonnes « Crédits » et « Solde net » s'ajoutent.",
        "C'est un état : rien n'est enregistré. À une date passée, une facture payée depuis compte encore comme due.",
        "Le nom du client ouvre sa fiche ; pour relancer les retards, passez à l'écran « Relances ».",
      ],
      motsCles: ["balance âgée", "créances", "analyse chronologique", "relevé client", "encours", "impayés"],
    },
    en: {
      resume: "See what each customer owes at a date, split by how late it is (current, 1–30 days… over 90 days), and open their statement of account.",
      etapes: [
        "Choose the date in “As of” (today by default): the amounts due and the delays are computed at that date.",
        "Read the “Total due”, “Overdue” and “Over 90 days” cards, then the table by customer; search a customer by code or name.",
        "Click a customer's “Statement of account” icon, set the period (“From”, “To”), then read their transactions, balance, invoices due and credits.",
        "Click a customer's “Record a payment” icon to enter their payment without leaving the screen.",
        "Click “Export” to get the analysis or the statement as a CSV file, to open in a spreadsheet.",
      ],
      astuces: [
        "The delay counts from the invoice's due date, or from its date when it has none. Drafts and cancelled invoices are left out.",
        "When credits exist (credit notes and deposits neither used nor refunded), the “Credits” and “Net balance” columns appear.",
        "It is a report: nothing is recorded. At a past date, an invoice paid since then still counts as due.",
        "The customer's name opens their record; to chase late payers, go to the “Reminders” screen.",
      ],
      motsCles: ["aged receivables", "aging report", "accounts receivable", "customer statement", "outstanding", "unpaid invoices"],
    },
  },
  "/relances": {
    fr: {
      resume: "Les factures en retard, du plus ancien retard au plus récent, avec leurs relances : relancez par courriel (facture jointe) ou notez une relance faite par téléphone ou par courrier.",
      etapes: [
        "Filtrez avec « Retard » (plus de 30, 60 ou 90 jours), cherchez par client ou facture, et cochez au besoin « Jamais relancées ».",
        "Cliquez sur l'enveloppe « Relancer par courriel », vérifiez le message (facture en PDF jointe) et cliquez sur « Envoyer » : la relance est notée à l'envoi.",
        "Pour un appel ou un courrier, cliquez sur « Noter une relance », choisissez le « Moyen » et la date, notez la réponse du client, puis « Enregistrer ».",
        "Cliquez sur « Historique des relances » pour voir les relances d'une facture : niveau, date, moyen, auteur et notes.",
      ],
      astuces: [
        "Chaque relance prend le niveau suivant (relance 1, 2, 3…) ; la colonne « Relances » montre leur nombre et la dernière.",
        "Les relances automatiques se règlent dans l'écran « Tâches planifiées » (tâche « Relances de paiement », inactive par défaut) : 3 au plus par facture, aux clients qui ont une adresse de courriel.",
        "Un client sans adresse de courriel affiche « Pas de courriel » : notez plutôt une relance faite par téléphone ou par courrier.",
        "La date d'une relance notée ne peut pas être dans le futur. Une facture entièrement payée sort de la liste.",
      ],
      motsCles: ["recouvrement", "rappel de paiement", "factures en retard", "impayés", "lettre de relance", "retard de paiement"],
    },
    en: {
      resume: "Overdue invoices, from the oldest delay to the most recent, with their reminders: send a reminder by email (invoice attached) or record one made by phone or letter.",
      etapes: [
        "Filter with “Delay” (over 30, 60 or 90 days), search by customer or invoice, and tick “Never reminded” if needed.",
        "Click the “Send a reminder by email” envelope, check the message (invoice PDF attached) and click “Send”: the reminder is recorded when it is sent.",
        "For a call or a letter, click “Record a reminder”, choose the “Channel” and the date, note the customer's answer, then click “Save”.",
        "Click “Reminder history” to see an invoice's reminders: level, date, channel, author and notes.",
      ],
      astuces: [
        "Each reminder takes the next level (reminder 1, 2, 3…); the “Reminders” column shows how many there are and the last one.",
        "Automatic reminders are set in the “Scheduled tasks” screen (“Payment reminders” task, off by default): at most 3 per invoice, to customers with an email address.",
        "A customer without an email address shows “No email”: record a reminder made by phone or letter instead.",
        "A recorded reminder cannot be dated in the future. An invoice paid in full leaves the list.",
      ],
      motsCles: ["dunning", "collections", "overdue invoices", "payment chaser", "unpaid", "past due"],
    },
  },
  "/bons-livraison": {
    fr: {
      resume: "Préparez et livrez la marchandise des factures émises : préparation, mise en colis, puis livraison avec les quantités livrées et la signature du client.",
      etapes: [
        "Créez le bon depuis une facture émise (écran « Factures », icône « Créer bon de livraison ») : il arrive ici au statut « Créé ».",
        "Cliquez sur « Démarrer préparation », puis sur « Modifier préparation » pour saisir les quantités préparées, et sur « Sauvegarder ».",
        "Au besoin, rangez la marchandise dans des colis : bouton « Colis », « Nouveau colis », puis « Imprimer les étiquettes ».",
        "Cliquez sur « Marquer prêt » : le bon peut alors partir dans une tournée (écran « Tournées ») ou être remis au client.",
        "À la livraison, cliquez sur « Enregistrer livraison », saisissez quantités livrées, lots ou numéros de série, notes et signature, puis « Valider livraison ».",
      ],
      astuces: [
        "Le « Statut personnalisé » (détail du document) est une étiquette de repérage en couleur, sans effet sur le cycle : elle se pose même sur un document clos, et la liste se filtre par étiquette.",
        "« Expédition » (détail du bon) : transporteur et numéro de suivi, repris de la facture, modifiables tant que le bon n'est ni livré ni annulé ; chaque colis peut avoir son numéro de suivi.",
        "Une livraison partielle met le bon au statut « Partiel ». La facture couvre déjà toute la quantité : pour livrer le reste, cliquez sur « Livrer le reste » dans l'écran « Factures », qui crée un nouveau bon pour les seules quantités non livrées, sans rien refacturer. Un reste qui ne sera pas livré se règle par une note de crédit.",
        "La quantité livrée ne peut pas dépasser la quantité préparée. Pour un produit suivi par lot, les lots proposés sont les premiers à périmer.",
        "Seuls les bons « Créé » ou « En préparation » s'annulent. Les colis se préparent jusqu'au départ du bon ; un colis coché comme chargé se décoche d'abord.",
        "Une plume verte près du statut signale un bon signé ; « Voir détails » et le PDF du bon montrent la signature, le signataire et l'heure.",
      ],
      motsCles: ["bl", "livraison", "préparation de commande", "colis", "expédition", "bordereau de livraison"],
    },
    en: {
      resume: "Prepare and deliver the goods of issued invoices: picking, packing into parcels, then delivery with the quantities delivered and the customer's signature.",
      etapes: [
        "Create the note from an issued invoice (“Factures” screen, “Créer bon de livraison” icon): it arrives here with the status “Créé” (created).",
        "Click “Démarrer préparation” (start picking), then “Modifier préparation” to enter the quantities picked, and “Sauvegarder” (save).",
        "If needed, pack the goods into parcels: “Parcels” button, “New parcel”, then “Print the labels”.",
        "Click “Marquer prêt” (mark ready): the note can then go on a delivery round (“Tournées” screen) or be handed to the customer.",
        "On delivery, click “Enregistrer livraison”, enter the quantities delivered, lots or serial numbers, notes and “Customer signature”, then click “Valider livraison”.",
      ],
      astuces: [
        "The “Custom status” (document details) is a coloured tracking label with no effect on the cycle: it can be set even on a closed document, and the list can be filtered by label.",
        "“Shipment” (note details): carrier and tracking number, carried from the invoice, editable until the note is delivered or cancelled; each parcel can have its own tracking number.",
        "A partial delivery sets the note to “Partiel”. The invoice already covers the whole quantity: to deliver the rest, click “Deliver the rest” in the “Factures” screen, which creates a new note for the undelivered quantities only, without billing anything again. A remainder that will not be delivered is settled with a credit note.",
        "The quantity delivered cannot exceed the quantity picked. For lot-tracked products, the lots expiring first are suggested.",
        "Only notes that are “Créé” or “En préparation” can be cancelled. Parcels can be prepared until the note leaves; a parcel ticked as loaded must be unticked first.",
        "A green pen next to the status marks a signed note; “Voir détails” and the note's PDF show the signature, the signer and the time.",
      ],
      motsCles: ["delivery note", "packing slip", "dispatch", "picking", "proof of delivery", "shipping"],
    },
  },
  "/camions": {
    fr: {
      resume: "Les véhicules de livraison de l'entreprise : immatriculation, type, capacité en kg et en m³, date du contrôle technique. Chaque tournée part avec l'un d'eux.",
      etapes: [
        "Cliquez sur « Ajouter », puis saisissez l'« Immatriculation » (obligatoire et unique), la « Marque » et le « Modèle ».",
        "Choisissez le « Type » (Camionnette, Camion ou Semi-remorque) et, si vous la connaissez, la capacité en kg et en m³.",
        "Indiquez la « Date contrôle technique », puis cliquez sur « Créer ».",
        "Pour corriger un camion, cliquez sur son crayon, modifiez-le, puis cliquez sur « Modifier » ; la corbeille le supprime.",
      ],
      astuces: [
        "La colonne « CT » affiche la date du contrôle technique.",
        "La capacité en kg sert aux tournées : la charge estimée d'une tournée s'affiche en rouge au-delà de la capacité de son camion.",
        "À la création d'une tournée, seuls les camions actifs et libres ce jour-là (sans tournée planifiée ou en cours) sont proposés.",
        "Un camion qui a une tournée planifiée ou en cours ne peut pas être supprimé.",
      ],
      motsCles: ["véhicule", "flotte", "camionnette", "immatriculation", "parc de véhicules"],
    },
    en: {
      resume: "The company's delivery vehicles: registration, type, capacity in kg and m³, technical inspection date. Each delivery round leaves with one of them.",
      etapes: [
        "Click “Ajouter” (add), then enter the “Immatriculation” (registration number, required and unique), the “Marque” (make) and the “Modèle” (model).",
        "Choose the “Type” (“Camionnette” van, “Camion” truck or “Semi-remorque” semi-trailer) and, if you know it, the capacity in kg and in m³.",
        "Enter the “Date contrôle technique” (technical inspection date), then click “Créer” (create).",
        "To correct a truck, click its pencil, change it, then click “Modifier” (update); the bin deletes it.",
      ],
      astuces: [
        "The “CT” column shows the technical inspection date.",
        "The capacity in kg is used by delivery rounds: a round's estimated load shows in red above its truck's capacity.",
        "When a round is created, only active trucks that are free that day (no planned or in-progress round) are offered.",
        "A truck with a planned or in-progress round cannot be deleted.",
      ],
      motsCles: ["truck", "vehicle", "fleet", "van", "lorry", "registration"],
    },
  },
  "/tournees": {
    fr: {
      resume: "Organisez les livraisons d'un jour : un camion, ses bons de livraison prêts dans l'ordre de passage, le chargement à cocher, puis le départ et le retour de la tournée.",
      etapes: [
        "Cliquez sur « Nouvelle tournée », choisissez la date, un camion libre ce jour-là, au besoin une zone et une « Route de livraison », puis « Créer ».",
        "Cliquez sur « Ajouter un bon de livraison » et ajoutez les bons « Prêt » ; avec une route, « Ajouter les bons de la route » (dans « Voir détails ») les ajoute d'un coup.",
        "Dans « Voir détails », rangez les arrêts avec les flèches ou « Trier par séquence », puis imprimez la « Feuille de route » du livreur.",
        "Cliquez sur « Chargement » : scannez l'étiquette de chaque colis ou cochez les colis et la marchandise « Hors colis » ; imprimez au besoin la « Fiche de chargement ».",
        "Au départ, cliquez sur « Démarrer » : les bons passent « En livraison » et l'heure de départ est notée. Au retour, cliquez sur « Terminer ».",
      ],
      astuces: [
        "Le chargement suit l'ordre inverse des livraisons : le dernier arrêt au fond du camion, le premier près des portes. Il ne se coche plus après le départ.",
        "Tant que la tournée est « Planifiée », vous pouvez retirer un bon, réordonner les arrêts ou supprimer la tournée ; un bon retiré peut rejoindre une autre tournée.",
        "Le détail montre la charge estimée face à la capacité du camion (en rouge au-delà) et, s'il y en a à bord, le « Document matières dangereuses » à remettre au conducteur.",
        "Les livraisons se valident bon par bon dans l'écran « Bons Livraison ». Par défaut, la liste montre les tournées du jour : « Toutes les dates » enlève ce filtre.",
      ],
      motsCles: ["tournée de livraison", "itinéraire", "feuille de route", "chargement du camion", "livreur", "planning de livraison"],
    },
    en: {
      resume: "Organise a day's deliveries: a truck, its ready delivery notes in stop order, the loading to tick off, then the round's departure and return.",
      etapes: [
        "Click “Nouvelle tournée” (new round), choose the date, a truck free that day, if needed a zone and a “Delivery route”, then click “Créer”.",
        "Click “Ajouter un bon de livraison” and add the “Prêt” (ready) notes; with a route, “Add the route's delivery notes” (in “Voir détails”) adds them at once.",
        "In “Voir détails”, order the stops with the arrows or “Sort by sequence”, then print the driver's “Route sheet”.",
        "Click “Loading”: scan each parcel's label or tick the parcels and the “Loose goods”; print the “Loading sheet” if needed.",
        "On departure, click “Démarrer”: the notes switch to “En livraison” and the departure time is recorded. On return, click “Terminer”.",
      ],
      astuces: [
        "Loading follows the reverse delivery order: the last stop at the back of the truck, the first one near the doors. It can no longer be ticked after departure.",
        "While the round is “Planifiée”, you can remove a note, reorder the stops or delete the round; a removed note can join another round.",
        "The detail shows the estimated load against the truck's capacity (in red above it) and, when there are any on board, the “Dangerous goods document” to give the driver.",
        "Deliveries are confirmed note by note in the “Bons Livraison” screen. By default the list shows today's rounds: “Toutes les dates” removes that filter.",
      ],
      motsCles: ["delivery round", "delivery run", "route", "route sheet", "truck loading", "driver"],
    },
  },
};
