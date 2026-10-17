<?php

namespace App\Support;

use App\Models\Unite;
use App\Models\UniteConversion;

/**
 * Facteur entre deux unités, d'après les conversions du référentiel (1 source = facteur × cible) :
 * - une conversion vaut dans les deux sens (l'inverse du facteur à rebours) ;
 * - les conversions s'enchaînent (palette → caisse → unité) : le chemin le plus court l'emporte ;
 * - les unités se désignent par leur code, sans tenir compte de la casse (« un » = « UN »), comme la mesure d'un produit.
 */
final class ConversionsUnites
{
    /** @var array<string, array<string, float>>|null code => code voisin => facteur (1 code = facteur × voisin) */
    private ?array $graphe = null;

    /**
     * Combien d'unités « a » dans une unité « de » ; 1 pour la même unité, null sans conversion qui les relie.
     */
    public function facteur(string $de, string $a): ?float
    {
        $de = self::cle($de);
        $a = self::cle($a);
        if ($de === '' || $a === '') {
            return null;
        }
        if ($de === $a) {
            return 1.0;
        }
        $graphe = $this->graphe();
        // Parcours en largeur : le chemin le plus court, le facteur multiplié en chemin.
        $vus = [$de => 1.0];
        $file = [$de];
        while ($file !== []) {
            $courant = array_shift($file);
            foreach ($graphe[$courant] ?? [] as $voisin => $facteur) {
                if (isset($vus[$voisin])) {
                    continue;
                }
                $vus[$voisin] = $vus[$courant] * $facteur;
                if ($voisin === $a) {
                    return round($vus[$voisin], 6);
                }
                $file[] = $voisin;
            }
        }

        return null;
    }

    /** Code comparable d'une unité. */
    public static function cle(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** @return array<string, array<string, float>> */
    private function graphe(): array
    {
        if ($this->graphe !== null) {
            return $this->graphe;
        }
        $codes = Unite::query()->pluck('code', 'id')->map(fn ($code) => self::cle((string) $code));
        $graphe = [];
        foreach (UniteConversion::query()->get(['unite_source_id', 'unite_cible_id', 'facteur']) as $conversion) {
            $source = $codes[$conversion->unite_source_id] ?? null;
            $cible = $codes[$conversion->unite_cible_id] ?? null;
            $facteur = (float) $conversion->facteur;
            if ($source === null || $cible === null || $facteur <= 0) {
                continue;
            }
            $graphe[$source][$cible] = $facteur;
            $graphe[$cible][$source] ??= 1 / $facteur;
        }

        return $this->graphe = $graphe;
    }
}
