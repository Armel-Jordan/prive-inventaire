<?php

namespace Tests\Feature\Ventes;

use App\Mail\CourrielSimple;
use App\Models\AdminUser;
use App\Models\Client;
use App\Models\CourrielEnvoi;
use App\Models\Devis;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use App\Taches\RelancerDevisSansReponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Relances des devis (phase 1.3) : relance par courriel (devis joint, boutons de réponse en ligne) comptée sur le
 * devis ; relances automatiques de la tâche planifiée (délai sans réponse, intervalle, maximum, devis répondu, expiré,
 * brouillon ou client sans courriel).
 */
class RelancesDevisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        config(['queue.default' => 'sync', 'mail.default' => 'array', 'portail.url' => 'https://clients.exemple.com']);
        Mail::fake();
        Cache::flush();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produitId] = $this->chezLeTenant(fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'email' => 'achats@client.test'])->id,
            ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function devis(string $validite = '2026-12-31', ?int $client = null, bool $envoyer = true): int
    {
        $id = $this->postJson('/api/devis', ['client_id' => $client ?? $this->clientId, 'date_devis' => '2026-10-16', 'date_validite' => $validite,
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire' => 100]]])->assertCreated()->json('id');
        if ($envoyer) {
            $this->postJson("/api/devis/{$id}/envoyer")->assertOk();
        }

        return $id;
    }

    public function test_relance_manuelle_par_courriel_comptee_sur_le_devis(): void
    {
        $devis = $this->devis();
        $numero = $this->chezLeTenant(fn () => Devis::findOrFail($devis)->numero);

        $courriel = $this->getJson("/api/courriels/preparer?type=relance_devis&document_id={$devis}")->assertOk()
            ->assertJsonPath('destinataires', ['achats@client.test'])->assertJsonPath('piece_jointe', "devis-{$numero}.pdf")->json();
        $this->assertSame("Relance : devis {$numero} — ".$this->tenant->nom, $courriel['objet']);
        $envoi = $this->postJson('/api/courriels/envois', [
            'type' => 'relance_devis', 'document_id' => $devis, 'langue' => 'fr', 'destinataires' => $courriel['destinataires'],
            'objet' => $courriel['objet'], 'contenu' => $courriel['contenu'],
        ])->assertCreated()->assertJsonPath('statut', 'envoye')->json('id');

        // Devis joint, boutons de réponse en ligne, relance comptée.
        Mail::assertSent(CourrielSimple::class, fn (CourrielSimple $c) => $c->fichiers[0]['nom'] === "devis-{$numero}.pdf" && $c->hasTo('achats@client.test'));
        $this->assertStringContainsString('Accepter le devis', $this->chezLeTenant(fn () => CourrielEnvoi::findOrFail($envoi)->corps_html));
        $fiche = $this->getJson("/api/devis/{$devis}")->assertOk()->assertJsonPath('relances', 1)->assertJsonPath('statut', 'envoye')->json();
        $this->assertNotNull($fiche['derniere_relance_le']);
    }

    public function test_relances_automatiques_delai_intervalle_et_maximum(): void
    {
        $aRelancer = $this->devis();
        $repondu = $this->devis();
        $this->postJson("/api/devis/{$repondu}/accepter")->assertOk();
        $this->devis('2026-10-20');
        $sansCourriel = $this->chezLeTenant(fn () => Client::create(['code' => 'CLI-2', 'raison_sociale' => 'Sans courriel', 'adresse_facturation' => '2 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id);
        $this->devis('2026-12-31', $sansCourriel);
        $this->devis('2026-12-31', null, false);
        $relancer = fn () => $this->chezLeTenant(fn () => app(RelancerDevisSansReponse::class)->executer());

        // Délai de 7 jours sans réponse : rien avant ; puis seulement le devis envoyé, encore valable, au client joignable.
        $this->travelTo('2026-10-20 10:00:00');
        $this->assertSame('Aucun devis à relancer.', $relancer());
        $this->travelTo('2026-10-23 10:00:00');
        $this->assertSame('1 relance(s) de devis envoyée(s).', $relancer());
        $this->assertSame([$aRelancer], $this->chezLeTenant(fn () => CourrielEnvoi::where('type', 'relance_devis')->where('statut', 'envoye')->pluck('document_id')->all()));

        // Intervalle : rien le jour même, puis de nouveau 7 jours plus tard ; 3 relances au plus.
        $this->assertSame('Aucun devis à relancer.', $relancer());
        $this->travel(7)->days();
        $this->assertSame('1 relance(s) de devis envoyée(s).', $relancer());
        $this->travel(7)->days();
        $relancer();
        $this->travel(7)->days();
        $this->assertSame('Aucun devis à relancer.', $relancer());
        $this->assertSame(3, $this->chezLeTenant(fn () => Devis::findOrFail($aRelancer)->relances));
        Mail::assertSentCount(3);

        // Une réponse en ligne arrête les relances.
        $this->chezLeTenant(fn () => Devis::whereKey($aRelancer)->update(['relances' => 0]));
        $this->travel(7)->days();
        $this->postJson("/api/devis/{$aRelancer}/accepter")->assertOk();
        $this->assertSame('Aucun devis à relancer.', $relancer());
    }
}
