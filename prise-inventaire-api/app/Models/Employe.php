<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employe extends Model
{
    protected $connection = 'oracle';
    protected $table = 'EMPLOYE';
    public $timestamps = false;

    protected $primaryKey = 'NUMERO';
    protected $keyType = 'string';
    public $incrementing = false;
}
