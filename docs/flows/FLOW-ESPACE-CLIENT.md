# ESPACE CLIENT — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-17 — page publique de réponse aux devis (`/devis/<jeton>`, FLOW-VENTES §3.7). 2026-10-16 : l'espace client devient un site à part (`prise-inventaire-client`), distinct du back-office. La phase 1.11 du plan de parité fripv2 l'avait créé comme une section `/portail` du back-office.

---

## 1. Ce que c'est

Les clients des entreprises ont **leur propre site**, distinct du back-office des employés :

| Site | Adresse (droplet, sans domaine) | Pour qui |
|---|---|---|
| Back-office | `http://<IP>` (port 80) | Employés de l'entreprise |
| **Espace client** | `http://<IP>:8081` | Clients de l'entreprise |
| Site marketing | `http://<IP>:8080` | Visiteurs |

Avec un domaine, l'espace client prend un sous-domaine, par exemple `clients.<domaine>` (voir § 7).

| Écran | Adresse | Ce que fait le client |
|---|---|---|
| **Catalogue** | `/` | Parcourt les produits proposés, à **ses** prix (listes de prix, remises, règles), hors taxes ; recherche par nom ou code, filtre par catégorie |
| **Panier** | `/panier` | Ajuste les quantités (prix recalculés), choisit l'adresse et la date de livraison souhaitée, ajoute une remarque, passe la commande |
| **Mes commandes** | `/commandes`, `/commandes/<n°>` | Historique (commandes passées en ligne et commandes saisies par l'entreprise), détail, PDF, annulation tant qu'elle attend, « Commander à nouveau » |
| **Mes factures** | `/factures` | Factures émises, reste à payer, retard, PDF |
| **Mon profil** | `/profil` | Nom et téléphone, mot de passe ; coordonnées et adresses du client (en lecture) |

Le site porte le **nom et les coordonnées de l'entreprise**, jamais celui de l'application. Il existe en français et en anglais.

**Comptes séparés :** les accès de l'espace client n'ont rien à voir avec les comptes des employés. Un client ne peut ni ouvrir le back-office, ni voir les données d'un autre client.

L'espace client est **fermé** tant que l'entreprise ne l'ouvre pas : rien ne change pour une entreprise qui ne s'en sert pas.

**Réponse aux devis :** le site sert aussi la page `/devis/<jeton>`, ouverte par le lien du courriel d'un devis. Le client y consulte le devis, télécharge le PDF, puis l'accepte ou le refuse, sans compte et même si l'espace client n'est pas ouvert (FLOW-VENTES §3.7).

---

## 2. Quelle entreprise ?

Le même site sert les clients de toutes les entreprises. L'entreprise se désigne par son **code** (celui de la connexion au back-office). Le code est pris, dans cet ordre :

1. **Dans l'adresse :**
   - le lien de l'entreprise, `http://<IP>:8081/<code>`, ouvre sa page de connexion ;
   - le lien d'invitation, `…/activation?entreprise=<code>&jeton=…` ;
   - toute adresse en `?entreprise=<code>`.
2. **Dans la session** du client connecté.
3. **Celui de la dernière visite :** le client retrouve directement la page de connexion de son fournisseur.
4. **Sinon :** le client saisit le code de son fournisseur. Un code inconnu, ou celui d'un espace fermé, affiche « Espace client indisponible » avec **Changer d'entreprise**.

---

## 3. Mise en route (côté entreprise)

Écran **Ventes → Espace client** du back-office (`/espace-client`) :
- visible pour qui voit les clients (module Ventes) ;
- modifications réservées aux administrateurs et aux responsables.

1. **Prérequis : la numérotation automatique des commandes client** (Paramètres → Configuration). Sans elle, l'écran affiche un avertissement et l'espace client refuse les commandes avec un message au client.
2. **Réglages :**
   - ouvrir l'espace client ;
   - message d'accueil, affiché sur la page de connexion ;
   - produits proposés, par catégorie. Si aucune catégorie n'est cochée, tous les produits actifs sont proposés.
3. **Adresse de votre espace client :** `http://<IP>:8081/<code>`, à copier et à donner aux clients (site web, courriels, factures). Le bouton **Ouvrir** montre l'espace tel que le client le voit.
4. **Accès :** un accès par contact qui commandera.
   - On renseigne le client, le nom, le courriel, le téléphone et la langue du courriel. Le contact de la fiche client pré-remplit les champs vides.
   - Le contact reçoit un courriel avec un lien vers l'espace client pour choisir son mot de passe. Le courriel part avec le serveur d'envoi de l'entreprise (Paramètres → Configuration → Courriels).
   - Le lien s'affiche aussi à l'écran, avec un bouton **Copier**, pour le transmettre autrement si le courriel ne part pas.
   - Une adresse courriel n'a qu'un accès par entreprise (sans tenir compte des majuscules).

| État | Signification |
|---|---|
| **Invitation envoyée** | Le lien est parti, le mot de passe n'est pas encore choisi (avec sa date d'expiration) |
| **Actif** | Le contact a choisi son mot de passe (avec sa dernière connexion) |
| **Suspendu** | Le contact ne peut plus se connecter ; ses sessions sont fermées |

