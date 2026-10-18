<?php

namespace App\Rapports;

use App\Jobs\EnvoyerCourriel;
use App\Models\AdminUser;
use App\Models\CourrielEnvoi;
use App\Models\RapportProgramme;
use App\Models\Tenant;
use App\Services\Courriels\EnvoiCourriel;
use App\Support\Droits;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use RuntimeException;

/**
 * Envois programmés des rapports (plan de parité fripv2, phase 5.2) : périodes relatives (veille, mois précédent…),
 * date du prochain envoi selon la fréquence, et l'envoi lui-même avec les droits du compte qui l'a réglé.
 */
final class Programmation
{
    /** Périodes relatives, calculées au jour de l'envoi. */
    public const PERIODES = [
        'veille' => ['fr' => 'La veille', 'en' => 'Yesterday'],
        'sept_jours' => ['fr' => 'Les 7 derniers jours', 'en' => 'Last 7 days'],
        'semaine_precedente' => ['fr' => 'La semaine précédente', 'en' => 'Previous week'],
        'mois_en_cours' => ['fr' => 'Le mois en cours', 'en' => 'Month to date'],
        'mois_precedent' => ['fr' => 'Le mois précédent', 'en' => 'Previous month'],
        'trimestre_precedent' => ['fr' => 'Le trimestre précédent', 'en' => 'Previous quarter'],
        'annee_en_cours' => ['fr' => "L'année en cours", 'en' => 'Year to date'],
        'annee_precedente' => ['fr' => "L'année précédente", 'en' => 'Previous year'],
    ];

    public function __construct(
        private readonly EnvoiCourriel $envois,
        private readonly Droits $droits,
        private readonly TenantContext $contexte,
        private readonly AssistantRapports $assistant,
    ) {}

    /**
     * Bornes d'une période relative au jour donné (la semaine va du lundi au dimanche).
     *
     * @return array{du: string, au: string}
     */
    public static function periode(string $periode, CarbonInterface $jour): array
    {
        $jour = CarbonImmutable::instance($jour)->startOfDay();
        $lundi = $jour->startOfWeek(CarbonInterface::MONDAY);
        [$du, $au] = match ($periode) {
            'veille' => [$jour->subDay(), $jour->subDay()],
            'sept_jours' => [$jour->subDays(7), $jour->subDay()],
            'semaine_precedente' => [$lundi->subWeek(), $lundi->subDay()],
            'mois_en_cours' => [$jour->startOfMonth(), $jour],
            'mois_precedent' => [$jour->startOfMonth()->subMonth(), $jour->startOfMonth()->subDay()],
            'trimestre_precedent' => [$jour->startOfQuarter()->subMonths(3), $jour->startOfQuarter()->subDay()],
            'annee_en_cours' => [$jour->startOfYear(), $jour],
            'annee_precedente' => [$jour->startOfYear()->subYear(), $jour->startOfYear()->subDay()],
        };

        return ['du' => $du->toDateString(), 'au' => $au->toDateString()];
    }

    /**
     * Premier jour d'envoi strictement après le jour donné : le lendemain, le prochain jour de la semaine choisi
     * (1 = lundi … 7 = dimanche), ou le prochain jour du mois choisi (le dernier du mois s'il est plus court).
     */
    public static function prochainEnvoi(string $frequence, ?int $jour, CarbonInterface $apres): CarbonImmutable
    {
        $apres = CarbonImmutable::instance($apres)->startOfDay();
        if ($frequence === 'hebdomadaire') {
            $date = $apres->addDay();
            while ($date->dayOfWeekIso !== $jour) {
                $date = $date->addDay();
            }

            return $date;
        }
        if ($frequence === 'mensuelle') {
            $dansLeMois = fn (CarbonImmutable $mois) => $mois->day(min((int) $jour, $mois->daysInMonth));
            $date = $dansLeMois($apres->startOfMonth());

            return $date->greaterThan($apres) ? $date : $dansLeMois($apres->startOfMonth()->addMonth());
        }

        return $apres->addDay();
    }

