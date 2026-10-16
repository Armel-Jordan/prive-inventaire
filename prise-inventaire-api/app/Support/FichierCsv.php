<?php

namespace App\Support;

use Illuminate\Http\Response;

/** Réponse CSV pour Excel : séparateur point-virgule, BOM UTF-8, montants avec une virgule décimale. */
final class FichierCsv
{
    /**
     * @param  list<string>  $entetes
     * @param  iterable<array<int, mixed>>  $lignes
     */
    public static function reponse(string $nom, array $entetes, iterable $lignes): Response
    {
        $flux = fopen('php://temp', 'r+');
        fwrite($flux, "\xEF\xBB\xBF");
        fputcsv($flux, $entetes, ';');
        foreach ($lignes as $ligne) {
            fputcsv($flux, array_map(fn ($valeur) => is_float($valeur) ? self::montant($valeur) : $valeur, $ligne), ';');
        }
        rewind($flux);

        return response(stream_get_contents($flux), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$nom.'"',
        ]);
    }

    public static function montant(float $valeur): string
    {
        return number_format($valeur, 2, ',', '');
    }
}
