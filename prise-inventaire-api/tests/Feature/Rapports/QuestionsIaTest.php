<?php

namespace Tests\Feature\Rapports;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\QuestionIa;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Questions à l'IA sur les rapports (phase 5.4), l'API d'Anthropic simulée : disponibilité (clé du serveur, module de
 * l'entreprise), réponse tirée des rapports exécutés avec les droits du compte, rapport non permis et paramètres
 * invalides renvoyés à l'IA, échecs du service, limite par jour, droits et isolation.
 */
class QuestionsIaTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://api.anthropic.com/v1/messages';

    private Tenant $tenant;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // La limite par minute de l'adresse (cache partagé entre les tests) n'est pas l'objet de ces tests ; celle par jour l'est.
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['ia.cle' => 'cle-de-test', 'ia.url' => self::URL, 'ia.modele' => 'claude-opus-5-5', 'ia.tours' => 6, 'ia.questions_par_jour' => 50]);
        $this->travelTo('2026-10-17 09:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats', 'ia']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->agir($this->admin);
        [$client, $produit] = app(TenantContext::class)->runAsTenant($this->tenant->id, function () {
            TenantParametres::create(['nom_entreprise' => 'Entreprise Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF']);

            return [
                Client::create(['code' => 'C-1', 'raison_sociale' => 'Bar du Port', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'actif' => true])->id,
                ProduitTenant::create(['numero' => 'BIERE', 'description' => 'Bière', 'categorie' => 'Boissons', 'mesure' => 'UN', 'prix_unitaire' => 100, 'actif' => true])->id,
            ];
        });
        foreach ([['2026-09-20', 10], ['2026-10-05', 5]] as [$date, $quantite]) {
            $id = $this->postJson('/api/factures', ['client_id' => $client, 'date_facture' => $date, 'lignes' => [
                ['produit_id' => $produit, 'quantite' => $quantite, 'prix_unitaire_ht' => 100, 'taux_tva' => 0],
            ]])->assertCreated()->json('id');
            $this->postJson("/api/factures/{$id}/emettre")->assertOk();
        }
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $compte->tenant->slug);
    }

    /** Réponse simulée de l'API : un appel d'outil. */
    private function appel(string $outil, array $entree, string $id): array
    {
        return [
            'id' => "msg_{$id}", 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'tool_use',
            'content' => [['type' => 'text', 'text' => 'Je consulte les rapports.'], ['type' => 'tool_use', 'id' => $id, 'name' => $outil, 'input' => $entree]],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 50],
        ];
    }

    private function question(string $texte = 'Qui est mon meilleur client depuis septembre ?', string $langue = 'fr'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/rapports/ia/questions', ['question' => $texte, 'langue' => $langue]);
    }

    public function test_disponibilite_selon_la_cle_et_le_module(): void
    {
        config(['ia.cle' => null]);
        $this->getJson('/api/rapports/ia/questions')->assertOk()->assertJsonPath('indisponible', 'cle');
        $this->question()->assertStatus(503)->assertJsonPath('message', "Le service d'IA n'est pas configuré sur ce serveur.");

        config(['ia.cle' => 'cle-de-test']);
        $sansModule = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->agir(AdminUser::factory()->for($sansModule)->create(['role' => 'admin']));
        $this->getJson('/api/rapports/ia/questions')->assertOk()->assertJsonPath('indisponible', 'module');
        $this->question()->assertForbidden()->assertJsonPath('message', "Le module « Intelligence artificielle » n'est pas activé pour votre entreprise.");

        $this->agir($this->admin);
        $this->getJson('/api/rapports/ia/questions')->assertOk()->assertJsonPath('indisponible', null)->assertJsonPath('restantes', 50);
        Http::assertNothingSent();
    }

    public function test_reponse_tiree_des_rapports_executes(): void
    {
        $periode = ['du' => '2026-09-01', 'au' => '2026-10-31'];
        Http::fake([self::URL => Http::sequence()
            ->push($this->appel('executer_rapport', ['code' => 'ventes-par-client', 'parametres' => $periode], 'toolu_1'))
            ->push($this->appel('repondre', [
                'titre' => 'Meilleur client depuis septembre',
                'reponse' => 'Le **Bar du Port** est votre meilleur client : 1 500,00 XAF hors taxes du 1er septembre au 31 octobre.',
                'rapport' => ['code' => 'ventes-par-client', 'parametres' => $periode],
            ], 'toolu_2'))]);

        $reponse = $this->question()->assertCreated()->json();
        $this->assertSame(['reussie', 'Meilleur client depuis septembre', 2000, 100, 'claude-opus-5-5'],
            [$reponse['statut'], $reponse['titre'], $reponse['jetons_entree'], $reponse['jetons_sortie'], $reponse['modele']]);
        $this->assertStringContainsString('**Bar du Port**', $reponse['reponse']);
        $this->assertSame(['ventes-par-client', 'Ventes par client', '2026-09-01', '2026-10-31', true],
            [$reponse['rapport']['code'], $reponse['rapport']['titre'], $reponse['rapport']['parametres']['du'], $reponse['rapport']['parametres']['au'], $reponse['rapport']['permis']]);
        $this->assertSame(['ventes-par-client'], array_column($reponse['rapports'], 'code'));

        // Premier appel : clé, version, modèle, rapports permis seulement, date du jour, la question.
        $envois = Http::recorded();
        $this->assertCount(2, $envois);
        /** @var Request $premier */
        $premier = $envois[0][0];
        $this->assertSame(['cle-de-test', '2023-06-01', 'claude-opus-5-5'], [$premier->header('x-api-key')[0], $premier->header('anthropic-version')[0], $premier['model']]);
        $this->assertCount(12, $premier['tools'][0]['input_schema']['properties']['code']['enum']);
        $this->assertStringContainsString("Aujourd'hui : 2026-10-17", $premier['system'][0]['text']);
        $this->assertStringContainsString('« Entreprise Test »', $premier['system'][0]['text']);
        $this->assertSame([['role' => 'user', 'content' => 'Qui est mon meilleur client depuis septembre ?']], $premier['messages']);
        // Second appel : le résultat du rapport, avec les vraies données.
        $resultat = collect($envois[1][0]['messages'])->last()['content'][0];
        $this->assertSame(['tool_result', 'toolu_1', false], [$resultat['type'], $resultat['tool_use_id'], $resultat['is_error']]);
        $donnees = json_decode($resultat['content'], true);
        $this->assertSame(['Ventes par client', 1, false], [$donnees['rapport'], $donnees['nombre_lignes'], $donnees['lignes_tronquees']]);
        $this->assertSame('Bar du Port', $donnees['lignes'][0]['client']);
        $this->assertEquals(1500, $donnees['lignes'][0]['montant_ht']);

        // L'historique du compte.
        $historique = $this->getJson('/api/rapports/ia/questions?langue=en')->assertOk()->json();
        $this->assertSame([[$reponse['id'], 'Sales by customer']], array_map(fn ($q) => [$q['id'], $q['rapport']['titre']], $historique['questions']));
        $this->assertSame(49, $historique['restantes']);
    }

    public function test_rapport_non_permis_et_parametres_invalides_renvoyes_a_l_ia(): void
    {
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => ['factures.voir' => false]])->assertOk();
        $this->agir($vendeur);
        Http::fake([self::URL => Http::sequence()
            ->push($this->appel('executer_rapport', ['code' => 'ventes-par-client'], 'toolu_1'))
            ->push($this->appel('executer_rapport', ['code' => 'stock-produits', 'parametres' => ['etat' => 'tout']], 'toolu_2'))
            ->push($this->appel('repondre', ['titre' => 'Ventes non disponibles', 'reponse' => 'Vos droits ne donnent pas accès aux ventes.'], 'toolu_3'))]);

        $reponse = $this->question()->assertCreated()->json();
        $this->assertSame(['reussie', null, []], [$reponse['statut'], $reponse['rapport'], $reponse['rapports']]);
        $envois = Http::recorded();
        // Les rapports de ventes ne sont même pas proposés à l'IA.
        $this->assertNotContains('ventes-par-client', $envois[0][0]['tools'][0]['input_schema']['properties']['code']['enum']);
        $this->assertContains('stock-produits', $envois[0][0]['tools'][0]['input_schema']['properties']['code']['enum']);
        $refus = collect($envois[1][0]['messages'])->last()['content'][0];
        $this->assertTrue($refus['is_error']);
        $this->assertStringStartsWith('Rapport inconnu, ou non permis', $refus['content']);
        $invalide = collect($envois[2][0]['messages'])->last()['content'][0];
        $this->assertTrue($invalide['is_error']);
        $this->assertStringStartsWith('Paramètres invalides', $invalide['content']);
    }

    public function test_echecs_du_service_et_reponse_en_texte(): void
    {
        // Une seule suite de réponses simulées pour les trois cas.
        Http::fake([self::URL => Http::sequence()
            ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)
            ->push($this->appel('executer_rapport', ['code' => 'stock-produits'], 'toolu_1'))
            ->push($this->appel('executer_rapport', ['code' => 'stock-produits'], 'toolu_2'))
            ->push([
                'id' => 'msg_t', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => "Aucun rapport ne donne les marges.\nLe rapport des ventes par produit est le plus proche."]],
                'usage' => ['input_tokens' => 800, 'output_tokens' => 40],
            ])]);

        // Service surchargé : l'échec est gardé et signalé.
        $this->question()->assertStatus(502)->assertJsonPath('message', "Le service d'IA est surchargé ; réessayez dans un moment.");
        $echec = app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => QuestionIa::latest('id')->first());
        $this->assertSame([QuestionIa::ECHEC, "Le service d'IA est surchargé ; réessayez dans un moment."], [$echec->statut, $echec->erreur]);

        // L'IA ne conclut pas dans le nombre d'échanges permis.
        config(['ia.tours' => 2]);
        $this->question()->assertStatus(502)->assertJsonPath('message', "L'IA n'a pas pu conclure : reformulez la question, plus précisément.");

        // Une réponse en texte, sans l'outil « repondre », est gardée.
        $texte = $this->question('Quelle est ma marge ?')->assertCreated()->json();
        $this->assertSame(['reussie', 'Aucun rapport ne donne les marges.'], [$texte['statut'], $texte['titre']]);
    }

    public function test_limite_droits_et_isolation(): void
    {
        Http::fake([self::URL => Http::response([
            'id' => 'msg_t', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Réponse.']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);
        config(['ia.questions_par_jour' => 2]);
        $this->question()->assertCreated();
        $this->question()->assertCreated();
        $this->question()->assertStatus(429)->assertJsonPath('message', 'Limite de 2 questions par jour atteinte : réessayez demain.');
        $this->postJson('/api/rapports/ia/questions', ['question' => ''])->assertUnprocessable()->assertJsonValidationErrors(['question' => 'Écrivez votre question.']);

        // Sans le droit de questionner l'IA ; un collègue ne voit pas les questions des autres ; une autre entreprise non plus.
        $collegue = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$collegue->id}", ['groupes' => [], 'exceptions' => ['rapports.questionner' => false]])->assertOk();
        $this->agir($collegue);
        $this->getJson('/api/rapports/ia/questions')->assertForbidden();
        $this->question()->assertForbidden();
        $this->agir($this->admin);
        $this->putJson("/api/acces-comptes/{$collegue->id}", ['groupes' => [], 'exceptions' => []])->assertOk();
        $this->agir($collegue);
        $this->assertSame([], $this->getJson('/api/rapports/ia/questions')->assertOk()->json('questions'));
        $this->agir(AdminUser::factory()->for(Tenant::factory()->create(['modules' => ['ventes', 'ia']]))->create(['role' => 'admin']));
        $this->assertSame([], $this->getJson('/api/rapports/ia/questions')->assertOk()->json('questions'));
    }
}
