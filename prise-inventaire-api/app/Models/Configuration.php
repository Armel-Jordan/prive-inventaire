<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Configuration extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $connection = 'mysql';

    protected $table = 'configurations';

    protected $fillable = [
        'tenant_id',
        'entite',
        'prefixe',
        'suffixe',
        'longueur',
        'separateur',
        'auto_increment',
        'prochain_numero',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected function casts(): array
    {
        return [
            'longueur' => 'integer',
            'auto_increment' => 'boolean',
            'prochain_numero' => 'integer',
        ];
    }

    /**
     * Génère le prochain numéro selon la configuration
     */
    public function genererNumero(): string
    {
        $numero = str_pad($this->prochain_numero, $this->longueur, '0', STR_PAD_LEFT);

        $result = $this->prefixe;
        if ($this->separateur && $this->prefixe) {
            $result .= $this->separateur;
        }
        $result .= $numero;
        if ($this->suffixe) {
            if ($this->separateur) {
                $result .= $this->separateur;
            }
            $result .= $this->suffixe;
        }

        return $result;
    }

    /**
     * Incrémente le compteur après utilisation
     */
    public function incrementer(): void
    {
        $this->prochain_numero++;
        $this->save();
    }

    /**
     * Récupère la configuration pour une entité donnée
     */
    public static function pourEntite(string $entite, int $tenantId): ?self
    {
        return self::where('tenant_id', $tenantId)->where('entite', $entite)->first();
    }

    /**
     * Génère ET consomme le prochain numéro de façon atomique.
     *
     * DOIT être appelé À L'INTÉRIEUR d'un DB::transaction() qui crée aussi le
     * document : le verrou lockForUpdate sérialise les créations concurrentes
     * (pas de doublon), et l'incrément est annulé si la création échoue (pas de
     * trou de séquence — exigence de continuité OHADA).
     */
    public static function consommerNumero(string $entite, int $tenantId): string
    {
        $config = self::where('tenant_id', $tenantId)
            ->where('entite', $entite)
            ->lockForUpdate()
            ->first();

        if (! $config || ! $config->auto_increment) {
            throw new \RuntimeException("Numérotation indisponible pour l'entité « {$entite} ».");
        }

        $numero = $config->genererNumero();
        $config->incrementer();

        return $numero;
    }
}
