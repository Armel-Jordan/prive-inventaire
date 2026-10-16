<?php

namespace Tests\Feature\Portail;

use App\Mail\InvitationPortail;
use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ClientAdresse;
use App\Models\Configuration;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Portail client (phase 1.11), accès : réglages et accès gérés par l'entreprise, invitation, choix du mot de
 * passe, connexion, mot de passe oublié, profil, et cloisonnement des sessions (portail et employés).
 */
class PortailClientTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private int $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->tenant = Tenant::factory()->create(['slug' => 'quincaillerie', 'modules' => ['ventes']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->employe();
        $this->client = app(TenantContext::class)->runAsTenant($this->tenant->id, function () {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie du Port']);
            $client = Client::create(['code' => 'C-1', 'raison_sociale' => 'Bâtiments du Littoral', 'adresse_facturation' => '1 rue du Port', 'ville' => 'Douala', 'code_postal' => '0', 'actif' => true]);
            ClientAdresse::create(['client_id' => $client->id, 'libelle' => 'Chantier Bonapriso', 'adresse' => '12 avenue de Gaulle', 'ville' => 'Douala', 'code_postal' => '0', 'livraison' => true, 'actif' => true]);

            return $client->id;
        });
    }

    private function employe(?AdminUser $compte = null): void
    {
        Sanctum::actingAs($compte ?? $this->admin, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    /** Ouvre le portail et crée un accès ; renvoie le jeton du lien d'invitation. */
    private function inviter(string $email = 'achats@littoral.test'): string
    {
        $this->putJson('/api/portail-clients/parametres', ['actif' => true, 'message_accueil' => 'Bienvenue !'])->assertOk();
        $lien = $this->postJson('/api/portail-clients/comptes', ['client_id' => $this->client, 'nom' => 'Awa Mbappé', 'email' => $email])
            ->assertCreated()->json('lien');
        parse_str((string) parse_url($lien, PHP_URL_QUERY), $parametres);

        return $parametres['jeton'];
    }

    /** Requête du portail avec un jeton de session (sans jeton d'employé). */
    private function portail(string $methode, string $uri, string $jeton, array $donnees = []): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$jeton}"])->json($methode, $uri, $donnees);
    }

    public function test_reglages_et_acces_geres_par_l_entreprise(): void
    {
        $this->getJson('/api/portail/quincaillerie')->assertNotFound();

        $this->inviter();
        Mail::assertSent(InvitationPortail::class, fn (InvitationPortail $m) => $m->hasTo('achats@littoral.test') && $m->entreprise === 'Quincaillerie du Port' && ! $m->nouveauMotDePasse);
        $this->getJson('/api/portail/quincaillerie')->assertOk()->assertJsonPath('entreprise', 'Quincaillerie du Port')->assertJsonPath('message_accueil', 'Bienvenue !');

        // Numérotation manuelle des commandes client : l'écran prévient que le portail refusera les commandes.
        app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => Configuration::create(['entite' => 'commande', 'prefixe' => 'CMD', 'suffixe' => '', 'separateur' => '-', 'longueur' => 4, 'auto_increment' => false, 'prochain_numero' => 1]));
        $etat = $this->getJson('/api/portail-clients')->assertOk()->json();
        $this->assertTrue($etat['parametres']['actif']);
        $this->assertFalse($etat['commandes_pretes']);
        $this->assertSame([['Awa Mbappé', 'invite', 'C-1']], array_map(fn ($c) => [$c['nom'], $c['etat'], $c['client']['code']], $etat['comptes']));

        // Une adresse n'a qu'un accès (sans tenir compte de la casse).
        $this->postJson('/api/portail-clients/comptes', ['client_id' => $this->client, 'nom' => 'Doublon', 'email' => 'ACHATS@littoral.test'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Cette adresse a déjà un accès au portail.');

        // Un utilisateur simple consulte mais ne modifie rien.
        $this->employe(AdminUser::factory()->for($this->tenant)->create(['role' => 'user']));
        $this->getJson('/api/portail-clients')->assertOk();
        $this->putJson('/api/portail-clients/parametres', ['actif' => false])->assertForbidden();
    }

    public function test_invitation_mot_de_passe_et_connexion(): void
    {
        $lien = $this->inviter();

        // Avant l'invitation acceptée, pas de connexion possible.
        $this->postJson('/api/portail/quincaillerie/connexion', ['email' => 'achats@littoral.test', 'password' => 'n importe quoi'])->assertUnprocessable();

        $this->postJson('/api/portail/quincaillerie/activation', ['jeton' => str_repeat('x', 64), 'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1'])
            ->assertUnprocessable()->assertJsonPath('message', 'Ce lien n\'est plus valable : demandez-en un nouveau.');
        $this->postJson('/api/portail/quincaillerie/activation', ['jeton' => $lien, 'password' => 'court', 'password_confirmation' => 'court'])->assertUnprocessable();
        $jeton = $this->postJson('/api/portail/quincaillerie/activation', ['jeton' => $lien, 'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1'])
            ->assertOk()->assertJsonPath('compte.nom', 'Awa Mbappé')->json('token');
        // Le lien ne sert qu'une fois.
        $this->postJson('/api/portail/quincaillerie/activation', ['jeton' => $lien, 'password' => 'autremotdepasse', 'password_confirmation' => 'autremotdepasse'])->assertUnprocessable();

        $moi = $this->portail('GET', '/api/espace-client/moi', $jeton)->assertOk()->json();
        $this->assertSame(['Bâtiments du Littoral', 'Chantier Bonapriso', 'Quincaillerie du Port'], [$moi['client']['raison_sociale'], $moi['client']['adresses'][0]['libelle'], $moi['entreprise']['nom']]);

        $this->postJson('/api/portail/quincaillerie/connexion', ['email' => 'achats@littoral.test', 'password' => 'mauvais'])
            ->assertUnprocessable()->assertJsonPath('message', 'Adresse ou mot de passe incorrect.');
        $this->postJson('/api/portail/quincaillerie/connexion', ['email' => 'Achats@Littoral.test', 'password' => 'motdepasse1'])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/portail/autre-entreprise/connexion', ['email' => 'achats@littoral.test', 'password' => 'motdepasse1'])->assertNotFound();

        // Déconnexion : le jeton ne vaut plus.
        $this->portail('POST', '/api/espace-client/deconnexion', $jeton)->assertOk();
        $this->portail('GET', '/api/espace-client/moi', $jeton)->assertUnauthorized();
    }

    public function test_sessions_du_portail_et_des_employes_cloisonnees(): void
    {
        $jeton = $this->postJson('/api/portail/quincaillerie/activation', ['jeton' => $this->inviter(), 'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1'])->json('token');

        // Un jeton du portail n'ouvre aucune route des employés.
        $this->portail('GET', '/api/clients', $jeton)->assertUnauthorized();
        $this->portail('GET', '/api/commandes-client', $jeton)->assertUnauthorized();
        // Un jeton d'employé n'ouvre pas l'espace client.
        $jetonEmploye = $this->admin->createToken('test')->plainTextToken;
        $this->portail('GET', '/api/espace-client/moi', $jetonEmploye)->assertUnauthorized();

        // Accès suspendu ou portail fermé : la session ne vaut plus.
        $this->portail('GET', '/api/espace-client/moi', $jeton)->assertOk();
        $this->employe();
        $this->putJson('/api/portail-clients/parametres', ['actif' => false])->assertOk();
        $this->portail('GET', '/api/espace-client/moi', $jeton)->assertForbidden()->assertJsonPath('portail_ferme', true);
        $this->employe();
        $this->putJson('/api/portail-clients/parametres', ['actif' => true])->assertOk();
        $compte = $this->getJson('/api/portail-clients')->json('comptes.0.id');
        $this->putJson("/api/portail-clients/comptes/{$compte}", ['nom' => 'Awa Mbappé', 'email' => 'achats@littoral.test', 'actif' => false])->assertOk()->assertJsonPath('etat', 'suspendu');
        $this->portail('GET', '/api/espace-client/moi', $jeton)->assertUnauthorized();
    }

    public function test_mot_de_passe_oublie_et_profil(): void
    {
        $jeton = $this->postJson('/api/portail/quincaillerie/activation', ['jeton' => $this->inviter(), 'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1'])->json('token');
        $this->withoutDefer();

        $message = 'Si un compte correspond à cette adresse, un courriel avec un lien vient de vous être envoyé.';
        $this->postJson('/api/portail/quincaillerie/mot-de-passe-oublie', ['email' => 'inconnu@littoral.test'])->assertOk()->assertJsonPath('message', $message);
        $this->postJson('/api/portail/quincaillerie/mot-de-passe-oublie', ['email' => 'achats@littoral.test'])->assertOk()->assertJsonPath('message', $message);
        Mail::assertSent(InvitationPortail::class, fn (InvitationPortail $m) => $m->nouveauMotDePasse && $m->hasTo('achats@littoral.test'));

        $this->portail('PUT', '/api/espace-client/moi', $jeton, ['nom' => 'Awa M.', 'telephone' => '+237 6 99 00 00 00'])->assertOk()->assertJsonPath('compte.nom', 'Awa M.');
        $this->portail('PUT', '/api/espace-client/moi/mot-de-passe', $jeton, ['mot_de_passe_actuel' => 'faux', 'password' => 'nouveaumdp1', 'password_confirmation' => 'nouveaumdp1'])
            ->assertUnprocessable()->assertJsonValidationErrors('mot_de_passe_actuel');
        $autre = $this->postJson('/api/portail/quincaillerie/connexion', ['email' => 'achats@littoral.test', 'password' => 'motdepasse1'])->json('token');
        $this->portail('PUT', '/api/espace-client/moi/mot-de-passe', $jeton, ['mot_de_passe_actuel' => 'motdepasse1', 'password' => 'nouveaumdp1', 'password_confirmation' => 'nouveaumdp1'])->assertOk();
        // Les autres sessions sont fermées, la session courante reste ouverte.
        $this->portail('GET', '/api/espace-client/moi', $autre)->assertUnauthorized();
        $this->portail('GET', '/api/espace-client/moi', $jeton)->assertOk();
    }
}
