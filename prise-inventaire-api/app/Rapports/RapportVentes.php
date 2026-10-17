<?php

namespace App\Rapports;

use App\Models\Facture;
use Illuminate\Database\Eloquent\Builder;

/** Rapport de ventes : les factures émises de la période (ni brouillons ni annulées), au droit de voir les factures. */
abstract class RapportVentes extends Rapport
{
    /** Factures qui comptent dans les ventes. */
    public const FACTURES_EMISES = ['emise', 'partiellement_payee', 'payee', 'creditee'];

    public function categorie(): string
    {
        return 'ventes';
    }

    public function module(): string
    {
        return 'factures';
    }

    protected function factures(array $valeurs): Builder
    {
        return Facture::query()->whereIn('statut', self::FACTURES_EMISES)
            ->whereDate('date_facture', '>=', $valeurs['du'])->whereDate('date_facture', '<=', $valeurs['au']);
    }
}
