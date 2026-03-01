<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $connection = 'mysql';
    protected $table = 'audit_logs';

    protected $fillable = [
        'action',
        'model_type',
        'model_id',
        'user_id',
        'user_name',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public static function log(
        string $action,
        string $modelType,
        int|string|null $modelId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $userId = null,
        ?string $userName = null
    ): self {
        return self::create([
            'action' => $action,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'user_id' => $userId,
            'user_name' => $userName,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
