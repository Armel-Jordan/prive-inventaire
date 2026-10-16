<?php

namespace App\Http\Controllers\Securite;

use App\Http\Controllers\Controller;
use App\Mail\LienReinitialisationMotDePasse;
use App\Models\AdminUser;
use App\Models\ReinitialisationMotDePasse;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

use function Illuminate\Support\defer;

/** Mot de passe oublié (lien par courriel) et changement de mot de passe depuis le compte. */
class MotDePasseController extends Controller
{
    /**
     * Envoie un lien de réinitialisation. La réponse est la même que le compte existe ou non, pour ne pas
     * révéler quelles adresses ont un compte.
     */
    public function oublie(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'tenant_slug' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'origine' => ['nullable', 'string', 'max:255'],
            'langue' => ['nullable', Rule::in(['fr', 'en'])],
        ]);

        $tenant = Tenant::where('slug', strtolower(trim($donnees['tenant_slug'])))->where('actif', true)->first();
        $utilisateur = $tenant
            ? AdminUser::where('tenant_id', $tenant->id)->where('email', $donnees['email'])->where('actif', true)->first()
            : null;

        if ($utilisateur) {
            $jeton = Str::random(64);
            $minutes = (int) config('comptes.reinitialisation_minutes');
            DB::transaction(function () use ($utilisateur, $jeton, $minutes) {
                // Une seule demande en cours par utilisateur : la précédente est annulée.
                ReinitialisationMotDePasse::where('admin_user_id', $utilisateur->id)->delete();
                ReinitialisationMotDePasse::create([
                    'admin_user_id' => $utilisateur->id,
                    'jeton' => hash('sha256', $jeton),
                    'expire_le' => now()->addMinutes($minutes),
                ]);
            });

            $courriel = new LienReinitialisationMotDePasse(
                $utilisateur->nom, $tenant->nom,
                $this->urlFront($donnees['origine'] ?? null).'/reinitialiser-mot-de-passe?jeton='.$jeton,
                $minutes, $donnees['langue'] ?? 'fr',
            );
            // Envoi après la réponse (defer) : un temps de réponse plus long ne trahit pas l'existence du compte.
            // Un échec d'envoi est journalisé (rescue) sans être révélé.
            defer(fn () => Mail::to($utilisateur->email)->send($courriel));
        }

        return response()->json(['message' => 'Si un compte correspond à ces informations, un courriel avec un lien de réinitialisation vient d\'être envoyé.']);
    }

    /** Nouveau mot de passe à partir du lien ; toutes les sessions ouvertes du compte sont fermées. */
    public function reinitialiser(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'jeton' => ['required', 'string', 'size:64'],
            'password' => $this->reglesNouveauMotDePasse(),
        ], $this->messages());

        $demande = ReinitialisationMotDePasse::with('utilisateur.tenant')
            ->where('jeton', hash('sha256', $donnees['jeton']))
            ->where('expire_le', '>', now())
            ->first();
        $utilisateur = $demande?->utilisateur;
        if (! $utilisateur || ! $utilisateur->actif || ! $utilisateur->tenant?->actif) {
            return response()->json(['message' => 'Ce lien n\'est plus valable : demandez-en un nouveau.'], 422);
        }

        DB::transaction(function () use ($utilisateur, $donnees) {
            $utilisateur->update(['password' => $donnees['password']]);
            ReinitialisationMotDePasse::where('admin_user_id', $utilisateur->id)->delete();
            $utilisateur->tokens()->delete();
        });

        return response()->json(['message' => 'Mot de passe modifié. Vous pouvez vous connecter.']);
    }

    /** Changement depuis le compte : mot de passe actuel exigé ; les autres sessions sont fermées. */
    public function changer(Request $request): JsonResponse
    {
        $utilisateur = $request->user();
        $donnees = $request->validate([
            'mot_de_passe_actuel' => ['required', 'string'],
            'password' => [...$this->reglesNouveauMotDePasse(), 'different:mot_de_passe_actuel'],
        ], $this->messages());

        if (! Hash::check($donnees['mot_de_passe_actuel'], $utilisateur->password)) {
            throw ValidationException::withMessages(['mot_de_passe_actuel' => 'Mot de passe actuel incorrect.']);
        }

        $utilisateur->update(['password' => $donnees['password']]);
        $courant = $utilisateur->currentAccessToken();
        $utilisateur->tokens()
            ->when($courant instanceof PersonalAccessToken ? $courant->getKey() : null, fn ($q, $id) => $q->whereKeyNot($id))
            ->delete();

        return response()->json(['message' => 'Mot de passe modifié. Vos autres sessions ont été fermées.']);
    }

    private function reglesNouveauMotDePasse(): array
    {
        return ['required', 'string', 'confirmed', 'min:'.config('comptes.longueur_min_mot_de_passe'), 'max:255'];
    }

    private function messages(): array
    {
        return [
            'password.min' => 'Le mot de passe doit contenir au moins :min caractères.',
            'password.confirmed' => 'La confirmation ne correspond pas au mot de passe.',
            'password.different' => 'Le nouveau mot de passe doit être différent de l\'actuel.',
        ];
    }

    /**
     * Adresse du site pour le lien : l'origine envoyée par le front si elle fait partie des origines CORS
     * autorisées (jamais « * »), sinon FRONTEND_URL, sinon APP_URL. Une origine arbitraire n'est jamais
     * reprise : elle permettrait d'envoyer un lien piégé vers un autre site.
     */
    private function urlFront(?string $origine): string
    {
        $autorisees = array_map(fn ($o) => rtrim((string) $o, '/'), array_filter((array) config('cors.allowed_origins', []), fn ($o) => $o !== '*'));
        if ($origine && in_array(rtrim($origine, '/'), $autorisees, true)) {
            return rtrim($origine, '/');
        }

        return rtrim((string) (config('comptes.url_front') ?: config('app.url')), '/');
    }
}
