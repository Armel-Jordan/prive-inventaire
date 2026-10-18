<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use App\Models\Categorie;
use App\Models\CompteComptable;
use App\Models\JournalComptable;
use App\Models\ParametreComptable;
use App\Models\ProduitTenant;
use App\Models\TenantTaxe;
use App\Services\Comptabilite\Comptabilisation;
use App\Support\TenantRule;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Comptabilisation automatique : réglages (journaux, comptes, correspondances, date de début), aperçu des documents à
 * comptabiliser d'une période, lancement. Consultation avec le droit de voir la comptabilité ; réglages et lancement avec
 * celui de saisir des écritures (voir App\Support\Droits).
 */
class ComptabilisationController extends Controller
{
    public function __construct(private readonly Comptabilisation $comptabilisation) {}

    /** Réglages, propositions pour les réglages vides, et listes de choix (journaux, comptes, taxes, catégories, modes). */
    public function parametres(): JsonResponse
    {
        $parametres = $this->comptabilisation->parametres();
        $categories = ProduitTenant::query()->whereNotNull('categorie')->where('categorie', '!=', '')->distinct()->orderBy('categorie')->pluck('categorie')
            ->merge(Categorie::query()->orderBy('nom')->pluck('nom'))->unique()->sort()->values();

        return response()->json([
            'parametres' => $parametres,
            'propositions' => $this->comptabilisation->propositions(),
            'manquants' => $this->comptabilisation->manquants($parametres),
            'journaux' => JournalComptable::query()->where('actif', true)->orderBy('code')->get(['id', 'code', 'libelle', 'type']),
            'comptes' => CompteComptable::query()->where('actif', true)->where('type', '!=', CompteComptable::EN_TETE)->orderBy('numero')->get(['id', 'numero', 'libelle', 'type']),
            'taxes' => TenantTaxe::query()->orderBy('nom')->get(['id', 'nom', 'taux']),
            'categories' => $categories,
            'modes' => Comptabilisation::MODES,
            'types' => Comptabilisation::TYPES,
        ]);
    }

    public function enregistrerParametres(Request $request): JsonResponse
    {
        $regles = ['date_debut' => ['nullable', 'date']];
        foreach (ParametreComptable::JOURNAUX as $champ) {
            $regles[$champ] = ['nullable', 'integer', TenantRule::exists('journaux_comptables')];
        }
        foreach (ParametreComptable::COMPTES as $champ) {
            $regles[$champ] = ['nullable', 'integer', TenantRule::exists('comptes_comptables')];
        }
        $donnees = $request->validate($regles + [
            'correspondances' => ['nullable', 'array'],
            'correspondances.modes' => ['nullable', 'array'],
            'correspondances.modes.*' => ['nullable', Rule::in(['caisse', 'banque'])],
            'correspondances.taxes' => ['nullable', 'array'],
            'correspondances.taxes.*.collectee' => ['nullable', 'integer', TenantRule::exists('comptes_comptables')],
            'correspondances.taxes.*.deductible' => ['nullable', 'integer', TenantRule::exists('comptes_comptables')],
            'correspondances.categories' => ['nullable', 'array'],
            'correspondances.categories.*.vente' => ['nullable', 'integer', TenantRule::exists('comptes_comptables')],
            'correspondances.categories.*.achat' => ['nullable', 'integer', TenantRule::exists('comptes_comptables')],
        ]);
        // Un compte d'en-tête regroupe des sous-comptes : il ne reçoit pas d'écriture.
        $comptes = collect(ParametreComptable::COMPTES)->map(fn ($champ) => $donnees[$champ] ?? null)
            ->merge(collect($donnees['correspondances']['taxes'] ?? [])->flatten())
            ->merge(collect($donnees['correspondances']['categories'] ?? [])->flatten())->filter()->unique();
        $entetes = CompteComptable::query()->whereIn('id', $comptes)->where('type', CompteComptable::EN_TETE)->pluck('numero');
        if ($entetes->isNotEmpty()) {
            throw ValidationException::withMessages(['comptes' => __("Un compte d'en-tête ne reçoit pas d'écriture : :comptes.", ['comptes' => $entetes->implode(', ')])]);
        }
        $parametres = $this->comptabilisation->parametres();
        $parametres->update($donnees);

        return response()->json(['parametres' => $parametres->fresh(), 'manquants' => $this->comptabilisation->manquants($parametres->fresh())]);
    }

    /** Documents à comptabiliser d'une période (par défaut : de la date de début à aujourd'hui). */
    public function apercu(Request $request): JsonResponse
    {
        [$du, $au] = $this->periode($request);

        return response()->json($this->comptabilisation->apercu($du, $au));
    }

    public function comptabiliser(Request $request): JsonResponse
    {
        [$du, $au] = $this->periode($request);
        try {
            $resultat = $this->comptabilisation->comptabiliser($du, $au, $request->user()?->id);
        } catch (DomainException $erreur) {
            return response()->json(['message' => $erreur->getMessage()], 422);
        }
        $message = match (true) {
            $resultat['creees'] + $resultat['contre_passations'] <= 0 => __('Aucune écriture à créer.'),
            (bool) $resultat['contre_passations'] => __(':nombre écriture(s) créée(s), :contre_passations contre-passation(s).', [
                'nombre' => $resultat['creees'], 'contre_passations' => $resultat['contre_passations'],
            ]),
            default => __(':nombre écriture(s) créée(s).', ['nombre' => $resultat['creees']]),
        };

        return response()->json($resultat + ['message' => $message]);
    }

    private function periode(Request $request): array
    {
        $dates = $request->validate(['du' => ['nullable', 'date'], 'au' => ['nullable', 'date', 'after_or_equal:du']]);

        return [Carbon::parse($dates['du'] ?? '1900-01-01')->startOfDay(), Carbon::parse($dates['au'] ?? today())->startOfDay()];
    }
}
