<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Facture;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Services\DocumentsVentePdf;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Notes de bas de page des documents de vente (plan de parité fripv2, socle S4.4) : celle par défaut du type de document
 * (dans la langue), une autre choisie ou aucune ; le texte se fige à l'émission ; une note retenue ne se supprime pas.
 */
class NotesBasPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->ids = $this->chez(fn () => [
            'client' => Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => 'Rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            'produit' => ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
        $note = fn (array $d) => $this->postJson('/api/referentiels/notes_bas_page', $d + ['actif' => true])->assertCreated()->json('id');
        $this->ids += [
            'anglais' => $note(['nom' => 'Default EN', 'document' => 'facture', 'langue' => 'en', 'contenu' => 'Thank you for your business.', 'par_defaut' => true]),
            'generale' => $note(['nom' => 'Générale', 'document' => 'facture', 'contenu' => 'Merci de votre confiance.', 'par_defaut' => true]),
            'export' => $note(['nom' => 'Export', 'document' => 'facture', 'contenu' => "Exonération export.\nArticle 128 du CGI.", 'par_defaut' => false]),
            'devis' => $note(['nom' => 'Devis', 'document' => 'devis', 'contenu' => 'Devis valable 30 jours.', 'par_defaut' => true]),
        ];
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function note(int $facture): ?string
    {
        return $this->chez(fn () => app(DocumentsVentePdf::class)->facture(Facture::findOrFail($facture))['note_bas_page']);
    }

    public function test_par_defaut_choisie_aucune_et_figee(): void
    {
        $facture = $this->postJson('/api/factures', ['client_id' => $this->ids['client'], 'date_facture' => '2026-11-10',
            'lignes' => [['produit_id' => $this->ids['produit'], 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->assertCreated()->json('id');

        // Par défaut : celle du type de document en français (ou pour toutes les langues), pas l'anglaise.
        $this->assertSame('Merci de votre confiance.', $this->note($facture));

        // Une autre, aucune, puis de nouveau celle par défaut ; celle d'un autre type de document est refusée.
        $this->putJson("/api/factures/{$facture}/note-bas-page", ['note_bas_page_id' => $this->ids['export']])->assertOk()
            ->assertJsonPath('texte', "Exonération export.\nArticle 128 du CGI.");
        $this->putJson("/api/factures/{$facture}/note-bas-page", ['note_bas_page_id' => 0])->assertOk()->assertJsonPath('texte', null);
        $this->assertNull($this->note($facture));
        $this->putJson("/api/factures/{$facture}/note-bas-page", ['note_bas_page_id' => $this->ids['devis']])->assertUnprocessable()
            ->assertJsonValidationErrors(['note_bas_page_id']);
        $this->putJson("/api/factures/{$facture}/note-bas-page", ['note_bas_page_id' => null])->assertOk()->assertJsonPath('texte', 'Merci de votre confiance.');

        // Émise avec la note « Export » : son texte se fige, et le choix ne se change plus.
        $this->putJson("/api/factures/{$facture}/note-bas-page", ['note_bas_page_id' => $this->ids['export']])->assertOk();
        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $this->putJson("/api/referentiels/notes_bas_page/{$this->ids['export']}", ['nom' => 'Export', 'document' => 'facture', 'contenu' => 'Texte changé', 'actif' => true])->assertOk();
        $this->assertSame("Exonération export.\nArticle 128 du CGI.", $this->note($facture));
        $this->putJson("/api/factures/{$facture}/note-bas-page", ['note_bas_page_id' => 0])->assertUnprocessable();

        // Retenue par un document, elle ne se supprime pas ; libre, si.
        $this->deleteJson("/api/referentiels/notes_bas_page/{$this->ids['export']}")->assertUnprocessable()
            ->assertJsonPath('message', 'Cette note de bas de page est retenue par des documents : désactivez-la plutôt.');
        $this->deleteJson("/api/referentiels/notes_bas_page/{$this->ids['anglais']}")->assertOk();
    }

    public function test_devis_et_isolation(): void
    {
        $devis = $this->postJson('/api/devis', ['client_id' => $this->ids['client'], 'date_devis' => '2026-11-10', 'date_validite' => '2026-12-10',
            'lignes' => [['produit_id' => $this->ids['produit'], 'quantite' => 1, 'prix_unitaire' => 100]]])->json('id');
        $this->assertSame('Devis valable 30 jours.', $this->chez(fn () => app(DocumentsVentePdf::class)->devis(\App\Models\Devis::findOrFail($devis))['note_bas_page']));

        // La note d'une autre entreprise est refusée.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $etrangere = app(TenantContext::class)->runAsTenant($autre->id, fn () => \App\Models\NoteBasPage::create(['nom' => 'X', 'document' => 'devis', 'contenu' => 'Ailleurs', 'actif' => true])->id);
        $this->putJson("/api/devis/{$devis}/note-bas-page", ['note_bas_page_id' => $etrangere])->assertUnprocessable()->assertJsonValidationErrors(['note_bas_page_id']);
    }
}
