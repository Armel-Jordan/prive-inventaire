<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fichier joint à une fiche ou à un document. */
class PieceJointe extends Model
{
    use BelongsToTenant;

    protected $table = 'pieces_jointes';

    protected $fillable = [
        'entite',
        'entite_id',
        'categorie',
        'description',
        'nom_original',
        'disque',
        'chemin',
        'type_mime',
        'taille',
        'ajoute_par',
    ];

    // L'emplacement de stockage reste interne : le fichier se télécharge par l'API.
    protected $hidden = ['disque', 'chemin'];

    protected function casts(): array
    {
        return [
            'entite_id' => 'integer',
            'taille' => 'integer',
        ];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'ajoute_par');
    }
}
