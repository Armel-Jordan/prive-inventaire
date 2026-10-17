<?php

namespace App\Rapports;

use App\Models\AdminUser;
use App\Models\Tenant;
use App\Support\Droits;
use Illuminate\Support\Collection;

/**
 * Bibliothèque des rapports (plan de parité fripv2, phase 5.1) : les rapports par catégorie. Un compte voit ceux dont il
 * a le droit de voir le module, dans les modules de son entreprise (ventes, achats).
 */
final class Catalogue
{
    /** @var list<class-string<Rapport>> */
    public const RAPPORTS = [
        VentesParPeriode::class, VentesParClient::class, VentesParProduit::class, VentesParCategorie::class, DevisParStatut::class,
        CommandesClientEnCours::class, ClientsInactifs::class,
        AchatsParFournisseur::class, CommandesFournisseurEnCours::class,
        StockProduits::class, ProduitsDormants::class,
        EncaissementsParMode::class,
    ];

    public const CATEGORIES = ['ventes', 'clients', 'achats', 'inventaire', 'finance'];

    /** Module de l'entreprise dont dépend chaque catégorie (l'inventaire est toujours là). */
    private const MODULES_ENTREPRISE = ['ventes' => 'ventes', 'clients' => 'ventes', 'finance' => 'ventes', 'achats' => 'achats'];

    /** @return Collection<string, Rapport> par code */
    public static function tous(): Collection
    {
        return collect(self::RAPPORTS)->map(fn (string $classe) => app($classe))->keyBy(fn (Rapport $r) => $r->code());
    }

    /** Les rapports qu'un compte peut ouvrir dans son entreprise. */
    public static function pour(AdminUser $compte, ?Tenant $entreprise): Collection
    {
        $droits = app(Droits::class);

        return self::tous()->filter(fn (Rapport $r) => $droits->permet($compte, $r->module(), 'voir')
            && (! isset(self::MODULES_ENTREPRISE[$r->categorie()]) || ($entreprise?->hasModule(self::MODULES_ENTREPRISE[$r->categorie()]) ?? true)));
    }
}
