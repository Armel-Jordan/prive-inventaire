import type { AidesEcrans } from '../types';

/** Aide des écrans de la catégorie Finance (phase 6.6). */
export const AIDES_FINANCE: AidesEcrans = {
  "/comptabilite": {
    fr: {
      resume: "Vue rapide des ventes facturées et des achats reçus sur une période : totaux, marge brute, évolution mensuelle, et journal des ventes ou des achats à exporter.",
      etapes: [
        "Choisissez la période avec les dates « Du » et « Au » (par défaut, le dernier mois).",
        "Lisez les cartes « Total Ventes HT », « Total Achats » et « Marge Brute », puis le graphique « Évolution mensuelle ».",
        "Cliquez sur « Journal des Ventes » pour voir les factures de la période, ou sur « Journal des Achats » pour les réceptions.",
        "Côté achats, filtrez par fournisseur ou cochez « Grouper par commande » pour obtenir un total par commande.",
        "Cliquez sur « Exporter CSV » pour télécharger le journal affiché.",
      ],
      astuces: [
        "Les ventes sont les factures émises de la période (ni brouillons ni annulées), hors taxes ; la « Marge Brute » correspond à ces ventes moins les achats reçus.",
        "Les achats sont les réceptions, valorisées au coût saisi à la réception, sinon au prix de la commande, et converties dans la devise de l'entreprise au taux de la commande.",
        "Le filtre fournisseur ne change que le tableau et son total : les cartes du haut portent sur tous les fournisseurs.",
        "Cet écran ne passe aucune écriture : la comptabilité en partie double se tient dans « Journal comptable ».",
      ],
      motsCles: ["journal des ventes", "journal des achats", "marge brute", "chiffre d'affaires", "ventes et achats", "export csv"],
    },
    en: {
      resume: "A quick view of invoiced sales and received purchases over a period: totals, gross margin, monthly trend, and the sales or purchases journal to export.",
      etapes: [
        "Choose the period with the “Du” and “Au” dates (the last month by default).",
        "Read the “Total Ventes HT”, “Total Achats” and “Marge Brute” cards, then the “Évolution mensuelle” chart.",
        "Click “Journal des Ventes” to see the period's invoices, or “Journal des Achats” to see goods receipts.",
        "For purchases, filter by supplier or tick “Grouper par commande” to get one total per order.",
        "Click “Exporter CSV” to download the journal shown.",
      ],
      astuces: [
        "Sales are the period's issued invoices (no drafts or cancelled invoices), excluding tax; “Marge Brute” is those sales minus the purchases received.",
        "Purchases are goods receipts, valued at the cost entered on receipt or else at the order price, and converted to the company currency at the order's rate.",
        "The supplier filter only changes the table and its total: the cards at the top cover all suppliers.",
        "This screen posts no journal entry: double-entry bookkeeping is kept in “Journal entries”.",
      ],
      motsCles: ["sales journal", "purchases journal", "gross margin", "revenue", "sales and purchases", "csv export"],
    },
  },

  "/plan-comptable": {
    fr: {
      resume: "Les comptes de votre comptabilité et leur hiérarchie : partez d'un plan type (SYSCOHADA, France, Canada) ou d'un fichier, puis créez, modifiez ou désactivez vos comptes.",
      etapes: [
        "Plan vide : cliquez sur « Partir de ce plan » sous le plan type voulu, ou sur « Importer un fichier » pour charger votre plan au format CSV.",
        "Pour créer un compte, cliquez sur « Ajouter un compte », ou sur l'icône « Ajouter un sous-compte » (+) d'une ligne.",
        "Renseignez le numéro, le libellé, le compte parent, la nature (actif, passif, capitaux propres, produit, charge) et le type, puis cliquez sur « Enregistrer ».",
        "Retrouvez un compte avec la recherche, les filtres de nature et d'état, ou « Replier les classes » et « Tout déplier ».",
        "Cliquez sur « Compléter avec un plan type » pour ajouter les comptes manquants d'un plan type, ou sur « Exporter » pour obtenir le plan en CSV.",
      ],
      astuces: [
        "Un compte qui a des écritures ou des sous-comptes ne se supprime pas ; désactivé, un compte ne reçoit plus de nouvelles écritures.",
        "Un seul compte peut avoir le type « Résultat de l'exercice », de nature capitaux propres : il reçoit le résultat à la clôture de l'exercice.",
        "Décochez « Saisie manuelle » pour un compte qui ne doit recevoir que les écritures générées par l'application, comme la comptabilisation automatique.",
        "L'import d'un fichier est tout ou rien : à la moindre erreur, aucun compte n'est créé et les erreurs sont listées avec leur ligne.",
      ],
      motsCles: ["plan de comptes", "comptes comptables", "liste des comptes", "plan type", "syscohada", "chart of accounts"],
    },
    en: {
      resume: "Your bookkeeping accounts and their hierarchy: start from a standard chart (SYSCOHADA, France, Canada) or a file, then create, edit or deactivate accounts.",
      etapes: [
        "Empty chart: click “Start from this chart” under the standard chart you want, or “Import a file” to load your own chart from a CSV file.",
        "To create an account, click “Add an account”, or the “Add a sub-account” icon (+) on a row.",
        "Fill in the number, name, parent account, nature (asset, liability, equity, revenue, expense) and type, then click “Save”.",
        "Find an account with the search box, the nature and status filters, or “Collapse classes” and “Expand all”.",
        "Click “Complete from a standard chart” to add the missing accounts of a standard chart, or “Export” to get the chart as a CSV file.",
      ],
      astuces: [
        "An account with entries or sub-accounts cannot be deleted; once deactivated, an account receives no new entries.",
        "Only one account can have the “Net income” type, an equity account: it receives the result when the fiscal year is closed.",
        "Untick “Manual entries” for an account that should only receive entries generated by the application, such as automatic posting.",
        "File import is all or nothing: on any error, no account is created and the errors are listed with their line.",
      ],
      motsCles: ["chart of accounts", "ledger accounts", "account list", "standard chart", "syscohada", "account numbers"],
    },
  },

  "/exercices-comptables": {
    fr: {
      resume: "Les exercices comptables de l'entreprise et leurs périodes : créez un exercice, fermez les périodes terminées et clôturez l'exercice pour reporter son résultat.",
      etapes: [
        "Cliquez sur « Nouvel exercice » : les douze mois qui suivent le dernier exercice (ou l'année civile en cours) sont proposés.",
        "Vérifiez « Début » et « Fin », choisissez le « Découpage » (mensuel, trimestriel ou une seule période), puis cliquez sur « Enregistrer ».",
        "Dépliez un exercice pour voir ses périodes ; la période du jour porte le badge « En cours ».",
        "Quand une période est terminée et vérifiée, cliquez sur « Fermer » sur sa ligne : elle n'accepte plus d'écriture.",
        "En fin d'exercice, cliquez sur « Clôturer », vérifiez les produits, les charges et le résultat, puis cliquez sur « Clôturer l'exercice ».",
        "Si la comptabilité reprend une activité existante, saisissez les soldes du premier exercice avec le bouton « Soldes d'ouverture ».",
      ],
      astuces: [
        "Un exercice dure 24 mois au plus et n'en chevauche aucun autre ; ses dates et son découpage se figent dès qu'une période est fermée ou qu'il a des écritures.",
        "La clôture demande que les exercices précédents soient clôturés et, s'il y a des produits ou des charges, un compte de type « Résultat de l'exercice » au « Plan comptable ».",
        "Rouvrir une période, clôturer ou rouvrir un exercice demande la permission « Clôturer ou rouvrir un exercice » ; seul le dernier exercice clôturé se rouvre.",
        "La clôture passe une écriture qui solde les produits et les charges ; rouvrir l'exercice la supprime, mais ses périodes restent fermées.",
      ],
      motsCles: ["exercice fiscal", "année financière", "périodes comptables", "clôture de l'exercice", "fermer une période", "fiscal year"],
    },
    en: {
      resume: "The company's fiscal years and their periods: create a fiscal year, close finished periods and close the year to carry its result forward.",
      etapes: [
        "Click “New fiscal year”: the twelve months after the last fiscal year (or the current calendar year) are proposed.",
        "Check “Start” and “End”, choose the “Periods” option (monthly, quarterly or a single period), then click “Save”.",
        "Expand a fiscal year to see its periods; today's period has the “Current” badge.",
        "When a period is finished and checked, click “Close” on its row: it no longer accepts entries.",
        "At year end, click “Close” next to the fiscal year, check revenue, expenses and the result, then click “Close the year”.",
        "If the bookkeeping takes over an existing business, enter the first year's balances with the “Opening balances” button.",
      ],
      astuces: [
        "A fiscal year lasts 24 months at most and overlaps no other; its dates and periods are locked once a period is closed or it has entries.",
        "Closing requires the previous years to be closed and, if there is revenue or expenses, a “Net income” type account in the “Chart of accounts”.",
        "Reopening a period, or closing or reopening a year, needs the “Close or reopen a fiscal year” permission; only the last closed year can be reopened.",
        "Closing posts an entry that clears the revenue and expense accounts; reopening the year deletes it, but its periods stay closed.",
      ],
      motsCles: ["fiscal year", "financial year", "accounting periods", "year-end closing", "close a period", "period lock"],
    },
  },

  "/journal-comptable": {
    fr: {
      resume: "Les écritures en partie double, classées par journal : consultez-les, saisissez une écriture équilibrée, corrigez-la, contre-passez-la ou répétez-la.",
      etapes: [
        "Filtrez par dates (l'exercice en cours par défaut), journal, compte, origine ou texte ; cliquez sur la date d'une écriture pour déplier ses lignes.",
        "Cliquez sur « Nouvelle écriture », puis choisissez le journal, la date, la référence et le libellé.",
        "Saisissez au moins deux lignes, chacune avec un compte et un débit ou un crédit ; « Ajouter une ligne » en ajoute d'autres.",
        "Quand les débits égalent les crédits (« Écriture équilibrée. »), cliquez sur « Enregistrer » ; « Équilibrer » porte l'écart sur une ligne sans montant.",
        "Pour corriger une écriture d'une période fermée, cliquez sur « Contre-passer » et choisissez une date dans une période ouverte.",
        "Cliquez sur « Exporter les lignes » pour télécharger en CSV les lignes de la période choisie.",
      ],
      astuces: [
        "Une écriture saisie se modifie ou se supprime tant que sa période est ouverte et qu'elle n'est pas pointée en rapprochement bancaire ; une écriture générée se corrige à sa source ou se contre-passe.",
        "La date doit tomber dans un exercice existant et une période ouverte ; un en-tête, un compte inactif ou fermé à la saisie manuelle est refusé.",
        "Le bouton « Journaux » ajoute, renomme ou désactive un journal ; son code préfixe le numéro des écritures et ne change plus une fois des écritures passées.",
        "« Répéter » planifie une écriture manuelle à intervalle régulier (loyer, amortissement) ; ses dates se suivent dans « Écritures récurrentes ».",
      ],
      motsCles: ["écritures comptables", "saisie d'écriture", "journal général", "partie double", "contre-passation", "opérations diverses"],
    },
    en: {
      resume: "Double-entry journal entries, sorted by journal: browse them, post a balanced entry, edit it, reverse it or repeat it.",
      etapes: [
        "Filter by dates (the current fiscal year by default), journal, account, origin or text; click an entry's date to expand its lines.",
        "Click “New entry”, then choose the journal, date, reference and label.",
        "Enter at least two lines, each with an account and a debit or a credit; “Add a line” adds more.",
        "When debits equal credits (“Balanced entry.”), click “Save”; “Balance” puts the difference on a line without an amount.",
        "To correct an entry in a closed period, click “Reverse” and pick a date in an open period.",
        "Click “Export lines” to download the lines of the chosen period as a CSV file.",
      ],
      astuces: [
        "A typed entry can be edited or deleted while its period is open and it is not ticked in a bank reconciliation; a generated entry is corrected at its source or reversed.",
        "The date must fall in an existing fiscal year and an open period; header accounts, inactive accounts and accounts closed to manual entries are refused.",
        "The “Journals” button adds, renames or deactivates journals; a journal's code prefixes entry numbers and is locked once entries are posted.",
        "“Repeat” schedules a manual entry at regular intervals (rent, depreciation); follow its dates in “Recurring entries”.",
      ],
      motsCles: ["journal entries", "general journal", "double entry", "manual entry", "reversal", "bookkeeping"],
    },
  },

  "/ecritures-recurrentes": {
    fr: {
      resume: "Les écritures qui reviennent à intervalle régulier (loyer, amortissement, abonnement) : suivez leurs dates et convertissez les dates échues en écritures.",
      etapes: [
        "Créez une récurrence depuis « Journal comptable » : bouton « Répéter » d'une écriture manuelle, avec la première date, le nombre de répétitions et la fréquence.",
        "Ici, lisez pour chaque récurrence sa fréquence, son montant, les dates « Converties », la « Prochaine date » et son « État ».",
        "Cliquez sur « Convertir les dates échues » pour créer d'un coup les écritures de toutes les dates arrivées.",
        "Cliquez sur le numéro d'une récurrence pour voir ses dates, et sur « Convertir » pour une seule date échue.",
        "Utilisez « Suspendre » et « Reprendre », ou « Supprimer les dates restantes » pour arrêter la récurrence.",
      ],
      astuces: [
        "Une date refusée (période fermée, compte désactivé…) reste à convertir, et son motif s'affiche après la conversion.",
        "Pour convertir sans y penser, activez la tâche « Écritures récurrentes » dans « Tâches planifiées ».",
        "La récurrence copie l'écriture modèle : modifier ou supprimer ce modèle ensuite ne change pas les dates planifiées.",
        "Après « Supprimer les dates restantes », les écritures déjà créées restent au journal, avec le badge « Récurrence n° … ».",
      ],
      motsCles: ["écriture récurrente", "écritures répétitives", "abonnement", "loyer", "amortissement", "récurrence"],
    },
    en: {
      resume: "Entries that come back at regular intervals (rent, depreciation, subscriptions): follow their dates and convert the due dates into journal entries.",
      etapes: [
        "Create a recurrence from “Journal entries”: the “Repeat” button of a manual entry, with the first date, the number of repetitions and the frequency.",
        "Here, read each recurrence's frequency, amount, “Converted” dates, “Next date” and “State”.",
        "Click “Convert the due dates” to create the entries of all the dates that have come, at once.",
        "Click a recurrence's number to see its dates, and “Convert” for a single due date.",
        "Use “Suspend” and “Resume”, or “Delete the remaining dates” to stop the recurrence.",
      ],
      astuces: [
        "A refused date (closed period, deactivated account…) stays to be converted, and its reason is shown after the conversion.",
        "To convert automatically, turn on the “Recurring entries” task in “Scheduled tasks”.",
        "The recurrence copies the template entry: changing or deleting the template later does not change the planned dates.",
        "After “Delete the remaining dates”, the entries already created stay in the journal, with the “Recurrence no. …” badge.",
      ],
      motsCles: ["recurring entries", "repeating entries", "recurring transactions", "rent", "depreciation", "subscription"],
    },
  },

  "/comptabilisation": {
    fr: {
      resume: "Passe au journal les ventes, les achats et les paiements, une écriture par document, selon vos réglages de journaux et de comptes.",
      etapes: [
        "Dans « Réglages », renseignez « Comptabiliser les documents datés à partir du » : les documents plus anciens ne passent pas au journal.",
        "Cliquez sur « Proposer d'après le plan comptable » pour remplir les journaux et les comptes vides, puis vérifiez-les.",
        "Au besoin, réglez les « Modes de paiement » (caisse ou banque), les comptes par taxe et par catégorie de produits, puis cliquez sur « Enregistrer les réglages ».",
        "Dans « À comptabiliser », choisissez la période « Du » et « Au », puis vérifiez le nombre et le montant des documents par type.",
        "Cliquez sur « Comptabiliser » ; les documents refusés s'affichent avec leur motif et attendent la prochaine comptabilisation.",
      ],
      astuces: [
        "Le bouton « Comptabiliser » reste grisé tant que des réglages indispensables manquent : ils sont listés en haut de l'écran.",
        "Un document ne passe qu'une fois ; s'il est annulé ou supprimé ensuite, son écriture est contre-passée à la comptabilisation suivante.",
        "Une période fermée ou un compte manquant ne bloque jamais une facture ni un paiement : le document attend simplement d'être comptabilisé.",
        "Pour comptabiliser chaque jour sans y penser, activez la tâche « Comptabilisation automatique » dans « Tâches planifiées ».",
      ],
      motsCles: ["comptabilisation", "passage en comptabilité", "écritures automatiques", "interface comptable", "imputation comptable", "automatic posting"],
    },
    en: {
      resume: "Posts sales, purchases and payments to the journal, one entry per document, according to your journal and account settings.",
      etapes: [
        "In “Settings”, fill in “Post documents dated from”: older documents are not posted to the journal.",
        "Click “Propose from the chart of accounts” to fill the empty journals and accounts, then check them.",
        "If needed, set the “Payment methods” (cash or bank) and the accounts per tax and product category, then click “Save the settings”.",
        "In “To post”, choose the “From” and “To” period, then check the count and amount of documents by type.",
        "Click “Post”; refused documents are listed with their reason and wait for the next posting.",
      ],
      astuces: [
        "The “Post” button stays greyed out while essential settings are missing: they are listed at the top of the screen.",
        "A document is posted only once; if it is later cancelled or deleted, its entry is reversed at the next posting.",
        "A closed period or a missing account never blocks an invoice or a payment: the document simply waits to be posted.",
        "To post every day automatically, turn on the “Automatic posting” task in “Scheduled tasks”.",
      ],
      motsCles: ["automatic posting", "post to the journal", "automatic entries", "accounting integration", "account mapping", "posting settings"],
    },
  },

  "/banque": {
    fr: {
      resume: "Les comptes bancaires de l'entreprise côté comptabilité : soldes, mouvements, transactions sans facture (dépôts, retraits, virements), relevés importés et rapprochement bancaire.",
      etapes: [
        "Onglet « Comptes » : reliez chaque compte bancaire à son propre compte comptable de trésorerie et, au besoin, à un journal.",
        "Onglet « Mouvements » : choisissez un compte et une période pour voir les entrées, les sorties et le solde progressif.",
        "Onglet « Transactions » : cliquez sur « Nouvelle transaction » pour un dépôt, un retrait (frais, intérêts) ou un virement entre comptes.",
        "Onglet « Relevés » : cliquez sur « Importer un relevé » (CSV, OFX ou CAMT), puis reliez chaque opération aux mouvements du compte.",
        "Onglet « Rapprochement » : saisissez la « Date du relevé » et le « Solde du relevé », cliquez sur « Commencer », puis pointez les mouvements.",
        "Quand l'écart (« Écart avec le relevé ») est nul, cliquez sur « Terminer ».",
      ],
      astuces: [
        "Les comptes bancaires se créent dans « Référentiels » → « Comptes bancaires » et se relient à un compte de type « Trésorerie » ; non reliés, ils n'ont ni mouvements ni rapprochement.",
        "Les documents (encaissements, déboursés…) n'apparaissent dans les mouvements qu'une fois passés au journal par la « Comptabilisation automatique ».",
        "Un mouvement pointé ne se modifie plus : retirez-le d'abord du rapprochement, en rouvrant celui-ci s'il est terminé.",
        "Une transaction saisie par erreur s'annule (ouvrez-la, puis « Annuler la transaction ») : elle reste dans la liste au statut « Annulée » ; si elle était passée au journal, son écriture est contre-passée.",
      ],
      motsCles: ["rapprochement bancaire", "relevé bancaire", "conciliation bancaire", "transactions bancaires", "frais bancaires", "virement entre comptes"],
    },
    en: {
      resume: "The company's bank accounts on the accounting side: balances, movements, transactions without an invoice (deposits, withdrawals, transfers), imported statements and bank reconciliation.",
      etapes: [
        "“Accounts” tab: link each bank account to its own cash ledger account and, if needed, to a journal.",
        "“Movements” tab: choose an account and a period to see money in, money out and the running balance.",
        "“Transactions” tab: click “New transaction” for a deposit, a withdrawal (fees, interest) or a transfer between accounts.",
        "“Statements” tab: click “Import a statement” (CSV, OFX or CAMT), then link each operation to the account's movements.",
        "“Reconciliation” tab: enter the “Statement date” and “Statement balance”, click “Start”, then tick the movements.",
        "When the “Difference with the statement” is zero, click “Finish”.",
      ],
      astuces: [
        "Bank accounts are created in “Reference data” → “Bank accounts” and linked to a “Cash and bank” type account; unlinked, they have no movements or reconciliation.",
        "Documents (customer receipts, disbursements…) appear in the movements only once posted to the journal by “Automatic posting”.",
        "A ticked movement can no longer be changed: first untick it in the reconciliation, reopening the reconciliation if it is finished.",
        "A transaction entered by mistake is cancelled (open it, then “Cancel transaction”): it stays in the list as “Cancelled”; if it was already posted, its entry is reversed.",
      ],
      motsCles: ["bank reconciliation", "bank statement", "bank transactions", "bank fees", "transfer between accounts", "statement import"],
    },
  },

  "/budgets": {
    fr: {
      resume: "Le budget de chaque compte, période par période d'un exercice, puis sa comparaison au réel du journal.",
      etapes: [
        "Choisissez l'exercice dans la liste « Exercice ».",
        "Cliquez sur « Comptes du budget », cochez les comptes à budgéter (« Cocher produits et charges » coche ceux affichés), puis cliquez sur « Enregistrer ».",
        "Dans la feuille, saisissez le montant de chaque compte par période : la cellule s'enregistre quand vous la quittez, Entrée passe à la suivante.",
        "Pour étaler un total, cliquez sur l'icône « Répartir » en fin de ligne ; pour partir d'un autre exercice, cliquez sur « Copier d'un exercice ».",
        "Ouvrez l'onglet « Budget et réel », choisissez « De la période » et « À la période », lisez les écarts et exportez-les avec « CSV ».",
      ],
      astuces: [
        "Saisissez les montants dans le sens naturel du compte (un produit ou une charge en positif) ; une cellule vidée est retirée du budget.",
        "« Copier d'un exercice » ajuste les montants d'un pourcentage ; sans « Remplacer les montants déjà saisis », seules les cellules vides sont remplies.",
        "Le réel est le mouvement du journal hors écriture de clôture ; un écart en vert est favorable (une charge sous son budget, un produit au-dessus).",
        "Le budget d'un exercice clôturé ne se modifie plus ; l'onglet « Budget et réel » demande aussi la permission « Voir les états comptables ».",
      ],
      motsCles: ["budget annuel", "budget prévisionnel", "budget et réel", "écarts budgétaires", "contrôle budgétaire", "budget par compte"],
    },
    en: {
      resume: "Each account's budget, period by period over a fiscal year, then compared with the actuals of the journal.",
      etapes: [
        "Choose the fiscal year in the “Fiscal year” list.",
        "Click “Budget accounts”, tick the accounts to budget (“Tick revenue and expenses” ticks the ones shown), then click “Save”.",
        "In the sheet, enter each account's amount per period: a cell is saved when you leave it, and Enter moves to the next one.",
        "To spread a total, click the “Spread” icon at the end of the row; to start from another year, click “Copy from a fiscal year”.",
        "Open the “Budget vs actual” tab, choose “From period” and “To period”, read the variances and export them with “CSV”.",
      ],
      astuces: [
        "Enter amounts in the account's natural direction (revenue or expense as a positive amount); an emptied cell is removed from the budget.",
        "“Copy from a fiscal year” adjusts amounts by a percentage; without “Replace the amounts already entered”, only empty cells are filled.",
        "Actuals are the journal movements excluding the closing entry; a green variance is favourable (an expense under budget, revenue above it).",
        "A closed fiscal year's budget can no longer be changed; the “Budget vs actual” tab also needs the “View financial statements” permission.",
      ],
      motsCles: ["annual budget", "budget vs actual", "variance", "forecast", "budget by account", "budgetary control"],
    },
  },

  "/grand-livre": {
    fr: {
      resume: "Les écritures d'un compte sur une période, avec le solde d'ouverture, le solde progressif et le solde final ; un en-tête cumule ses sous-comptes.",
      etapes: [
        "Choisissez le « Compte » ; un en-tête porte la mention « avec ses sous-comptes ».",
        "Ajustez les dates « Du » et « Au » (l'exercice en cours par défaut).",
        "Lisez le « Solde d'ouverture », chaque écriture avec son solde progressif (D ou C), puis la ligne « Totaux et solde final ».",
        "Tapez un libellé, un numéro ou une référence dans « Recherche » pour filtrer les écritures.",
        "Cliquez sur « Exporter » pour télécharger le grand livre complet en CSV.",
      ],
      astuces: [
        "L'écran affiche au plus 5 000 lignes ; l'export, lui, contient tout le grand livre.",
        "Depuis la « Balance », un clic sur un compte ouvre directement son grand livre sur la même période.",
        "Le « Solde d'ouverture » cumule les écritures antérieures à « Du » ; si « Du » est le premier jour de l'exercice, les soldes d'ouverture saisis figurent parmi les écritures.",
      ],
      motsCles: ["grand-livre", "détail d'un compte", "mouvements d'un compte", "historique d'un compte", "solde progressif", "general ledger"],
    },
    en: {
      resume: "An account's entries over a period, with the opening balance, running balance and closing balance; a header account adds up its sub-accounts.",
      etapes: [
        "Choose the “Account”; a header account is marked “with its sub-accounts”.",
        "Adjust the “From” and “To” dates (the current fiscal year by default).",
        "Read the “Opening balance”, each entry with its running balance (Dr or Cr), then the “Totals and closing balance” line.",
        "Type a label, number or reference in “Search” to filter the entries.",
        "Click “Export” to download the complete ledger as a CSV file.",
      ],
      astuces: [
        "The screen shows 5,000 lines at most; the export contains the whole ledger.",
        "From the “Trial balance”, clicking an account opens its ledger for the same period.",
        "The “Opening balance” adds up the entries before “From”; if “From” is the first day of the fiscal year, the opening balances entered appear among the entries.",
      ],
      motsCles: ["general ledger", "account detail", "account history", "account movements", "running balance", "ledger report"],
    },
  },

  "/balance-comptable": {
    fr: {
      resume: "Pour chaque compte mouvementé : solde d'ouverture, débits et crédits de la période, solde final, avec un contrôle de l'équilibre des débits et des crédits.",
      etapes: [
        "Ajustez les dates « Du » et « Au » (l'exercice en cours par défaut).",
        "Vérifiez l'indicateur « Balance équilibrée » : le total des débits doit égaler celui des crédits.",
        "Lisez les comptes regroupés par classe, avec le total de chaque classe et les « Totaux » en bas.",
        "Cliquez sur un compte pour ouvrir son « Grand livre » sur la même période.",
        "Cliquez sur « Exporter » pour télécharger la balance en CSV.",
      ],
      astuces: [
        "Seuls les comptes qui ont un solde d'ouverture ou des mouvements sur la période apparaissent ; la classe est le premier caractère du numéro.",
        "Le solde d'ouverture cumule les écritures antérieures à « Du » ; si « Du » est le premier jour de l'exercice, les soldes d'ouverture saisis figurent dans les mouvements.",
      ],
      motsCles: ["balance générale", "balance des comptes", "soldes des comptes", "balance comptable", "trial balance", "équilibre débit crédit"],
    },
    en: {
      resume: "For each account with activity: opening balance, debits and credits of the period, closing balance, with a check that debits and credits balance.",
      etapes: [
        "Adjust the “From” and “To” dates (the current fiscal year by default).",
        "Check the “Balanced” indicator: total debits must equal total credits.",
        "Read the accounts grouped by class, with each class total and the “Totals” at the bottom.",
        "Click an account to open its “General ledger” for the same period.",
        "Click “Export” to download the trial balance as a CSV file.",
      ],
      astuces: [
        "Only accounts with an opening balance or movements over the period are listed; the class is the first character of the account number.",
        "The opening balance adds up the entries before “From”; if “From” is the first day of the fiscal year, the opening balances entered appear in the movements.",
      ],
      motsCles: ["trial balance", "account balances", "balance of accounts", "debit and credit totals", "general balance", "accounts summary"],
    },
  },

  "/etats-financiers": {
    fr: {
      resume: "Le bilan à une date et le compte de résultat d'une période, tirés des écritures et regroupés selon votre plan comptable.",
      etapes: [
        "Onglet « Bilan » : choisissez la date « Au » (aujourd'hui par défaut) et vérifiez l'indicateur « Bilan équilibré ».",
        "Lisez l'actif, les capitaux propres et les dettes, puis le « Total du passif (capitaux propres et dettes) ».",
        "Onglet « Compte de résultat » : choisissez les dates « Du » et « Au » (l'exercice en cours par défaut) pour voir les produits, les charges et le résultat.",
        "Cliquez sur un groupe pour déplier le détail de ses comptes.",
        "Cliquez sur « Exporter » pour télécharger en CSV l'état affiché.",
      ],
      astuces: [
        "Les comptes se regroupent sous leur en-tête le plus proche du « Plan comptable » : des en-têtes bien nommés donnent des états lisibles.",
        "Tant que l'exercice n'est pas clôturé, son résultat (produits moins charges) figure dans les capitaux propres.",
        "Le compte de résultat exclut l'écriture de clôture : un exercice clôturé garde donc son compte de résultat.",
        "À l'actif, un compte au solde créditeur, comme les amortissements, vient en déduction.",
      ],
      motsCles: ["bilan", "compte de résultat", "états de synthèse", "résultat net", "bénéfice ou perte", "balance sheet"],
    },
    en: {
      resume: "The balance sheet at a date and the income statement for a period, drawn from the entries and grouped according to your chart of accounts.",
      etapes: [
        "“Balance sheet” tab: choose the “As of” date (today by default) and check the “Balanced” indicator.",
        "Read assets, equity and liabilities, then “Total equity and liabilities”.",
        "“Income statement” tab: choose the “From” and “To” dates (the current fiscal year by default) to see revenue, expenses and the result.",
        "Click a group to expand the detail of its accounts.",
        "Click “Export” to download the statement shown as a CSV file.",
      ],
      astuces: [
        "Accounts are grouped under their nearest header in the “Chart of accounts”: well-named headers make readable statements.",
        "Until the fiscal year is closed, its result (revenue minus expenses) is shown in equity.",
        "The income statement excludes the closing entry: a closed fiscal year therefore keeps its income statement.",
        "Under assets, an account with a credit balance, such as accumulated depreciation, is deducted.",
      ],
      motsCles: ["balance sheet", "income statement", "profit and loss", "p&l", "net income", "financial reports"],
    },
  },

  "/rapport-taxes": {
    fr: {
      resume: "Les taxes collectées sur les ventes et déductibles sur les achats, taxe par taxe, sur une période, pour préparer et justifier vos déclarations.",
      etapes: [
        "Choisissez la période avec « Du » et « Au », ou un raccourci : « Mois précédent » (par défaut), « Mois en cours » ou « Trimestre précédent ».",
        "Choisissez la « Présentation » : « Détail de la période » (un document par ligne), « Total par jour » ou « Total de la période ».",
        "Lisez le « Sommaire par taxe » : collectée, déductible et différence, jusqu'à la « Taxe à reverser » ou au « Crédit de taxe à récupérer ».",
        "Comparez avec les « Comptes de taxes au journal » : un écart signale des documents pas encore comptabilisés ou des taxes saisies à la main.",
        "Téléchargez le rapport avec « CSV » ou « PDF ».",
      ],
      astuces: [
        "Ventes : factures émises (y compris celles créditées), diminuées des notes de crédit ; achats : factures fournisseurs enregistrées ; ni brouillons ni documents annulés.",
        "Les montants sont dans la devise de l'entreprise, au taux de chaque document ; une ligne taxée par un groupe de taxes garde le détail de chaque taxe.",
        "Les comptes de taxes au journal n'apparaissent qu'une fois la « Comptabilisation automatique » réglée.",
      ],
      motsCles: ["déclaration de tva", "tva collectée", "tva déductible", "taxes à reverser", "tps tvq", "rapport de taxes"],
    },
    en: {
      resume: "Taxes collected on sales and deductible on purchases, tax by tax, over a period, to prepare and support your tax returns.",
      etapes: [
        "Choose the period with “From” and “To”, or a shortcut: “Previous month” (the default), “Current month” or “Previous quarter”.",
        "Choose the “Layout”: “Period detail” (one document per line), “Total per day” or “Period total”.",
        "Read the “Summary per tax”: collected, deductible and difference, down to the “Tax to remit” or the “Tax credit to recover”.",
        "Compare with the “Tax accounts in the journal”: a difference points to documents not posted yet or taxes entered by hand.",
        "Download the report with “CSV” or “PDF”.",
      ],
      astuces: [
        "Sales: issued invoices (including credited ones), less credit notes; purchases: recorded supplier invoices; no drafts or cancelled documents.",
        "Amounts are in the company currency, at each document's rate; a line taxed with a tax group keeps the detail of each tax.",
        "The tax accounts in the journal only appear once “Automatic posting” is set up.",
      ],
      motsCles: ["vat return", "sales tax report", "tax collected", "input tax", "gst", "tax return"],
    },
  },

  "/previsions-stock": {
    fr: {
      resume: "Repérez les produits à réapprovisionner : pour chaque produit actif, le stock actuel, la consommation moyenne par jour, les jours de stock restants et une quantité suggérée.",
      etapes: [
        "Lisez les cartes « Total produits », « Stock critique », « Stock bas » et « À commander ».",
        "Filtrez la liste avec « Tous », « Critique », « Stock bas » ou « OK » ; les produits critiques viennent en premier.",
        "Pour chaque produit, comparez le « Stock actuel » au « Stock min » et lisez les « Jours restants » (en rouge à 7 jours ou moins).",
        "Reprenez la quantité de la colonne « Suggestion » pour passer vos commandes dans « Commandes » (menu Achats) ou « Suggestions d'achat ».",
      ],
      astuces: [
        "« Conso/jour » est la moyenne des sorties de stock des 30 derniers jours ; les « Jours restants » sont le stock actuel divisé par cette consommation.",
        "Avec un stock minimum, un produit est critique à la moitié du minimum ou moins, bas jusqu'au minimum ; sans minimum, critique à 7 jours restants ou moins, bas jusqu'à 14 jours.",
        "Le stock minimum se règle dans « Alertes Stock » (bouton « Configurer seuils ») ; la quantité suggérée ramène le stock au double de ce minimum.",
        "Cet écran ne passe aucune commande : il sert à repérer les produits à réapprovisionner.",
      ],
      motsCles: ["prévisions de stock", "rupture de stock", "réapprovisionnement", "stock minimum", "consommation moyenne", "jours de stock"],
    },
    en: {
      resume: "Spot the products to restock: for each active product, the current stock, average daily usage, days of stock left and a suggested quantity.",
      etapes: [
        "Read the “Total produits”, “Stock critique”, “Stock bas” and “À commander” cards.",
        "Filter the list with “Tous”, “Critique”, “Stock bas” or “OK”; critical products come first.",
        "For each product, compare “Stock actuel” with “Stock min” and read “Jours restants” (in red at 7 days or less).",
        "Use the quantity in the “Suggestion” column to place your orders in “Commandes” (Achats menu) or “Purchase suggestions”.",
      ],
      astuces: [
        "“Conso/jour” is the average stock outflow over the last 30 days; “Jours restants” is the current stock divided by that usage.",
        "With a minimum stock, a product is critical at half the minimum or less and low up to the minimum; without one, critical at 7 days left or less, low up to 14 days.",
        "The minimum stock is set in “Stock Alerts” (“Configurer seuils” button); the suggested quantity brings stock back to twice that minimum.",
        "This screen places no order: it helps you spot the products to restock.",
      ],
      motsCles: ["stock forecast", "stock-out", "replenishment", "minimum stock", "days of stock", "reorder"],
    },
  },

  "/gestion-prix": {
    fr: {
      resume: "Les prix de vente : prix de base, listes de prix (grossiste, détaillant…), règles de prix (prix négociés, paliers, promotions) et simulateur du prix d'un client.",
      etapes: [
        "Onglet « Grille de prix » : modifiez le prix de base et les prix par liste directement dans le tableau, puis cliquez sur « Enregistrer ».",
        "Onglet « Listes de prix » : cliquez sur « Nouvelle liste », donnez un nom et un ajustement en % sur le prix de base ; dépliez une liste pour rattacher des clients.",
        "Onglet « Règles de prix » : cliquez sur « Nouvelle règle », puis choisissez la portée (client, liste, produit, catégorie), les conditions et l'effet.",
        "Onglet « Simulateur » : choisissez un client, un produit, une quantité et une date, puis cliquez sur « Calculer » pour voir le prix retenu.",
      ],
      astuces: [
        "Le prix d'un client est le plus bas entre le prix de sa liste, sa remise globale et les règles qui s'appliquent : une règle ne peut donc pas vendre plus cher que la liste.",
        "Dans la grille, un champ de liste vide suit la formule de la liste (valeur grisée) ; une valeur saisie la remplace, et la vider rend le produit à la formule.",
        "Un client est rattaché à une seule liste ; sans liste, ou si elle est inactive, il paie le prix de base.",
        "Les devis, commandes et factures proposent ce prix sur chaque ligne ; il reste modifiable.",
      ],
      motsCles: ["liste de prix", "tarifs", "remises", "prix de vente", "promotions", "prix client"],
    },
    en: {
      resume: "Selling prices: base price, price lists (wholesale, retail…), price rules (negotiated prices, quantity tiers, promotions) and a simulator of a customer's price.",
      etapes: [
        "“Price grid” tab: change the base price and the list prices directly in the table, then click “Save”.",
        "“Price lists” tab: click “New list”, give it a name and an adjustment in % on the base price; expand a list to attach customers.",
        "“Price rules” tab: click “New rule”, then choose the scope (customer, list, product, category), the conditions and the effect.",
        "“Simulator” tab: choose a customer, a product, a quantity and a date, then click “Compute” to see the selected price.",
      ],
      astuces: [
        "A customer's price is the lowest of their list price, their global discount and the rules that apply: a rule therefore cannot sell above the list price.",
        "In the grid, an empty list field follows the list formula (greyed value); a typed value replaces it, and clearing it returns the product to the formula.",
        "A customer is attached to one list at most; with no list, or an inactive one, they pay the base price.",
        "Quotes, orders and invoices propose this price on each line; it can still be changed.",
      ],
      motsCles: ["price list", "pricing", "discounts", "selling price", "promotions", "customer price"],
    },
  },

  "/soldes-ouverture": {
    fr: {
      resume: "Les soldes des comptes de bilan au premier jour du premier exercice, quand la comptabilité reprend une activité existante ; on y accède depuis « Exercices comptables ».",
      etapes: [
        "Ouvrez « Exercices comptables » et cliquez sur le bouton « Soldes d'ouverture » (le premier exercice doit déjà exister).",
        "Pour chaque compte, saisissez son solde au « Débit » ou au « Crédit » ; « Rechercher un compte… » filtre la liste.",
        "Suivez le bandeau des totaux jusqu'au message « Les soldes sont équilibrés. »",
        "Cliquez sur « Enregistrer » : le bouton reste grisé tant que les débits n'égalent pas les crédits.",
      ],
      astuces: [
        "Seuls les comptes de bilan (actif, passif, capitaux propres) figurent, sans les en-têtes ni les comptes désactivés ; les exercices suivants reprennent les soldes de clôture.",
        "Les soldes forment une seule écriture du journal des à-nouveaux, datée du premier jour ; chaque enregistrement la remplace, des soldes tous nuls la suppriment.",
        "Une fois la première période de l'exercice fermée, les soldes ne changent plus.",
        "La saisie demande la permission « Saisir des écritures ».",
      ],
      motsCles: ["soldes d'ouverture", "à-nouveaux", "reprise des soldes", "bilan d'ouverture", "reprise de comptabilité", "opening balances"],
    },
    en: {
      resume: "Balances of the balance-sheet accounts on the first day of the first fiscal year, when the bookkeeping takes over an existing business; reached from “Fiscal years”.",
      etapes: [
        "Open “Fiscal years” and click the “Opening balances” button (the first fiscal year must already exist).",
        "For each account, enter its balance as a “Debit” or a “Credit”; “Search an account…” filters the list.",
        "Watch the totals banner until it says “The balances are balanced.”",
        "Click “Save”: the button stays greyed out until debits equal credits.",
      ],
      astuces: [
        "Only balance-sheet accounts (asset, liability, equity) are listed, without headers or deactivated accounts; later fiscal years carry over the closing balances.",
        "The balances form a single entry in the opening journal, dated the first day; each save replaces it, and all-zero balances delete it.",
        "Once the first period of the fiscal year is closed, the balances can no longer change.",
        "Entering the balances needs the “Post journal entries” permission.",
      ],
      motsCles: ["opening balances", "opening entry", "starting balances", "balances brought forward", "opening balance sheet", "takeover of books"],
    },
  },
};
