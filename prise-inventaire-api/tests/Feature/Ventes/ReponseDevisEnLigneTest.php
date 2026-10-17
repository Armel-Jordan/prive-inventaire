<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\CourrielEnvoi;
use App\Models\Devis;
use App\Models\Notification;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Réponse en ligne aux devis (phase 1.3) : carte Accepter / Refuser dans le courriel du devis, devis envoyé à l'envoi
 * du courriel, page publique du lien (consultation, PDF, acceptation, refus), notification, expiration, lien partagé
 * depuis le back-office, réglage de l'entreprise, droits et isolation.
 */
class ReponseDevisEnLigneTest extends TestCase
{
    use RefreshDatabase;

    private const LIEN = '#https://clients[.]exemple[.]com/devis/([A-Za-z0-9]{64})#';

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        config(['queue.default' => 'sync', 'mail.default' => 'array', 'portail.url' => 'https://clients.exemple.com/']);
        Mail::fake();
        Cache::flush();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produitId] = $this->chezLeTenant(fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'email' => 'achats@client.test'])->id,
            ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        $this->agir(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']));
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function devis(string $validite = '2026-11-15'): int
    {
        return $this->postJson('/api/devis', ['client_id' => $this->clientId, 'date_devis' => '2026-10-16', 'date_validite' => $validite,
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 3, 'prix_unitaire' => 100]]])->assertCreated()->json('id');
    }

    /** Envoie le devis par courriel (fenêtre préparée puis envoyée) ; renvoie le courriel enregistré. */
    private function envoyerParCourriel(int $devis): CourrielEnvoi
    {
        $courriel = $this->getJson("/api/courriels/preparer?type=devis&document_id={$devis}")->assertOk()->json();
        $envoi = $this->postJson('/api/courriels/envois', [
            'type' => 'devis', 'document_id' => $devis, 'langue' => 'fr', 'destinataires' => $courriel['destinataires'],
            'objet' => $courriel['objet'], 'contenu' => $courriel['contenu'],
        ])->assertCreated()->assertJsonPath('statut', 'envoye')->json('id');

        return $this->chezLeTenant(fn () => CourrielEnvoi::findOrFail($envoi));
    }

    private function jeton(string $texte): string
    {
        $this->assertMatchesRegularExpression(self::LIEN, $texte);
        preg_match(self::LIEN, $texte, $m);

        return $m[1];
    }

    public function test_courriel_avec_carte_de_reponse_et_acceptation_en_ligne(): void
    {
        $devis = $this->devis();

        // Le courriel porte la carte de réponse ; envoyé, le brouillon passe à Envoyé.
        $envoi = $this->envoyerParCourriel($devis);
        $this->assertStringContainsString('Accepter le devis', $envoi->corps_html);
        $this->assertStringContainsString('Refuser le devis', $envoi->corps_html);
        $jeton = $this->jeton($envoi->corps_html);
        $this->assertStringContainsString("Accepter le devis : https://clients.exemple.com/devis/{$jeton}?reponse=accepter", $envoi->corps_texte);
        $fiche = $this->getJson("/api/devis/{$devis}")->assertOk()->assertJsonPath('statut', 'envoye')->json();
        $this->assertNotNull($fiche['envoye_le']);
        $this->assertArrayNotHasKey('reponse_jeton', $fiche);
        $this->assertArrayNotHasKey('reponse_jeton_hash', $fiche);
        // Le lien reste le même (un courriel déjà envoyé reste valable).
        $this->assertSame("https://clients.exemple.com/devis/{$jeton}", $this->postJson("/api/devis/{$devis}/lien-reponse")->assertOk()->json('url'));

        // Page publique, sans compte : le devis, son PDF.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $page = $this->getJson("/api/devis-en-ligne/{$jeton}")->assertOk()
            ->assertJsonPath('devis.numero', Devis::withoutGlobalScopes()->find($devis)->numero)
            ->assertJsonPath('devis.statut', 'envoye')->assertJsonPath('devis.client', 'Client Test')
            ->assertJsonPath('devis.lignes.0.description', 'Ciment')->assertJsonPath('peut_repondre', true)->json();
        $this->assertEquals(300, $page['devis']['montant_total']);
        $this->assertStringStartsWith('%PDF', $this->get("/api/devis-en-ligne/{$jeton}/pdf")->assertOk()->getContent());
        $this->getJson('/api/devis-en-ligne/'.str_repeat('a', 64))->assertNotFound();
        $this->getJson('/api/devis-en-ligne/court')->assertNotFound();

        // Acceptation : nom obligatoire ; puis le devis est accepté, une seule fois.
        $this->postJson("/api/devis-en-ligne/{$jeton}/accepter", [])->assertUnprocessable()->assertJsonValidationErrors(['nom']);
        $this->postJson("/api/devis-en-ligne/{$jeton}/accepter", ['nom' => 'Awa Ndiaye', 'commentaire' => 'Livraison lundi.'])->assertOk()
            ->assertJsonPath('devis.statut', 'accepte')->assertJsonPath('reponse.nom', 'Awa Ndiaye')->assertJsonPath('peut_repondre', false);
        $this->postJson("/api/devis-en-ligne/{$jeton}/refuser", [])->assertUnprocessable()->assertJsonPath('message', 'Ce devis a déjà été accepté.');

        // Back-office : réponse visible, notification, conversion possible.
        $this->agir(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']));
        $this->getJson("/api/devis/{$devis}")->assertJsonPath('statut', 'accepte')->assertJsonPath('reponse_en_ligne', true)
            ->assertJsonPath('reponse_nom', 'Awa Ndiaye')->assertJsonPath('reponse_commentaire', 'Livraison lundi.');
        $notification = $this->chezLeTenant(fn () => Notification::where('type', 'devis_accepte')->firstOrFail());
        $this->assertStringContainsString('Client Test (Awa Ndiaye) a accepté le devis', $notification->message);
        $this->postJson("/api/devis/{$devis}/convertir")->assertOk();
        $this->postJson("/api/devis/{$devis}/lien-reponse")->assertUnprocessable();
    }

    public function test_refus_expiration_lien_partage_et_reglage(): void
    {
        // Lien partagé depuis le back-office : le brouillon passe à Envoyé ; refus en ligne avec sa raison.
        $devis = $this->devis();
        $url = $this->postJson("/api/devis/{$devis}/lien-reponse")->assertOk()->assertJsonPath('devis.statut', 'envoye')->json('url');
        $jeton = $this->jeton($url);
        $this->postJson("/api/devis-en-ligne/{$jeton}/refuser", ['commentaire' => 'Trop cher.'])->assertOk()->assertJsonPath('devis.statut', 'refuse');
        $this->getJson("/api/devis/{$devis}")->assertJsonPath('statut', 'refuse')->assertJsonPath('reponse_commentaire', 'Trop cher.');
        $this->assertTrue($this->chezLeTenant(fn () => Notification::where('type', 'devis_refuse')->exists()));

        // Devis expiré : la page le dit, la réponse est refusée et le devis passe à Expiré.
        $court = $this->devis('2026-10-20');
        $jetonCourt = $this->jeton($this->envoyerParCourriel($court)->corps_html);
        $this->travelTo('2026-10-25 10:00:00');
        $this->getJson("/api/devis-en-ligne/{$jetonCourt}")->assertOk()->assertJsonPath('devis.statut', 'expire')->assertJsonPath('peut_repondre', false);
        $this->postJson("/api/devis-en-ligne/{$jetonCourt}/accepter", ['nom' => 'Awa'])->assertUnprocessable();
        $this->getJson("/api/devis/{$court}")->assertJsonPath('statut', 'expire');

        // Réponse notée à la main : sans nom en ligne.
        $manuel = $this->devis('2026-11-30');
        $this->postJson("/api/devis/{$manuel}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$manuel}/accepter")->assertOk();
        $this->getJson("/api/devis/{$manuel}")->assertJsonPath('reponse_en_ligne', false)->assertJsonPath('reponse_nom', null);

        // Réponse en ligne désactivée : ni carte dans le courriel, ni lien.
        $this->putJson('/api/courriels/parametres', ['transport' => 'plateforme', 'devis_reponse_en_ligne' => false])->assertOk()
            ->assertJsonPath('devis_reponse_en_ligne', false);
        $sans = $this->devis('2026-11-30');
        $envoi = $this->envoyerParCourriel($sans);
        $this->assertStringNotContainsString('Accepter le devis', $envoi->corps_html);
        $this->postJson("/api/devis/{$sans}/lien-reponse")->assertUnprocessable();
    }

    public function test_adresse_du_site_droits_et_isolation(): void
    {
        // Sans ESPACE_CLIENT_URL : l'adresse transmise par le back-office est retenue.
        config(['portail.url' => null]);
        $devis = $this->devis();
        $this->postJson("/api/devis/{$devis}/lien-reponse")->assertUnprocessable();
        $url = $this->postJson("/api/devis/{$devis}/lien-reponse", ['origine' => 'http://127.0.0.1:5185/'])->assertOk()->json('url');
        $this->assertStringStartsWith('http://127.0.0.1:5185/devis/', $url);

        // Partager le lien demande le droit d'envoyer un devis.
        $admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->agir($vendeur);
        $this->postJson("/api/devis/{$devis}/lien-reponse")->assertOk();
        $this->agir($admin);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => ['devis.envoyer' => false]])->assertOk();
        $this->agir($vendeur->fresh());
        $this->postJson("/api/devis/{$devis}/lien-reponse")->assertForbidden();
        $this->getJson("/api/devis/{$devis}")->assertOk();

        // Entreprise sans le module Ventes : le lien ne mène plus à rien.
        $jeton = substr($url, strrpos($url, '/') + 1);
        $this->tenant->update(['modules' => ['inventaire']]);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson("/api/devis-en-ligne/{$jeton}")->assertNotFound();
        $this->postJson("/api/devis-en-ligne/{$jeton}/accepter", ['nom' => 'Awa'])->assertNotFound();
    }
}
