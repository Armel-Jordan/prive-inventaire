# TÂCHES PLANIFIÉES — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-18 — application des révisions de prix (`revisions_prix`) ; tableau complété (péremptions, écritures récurrentes, comptabilisation, rapports programmés). 2026-10-17 : loyers et renouvellement des contrats de location (`facturation_locations`, `renouvellement_contrats`) ; facturation des abonnements (`facturation_abonnements`) ; relances des devis (`relances_devis`). 2026-10-16 : alertes réglables (factures, commandes, livraisons, contrôles techniques) et écran **Réglage des alertes**
>
> Historique : 2026-10-15 — création (plan de parité fripv2, phase 0.10)

---

## 1. Ce que c'est

Des traitements automatiques prédéfinis par l'application. Chaque entreprise les active et règle leur fréquence dans **Paramètres → Tâches planifiées** (module de droits « Tâches planifiées » : administrateurs, et les groupes ou comptes qui en reçoivent les droits). **Toutes sont inactives par défaut** : tant qu'une entreprise n'en active pas, rien ne change pour elle.

| Tâche (`code`) | Par défaut | Ce qu'elle fait |
|---|---|---|
| `transferts_planifies` | Toutes les heures | Exécute les transferts planifiés arrivés à leur date, comme le bouton « Exécuter » (exécutant : « Tâche planifiée »), et crée une notification « Transfert terminé » |
| `transferts_imminents` | Chaque jour à 07:00 | Notifie les transferts prévus dans les 24 heures (une notification par transfert) |
| `alertes_stock` | Chaque jour à 07:00 | Notifie les produits sous leur seuil d'alerte (même calcul que l'écran Alertes stock) ; pas de nouvelle notification tant que la précédente n'est pas lue |
| `nettoyage_notifications` | Chaque jour à 02:00 | Supprime les notifications lues depuis plus de 30 jours |
| `factures_impayees` | Chaque jour à 07:00 | Notifie les factures émises ou partiellement payées dont il reste à payer, plus de **N jours** (30) après l'échéance (ou la date de facture, sans échéance) |
| `relances_paiement` | Chaque jour à 08:00 | Envoie le courriel de relance (modèle « relance », facture jointe) pour chaque facture en retard d'au moins **N jours** (15) dont la dernière relance date d'au moins N jours ; 3 relances automatiques au plus par facture, seulement aux clients qui ont une adresse de courriel (FLOW-VENTES §7 ter) |
| `relances_devis` | Chaque jour à 08:30 | Envoie le courriel « Relance de devis » (devis joint, boutons Accepter et Refuser) pour chaque devis envoyé resté sans réponse depuis au moins **N jours** (7), comptés depuis l'envoi ou la dernière relance, tant qu'il est valable ; 3 relances au plus par devis, seulement aux clients qui ont une adresse de courriel (FLOW-VENTES §3.8) |
| `facturation_abonnements` | Chaque jour à 06:00 | Crée les factures des échéances d'abonnement dues à ce jour : une par client et par date d'échéance, datée de l'échéance. En brouillon, ou **émises** si le paramètre `emettre` est coché (sauf une facture qui contient un abonnement marqué brouillon). Un prix ou un taux de change manquant est noté dans le résultat sans arrêter les autres factures ; numérotation automatique des factures requise (FLOW-VENTES §5.16) |
| `facturation_locations` | Chaque jour à 06:10 | Crée les factures des loyers dus à ce jour sur les contrats de location actifs : une par contrat et par période, datée de la facturation prévue ; la prochaine facturation avance d'une période, jusqu'à la fin du contrat. En brouillon, ou **émises** si le paramètre `emettre` est coché (FLOW-VENTES §5.17) |
| `renouvellement_contrats` | Chaque jour à 05:50 | Renouvelle les contrats de location actifs marqués « renouvellement automatique », pas encore renouvelés, arrivés à leur fin : contrat suivant pour la même durée, prix augmentés de leurs taux, facture brouillon des prix de contrat (FLOW-VENTES §5.17) |
| `commandes_fournisseur_en_retard` | Chaque jour à 07:00 | Notifie les commandes fournisseur envoyées ou partiellement reçues dont la livraison prévue est dépassée de plus de **N jours** (0 : dès le lendemain) |
| `livraisons_en_retard` | Chaque jour à 07:00 | Notifie les bons de livraison pas encore livrés (créés, en préparation, prêts, en livraison) plus de **N jours** (3) après leur création |
| `controles_techniques` | Chaque jour à 07:00 | Prévient **N jours** (30) avant l'expiration du contrôle technique d'un camion actif, et une fois la date dépassée |
| `peremptions` | Chaque jour à 07:00 | Prévient des lots dont il reste une quantité et qui périment dans les **N jours** (30), ou sont périmés ; le même délai règle les lots « à surveiller » du registre des lots |
| `ecritures_recurrentes` | Chaque jour à 05:30 | Crée les écritures récurrentes dont la date est arrivée (FLOW-COMPTABILITE) |
| `comptabilisation` | Chaque jour à 23:30 | Passe au journal les ventes, les achats et les paiements depuis la date de début réglée (FLOW-COMPTABILITE) |
| `rapports_programmes` | Chaque jour à 06:30 | Envoie les rapports programmés du jour (FLOW-RAPPORTS) ; activée au premier envoi programmé si personne ne l'a réglée |
| `revisions_prix` | Chaque jour à 00:05 | Applique les révisions de prix planifiées dont la date d'entrée en vigueur est atteinte, une seule fois, et notifie chacune (FLOW-PRIX §5) ; activée à la première révision planifiée si personne ne l'a réglée |

