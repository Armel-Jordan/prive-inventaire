# Phases 6, 7 et portail client : ce qui reste à faire dans PI par rapport à fripv2

> Partie de [la liste du reste à faire](../17-RESTE-A-FAIRE.md), état au 19 octobre 2026 (après la PR #131).
> Pour chaque écran : **ce que fait fripv2** (le comportement de référence), **ce que PI a aujourd'hui**, puis
> **ce qu'il faut faire** (taille **P** petite, **M** moyenne, **G** grande).

**Règles appliquées à chaque proposition**
- Tout est additif et activable par entreprise.
- `tenant_id` + `BelongsToTenant` + `TenantRule`, avec des tests d'isolation (`tests/Feature/Tenant`).
- Droits dans `App\Support\Permissions` et `App\Support\Droits` (préfixe dans `Droits::MODULES`, lectures sensibles dans `LECTURES`).
- Messages de l'API en `__()` + `lang/en.json`, vérifiés par `TraductionsAnglaisesTest`.
- Écrans FR/EN dans `src/i18n/translations.ts`.
- Aucun secret dans le dépôt.

**Prérequis commun à 6.10, 1.11 et 7.x : un domaine en HTTPS.**
- Aujourd'hui tout circule en HTTP sur l'adresse IP du serveur : back-office sur le port 80, espace client sur le port 8081 (`deploy/nginx-*.conf`).
- L'application Android autorise le trafic en clair vers cette IP (`res/xml/network_security_config.xml`).
- Sans HTTPS : pas de paiement en ligne réel, pas de webhooks Stripe/Plaid en production, et les jetons et codes 2FA circulent en clair.

---

### 6.1 Mot de passe, double authentification, sessions — Partiel

**Ce qui existe dans PI**
- Mot de passe oublié, changement de mot de passe, TOTP avec 8 codes de secours.
- Réinitialisation de la 2FA d'un compte par un administrateur.
- Toutes les sessions révoquées après un changement de mot de passe.
- Code : `app/Http/Controllers/Securite/*`, `App\Support\Totp`, `App\Services\DeuxFacteurs`, `SecuriteComptePage`.

**Ce qui manque**
- Limitation des tentatives sur `POST /auth/login` et `POST /super-admin/login` : pas de `throttle` dans `routes/api.php`, aucun `RateLimiter::for`.
- Mot de passe : 8 caractères minimum, sans règle de complexité.
- Pas de 2FA pour le super-administrateur (FLOW-SECURITE §6) ni pour les accès de l'espace client.
- Jeton Sanctum de 7 jours (`AuthController::connecter`), sans écran « mes sessions ».
- Aucun journal des tentatives de connexion.
- La 2FA ne peut pas être imposée par entreprise ou par rôle.

**1) Ce que fait fripv2**
- **Changement de mot de passe**
  - Le mot de passe actuel est exigé.
  - Le nouveau doit avoir **10 caractères au moins**, avec une majuscule, une minuscule, un chiffre et un caractère spécial.
  - Les 5 critères se cochent en direct, avec un indicateur de force, une confirmation et un œil pour afficher.
- L'administrateur donne un mot de passe temporaire ; un mot de passe n'est jamais relisible.
- **Écran de connexion** : le dernier employé du poste est présélectionné, le mot de passe est mémorisable, et chaque tentative échouée est inscrite à l'historique.
- Pas de 2FA dans le logiciel de bureau ; l'administration du portail web exige une **2FA TOTP** configurée à la première connexion.
- Portail : l'IP et le navigateur de chaque connexion sont gardés un an, puis purgés par une tâche à 3 h 30.

**2) Ce qu'il faut faire dans PI** (taille **M**)
- **Limitation et blocage**
  - `RateLimiter::for('connexion')` par IP et par couple (entreprise, courriel), 5 par minute.
  - Après 10 échecs en 15 minutes sur un compte, blocage de 15 minutes (429), comme pour l'étape 2FA.
  - Mêmes règles pour `/super-admin/login`.
- **Journal des connexions**
  - Table `journal_connexions` :
    - tenant_id et admin_user_id (tous deux nullable) ;
    - canal : web, mobile, super_admin, portail ;
    - résultat : succès, mot_de_passe, deux_facteurs, bloqué, inactif, expiré ;
    - ip, user_agent, appareil_id (nullable), created_at.
  - Onglet « Connexions » dans Sécurité, réservé aux administrateurs (nouvelle action `securite.journal`).
  - Tâche de purge, 365 jours par défaut.
- **Politique de mot de passe par entreprise** (`tenant_parametres`)
  - Longueur, classes de caractères exigées, `Password::uncompromised()` en option (appel réseau).
  - Une règle unique `App\Support\RegleMotDePasse` remplace les `min:8` écrits à la main dans `EmployeTenantController`, `AdminUserController`, `MotDePasseController`, `SuperAdminController` et le portail.
  - Indicateur de force à l'écran.
- **2FA exigée** (option d'entreprise : tous les comptes, ou administrateurs et responsables)
  - Un compte sans 2FA reçoit un jeton restreint (ability `securite:configurer`) qui n'ouvre que l'activation.
  - 2FA du super-administrateur avec le même service.
- **Sessions**
  - Colonnes additives `ip`, `user_agent`, `appareil_id` sur `personal_access_tokens`.
  - Routes `GET /auth/sessions`, `DELETE /auth/sessions/{id}`, `DELETE /auth/sessions` (toutes sauf la session courante).
  - Écran « Mes sessions » ; l'administrateur peut fermer toutes les sessions d'un compte.
- **Tests**
  - 429 et blocage.
  - Règle de mot de passe appliquée aux 5 points de création.
  - Jeton restreint, révocation, isolation du journal.
- **Pièges**
  - Le blocage par compte peut servir à bloquer quelqu'un d'autre : garder un blocage court et notifier.
  - Le mobile doit gérer 429 et le jeton restreint.
  - Répondre de la même façon que le code d'entreprise existe ou non.

### 6.2 Permissions fines et groupes — Fait (compléments, taille P)

**Ce qui existe dans PI**
- `Permissions` : modules, CRUD et actions nommées FR/EN.
- `Droits` :
  - rôle + groupes supplémentaires + exceptions par compte, le refus l'emportant ;
  - lectures sensibles contrôlées ;
  - `api/mobile/*` ramené aux mêmes règles.
- `AccesCompteController`, `RolesPage`, `AccesComptePage`, `/permissions/me`.

**1) Ce que fait fripv2**
- **Accès d'un usager** : deux colonnes. À gauche les droits du groupe, en lecture ; à droite les droits propres, à 3 états (accordé, hérité, refusé).
- **Accès d'un groupe** : cases à 2 états.
- Onglets calqués sur le menu, avec compteurs.
- Recherche multi-mots, sans accents, dans les sections et les permissions.
- Boutons « Donner tous les accès », « Retirer tous les accès », « Retirer selon le groupe ».
- Groupes : traduits, désactivables, supprimables seulement sans membre.
- Journal des droits et historique des fiches.

**2) Ce que PI a déjà** : un équivalent fonctionnel, mais sans recherche, sans actions en lot et **sans journal**. `JournalModifications::MODELES` ne couvre ni les rôles, ni la matrice, ni les exceptions.

**3) Ce qu'il faut faire dans PI**
- AuditLog explicite (qui, cible, permission, avant → après) dans :
  - `RolePermissionController` : store, update, destroy, assignRole ;
  - `AccesCompteController::update`.
- Recherche dans la matrice.
- « Tout accorder », « Tout refuser » et « Revenir au rôle » sur un compte ; « Dupliquer un rôle ».
- Noms de rôles traduisibles (voir 6.8).
- L'application Android doit lire `/permissions/me` : aujourd'hui le bouton Relocalisation s'affiche toujours, puis l'API répond 403.
- Tests : journal écrit, isolation.

### 6.3 Verrous des fiches — Partiel

**Ce qui existe dans PI**
- `app/Services/Verrous.php` : `DUREE_SECONDES = 180`. `ENTITES` couvre six types : client, fournisseur, produit, devis, commande_client, commande_fournisseur.
- `useVerrou.ts` renouvelle le verrou chaque minute et au retour sur l'onglet.
- Middleware `verrou:` sur 13 routes.
- `/fiches-en-cours` : rafraîchie toutes les 30 secondes, libération une fiche à la fois.

**1) Ce que fait fripv2**
- **Délais d'expiration des verrous**
  - Un **délai d'inactivité en minutes par type de document** : client, fournisseur, produit, facture, commande, devis, BL, compte GL… (un seul par type). Sans délai, la valeur par défaut s'applique.
  - Le délai vaut tout de suite pour les verrous en cours ; un verrou périmé est levé « au prochain accès ».
  - Droits voir, ajouter, modifier, supprimer, et historique.
  - Exemples donnés : facture 15 minutes, commande fournisseur 120 minutes.
- **Déverrouiller**
  - Tous les verrous en temps réel : type, identifiant, usager, **temps restant à la seconde**, échéance.
  - Libérer un verrou avec confirmation, et **« Tout déverrouiller »**.
  - Recherche et copie.
- Verrous aussi sur les comptes GL, les factures, les BL, les encaissements, les déboursés et les transactions bancaires.

**2) Ce que PI a déjà**
- Le verrou tombe 180 s après le dernier renouvellement. Comme l'écran renouvelle tant qu'il est ouvert, **un usager inactif garde la fiche indéfiniment**. C'est exactement le cas que règle le délai d'inactivité de fripv2.
- Pas de « tout libérer » ni de temps restant.
- Restent sans `verrou:` les `PUT` des :
  - factures fournisseurs, déboursés, encaissements, acomptes, demandes d'acompte, notes de crédit ;
  - retours clients et fournisseurs, reliquats ;
  - abonnements, contrats de location, cartes cadeaux ;
  - corrections de prise d'inventaire, lignes de BL, ordre des tournées.

**3) Ce qu'il faut faire dans PI** (taille **P/M**)
- **Migrations**
  - Table `verrous_delais` : tenant_id, entite, minutes_inactivite ; unique par entreprise et entité.
  - Colonne `verrous.derniere_activite` (nullable).
  - Paramètre `verrous_inactivite_defaut` : null garde le comportement actuel.
- **Mesure de l'activité**
  - `POST /verrous` reçoit `activite_le`, mesuré dans `useVerrou` (dernière frappe, clic ou focus).
  - Au-delà du délai du type, le serveur refuse le renouvellement (409, `motif: inactivite`) et la fiche redevient libre pour les autres.
  - Le bandeau rouge existant affiche « interrompue après N min sans activité ».
- `DUREE_SECONDES` passe en configuration, sans changer la valeur par défaut.
- Étendre `ENTITES`, le middleware et `useVerrou` aux fenêtres listées plus haut, par domaine.
- **Page Fiches en cours**
  - Colonnes « temps restant » et « inactif depuis ».
  - Bouton **Tout libérer** (droit `verrous.liberer`, confirmation, AuditLog).
  - Onglet « Délais », avec le droit de configuration.
- **Tests** : inactivité, délai par type, prise de main inchangée, messages 409 FR/EN.
- **Piège** : un onglet en arrière-plan ne compte pas comme actif.

### 6.4 Recherche globale — Partiel

**Ce qui existe dans PI**
- `App\Services\RechercheGlobale::TYPES` couvre 14 types : produits, clients, fournisseurs, devis, commandes client, factures, notes de crédit, retours clients, demandes d'acompte, BL, commandes fournisseur, retours fournisseurs, secteurs, employés.
- S'y ajoutent les écrans (6 au plus).
- Ctrl+K, 2 caractères minimum, 5 résultats par type, aperçu, `?recherche=`, 60 requêtes par minute.

