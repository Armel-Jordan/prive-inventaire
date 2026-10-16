# PIÈCES JOINTES — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-15 — création (plan de parité fripv2, phase 0.7)

---

## 1. Ce que c'est

Des fichiers (contrat signé, photo, bon de livraison signé, fiche technique…) rattachés à une **fiche** ou à un **document** :

| Entité (`entite`) | Écran | Module requis |
|---|---|---|
| `client` | Clients | ventes |
| `fournisseur` | Fournisseurs | achats |
| `produit` | Produits | — |
| `employe` | Employés | — |
| `devis` | Devis | ventes |
| `commande_client` | Commandes client | ventes |
| `facture` | Factures | ventes |
| `bon_livraison` | Bons de livraison | ventes |
| `commande_fournisseur` | Commandes fournisseur (réceptions comprises) | achats |

Ouvrir les pièces jointes d'un nouvel écran : ajouter une ligne au catalogue `App\Support\PiecesJointes::ENTITES`, puis le bouton `BoutonPiecesJointes` dans la page.

---

## 2. Utilisation

**Depuis une fiche ou un document** : le trombone de la ligne ouvre ses pièces jointes.

1. **Ajouter** : cliquer sur la zone (sélection de plusieurs fichiers), y glisser-déposer des fichiers, ou coller une image (capture d'écran) avec Ctrl + V. Une image collée prend le nom « Image collée AAAA-MM-JJ HHMMSS.png ».
2. Pour chaque fichier, une description facultative ; pour l'envoi, une **catégorie** commune (texte libre, avec les catégories déjà utilisées en suggestion : Contrats, Photos…).
3. **Enregistrer** : jusqu'à 10 fichiers à la fois.
4. Cliquer sur un nom de fichier l'ouvre dans un nouvel onglet (PDF, images, texte) ou le télécharge (autres types).
5. Crayon : modifier la description ou la catégorie. Corbeille : supprimer la pièce et son fichier.

**Paramètres → Pièces jointes** : toutes les pièces de l'entreprise, avec la fiche ou le document de rattachement. Recherche dans le nom du fichier, la description et la catégorie ; filtre par type de fiche ; suppression.

---

## 3. Stockage

| Réglage (`.env`) | Défaut | Rôle |
|---|---|---|
| `PIECES_JOINTES_DISQUE` | `local` | Disque de `config/filesystems.php` : `local` = `storage/app/private`, non servi par le web |
| `PIECES_JOINTES_TAILLE_MAX_KO` | `20480` | Taille maximale d'un fichier (20 Mo) |

**Limites du serveur** : nginx (`client_max_body_size`, 1 Mo par défaut) et PHP (`upload_max_filesize`, 2 Mo par défaut ; `post_max_size`) refusent un envoi trop gros avant l'application, avec une erreur 413 que l'écran explique. Pour 10 fichiers de 20 Mo : `client_max_body_size 210m`, `upload_max_filesize = 20M`, `post_max_size = 210M`.

- Rangement : `pieces-jointes/{tenant}/{entite}/{id}/{uuid}.{extension}`. Le nom d'origine n'entre jamais dans le chemin ; il est conservé en base (`pieces_jointes.nom_original`) et redonné au téléchargement.
- **S3** : installer `league/flysystem-aws-s3-v3`, renseigner les variables `AWS_*`, puis `PIECES_JOINTES_DISQUE=s3`. Les pièces déjà enregistrées gardent leur disque d'origine (colonne `disque`).
- Sauvegardes : `storage/app/private/pieces-jointes` est à inclure dans la sauvegarde du serveur (fichiers hors base de données).

---

## 4. Sécurité

- Fichiers **téléchargés par l'API** (jeton dans l'en-tête), jamais exposés par une URL publique.
- Extensions refusées : exécutables et scripts (`exe`, `bat`, `cmd`, `msi`, `ps1`, `js`, `sh`, `php`, `iso`…), liste dans `config/pieces_jointes.php`.
- Seuls les PDF, images (PNG, JPEG, GIF, WebP) et textes gardent leur type au téléchargement ; tout le reste (HTML, SVG…) part en `application/octet-stream` avec `X-Content-Type-Options: nosniff`, et l'application le télécharge au lieu de l'ouvrir.
- Fiche d'une autre entreprise : 404. Module de la fiche inactif : 403, et ses pièces sont masquées de la liste globale.
- Un envoi qui échoue en cours de route ne laisse aucun fichier orphelin.

---

## 5. API

| Route | Rôle |
|---|---|
| `GET /pieces-jointes?entite=&entite_id=&categorie=&recherche=` | Pièces d'une fiche, ou de toute l'entreprise (paginé, avec `libelle_entite`) |
| `GET /pieces-jointes/categories?entite=` | Catégories déjà utilisées |
| `POST /pieces-jointes` (multipart) | `entite`, `entite_id`, `fichiers[]`, `descriptions[]` ou `description`, `categorie` |
| `GET /pieces-jointes/{id}/fichier` | Téléchargement du fichier d'origine |
| `PUT /pieces-jointes/{id}` | Description, catégorie |
| `DELETE /pieces-jointes/{id}` | Suppression de la pièce et du fichier |

Code : `App\Http\Controllers\Api\PieceJointeController`, `App\Models\PieceJointe`, `App\Support\PiecesJointes`, `config/pieces_jointes.php` ; front `PiecesJointes.tsx`, `BoutonPiecesJointes.tsx`, `PiecesJointesPage.tsx`.
