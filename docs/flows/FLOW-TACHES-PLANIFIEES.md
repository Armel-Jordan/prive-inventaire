# TÂCHES PLANIFIÉES — Flow complet

> **Document évolutif.** Mis à jour à chaque modification du code.
> Dernière mise à jour : 2026-10-15 — création (plan de parité fripv2, phase 0.10)

---

## 1. Ce que c'est

Des traitements automatiques prédéfinis par l'application. Chaque entreprise les active et règle leur fréquence dans **Paramètres → Tâches planifiées** (administrateurs). **Toutes sont inactives par défaut** : tant qu'une entreprise n'en active pas, rien ne change pour elle.

| Tâche (`code`) | Par défaut | Ce qu'elle fait |
|---|---|---|
| `transferts_planifies` | Toutes les heures | Exécute les transferts planifiés arrivés à leur date, comme le bouton « Exécuter » (exécutant : « Tâche planifiée »), et crée une notification « Transfert terminé » |
| `transferts_imminents` | Chaque jour à 07:00 | Notifie les transferts prévus dans les 24 heures (une notification par transfert) |
| `alertes_stock` | Chaque jour à 07:00 | Notifie les produits sous leur seuil d'alerte (même calcul que l'écran Alertes stock) ; pas de nouvelle notification tant que la précédente n'est pas lue |
| `nettoyage_notifications` | Chaque jour à 02:00 | Supprime les notifications lues depuis plus de 30 jours |

Ajouter une tâche : une classe dans `app/Taches` (interface `App\Taches\Tache`) et une ligne dans `App\Support\TachesPlanifiees::TACHES`.

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

## 5. API (administrateurs)

| Route | Rôle |
|---|---|
| `GET /taches-planifiees` | Tâches de l'entreprise (créées inactives au premier accès), dernière vérification, fuseau |
| `PUT /taches-planifiees/{id}` | `actif`, `recurrence`, `heure`, `jours_semaine`, `jour_du_mois`, `prochaine_execution` (une seule fois) |
| `POST /taches-planifiees/{id}/executer` | Exécution immédiate (409 si déjà en cours) |
| `GET /taches-planifiees/historique?tache_id=&resultat=` | Historique paginé |

Code : `App\Services\Planificateur`, `App\Support\TachesPlanifiees`, `app/Taches/*`, `App\Console\Commands\ExecuterTachesPlanifiees`, `config/taches.php`.