**1) Ce que fait fripv2**
- Raccourci Ctrl+F, ou menu Informations.
- Types de fiches couverts :
  - clients et fournisseurs, y compris téléphones et numéros de taxes ;
  - **contacts** et **adresses** ;
  - produits, y compris **code-barres, SKU, manufacturier** ;
  - documents de vente, y compris n° de commande du client, nom de livraison, ville, **note** ;
  - **commandes d'achat et réceptions** ;
  - **comptes à recevoir et à payer**, **grand livre** ;
  - **encaissements, déboursés** ;
  - **contrats**.
- « Actions » : les écrans et les boutons du ruban avec leur chemin dans le menu ; 15 écrans au plus sans le filtre Action.
- **Pastilles par type avec leur nombre**, **filtre de dates Du/Au**, **défilement continu**, recherche insensible aux accents.
- Index Meilisearch rafraîchi en tâche de fond.
- Permission « Utiliser la recherche globale ».

**2) Ce qui manque dans PI** : les types en gras, le filtre de dates, les pastilles avec pagination, la recherche dans les notes et les codes-barres. La recherche passe par des `LIKE %…%`.

**3) Ce qu'il faut faire dans PI** (taille **M**)
- **Nouvelles définitions dans `definitions()`**
  - Contacts clients et fournisseurs, adresses.
  - Réceptions, factures fournisseurs, déboursés, encaissements.
  - Écritures comptables, transactions bancaires.
  - Contrats de location, abonnements, consignations.
  - Lots et numéros de série, codes-barres produits, références clients.
  - N° de commande du client.
- Chaque nouveau type porte son module ou son droit, comme dans `services/recherche.ts`.
- Paramètres `du`, `au` et `page` par type ; puces avec total et « voir plus » dans `RechercheGlobale.tsx`.
- Insensibilité aux accents garantie : vérifier la collation `utf8mb4_unicode_ci`, ou des colonnes normalisées.
- **Options**
  - Index FULLTEXT.
  - Moteur externe (Meilisearch ou Typesense) derrière une interface `MoteurRecherche`, interrogé seulement par le serveur avec un filtre `tenant_id`.
  - Permission dédiée.
- **Tests** : droits par type, isolation, filtre de dates.

### 6.5 Assistant IA sur les données — Partiel

**1) Ce que fait fripv2**
- **Assistant** ouvert par le menu Informations ou **F2**, accessible à tous, dans la langue de l'usager.
- **Questions « comment faire »**
  - L'IA cherche et lit la documentation par outils.
  - Elle cite ses sources, cliquables.
  - Quand la doc ne couvre pas la question, elle le dit et renvoie au support.
- **Questions chiffrées** : fripv2 laisse le modèle écrire un SELECT contrôlé (lecture seule, tables limitées aux domaines permis, 100 lignes). **Non repris dans PI** (voir la règle plus bas).
- **Contexte transmis** : nom de l'usager, compagnie, date, écran ouvert et identifiant de la fiche affichée, sans le contenu de l'écran.
- **Liens dans la réponse** : ouvrir un écran autorisé ou une fiche en consultation, permissions revérifiées.
- **Actions**
  - Ouvrir un écran de **création pré-rempli** : client, fournisseur, produit, ou devis/commande/facture/BL pour un client.
  - **Aucune écriture en base** ; permission d'ajout vérifiée ; identifiants validés ; un seul écran par réponse ; action journalisée.
- **Interface** : suite de conversation, « Nouvelle conversation », « Annuler », étapes affichées (« Je consulte vos données… »). Une réponse prend 30 s à 1 min.
- **Justesse**
  - Guide des règles de calcul par écran de référence.
  - Dire ce que couvre le chiffre, annoncer l'hypothèse retenue, revérifier un chiffre surprenant.
  - **Banc d'essai** d'environ 60 questions : 30 « comment faire » et 30 chiffrées, avec une valeur de référence recalculée à chaque passage.
- **Autres fonctions IA**
  - Rapports IA périodiques.
  - **Correction orthographique** : bouton IA dans les champs de texte, aperçu côte à côte, « Appliquer » ; la mise en forme des courriels est conservée.
  - **Rédaction d'un courriel** : objet et corps HTML tirés du contexte.
  - **Traduction automatique** des libellés vides.
  - **Synthèse IA d'un client** : profil, habitudes et marge, situation financière et âge des comptes, opportunités, vigilance ; régénérer, PDF, courriel ; droit dédié.

**2) Ce que PI a aujourd'hui**
- Questions sur les rapports (phase 5.4) :
  - `App\Rapports\AssistantRapports` : outils `executer_rapport` et `repondre`, catalogue filtré par droits, 200 lignes, 6 tours ;
  - `App\Services\Ia\ClientClaude` : HTTP brut, `max_tokens` 4096, pas d'`output_config.effort`, pas de lecture de `stop_reason` ;
  - `RapportIaController` : 50 questions par jour et par compte, 10 par minute ;
  - journal `QuestionIa`, module `ia`, droit `rapports.questionner`, et `commenter` pour les envois périodiques.
- Il manque :
  - la conversation, le contexte d'écran et de fiche ;
  - l'aide documentaire : le contenu est dans `prise-inventaire-web/src/aide/ecrans/*.ts`, que l'API ne peut pas lire ;
  - la recherche de fiches et les liens vers une fiche ;
  - un budget par entreprise et une purge de `questions_ia` ;
  - correction, rédaction, traduction et synthèse client.

**3) Ce qu'il faut faire dans PI** (taille **G**, en lots). Règle conservée : **l'IA n'appelle que des outils applicatifs, jamais de SQL**.

- **6.5a Moteur et conversation (M)**
  - Généraliser `AssistantRapports` en `App\Assistant\AssistantDonnees`.
  - Interface `App\Assistant\Outil` :
    - `nom` ;
    - description FR/EN ;
    - schéma JSON **strict** (`strict: true`, `additionalProperties: false`) ;
    - `disponible(compte, entreprise)` : vérifie le module d'entreprise et `Droits::permet` ;
    - `executer(entrée, contexte)` : revérifie les droits sous `TenantContext`, renvoie au plus N lignes, les totaux et des liens.
  - Le modèle ne voit que les outils et les codes autorisés (enum).
- **Outils**
  - `executer_rapport` : l'outil existant.
  - `chercher_fiches` : enveloppe `RechercheGlobale`, avec les mêmes droits.
  - `lire_fiche` :
    - liste blanche de types : client, fournisseur, produit, devis, commande, facture, BL, commande fournisseur ;
    - renvoie un résumé choisi : réutiliser `GET /clients/{id}/synthese`, l'historique d'achats, le détail des documents ;
    - pas de coordonnées bancaires sans le droit, pas de coûts sans `valorisation`.
  - `indicateur` : **catalogue fermé de mesures nommées** écrites en PHP et testées.
    - Exemples : clients actifs, ventes d'une période, encours et retards, stock d'un produit, commandes en retard.
    - Chaque mesure déclare son module.
    - C'est la réponse « sans SQL » aux questions chiffrées de fripv2.
  - `chercher_aide` et `lire_aide` : sur le contenu d'aide exporté côté API (voir 6.6) et sur les notes de l'entreprise.
  - `repondre` : titre, texte, sources (rapports, fiches, pages d'aide) et liens.
- **Liens, validés côté serveur**
  - Un écran doit figurer dans `config/ecrans.php`, généré depuis `src/navigation.ts` avec son module et son action.
  - Une fiche doit exister dans l'entreprise et être visible par le compte.
  - Un rapport doit être permis.
  - Le front n'affiche que les liens validés.
  - Les « actions » de fripv2 deviennent des liens d'ouverture pré-remplie (`/devis?nouveau=1&client_id=…`), sans écriture.
- **Contexte**
  - Le front envoie `ecran` (le chemin) et `fiche {type, id}` ; le serveur vérifie l'accès avant de les transmettre.
  - S'y ajoutent la langue, la date, le fuseau, le nom, l'entreprise et la devise.
- **Données (migration additive)**
  - `questions_ia` :
    - `type` : rapports par défaut, ou assistant, correction, redaction, traduction, synthese_client ;
    - `conversation_id` ;
    - `outils` (json : nom, entrée validée, lignes, durée, refus) ;
    - `contexte` (json), `cout_estime`.
  - Table `assistant_conversations` : tenant_id, admin_user_id, titre, langue, `transcription` en `encrypted:array` (contenu brut des tours, nécessaire pour continuer la conversation), jetons, coût, timestamps.
  - Tâche `nettoyage_ia` : purge au-delà de `IA_RETENTION_JOURS` (30 jours par défaut).
- **Limites**
  - Quota par compte et par jour : celui qui existe, à séparer ou à partager.
  - **Budget mensuel par entreprise** en jetons ou en dollars, réglé par le super-administrateur (429 au-delà).
  - Environ 10 outils par question ; 200 lignes par outil, avec les totaux sur tout ; taille maximale d'un résultat, par exemple 20 000 caractères.
  - Environ 30 messages par conversation ; délais par appel et par question ; 10 requêtes par minute.
  - Écran super-administrateur « Consommation IA » par entreprise et par mois ; prix par modèle en configuration, pas en dur.
- **API Anthropic : points à vérifier dans la documentation au moment de coder**
  - Avec les modèles récents, la réflexion peut être toujours active : ne pas envoyer de paramètres de réflexion refusés par le modèle choisi.
  - Fixer l'effort explicitement (`output_config.effort`, config `IA_EFFORT`).
  - Un `tool_choice` forcé peut être refusé : garder `auto` et demander de conclure par `repondre`. PI gère déjà la réponse en texte libre.
  - Lire `stop_reason` (`refusal`, `max_tokens`) avant le contenu.
  - Monter `max_tokens` (environ 16 000 sans streaming), car la réflexion consomme la sortie.
  - Renvoyer le contenu de l'assistant **inchangé**, blocs de réflexion compris, et ne jamais réécrire l'historique. Pour alléger une conversation : l'édition de contexte côté API, ou une nouvelle conversation.
  - Cache : outils et consignes stables en tête ; date et écran après le point de cache.
  - Options : streaming SSE pour afficher les étapes ; SDK PHP officiel plutôt que le HTTP brut.
- **Écran**
  - `components/assistant/PanneauAssistant.tsx`, sur le modèle du panneau d'aide, ouvert par F2 ou un bouton de l'en-tête.
  - Étapes en cours, sources cliquables, « Nouvelle conversation », « Annuler » (AbortController plus annulation côté serveur), historique personnel.
  - Mention « données transmises au service d'IA ».
- **Droits**
  - Nouveau module `assistant` (voir), sur la base du tableau de bord, en plus du module d'entreprise `ia`.
  - `rapports.questionner` est conservé.
- **Sécurité**
  - Les résultats d'outils sont des données : la consigne interdit de suivre des instructions qu'ils contiendraient (remarques, descriptions).
  - Aucun outil d'écriture. Markdown restreint, sans HTML ni lien externe.
- **6.5b Mesures et banc d'essai (M)**
  - Catalogue `indicateur`.
  - Jeu d'évaluation sur le modèle fripv2 : environ 30 questions chiffrées avec la valeur de référence calculée par le service, et 30 questions d'aide.
  - Commande artisan pour comparer modèle et effort.
- **6.5c Fonctions IA d'appoint (P chacune)** : un seul appel, sans outil, journalisé avec son `type`.
  - Correction d'un champ de texte, avec aperçu puis « Appliquer ».
  - Rédaction de courriel dans `ComposerCourriel`.
  - Traduction automatique dans l'écran des traductions (voir 6.8).
  - Synthèse IA d'un client sur la fiche client, avec un droit dédié.
- **Tests** (API simulée par `Http::fake`, comme `QuestionsIaTest`)
  - Outils absents ou refusés sans le droit ; fiche d'une autre entreprise invisible.
  - Liens validés.
  - Quotas et budget.
  - `stop_reason` refusal et max_tokens.
  - Continuité de la conversation, purge, traductions.
- **Pièges**
  - Latence de 30 à 60 s : l'affichage des étapes est indispensable.
  - Chiffres inventés : imposer « uniquement les chiffres des outils » et afficher les sources.
  - La carte des écrans et l'aide ne vivent que dans le front.
  - Le coût par entreprise.

### 6.6 Aide en ligne — Partiel

**Ce qui existe dans PI**
- Panneau d'aide par écran FR/EN (F1, « ? »), carte `/aide`, écrans dans la recherche, À propos et nouveautés (FLOW-AIDE).
- Contenu dans `src/aide/ecrans/*.ts` (achats, finance, inventaire, paramètres, ventes) et `nouveautes.ts`.

**Ce qui manque**
- Rien pour l'espace client (`prise-inventaire-client`, sans aide) ni pour l'application Android.
- Pas de notes propres à l'entreprise.

**1) Ce que fait fripv2**
- F1 ouvre la documentation en ligne de l'écran (FR/EN, versionnée).
- Menu Informations : arborescence des écrans, Rechercher, Consulter la documentation, Actualiser (F5), À propos et nouveautés, demandes de support et de suggestion.
- Pages très détaillées : scénarios, champs, droits, FAQ. L'assistant cite ces pages.

