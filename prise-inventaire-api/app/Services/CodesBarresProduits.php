<?php

namespace App\Services;

use App\Models\ProduitCodeBarre;
use App\Models\ProduitTenant;
use App\Support\CodesBarres;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Codes-barres des produits (plan de parité fripv2, phase 2.1) :
 * - un code lu désigne un produit actif par son numéro, sinon par l'un de ses codes-barres, qui compte pour la quantité
 *   du code (un carton de 12) ;
 * - un code est unique dans l'entreprise, n'est pas le numéro d'un autre produit (la lecture serait ambiguë) et
 *   respecte son type (la clé d'un EAN ou d'un UPC est contrôlée ; le type se devine s'il n'est pas donné) ;
 * - un seul code principal par produit ; le premier code d'un produit l'est d'office.
 */
class CodesBarresProduits
{
    /**
     * @return array{produit: ProduitTenant, quantite: float, code: ?string}|null code : le code-barres lu, null pour un numéro
     */
    public function resoudre(string $code): ?array
    {
        $code = trim($code);
        $produit = ProduitTenant::query()->where('numero', $code)->where('actif', true)->first();
        if ($produit) {
            return ['produit' => $produit, 'quantite' => 1.0, 'code' => null];
        }
        $codeBarre = ProduitCodeBarre::query()->where('code', $code)->whereHas('produit', fn ($q) => $q->where('actif', true))->with('produit')->first();

        return $codeBarre ? ['produit' => $codeBarre->produit, 'quantite' => (float) $codeBarre->quantite, 'code' => $codeBarre->code] : null;
    }

    /** Ajoute ou modifie un code-barres d'un produit. */
    public function enregistrer(ProduitTenant $produit, array $donnees, ?ProduitCodeBarre $existant = null): ProduitCodeBarre
    {
        $code = trim((string) $donnees['code']);
        $type = ($donnees['type'] ?? null) ?: CodesBarres::detecter($code);
        if ($erreur = CodesBarres::erreur($code, $type)) {
            throw ValidationException::withMessages(['code' => $erreur]);
        }
        $autre = ProduitCodeBarre::query()->where('code', $code)->when($existant, fn ($q) => $q->whereKeyNot($existant->id))->with('produit:id,numero')->first();
        if ($autre) {
            throw ValidationException::withMessages(['code' => __('Le code :code est déjà celui du produit :produit.', ['code' => $code, 'produit' => $autre->produit?->numero])]);
        }
        if (ProduitTenant::withTrashed()->where('numero', $code)->whereKeyNot($produit->id)->exists()) {
            throw ValidationException::withMessages(['code' => __("Le code :code est le numéro d'un autre produit : une lecture serait ambiguë.", ['code' => $code])]);
        }

        return DB::transaction(function () use ($produit, $donnees, $existant, $code, $type) {
            $premier = ! ProduitCodeBarre::query()->where('produit_id', $produit->id)->when($existant, fn ($q) => $q->whereKeyNot($existant->id))->exists();
            $principal = $premier || (bool) ($donnees['principal'] ?? $existant?->principal ?? false);
            if ($principal) {
                ProduitCodeBarre::query()->where('produit_id', $produit->id)->when($existant, fn ($q) => $q->whereKeyNot($existant->id))->update(['principal' => false]);
            }
            $valeurs = [
                'code' => $code, 'type' => $type, 'quantite' => $donnees['quantite'] ?? $existant?->quantite ?? 1,
                'principal' => $principal, 'notes' => array_key_exists('notes', $donnees) ? (trim((string) $donnees['notes']) ?: null) : $existant?->notes,
            ];
            if ($existant) {
                $existant->update($valeurs);

                return $existant;
            }

            return ProduitCodeBarre::create($valeurs + ['produit_id' => $produit->id]);
        });
    }

    /** Retire un code ; si c'était le principal, le plus ancien des autres le devient. */
    public function supprimer(ProduitCodeBarre $codeBarre): void
    {
        DB::transaction(function () use ($codeBarre) {
            $codeBarre->delete();
            if ($codeBarre->principal) {
                ProduitCodeBarre::query()->where('produit_id', $codeBarre->produit_id)->orderBy('id')->first()?->update(['principal' => true]);
            }
        });
    }
}
