<?php

namespace App\Taches;

use App\Models\RapportProgramme;
use App\Rapports\Programmation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Envoie les rapports programmés du jour (phase 5.2), et ceux qu'un arrêt du serveur a fait manquer. Un envoi refusé
 * (le compte qui l'a réglé n'a plus les droits…) est noté sur sa programmation ; l'exécution est alors en erreur.
 */
class EnvoyerRapportsProgrammes implements Tache
{
    public function __construct(private readonly Programmation $programmation) {}

    public function executer(): string
    {
        $jour = now(config('taches.fuseau') ?: config('app.timezone'))->startOfDay();
        $dus = RapportProgramme::query()->where('actif', true)->whereNotNull('prochain_envoi')
            ->whereDate('prochain_envoi', '<=', $jour->toDateString())->orderBy('id')->get();
        [$envoyes, $refuses] = [0, []];

        foreach ($dus as $programme) {
            try {
                $this->programmation->envoyer($programme, $jour);
                $envoyes++;
            } catch (Throwable $erreur) {
                Log::warning("Rapport programmé {$programme->id} non envoyé : {$erreur->getMessage()}");
                $programme->derniere_erreur = Str::limit($erreur->getMessage(), 500, '');
                $refuses[] = "« {$programme->nom} » : {$programme->derniere_erreur}";
            }
            // Prochain envoi après aujourd'hui, même après un refus : il n'est pas retenté chaque jour.
            $programme->prochain_envoi = Programmation::prochainEnvoi($programme->frequence, $programme->jour, $jour);
            $programme->save();
        }

        if ($refuses) {
            throw new RuntimeException("{$envoyes} rapport(s) envoyé(s), ".count($refuses).' refusé(s) : '.implode(' ; ', $refuses));
        }

        return $envoyes === 0 ? 'Aucun rapport à envoyer.' : "{$envoyes} rapport(s) envoyé(s).";
    }
}
