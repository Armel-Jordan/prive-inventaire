<?php

namespace App\Http\Controllers\Api;

use App\Models\ClientContact;

/** Contacts d'un client : qui joindre, et qui reçoit factures et commandes. */
class ClientFicheContactController extends ClientFicheElementController
{
    protected function modele(): string
    {
        return ClientContact::class;
    }

    protected function colonnePrincipale(): string
    {
        return 'principal';
    }

    protected function ordre(): string
    {
        return 'nom';
    }

    protected function regles(): array
    {
        return [
            'nom' => ['required', 'string', 'max:150'],
            'fonction' => ['nullable', 'string', 'max:100'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'telephone_secondaire' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'principal' => ['sometimes', 'boolean'],
            'recoit_factures' => ['sometimes', 'boolean'],
            'recoit_commandes' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
