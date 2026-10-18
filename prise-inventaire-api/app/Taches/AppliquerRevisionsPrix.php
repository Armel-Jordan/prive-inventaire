<?php

namespace App\Taches;

use App\Models\Notification;
use App\Models\RevisionPrix;
use App\Services\RevisionsPrix;
use RuntimeException;
use Throwable;

/**
 * Applique les révisions de prix planifiées dont la date d'entrée en vigueur est arrivée (phase 1.2d), et celles qu'un
 * arrêt du serveur a fait manquer ; une révision ne s'applique qu'une fois. Chaque révision appliquée est notifiée.
 */
class AppliquerRevisionsPrix implements Tache
{
    public function __construct(private readonly RevisionsPrix $revisions) {}

    public function executer(): string
    {
        $jour = now(config('taches.fuseau') ?: config('app.timezone'))->toDateString();
        $dues = RevisionPrix::query()->where('statut', RevisionPrix::PLANIFIEE)->whereNotNull('date_effet')
            ->whereDate('date_effet', '<=', $jour)->orderBy('date_effet')->orderBy('id')->get();
        [$appliquees, $prix, $echecs] = [0, 0, []];

        foreach ($dues as $revision) {
            try {
                $nombre = $this->revisions->appliquer($revision, null);
                $appliquees++;
                $prix += $nombre;
                Notification::create([
                    'type' => 'revision_prix',
                    'titre' => 'Révision de prix appliquée',
                    'message' => "La révision « {$revision->nom} » est entrée en vigueur : {$nombre} prix mis à jour.",
                    'lien' => '/gestion-prix?onglet=revisions',
                    'data' => ['revision_prix_id' => $revision->id],
                ]);
            } catch (Throwable $erreur) {
                $echecs[] = "« {$revision->nom} » : {$erreur->getMessage()}";
            }
        }

        $compteRendu = $appliquees === 0 && ! $echecs ? 'Aucune révision de prix à appliquer.' : "{$appliquees} révision(s) appliquée(s), {$prix} prix mis à jour.";
        if ($echecs) {
            throw new RuntimeException($compteRendu.' En échec : '.implode(' ; ', $echecs));
        }

        return $compteRendu;
    }
}
