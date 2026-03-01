<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProduitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'numero' => $this->NUMERO,
            'description' => $this->DESCRIPTION,
            'mesure' => $this->MESURE,
            'type' => $this->TYPE,
        ];
    }
}