Actions sur un accès :
- **Renvoyer un lien** : invitation non reçue ou mot de passe à changer. Le lien précédent ne vaut plus.
- **Modifier** : nom, courriel, téléphone, suspension.
- **Supprimer** : le contact ne peut plus se connecter. Ses commandes restent dans l'historique du client.

---

## 4. Côté client

### 4.1 Connexion

- Courriel et mot de passe, sur la page de connexion de l'entreprise : son nom, son logo, son message d'accueil et ses coordonnées.
- **Lien d'invitation** (ou de nouveau mot de passe) :
  - le client choisit son mot de passe (8 caractères au moins, avec confirmation) et se retrouve connecté ;
  - ses autres sessions sont fermées.
- **Mot de passe oublié :**
  - la réponse est **toujours la même**, que l'adresse ait un accès ou non ;
  - un courriel avec un lien part si l'accès existe et n'est pas suspendu.
- Un lien expiré, déjà utilisé ou remplacé par un plus récent est refusé. L'écran propose d'en demander un nouveau.

### 4.2 Catalogue et prix

- Seuls les produits **actifs** sont proposés, restreints aux catégories choisies par l'entreprise s'il y en a.
- Le prix affiché vient du moteur de prix ([FLOW-PRIX.md](./FLOW-PRIX.md)) : celui du client pour une quantité de 1, hors taxes.
- Quand le client a une remise, le prix de liste est affiché barré à côté.
- Si aucun prix ne peut être calculé, le produit affiche **« Prix sur demande »** et ne se commande pas en ligne.

### 4.3 Panier et commande

1. Le panier est gardé dans le navigateur, par accès : il survit à une fermeture de l'onglet et à une session expirée.
2. Les prix sont **recalculés par le serveur** à chaque changement, ce qui applique les règles de quantité. Un prix envoyé par le navigateur n'est jamais repris.
3. Un produit qui n'est plus proposé est signalé. Il faut le retirer pour commander.
4. **Livraison :**
   - adresse : une adresse active du client, par défaut la principale de livraison, ou l'adresse habituelle du compte ;
   - date souhaitée : facultative, pas dans le passé ;
   - remarques : facultatives.
5. La commande est créée en statut **`en_attente`** :
   - **numéro :** celui de la numérotation des commandes client ;
   - **taxes :** taux par défaut de l'entreprise et groupe de taxes par défaut, comme pour une commande saisie ;
   - **remarques :** « Commande passée sur le portail client par <nom> (<courriel>). », puis « Livraison : <adresse> », puis les remarques du client. Elles figurent aussi sur le PDF de la commande ;
   - **notification :** l'entreprise reçoit « Nouvelle commande du portail client », avec un lien vers les commandes client.
6. L'entreprise l'**accepte** ou la **refuse** (avec un motif) dans Ventes → Commandes, comme toute commande. La suite est inchangée : facture, bon de livraison, tournée.

### 4.4 Suivi des commandes

| Statut affiché au client | Statut de la commande |
|---|---|
| En attente de confirmation | `en_attente` |
| Confirmée | `acceptee` |
| Refusée (avec le motif) | `refusee` |
| Facturée (avec la facture en PDF) | `facturee` |
| Annulée | `annulee` |

