<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;
use App\Models\ClientRemarque;

/** Remarques datées d'un client. Seuls leur auteur et les administrateurs les modifient ou les suppriment. */
class ClientFicheRemarqueController extends FicheRemarqueController
{
    protected function modeleParent(): string
    {
        return Client::class;
    }

    protected function cleParent(): string
    {
        return 'client_id';
    }

    protected function modele(): string
    {
        return ClientRemarque::class;
    }
}
