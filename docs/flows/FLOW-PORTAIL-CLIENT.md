# PORTAIL CLIENT — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-16 — création (plan de parité fripv2, phase 1.11)

---

## 1. Ce que c'est

Les clients d'une entreprise ont leur propre espace, à l'adresse **`<site>/portail/<code de l'entreprise>`** :

| Écran | Ce que fait le client |
|---|---|
| **Catalogue** | Parcourt les produits proposés, à **ses** prix (listes de prix, remises, règles), hors taxes ; recherche par nom ou code, filtre par catégorie |
| **Panier** | Ajuste les quantités (prix recalculés), choisit l'adresse et la date de livraison souhaitée, ajoute une remarque, passe la commande |
| **Mes commandes** | Historique (commandes passées en ligne et commandes saisies par l'entreprise), détail, PDF, annulation tant qu'elle attend, « Commander à nouveau » |
| **Mes factures** | Factures émises, reste à payer, retard, PDF |
| **Mon profil** | Nom et téléphone, mot de passe ; coordonnées et adresses du client (en lecture) |

Le portail porte le **nom et les coordonnées de l'entreprise**, pas celui de l'application. Il existe en français et en anglais (sélecteur de langue).

**Comptes séparés :** les accès du portail n'ont rien à voir avec les comptes des employés. Un client ne peut ni ouvrir le back-office, ni voir les données d'un autre client.

Le portail est **fermé** tant que l'entreprise ne l'ouvre pas : rien ne change pour une entreprise qui ne s'en sert pas.

---

## 2. Mise en route (côté entreprise)

Écran **Ventes → Portail clients** :
- visible pour qui voit les clients (module Ventes) ;
- modifications réservées aux administrateurs et aux responsables.

1. **Prérequis : la numérotation automatique des commandes client** (Paramètres → Configuration). Sans elle, l'écran affiche un avertissement et le portail refuse les commandes avec un message au client.
2. **Réglages :**
   - ouvrir le portail ;
   - message d'accueil, affiché sur la page de connexion ;
   - produits proposés, par catégorie. Si aucune catégorie n'est cochée, tous les produits actifs sont proposés.
3. **Adresse du portail :** à copier et à donner aux clients (site, courriels, factures). Le bouton **Ouvrir** montre le portail tel que le client le voit.
4. **Accès :** un accès par contact qui commandera.
   - On renseigne le client, le nom, le courriel, le téléphone et la langue du courriel. Le contact de la fiche client pré-remplit les champs vides.
   - Le contact reçoit un courriel avec un lien pour choisir son mot de passe. Le courriel part avec le serveur d'envoi de l'entreprise (Paramètres → Configuration → Courriels).
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

## 3. Côté client

### 3.1 Connexion

- Courriel et mot de passe. La page montre le message d'accueil et les coordonnées de l'entreprise.
- **Lien d'invitation** (ou de nouveau mot de passe) :
  - le client choisit son mot de passe (8 caractères au moins, avec confirmation) et se retrouve connecté ;
  - ses autres sessions sont fermées.
- **Mot de passe oublié :**
  - la réponse est **toujours la même**, que l'adresse ait un accès ou non ;
  - un courriel avec un lien part si l'accès existe et n'est pas suspendu.
- Un lien expiré, déjà utilisé ou remplacé par un plus récent est refusé. L'écran propose d'en demander un nouveau.

### 3.2 Catalogue et prix

- Seuls les produits **actifs** sont proposés, restreints aux catégories choisies par l'entreprise s'il y en a.
- Le prix affiché vient du moteur de prix ([FLOW-PRIX.md](./FLOW-PRIX.md)) : celui du client pour une quantité de 1, hors taxes.
- Quand le client a une remise, le prix de liste est affiché barré à côté.
- Si aucun prix ne peut être calculé, le produit affiche **« Prix sur demande »** et ne se commande pas en ligne.

### 3.3 Panier et commande

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

### 3.4 Suivi des commandes

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

### 3.5 Factures

- Le client voit ses factures émises, partiellement payées ou payées. Les brouillons et les factures annulées ne sont pas montrés.
- Une facture est **« En retard »** quand son échéance est passée et qu'il reste une somme à payer.
- Chaque facture se télécharge en PDF : le même document que celui de l'entreprise.

### 3.6 Profil

- Le client modifie son **nom** et son **téléphone**. Le **courriel** est géré par l'entreprise.
- **Mot de passe :**
  - l'actuel est demandé pour le changer ;
  - les autres sessions sont alors fermées, la session courante reste ouverte.
- Coordonnées du client, adresse de facturation et adresses : en lecture. Pour les modifier, le client contacte l'entreprise.

---

## 4. Sécurité

**Sessions :**
- Jeton aléatoire de 64 caractères. La base n'en garde que l'empreinte sha256.
- Valable **30 jours**.
- Ces jetons sont distincts de ceux des employés : un jeton du portail n'ouvre aucune route du back-office, et un jeton d'employé n'ouvre pas l'espace client.

**Cloisonnement :** commandes, factures et PDF sont limités au client de l'accès connecté. Un document d'un autre client répond 404.

**Accès coupé immédiatement :**
- la session est refusée (401) si :
  - l'accès est suspendu ou supprimé ;
  - le client est désactivé ;
  - l'entreprise est désactivée, expirée ou sans le module Ventes ;
- portail fermé entre-temps : 403, l'écran l'indique.

**Liens d'invitation et de mot de passe oublié :**
- La base n'en garde que l'empreinte sha256.
- Valables **7 jours**, à usage unique ; un nouveau lien annule le précédent.
- Adresse du lien :
  - l'origine du site est reprise seulement si elle figure dans `CORS_ALLOWED_ORIGINS` ;
  - sinon `FRONTEND_URL` ;
  - sinon `APP_URL`.

  Une origine quelconque n'est jamais reprise.

**Limites par minute :**

| Action | Maximum par minute |
|---|---|
| Connexion | 10 |
| Choix du mot de passe | 10 |
| Mot de passe oublié | 5 |
| Commandes | 20 |

**Mot de passe oublié :** le courriel part **après la réponse**. Le temps de réponse ne trahit donc pas l'existence d'un accès.

---

## 5. API

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/portail-clients` | Réglages, accès, catégories disponibles, numérotation prête (module Ventes) |
| PUT | `/portail-clients/parametres` | Ouvrir ou fermer, message d'accueil, catégories (admin, manager) |
| POST | `/portail-clients/comptes` | Nouvel accès et lien d'invitation (admin, manager) |
| PUT | `/portail-clients/comptes/{id}` | Modifier ou suspendre un accès (admin, manager) |
| POST | `/portail-clients/comptes/{id}/invitation` | Nouveau lien (admin, manager) |
| DELETE | `/portail-clients/comptes/{id}` | Supprimer un accès (admin, manager) |
| GET | `/portail/{code}` | Page de connexion : entreprise, coordonnées, message d'accueil (404 si le portail n'est pas ouvert) |
| POST | `/portail/{code}/connexion` | Connexion |
| POST | `/portail/{code}/activation` | Mot de passe choisi depuis un lien |
| POST | `/portail/{code}/mot-de-passe-oublie` | Demande de lien |
| GET, PUT | `/espace-client/moi` | Accès, client (adresses), entreprise ; nom et téléphone |
| PUT | `/espace-client/moi/mot-de-passe` | Changement de mot de passe |
| POST | `/espace-client/deconnexion` | Fermeture de la session |
| GET | `/espace-client/catalogue` | Catalogue au prix du client (`recherche`, `categorie`, `page`) |
| POST | `/espace-client/prix` | Prix du panier |
| GET, POST | `/espace-client/commandes` | Historique ; nouvelle commande |
| GET | `/espace-client/commandes/{id}` | Détail (lignes, facture, annulable) |
| POST | `/espace-client/commandes/{id}/annuler` | Annulation |
| GET | `/espace-client/commandes/{id}/pdf` | PDF de la commande |
| GET | `/espace-client/factures` | Factures |
| GET | `/espace-client/factures/{id}/pdf` | PDF d'une facture |

Les routes `/espace-client/*` attendent le jeton du portail (`Authorization: Bearer …`), sans code d'entreprise : le jeton désigne l'entreprise.

---

## 6. Données

| Table | Contenu |
|---|---|
| `portail_parametres` | Par entreprise : ouvert ou non, message d'accueil, catégories proposées |
| `portail_comptes` | Accès : client, nom, courriel (unique par entreprise), téléphone, mot de passe, actif, lien d'invitation en cours, dernière connexion |
| `portail_jetons` | Sessions ouvertes (empreinte, expiration, dernière utilisation) |
| `com_client_entete.portail_compte_id` | Accès qui a passé la commande (vide pour une commande saisie par l'entreprise) |

---

## 7. Réglages du serveur

- **`FRONTEND_URL`** et/ou **`CORS_ALLOWED_ORIGINS`** : l'adresse du site, pour que les liens des courriels pointent au bon endroit.
- **Envoi des courriels :** serveur d'envoi de l'entreprise (Paramètres → Configuration → Courriels), sinon la configuration `MAIL_*` du serveur. Si l'envoi échoue, l'écran de l'entreprise donne le lien à transmettre.
- Le site doit renvoyer `index.html` pour toute adresse `/portail/...` : c'est déjà le cas pour les autres pages de l'application.

---

## 8. Limites connues

- Pas de paiement en ligne : le client voit ce qu'il doit, le règlement suit le circuit habituel.
- Les adresses de livraison sont gérées par l'entreprise, depuis la fiche client.
- Les messages d'erreur métier renvoyés par l'API (rares côté client) sont en français, comme dans le reste de l'application.