- Les brouillons de l'entreprise ne sont pas montrés.
- **Annuler :** possible tant que la commande est en attente, et seulement pour une commande passée en ligne. Une fois acceptée, il faut contacter l'entreprise.
- **Commander à nouveau :** les produits et quantités vont au panier, où les prix du jour sont repris.
- **Numéro de commande :** le client donne sa référence en commandant (obligatoire si sa fiche l'exige) ; elle est
  rappelée dans le détail et suit la commande sur la facture et le bon de livraison (FLOW-VENTES §2.10).
- **Expédition :** « Expédié par … », le numéro de suivi et le lien « Suivre l'envoi » du transporteur, d'après le
  dernier bon de livraison de la facture, sinon la facture, sinon la commande (FLOW-VENTES §2.8).

### 4.5 Factures

- Le client voit ses factures émises, partiellement payées ou payées. Les brouillons et les factures annulées ne sont pas montrés.
- Une facture est **« En retard »** quand son échéance est passée et qu'il reste une somme à payer.
- Chaque facture se télécharge en PDF : le même document que celui de l'entreprise.

### 4.6 Profil

- Le client modifie son **nom** et son **téléphone**. Le **courriel** est géré par l'entreprise.
- **Mot de passe :**
  - l'actuel est demandé pour le changer ;
  - les autres sessions sont alors fermées, la session courante reste ouverte.
- Coordonnées du client, adresse de facturation et adresses : en lecture. Pour les modifier, le client contacte l'entreprise.

---

## 5. Sécurité

**Sessions :**
- Jeton aléatoire de 64 caractères. La base n'en garde que l'empreinte sha256.
- Valable **30 jours**.
- Ces jetons sont distincts de ceux des employés : un jeton de l'espace client n'ouvre aucune route du back-office, et un jeton d'employé n'ouvre pas l'espace client.

**Cloisonnement :** commandes, factures et PDF sont limités au client de l'accès connecté. Un document d'un autre client répond 404.

**Accès coupé immédiatement :**
- la session est refusée (401) si :
  - l'accès est suspendu ou supprimé ;
  - le client est désactivé ;
  - l'entreprise est désactivée, expirée ou sans le module Ventes ;
- espace fermé entre-temps : 403, l'écran l'indique.

**Liens d'invitation et de mot de passe oublié :**
- La base n'en garde que l'empreinte sha256.
- Valables **7 jours**, à usage unique ; un nouveau lien annule le précédent.
- **Adresse du lien**, dans cet ordre :
  1. `ESPACE_CLIENT_URL` (`.env` de l'API) ;
  2. sinon, l'adresse que le back-office de l'entreprise transmet à chaque enregistrement des réglages et à chaque invitation (champ `portail_parametres.url_espace_client`) ;
  3. sinon `FRONTEND_URL`, puis `APP_URL`.

  Une adresse envoyée par un visiteur anonyme (mot de passe oublié) n'est **jamais** reprise : elle permettrait d'envoyer un vrai lien vers un faux site.

**Limites par minute :**

| Action | Maximum par minute |
|---|---|
| Connexion | 10 |
| Choix du mot de passe | 10 |
| Mot de passe oublié | 5 |
| Commandes | 20 |

**Mot de passe oublié :** le courriel part **après la réponse**. Le temps de réponse ne trahit donc pas l'existence d'un accès.

---

## 6. API

Les routes de l'API gardent le préfixe `portail` : il n'apparaît dans aucune adresse vue par les clients.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/portail-clients` | Réglages, accès, catégories disponibles, numérotation prête (module Ventes) |
| PUT | `/portail-clients/parametres` | Ouvrir ou fermer, message d'accueil, catégories, adresse de l'espace client (`origine`) (admin, manager) |
| POST | `/portail-clients/comptes` | Nouvel accès et lien d'invitation (admin, manager) |
| PUT | `/portail-clients/comptes/{id}` | Modifier ou suspendre un accès (admin, manager) |
| POST | `/portail-clients/comptes/{id}/invitation` | Nouveau lien (admin, manager) |
| DELETE | `/portail-clients/comptes/{id}` | Supprimer un accès (admin, manager) |
| GET | `/portail/{code}` | Page de connexion : entreprise, coordonnées, message d'accueil (404 si l'espace n'est pas ouvert) |
| POST | `/portail/{code}/connexion` | Connexion |
| POST | `/portail/{code}/activation` | Mot de passe choisi depuis un lien |
| GET, POST | `/devis-en-ligne/{jeton}`, `/pdf`, `/accepter`, `/refuser` | Réponse à un devis par son lien, sans session (FLOW-VENTES §3.7) |
| POST | `/portail/{code}/mot-de-passe-oublie` | Demande de lien |
| GET, PUT | `/espace-client/moi` | Accès, client (adresses), entreprise ; nom et téléphone |
| PUT | `/espace-client/moi/mot-de-passe` | Changement de mot de passe |
| POST | `/espace-client/deconnexion` | Fermeture de la session |
| GET | `/espace-client/catalogue` | Catalogue au prix du client (`recherche`, `categorie`, `page`) |
| POST | `/espace-client/prix` | Prix du panier |
| GET, POST | `/espace-client/commandes` | Historique ; nouvelle commande |
| GET | `/espace-client/commandes/{id}` | Détail (lignes, facture, annulable, expédition) |
| POST | `/espace-client/commandes/{id}/annuler` | Annulation |
| GET | `/espace-client/commandes/{id}/pdf` | PDF de la commande |
| GET | `/espace-client/factures` | Factures |
| GET | `/espace-client/factures/{id}/pdf` | PDF d'une facture |

Les routes `/espace-client/*` attendent le jeton de l'espace client (`Authorization: Bearer …`), sans code d'entreprise : le jeton désigne l'entreprise.

---

## 7. Installation et réglages

### 7.1 Code

| Partie | Où |
|---|---|
| Site de l'espace client | `prise-inventaire-client/` (React, Vite, Tailwind), indépendant du back-office |
| Écran de l'entreprise | `prise-inventaire-web/src/pages/PortailClientsPage.tsx` |
| API | `app/Http/Controllers/Portail/*`, `app/Http/Controllers/Api/PortailClientController.php`, `app/Services/Portail/*` |

### 7.2 Déploiement (droplet)

Le workflow `.github/workflows/client-cd.yml` se déclenche sur un push dans `main` touchant `prise-inventaire-client/**`. Il :
1. construit le site : `/api` en même origine ;
2. le copie dans `/var/www/prise-client` ;
3. installe ou met à jour le site nginx `deploy/nginx-espace-client.conf` sur le **port 8081** (`/etc/nginx/sites-available/prise-espace-client`). Une configuration refusée par `nginx -t` est retirée ;
4. ouvre le port 8081 si le pare-feu `ufw` est actif.

Un pare-feu DigitalOcean (dans le panneau) doit laisser passer le port 8081.

nginx transmet `/api` à Laravel sur ce port aussi : le navigateur reste en même origine, sans CORS.

### 7.3 Réglages

| Réglage | Où | Effet |
|---|---|---|
| `VITE_ESPACE_CLIENT_URL` | build du back-office | Adresse de l'espace client affichée et transmise par l'écran Ventes → Espace client. Vide : même hôte que le back-office, port 8081 |
| `ESPACE_CLIENT_URL` | `.env` de l'API | Impose l'adresse des liens des courriels (utile avec un domaine) |
| `VITE_API_URL` | build de l'espace client | `/api` en production (même origine) |
| Envoi des courriels | Paramètres → Configuration → Courriels, sinon `MAIL_*` | Si l'envoi échoue, l'écran de l'entreprise donne le lien à transmettre |

**Avec un domaine :**
1. Dans `deploy/nginx-espace-client.conf`, `listen 80` (puis 443), et `server_name clients.<domaine>`.
2. `VITE_ESPACE_CLIENT_URL=https://clients.<domaine>` au build du back-office.
3. `ESPACE_CLIENT_URL=https://clients.<domaine>` dans le `.env` de l'API.

### 7.4 En local

```bash
cd prise-inventaire-client
VITE_API_URL=http://127.0.0.1:8000/api npx vite --port 5175
```

Côté back-office, lancer avec `VITE_ESPACE_CLIENT_URL=http://127.0.0.1:5175` pour que l'adresse affichée et les liens pointent vers ce site.

---

## 8. Données

| Table | Contenu |
|---|---|
| `portail_parametres` | Par entreprise : ouvert ou non, message d'accueil, catégories proposées, adresse de l'espace client (`url_espace_client`) |
| `portail_comptes` | Accès : client, nom, courriel (unique par entreprise), téléphone, mot de passe, actif, lien d'invitation en cours, dernière connexion |
| `portail_jetons` | Sessions ouvertes (empreinte, expiration, dernière utilisation) |
| `com_client_entete.portail_compte_id` | Accès qui a passé la commande (vide pour une commande saisie par l'entreprise) |

---

## 9. Limites connues

- Pas de paiement en ligne : le client voit ce qu'il doit, le règlement suit le circuit habituel.
- Les adresses de livraison sont gérées par l'entreprise, depuis la fiche client.
- Les messages d'erreur métier renvoyés par l'API (rares côté client) sont en français, comme dans le reste de l'application.
