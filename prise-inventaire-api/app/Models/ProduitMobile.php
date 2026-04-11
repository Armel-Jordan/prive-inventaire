<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProduitMobile extends Model
{
    protected $connection = 'oracle';

    protected $table = 'V_PRODUITS_MOBILES';

    public $timestamps = false;

    protected $primaryKey = 'NUMERO';

    protected $keyType = 'string';

    public $incrementing = false;
}