**2) Ce que PI a déjà** : une aide équivalente pour le back-office, mais intégrée au bundle web, donc inaccessible à l'API, à l'IA et au mobile.

**3) Ce qu'il faut faire dans PI** (taille **P + P + P**)
- **Notes d'aide de l'entreprise**
  - Table `aide_notes` :
    - tenant_id ;
    - `ecran` : modèle de chemin, comme `/clients/:id` ;
    - titre, contenu en markdown restreint ;
    - langue : fr, en ou toutes ;
    - `roles` visibles (json, null = tous) ;
    - ordre, auteur, timestamps.
  - Routes : `GET /aide/notes?ecran=` pour tous ; CRUD avec une nouvelle action `aide.modifier` (base : configuration modifier).
  - Section « Consignes de l'entreprise » en tête du panneau, comprise dans la recherche et la carte.
  - Pièces jointes possibles (entité `aide_note`) ; AuditLog.
- **Export de l'aide** : script de build `aide:export` qui produit `prise-inventaire-api/resources/aide/{fr,en}.json` (titre, résumé, étapes, mots-clés, chemin). C'est la source de `chercher_aide` (6.5) et de l'aide mobile.
- **Aide de l'espace client et du mobile** : courte FAQ par page FR/EN.
- **Tests** : visibilité par rôle, isolation, langue.

### 6.7 Support et suggestions — Fait (compléments, taille P)

**Ce qui existe dans PI**
- `SupportController`, `SuperAdminSupportController`, `App\Services\Support\DemandesSupport`.
- Statuts : ouverte, en_cours, en_attente, resolue, fermee.
- Notification dans l'application, courriel à `SUPPORT_EMAIL`, écran d'origine retenu.

**1) Ce que fait fripv2**
- **Jusqu'à 5 images** : capture de l'écran d'origine, collage, glisser-déposer ; PNG, JPG, GIF ou BMP, vérifiées à l'ajout.
- **Courriel de confirmation à l'usager**, puis un courriel à chaque réponse (« Votre demande #9 : nouvelle réponse »).
- Numéro `#n` recherchable.
- **Statuts propres aux suggestions** : nouveau, en analyse, accepté, refusé, complété. Une suggestion refusée ou complétée est close.
- Seule l'équipe change le statut.

**2) Ce qui manque dans PI** : les images, le courriel à l'usager, des statuts distincts pour les suggestions (aide et suggestions partagent les mêmes).

**3) Ce qu'il faut faire dans PI**
- Images via `PiecesJointes` (nouvelle entité `demande_support`, lecture permise au super-administrateur) ; capture navigateur (`getDisplayMedia`) ou collage.
- Courriels à l'usager (`CourrielSimple`, FR/EN) à la création et à chaque réponse.
- Jeu de statuts « suggestion ».
- Recherche par `#n`.
- **Tests** : 5 images au plus, types de fichiers, isolation.

### 6.8 Traductions (interface, données, documents) — Partiel

**Ce qui existe dans PI**
- Messages de l'API FR/EN (`DefinirLangue`, `lang/en.json`, test dédié).
- Rapports bilingues, modèles de courriel par langue.
- Descriptions de produits par langue (`ProduitTraduction`).

**Ce qui reste**
- **22 pages web sans traduction** : AlertesPage, ApprobationsPage, AuditPage, CamionsPage, ComparaisonPage, CompleteProfilePage, ComptabilitePage, Dashboard, FichesEmployesPage, InventaireTournantPage, PlanificationPage, PrevisionsStockPage, ProfilPage, RapportsPage, RelocalisationPage, StatsPage, et les 6 pages SuperAdmin.
- Environ **600 lignes .tsx** contenant du français accentué en dur : FacturesPage 36, EmployesPage 35, ConfigurationPage 31, ProduitsPage 30…
- Environ **20 libellés en dur dans `src/navigation.ts`** : « Inventaire », « Achats », « Ventes », « Tournées », « Tarifs & Prix »…
- Android : écrans Kotlin en français en dur, alors que `res/values*/strings.xml` contient 64 chaînes FR/EN quasiment inutilisées (un seul `R.string`).
- PDF (devis, factures, BL…) en français seulement ; aucune langue sur la fiche client ou fournisseur.
- Référentiels en une seule langue.

**1) Ce que fait fripv2**
- **Table des langues** (code, description) pour l'interface et les données ; langue d'interface par usager.
- **Langue d'un client, d'un fournisseur et de la compagnie** : les rapports clients s'impriment dans leur langue.
- Table polymorphe `traduction` pour : groupes, catégories de produits, balises, classes GL, unités (descriptions longue et courte), devises, conditions de paiement, catégories de contact, adresses, institutions, raisons, types de location, transporteurs…
- Bouton globe : une ligne par langue, obligatoire seulement dans la langue de l'usager, espaces retirés, anti-doublon.
- **« Traduire automatiquement »** par l'IA : remplit seulement les cases vides et respecte les longueurs.

**2) Ce qu'il faut faire dans PI**
- **6.8c Écrans web (M)**
  - Traduire les 22 pages et `navigation.ts` (`t.nav.*`).
  - Contrôle en CI : règle ESLint contre les littéraux dans le JSX, ou script qui compte les accents dans les `.tsx` ; test de parité des clés FR/EN.
- **6.8d Données (M)**
  - Table `traductions` : tenant_id, entite, entite_id, champ, langue, valeur ; unique sur l'ensemble.
  - Trait `Traduisible` : lecture dans la langue de la requête, repli sur la colonne d'origine.
  - Composant « Traductions » (globe) dans les formulaires de :
    - référentiels (`Referentiels`), catégories, unités ;
    - modes et conditions de paiement, raisons, statuts personnalisés, types de location, frais ;
    - champs personnalisés (libellés et options), balises, remarques rapides, notes de bas de page ;
    - noms de rôles.
  - Bouton « Traduire automatiquement » avec le module `ia` (voir 6.5c).
- **6.8e Documents (M)**
  - `clients.langue` et `fournisseurs.langue` (null = langue de l'entreprise).
  - Vues `resources/views/pdf/*.blade.php` rendues sous `App::setLocale(langue)` avec `__()`, descriptions de produits et libellés traduits.
  - Courriels : les modèles par langue existent déjà.
- **6.8f Android (P)** : tous les textes dans `strings.xml` ; `LocaleManager` existe déjà.
- **Optionnel** : langues actives par entreprise au-delà de fr et en (voir la partie Base, option 25).
- **Tests** : repli de langue, PDF dans la langue du client, isolation des traductions.

### 6.9 CRM : activités, suivis, webhooks — Manquant (la fiche client couvre une partie)

**1) Ce que fait fripv2**
- **Dans l'ERP**
  - Contacts multiples : poste, téléphone et poste, cellulaire, courriel.
  - **Catégories de contact N-N, traduites**, visibles dans la fiche et dans la liste des clients.
  - **Cases d'envoi** par contact : facture, état de compte, commande.
  - Contact actif ; le premier contact devient principal automatiquement ; identifiant et mot de passe de commande web par contact.
  - **Remarques client** datées et signées, que seul l'auteur modifie, avec remarques rapides (F4).
  - **Panneau Commentaires** sur les documents :
    - un fil par fiche, avec auteur et heure ;
    - « Nouveau » **non lu par personne**, compteur sur le bouton ;
    - « modifié le… » ; un message supprimé devient « Ce message a été supprimé ».
  - **Prospect** : utilisable seulement en devis ; l'acceptation du devis le convertit en client ; exclu des rapports.
  - Synthèse IA du client ; fil d'activité par fiche ; relances de paiement et de devis.
- **Connecteur vers le CRM de l'éditeur**
  - **Envoi vers le CRM** : clients, contacts, adresses et catégories de contact, par lots de 200.
    - Les lignes modifiées sont repérées par balayage de `updated_at` (curseur reculé de quelques minutes), avec une **empreinte SHA-256** pour ne rien renvoyer d'inchangé.
    - Réponse 207 en cas d'échec partiel ; le lot est découpé par moitiés jusqu'à isoler la fiche fautive, qui est mise en quarantaine.
  - **Réception depuis le CRM** (outbox), pour chaque événement :
    - un compte non prospect devient un client ;
    - un devis devient un devis ERP, seulement pour un client confirmé, en une transaction, avec la numérotation de l'ERP ;
    - l'événement est acquitté (applied, skipped ou failed, avec l'identifiant source) ;
    - un parent absent est retenté N fois ; l'empreinte est pré-inscrite pour éviter l'écho.
  - **Authentification** : jeton Bearer par compagnie, stocké chiffré. Sans configuration, tout est silencieux. Le connecteur tourne dans le planificateur de tâches.
- **Le CRM de l'éditeur** (produit séparé)
  - **Activités** : appel, rencontre, démo, courriel, note, changement d'étape. Elles sont liées à un prospect, un compte, une opportunité, un contact ou un devis.
  - **Tâches** :
    - objet, échéance, priorité (basse à urgente), statut (non commencé, ouvert, terminé) ;
    - assignée à quelqu'un, liée à une entité ;
    - **rappel** à une date, par courriel et/ou notification, avec sa date d'envoi.
  - **Appels** : téléphonie, sens, durée, enregistrement, raison.
  - **Opportunités** :
    - pipeline : analyse des besoins → budgétaire déposé → préparation de proposition → proposition déposée → négociation → gagnée ou perdue ;
    - montant, revenu sur 12 mois, raison de perte, historique des étapes.
  - **Objectifs** par période et par mesure ; tableaux de bord ; vues de liste mémorisées ; import Zoho CRM ; multi-compagnie.

**2) Ce que PI a aujourd'hui**
- **Fiche client** (FLOW-FICHE-CLIENT) :
  - synthèse, historique d'achats ;
  - contacts avec fonction ; les cases `recoit_factures` et `recoit_commandes` pré-remplissent les destinataires dans `EnvoiCourriel` ;
  - adresses, remarques datées, signées et épinglables ;
  - catégories de **clients**, favoris, représentant.
