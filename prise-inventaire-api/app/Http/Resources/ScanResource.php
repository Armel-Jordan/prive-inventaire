<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ID,
            'numero' => $this->NUMERO,
            'type' => $this->TYPE,
            'quantite' => $this->QUANTITE,
            'unite_mesure' => $this->UNITE_MESURE,
            'employe' => $this->EMPLOYE,
            'secteur' => $this->SECTEUR,
            'date_saisie' => $this->DATE_SAISIE?->toIso8601String(),
            'scanneur' => $this->SCANNEUR,
        ];
    }
}
