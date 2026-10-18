# Flow — Support et suggestions

> Plan de parité fripv2, phase 6.7 (« Support, Suggestions »).

Un compte écrit à l'**éditeur du logiciel** : une **demande d'aide** (un problème, une question) ou une
**suggestion**. Il suit ensuite les réponses et l'avancement. L'éditeur (super-administrateur) voit les demandes de
toutes les entreprises et y répond.

## Côté entreprise (`/support`)

- **Ouvrir** : bouton bouée de l'en-tête, depuis n'importe quel écran. La nouvelle demande retient l'écran d'où elle
  part (« Envoyée depuis l'écran /scans »).
- **Nouvelle demande** :
  - type (aide ou suggestion) ;
  - sujet ;
  - description : ce qui se passe, ou l'idée ;
  - priorité, pour une demande d'aide : basse, normale, haute, urgente.
- **Liste** : les demandes, la plus récemment active d'abord, filtrées par statut. Celles où l'éditeur attend une
  réponse le signalent.
- **Détail** : la description, l'échange de messages (usager, éditeur), la réponse et la fermeture. Répondre à une
  demande résolue ou fermée la rouvre ; répondre à une demande « en attente de l'usager » la repasse en cours.
- **Notification** : une réponse de l'éditeur, ou la résolution d'une demande, arrive dans les notifications de
  l'entreprise, avec le lien de la demande (`/support?demande=12`).

### Statuts

| Statut | Sens |
|---|---|
| Ouverte | Nouvelle, ou rouverte par l'usager |
| En cours | L'éditeur la traite ; l'usager vient de répondre |
| En attente de l'usager | L'éditeur a répondu et attend l'usager (statut par défaut après une réponse de l'éditeur) |
| Résolue | Réglée par l'éditeur (date de résolution) |
| Fermée | Close par l'usager ou l'éditeur |

### Droits (module « Support et suggestions »)

- **Créer** : écrire à l'éditeur, suivre et faire avancer **ses** demandes. Tout compte qui voit le tableau de bord
  l'a au départ.
- **Voir** : ouvrir **toutes** les demandes de l'entreprise. Ce droit suit au départ celui de voir la configuration :
  administrateurs et responsables.

## Côté éditeur (`/super-admin/support`)

- Bouton « Support » du tableau de bord du super-administrateur.
- **Liste** : « À traiter » (ni résolues ni fermées), ou par statut avec le nombre de chaque. Chaque demande montre son
  entreprise, son auteur, sa priorité et sa dernière activité.
- **Détail** :
  - l'entreprise, l'auteur et son courriel, l'écran d'origine ;
  - le changement de statut et de priorité ;
  - l'échange, et la réponse avec le statut qui suit.
- **Courriel à l'éditeur** : à chaque nouvelle demande et à chaque réponse d'un usager, si l'adresse `SUPPORT_EMAIL`
  est réglée sur le serveur. Le courriel répond directement à l'usager.

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/support?statut=&type=` | Demandes du compte (toutes celles de l'entreprise avec le droit de voir) |
| POST | `/support` | `type`, `sujet`, `description`, `priorite`, `ecran` |
| GET | `/support/{id}` | Détail et messages |
| POST | `/support/{id}/messages` | Réponse de l'usager |
| POST | `/support/{id}/fermer` | Fermeture |
| GET | `/super-admin/support?statut=&type=&tenant_id=&actives=1` | Toutes les demandes, nombres par statut |
| GET | `/super-admin/support/{id}` | Détail |
| POST | `/super-admin/support/{id}/messages` | Réponse de l'éditeur (`message`, `statut` après) |
| PUT | `/super-admin/support/{id}` | `statut`, `priorite` |

Code :
- API : `SupportController`, `SuperAdminSupportController`, service `App\Services\Support\DemandesSupport`, modèles
  `DemandeSupport` et `DemandeSupportMessage`, `config/support.php` ;
- migration `2026_10_17_100085_creer_demandes_support` (tables et module de droits « support ») ;
- web : `SupportPage`, `SuperAdminSupportPage`, `services/support.ts`, bouton de l'en-tête dans `Layout`, traductions
  `support` ;
- tests : `tests/Feature/Support/DemandesSupportTest.php`.