- Fiche fournisseur équivalente ; relances de paiement et de devis ; AuditLog par fiche ; pièces jointes ; balises.
- Il n'y a **ni activité, ni tâche, ni rappel, ni prospect, ni pipeline, ni commentaire sur les documents, ni catégorie de contact, ni webhook, ni jeton d'API pour un système externe**.
- Les **notifications sont communes à l'entreprise** : `Notification` n'a pas de compte destinataire, et un seul `lu` est partagé (voir l'anomalie A4).

**3) Ce qu'il faut faire dans PI** (total **G**, en lots)
- **6.9a Activités et suivis (M)**
  - Table `crm_activites` :
    - tenant_id ;
    - type : appel, rencontre, courriel, visite, note ;
    - sujet, contenu, date_activite, duree_minutes, sens ;
    - auteur_id ;
    - cible polymorphe : `cible_type` (client, fournisseur, contact, devis, commande_client, facture, opportunité) et `cible_id`, avec `client_id` et `fournisseur_id` dénormalisés ;
    - deleted_at.
  - Table `crm_suivis` :
    - objet, description, `echeance`, priorité ;
    - statut : à faire, en cours, terminé, annulé ;
    - `assigne_a`, `cree_par`, cible polymorphe ;
    - `rappel_le`, `rappel_courriel`, `rappel_notification`, `rappel_envoye_le`, `termine_le`, `resultat`.
  - Colonne additive `notifications.admin_user_id` (null = toute l'entreprise, comme aujourd'hui) et lecture par compte.
  - Tâche `crm_rappels`, toutes les heures ou toutes les 15 minutes :
    - chaque rappel est envoyé une seule fois ;
    - notification + courriel (modèle `rappel_suivi`, FR/EN) ;
    - résumé quotidien en option.
  - **Routes**
    - `/crm/activites` et `/crm/suivis` : CRUD, filtres moi, équipe, en retard, aujourd'hui.
    - `GET /clients/{id}/fil` et `GET /fournisseurs/{id}/fil` : fil chronologique qui mêle activités, suivis, remarques, documents, courriels envoyés, relances et modifications.
  - **Écrans**
    - « CRM → Mes suivis » : aujourd'hui, en retard, à venir.
    - Onglet « Activités » sur les fiches client et fournisseur.
    - Bouton « Activité / Planifier un suivi » sur la fiche client, le devis, la commande et la facture.
  - **Droits** : module `crm` (voir, créer, modifier, supprimer) avec les actions `assigner` et `voir_tout` (par défaut, chacun ne voit que les siens) ; module d'entreprise `crm`.
- **6.9b Commentaires sur les documents (P)**
  - Table `commentaires` : tenant_id, entite, entite_id, auteur, texte, `modifie_le`, `supprime_le`.
  - Table `commentaires_lus` : compte, entite, entite_id, `lu_jusqu_au`.
  - Même registre d'entités que `PiecesJointes`.
  - Lire et écrire demandent le droit **voir** de la fiche ; chacun ne modifie que ses propres commentaires.
  - Panneau latéral, badge non lu.
- **6.9c Catégories de contact et préférences d'envoi (P)**
  - Type de référentiel `categories_contact` (traduisible, voir 6.8) et table pivot `client_contact_categories`.
  - Colonne « Catégories » dans l'onglet Contacts et dans la liste des clients.
  - Ajouter `recoit_etats_compte` (et `recoit_bl`) pour pré-remplir les destinataires.
- **6.9d Prospects (P/M)**
  - `clients.prospect` (bool, false par défaut).
  - Un prospect n'est accepté qu'en devis : refus 422 ailleurs, au niveau des services.
  - L'acceptation du devis (`ReponsesDevis`, décision manuelle) le convertit en client.
  - Exclu des listes de vente et des rapports, seulement pour les entreprises qui utilisent la case.
- **6.9e Opportunités (M, facultatif)**
  - Table `crm_opportunites` :
    - client ou prospect, contact, responsable ;
    - étape : référentiel `etapes_pipeline`, avec probabilité ;
    - montant et devise, date de clôture prévue ;
    - source, raison de perte, devis lié.
  - Table `crm_opportunite_etapes` pour l'historique.
  - Vue Kanban ; rapport « pipeline ».
- **6.9f Webhooks sortants et jetons d'intégration (M)**
  - Table `webhooks` : tenant_id, nom, URL **https**, `evenements` (json), `secret` (`encrypted`, `$hidden`), actif, `echecs_consecutifs`.
  - Table `webhook_livraisons` : evenement, `id_evenement` (uuid), payload sans données sensibles, statut, tentatives, prochaine tentative, code HTTP, réponse tronquée, durée.
  - **Événements**
    - `client.*`, `contact.*` ;
    - `devis.envoye`, `devis.accepte`, `devis.refuse` ;
    - `commande_client.creee`, `commande_client.acceptee` ;
    - `facture.emise`, `facture.payee`, `encaissement.cree` ;
    - `bon_livraison.livre`, `stock.sous_seuil`, `crm.suivi.echu`.
    - Tous sont émis **après commit** (`DB::afterCommit`).
  - **Envoi** par job en file :
    - en-têtes `X-PI-Evenement`, `X-PI-Livraison`, `X-PI-Horodatage` ;
    - signature `X-PI-Signature: sha256=HMAC(secret, horodatage.corps)`.
  - **Nouvelles tentatives** à 1 min, 5 min, 30 min, 2 h, 6 h et 24 h. Désactivation après N échecs, avec notification. Boutons « Envoyer un test » et « Rejouer ».
  - **Protection SSRF**
    - https seulement ;
    - IP privées et loopback refusées après résolution DNS ;
    - pas de redirection ;
    - délai de 10 s ;
    - réponse lue jusqu'à 64 Ko.
  - **Jetons d'intégration entrants**
    - Un compte technique par intégration, avec un rôle, de sorte que `Droits` s'applique tel quel.
    - Jeton Sanctum avec abilities et expiration, montré une seule fois.
    - Limite de débit par jeton ; liste et révocation.
  - **Droits** : module `integrations` (voir, modifier).
- **6.9g Connecteur CRM externe (G, facultatif)** : moteur de synchronisation générique sur le socle 6.10, avec les recettes de fripv2.
  - Envoi par `updated_at` + empreinte.
  - Réception par webhook ou par interrogation périodique.
  - Correspondances d'identifiants dans `integration_liens`.
  - Création sans fusion automatique, acquittement même des échecs.
  - Cibles possibles : HubSpot, Zoho CRM, Salesforce.
- **Tests**
  - Isolation et droits.
  - Rappel envoyé une seule fois.
  - Commentaires non lus par compte.
  - Prospect refusé hors devis.
  - Signature HMAC, nouvelles tentatives, SSRF refusé.
- **Pièges** : les notifications sont aujourd'hui communes à l'entreprise ; des événements émis avant le commit ; les fuseaux horaires des rappels.

### 6.10-0 Socle des intégrations (connecteurs, secrets, webhooks entrants) — Manquant, prérequis de 6.10-a à f et de 6.9

**Ce qui existe dans PI**
- Aucun connecteur ni webhook.
- Modèle de secret chiffré à reprendre : `CourrielParametres::smtp_mot_de_passe`, en cast `encrypted`.
- `JournalModifications` exclut `EXCLUS` + `$hidden`.
- File `database` ; des jobs servent déjà pour les courriels.

**1) Ce que fait fripv2**
- Les secrets sont dans un fichier chiffré, modifiable seulement par un utilitaire dédié ; un jeton par compagnie.
- Les fournisseurs API ont 5 paramètres libellés, chiffrés. **Mais l'historique des fiches garde l'ancienne et la nouvelle valeur affichées** : défaut à ne pas reproduire.

**2) Ce qu'il faut faire dans PI** (taille **M**)
- **Tables**
  - `integrations` :
    - tenant_id, `connecteur` (code), nom, actif ;
    - `parametres` : json, valeurs non secrètes ;
    - `secrets` : `encrypted:array`, `$hidden`, jamais renvoyés ;
    - statut : non configuré, ok, erreur, reconnexion requise ;
    - dernière exécution, dernière réussite, dernière erreur (sans secret).
  - `integration_executions` : type, début et fin, statut, compteurs (lus, créés, mis à jour, ignorés, erreurs), message.
  - `integration_liens` : entite, entite_id, `id_externe`, empreinte, `synchronise_le` ; uniques par intégration.
  - `integration_evenements_recus` : id externe unique, statut, payload tronqué, pour l'idempotence des webhooks.
- **Code**
  - Interface `App\Integrations\Connecteur` : code, libellés FR/EN, champs (texte, secret, choix), `tester()`, capacités.
  - Catalogue `config/integrations.php` ; le super-administrateur l'ouvre entreprise par entreprise.
  - Client HTTP commun : délais, nouvelles tentatives, journalisation **expurgée**.
  - Route publique `POST /api/webhooks/{connecteur}/{jeton}` : signature vérifiée par le connecteur, traitement en file.
- **Secrets**
  - Clés de la plateforme (Stripe, Plaid, Anthropic) dans le `.env` du serveur.
  - Secrets d'une entreprise en base, chiffrés avec APP_KEY ; rotation par `APP_PREVIOUS_KEYS`.
  - À l'écran : « configuré ••••1234 » ; un champ vide garde la valeur.
  - Jamais dans les journaux, l'AuditLog ou les exceptions.
- **Écran** : Paramètres → Intégrations (cartes, état, configurer, tester, journal).
- **Droits** : `integrations.modifier`, et l'action `integrations.secrets` (administrateurs).
- **Tests** : un secret n'apparaît jamais dans les réponses ni dans `audit_logs` ; isolation ; `Http::fake`.

### 6.10-a Fournisseurs API : facturation à l'usage (VoiceMeUp, ITCloud) — Partiel

Voir aussi la partie Gestion, section 14 (« Connecteurs de consommation ») : même besoin, à construire une seule fois sur le socle 6.10-0.

**1) Ce que fait fripv2**
- **Fiche « Fournisseur API »**
  - Nom, actif, « visible dans les abonnements » ; case `fiche_client` : le code se saisit sur la fiche client.
  - **5 paramètres de connexion libellés, chiffrés.**
  - Ajout et suppression réservés aux administrateurs ; brancher un nouveau service demande l'éditeur.
- **Abonnement**
  - Fournisseur API.
  - Type de produit : 1 texto, 2 appels internationaux, 3 ligne sans frais ; obligatoire pour VoiceMeUp.
  - Code du sous-compte du client.
- **À la facturation**
  - La période est le mois ou l'année qui précède la date de facture.
  - Appel de l'API du fournisseur (nom d'usager + jeton).
  - Texto : segments entrants + sortants, au taux de base.
  - Ligne sans frais : minutes d'origination.
  - International : minutes de terminaison, avec un **taux = montant facturé × 1,5 / minutes** (marge de 50 % codée en dur).
- **Une anomalie annule toute la facture du client** (sous-compte invalide, erreur de l'API) : rollback, et l'erreur figure au bilan.
- ITCloud est déclaré mais **sans calcul d'usage**.

**2) Ce que PI a aujourd'hui**
- Abonnement « à l'usage » : relevés saisis ou importés en CSV, en tout ou rien (`POST /abonnements-clients/releves/import`).
- Quantité facturée = somme des relevés − quantité incluse ; « Relevé manquant » sinon.
- Pas de connecteur, pas de prix « coût + marge ».

**3) Ce qu'il faut faire dans PI** (taille **M**, dépend de 6.10-0)
- **Migration**
  - `abonnements_clients` : `integration_id`, `compte_externe`, `type_usage`, `prix_usage_mode` (tarif par défaut, ou `cout_plus_marge`), `marge_pct`.
  - `abonnement_releves` : `source` (saisie, csv, api), `integration_execution_id`, `reference_externe`, `cout_fournisseur`, `devise_cout` ; unique (abonnement, période, source api).
  - Table `client_comptes_externes`, pour le code saisi sur la fiche client.
- **Interface `FournisseurUsage`**
  - `typesUsage()`.
  - `consommation(integration, compte, type, période)` → quantité, unité, coût.
  - Connecteurs : VoiceMeUp (formules de fripv2, **marge en paramètre**) ; ITCloud, à définir avec son API ; CSV (existant).
