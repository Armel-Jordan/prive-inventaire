<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'numero' => $this->NUMERO,
            'nom' => $this->NOM,
        ];
    }
}
