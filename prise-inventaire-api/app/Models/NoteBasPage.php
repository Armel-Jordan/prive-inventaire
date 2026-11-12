<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Note de bas de page d'un type de document de vente, dans une langue (socle S4.4). */
class NoteBasPage extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** Types de document qui ont des notes de bas de page. */
    public const DOCUMENTS = ['devis', 'commande_client', 'facture', 'bon_livraison'];

    public const LANGUES = ['fr', 'en'];

    protected $table = 'notes_bas_page';

    protected $fillable = ['tenant_id', 'nom', 'document', 'langue', 'contenu', 'par_defaut', 'actif'];

    protected $casts = [
        'par_defaut' => 'boolean',
        'actif' => 'boolean',
    ];
}