Ajouter une tâche : une classe dans `app/Taches` (interface `App\Taches\Tache`) et une ligne dans `App\Support\TachesPlanifiees::TACHES` ; ses paramètres réglables dans `PARAMETRES`, le module qu'elle suppose dans `MODULES`.

### Alertes réglables

Les tâches qui notifient (stock, transferts imminents, factures, commandes, livraisons, contrôles techniques) se règlent aussi dans **Paramètres → Réglage des alertes**, un écran plus simple : activer, régler le délai en jours, **Vérifier maintenant**, voir la fréquence et la dernière vérification. La fréquence, l'heure et l'historique restent dans les tâches planifiées. L'écran remplace une page factice (valeurs codées en dur, sauvegarde simulée), auparavant rangée sous Finance et donc invisible sans ce module.

- **Délai (N jours)** : paramètre `jours` de la tâche, entre 0 et 365. Seuls les paramètres prévus par le catalogue sont enregistrés ; les autres sont ignorés.
- **Factures émises** : paramètre `emettre` (oui ou non) de la facturation des abonnements et des loyers, une case dans le réglage de la tâche.
- **Modules** : une alerte n'est proposée que si l'entreprise a le module correspondant (Ventes pour les factures, les livraisons et les camions ; Achats pour les commandes fournisseur).
- **Pas de doublon** : une alerte n'est pas renvoyée tant que la notification précédente sur le même document n'est pas lue (`Notification::alerterUneFois`). Pour un contrôle technique, une nouvelle date donne lieu à une nouvelle alerte.
- Chaque notification mène à l'écran concerné (factures, commandes fournisseur, bons de livraison, camions).

---

## 2. Réglages

| Récurrence | Réglage |
|---|---|
| Toutes les heures | Minute de chaque heure |
| Chaque jour | Heure |
| Chaque semaine | Jours (lundi à dimanche) et heure |
| Chaque mois | Jour du mois (un mois plus court passe au dernier jour) et heure |
| Une seule fois | Date et heure ; la tâche se désactive après son exécution |

- Les heures s'entendent dans le fuseau des tâches : `TACHES_FUSEAU` (ex. `Africa/Douala`), sinon celui de l'application (UTC).
- L'écran montre, pour chaque tâche, la prochaine et la dernière exécution avec son résultat. **Exécuter maintenant** lance la tâche sans décaler son échéance.
- **Historique** : chaque exécution (planifiée ou manuelle, avec son auteur), sa durée, son résultat et son compte rendu, filtrable par tâche.

---

## 3. Déclenchement

1. **Planificateur (recommandé)** : la commande `taches:executer` est planifiée chaque minute (`AppServiceProvider`). Sur le serveur, une seule ligne de cron suffit :

   ```
   * * * * * cd /var/www/prise-api && php artisan schedule:run >> /dev/null 2>&1
   ```

   Une fois en place, mettre `TACHES_DECLENCHEMENT_REQUETES=false`.

2. **Sans planificateur** (situation actuelle de la production) : les tâches échues s'exécutent après la réponse d'une requête web, sans faire attendre l'utilisateur, au plus une fois par minute. Elles tournent donc tant que l'application est utilisée, avec une précision qui dépend de l'activité.

L'écran indique la dernière vérification des échéances et sa source (planificateur ou activité de l'application).

**Sûreté** :
- une tâche n'est jamais exécutée deux fois en même temps (prise en charge atomique) ;
- une exécution restée « en cours » plus d'une heure est considérée interrompue et peut repartir ;
- un échec est consigné dans l'historique et ne bloque pas les autres tâches ni les autres entreprises ;
- chaque tâche s'exécute dans le contexte de son entreprise.

---

## 4. Correctif lié

L'exécution d'un transfert planifié (bouton « Exécuter » et tâche automatique) ne renseignait pas `date_mouvement`, colonne obligatoire de `mouvement_relocalisation`. En MySQL strict, l'exécution échouait avec une erreur 500. Elle est maintenant renseignée à l'heure de l'exécution (`TransfertPlanifie::executer()`).

---

## 5. API

Droits (module « Tâches planifiées ») : **Consulter** pour la liste et l'historique, **Modifier** pour régler une tâche,
**Lancer une tâche tout de suite** pour l'exécution immédiate. Un administrateur a tout ; un autre compte, ce que son
rôle, ses groupes ou ses exceptions lui accordent (aucun par défaut).

| Route | Rôle |
|---|---|
| `GET /taches-planifiees` | Tâches de l'entreprise (créées inactives au premier accès), dernière vérification, fuseau |
| `PUT /taches-planifiees/{id}` | `actif`, `recurrence`, `heure`, `jours_semaine`, `jour_du_mois`, `prochaine_execution` (une seule fois), `parametres` (ex. `{"jours": 15}`) |
| `POST /taches-planifiees/{id}/executer` | Exécution immédiate (409 si déjà en cours) |
| `GET /taches-planifiees/historique?tache_id=&resultat=` | Historique paginé |

Chaque tâche renvoyée porte aussi `parametres` (valeurs par défaut comprises) et `module` (module requis, `null` si aucun).

Code : `App\Services\Planificateur`, `App\Support\TachesPlanifiees`, `app/Taches/*`, `App\Console\Commands\ExecuterTachesPlanifiees`, `config/taches.php`. Écrans : `TachesPlanifieesPage`, `NotificationsConfigPage` (réglage des alertes), aides communes dans `src/lib/taches.ts`.
