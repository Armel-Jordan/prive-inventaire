<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class SuperAdmin extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $table = 'super_admins';

    protected $fillable = [
        'nom',
        'email',
        'password',
        'actif',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'derniere_connexion' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