- **Tâche `releves_usage`**, vers 5 h, avant `facturation_abonnements` à 6 h : pour chaque échéance due sans relevé, elle crée le relevé (source api).
- **La facturation actuelle ne change pas.** Seule nouvelle branche : en mode `cout_plus_marge`, prix = coût × (1 + marge) / quantité.
- Bouton « Importer la consommation » sur l'abonnement et dans la facturation, avec simulation.
- **Tests** : réponses simulées (nombres en chaîne, clés absentes), changement d'année, idempotence, marge, erreur isolée par client.
- **Pièges**
  - Unité des durées (secondes ou minutes : à vérifier).
  - Devise du coût, fuseau des périodes.
  - Ne pas journaliser les secrets.

### 6.10-b Imports Zoho, Celibec, Trendex : vers un assistant d'import générique — Partiel

**1) Ce que fait fripv2**
- **Import de produits Zoho** : utilitaire séparé en 4 étapes.
  - **Connexion à la base.**
  - **Fichier** : CSV UTF-8 avec `;`, ou XLSX ; **prévisualisation sans écriture** (articles, catégories, fournisseurs, comptes GL, doublons de SKU ou d'Item ID).
  - **Options**
    - Mettre à jour les produits existants.
    - Créer catégories, unités (code de 4 caractères unique) et manufacturiers.
    - **Fournisseurs non créés par défaut.**
    - Importer les quantités en main vers une localisation.
    - Inverser catégorie et sous-catégorie.
    - Valeurs par défaut : département, popularité, localisation, fournisseur, unité, catégorie, quantité max, précision.
  - **Journal.**
- **Correspondance des colonnes Zoho**
  - Item ID : clé d'idempotence, cachée.
  - SKU → numéro (30 caractères, tronqué avec avertissement).
  - Descriptions : longue (100) et courte (30, déduite sans couper un mot).
  - UPC → code-barres, sinon le SKU.
  - Purchase Rate → coûtants, préfixe « CAD » retiré.
  - Account Code → département, par son compte de revenu ; un compte à 4 chiffres est retenté avec un 0 ajouté.
  - Product Type, Status, CF.Unité ou Usage unit, Vendor (par nom puis par numéro), Stock On Hand, Reorder Point, TrackSerialNumber.
  - Montants « 1 234,56 » et « 1,234.56 » compris.
- **Tout ou rien**, relançable sans doublon.
- **Celibec** (Excel)
  - Clients : villes, provinces et codes postaux créés ; Canada ou USA selon le format du code postal ; 6 contacts ; vendeur, territoire, condition de paiement, limite de crédit, prix de base.
  - Fournisseurs : colonnes A à L.
  - Produits : 4 passes (cylindres, produits, prix, escomptes clients) en UPSERT ; la colonne « Cylindre loue » relie un gaz à son cylindre.
- **Trendex** : 5 fichiers, dont ECOM_PRODUCT.
- Import de localisations hiérarchiques ; import d'abonnements.

**2) Ce que PI a aujourd'hui**
- Imports existants : prix fournisseurs (simulation + profils), plan comptable, relevés bancaires, relevés d'usage.
- Produits et employés : import **dans le navigateur** (`ProduitsPage.tsx`, `handleImport`).
  - Découpage naïf `split(';')`, sans guillemets.
  - Colonnes fixes ; création seulement.
  - Une requête par ligne ; non transactionnel.
- Rien pour les clients, fournisseurs, contacts, stocks et localisations.

**3) Ce qu'il faut faire dans PI** (taille **M/G**)
- **Framework `App\Imports`**
  - Entités cibles : produits, clients, fournisseurs, contacts, adresses, localisations, stock initial, tarifs.
  - Lecteurs :
    - CSV : réutiliser la détection du séparateur et de l'encodage de `LecteurReleves::lireCsv` ;
    - XLSX : dépendance à choisir, OpenSpout ou PhpSpreadsheet.
  - Correspondance proposée depuis les en-têtes.
  - **Modèles JSON** prêts à l'emploi (`resources/imports/modeles/*.json`) : « Zoho Inventory articles », « Zoho Books contacts », « Celibec clients », « Trendex PRODUCT ».
  - Options et valeurs par défaut.
  - Clé de rapprochement : identifiant externe dans `integration_liens`, sinon numéro ou code.
- **Exécution**
  - Simulation, puis job en file avec progression.
  - Table `imports` : statut, compteurs, rapport ; fichier temporaire supprimé après N jours.
  - Transaction tout ou rien ; CSV des lignes refusées.
- **Routes** : `/imports/analyser`, `/imports/simuler`, `POST /imports`, `GET /imports/{id}`, `GET /imports/modeles` ; profils (étendre `profils_import` avec `entite`).
- **Écran** : Paramètres → Imports, assistant en 4 étapes ; les boutons actuels pointent vers lui.
- **Droits** : module `imports`, plus le droit de créer de chaque entité.
- **Piège** : dans PI, le stock vient des comptages. Le stock initial doit passer par une **prise d'inventaire validée** ou des arrivages par secteur, jamais par écriture directe.
- **Tests** : bizarreries des CSV, réimport idempotent, rollback, isolation.

### 6.10-c Flux bancaire (Plaid) — Partiel

**1) Ce que fait fripv2**
- **Import**
  - Automatique par `/transactions/sync` avec curseur, une ou deux fois par jour (tâche planifiée).
  - **Date de départ** : rien d'antérieur n'est importé.
  - Table générique des transactions du flux : source, compte et transaction externes, compte GL, date, montant signé, devise.
  - Description et marchand **chiffrés**.
  - Table des imports : curseur ; statut succès, échec ou **reconnexion requise**.
- **Bandeau** quand une connexion échoue ou demande une reconnexion ; compteur dans le menu ; toast « N nouvelles transactions ».
- **Statuts** : non relié, partiellement relié, relié, exclu, **retiré par la banque**.
- **Correspondance**
  - Meilleure correspondance : même compte, même montant et même sens, ±5 jours, sans ambiguïté.
  - Correspondances possibles à ±30 jours, ou recherche sur toutes les dates ; sélection multiple avec l'écart affiché ; délier.
  - **Classer manuellement** : crée un encaissement, un paiement fournisseur, une dépense, une transaction bancaire ou une écriture GL, **pré-rempli et relié d'office**.
  - Exclure avec une raison, rétablir, historique.
- **Comptes du flux** : chaque compte Plaid est associé à un compte GL de banque (« À associer » sinon). Relier n'est pas concilier.
- **Inscription**
  - Plaid Link : link token, échange du public token, mode « mise à jour » pour se reconnecter.
  - URI de redirection OAuth, webhook, retrait de l'item.
  - Page de confidentialité exigée par Plaid.

**2) Ce que PI a aujourd'hui** (FLOW-COMPTABILITE, `App\Services\Banque\RelevesBancaires`)
- Import de **fichiers** OFX, CAMT ou CSV : aperçu, dédoublonnage, liaison aux mouvements, meilleure correspondance, ±30 jours, exclure, rétablir.
- « Créer la transaction » produit un dépôt ou un retrait (transaction bancaire seulement).

**3) Ce qu'il faut faire dans PI** (taille **M**, plus **P** pour le classement)
- **Interface `AgregateurBancaire`**
  - Plaid pour les États-Unis et le Canada.
  - D'autres agrégateurs selon les pays, par exemple GoCardless Bank Account Data pour l'UE et le Royaume-Uni (couverture à vérifier).
  - Elle alimente **les tables de relevés existantes**.
- **Migration**
  - `releve_bancaire_lignes` : `source` (fichier ou connecteur), `integration_id`, `id_externe` (unique), `statut_banque` (en attente, comptabilisé, retiré).
  - Table `comptes_bancaires_externes` : compte externe, nom, masque, `compte_bancaire_id` (null = à associer), devise, solde.
  - Curseur et date de départ dans `integrations`.
- **Routes**
  - `POST /banque/connexions/link-token`.
  - `POST /banque/connexions` : échange du public token ; l'access_token est chiffré.
  - `POST /banque/connexions/{id}/reconnexion`, `DELETE …`.
  - Webhook « mises à jour disponibles » ; tâche `flux_bancaires`.
- **Écrans** : bandeau d'état, comptes à associer, notifications aux comptes qui ont `banque.rapprocher`.
- **Classer manuellement** : étendre « Créer la transaction » à l'encaissement (client) et au déboursé (fournisseur), pré-remplis et reliés, par les services `Encaissements` et `Debourses`.
- **Droits** : nouvelle action `banque.connecter` (administrateurs).
- **Tests** : pages de sync simulées (ajoutées, modifiées, retirées, `has_more`) ; une ligne retirée mais reliée passe à « retirée » ; reconnexion ; isolation ; secrets.
- **Pièges**
  - HTTPS obligatoire, consentement, politique de confidentialité, coût par connexion.
  - Les comptes en devise étrangère sont aujourd'hui refusés à l'import.

### 6.10-d Paiement en ligne (espace client) — Manquant

**1) Ce que fait fripv2**
- **Plafond et case par facture**
  - « Montant maximal facture payable par le web » (TTC ; 0 = jamais).
  - Case **« Payable par plateforme web »** sur la facture :
    - disponible seulement sous le plafond ;
    - modifiable après émission tant qu'il reste un solde ;
    - journalisée ;
    - une infobulle donne la raison quand la case est grisée.
- **Côté client**
  - Résumé du compte : solde, les 5 créances les plus anciennes avec les jours de retard, mention « payable ».
  - **Payer** une facture, ou **Payer tout**.
  - Groupe de paiement : uuid, client, devise, montant en centimes, répartition en JSON, référence.
- **Paiement par Stripe Checkout**, page hébergée.
- **Enregistrement par deux chemins**, idempotents (le premier arrivé gagne) : le retour du navigateur, qui relit la session ; le webhook `checkout.session.completed` signé.
- **Écritures en une transaction** : encaissement, applications sur les comptes à recevoir, écriture GL (compte à recevoir au crédit, banque au débit).
- **Garde-fou** : la clé secrète Stripe, le mode de paiement et le compte à recevoir doivent tous être réglés.
- **Reçu** : courriel et PDF ; liste des paiements avec reçus ; badge « Web ».
- **Prélèvement automatique** par carte enregistrée :
  - portée : factures d'abonnement, ou toutes ;
  - seulement les factures datées après l'inscription ;
  - **3 tentatives espacées de 3 jours** ; tâche à 6 h.
- **Administration du portail** : paiement saisi par un employé (carte donnée au téléphone), cartes enregistrées, historique des transactions Stripe, commande de rejeu.

**2) Ce que PI a aujourd'hui**
- Aucun paiement en ligne (FLOW-ESPACE-CLIENT §9).
- Briques disponibles :
  - `App\Services\Encaissements::enregistrer`, avec répartition sur plusieurs factures ;
  - comptes bancaires, modes de paiement, comptabilisation automatique ;
  - reçu PDF (`pdf/recu-encaissement`) ;
  - factures avec leur reste à payer dans l'espace client.

**3) Ce qu'il faut faire dans PI** (taille **G**, dépend de 6.10-0 et d'**HTTPS sur l'espace client**)
- **Interface `PrestatairePaiement`** : `creerSession`, `verifierWebhook`, `recuperer`, puis plus tard `rembourser`.
  - Premier connecteur : Stripe Checkout.
  - D'autres connecteurs par pays plus tard (par exemple Moneris au Canada, Mollie dans l'UE, des agrégateurs Mobile Money en Afrique : à étudier).
- **Modèle de compte recommandé : Stripe Connect**
  - L'argent arrive sur le compte Stripe de l'entreprise.
  - La clé de plateforme reste dans `.env` ; l'entreprise ne garde que `stripe_account_id`.
  - Variante : des clés propres à l'entreprise, chiffrées.
