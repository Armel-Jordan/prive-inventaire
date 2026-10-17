<?php

namespace App\Services;

use App\Models\ProduitTenant;
use App\Models\ProduitUnite;
use App\Models\Unite;
use App\Support\ConversionsUnites;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unités de vente des produits (plan de parité fripv2, phase 2.2) :
 * - un produit se compte dans son unité de stock (sa mesure) et se vend aussi dans d'autres unités du référentiel (la
 *   caisse de 12, le carton) ; le facteur d'une unité est le nombre d'unités de stock qu'elle contient, proposé d'après
 *   les conversions du référentiel (dans les deux sens, enchaînées) ; une unité peut avoir son propre prix ;
 * - une unité par défaut est proposée sur les lignes de vente (sinon l'unité de stock) ;
 * - une ligne de document porte le code de son unité et son facteur, figés à la saisie (vides : l'unité de stock) :
 *   changer ensuite les unités du produit ne change rien aux documents.
 */
class UnitesProduits
{
    public function __construct(private readonly ConversionsUnites $conversions) {}

    /** Unités de vente d'un produit, celle par défaut d'abord. */
    public function liste(ProduitTenant $produit): Collection
    {
        return $produit->unites()->with('unite')->get();
    }

    /** Facteur d'une unité pour un produit d'après les conversions du référentiel (unités de stock dans une unité). */
    public function facteurPropose(ProduitTenant $produit, Unite $unite): ?float
    {
        return $produit->mesure ? $this->conversions->facteur($unite->code, $produit->mesure) : null;
    }

    /** Ajoute ou modifie une unité de vente d'un produit. */
    public function enregistrer(ProduitTenant $produit, array $donnees, ?ProduitUnite $existant = null): ProduitUnite
    {
        $unite = Unite::query()->find($donnees['unite_id'] ?? $existant?->unite_id);
        if (! $unite || (! $unite->actif && $unite->id !== $existant?->unite_id)) {
            throw ValidationException::withMessages(['unite_id' => 'Choisissez une unité active du référentiel (Paramètres → Référentiels).']);
        }
        if (ConversionsUnites::cle($unite->code) === ConversionsUnites::cle((string) $produit->mesure)) {
            throw ValidationException::withMessages(['unite_id' => "{$unite->code} est l'unité de stock du produit : elle vaut toujours 1."]);
        }
        $doublon = ProduitUnite::query()->where('produit_id', $produit->id)->where('unite_id', $unite->id)
            ->when($existant, fn ($q) => $q->whereKeyNot($existant->id))->exists();
        if ($doublon) {
            throw ValidationException::withMessages(['unite_id' => "Le produit se vend déjà en {$unite->code}."]);
        }
        $facteur = array_key_exists('facteur', $donnees) && $donnees['facteur'] !== null
            ? round((float) $donnees['facteur'], 6)
            : ($existant?->facteur ?? $this->facteurPropose($produit, $unite));
        if ($facteur === null) {
            throw ValidationException::withMessages(['facteur' => "Indiquez combien de {$produit->mesure} contient une unité {$unite->code} : aucune conversion du référentiel ne les relie."]);
        }
        if ($facteur <= 0) {
            throw ValidationException::withMessages(['facteur' => 'Le facteur est un nombre positif.']);
        }

        return DB::transaction(function () use ($produit, $donnees, $existant, $unite, $facteur) {
            $parDefaut = (bool) ($donnees['par_defaut'] ?? $existant?->par_defaut ?? false);
            if ($parDefaut) {
                ProduitUnite::query()->where('produit_id', $produit->id)->when($existant, fn ($q) => $q->whereKeyNot($existant->id))
                    ->update(['par_defaut' => false]);
            }
            $fiche = $existant ?? new ProduitUnite(['produit_id' => $produit->id]);
            $fiche->fill([
                'unite_id' => $unite->id,
                'facteur' => $facteur,
                'prix' => array_key_exists('prix', $donnees) ? ($donnees['prix'] === null ? null : round((float) $donnees['prix'], 4)) : $existant?->prix,
                'par_defaut' => $parDefaut,
            ])->save();

            return $fiche->load('unite');
        });
    }

    public function supprimer(ProduitUnite $unite): void
    {
        $unite->delete();
    }

    /**
     * Unité et facteur de chaque ligne d'un document de vente : l'unité de stock du produit (unité vide ou égale à sa
     * mesure ; facteur vide), sinon une de ses unités de vente, au facteur du produit ce jour-là.
     *
     * @param  list<array{produit_id: int|string, unite?: ?string}>  $lignes
     * @return list<array{unite: ?string, facteur: ?float}> dans l'ordre des lignes
     */
    public function pourLignes(array $lignes, string $prefixe = 'lignes'): array
    {
        $ids = array_values(array_unique(array_map(fn (array $l) => (int) $l['produit_id'], $lignes)));
        $produits = ProduitTenant::withTrashed()->whereIn('id', $ids)->get(['id', 'numero', 'mesure'])->keyBy('id');
        $unites = ProduitUnite::query()->whereIn('produit_id', $ids)->with('unite')->get()->groupBy('produit_id');

        $resultat = [];
        foreach (array_values($lignes) as $rang => $ligne) {
            $code = trim((string) ($ligne['unite'] ?? ''));
            $produit = $produits->get((int) $ligne['produit_id']);
            if ($code === '' || ! $produit || ConversionsUnites::cle($code) === ConversionsUnites::cle((string) $produit->mesure)) {
                $resultat[] = ['unite' => null, 'facteur' => null];

                continue;
            }
            $unite = ($unites[$produit->id] ?? collect())->first(fn (ProduitUnite $u) => ConversionsUnites::cle((string) $u->code) === ConversionsUnites::cle($code));
            if (! $unite) {
                throw ValidationException::withMessages(["{$prefixe}.{$rang}.unite" => "Le produit {$produit->numero} ne se vend pas en {$code} : ajoutez cette unité à ses unités de vente."]);
            }
            $resultat[] = ['unite' => $unite->code, 'facteur' => (float) $unite->facteur];
        }

        return $resultat;
    }

    /** Les lignes saisies, chacune avec son unité et son facteur (voir pourLignes). */
    public function completerLignes(array $lignes, string $prefixe = 'lignes'): array
    {
        $unites = $this->pourLignes($lignes, $prefixe);

        return array_map(fn (array $ligne, array $unite) => $unite + $ligne, array_values($lignes), $unites);
    }
}
