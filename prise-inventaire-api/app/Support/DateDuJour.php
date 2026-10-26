<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Date du jour d'un document créé par l'application (commande tirée d'un devis, facture tirée d'une commande, commande
 * fournisseur des reliquats ou des suggestions) : celle de l'entreprise, et non celle de l'horloge du serveur en UTC,
 * qui donnait le lendemain à une saisie faite le soir à l'ouest de Greenwich (anomalie A6).
 */
final class DateDuJour
{
    /** Date du jour dans le fuseau de l'entreprise (TACHES_FUSEAU), sinon dans celui de l'application. */
    public static function aujourdhui(): string
    {
        return now(config('taches.fuseau') ?: config('app.timezone'))->toDateString();
    }

    /**
     * Date envoyée par l'écran (la date locale de l'usager, `date`), sinon la date du jour de l'entreprise. Un jour de
     * marge est accepté (l'API compte en UTC) ; une date plus lointaine est refusée.
     */
    public static function demandee(Request $request): string
    {
        return $request->validate(['date' => ['nullable', 'date', 'before_or_equal:tomorrow']])['date'] ?? self::aujourdhui();
    }
}
