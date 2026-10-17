<?php

namespace App\Services;

use App\Models\BonLivraison;
use App\Models\Tournee;
use App\Models\TourneeBon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Séquences de livraison (plan de parité fripv2, phase 1.6) : le client a une route et un rang de passage ; les arrêts
 * d'une tournée se rangent dans cet ordre, et une tournée qui suit une route reçoit d'un coup les bons prêts des
 * clients de cette route. Les clients sans rang passent après les autres, dans l'ordre où ils ont été ajoutés.
 */
class SequencesLivraison
{
    /** Range les arrêts de la tournée par rang de passage des clients ; renumérote 1, 2, 3… */
    public function ordonner(Tournee $tournee): void
    {
        $liens = $tournee->tourneeBons()->with('bonLivraison.facture.client')->get();
        $ranges = $this->trier($liens, fn (TourneeBon $lien) => $lien->bonLivraison?->facture?->client?->sequence_livraison, fn (TourneeBon $lien) => $lien->ordre_livraison);

        DB::transaction(function () use ($ranges) {
            foreach ($ranges as $index => $lien) {
                if ((int) $lien->ordre_livraison !== $index + 1) {
                    $lien->update(['ordre_livraison' => $index + 1]);
                }
            }
        });
    }

    /**
     * Bons prêts, pas encore dans une tournée, des clients de la route, dans l'ordre de leurs rangs de passage.
     *
     * @return Collection<int, BonLivraison>
     */
    public function bonsDeLaRoute(int $itineraireId): Collection
    {
        $bons = BonLivraison::with('facture.client')
            ->where('statut', 'pret')
            ->whereDoesntHave('tourneeBon')
            ->whereHas('facture.client', fn ($q) => $q->where('itineraire_id', $itineraireId))
            ->get();

        return $this->trier($bons, fn (BonLivraison $bon) => $bon->facture?->client?->sequence_livraison, fn (BonLivraison $bon) => $bon->id);
    }

    /** Ajoute à la tournée les bons prêts de sa route, puis range ses arrêts ; renvoie le nombre de bons ajoutés. */
    public function ajouterLaRoute(Tournee $tournee): int
    {
        $bons = $this->bonsDeLaRoute((int) $tournee->itineraire_id);
        DB::transaction(function () use ($tournee, $bons) {
            $ordre = (int) $tournee->tourneeBons()->max('ordre_livraison');
            foreach ($bons as $bon) {
                TourneeBon::create(['tournee_id' => $tournee->id, 'bon_livraison_id' => $bon->id, 'ordre_livraison' => ++$ordre]);
            }
        });
        $this->ordonner($tournee);

        return $bons->count();
    }

    /** Tri stable : rang de passage croissant, sans rang à la fin, puis la clé de départage. */
    private function trier(Collection $elements, callable $rang, callable $departage): Collection
    {
        return $elements->sort(function ($a, $b) use ($rang, $departage) {
            $ra = $rang($a);
            $rb = $rang($b);
            if (($ra === null) !== ($rb === null)) {
                return $ra === null ? 1 : -1;
            }

            return [(int) $ra, $departage($a)] <=> [(int) $rb, $departage($b)];
        })->values();
    }
}
