<?php

namespace App\Http\Controllers\Api;

use App\Models\FournisseurCompteBancaire;
use Illuminate\Http\Request;

/**
 * Coordonnées bancaires d'un fournisseur. Lecture pour tous ; saisie réservée aux administrateurs et
 * managers (routes), avec l'auteur de la dernière modification, affiché sur la fiche.
 */
class FournisseurFicheCompteController extends FournisseurFicheElementController
{
    protected function modele(): string
    {
        return FournisseurCompteBancaire::class;
    }

    protected function colonnePrincipale(): string
    {
        return 'principal';
    }

    protected function ordre(): string
    {
        return 'banque';
    }

    protected function avec(): array
    {
        return ['auteurModification:id,nom'];
    }

    /** IBAN et BIC saisis avec espaces ou en minuscules : on les compacte en majuscules. */
    protected function normaliser(Request $request): void
    {
        foreach (['iban', 'bic', 'devise'] as $champ) {
            if (is_string($request->input($champ))) {
                $request->merge([$champ => strtoupper(preg_replace('/\s+/', '', $request->input($champ))) ?: null]);
            }
        }
    }

    protected function completer(array $donnees, Request $request): array
    {
        return [...$donnees, 'modifie_par' => $request->user()->id];
    }

    protected function regles(): array
    {
        return [
            'banque' => ['required', 'string', 'max:150'],
            'agence' => ['nullable', 'string', 'max:150'],
            'titulaire' => ['nullable', 'string', 'max:150'],
            'numero_compte' => ['nullable', 'required_without:iban', 'string', 'max:50'],
            'iban' => ['nullable', 'required_without:numero_compte', 'string', 'regex:/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/'],
            'bic' => ['nullable', 'string', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/'],
            'devise' => ['nullable', 'string', 'size:3'],
            'principal' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'actif' => ['sometimes', 'boolean'],
        ];
    }
}
