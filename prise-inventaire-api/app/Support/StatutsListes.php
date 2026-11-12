<?php

namespace App\Support;

use App\Models\BonLivraison;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Statuts calculés et filtres des listes de documents de vente (plan de parité fripv2, socle S4.7).
 *
 * - Statut de livraison d'une commande ou d'une facture, d'après son bon de livraison en cours : non livré, en
 *   attente (bon créé, en préparation ou prêt), en livraison, livré partiel, livré complet ; « rien à livrer » quand
 *   aucune ligne n'est un produit stocké (services, produits non stockés).
 * - Âge d'une facture due : courante, échue depuis 1 à 30, 31 à 60, 61 à 90 ou plus de 90 jours.
 * - Filtre des documents qui contiennent un produit.
 */
final class StatutsListes
{
    public const LIVRAISON = ['non_livre', 'en_attente', 'en_livraison', 'livre_partiel', 'livre_complet', 'rien_a_livrer'];

    public const AGES = ['courant', '1_30', '31_60', '61_90', 'plus_90'];

    /** Statuts d'une facture qui reste due. */
    private const DUES = ['emise', 'partiellement_payee'];

    /** Lignes « à livrer » : un produit stocké qui n'est pas un service (à compter avec withExists). */
    public static function aLivrer(): array
    {
        return ['lignes as a_livrer' => fn ($l) => $l->whereHas('produit', fn ($p) => $p->where('service', false)->where('stocke', true))];
    }

    /** Statut de livraison d'après le bon en cours et la présence de produits à livrer. */
    public static function livraison(?BonLivraison $bon, bool $aLivrer): string
    {
        if ($bon) {
            return match ($bon->statut) {
                'en_livraison', 'livre_partiel', 'livre_complet' => $bon->statut,
                default => 'en_attente',
            };
        }

        return $aLivrer ? 'non_livre' : 'rien_a_livrer';
    }

    /**
     * Filtre « statut de livraison ».
     *
     * @param  string  $bon  chemin du bon en cours : « bonLivraison » (facture), « facture.bonLivraison » (commande)
     */
    public static function filtrerLivraison(Builder $requete, ?string $statut, string $bon): void
    {
        $stocke = fn ($p) => $p->where('service', false)->where('stocke', true);
        match ($statut) {
            'en_attente' => $requete->whereHas($bon, fn ($b) => $b->whereIn('statut', ['cree', 'en_preparation', 'pret'])),
            'en_livraison', 'livre_partiel', 'livre_complet' => $requete->whereHas($bon, fn ($b) => $b->where('statut', $statut)),
            'non_livre' => $requete->whereDoesntHave($bon)->whereHas('lignes.produit', $stocke),
            'rien_a_livrer' => $requete->whereDoesntHave($bon)->whereDoesntHave('lignes.produit', $stocke),
            default => null,
        };
    }

    /** Filtre « documents qui contiennent ce produit ». */
    public static function filtrerProduit(Builder $requete, Request $request): void
    {
        if ($request->filled('produit_id')) {
            $requete->whereHas('lignes', fn ($l) => $l->where('produit_id', $request->integer('produit_id')));
        }
    }

    /** Filtre « âge » des factures dues, d'après leur échéance (une facture sans échéance est courante). */
    public static function filtrerAge(Builder $requete, ?string $age): void
    {
        if (! in_array($age, self::AGES, true)) {
            return;
        }
        $jour = Carbon::parse(DateDuJour::aujourdhui());
        $requete->whereIn('statut', self::DUES);
        $avant = fn (int $jours) => $jour->copy()->subDays($jours)->toDateString();
        match ($age) {
            'courant' => $requete->where(fn ($q) => $q->whereNull('date_echeance')->orWhereDate('date_echeance', '>=', $jour->toDateString())),
            '1_30' => $requete->whereDate('date_echeance', '<', $jour->toDateString())->whereDate('date_echeance', '>=', $avant(30)),
            '31_60' => $requete->whereDate('date_echeance', '<', $avant(30))->whereDate('date_echeance', '>=', $avant(60)),
            '61_90' => $requete->whereDate('date_echeance', '<', $avant(60))->whereDate('date_echeance', '>=', $avant(90)),
            'plus_90' => $requete->whereDate('date_echeance', '<', $avant(90)),
        };
    }

    /** Jours de retard d'une facture due (0 si elle n'est pas échue), null si elle n'est plus due. */
    public static function joursRetard(string $statut, mixed $echeance): ?int
    {
        if (! in_array($statut, self::DUES, true)) {
            return null;
        }
        if (! $echeance) {
            return 0;
        }

        return max(0, (int) Carbon::parse($echeance)->startOfDay()->diffInDays(Carbon::parse(DateDuJour::aujourdhui()), false));
    }
}
