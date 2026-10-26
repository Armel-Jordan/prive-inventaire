<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\TenantRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;

class Transporteur extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'transporteurs';

    protected $fillable = [
        'tenant_id', 'code', 'nom', 'telephone', 'email', 'url_suivi', 'numero_compte', 'notes', 'actif',
        // « Livraison facturée » : décochée, le frais de livraison du client n'est pas proposé (phase 1.11b).
        'frais_livraison_applicable',
        // Frais de livraison proposé en « Frais de transport » sur les factures des fournisseurs qu'il livre.
        'frais_livraison_achat_id',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'frais_livraison_applicable' => 'boolean',
        'frais_livraison_achat_id' => 'integer',
    ];

    public function fraisAchat(): BelongsTo
    {
        return $this->belongsTo(Frais::class, 'frais_livraison_achat_id');
    }

    /**
     * Option « transporteur obligatoire » de l'entreprise ; sans effet tant qu'elle n'a aucun transporteur actif (on ne
     * bloque pas une saisie qui n'a rien à choisir).
     */
    public static function obligatoire(): bool
    {
        return (bool) TenantParametres::query()->value('transporteur_obligatoire') && self::query()->where('actif', true)->exists();
    }

    /**
     * Règle « exigé » de l'option, pour un document saisi : à la création, sauf si le défaut du tiers en donne un (non
     * transmis, le transporteur du client ou du fournisseur est repris) ; transmis, il ne peut pas être vide.
     *
     * @param  callable(): mixed  $defaut  transporteur par défaut du tiers du document
     */
    public static function exige(?Model $document, callable $defaut): RequiredIf
    {
        return Rule::requiredIf(fn () => self::obligatoire() && (request()->exists('transporteur_id') || (! $document && ! $defaut())));
    }

    /** Règle d'un champ transporteur : un transporteur de l'entreprise, non retiré (sauf la valeur actuelle de la fiche). */
    public static function regle(?int $actuel = null): array
    {
        return ['nullable', 'integer', TenantRule::exists('transporteurs')->where(fn ($q) => $q->whereNull('deleted_at')
            ->when($actuel, fn ($q, $id) => $q->orWhere('id', $id)))];
    }
}
