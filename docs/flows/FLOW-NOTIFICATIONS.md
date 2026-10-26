# Flow — Notifications

> Anomalie A4 de `docs/17-RESTE-A-FAIRE.md`, corrigée le 26/10/2026.

La cloche de l'en-tête et l'écran **Notifications** montrent les avis de l'application : alertes de stock, transferts,
approbations, réponses aux devis, commandes de l'espace client, révisions de prix, retards, péremptions, réponses du
support…

## Qui voit quoi

Chaque compte voit :
- les notifications de **toute l'entreprise** (`admin_user_id` vide) dont il peut voir le module ;
- les notifications qui le **visent** (`admin_user_id` = son compte), par exemple la réponse de l'éditeur à sa demande
  de support.

Le module de chaque type est donné par `Notification::DROITS` ; il faut son droit « Consulter » :

| Type | Module |
|---|---|
| `alerte_stock` | Alertes de stock |
| `transfert_complete`, `planification_imminente` | Planification des transferts |
| `approbation_requise` | Approbations |
| `revision_prix` | Tarifs et prix |
| `commande_portail` | Commandes client |
| `devis_accepte`, `devis_refuse` | Devis |
| `ct_expire` | Camions |
| `commande_retard` | Commandes fournisseur |
| `peremption` | Lots et numéros de série |
| `facture_impayee` | Factures |
| `livraison_retard` | Bons de livraison |

Un type absent de cette liste est montré à tous les comptes de l'entreprise. Un administrateur voit tout.

## Lue, supprimée : pour soi seul

- **Lue** : ouvrir une notification, ou « Tout marquer comme lu », la marque lue pour ce compte seulement
  (`notification_lectures`). Les autres comptes la voient encore comme nouvelle.
- **Supprimer** (corbeille) : la notification disparaît de la cloche de ce compte seulement.
- Le nombre de non lues (cloche, tableau de bord) est celui du compte.
- La colonne `notifications.lu` signifie « lue par au moins un compte ». Elle sert à ne pas répéter une alerte tant
  qu'elle n'a été lue par personne, et au nettoyage des notifications lues anciennes. Une notification déjà lue avant
  ce changement (sans lecture enregistrée) compte comme lue pour tous.

## API

| Route | Rôle |
|---|---|
| `GET /notifications?non_lues=1&type=&limit=` | Notifications du compte ; `lu` est la lecture du compte |
| `GET /notifications/unread-count` | Non lues du compte |
| `POST /notifications/{id}/read` | Lue pour le compte (404 si le compte ne la voit pas) |
| `POST /notifications/read-all` | Toutes lues pour le compte |
| `DELETE /notifications/{id}` | Masquée pour le compte |

Code : `App\Services\NotificationsCompte`, `App\Models\Notification` (`DROITS`), `App\Models\NotificationLecture`,
`App\Http\Controllers\NotificationController` ; migration `2026_10_26_100091_notifications_par_compte`.
