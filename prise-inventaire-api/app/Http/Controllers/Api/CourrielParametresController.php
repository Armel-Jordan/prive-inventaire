<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CourrielSimple;
use App\Models\CourrielParametres;
use App\Services\Courriels\MailerTenant;
use App\Services\Courriels\RenduCourriel;
use App\Services\Courriels\VariablesCourriel;
use App\Support\Courriels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/** Paramètres d'envoi des courriels de l'entreprise et courriel de test. */
class CourrielParametresController extends Controller
{
    private const TEST = [
        'fr' => [
            'objet' => 'Test d\'envoi — {entreprise_nom}',
            'contenu' => "Bonjour,\n\nCe courriel de test confirme que l'envoi des courriels de **{entreprise_nom}** fonctionne.\n\nLes documents envoyés à vos clients et fournisseurs partiront avec les mêmes paramètres.",
        ],
        'en' => [
            'objet' => 'Test email — {entreprise_nom}',
            'contenu' => "Hello,\n\nThis test email confirms that sending emails from **{entreprise_nom}** works.\n\nDocuments sent to your customers and suppliers will use the same settings.",
        ],
    ];

    public function __construct(
        private readonly MailerTenant $mailer,
        private readonly VariablesCourriel $variables,
        private readonly RenduCourriel $rendu,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->presenter(CourrielParametres::first()));
    }

    public function update(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'transport' => ['required', Rule::in(['plateforme', 'smtp'])],
            'smtp_hote' => ['nullable', 'required_if:transport,smtp', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp_chiffrement' => ['nullable', Rule::in(['tls', 'ssl', 'aucun'])],
            'smtp_utilisateur' => ['nullable', 'string', 'max:255'],
            'smtp_mot_de_passe' => ['nullable', 'string', 'max:255'],
            'expediteur_adresse' => ['nullable', 'required_if:transport,smtp', 'email', 'max:255'],
            'expediteur_nom' => ['nullable', 'string', 'max:255'],
            'repondre_a' => ['nullable', 'email', 'max:255'],
            'copie_cachee' => ['nullable', 'email', 'max:255'],
            'signature' => ['nullable', 'string', 'max:5000'],
        ]);

        // Mot de passe laissé vide : celui déjà enregistré est conservé.
        if (blank($donnees['smtp_mot_de_passe'] ?? null)) {
            unset($donnees['smtp_mot_de_passe']);
        }

        $parametres = CourrielParametres::firstOrNew();
        $parametres->fill($donnees)->save();

        return response()->json($this->presenter($parametres));
    }

    /** Envoie un courriel de test avec les paramètres enregistrés. */
    public function tester(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'destinataire' => ['required', 'email', 'max:255'],
            'langue' => ['nullable', Rule::in(Courriels::LANGUES)],
        ]);
        $langue = $donnees['langue'] ?? 'fr';
        $parametres = CourrielParametres::first();

        $rendu = $this->rendu->rendre('general', self::TEST[$langue], $this->variables->pour('general', null, $langue), $langue);

        try {
            $this->mailer->mailer($parametres)->to($donnees['destinataire'])->send(new CourrielSimple(
                objetCourriel: $rendu['objet'],
                corpsHtml: $rendu['html'],
                corpsTexte: $rendu['texte'],
                expediteur: $this->mailer->expediteur($parametres),
                reponseA: $this->mailer->repondreA($parametres),
            ));
        } catch (Throwable $erreur) {
            return response()->json(['message' => 'Échec de l\'envoi : '.Str::limit($erreur->getMessage(), 500)], 422);
        }

        return response()->json(['message' => 'Courriel de test envoyé à '.$donnees['destinataire'].'.']);
    }

    private function presenter(?CourrielParametres $parametres): array
    {
        $parametres ??= new CourrielParametres;

        return array_merge([
            'transport' => 'plateforme',
            'smtp_hote' => null,
            'smtp_port' => null,
            'smtp_chiffrement' => null,
            'smtp_utilisateur' => null,
            'expediteur_adresse' => null,
            'expediteur_nom' => null,
            'repondre_a' => null,
            'copie_cachee' => null,
            'signature' => null,
        ], $parametres->toArray(), [
            // Le mot de passe n'est jamais renvoyé : on indique seulement s'il existe.
            'smtp_mot_de_passe_defini' => filled($parametres->getAttributes()['smtp_mot_de_passe'] ?? null),
            'adresse_plateforme' => config('mail.from.address'),
        ]);
    }
}
