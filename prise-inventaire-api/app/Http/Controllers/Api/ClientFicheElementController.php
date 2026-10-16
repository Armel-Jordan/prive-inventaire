<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;

/** Listes rattachées à une fiche client (contacts, adresses) : au plus un élément « principal » par client. */
abstract class ClientFicheElementController extends FicheElementController
{
    protected function modeleParent(): string
    {
        return Client::class;
    }

    protected function cleParent(): string
    {
        return 'client_id';
    }
}