    /**
     * Envoie le rapport programmé, sa période calculée au jour donné, avec le courriel du modèle « rapport » de
     * l'entreprise. Les droits sont ceux du compte qui l'a réglé, ou de celui qui l'envoie à la main ; une
     * DomainException dit pourquoi l'envoi est refusé. Avec une question à l'IA, son analyse du rapport entre dans le
     * courriel ; sans elle (IA indisponible ou en échec), le courriel part quand même et le motif est noté.
     */
    public function envoyer(RapportProgramme $programme, CarbonInterface $jour, ?AdminUser $par = null): CourrielEnvoi
    {
        $compte = $par ?? $programme->compte;
        $rapport = $this->rapportPermis($programme, $compte, $par === null);
        $rapport->langue = $programme->langue;
        $valeurs = $rapport->valeurs($this->saisies($rapport, $programme, $jour));
        [$analyse, $avertissement] = $this->analyse($programme, $compte, $rapport, $valeurs);
        $courriel = $this->envois->preparerRapport($rapport, $valeurs, $programme->langue, null, $analyse);
        // Modèle de l'entreprise sans {analyse_ia} : l'analyse vient à la fin du courriel.
        if ($analyse !== null && ! str_contains($courriel['contenu'], trim($analyse))) {
            $courriel['contenu'] .= "\n\n".($programme->langue === 'en' ? '**AI analysis**' : "**Analyse de l'IA**")."\n\n".trim($analyse);
        }

        $envoi = $this->envois->creerRapport($rapport, [
            'valeurs' => $valeurs,
            'format' => $programme->format,
            'fuseau' => config('taches.fuseau') ?: config('app.timezone'),
        ], [
            'langue' => $programme->langue,
            'destinataires' => $programme->destinataires,
            'objet' => $courriel['objet'],
            'contenu' => $courriel['contenu'],
        ], $compte->id, $programme->id);
        EnvoyerCourriel::dispatch($envoi->id);

        $programme->update(['dernier_envoi_le' => now(), 'dernier_envoi_id' => $envoi->id, 'derniere_erreur' => $avertissement]);

        return $envoi->refresh();
    }

    /**
     * Analyse de l'IA pour un envoi qui porte une question, si l'IA est disponible pour l'entreprise et le compte ;
     * sinon le motif, noté sur l'envoi programmé.
     *
     * @return array{?string, ?string} analyse, avertissement
     */
    private function analyse(RapportProgramme $programme, AdminUser $compte, Rapport $rapport, array $valeurs): array
    {
        if (blank($programme->question_ia)) {
            return [null, null];
        }
        $entreprise = Tenant::find($this->contexte->getTenantId());
        $raison = match (true) {
            AssistantRapports::indisponibilite($entreprise) === 'cle' => __("le service d'IA n'est pas configuré sur ce serveur"),
            AssistantRapports::indisponibilite($entreprise) === 'module' => __("le module « Intelligence artificielle » n'est pas activé"),
            ! $this->droits->permet($compte, 'rapports', 'questionner') => __(":nom n'a pas le droit de poser des questions à l'IA", ['nom' => $compte->nom]),
            default => null,
        };
        if ($raison === null) {
            try {
                return [$this->assistant->commenter($compte, $entreprise, $rapport, $valeurs, (string) $programme->question_ia, $programme->langue), null];
            } catch (RuntimeException $erreur) {
                $raison = $erreur->getMessage();
            }
        }

        return [null, __("Envoyé sans l'analyse de l'IA : :raison.", ['raison' => rtrim($raison, '.')])];
    }

    /** Le rapport, si le compte peut encore l'ouvrir (et programmer des envois, pour un envoi planifié). */
    private function rapportPermis(RapportProgramme $programme, ?AdminUser $compte, bool $planifie): Rapport
    {
        if (! $compte || ! $compte->actif) {
            throw new DomainException(__("Le compte qui a réglé cet envoi n'est plus actif : modifiez l'envoi pour le reprendre à votre nom."));
        }
        if ($planifie && ! $this->droits->permet($compte, 'rapports', 'programmer')) {
            throw new DomainException(__(":nom n'a plus le droit de programmer l'envoi des rapports.", ['nom' => $compte->nom]));
        }
        $rapport = Catalogue::pour($compte, Tenant::find($this->contexte->getTenantId()))->get($programme->code);
        if (! $rapport) {
            throw new DomainException(__(':nom ne peut plus ouvrir ce rapport : l\'envoi est suspendu.', ['nom' => $compte->nom]));
        }

        return $rapport;
    }

    /** Paramètres gardés, avec la période relative au jour d'envoi pour un rapport qui a une période. */
    private function saisies(Rapport $rapport, RapportProgramme $programme, CarbonInterface $jour): array
    {
        $saisies = $programme->parametres ?? [];
        $parametres = $rapport->parametres();
        if ($programme->periode && isset($parametres['du'], $parametres['au'])) {
            $saisies = self::periode($programme->periode, $jour) + $saisies;
        }

        return $saisies;
    }
}
