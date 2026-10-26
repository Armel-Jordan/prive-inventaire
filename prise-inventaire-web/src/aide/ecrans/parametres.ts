import type { AidesEcrans } from '../types';

/** Aide des écrans de la catégorie Paramètres et des écrans généraux (phase 6.6). */
export const AIDES_PARAMETRES: AidesEcrans = {
  "/": {
    fr: {
      resume: "Vue d'ensemble de l'activité du mois en cours : ventes, achats, finance et inventaire, avec les actions qui demandent votre attention. Chaque carte mène à l'écran détaillé.",
      etapes: [
        "Parcourez les sections « Ventes », « Achats », « Finance » et « Inventaire » : seules celles des modules de votre entreprise s'affichent.",
        "Cliquez sur une carte (« CA du mois », « Factures impayées », « Alertes stock »…) pour ouvrir l'écran correspondant.",
        "Traitez les « Actions requises » du bandeau orange : alertes, notifications, approbations et transferts planifiés des 7 prochains jours.",
        "Laissez « Auto » pour actualiser les chiffres toutes les 30 secondes, ou passez en « Manuel » et utilisez le bouton d'actualisation.",
      ],
      astuces: [
        "Les montants sont arrondis à l'unité, dans la devise de l'entreprise réglée dans « Configuration » (onglet « Devise & TVA »).",
        "« Alertes stock » et « Ruptures » ne comptent que les produits qui ont un seuil d'alerte (écran « Alertes Stock »).",
        "Administrateurs : tant que des réglages obligatoires manquent, un rappel en haut de l'écran mène à la « Configuration à compléter ».",
        "Ctrl + K ouvre la recherche globale de l'en-tête : écrans, produits, clients, documents…",
      ],
      motsCles: ["accueil", "indicateurs", "chiffre d'affaires", "synthèse", "kpi"],
    },
    en: {
      resume: "Overview of the current month's activity: sales, purchasing, finance and inventory, with the actions that need your attention. Each card opens the detailed screen.",
      etapes: [
        "Browse the “Ventes”, “Achats”, “Finance” and “Inventaire” sections: only those of your company's modules are shown.",
        "Click a card (“CA du mois”, “Factures impayées”, “Alertes stock”…) to open the matching screen.",
        "Handle the “Actions requises” in the orange banner: alerts, notifications, approvals and transfers planned for the next 7 days.",
        "Leave “Auto” on to refresh the figures every 30 seconds, or switch to “Manuel” and use the refresh button.",
      ],
      astuces: [
        "Amounts are rounded to the unit, in the company currency set in “Configuration” (“Devise & TVA” tab).",
        "“Alertes stock” and “Ruptures” only count products that have an alert threshold (“Stock Alerts” screen).",
        "Administrators: while required settings are missing, a reminder at the top of the screen leads to the “Setup checklist”.",
        "Ctrl + K opens the global search in the header: screens, products, customers, documents…",
      ],
      motsCles: ["home", "indicators", "revenue", "overview", "kpi"],
    },
  },

  "/notifications": {
    fr: {
      resume: "Les 100 dernières notifications de l'entreprise : alertes de stock, transferts, approbations, réponses du support… La cloche de l'en-tête n'en montre que les dix plus récentes.",
      etapes: [
        "Cochez « Non lues seulement » pour ne voir que ce qui reste à traiter.",
        "Cliquez sur « Ouvrir » pour aller à l'écran concerné : la notification est alors marquée lue.",
        "Cliquez sur « Marquer comme lue » (coche) d'une notification, ou sur « Tout marquer lu » pour toutes.",
        "Cliquez sur la corbeille « Supprimer » pour retirer une notification devenue inutile.",
      ],
      astuces: [
        "Les notifications sont communes à toute l'entreprise : une notification lue ou supprimée l'est pour tous les comptes.",
        "Une même alerte n'est pas répétée tant que sa notification n'est pas lue : marquez-la lue pour être prévenu de nouveau.",
        "Les alertes envoyées ici se règlent dans « Réglage des alertes ».",
        "Si elle est active, la tâche planifiée « Nettoyage des notifications » supprime les notifications lues depuis plus de 30 jours.",
      ],
      motsCles: ["alertes", "cloche", "messages", "non lues", "avertissements"],
    },
    en: {
      resume: "The company's 100 latest notifications: stock alerts, transfers, approvals, support answers… The bell in the header only shows the ten most recent.",
      etapes: [
        "Tick “Unread only” to see only what is left to handle.",
        "Click “Open” to go to the related screen: the notification is then marked as read.",
        "Click “Mark as read” (tick) on a notification, or “Mark all as read” for all of them.",
        "Click the “Delete” bin icon to remove a notification you no longer need.",
      ],
      astuces: [
        "Notifications are shared by the whole company: a notification read or deleted is read or deleted for every account.",
        "The same alert is not repeated while its notification is unread: mark it as read to be warned again.",
        "The alerts sent here are set in “Alert settings”.",
        "When active, the “Notification cleanup” scheduled task deletes the notifications read more than 30 days ago.",
      ],
      motsCles: ["alerts", "bell", "messages", "unread", "warnings"],
    },
  },

  "/support": {
    fr: {
      resume: "Écrivez à l'éditeur du logiciel : une demande d'aide (un problème, une question) ou une suggestion d'amélioration, puis suivez ses réponses et l'avancement de vos demandes.",
      etapes: [
        "Cliquez sur « Nouvelle demande », choisissez « Demande d'aide » ou « Suggestion » et saisissez le sujet.",
        "Décrivez ce que vous faisiez, ce que vous attendiez et ce qui s'est passé ; pour une demande d'aide, choisissez la priorité.",
        "Cliquez sur « Envoyer » : la demande apparaît dans la liste avec son statut.",
        "Cliquez sur une demande pour lire l'échange, répondez dans « Votre réponse », puis cliquez sur « Envoyer ».",
        "Une fois le problème réglé, cliquez sur « Fermer la demande ».",
      ],
      astuces: [
        "Ouverte depuis la bouée de l'en-tête, une nouvelle demande retient l'écran d'où elle part : ouvrez-la depuis l'écran qui pose problème.",
        "« Votre réponse est attendue » signale une demande où l'éditeur attend votre réponse ; ses réponses arrivent aussi dans les notifications.",
        "Répondre à une demande résolue ou fermée la rouvre.",
        "Vous voyez vos propres demandes ; le droit de consulter le module « Support et suggestions » donne accès à toutes celles de l'entreprise.",
      ],
      motsCles: ["aide", "assistance", "suggestion", "ticket", "problème", "éditeur"],
    },
    en: {
      resume: "Write to the software publisher: a request for help (a problem, a question) or an improvement suggestion, then follow their answers and the progress of your requests.",
      etapes: [
        "Click “New request”, choose “Help request” or “Suggestion” and enter the subject.",
        "Describe what you were doing, what you expected and what happened; for a help request, choose the priority.",
        "Click “Send”: the request appears in the list with its status.",
        "Click a request to read the exchange, reply in “Your answer”, then click “Send”.",
        "Once the issue is solved, click “Close the request”.",
      ],
      astuces: [
        "Opened from the life-buoy button in the header, a new request records the screen it comes from: open it from the screen where the problem occurs.",
        "“Waiting for your answer” flags a request where the publisher expects your reply; their answers also arrive in the notifications.",
        "Replying to a resolved or closed request reopens it.",
        "You see your own requests; the right to view the “Support and suggestions” module gives access to all of the company's requests.",
      ],
      motsCles: ["help", "assistance", "suggestion", "ticket", "issue", "publisher"],
    },
  },

  "/securite": {
    fr: {
      resume: "Protégez votre compte : changez votre mot de passe et activez la double authentification. Les administrateurs peuvent aussi la désactiver pour un utilisateur qui a perdu son téléphone.",
      etapes: [
        "Pour changer de mot de passe, saisissez l'actuel, le nouveau (8 caractères au moins) et sa confirmation, puis « Changer le mot de passe ».",
        "Pour la double authentification, cliquez sur « Activer », confirmez avec votre mot de passe, puis « Continuer ».",
        "Scannez le QR code avec une application d'authentification (ou saisissez la clé affichée), tapez le code à 6 chiffres, puis « Confirmer l'activation ».",
        "Notez les codes de secours affichés (« Copier les codes ») : ils ne seront plus montrés. Cliquez ensuite sur « J'ai noté mes codes ».",
        "« Générer de nouveaux codes » remplace les anciens ; « Désactiver » retire la double authentification (mot de passe exigé).",
      ],
      astuces: [
        "Changer de mot de passe ferme vos autres sessions : autres navigateurs et application mobile.",
        "Chaque code de secours permet une seule connexion sans téléphone.",
        "Mettez à jour l'application mobile avant d'activer la double authentification : une ancienne version ne permet plus de s'y connecter.",
        "Téléphone perdu : un administrateur désactive votre double authentification dans « Double authentification des utilisateurs » ; reconnectez-vous, puis réactivez-la.",
      ],
      motsCles: ["mot de passe", "double authentification", "2fa", "codes de secours", "connexion", "sécurité"],
    },
    en: {
      resume: "Protect your account: change your password and turn on two-factor authentication. Administrators can also turn it off for a user who lost their phone.",
      etapes: [
        "To change your password, enter the current one, the new one (at least 8 characters) and its confirmation, then click “Change password”.",
        "For two-factor authentication, click “Enable”, confirm with your password, then click “Continue”.",
        "Scan the QR code with an authenticator app (or enter the key shown), type the 6-digit code, then click “Confirm activation”.",
        "Save the recovery codes shown (“Copy the codes”): they will not be shown again. Then click “I have saved my codes”.",
        "“Generate new codes” replaces the old ones; “Disable” turns two-factor authentication off (password required).",
      ],
      astuces: [
        "Changing your password closes your other sessions: other browsers and the mobile app.",
        "Each recovery code allows a single sign-in without your phone.",
        "Update the mobile app before enabling two-factor authentication: an older version can no longer sign in.",
        "Lost phone: an administrator disables your two-factor authentication in “Users' two-factor authentication”; sign in again, then enable it again.",
      ],
      motsCles: ["password", "two-factor", "2fa", "recovery codes", "sign-in", "security"],
    },
  },

  "/profil": {
    fr: {
      resume: "Vos informations personnelles et professionnelles : photo, téléphone, adresse, poste et département. Elles complètent votre fiche dans « Fiches Employés ».",
      etapes: [
        "Cliquez sur l'appareil photo pour choisir une image (JPG, PNG ou GIF, 2 Mo au plus), puis sur « Enregistrer la photo ».",
        "Complétez vos informations personnelles : sexe, date de naissance et téléphone.",
        "Renseignez votre adresse (rue, ville, code postal, pays), puis votre poste et votre département.",
        "Cliquez sur « Enregistrer les modifications ».",
      ],
      astuces: [
        "Votre numéro, votre nom et votre courriel ne se modifient pas ici : le nom et le courriel se corrigent dans « Employés ».",
        "La photo s'enregistre à part, avec son propre bouton, avant ou après les autres informations.",
        "Pour changer votre mot de passe ou activer la double authentification, ouvrez « Sécurité du compte » (bouclier de l'en-tête).",
      ],
      motsCles: ["mon compte", "photo", "coordonnées", "informations personnelles", "adresse", "téléphone"],
    },
    en: {
      resume: "Your personal and work details: photo, phone, address, job title and department. They complete your record in “Fiches Employés”.",
      etapes: [
        "Click the camera icon to choose a picture (JPG, PNG or GIF, 2 MB max), then click “Enregistrer la photo”.",
        "Fill in your personal details: gender, date of birth and phone.",
        "Enter your address (street, city, postal code, country), then your job title and department.",
        "Click “Enregistrer les modifications”.",
      ],
      astuces: [
        "Your number, name and email cannot be changed here: the name and email are corrected in “Employees”.",
        "The photo is saved separately, with its own button, before or after the other details.",
        "To change your password or turn on two-factor authentication, open “Account security” (shield button in the header).",
      ],
      motsCles: ["my account", "photo", "contact details", "personal information", "address", "phone"],
    },
  },

  "/pieces-jointes": {
    fr: {
      resume: "Tous les fichiers joints aux fiches et documents de l'entreprise (clients, produits, factures, commandes…) : retrouvez-les, ouvrez-les ou faites le ménage.",
      etapes: [
        "Tapez un nom de fichier, une description ou une catégorie dans la recherche, puis appuyez sur Entrée.",
        "Filtrez par type de fiche avec la liste « Toutes les fiches » (client, produit, facture…).",
        "Cliquez sur le nom d'un fichier pour l'ouvrir (PDF, image, texte) ou le télécharger (autres formats).",
        "Cliquez sur la corbeille pour supprimer définitivement une pièce jointe et son fichier.",
      ],
      astuces: [
        "Pour ajouter des fichiers, ouvrez la fiche ou le document concerné et cliquez sur son trombone « Pièces jointes » : glisser-déposer, sélection ou image collée (Ctrl + V).",
        "Jusqu'à 10 fichiers par envoi, avec une description et une catégorie (Contrats, Photos…) qui facilitent la recherche.",
        "La colonne « Rattachée à » indique la fiche ou le document de chaque pièce.",
        "Les pièces d'un module non activé pour votre entreprise (« Ventes », « Achats ») n'apparaissent pas.",
        "Vous ne voyez que les pièces des fiches que votre rôle permet de consulter ; ajouter, modifier ou supprimer une pièce demande le droit de modifier sa fiche.",
      ],
      motsCles: ["fichiers", "documents joints", "trombone", "télécharger", "joindre"],
    },
    en: {
      resume: "All the files attached to the company's records and documents (customers, products, invoices, orders…): find them, open them or clean up.",
      etapes: [
        "Type a file name, description or category in the search box, then press Enter.",
        "Filter by type of record with the “All records” list (customer, product, invoice…).",
        "Click a file name to open it (PDF, image, text) or download it (other formats).",
        "Click the bin icon to permanently delete an attachment and its file.",
      ],
      astuces: [
        "To add files, open the record or document and click its “Attachments” paperclip: drag and drop, pick files or paste an image (Ctrl + V).",
        "Up to 10 files per upload, with a description and a category (Contracts, Photos…) that make searching easier.",
        "The “Attached to” column shows the record or document of each file.",
        "Files of a module your company has not enabled (“Ventes”, “Achats”) are not listed.",
        "You only see the files of records your role lets you view; adding, editing or deleting a file requires the right to edit its record.",
      ],
      motsCles: ["files", "attached documents", "paperclip", "download", "attach"],
    },
  },

  "/produits": {
    fr: {
      resume: "Le catalogue des articles que vous achetez, stockez ou vendez : création, codes-barres, unités, kits, suivi par lot ou numéro de série, étiquettes, import et export.",
      etapes: [
        "Cliquez sur « Ajouter », saisissez la description, choisissez le secteur et l'unité de mesure, puis « Créer » (le numéro peut être généré automatiquement).",
        "Complétez au besoin le type, la « Fiche détaillée », le « Suivi des exemplaires », le conditionnement fournisseur et le « Transport ».",
        "Sur chaque ligne, les boutons ouvrent les « Codes-barres », les « Unités » de vente, « Ensemble, annexes, équivalents », les pièces jointes et « Suggérer l'achat ».",
        "Cherchez par numéro, description, référence fabricant ou code-barres ; filtrez par type, secteur, fabricant et statut.",
        "Imprimez les étiquettes d'un produit avec son bouton « Étiquettes », ou celles des produits affichés avec le bouton du haut.",
      ],
      astuces: [
        "La corbeille désactive le produit : il quitte les listes de choix et l'application mobile, son historique reste ; le filtre « Désactivés » permet de le réactiver.",
        "Le prix de base se saisit dans le champ « Prix de vente » du produit, ou pour plusieurs produits dans « Tarifs & Prix », onglet « Grille de prix ».",
        "Le suivi par lot ou par numéro de série ne change plus dès que des lots ou des numéros sont enregistrés.",
        "Pour importer, partez d'un fichier exporté (colonnes séparées par des points-virgules) : chaque ligne crée un produit, avec un code de secteur existant.",
      ],
      motsCles: ["articles", "catalogue", "références", "codes-barres", "kits", "nomenclature"],
    },
    en: {
      resume: "The catalog of the items you buy, stock or sell: creation, barcodes, units, kits, lot or serial number tracking, labels, import and export.",
      etapes: [
        "Click “Ajouter”, enter the description, choose the sector and the unit of measure, then click “Créer” (the number can be generated automatically).",
        "Complete as needed the type, the “Detailed record”, “Item tracking”, the supplier packaging and “Transport”.",
        "On each row, buttons open the “Barcodes”, the sales “Units”, “Kit, add-ons, equivalents”, the attachments and “Suggest a purchase”.",
        "Search by number, description, manufacturer reference or barcode; filter by type, sector, manufacturer and status.",
        "Print the labels of one product with its “Labels” button, or those of the products shown with the button at the top.",
      ],
      astuces: [
        "The bin icon deactivates the product: it leaves pick lists and the mobile app but keeps its history; the “Désactivés” filter lets you reactivate it.",
        "The base price is entered in the product’s “Prix de vente” field, or for several products in “Tarifs & Prix”, “Price grid” tab.",
        "Lot or serial number tracking can no longer change once lots or numbers are recorded.",
        "To import, start from an exported file (semicolon-separated columns): each line creates a product, with an existing sector code.",
      ],
      motsCles: ["items", "catalog", "sku", "barcodes", "kits", "bill of materials"],
    },
  },

  "/etiquettes": {
    fr: {
      resume: "Imprimez les étiquettes de vos produits en PDF, sur rouleau (imprimante d'étiquettes) ou sur planche, et gérez les formats : taille, disposition et contenu (code-barres, prix…).",
      etapes: [
        "Onglet « Imprimer » : choisissez le « Format » et, s'il affiche un prix, le « Prix affiché » et le « Groupe de taxes ».",
        "Ajoutez les produits : recherche par numéro, description ou code-barres (une douchette suivie d'Entrée convient), ou « Ajouter les produits d'un secteur ».",
        "Pour chaque produit, choisissez le « Code imprimé » et le nombre d'« Exemplaires ».",
        "Sur une planche déjà entamée, cliquez sur la « Première étiquette libre ».",
        "Cliquez sur « Imprimer » : le PDF s'ouvre dans un nouvel onglet.",
      ],
      astuces: [
        "Depuis « Produits », le bouton « Étiquettes » d'une ligne, ou celui du haut de la liste (200 produits au plus), arrive ici avec les produits déjà ajoutés.",
        "Au plus 2 000 étiquettes par impression.",
        "Avant d'utiliser vos planches, cochez « Tracer le contour des étiquettes » et faites un essai sur papier ordinaire.",
        "Onglet « Formats » : créer, modifier ou retirer un format (rouleau ou planche, taille, contenu) demande la permission « Gérer les formats d'étiquettes ».",
      ],
      motsCles: ["étiquettes produits", "impression", "code-barres", "planche", "rouleau", "pdf"],
    },
    en: {
      resume: "Print your product labels as a PDF, on a roll (label printer) or on a sheet, and manage the formats: size, layout and content (barcode, price…).",
      etapes: [
        "“Print” tab: choose the “Format” and, if it shows a price, the “Price shown” and the “Tax group”.",
        "Add the products: search by number, description or barcode (a scanner followed by Enter works), or use “Add the products of a sector”.",
        "For each product, choose the “Printed code” and the number of “Copies”.",
        "On a partly used sheet, click the “First free label”.",
        "Click “Print”: the PDF opens in a new tab.",
      ],
      astuces: [
        "From “Products”, a row's “Labels” button, or the one at the top of the list (200 products at most), brings you here with the products already added.",
        "At most 2,000 labels per print.",
        "Before using your sheets, tick “Draw label outlines” and run a test on plain paper.",
        "“Formats” tab: creating, changing or removing a format (roll or sheet, size, content) needs the “Manage label formats” permission.",
      ],
      motsCles: ["product labels", "printing", "barcode", "sheet", "roll", "pdf"],
    },
  },

  "/secteurs": {
    fr: {
      resume: "Les emplacements où vous rangez et comptez vos produits (allée, rayon, zone, dépôt…), chacun avec un QR code à imprimer et à scanner avec l'application mobile.",
      etapes: [
        "Cliquez sur « Ajouter », saisissez le code (ex. A1) et le nom, puis « Créer ».",
        "Si votre entreprise a des entrepôts, choisissez l'« Entrepôt » du secteur ; la liste se filtre aussi par entrepôt.",
        "Cliquez sur l'icône QR code d'un secteur, puis « Imprimer » pour coller l'étiquette sur place.",
        "Utilisez « Exporter » et « Importer » pour échanger la liste en fichier CSV.",
      ],
      astuces: [
        "Le QR code contient le code du secteur, que l'application mobile reconnaît au scan.",
        "Le code ne se modifie plus après la création ; sans numérotation automatique (« Configuration »), il se saisit à la main.",
        "La corbeille désactive le secteur : il quitte les listes de choix et l'application mobile, ses produits restent rattachés ; le filtre « Désactivés » permet de le réactiver.",
        "Les entrepôts se créent dans « Référentiels », liste « Entrepôts ».",
      ],
      motsCles: ["emplacements", "zones", "qr code", "allées", "rayons", "localisation"],
    },
    en: {
      resume: "The places where you store and count your products (aisle, shelf, zone, depot…), each with a QR code to print and scan with the mobile app.",
      etapes: [
        "Click “Ajouter”, enter the code (e.g. A1) and the name, then click “Créer”.",
        "If your company has warehouses, choose the sector's “Warehouse”; the list can also be filtered by warehouse.",
        "Click a sector's QR code icon, then “Imprimer” to stick the label on site.",
        "Use “Exporter” and “Importer” to exchange the list as a CSV file.",
      ],
      astuces: [
        "The QR code holds the sector code, which the mobile app recognizes when scanning.",
        "The code cannot change after creation; without automatic numbering (“Configuration”), you type it yourself.",
        "The bin icon deactivates the sector: it leaves pick lists and the mobile app, its products stay attached; the “Désactivés” filter lets you reactivate it.",
        "Warehouses are created in “Reference data”, “Warehouses” list.",
      ],
      motsCles: ["locations", "zones", "qr code", "aisles", "bins", "storage"],
    },
  },

  "/configuration-a-completer": {
    fr: {
      resume: "Le parcours de mise en route : les réglages nécessaires pour bien démarrer, vérifiés dans vos données. Chaque ligne se coche d'elle-même dès que c'est fait.",
      etapes: [
        "Suivez les étapes de gauche ; l'écran s'ouvre sur la première où il reste quelque chose à faire.",
        "Pour chaque réglage « Obligatoire » ou « Recommandé », cliquez sur « Ouvrir » pour aller à l'écran où il se fait.",
        "Revenez et cliquez sur « Actualiser » : la ligne se coche dès que la donnée existe.",
        "Passez d'une étape à l'autre avec « Étape précédente » et « Étape suivante ».",
        "Ouvrez « Qualité des données » pour repérer les fiches incomplètes, puis « Voir » pour les corriger.",
      ],
      astuces: [
        "Rien ne se coche à la main : une ligne peut aussi se décocher si la donnée disparaît.",
        "Administrateurs : « Ignorer » écarte un réglage recommandé qui ne vous concerne pas, avec sa raison ; « Reprendre » le remet. Un réglage obligatoire ne s'ignore pas.",
        "Les réglages suivent les modules de votre entreprise : sans le module « Achats », rien sur les fournisseurs.",
        "Tant qu'il reste des réglages obligatoires, les administrateurs voient un rappel en haut du tableau de bord.",
      ],
      motsCles: ["mise en route", "démarrage", "premiers pas", "paramétrage initial", "qualité des données"],
    },
    en: {
      resume: "The getting-started path: the settings needed to start well, checked in your data. Each line ticks itself as soon as it is done.",
      etapes: [
        "Follow the steps on the left; the screen opens on the first one with something left to do.",
        "For each “Required” or “Recommended” setting, click “Open” to go to the screen where it is done.",
        "Come back and click “Refresh”: the line ticks itself as soon as the data exists.",
        "Move between steps with “Previous step” and “Next step”.",
        "Open “Data quality” to spot incomplete records, then click “See” to fix them.",
      ],
      astuces: [
        "Nothing is ticked by hand: a line can also untick itself if the data disappears.",
        "Administrators: “Ignore” sets aside a recommended setting that does not apply to you, with a reason; “Resume” brings it back. A required setting cannot be ignored.",
        "Settings follow your company's modules: without the “Achats” module, nothing about suppliers.",
        "While required settings remain, administrators see a reminder at the top of the dashboard.",
      ],
      motsCles: ["onboarding", "getting started", "first steps", "initial setup", "data quality"],
    },
  },

  "/employes": {
    fr: {
      resume: "Les personnes de l'entreprise et leur accès à l'application : création, rôle, mot de passe, droits détaillés, désactivation, import et export.",
      etapes: [
        "Cliquez sur « Ajouter », saisissez le nom, le prénom et le courriel ; le numéro peut être généré automatiquement.",
        "Pour donner l'accès, choisissez un « Rôle » dans « Accès à l'application » et un mot de passe (8 caractères au moins) : le courriel sert d'identifiant.",
        "Cliquez sur « Créer », puis communiquez à l'employé son courriel et son mot de passe.",
        "Administrateurs : réglez ses groupes et ses exceptions avec la clé « Accès et permissions » de sa ligne.",
        "Pour retirer l'accès, modifiez l'employé et choisissez « Supprimer l'accès » ; pour un départ, utilisez la corbeille « Désactiver ».",
      ],
      astuces: [
        "Désactiver un employé coupe aussi son accès et ferme ses sessions ouvertes ; le filtre « Désactivés » permet de le réactiver.",
        "Un manager n'attribue que les rôles « Lecture seule » et « Utilisateur », sans rôle personnalisé, et ne touche pas aux comptes administrateur ou manager.",
        "L'entreprise garde toujours un administrateur actif : le dernier ne peut être ni désactivé ni rétrogradé.",
        "L'import crée des employés sans accès à l'application : partez d'un fichier exporté (colonnes séparées par des points-virgules).",
      ],
      motsCles: ["utilisateurs", "personnel", "comptes", "accès", "rôles", "identifiants"],
    },
    en: {
      resume: "The company's people and their access to the application: creation, role, password, detailed rights, deactivation, import and export.",
      etapes: [
        "Click “Ajouter”, enter the last name, first name and email; the number can be generated automatically.",
        "To grant access, choose a “Rôle” under “Accès à l'application” and a password (at least 8 characters): the email is the login.",
        "Click “Créer”, then give the employee their email and password.",
        "Administrators: set their groups and exceptions with the “Access and permissions” key on their row.",
        "To remove access, edit the employee and choose “Supprimer l'accès”; when someone leaves, use the “Désactiver” bin icon.",
      ],
      astuces: [
        "Deactivating an employee also cuts their access and closes their open sessions; the “Désactivés” filter lets you reactivate them.",
        "A manager only assigns the “Lecture seule” and “Utilisateur” roles, without a custom role, and cannot change administrator or manager accounts.",
        "The company always keeps one active administrator: the last one can be neither deactivated nor downgraded.",
        "Import creates employees without access to the application: start from an exported file (semicolon-separated columns).",
      ],
      motsCles: ["users", "staff", "accounts", "access", "roles", "login"],
    },
  },

  "/acces/:compteId": {
    fr: {
      resume: "Les droits d'un compte en détail : son rôle principal, ses groupes supplémentaires et ses exceptions, permission par permission. Réservé aux administrateurs.",
      etapes: [
        "Ouvrez l'écran depuis « Employés », avec la clé « Accès et permissions » d'un employé qui a un accès.",
        "Cochez les « Groupes supplémentaires » dont le compte reçoit aussi les droits.",
        "Dans les permissions, cliquez sur la case de droite pour passer de « Hérité » à « Accordé », puis « Refusé », puis de nouveau « Hérité ».",
        "Cherchez une permission, ou choisissez « Exceptions seulement » dans « Afficher » pour vérifier vos réglages.",
        "Cliquez sur « Enregistrer ».",
      ],
      astuces: [
        "Les droits des groupes s'additionnent ; une exception « Refusé » l'emporte sur tous les groupes.",
        "La colonne de gauche montre ce que donnent les groupes ; le rôle principal se règle dans la fiche de l'employé.",
        "« Retirer les exceptions » ramène le compte aux droits de ses groupes ; « Tout accorder » accorde chaque permission en exception.",
        "Un administrateur a tous les droits, quels que soient ses groupes : il n'y a rien à régler.",
      ],
      motsCles: ["permissions", "droits", "exceptions", "groupes", "accès d'un utilisateur"],
    },
    en: {
      resume: "An account's rights in detail: its main role, extra groups and exceptions, permission by permission. Administrators only.",
      etapes: [
        "Open this screen from “Employees”, with the “Access and permissions” key of an employee who has access.",
        "Tick the “Extra groups” whose rights the account also receives.",
        "In the permissions, click the right-hand box to go from “Inherited” to “Granted”, then “Refused”, then back to “Inherited”.",
        "Search for a permission, or pick “Exceptions only” in “Show” to check your settings.",
        "Click “Save”.",
      ],
      astuces: [
        "Group rights add up; a “Refused” exception wins over every group.",
        "The left column shows what the groups grant; the main role is set in the employee's record.",
        "“Remove exceptions” brings the account back to its groups' rights; “Grant all” grants every permission as an exception.",
        "An administrator has every right, whatever their groups: there is nothing to set.",
      ],
      motsCles: ["permissions", "rights", "exceptions", "groups", "user access"],
    },
  },

  "/taches-planifiees": {
    fr: {
      resume: "Les traitements automatiques de l'application (alertes, relances, facturation récurrente, exécution des transferts…) : activez ceux qui vous servent et réglez leur fréquence.",
      etapes: [
        "Activez une tâche avec son interrupteur « Active » : toutes sont inactives au départ.",
        "Cliquez sur « Régler » pour choisir la « Récurrence » (toutes les heures, chaque jour, chaque semaine, chaque mois ou une seule fois) et l'heure.",
        "Réglez au besoin le délai en jours ou cochez « Émettre les factures », puis cliquez sur « Enregistrer ».",
        "Cliquez sur « Exécuter maintenant » pour lancer une tâche tout de suite, sans décaler sa prochaine exécution.",
        "Consultez l'« Historique » : déclenchement, durée, résultat et compte rendu de chaque exécution, filtrable par tâche.",
      ],
      astuces: [
        "Les heures sont celles du fuseau indiqué à l'écran ; un mois plus court passe au dernier jour, et une tâche « Une seule fois » se désactive après son exécution.",
        "Sans planificateur installé sur le serveur, les tâches tournent au fil de l'activité de l'application : le bandeau du haut montre la dernière vérification.",
        "Les alertes se règlent plus simplement dans « Réglage des alertes ».",
        "Réservé aux administrateurs et aux comptes qui ont les droits « Tâches planifiées » (Rôles et permissions) : consulter, régler, lancer tout de suite.",
      ],
      motsCles: ["automatisation", "planificateur", "tâches automatiques", "récurrence", "relances automatiques", "historique"],
    },
    en: {
      resume: "The application's automatic processes (alerts, reminders, recurring invoicing, running planned transfers…): turn on the ones you need and set how often they run.",
      etapes: [
        "Turn a task on with its “Active” switch: all of them start inactive.",
        "Click “Settings” to choose the “Recurrence” (every hour, day, week or month, or only once) and the time.",
        "Set the delay in days or tick “Issue the invoices” if offered, then click “Save”.",
        "Click “Run now” to start a task right away, without moving its next run.",
        "Check the “History”: trigger, duration, result and report of each run, filterable by task.",
      ],
      astuces: [
        "Times are in the time zone shown on screen; a shorter month moves to its last day, and an “Only once” task turns itself off after running.",
        "Without a scheduler installed on the server, tasks run along with application activity: the top banner shows the last check.",
        "Alerts are easier to set in “Alert settings”.",
        "For administrators and accounts with the “Scheduled tasks” rights (Roles and permissions): view, adjust, run now.",
      ],
      motsCles: ["automation", "scheduler", "automatic tasks", "recurrence", "automatic reminders", "history"],
    },
  },

  "/alertes-config": {
    fr: {
      resume: "Les notifications que l'application envoie quand une situation demande votre attention : stock bas, factures impayées, retards, contrôles techniques, péremptions… Activez-les et réglez leur délai.",
      etapes: [
        "Activez une alerte avec son interrupteur, en haut à droite de sa carte.",
        "Si l'alerte a un délai, réglez-le en jours (0 à 365), puis cliquez sur « Enregistrer » ou appuyez sur Entrée.",
        "Cliquez sur « Vérifier maintenant » pour lancer la vérification sans attendre.",
        "Suivez la fréquence et la « Dernière vérification » de chaque alerte, avec son résultat.",
      ],
      astuces: [
        "Les alertes arrivent dans les notifications (cloche de l'en-tête) ; une alerte n'est renvoyée qu'une fois la précédente lue.",
        "Seules les alertes des modules de votre entreprise sont proposées.",
        "Fréquence, heure et historique : suivez le lien vers les tâches planifiées, en bas de l'écran.",
        "Réservé aux administrateurs.",
      ],
      motsCles: ["alertes", "notifications", "rappels", "retards", "seuils", "péremption"],
    },
    en: {
      resume: "The notifications the application sends when a situation needs your attention: low stock, unpaid invoices, delays, truck inspections, lot expiry… Turn them on and set their delay.",
      etapes: [
        "Turn an alert on with its switch, at the top right of its card.",
        "If the alert has a delay, set it in days (0 to 365), then click “Save” or press Enter.",
        "Click “Check now” to run the check without waiting.",
        "Follow the frequency and “Last check” of each alert, with its result.",
      ],
      astuces: [
        "Alerts arrive in the notifications (bell in the header); an alert is only sent again once the previous one is read.",
        "Only the alerts of your company's modules are offered.",
        "Frequency, time and history: follow the link to the scheduled tasks at the bottom of the screen.",
        "Administrators only.",
      ],
      motsCles: ["alerts", "notifications", "reminders", "overdue", "thresholds", "expiry"],
    },
  },

  "/fiches-employes": {
    fr: {
      resume: "L'annuaire des employés de l'entreprise : photo, poste, département, coordonnées et rôle d'accès de chacun, présentés en fiches.",
      etapes: [
        "Tapez un nom, un numéro, un courriel, un poste ou un département dans « Rechercher... ».",
        "Cliquez sur une fiche pour afficher le détail : poste, coordonnées, adresse, date de naissance.",
        "Pour changer la photo, cliquez sur l'appareil photo de la fenêtre de détail (« Changer la photo ») et choisissez une image.",
        "Cliquez sur « Fermer » pour revenir à la liste.",
      ],
      astuces: [
        "Chaque employé complète ses informations dans « Mon profil » (bouton de l'en-tête) ; « Profil non complété » signale une fiche sans poste, ni téléphone, ni courriel.",
        "Le nom, le courriel et l'accès à l'application se gèrent dans « Employés ».",
        "Photo : JPG, PNG ou GIF, 2 Mo au plus ; la changer demande le droit de modifier les employés.",
      ],
      motsCles: ["annuaire", "trombinoscope", "profils", "rh", "photos", "personnel"],
    },
    en: {
      resume: "The company's staff directory: each employee's photo, job title, department, contact details and access role, shown as cards.",
      etapes: [
        "Type a name, number, email, job title or department in “Rechercher...”.",
        "Click a card to show the details: job title, contact details, address, date of birth.",
        "To change the photo, click the camera icon in the details window (“Changer la photo”) and choose a picture.",
        "Click “Fermer” to go back to the list.",
      ],
      astuces: [
        "Each employee fills in their details in “Mon profil” (header button); “Profil non complété” flags a card with no job title, phone or email.",
        "Name, email and access to the application are managed in “Employees”.",
        "Photo: JPG, PNG or GIF, 2 MB max; changing it requires the right to edit employees.",
      ],
      motsCles: ["directory", "staff profiles", "hr", "photos", "personnel", "people"],
    },
  },

  "/balises": {
    fr: {
      resume: "Classez vos fiches et documents avec vos propres balises (Région, Priorité…) et gardez sous la main les remarques rapides, ces textes que vous réutilisez dans les notes.",
      etapes: [
        "Onglet « Balises » : cliquez sur « Nouvelle balise », donnez un nom, un type (« Un seul choix » ou « Plusieurs choix ») et les fiches où elle s'applique.",
        "Ajoutez ses items avec « Nouvel item », une couleur et « Ajouter » ; cliquez sur un item pour le renommer, le désactiver ou le supprimer.",
        "Posez les items depuis la liste de l'écran concerné, avec le bouton « Balises » sous le nom de chaque fiche.",
        "« Retrouver des fiches » : choisissez un type de fiche, cochez des items, puis « Au moins un des items » ou « Tous les items » et « Rechercher ».",
        "Onglet « Remarques rapides » : saisissez une catégorie (facultative) et le texte, puis cliquez sur « Nouvelle remarque ».",
      ],
      astuces: [
        "Dans les devis, commandes, factures et bons de livraison, le bouton « Remarque rapide » sous les notes insère une remarque d'un clic.",
        "Supprimer une balise ou un item retire aussi toutes ses poses ; une balise désactivée disparaît des fiches, mais ses poses sont gardées.",
        "Passer une balise à « Un seul choix » est refusé tant que des fiches en portent plusieurs items.",
      ],
      motsCles: ["classement", "catégories", "segmentation", "remarques", "textes types", "notes"],
    },
    en: {
      resume: "Classify your records and documents with your own tags (Region, Priority…) and keep quick remarks at hand: the texts you reuse in notes.",
      etapes: [
        "“Tags” tab: click “New tag”, give it a name, a type (“Single choice” or “Several choices”) and the records it applies to.",
        "Add its items with “New item”, a color and “Add”; click an item to rename, deactivate or delete it.",
        "Set the items from the list of the screen concerned, with the “Tags” button under each record's name.",
        "“Find records”: choose a type of record, tick items, then “At least one of the items” or “All the items” and “Search”.",
        "“Quick remarks” tab: enter a category (optional) and the text, then click “New remark”.",
      ],
      astuces: [
        "In quotes, orders, invoices and delivery notes, the “Quick remark” button under the notes inserts a remark in one click.",
        "Deleting a tag or an item also removes it everywhere it is set; a deactivated tag disappears from records but keeps its assignments.",
        "Switching a tag to “Single choice” is refused while records carry several of its items.",
      ],
      motsCles: ["classification", "categories", "segmentation", "remarks", "canned text", "notes"],
    },
  },

  "/roles": {
    fr: {
      resume: "Les groupes de permissions de l'entreprise, par écran et par action : qui peut consulter, créer, modifier, supprimer ou faire une opération précise. Réservé aux administrateurs.",
      etapes: [
        "Choisissez un groupe à gauche pour voir ses permissions ; les rôles système (cadenas) sont en lecture seule.",
        "Cliquez sur « Nouveau groupe », ou sur « Dupliquer » pour partir des droits d'un groupe existant.",
        "Saisissez le « Nom du groupe » et sa description, puis cochez les permissions de chaque onglet et écran (« Tous », « Tout cocher », « Tout décocher »).",
        "Cherchez une permission par mots, sans accents (ex. « facture émettre ») ; survolez-la pour lire son aide en bas de l'écran.",
        "Cliquez sur « Enregistrer », puis attribuez le groupe dans « Employés » (rôle personnalisé) ou dans l'accès d'un compte.",
      ],
      astuces: [
        "Un compte additionne les droits de son rôle principal et de ses groupes supplémentaires ; ses exceptions se règlent avec « Accès et permissions » dans « Employés ».",
        "Décocher « Groupe actif » retire tous les droits du groupe sans le retirer des comptes.",
        "Un groupe attribué à des comptes ne se supprime pas : désactivez-le.",
        "Un administrateur a toujours tous les droits.",
      ],
      motsCles: ["permissions", "droits", "groupes", "accès", "habilitations", "profils"],
    },
    en: {
      resume: "The company's permission groups, per screen and per action: who can view, create, edit, delete or perform a specific operation. Administrators only.",
      etapes: [
        "Choose a group on the left to see its permissions; system roles (padlock) are read-only.",
        "Click “New group”, or “Duplicate” to start from an existing group's rights.",
        "Enter the “Group name” and its description, then tick the permissions of each tab and screen (“All”, “Check all”, “Uncheck all”).",
        "Search for a permission by words, accents ignored (e.g. “invoice issue”); hover over it to read its help at the bottom.",
        "Click “Save”, then assign the group in “Employees” (custom role) or in an account's access.",
      ],
      astuces: [
        "An account adds up the rights of its main role and extra groups; its exceptions are set with “Access and permissions” in “Employees”.",
        "Unticking “Group active” removes all of the group's rights without removing it from accounts.",
        "A group assigned to accounts cannot be deleted: deactivate it.",
        "An administrator always has every right.",
      ],
      motsCles: ["permissions", "rights", "groups", "access", "user roles", "profiles"],
    },
  },

  "/fiches-en-cours": {
    fr: {
      resume: "Les fiches qu'un compte de l'entreprise est en train de modifier : qui les modifie et depuis quand. Une fiche ouverte en modification est verrouillée pour les autres comptes.",
      etapes: [
        "Consultez la liste : type et nom de chaque fiche, « Modifiée par » et « Depuis ».",
        "Cliquez sur le nom d'une fiche pour ouvrir son écran.",
        "Si une fiche reste bloquée (collègue absent, ordinateur en veille), cliquez sur « Libérer » et confirmez.",
        "Cliquez sur « Actualiser » ; la liste se met aussi à jour d'elle-même toutes les 30 secondes.",
      ],
      astuces: [
        "Après une libération, les changements que l'autre compte n'avait pas encore enregistrés sont refusés : prévenez-le si possible.",
        "Sans intervention, un verrou tombe de lui-même trois minutes après que l'écran a cessé de répondre (navigateur fermé, réseau coupé).",
        "Sont verrouillés : clients, fournisseurs, produits, devis, commandes client, commandes fournisseur et factures brouillons.",
        "« Libérer » demande la permission « Prendre la main sur une fiche en cours de modification ».",
      ],
      motsCles: ["verrous", "verrouillage", "fiche bloquée", "modification en cours", "libérer", "prendre la main"],
    },
    en: {
      resume: "The records an account of the company is currently editing: who is editing them and since when. A record opened for editing is locked for other accounts.",
      etapes: [
        "Read the list: type and name of each record, “Edited by” and “Since”.",
        "Click a record's name to open its screen.",
        "If a record stays blocked (colleague away, computer asleep), click “Release” and confirm.",
        "Click “Refresh”; the list also updates itself every 30 seconds.",
      ],
      astuces: [
        "After a release, the changes the other account had not saved yet are refused: warn them if you can.",
        "Without any action, a lock drops by itself three minutes after the screen stopped answering (browser closed, network down).",
        "Locked records: customers, suppliers, products, quotes, customer orders, purchase orders and draft invoices.",
        "“Release” needs the “Take over a record being edited” permission.",
      ],
      motsCles: ["locks", "locking", "locked record", "being edited", "release", "take over"],
    },
  },

  "/champs-personnalises": {
    fr: {
      resume: "Ajoutez vos propres champs aux fiches et aux documents (numéro de contrat, type de client, date promise…), puis remplissez-les fiche par fiche.",
      etapes: [
        "Choisissez l'onglet du type de fiche (Clients, Produits, Factures…), puis cliquez sur « Nouveau champ ».",
        "Saisissez le « Libellé » et choisissez le « Type » ; pour une liste, saisissez un choix par ligne.",
        "Réglez au besoin la « Valeur par défaut », l'« Aide », « Obligatoire » et « Sans doublon », puis cliquez sur « Enregistrer ».",
        "Ordonnez les champs avec les flèches « Monter » et « Descendre ».",
        "Remplissez-les depuis chaque fiche, avec le bouton « Champs personnalisés » en fin de ligne.",
      ],
      astuces: [
        "Le type d'un champ ne change plus après sa création.",
        "Supprimer un champ efface toutes ses valeurs : décochez plutôt « Actif » pour le masquer en les gardant.",
        "« Retrouver des fiches » liste les fiches dont un champ contient une valeur.",
        "Un champ « Obligatoire » est exigé dans la fenêtre des champs personnalisés, pas pour enregistrer la fiche elle-même.",
      ],
      motsCles: ["champs", "champs supplémentaires", "champs libres", "données personnalisées", "zones utilisateur"],
    },
    en: {
      resume: "Add your own fields to records and documents (contract number, customer type, promised date…), then fill them in record by record.",
      etapes: [
        "Choose the tab of the type of record (Customers, Products, Invoices…), then click “New field”.",
        "Enter the “Label” and choose the “Type”; for a list, type one choice per line.",
        "Set the “Default value”, “Help”, “Required” and “No duplicates” as needed, then click “Save”.",
        "Order the fields with the “Move up” and “Move down” arrows.",
        "Fill them in from each record, with the “Custom fields” button at the end of its row.",
      ],
      astuces: [
        "A field's type cannot change after it is created.",
        "Deleting a field erases all its values: untick “Active” instead to hide it and keep them.",
        "“Find records” lists the records whose field contains a value.",
        "A “Required” field is enforced in the custom fields window, not when saving the record itself.",
      ],
      motsCles: ["fields", "extra fields", "user-defined fields", "custom data", "additional fields"],
    },
  },

  "/configuration": {
    fr: {
      resume: "Les réglages généraux de l'entreprise : numérotation des documents, identité de l'entreprise, devise et taxes, délais par défaut, envoi des courriels et modèles.",
      etapes: [
        "Onglet « Entreprise » : saisissez le nom, l'adresse, le téléphone, le courriel et les numéros légaux, puis « Sauvegarder » ; ils figurent sur vos PDF.",
        "Onglet « Devise & TVA » : vérifiez le symbole et le code ISO de la devise, cliquez sur « Sauvegarder », puis ajoutez vos taxes et choisissez celle par défaut.",
        "Combinez au besoin plusieurs taxes dans « Groupes de taxes » (ex. deux taxes cumulées) et testez-les avec « Simuler ».",
        "Onglet « Numérotation » : choisissez un type de document, réglez préfixe, longueur, séparateur et prochain numéro, vérifiez l'« Aperçu », puis « Sauvegarder ».",
        "Onglet « Délais par défaut » : réglez le délai de paiement des factures et le délai de livraison des commandes fournisseur.",
        "Onglet « Courriels » : choisissez le serveur d'envoi, testez-le, puis personnalisez les modèles par type de document et par langue.",
      ],
      astuces: [
        "Les paramètres démarrent en euros avec une TVA de 20 % : enregistrez-les au moins une fois, même inchangés, pour les confirmer.",
        "Avec « Définir par défaut », un groupe de taxes s'applique à toutes les nouvelles commandes et factures ; une taxe utilisée dans un groupe ne se supprime pas.",
        "Changer le « Prochain numéro » peut laisser des trous dans la numérotation ; sans « Numérotation automatique », le numéro se saisit à la main.",
        "Seul un administrateur modifie ou teste le serveur d'envoi des courriels.",
      ],
      motsCles: ["paramètres", "numérotation", "entreprise", "devise", "taxes", "tva"],
    },
    en: {
      resume: "The company's general settings: document numbering, company identity, currency and taxes, default delays, email sending and templates.",
      etapes: [
        "“Entreprise” tab: enter the name, address, phone, email and legal numbers, then click “Sauvegarder”; they appear on your PDFs.",
        "“Devise & TVA” tab: check the currency symbol and ISO code, click “Sauvegarder”, then add your taxes and choose the default one.",
        "If needed, combine several taxes in “Tax groups” (e.g. two cumulative taxes) and test them with “Simulate”.",
        "“Numérotation” tab: choose a document type, set prefix, length, separator and next number, check the “Aperçu”, then click “Sauvegarder”.",
        "“Délais par défaut” tab: set the invoice payment delay and the purchase order delivery delay.",
        "“Courriels” tab: choose the sending server, test it, then customize the templates per document type and language.",
      ],
      astuces: [
        "Settings start in euros with 20% VAT: save them at least once, even unchanged, to confirm them.",
        "With “Set as default”, a tax group applies to all new orders and invoices; a tax used in a group cannot be deleted.",
        "Changing the “Prochain numéro” can leave gaps in the numbering; without “Numérotation automatique”, numbers are typed by hand.",
        "Only an administrator can change or test the email sending server.",
      ],
      motsCles: ["settings", "numbering", "company details", "currency", "taxes", "vat"],
    },
  },

  "/referentiels": {
    fr: {
      resume: "Les listes de référence partagées par toute l'application : unités, catégories, fabricants, modes et conditions de paiement, devises, entrepôts, transporteurs, raisons, statuts…",
      etapes: [
        "Choisissez une liste dans le menu de gauche, rangé par famille (Produits, Paiement et finance, Logistique, Commercial, Suivi des opérations).",
        "Cliquez sur « Ajouter », remplissez le formulaire (les champs marqués d'un astérisque sont obligatoires), puis « Créer ».",
        "Pour corriger un élément, cliquez sur le crayon, puis sur « Enregistrer ».",
        "Filtrez la liste affichée avec « Rechercher… ».",
      ],
      astuces: [
        "Transporteurs : « Livraison facturée » décochée, le frais de livraison du client n'est pas proposé sur ses documents expédiés par ce transporteur ; le « Frais de transport (achats) » est proposé sur les factures des fournisseurs qu'il livre.",
        "Transporteurs : « Transporteur obligatoire » (case au-dessus de la liste) exige un transporteur sur les devis, commandes, factures et commandes fournisseur saisis ; celui du client ou du fournisseur est proposé.",
        "Décochez « Actif » plutôt que de supprimer : l'historique reste, et la suppression, définitive, est refusée pour un élément utilisé.",
        "Devises : le taux est la valeur d'une unité dans votre devise de référence ; chaque nouveau taux est gardé dans l'« Historique des taux ».",
        "Un seul élément « Par défaut » pour les conditions de paiement, les comptes bancaires et les entrepôts.",
        "Les unités et leurs conversions servent aux unités de vente des produits ; les entrepôts regroupent les secteurs.",
      ],
      motsCles: ["listes de référence", "unités", "catégories", "devises", "modes de paiement", "entrepôts"],
    },
    en: {
      resume: "The reference lists shared across the application: units, categories, manufacturers, payment methods and terms, currencies, warehouses, carriers, reasons, statuses…",
      etapes: [
        "Pick a list in the left-hand menu, grouped by family (Products, Payment & finance, Logistics, Sales, Operations tracking).",
        "Click “Add”, fill in the form (fields marked with an asterisk are required), then click “Create”.",
        "To correct an item, click the pencil, then “Save”.",
        "Filter the list shown with “Search…”.",
      ],
      astuces: [
        "Carriers: with “Delivery billed” unticked, the customer's delivery fee is not proposed on documents shipped by this carrier; the “Freight (purchases)” fee is proposed on the invoices of the suppliers it delivers.",
        "Carriers: “Carrier required” (box above the list) requires a carrier on quotes, orders, invoices and purchase orders entered; the customer's or supplier's is proposed.",
        "Untick “Active” rather than deleting: history is kept, and deletion, which is final, is refused for an item in use.",
        "Currencies: the rate is the value of one unit in your reference currency; each new rate is kept in the “Rate history”.",
        "Only one “Default” item for payment terms, bank accounts and warehouses.",
        "Units and their conversions feed the products' sales units; warehouses group the sectors.",
      ],
      motsCles: ["reference lists", "units", "categories", "currencies", "payment methods", "warehouses"],
    },
  },

  "/frais": {
    fr: {
      resume: "Les frais que les devis, commandes et factures proposent d'eux-mêmes, comme une ligne de produit : manutention, emballage, matière dangereuse, livraison…",
      etapes: [
        "Créez d'abord dans « Produits » le produit qui portera la ligne du frais, de préférence un service.",
        "Cliquez sur « Nouveau frais », saisissez le « Libellé » et choisissez le « Produit de la ligne ».",
        "Choisissez le « Calcul » (montant fixe ou pourcentage du montant HT) et sa valeur.",
        "Choisissez où il s'applique : « Sur tout document », « Sur certains produits » (à cocher) ou « Frais de livraison (fiche client) ».",
        "Cochez les documents concernés (devis, commandes, factures), laissez « Actif » coché, puis cliquez sur « Enregistrer ».",
      ],
      astuces: [
        "Pendant la saisie d'un document, les frais applicables s'affichent sous les lignes ; un frais retiré ne revient pas pour ce document.",
        "Un frais de livraison se choisit ensuite dans la fiche de chaque client (champ « Frais de livraison »).",
        "« Sur certains produits » peut aussi viser toutes les matières dangereuses d'une case à cocher, sans les choisir une par une.",
        "Supprimer un frais le retire des clients qui l'avaient comme frais de livraison ; les documents déjà faits gardent leurs lignes.",
      ],
      motsCles: ["frais de livraison", "frais de port", "manutention", "emballage", "suppléments", "transport"],
    },
    en: {
      resume: "The fees that quotes, orders and invoices suggest on their own, as a product line: handling, packaging, hazardous goods, delivery…",
      etapes: [
        "First create, in “Products”, the product that will carry the fee line, preferably a service.",
        "Click “New fee”, enter the “Label” and choose the “Line product”.",
        "Choose the “Calculation” (fixed amount or percentage of the amount before tax) and its value.",
        "Choose where it applies: “On every document”, “On some products” (to tick) or “Delivery fee (customer record)”.",
        "Tick the documents concerned (quotes, orders, invoices), keep “Active” ticked, then click “Save”.",
      ],
      astuces: [
        "While entering a document, the applicable fees show under the lines; a fee you remove does not come back for that document.",
        "A delivery fee is then chosen in each customer's record (“Delivery fee” field).",
        "“On some products” can also target all dangerous goods with one checkbox, without choosing them one by one.",
        "Deleting a fee removes it from the customers who had it as their delivery fee; documents already made keep their lines.",
      ],
      motsCles: ["delivery fees", "shipping", "handling", "packaging", "surcharges", "freight"],
    },
  },

  "/courriels": {
    fr: {
      resume: "L'historique et la file d'envoi des courriels de l'entreprise : documents envoyés aux clients et fournisseurs, relances, rapports programmés, avec leur statut.",
      etapes: [
        "Filtrez par statut (« En attente », « En cours », « Envoyé », « Échec ») ou par type de document.",
        "Cliquez sur l'œil « Voir » pour relire un courriel tel qu'il est parti : expéditeur, destinataires, copies, pièce jointe.",
        "Pour un courriel en « Échec », lisez la « Dernière erreur », corrigez la cause, puis cliquez sur « Réessayer ».",
      ],
      astuces: [
        "Les courriels partent du bouton « Envoyer par courriel » des documents (devis, commandes, factures, bons de livraison…) ; sa fenêtre liste aussi les envois précédents.",
        "Un nouvel essai joint le PDF du document dans son état actuel.",
        "Le nombre de tentatives s'affiche à côté du statut (×2, ×3…).",
        "Serveur d'envoi et modèles : « Configuration », onglet « Courriels ».",
        "Cet historique complet demande le droit de voir la Configuration ; la fenêtre d'envoi d'un document montre ses envois à quiconque peut voir ce document.",
      ],
      motsCles: ["emails", "mails", "historique des envois", "file d'envoi", "échecs d'envoi"],
    },
    en: {
      resume: "The history and sending queue of the company's emails: documents sent to customers and suppliers, reminders, scheduled reports, with their status.",
      etapes: [
        "Filter by status (“Pending”, “Sending”, “Sent”, “Failed”) or by document type.",
        "Click the eye icon (“View”) to read an email as it was sent: sender, recipients, copies, attachment.",
        "For a “Failed” email, read the “Last error”, fix the cause, then click “Retry”.",
      ],
      astuces: [
        "Emails are sent with the “Send by email” button of documents (quotes, orders, invoices, delivery notes…); its window also lists previous sends.",
        "A new attempt attaches the document's PDF as it is now.",
        "The number of attempts shows next to the status (×2, ×3…).",
        "Sending server and templates: “Configuration”, “Courriels” tab.",
        "This full history requires the right to view Configuration; the sending window of a document shows its sends to anyone who can view that document.",
      ],
      motsCles: ["emails", "mail", "sending history", "queue", "failed emails", "outbox"],
    },
  },
};
