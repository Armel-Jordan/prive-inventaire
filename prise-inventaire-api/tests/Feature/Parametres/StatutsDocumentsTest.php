<?php

namespace Tests\Feature\Parametres;

use App\Models\AdminUser;
use App\Models\Tenant;
use App\Support\StatutsDocuments;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Statuts des documents (plan de parité fripv2, « Statuts transactions ») : libellés et couleurs des statuts système,
 * lus par tous les écrans, réglés avec la configuration, avec retour au défaut ; un statut inconnu est refusé ; chaque
 * entreprise a les siens.
 */
class StatutsDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->agir(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), $this->tenant);
    }

    private function agir(AdminUser $compte, Tenant $tenant): void
    {
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->reset();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);
    }

    private function statut(array $liste, string $document, string $code): array
    {
        return collect($liste[$document])->firstWhere('code', $code);
    }

    public function test_reglage_et_retour_au_defaut(): void
    {
        // Le catalogue : tous les documents, libellés et couleurs par défaut.
        $liste = $this->getJson('/api/statuts-documents')->assertOk()->json();
        $this->assertEqualsCanonicalizing(array_keys(StatutsDocuments::CATALOGUE), array_keys($liste));
        $this->assertSame(['Payée', 'Paid', '#dcfce7', '#166534', false],
            array_values(array_intersect_key($this->statut($liste, 'facture', 'payee'), array_flip(['libelle_fr', 'libelle_en', 'couleur_fond', 'couleur_texte', 'regle']))));

        // Réglé : le libellé et la couleur choisis, le reste par défaut ; la liste suivante les donne aussitôt.
        $this->putJson('/api/statuts-documents/facture/payee', ['libelle_fr' => 'Réglée', 'couleur_fond' => '#000000'])->assertOk();
        $paye = $this->statut($this->getJson('/api/statuts-documents')->json(), 'facture', 'payee');
        $this->assertSame(['Réglée', 'Paid', '#000000', '#166534', true], [$paye['libelle_fr'], $paye['libelle_en'], $paye['couleur_fond'], $paye['couleur_texte'], $paye['regle']]);

        // Retour au défaut.
        $this->deleteJson('/api/statuts-documents/facture/payee')->assertOk();
        $this->assertSame('Payée', $this->statut($this->getJson('/api/statuts-documents')->json(), 'facture', 'payee')['libelle_fr']);

        // Un statut ou un document inconnu, une couleur mal écrite : refusés.
        $this->putJson('/api/statuts-documents/facture/inconnu', ['libelle_fr' => 'X'])->assertUnprocessable();
        $this->putJson('/api/statuts-documents/inconnu/payee', ['libelle_fr' => 'X'])->assertUnprocessable();
        $this->putJson('/api/statuts-documents/facture/payee', ['couleur_fond' => 'vert'])->assertUnprocessable()->assertJsonValidationErrors(['couleur_fond']);
    }

    public function test_lecture_libre_reglage_avec_la_configuration_et_isolation(): void
    {
        $this->putJson('/api/statuts-documents/devis/accepte', ['libelle_fr' => 'Signé'])->assertOk();

        // Un compte sans droit sur la configuration lit les statuts, mais ne les règle pas.
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => ['configuration.voir' => false, 'configuration.modifier' => false]])->assertOk();
        $this->agir($vendeur, $this->tenant);
        $this->assertSame('Signé', $this->statut($this->getJson('/api/statuts-documents')->assertOk()->json(), 'devis', 'accepte')['libelle_fr']);
        $this->putJson('/api/statuts-documents/devis/accepte', ['libelle_fr' => 'Gagné'])->assertForbidden();
        $this->deleteJson('/api/statuts-documents/devis/accepte')->assertForbidden();

        // Une autre entreprise garde les libellés par défaut.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->assertSame('Accepté', $this->statut($this->getJson('/api/statuts-documents')->json(), 'devis', 'accepte')['libelle_fr']);
    }
}
