<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Journal comptable (opérations diverses, ventes, achats, banque…) : il classe les écritures et préfixe leur numéro. */
class JournalComptable extends Model
{
    use BelongsToTenant;

    public const TYPES = ['general', 'ventes', 'achats', 'tresorerie', 'ouverture'];

    /** Journaux créés pour chaque entreprise au premier accès : code => [libellé, type]. */
    public const PAR_DEFAUT = [
        'OD' => ['Opérations diverses', 'general'],
        'VE' => ['Ventes', 'ventes'],
        'AC' => ['Achats', 'achats'],
        'BQ' => ['Banque', 'tresorerie'],
        'CA' => ['Caisse', 'tresorerie'],
        'AN' => ['À-nouveaux (soldes d\'ouverture)', 'ouverture'],
    ];

    protected $table = 'journaux_comptables';

    protected $fillable = ['code', 'libelle', 'type', 'actif'];

    protected $casts = ['actif' => 'boolean'];

    public function ecritures(): HasMany
    {
        return $this->hasMany(EcritureComptable::class, 'journal_id');
    }

    /** Crée les journaux par défaut d'une entreprise qui n'en a encore aucun. */
    public static function assurerParDefaut(): void
    {
        if (static::exists()) {
            return;
        }
        foreach (self::PAR_DEFAUT as $code => [$libelle, $type]) {
            static::create(['code' => $code, 'libelle' => $libelle, 'type' => $type, 'actif' => true]);
        }
    }

    /** Journal d'un type (le premier actif), créé au besoin avec les journaux par défaut. */
    public static function duType(string $type): self
    {
        static::assurerParDefaut();

        return static::where('type', $type)->where('actif', true)->orderBy('id')->first()
            ?? static::where('type', 'general')->orderBy('id')->firstOrFail();
    }
}
