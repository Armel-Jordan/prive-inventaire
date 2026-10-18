<?php

namespace Tests\Feature\Support;

use App\Mail\CourrielSimple;
use App\Models\AdminUser;
use App\Models\Notification;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Demandes de support et suggestions (phase 6.7) : un compte écrit à l'éditeur (courriel à l'adresse du support), suit
 * sa demande et y répond ; l'éditeur (super-administrateur) voit toutes les demandes, répond, fait avancer le statut
 * (notification à l'entreprise) ; visibilité (la sienne, ou toutes avec le droit de voir) ; droits et isolation.
 */
class DemandesSupportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $usager;

    protected function setUp(): void
    {
        parent::setUp();
        config(['support.adresse' => 'support@editeur.test', 'mail.from.address' => 'plateforme@editeur.test', 'mail.from.name' => 'Plateforme']);
        $this->tenant = Tenant::factory()->create(['nom' => 'Entreprise Alpha']);
        $this->usager = AdminUser::factory()->for($this->tenant)->create(['role' => 'user', 'nom' => 'Paul Usager', 'email' => 'paul@alpha.test']);
        $this->agir($this->usager);
    }

    private function agir(AdminUser|SuperAdmin $compte): void
    {
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->reset();
        Sanctum::actingAs($compte, ['*']);
        if ($compte instanceof AdminUser) {
            $this->withHeader('X-Tenant-Slug', $compte->tenant->slug);
        }
    }

    private function demande(array $donnees = []): array
    {
        return $this->postJson('/api/support', $donnees + [
            'type' => 'support', 'sujet' => "L'export des scans est vide", 'description' => "Depuis ce matin, l'export CSV des scans ne contient que l'en-tête.",
            'priorite' => 'haute', 'ecran' => '/scans',
        ])->assertCreated()->json();
    }

    public function test_cycle_d_une_demande_entre_l_usager_et_l_editeur(): void
    {
        Mail::fake();
        $demande = $this->demande();
        $this->assertSame(['support', 'ouverte', 'haute', 'Paul Usager', 'usager', []],
            [$demande['type'], $demande['statut'], $demande['priorite'], $demande['auteur'], $demande['dernier_auteur'], $demande['messages']]);
        // L'éditeur est prévenu ; il peut répondre directement à l'usager.
        Mail::assertSent(CourrielSimple::class, fn (CourrielSimple $c) => $c->hasTo('support@editeur.test')
            && str_contains($c->objetCourriel, "Nouvelle demande de support n° {$demande['id']} — Entreprise Alpha : L'export des scans est vide")
            && str_contains($c->corpsTexte, 'Écran : /scans') && $c->reponseA === 'paul@alpha.test');

        // L'éditeur la voit avec toutes les autres, répond : la demande attend l'usager, qui est prévenu.
        $this->agir(SuperAdmin::factory()->create(['nom' => 'Sophie Support']));
        $liste = $this->getJson('/api/super-admin/support?actives=1')->assertOk()->json();
        $this->assertSame([[$demande['id'], 'Entreprise Alpha', 'paul@alpha.test']], array_map(fn ($d) => [$d['id'], $d['entreprise'], $d['auteur_email']], $liste['demandes']));
        $this->assertSame(['ouverte' => 1], $liste['par_statut']);
        $reponse = $this->postJson("/api/super-admin/support/{$demande['id']}/messages", ['message' => "Pouvez-vous préciser l'entrepôt filtré ?"])->assertCreated()->json();
        $this->assertSame(['en_attente', 'editeur', 'Sophie Support'], [$reponse['statut'], $reponse['dernier_auteur'], $reponse['messages'][0]['nom']]);
        $notification = app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => Notification::where('type', 'support')->firstOrFail());
        $this->assertSame(["Réponse à votre demande n° {$demande['id']}", "/support?demande={$demande['id']}", 'Paul Usager'],
            [$notification->titre, $notification->lien, $notification->destinataire]);

        // L'usager répond : la demande repasse en cours, l'éditeur est prévenu.
        $this->agir($this->usager);
        $suite = $this->postJson("/api/support/{$demande['id']}/messages", ['message' => 'Entrepôt de Douala.'])->assertCreated()->json();
        $this->assertSame(['en_cours', 2], [$suite['statut'], count($suite['messages'])]);
        Mail::assertSent(CourrielSimple::class, fn (CourrielSimple $c) => str_contains($c->objetCourriel, "Réponse de l'usager n° {$demande['id']}"));

        // L'éditeur la résout (l'usager est prévenu) ; une nouvelle réponse de l'usager la rouvre ; l'usager la ferme.
        $this->agir(SuperAdmin::factory()->create());
        $resolue = $this->putJson("/api/super-admin/support/{$demande['id']}", ['statut' => 'resolue', 'priorite' => 'normale'])->assertOk()->json();
        $this->assertSame(['resolue', 'normale'], [$resolue['statut'], $resolue['priorite']]);
        $this->assertNotNull($resolue['resolue_le']);
        $this->assertSame(2, app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => Notification::where('type', 'support')->count()));
        $this->agir($this->usager);
        $rouverte = $this->postJson("/api/support/{$demande['id']}/messages", ['message' => 'Le problème est revenu.'])->assertCreated()->json();
        $this->assertSame(['ouverte', null], [$rouverte['statut'], $rouverte['resolue_le']]);
        $this->postJson("/api/support/{$demande['id']}/fermer")->assertOk()->assertJsonPath('statut', 'fermee');
    }

    public function test_visibilite_droits_et_isolation(): void
    {
        $demande = $this->demande(['type' => 'suggestion', 'priorite' => null, 'sujet' => 'Exporter les scans en Excel']);
        $this->assertSame('normale', $demande['priorite']);

        // Un collègue (sans le droit de voir le support) ne voit pas la demande d'un autre.
        $collegue = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->agir($collegue);
        $this->assertSame([false, []], [$this->getJson('/api/support')->json('toutes'), $this->getJson('/api/support')->json('demandes')]);
        $this->getJson("/api/support/{$demande['id']}")->assertNotFound();
        $this->postJson("/api/support/{$demande['id']}/messages", ['message' => 'Moi aussi.'])->assertNotFound();

        // Un administrateur de l'entreprise voit toutes les demandes.
        $admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->agir($admin);
        $this->assertSame([true, [$demande['id']]], [$this->getJson('/api/support')->json('toutes'), array_column($this->getJson('/api/support')->json('demandes'), 'id')]);
        $this->getJson("/api/support/{$demande['id']}")->assertOk()->assertJsonPath('sujet', 'Exporter les scans en Excel');

        // Sans le droit d'écrire à l'éditeur : refus.
        $this->putJson("/api/acces-comptes/{$collegue->id}", ['groupes' => [], 'exceptions' => ['support.creer' => false]])->assertOk();
        $this->agir($collegue);
        $this->postJson('/api/support', ['type' => 'support', 'sujet' => 'Aide', 'description' => 'Aide'])->assertForbidden();

        // Une autre entreprise ne voit rien ; contrôles de saisie.
        $this->agir(AdminUser::factory()->for(Tenant::factory()->create())->create(['role' => 'admin']));
        $this->assertSame([], $this->getJson('/api/support')->json('demandes'));
        $this->getJson("/api/support/{$demande['id']}")->assertNotFound();
        $this->postJson('/api/support', ['type' => 'autre', 'sujet' => '', 'description' => 'x', 'priorite' => 'critique'])
            ->assertUnprocessable()->assertJsonValidationErrors(['type', 'sujet', 'priorite']);

        // Les routes de l'éditeur sont réservées aux super-administrateurs.
        $this->getJson('/api/super-admin/support')->assertForbidden();
    }
}
