<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Lecture d'une notification par un compte (et son masquage pour ce compte seul). */
class NotificationLecture extends Model
{
    use BelongsToTenant;

    // Même connexion que les notifications : les deux tables se lisent ensemble.
    protected $connection = 'mysql';

    protected $table = 'notification_lectures';

    protected $fillable = ['notification_id', 'admin_user_id', 'lu_le', 'masquee'];

    protected function casts(): array
    {
        return ['lu_le' => 'datetime', 'masquee' => 'boolean'];
    }
}