- **Migration**
  - Paramètres d'entreprise : `paiement_en_ligne_actif`, `paiement_plafond_ttc` (0 = désactivé), mode de paiement « carte en ligne », compte bancaire de compensation, compte des frais (facultatif).
  - `factures.payable_en_ligne`.
  - Table `paiements_en_ligne` :
    - uuid, client, accès du portail ;
    - prestataire, session, référence ;
    - montant, devise, `repartition` (json) ;
    - statut : créé, en attente, payé, échoué, expiré, remboursé ;
    - `encaissement_id`, erreur ;
    - unique (prestataire, référence).
- **Routes**
  - `GET /espace-client/resume`.
  - `POST /espace-client/paiements` : factures choisies ou toutes ; **montants recalculés par le serveur**.
  - `GET /espace-client/paiements`, `/{uuid}`, `/{uuid}/recu`.
  - Webhook public `POST /api/webhooks/paiements/{prestataire}` :
    - signature vérifiée ;
    - entreprise tirée des métadonnées ou du compte connecté ;
    - traitement idempotent avec `lockForUpdate`.
- **Enregistrement** par `Encaissements::enregistrer`, en une transaction.
  - Si une facture a été réglée entre-temps, l'excédent passe en acompte.
  - Devise identique à celle de la facture.
  - Reçu par `EnvoiCourriel` (modèle `recu_paiement`, FR/EN) et notification au personnel.
- **Back-office** : Ventes → Paiements en ligne (liste, rejeu) ; case sur la facture ; Paramètres → Intégrations → Stripe ; éléments de « Configuration à compléter ».
- **Plus tard (M)** : cartes enregistrées et prélèvement automatique, selon les règles de fripv2.
- **Tests** : signature, idempotence entre le retour et le webhook, excédent, devise différente, webhook portant les métadonnées d'une autre entreprise.
- **Pièges** : PCI (Checkout hébergé = SAQ A), aucun montant venu du navigateur, HTTPS obligatoire.

### 6.10-e Export vers une comptabilité externe — Partiel

**1) Ce que fait fripv2** : il tient lui-même le grand livre. On n'y trouve **aucun export vers un logiciel comptable externe**, seulement des fichiers bancaires TFE et des exports Excel des rapports.

**2) Ce que PI a aujourd'hui** : export CSV des écritures sur une période (`EcritureComptableController::exporter`) et du plan comptable.

**3) Ce qu'il faut faire dans PI** (taille **M**, plus **M** par connecteur d'API)
- **Profils d'export**
  - FEC (France : ordre des colonnes, séparateur, nom du fichier).
  - Sage, QuickBooks (IIF ou CSV), Xero, Zoho Books.
  - CSV paramétrable : colonnes, séparateur, formats de date et de montant, débit/crédit ou montant signé.
- **Correspondances** : comptes (`export_correspondances`), journaux, comptes auxiliaires des tiers.
- **Lots**
  - Table `exports_comptables` : période, format, nombre d'écritures, totaux, fichier, auteur.
  - `ecritures_comptables.export_id` et `exporte_le`, pour éviter un double export (« inclure les écritures déjà exportées » en option).
  - Avertissement si une écriture exportée est modifiée.
- Nouvelle action `comptabilite.exporter`.
- **Tests** : conformité FEC, équilibre, double export.
- **Piège** : les formats dépendent du pays (le FEC est français ; prévoir aussi l'OHADA).

### 6.10-f E-commerce — Manquant

**1) Ce que fait fripv2**
- Pas de connecteur de boutique en ligne.
- Son portail B2B tient lieu de vente en ligne : produits « vente web », « quantité max à afficher », images, favoris, panier en brouillon côté serveur.

**2) Ce que PI a aujourd'hui** : l'espace client B2B seulement.

**3) Ce qu'il faut faire dans PI** (taille **G** par connecteur ; commencer par Shopify ou WooCommerce)
- **Envoi du catalogue**
  - Produits cochés `publie_ecommerce`, catégories mises en correspondance.
  - Prix d'une liste de prix « Web ».
  - Stock disponible (`DisponibiliteProduits`) :
    - envoyé après chaque mouvement, en lots regroupés ;
    - resynchronisation complète périodique.
  - Images, descriptions par langue.
- **Réception des commandes**
  - Webhook « commande créée », signature HMAC vérifiée.
  - Crée une **commande client**, en attente ou acceptée selon le réglage :
    - pour un client mis en correspondance ;
    - ou pour le **client de passage**, avec les coordonnées de l'acheteur.
  - Paiement capturé : encaissement.
  - Idempotence par identifiant externe (`integration_liens`).
- **Dépend de** 6.10-0, 2.5 (multi-entrepôts) et 1.2 (prix).
- **Pièges** : variantes de produits face aux produits PI, écart de taxes entre la boutique et PI, données personnelles des clients particuliers, limites de débit des API.

### 1.11 Portail client (catalogue, commandes, paiement en ligne, documents) — Partiel

**1) Ce que fait fripv2 (« Commande web »)**
- **Connexion**
  - Identifiant et mot de passe par contact, ou mot de passe du client.
  - Mot de passe oublié, choix de la langue, page de confidentialité FR/EN.
  - Journal des connexions, purgé après un an.
- **Accueil** : résumé du compte.
- **Catalogue**
  - Prix du client, page produit, vignettes.
  - **Favoris par contact**.
  - Produits « vente web » ; « quantité max à afficher » (« plus de X »).
- **Commandes**
  - **Brouillon web côté serveur** : invisible dans l'ERP, sans réservation de stock.
  - Ajouter, modifier ou retirer des lignes ; annuler ; **finaliser**.
  - La commande passe alors dans l'ERP avec un badge « Web », que portent aussi ses BL et ses factures.
  - **Courriel de confirmation**, PDF.
  - **Commis web par défaut** attribué (fiche compagnie).
- **Mon compte**
  - **Devis** : liste, détail, PDF.
  - Factures, **acomptes**.
  - **Paiements avec reçus**.
  - Payer une facture ou payer tout ; prélèvement automatique.
  - Adresses.
- **Réponse à un devis par lien** : un GET ne modifie jamais le devis.
- **Administration du portail** (employés, 2FA obligatoire)
  - Recherche de clients.
  - **Incarnation d'un contact en lecture seule**.
  - Paiements par téléphone, cartes enregistrées, transactions Stripe.
  - **Apparence** : deux couleurs + logo.
  - Plaid.
  - Liste des employés qui accèdent à l'administration.

**2) Ce que PI a aujourd'hui** (FLOW-ESPACE-CLIENT ; `prise-inventaire-client/src/espace/pages/*`)
- Catalogue au prix du client avec catégories, recherche, image principale et références client.
- Panier gardé dans le navigateur, prix recalculés par le serveur.
- Commande en attente (adresse, date, remarques), notification à l'entreprise.
- Commandes : liste, détail, PDF, annulation, « commander à nouveau ».
- Factures avec reste à payer, retard et PDF.
- Profil, invitation et mot de passe oublié, sessions de 30 jours (jeton haché), réponse aux devis par jeton, FR/EN, fermé par défaut.

**3) Ce qu'il faut faire dans PI** (taille **G** au total)
- Paiement en ligne et résumé du compte (voir 6.10-d).
- **Documents (M)**
  - Devis : liste, PDF, accepter depuis la liste.
  - BL avec signature et preuve (après 7.1).
  - Notes de crédit, acomptes et demandes d'acompte (à payer), reçus.
  - **État de compte PDF.**
  - Contrats de location, abonnements et relevés de consignation, en lecture seule.
  - Nouvelles routes `/espace-client/{devis,bons-livraison,avoirs,acomptes,etat-de-compte}`, chacune bornée au client.
- **Catalogue (P/M)**
  - Favoris par accès (`portail_favoris`).
  - Commande rapide par référence et quantité ; import CSV d'une commande.
  - Indication de stock (en stock, sur commande, délai), avec le plafond « plus de X ».
  - Case produit « visible dans l'espace client », en plus des catégories.
- **Commande (P)**
  - Courriel de confirmation au client, puis à chaque changement de statut (`EnvoiCourriel`, FR/EN).
  - Représentant par défaut des commandes du portail (paramètre).
  - Repère « portail » sur les commandes, factures et BL du back-office : `com_client_entete.portail_compte_id` existe déjà, il reste à l'afficher.
  - Panier côté serveur, en option.
- **Back-office (P/M)**
  - **Incarnation en lecture seule** pour le support (droit dédié, journalisée).
  - Couleurs de l'entreprise.
  - Droits par accès, en option : peut commander, voir les factures, payer.
- **Sécurité (P)** : HTTPS et domaine ; 2FA facultative pour les accès ; journal des connexions avec purge ; page de confidentialité.
- **Aide du portail** : voir 6.6.
- **Tests** : bornage au client pour chaque document ; incarnation sans écriture.

### 7.0 Application Android actuelle, socle mobile et registre des appareils (« Tablettes ») — Partiel

**1) Ce que fait fripv2**
- **Tablettes**
  - Identifiant **unique, en majuscules** (100 caractères).
  - Mot de passe haché : obligatoire à la création ; laissé vide, il reste inchangé.
  - Case active ; filtre actives ou inactives ; recherche.
  - **Suppression refusée** si la tablette a des scans de prise d'inventaire ou des boîtes de BL : il faut la désactiver.
  - Historique sans le mot de passe ; droits voir, ajouter, modifier ou désactiver, supprimer.
  - Chaque scan d'inventaire garde l'usager, la tablette, la date et la quantité.
  - Une **imprimante peut être rattachée à une tablette** (étiquettes thermiques).
  - Champ « No tablette » sur l'usager.
- **API des tablettes**
  - L'identifiant et le mot de passe de la tablette partent avec chaque requête, sans jeton : à ne pas reproduire.
  - Données de référence chargées depuis la dernière synchronisation moins 30 minutes : clients, contacts, adresses, produits, codes-barres, localisations, routes, traductions.
  - Livraison : téléchargement, envoi, statut, rapport, versions.
  - Boîtes : récupération, envoi, impression.
  - Camion : liste, routes, préparation, BL par route.
  - Produits : recherche, localisations, cylindres.
  - Relocalisation par produit ou par localisation.
  - Inventaire.
  - Réception fournisseur : recherche de commande ou de produit, destinations, scan de cylindre ou de matériel, réception hors commande.

**2) Ce que PI a aujourd'hui** (`prise-inventaire-android` : Kotlin et Compose, minSdk 26, cible 34)
- **Parcours**
  - Splash → Connexion : code entreprise, courriel, mot de passe.
  - **Étape 2FA présente** :
    - `LoginActivity.DeuxFacteursScreen` : code à 6 chiffres ou code de secours ;
    - `InventaireApiService.loginDeuxFacteurs` appelle `POST /auth/login/deux-facteurs` ;
    - `defi_expire` ramène au mot de passe.
  - **Mais** l'APK versionné à la racine, `prise-inventaire-v1.0.apk`, date d'avant l'authentification par jeton et d'avant la 2FA : il appelle des routes supprimées et **ne peut plus se connecter**.
  - Choix de l'employé dans une liste, **sans code personnel**. La liste vient de `GET /mobile/employes` sans `?actif=1`, donc les inactifs apparaissent.
  - Secteur : liste ou QR (ZXing).
  - **Scan** : saisie ou douchette en mode clavier, puis `POST /mobile/produit/valider` et `POST /mobile/scan/enregistrer`.
  - Historique : `GET /mobile/scan/historique`, `PUT` et `DELETE /mobile/scan/{id}`.
  - Relocalisation (arrivage, transfert, sortie) : `GET /mobile/secteurs`, `POST /mobile/relocalisation`.
  - Configuration : URL du serveur, langue.
  - Routes non utilisées : `GET /mobile/produits`, `GET /mobile/relocalisation`, `POST /mobile/secteurs/validate-qr`.
