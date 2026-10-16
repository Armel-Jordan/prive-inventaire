<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\TenantParametres;

/**
 * En-tête commun des PDF : coordonnées de l'entreprise émettrice (ses paramètres, à défaut le nom de
 * l'entreprise) et sa devise. Un document envoyé à un client ou à un fournisseur porte le nom de
 * l'entreprise, jamais celui de l'application.
 */
class EntreprisePdf
{
    /**
     * @return array{devise: string, entreprise: array{nom: string, adresse: ?string, telephone: ?string, email: ?string, mentions: string}}
     */
    public static function entete(): array
    {
        $parametres = TenantParametres::first();
        $tenant = Tenant::find(app(TenantContext::class)->getTenantId());

        return [
            'devise' => $parametres?->devise_symbole ?: '€',
            'entreprise' => [
                'nom' => $parametres?->nom_entreprise ?: ($tenant?->nom ?? ''),
                'adresse' => $parametres?->adresse,
                'telephone' => $parametres?->telephone,
                'email' => $parametres?->email,
                'mentions' => implode(' — ', array_filter([
                    $parametres?->siret ? 'N° d\'entreprise : '.$parametres->siret : null,
                    $parametres?->tva_numero ? 'N° de taxe : '.$parametres->tva_numero : null,
                ])),
            ],
        ];
    }
}
