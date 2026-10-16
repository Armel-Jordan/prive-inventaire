<?php

namespace App\Http\Controllers\Api;

use App\Models\ClientAdresse;

/** Adresses supplémentaires d'un client (chantiers, succursales, points de livraison). */
class ClientFicheAdresseController extends ClientFicheElementController
{
    protected function modele(): string
    {
        return ClientAdresse::class;
    }

    protected function colonnePrincipale(): string
    {
        return 'principale';
    }

    protected function ordre(): string
    {
        return 'libelle';
    }

    protected function regles(): array
    {
        return [
            'libelle' => ['required', 'string', 'max:100'],
            'adresse' => ['required', 'string', 'max:1000'],
            'ville' => ['nullable', 'string', 'max:100'],
            'code_postal' => ['nullable', 'string', 'max:20'],
            'region' => ['nullable', 'string', 'max:100'],
            'pays' => ['nullable', 'string', 'max:100'],
            'livraison' => ['sometimes', 'boolean'],
            'principale' => ['sometimes', 'boolean'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