- **Défauts constatés**
  - **Hors-ligne inopérant**
    - La table Room `pending_scans` existe, mais `saveScanOffline` n'est jamais appelé.
    - `SyncWorker.schedule` n'est jamais appelé, et le worker ne restaure pas le jeton.
    - `HistoriqueLocalActivity` n'est accessible depuis aucun écran.
  - **Sécurité de l'application**
    - Jeton en `SharedPreferences` en clair (`AuthStore`, avec le commentaire « à durcir »).
    - **Pas de déconnexion** (« Quitter » ferme seulement l'écran) et **pas de gestion du 401**, alors que le jeton dure 7 jours.
    - HTTP en clair vers l'IP du serveur (`InventaireApiService.baseUrl`, `network_security_config.xml`).
    - Configuration de signature écrite dans `app/build.gradle`.
  - **Construction**
    - Le build modifie `version.properties`.
    - Pas de CI Android : `.github/workflows` n'en a pas.
  - **Fonctionnement**
    - Textes en français en dur.
    - Les codes-barres de carton perdent leur multiplicateur : l'API renvoie `quantite_par_lecture`, mais l'application renvoie le **numéro du produit** avec la quantité saisie.
    - Pas de scan caméra des produits.
    - Les droits sont ignorés.
    - L'appareil n'est identifié que par `Build.MODEL` (champ `scanneur`).
  - Le tableau des endpoints de `docs/07` est périmé (`/mobile/scans`, `/mobile/sync`).

**3) Ce qu'il faut faire dans PI** (taille **M + M + M**)
- **Registre des appareils (M)**
  - Table `appareils_mobiles` :
    - tenant_id ;
    - nom (unique, en majuscules) ;
    - `identifiant_installation` (uuid généré par l'application) ;
    - modèle, OS, version de l'application ;
    - actif, `enrole_le` et `par`, `dernier_contact_le`, IP ;
    - `employe_defaut_id`, `imprimante` (json), notes, deleted_at.
  - Colonne additive `personal_access_tokens.appareil_id`.
  - **Enrôlement par QR**
    - L'administrateur génère un code à usage unique (15 minutes, haché).
    - L'application appelle `POST /mobile/appareils/enroler`.
    - Un compte connecté (avec 2FA) est ensuite lié à l'appareil.
  - **Middleware `appareil`** sur `/mobile/*`
    - Lit l'en-tête `X-Appareil`.
    - N'est actif que si l'entreprise coche « appareils enregistrés obligatoires » ; sinon, comportement actuel.
    - Met à jour le dernier contact.
  - **Code personnel de l'employé** (`employes.pin_hash`, 4 à 6 chiffres, blocage après plusieurs échecs), pour remplacer le choix libre sur un appareil partagé.
  - **Écran Paramètres → Appareils mobiles**
    - Liste : version, dernier contact, employé.
    - Ajouter (QR) ; renommer.
    - Désactiver : révoque les jetons ; l'application efface ses données sur `401 appareil_desactive`.
    - Supprimer, seulement si l'appareil n'a jamais servi ; historique.
  - **Droits** : module `appareils` (voir, créer, modifier, supprimer).
  - Colonne `appareil_id` (nullable) sur les scans, mouvements, livraisons et photos, et colonne « Appareil » dans le back-office.
  - `GET /mobile/version` (version minimale), pour bloquer une application trop ancienne.
- **Durcissement (M)**
  - Jeton chiffré par une clé de l'Android Keystore (vérifier l'état de la bibliothèque androidx security-crypto avant de la choisir).
  - Intercepteur 401 → retour à la connexion ; menu « Se déconnecter » (`POST /auth/logout`).
  - HTTPS seulement : retirer l'IP et le trafic en clair.
  - Signature lue hors du dépôt : `keystore.properties` ignoré par git, ou variables de CI.
  - Workflow GitHub Actions `assembleRelease` ; supprimer l'APK obsolète de la racine.
  - Textes dans `strings.xml` ; `?actif=1` sur les employés.
  - Utiliser `quantite_par_lecture` ; scan caméra des produits ; intents des douchettes, en option.
  - Menus adaptés à `/permissions/me`.
- **Moteur hors-ligne commun à 7.1, 7.2 et 7.3 (M)**
  - **Côté application** : file Room par type d'action (uuid, `saisi_le`, données, statut, tentatives) ; WorkManager périodique et au retour du réseau.
  - **Côté API : idempotence**
    - Middleware `idempotent` sur l'en-tête `Idempotency-Key`.
    - Table `requetes_mobiles` : tenant, uuid unique, route, statut, réponse.
    - Une requête rejouée reçoit la même réponse.
  - **`saisi_le` du client accepté entre maintenant − 7 jours et maintenant + 5 minutes.** Cela lève la limite notée dans FLOW-PRISE-INVENTAIRE §5.
  - **Conflits** : 409 avec un `code` (`bon_deja_livre`, `prise_cloturee`…), puis une liste « À corriger » dans l'application.
  - `GET /mobile/referentiel?depuis=` : lignes modifiées et identifiants supprimés, avec une marge de recouvrement ; l'heure du serveur sert de prochain curseur.
- **Tests** : `MobileTenantTest` étendu, appareil désactivé, idempotence, bornes de `saisi_le`.

### 7.1 Application livreur — Manquant côté mobile (le back-office web existe)

**1) Ce que fait fripv2**
- **Tablette de livraison**
  - Téléchargement des BL **par route**, aux statuts en attente, boîtes préparées ou préparation camion.
    - Les BL arrivent **dans l'ordre de passage**, avec leurs lignes et leurs codes-barres.
  - Saisie de la quantité réellement livrée par ligne.
  - **Scan des codes-barres** : les articles suivis se scannent un par un, comme les cylindres.
  - **Produits ajoutés sur place** : prix calculés par la logique de la facture ou de la commande.
  - **Quantités négatives** pour les vides ramassés (voir 7.2).
  - **Signature**, stockée en image.
  - Envoi par uuid.
- **Traitement serveur de chaque BL**
  - BL venant d'une facture ou d'une commande : mise à jour de ses lignes.
  - **BL manuel** : une facture complète est créée.
  - Mouvements de stock ; statut livré partiel ou complet ; rapport de livraison.
- **Camion**
  - Préparation : liste des camions et des routes, chargement et validation.
  - Boîtes imprimées sur l'imprimante de la tablette.
  - Le camion est une **localisation de stock** :
    - le chargement déplace la marchandise ;
    - la livraison la prend dans le camion ;
    - le déchargement remet le reste en place.
- Vérification de la version de l'application.

**2) Ce que PI a aujourd'hui** (web seulement)
- **BL**
  - Statuts : créé → en préparation → prêt → en livraison → livré complet ou partiel → annulé.
  - Reliquat facturé automatiquement.
  - **Signature** dans « Enregistrer livraison » : `PadSignature`, PNG ou JPEG de 500 ko au plus, signataire, `signe_le`, imprimée sur le PDF.
  - Colis avec étiquette QR ; **chargement du camion avec scan**, sur le web.
- **Tournées**
  - Camion, `livreur_id`, zone, itinéraire, ordre ; « ajouter la route », tri par séquence.
  - Démarrer et terminer, avec les kilomètres.
  - `TourneeBon` : statut en attente/livré/échec, `motif_echec`, `heure_livraison`.
  - Feuille de route PDF, document matières dangereuses.
- Lots et séries à sortir : `GET /bons-livraison/{id}/exemplaires`.
- Il n'y a **ni application, ni vue « mes tournées », ni photos, ni GPS, ni hors-ligne**.
- `livreur_id` est validé en `nullable|integer` **sans `TenantRule`** (`TourneeController`).

**3) Ce qu'il faut faire dans PI** (taille **G** ; dépend de 7.0)
- **Service** : extraire la logique de `BonLivraisonController::enregistrerLivraison` dans un service `LivraisonsBon`, partagé entre le web et le mobile, pour que la livraison partielle se comporte pareil des deux côtés.
- **Routes `/mobile`**
  - Droits : `bons_livraison.livrer`, `tournees.piloter` et `tournees.charger`, plus une nouvelle action `tournees.voir_toutes`.
  - `GET /mobile/tournees?date=` : les tournées de l'employé lié au compte.
  - `GET /mobile/tournees/{id}/paquet` (paquet hors-ligne) :
    - arrêts ordonnés, lignes, lots et séries attendus, colis ;
    - client avec adresse, instructions, téléphone et contact ;
    - matières dangereuses, version.
  - `POST /mobile/tournees/{id}/demarrer` et `/terminer` : kilomètres, `saisi_le`.
  - `POST /mobile/chargement/scan` : réutilise `ChargementTournee`.
  - `POST /mobile/bons-livraison/{id}/livrer` (idempotent) :
    - lignes, quantités, lots et séries, codes scannés ;
    - signature, signataire, notes ;
    - `gps {lat, lng, precision}` ;
    - uuid des photos.
  - `POST /mobile/bons-livraison/{id}/echec` : motif tiré d'un référentiel `motifs_echec_livraison`, notes, photo.
  - `POST /mobile/bons-livraison/{id}/photos` : envoi multipart vers `PiecesJointes` (entité `bon_livraison`, catégorie preuve, dommage ou lieu). Les photos sont compressées sur l'appareil.
  - `GET /mobile/bons-livraison/{id}/pdf`.
- **Migrations (additives)**
  - `bons_livraison` : `livre_lat`, `livre_lng`, `livre_precision_m`, `livre_appareil_id`, `livre_par`.
  - `tournee_bons` : `arrivee_le`, `arrivee_lat` et `arrivee_lng`, `motif_echec_id`.
  - `client_adresses` : `latitude`, `longitude`.
  - Corriger la validation de `livreur_id` : `TenantRule::exists('employes')`.
- **Application** : module « Livraison » ajouté à l'application existante.
  - Mes tournées → télécharger.
  - Chargement : scan des colis.
  - Démarrer : kilomètres.
  - Arrêts :
    - appeler le client ;
    - **naviguer** par intent (Google Maps ou Waze, avec l'adresse ou les coordonnées) ;
    - scanner ou confirmer les lignes ;
    - photos ;
    - **signature** : la dépendance `signature-view` est déjà déclarée dans `app/build.gradle`, inutilisée ;
    - valider, ou déclarer l'échec.
  - Terminer.
  - Indicateur de synchronisation.
- **Back-office** : statut de chaque arrêt en direct (heure, lien GPS, photos, signature) ; notification « livraison échouée ».
- **Réglages d'entreprise** : photo obligatoire, signature obligatoire, GPS à la livraison (seulement à ce moment, pas de suivi continu).
- **Tests**
  - Rejeux idempotents.
  - Livraison partielle identique au web.
  - Lots obligatoires.
  - BL annulé entre-temps (409).
  - Un livreur ne voit pas la tournée d'un autre.
- **Pièges**
  - Écarts d'horloge ; gros volumes de photos sur réseau mobile.
  - Lien entre l'employé et le compte.
  - Vie privée liée au GPS ; restrictions des tâches de fond sur Android.

### 7.2 Ramassage et retour des actifs retournables — Manquant (le back-office couvre une partie)

**1) Ce que fait fripv2**
- **Cylindres**
  - Cases produit « location de cylindre » et « produit avec cylindre » : le gaz est relié à son cylindre, **ajouté d'office aux BL et aux réceptions**.
  - Suivi à l'unité par code-barres : client, localisation, date de retour.
  - Contrats « cylindre » : quantité exemptée, prix de location, facteur.
  - **Rapport de loyer jour par jour** : solde en main − quantité sous contrat, multiplié par le taux.
- **Sur la tablette**
  - Le livreur saisit les vides ramassés en **quantité négative**, avec le scan des codes.
  - Le serveur crée alors un retour fournisseur (raison « Retour cylindre », vers le fournisseur principal).
  - Il remet chaque numéro en stock à la localisation de retour : le client est vidé, la date de retour est inscrite, avec le mouvement de stock.
