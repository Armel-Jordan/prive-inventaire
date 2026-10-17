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
     * Numérotation par défaut de chaque type de fiche ou de document. Une entreprise qui n'a pas encore
     * enregistré la sienne l'obtient au premier besoin (écran Configuration ou création du document) :
     * sans cela, son premier bon de livraison, devis… était refusé (« Numéro requis »).
     */
    public const DEFAUTS = [
        'produit' => ['prefixe' => 'P', 'longueur' => 5, 'separateur' => '', 'auto_increment' => true],
        'employe' => ['prefixe' => 'E', 'longueur' => 4, 'separateur' => '', 'auto_increment' => true],
        'secteur' => ['prefixe' => '', 'longueur' => 3, 'separateur' => '-', 'auto_increment' => false],
        'fournisseur' => ['prefixe' => 'F', 'longueur' => 5, 'separateur' => '', 'auto_increment' => true],
        'client' => ['prefixe' => 'C', 'longueur' => 5, 'separateur' => '', 'auto_increment' => true],
        'devis' => ['prefixe' => 'DEV', 'longueur' => 5, 'separateur' => '-', 'auto_increment' => true],
        'commande' => ['prefixe' => 'CMD', 'longueur' => 5, 'separateur' => '-', 'auto_increment' => true],
        'commande_fournisseur' => ['prefixe' => 'BC', 'longueur' => 5, 'separateur' => '-', 'auto_increment' => true],
        'facture' => ['prefixe' => 'FAC', 'longueur' => 5, 'separateur' => '-', 'auto_increment' => true],
        'avoir' => ['prefixe' => 'AV', 'longueur' => 5, 'separateur' => '-', 'auto_increment' => true],
        'demande_acompte' => ['prefixe' => 'DA', 'longueur' => 5, 'separateur' => '-', 'auto_increment' => true],
        'encaissement' => ['prefixe' => 'ENC', 'longueur' => 5, 'separateur' => '-', 'auto_increment' => true],
        'retour_client' => ['prefixe' => 'RC', 'longueur' => 5, 'separateur' => '-', 'auto_increment' => true],
        'retour_fournisseur' => ['prefixe' => 'RF', 'longueur' => 5, 'separateur' => '-', 'auto_increment' => true],
        'reception' => ['prefixe' => 'REC', 'longueur' => 5, 'separateur' => '-', 'auto_increment' => true],
        'facture_fournisseur' => ['prefixe' => 'FF', 'longueur' => 5, 'separateur' => '-', 'auto_increment' => true],
        'bon_livraison' => ['prefixe' => 'BL', 'longueur' => 5, 'separateur' => '-', 'auto_increment' => true],
        'tournee' => ['prefixe' => 'T', 'longueur' => 4, 'separateur' => '', 'auto_increment' => true],
        'contrat_location' => ['prefixe' => 'CT', 'longueur' => 5, 'separateur' => '-', 'auto_increment' => true],
        'consignation' => ['prefixe' => 'CS', 'longueur' => 5, 'separateur' => '-', 'auto_increment' => true],
        'carte_cadeau' => ['prefixe' => 'CC', 'longueur' => 6, 'separateur' => '-', 'auto_increment' => true],
    ];

    /**
     * Configuration d'une entité ; créée avec la numérotation par défaut si l'entreprise n'en a pas encore.
     */
    public static function pourEntite(string $entite, int $tenantId): ?self
    {
        $config = self::withTrashed()->where('tenant_id', $tenantId)->where('entite', $entite)->first();
        if ($config?->trashed()) {
            $config->restore();
        }
        if ($config || ! isset(self::DEFAUTS[$entite])) {
            return $config;
        }

        return self::create(['tenant_id' => $tenantId, 'entite' => $entite, 'suffixe' => '', 'prochain_numero' => 1] + self::DEFAUTS[$entite]);
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
        self::pourEntite($entite, $tenantId);
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
