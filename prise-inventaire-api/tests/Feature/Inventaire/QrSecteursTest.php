<?php

namespace Tests\Feature\Inventaire;

use App\Models\AdminUser;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * QR code d'un secteur : reconnu quelle qu'en soit la forme (code imprimé par l'écran Secteurs, JSON des anciennes
 * étiquettes, QR attribué « SECT-… », nom). La validation n'acceptait que le format « SECT-… », jamais imprimé.
 */
class QrSecteursTest extends TestCase
{
    use RefreshDatabase;

    private int $secteurId;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->runAsTenant($tenant->id, function () {
            $this->secteurId = Secteur::create(['code' => 'A1', 'nom' => 'Allée 1', 'actif' => true])->id;
            Secteur::create(['code' => 'Z9', 'nom' => 'Fermée', 'actif' => false]);
        });
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(), ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);
    }

    public function test_un_qr_est_reconnu_sous_toutes_ses_formes(): void
    {
        $qrAttribue = $this->postJson("/api/secteurs/{$this->secteurId}/generate-qr")->assertOk()->json('qr_code');

        foreach (['A1', 'a1', '{"code":"A1","nom":"Allée 1","id":1}', $qrAttribue, 'allée 1'] as $contenu) {
            $this->postJson('/api/secteurs/validate-qr', ['qr_code' => $contenu])->assertOk()
                ->assertJsonPath('valide', true)
                ->assertJsonPath('secteur.code', 'A1');
        }
        $this->postJson('/api/mobile/secteurs/validate-qr', ['qr_code' => 'A1'])->assertOk()->assertJsonPath('secteur.code', 'A1');
    }

    public function test_un_qr_inconnu_ou_d_un_secteur_inactif_est_refuse(): void
    {
        $this->postJson('/api/secteurs/validate-qr', ['qr_code' => 'B7'])->assertNotFound()->assertJsonPath('valide', false);
        $this->postJson('/api/secteurs/validate-qr', ['qr_code' => 'Z9'])->assertNotFound();
    }
}
