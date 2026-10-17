<?php

namespace App\Http\Controllers\Inventaire;

use App\Http\Controllers\Controller;
use App\Models\NumeroSerie;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Registre des numéros de série (phase 2.8) : produit, statut, réception d'origine (fournisseur, date, document). */
class NumeroSerieController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'recherche' => ['nullable', 'string', 'max:100'],
            'produit_id' => ['nullable', 'integer'],
            'statut' => ['nullable', Rule::in(NumeroSerie::STATUTS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $motif = filled($filtres['recherche'] ?? null) ? '%'.addcslashes(trim((string) $filtres['recherche']), '%_\\').'%' : null;

        $page = NumeroSerie::query()
            ->with(['produit:id,numero,description', 'fournisseur:id,raison_sociale', 'receptionLigne:id,reception_id', 'receptionLigne.reception:id,numero',
                'client:id,code,raison_sociale', 'bonLivraisonLigne:id,bon_id', 'bonLivraisonLigne.bonLivraison:id,numero'])
            ->when($motif, fn (Builder $q) => $q->where(fn (Builder $r) => $r->where('numero', 'like', $motif)
                ->orWhereHas('produit', fn (Builder $p) => $p->where('numero', 'like', $motif)->orWhere('description', 'like', $motif))))
            ->when($filtres['produit_id'] ?? null, fn (Builder $q, $produit) => $q->where('produit_id', $produit))
            ->when($filtres['statut'] ?? null, fn (Builder $q, $statut) => $q->where('statut', $statut))
            ->orderByDesc('recu_le')->orderBy('numero')
            ->paginate(50, ['*'], 'page', (int) ($filtres['page'] ?? 1));
        $page->getCollection()->transform(fn (NumeroSerie $s) => [
            'id' => $s->id,
            'numero' => $s->numero,
            'statut' => $s->statut,
            'produit' => $s->produit?->only(['id', 'numero', 'description']),
            'fournisseur' => $s->fournisseur?->raison_sociale,
            'recu_le' => $s->recu_le?->toDateString(),
            'reception' => $s->receptionLigne?->reception?->only(['id', 'numero']),
            // Sortie (phase 2.8b) : le client, la date et le bon de livraison.
            'client' => $s->client ? "{$s->client->code} — {$s->client->raison_sociale}" : null,
            'livre_le' => $s->livre_le?->toDateString(),
            'bon_livraison' => $s->bonLivraisonLigne?->bonLivraison?->numero,
        ]);

        return response()->json($page);
    }
}
