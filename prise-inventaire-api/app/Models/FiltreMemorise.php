<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Filtres d'un écran enregistrés sous un nom par un compte (phase 5.3) ; celui « par défaut » s'applique à l'ouverture
 * de l'écran. Chaque compte ne voit que les siens.
 */
class FiltreMemorise extends Model
{
    use BelongsToTenant;

    protected $table = 'filtres_memorises';

    protected $fillable = ['tenant_id', 'admin_user_id', 'ecran', 'nom', 'valeurs', 'par_defaut'];

    protected function casts(): array
    {
        return [
            'valeurs' => 'array',
            'par_defaut' => 'boolean',
        ];
    }
}