- Raisons de retour fixes : note de crédit, retour de cylindre, retour de location, défectueux.

**2) Ce que PI a aujourd'hui**
- Contrats de location : retour d'unités par quantité (`POST /contrats-location/{id}/lignes/{ligneId}/retour`), renouvellement, loyers.
- Consignations : dépôt, relevé, **reprise**.
- Numéros de série : statuts en stock, livré, retourné, rebut, avec `client_id`.
- Retours clients avec lots et séries, remise en stock ou rebut.
- **Produits annexes** ajoutés d'office aux ventes (`LiensProduits`).
- Il manque :
  - la notion d'**actif retournable**, avec un solde par client ;
  - le ramassage ;
  - le mobile.

**3) Ce qu'il faut faire dans PI** (taille **M/G** ; dépend de 7.0 et 7.1)
- **Produit** : `retournable`, `mode_retournable` (consigne, location ou prêt), `montant_consigne` ; le contenant est relié par une annexe de type « contenant ».
- **Registre** `actifs_retournables_mouvements`
  - Client, produit, `numero_serie_id`.
  - Quantité : + pour une sortie chez le client, − pour un retour.
  - Origine : BL, ramassage, retour client, contrat, consignation, ajustement.
  - Date, appareil, auteur.
  - Vue des soldes par client.
- **Ramassage** (`RAM-00001`)
  - En-tête : client, adresse, date prévue, statut.
  - Lignes : prévu, ramassé, séries, état (bon, endommagé, vide, plein).
  - Signature, photos.
  - Trois façons de le créer :
    - planifié depuis la fiche client ou le contrat ;
    - ajouté à une tournée (table `tournee_ramassages`) ;
    - créé par le livreur à un arrêt.
- **Effets**, chacun en option :
  - mouvement vers un secteur « retours » ;
  - séries passées à « retourné » ;
  - solde du client diminué ;
  - retour du contrat de location, ou reprise de la consignation ;
  - **note de crédit de consigne** ;
  - retour fournisseur automatique, comme pour les cylindres de fripv2.
- **Routes `/mobile`**
  - Ramassages de la tournée.
  - `GET /mobile/clients/{id}/actifs`.
  - `POST /mobile/ramassages` et `/{id}/effectuer`, idempotents.
  - `POST /mobile/numeros-serie/resoudre` : avertit si le numéro n'est pas chez ce client.
- **Écrans**
  - Ventes → Actifs chez les clients, et Ramassages.
  - Onglet « Actifs » de la fiche client.
  - Rapports « en circulation » et « retours en retard » ; loyer journalier en option.
- **Droits** : module `actifs_retournables` et action `ramassages.effectuer`.
- **Pièges**
  - Une seule source de vérité : le registre. Un contrat de location ne le lit que si l'option est active.
  - Numéros inconnus (contenant d'un autre fournisseur).
  - Soldes initiaux à importer.

### 7.3 Inventaire mobile enrichi (lots, étiquettes, sessions de comptage) — Partiel

**1) Ce que fait fripv2** : la tablette collecte, le poste de bureau décide.
- **Prises en cours** : localisation et sous-localisations, début et durée.
- **Saisie**
  - Scan d'un code, ou recherche.
  - Fiche produit : théorique, réel, écart, « compté il y a… ».
  - Progression (n/m, %) ; filtres tous, comptés, non comptés, écarts.
  - **Code-barres UNIQUE** (produit suivi à l'unité, ou cylindre) : quantité forcée à 1. Code MULTIPLE : quantité saisie.
  - **« Produit hors localisation : enregistrer quand même ? »**
- Chaque scan garde l'usager, la tablette, la date et la quantité, et remonte seul dans la prise.
- **La validation se fait sur le poste de bureau** : écarts positifs, négatifs et nets ; les non-comptés sont ignorés.
- Sur la tablette aussi :
  - relocalisation par produit ou par localisation ;
  - **réception fournisseur** : recherche de commande, scan de cylindre ou de matériel, réception hors commande ;
  - boîtes, et **étiquettes sur l'imprimante de la tablette**.

**2) Ce que PI a aujourd'hui**
- **Mobile**
  - Scan par secteur, sans session ; quantité saisie ; facteur carton perdu.
  - Pas de lot, de série ni de péremption.
  - Pas de localisation : seulement les secteurs, par leur nom.
  - En ligne seulement.
- **Back-office**
  - Sessions de prise d'inventaire au-dessus des scans : corrections, validation → inventaire officiel ; deux sessions ne partagent pas un secteur.
  - Lots et séries, multi-entrepôts.
  - **Formats d'étiquettes et impression PDF** (`/etiquettes/imprimer`).
  - Codes-barres multiples avec quantité.

**3) Ce qu'il faut faire dans PI** (taille **M**, plus **P** pour les étiquettes, plus **M** en option pour la réception ; dépend de 7.0)
- **Migration des scans** (additive) : `uuid`, `appareil_id`, `prise_inventaire_id`, `emplacement_id` (voir Base §23), `lot_numero`, `date_peremption`, `numero_serie`, `saisi_le`.
- `PrisesInventaire` prend `saisi_le` quand il existe (option par entreprise, pour garder l'existant).
- **Routes**
  - `GET /mobile/prises` : sessions en cours et avancement par secteur.
  - `GET /mobile/prises/{id}/secteurs/{secteur}` : lignes attendues, ou **comptage à l'aveugle** en option.
  - `POST /mobile/scans` en v2 : uuid, `saisi_le`, numéro ou code lu, quantité, secteur ou emplacement, prise, lot et péremption, séries.
  - `GET /mobile/produits/recherche`.
  - Relocalisation v2, avec emplacements et lots.
  - `POST /mobile/etiquettes` : renvoie le PDF à imprimer par le service d'impression Android, ou l'envoie à l'imprimante de l'appareil (`appareils_mobiles.imprimante`).
  - En option : `GET /mobile/receptions/commandes` et `POST /mobile/receptions`, qui réutilisent `ReceptionsFournisseur`.
- **Application**
  - Choix d'une session, ou scan libre comme aujourd'hui ; QR du secteur ou de l'emplacement.
  - Boucle de scan à la caméra ou à la douchette.
  - Produits suivis à l'unité : quantité 1 et capture de la série. Produits suivis par lot : demande du lot et de la péremption.
  - Facteur carton affiché ; alerte « hors secteur ».
  - Progression et filtres, file hors-ligne, impression d'étiquettes.
- **Droits** : `inventaires`, `inventaires.prises`, `lots.voir`, `etiquettes`, `receptions.creer`.
- **Tests** : idempotence, `saisi_le` dans la fenêtre de la session, lots et séries, facteur carton, isolation.
- **Pièges**
  - PI n'a pas de stock théorique : l'« attendu » est le dernier inventaire validé.
  - Session déjà validée : le scan reçoit 409, ou passe « hors session ».
  - Unicité des numéros de série.

### Hors périmètre (confirmé)

- Rapport IA par SQL écrit par le modèle, et l'outil d'interrogation directe de la base : non repris, par la règle de PI.
- Transport du chatbot par ligne de commande, fichier de connexion chiffré et son utilitaire de configuration.
- Licences par poste, relais, captures d'écran documentaires.
- Hébergement de l'aide sur un wiki externe.
- Téléphonie intégrée du CRM de l'éditeur : connecteur éventuel, non prévu.
- Connexion des tablettes par identifiant et mot de passe à chaque requête : remplacée par les jetons d'appareil (7.0).

---

## Tableau de synthèse

| # | Élément | Statut | Reste à faire (résumé) | Taille | Dépend de |
|---|---|---|---|---|---|
| 6.1 | Mot de passe, 2FA, sessions | Partiel | Limitation des tentatives de connexion, journal, politique par entreprise, 2FA exigible et super-administrateur, écran des sessions | M | — |
| 6.2 | Permissions et groupes | Fait | Journal des droits, recherche, actions en lot ; mobile qui respecte les droits | P | — |
| 6.3 | Verrous | Partiel | Délai d'inactivité par type, « tout libérer », plus de fiches verrouillées | P/M | — |
| 6.4 | Recherche globale | Partiel | Types manquants, dates, pastilles et pagination, accents, index | M | — |
| 6.5 | Assistant IA | Partiel | Conversation, contexte, outils (fiches, mesures, aide), liens validés, budgets, journal, banc d'essai ; IA d'appoint | G | 6.6 (export de l'aide), 6.10-0 (budgets, secrets) |
| 6.6 | Aide | Partiel | Notes de l'entreprise, export JSON de l'aide, aide du portail et du mobile | P+P+P | — |
| 6.7 | Support | Fait | Images, courriels à l'usager, statuts des suggestions | P | — |
| 6.8 | Traductions | Partiel | 22 pages et menu, données traduisibles, PDF dans la langue du client, Android | M+M+M+P | — |
| 6.9 | CRM et webhooks | Manquant | Activités et suivis avec rappels, commentaires, catégories de contact, prospects, opportunités (option), webhooks et jetons, connecteur (option) | G | 6.10-0 ; notifications par compte |
| 6.10-0 | Socle des intégrations | Manquant | Connecteurs, secrets chiffrés, journal, liens, webhooks entrants | M | HTTPS |
| 6.10-a | Usage par API | Partiel | Connecteurs d'usage (VoiceMeUp…), relevés « api », prix coût + marge | M | 6.10-0 |
| 6.10-b | Imports | Partiel | Assistant générique CSV/XLSX, modèles Zoho, Celibec, Trendex | M/G | — |
| 6.10-c | Flux bancaire | Partiel | Agrégateur (Plaid) vers les relevés existants, connexions, statuts, « classer » vers encaissement ou déboursé | M+P | 6.10-0, HTTPS |
| 6.10-d | Paiement en ligne | Manquant | Prestataire (Stripe Connect), paiements de l'espace client, webhook idempotent, encaissement, reçu | G | 6.10-0, HTTPS, 1.11 |
| 6.10-e | Export comptable | Partiel | Profils FEC, Sage, QuickBooks, Xero, CSV ; lots d'export ; correspondances | M | — |
| 6.10-f | E-commerce | Manquant | Connecteur Shopify ou WooCommerce (catalogue, stock, commandes) | G | 6.10-0 |
| 1.11 | Portail client | Partiel | Paiement, résumé du compte, documents, favoris, stock, confirmations, incarnation, HTTPS | G | 6.10-d, 7.1 (preuves) |
| 7.0 | Socle mobile et appareils | Partiel | Registre des appareils (QR), code personnel, durcissement, CI, moteur hors-ligne idempotent | M+M+M | HTTPS |
| 7.1 | Application livreur | Manquant | Tournées hors-ligne, navigation, scan, signature, photos, GPS, échec | G | 7.0 |
| 7.2 | Ramassage et actifs retournables | Manquant | Registre des actifs, ramassages, mobile, consignes et notes de crédit | M/G | 7.0, 7.1 |
| 7.3 | Inventaire mobile | Partiel | Sessions, lots et séries, emplacements, étiquettes, réception (option) | M+P(+M) | 7.0 |

## Ordre conseillé

1. **Sécurité** : limitation des tentatives sur `/auth/login` (6.1) ; configuration de signature Android hors du dépôt et APK obsolète retiré (7.0) ; domaine et HTTPS.
2. Socle des intégrations (6.10-0), puis durcissement mobile et moteur hors-ligne (7.0).
3. Travaux rapides et peu risqués : 6.3, 6.6 (notes et export de l'aide), 6.7, 6.2, 6.8c (pages web).
4. Revenus et trésorerie : 6.10-d avec 1.11, puis 6.10-c et 6.10-a.
5. Terrain : 7.1, puis 7.3, puis 7.2.
6. 6.5 (assistant), 6.9 (CRM et webhooks), 6.10-b, 6.10-e, puis 6.4, 6.8d/e, 6.10-f.
