<?php

namespace App\Services;

use App\Models\ComClientEntete;
use App\Models\Devis;
use App\Models\DocumentCopie;
use App\Models\Facture;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Liens « Copié de » et « Copies » des documents de vente (plan de parité fripv2, socle S4.3). Le document créé à partir
 * d'un autre (« Copier vers », copie d'un devis) note son origine : `copie_de: {type, id}` dans la création, d'un
 * document de l'entreprise. Les liens vers un document supprimé disparaissent de l'affichage.
 */
class CopiesDocuments
{
    /** Type de document => modèle. */
    public const TYPES = [
        'devis' => Devis::class,
        'commande_client' => ComClientEntete::class,
        'facture' => Facture::class,
    ];

    /** Type de document => module des droits. */
    public const MODULES = [
        'devis' => 'devis',
        'commande_client' => 'commandes_client',
        'facture' => 'factures',
    ];

    /** Règles de `copie_de` dans la création d'un document. */
    public static function regles(): array
    {
        return [
            'copie_de' => ['nullable', 'array'],
            'copie_de.type' => ['required_with:copie_de', Rule::in(array_keys(self::TYPES))],
            'copie_de.id' => ['required_with:copie_de', 'integer'],
        ];
    }

    /** Note l'origine du document créé, si elle est un document de l'entreprise (sinon rien). */
    public function noter(?array $copieDe, string $cibleType, Model $cible, ?int $auteur = null): void
    {
        $modele = self::TYPES[$copieDe['type'] ?? ''] ?? null;
        if (! $modele || ! isset($copieDe['id']) || ! $modele::whereKey((int) $copieDe['id'])->exists()) {
            return;
        }
        DocumentCopie::create([
            'source_type' => $copieDe['type'], 'source_id' => (int) $copieDe['id'],
            'cible_type' => $cibleType, 'cible_id' => $cible->getKey(), 'cree_par' => $auteur,
        ]);
    }

    /**
     * Liens d'un document : celui dont il est la copie, et ses copies (les plus récentes d'abord), avec leur numéro.
     *
     * @return array{copie_de: array|null, copies: array<int, array>}
     */
    public function liens(string $type, int $id): array
    {
        $origine = DocumentCopie::where('cible_type', $type)->where('cible_id', $id)->latest('id')->first();
        $copies = DocumentCopie::where('source_type', $type)->where('source_id', $id)->orderByDesc('id')->get();

        return [
            'copie_de' => $origine ? $this->document($origine->source_type, $origine->source_id, $origine->created_at) : null,
            'copies' => $copies->map(fn (DocumentCopie $lien) => $this->document($lien->cible_type, $lien->cible_id, $lien->created_at))
                ->filter()->values()->all(),
        ];
    }

    /** Type, identifiant, numéro et date du lien ; null si le document n'existe plus. */
    private function document(string $type, int $id, mixed $le): ?array
    {
        $modele = self::TYPES[$type] ?? null;
        $numero = $modele ? $modele::whereKey($id)->value('numero') : null;

        return $numero === null ? null : ['type' => $type, 'id' => $id, 'numero' => $numero, 'le' => $le?->toIso8601String()];
    }
}
