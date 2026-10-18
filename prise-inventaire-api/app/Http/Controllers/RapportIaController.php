<?php

namespace App\Http\Controllers;

use App\Models\QuestionIa;
use App\Rapports\AssistantRapports;
use App\Rapports\Catalogue;
use App\Rapports\Rapport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Questions à l'IA sur les rapports (plan de parité fripv2, phase 5.4) : l'historique du compte, et une nouvelle
 * question. Il faut la clé de l'API sur le serveur et le module « Intelligence artificielle » de l'entreprise.
 */
class RapportIaController extends Controller
{
    public function __construct(private readonly AssistantRapports $assistant) {}

    public function index(Request $request): JsonResponse
    {
        $rapports = Catalogue::pour($request->user(), $request->attributes->get('tenant'));
        $langue = $this->langue($request);

        return response()->json([
            // null quand l'IA est disponible ; sinon « cle » (serveur) ou « module » (entreprise).
            'indisponible' => AssistantRapports::indisponibilite($request->attributes->get('tenant')),
            'restantes' => max(0, (int) config('ia.questions_par_jour') - $this->questionsDuJour($request)),
            'questions' => QuestionIa::query()->where('admin_user_id', $request->user()->id)->latest('id')->limit(30)->get()
                ->map(fn (QuestionIa $q) => $this->presenter($q, $rapports, $langue))->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:1000'],
            'langue' => ['nullable', Rule::in(['fr', 'en'])],
        ], [
            'question.required' => 'Écrivez votre question.',
            'question.min' => 'Écrivez votre question.',
            'question.max' => 'La question tient en 1 000 caractères au plus.',
        ]);
        $entreprise = $request->attributes->get('tenant');
        $raison = AssistantRapports::indisponibilite($entreprise);
        if ($raison === 'cle') {
            return response()->json(['message' => "Le service d'IA n'est pas configuré sur ce serveur."], 503);
        }
        if ($raison === 'module') {
            return response()->json(['message' => "Le module « Intelligence artificielle » n'est pas activé pour votre entreprise."], 403);
        }
        $limite = (int) config('ia.questions_par_jour');
        if ($this->questionsDuJour($request) >= $limite) {
            return response()->json(['message' => "Limite de {$limite} questions par jour atteinte : réessayez demain."], 429);
        }

        $langue = $donnees['langue'] ?? 'fr';
        try {
            $question = $this->assistant->repondre($request->user(), $entreprise, trim($donnees['question']), $langue);
        } catch (RuntimeException $erreur) {
            return response()->json(['message' => $erreur->getMessage()], 502);
        }

        return response()->json($this->presenter($question, Catalogue::pour($request->user(), $entreprise), $langue), 201);
    }

    private function questionsDuJour(Request $request): int
    {
        return QuestionIa::query()->where('admin_user_id', $request->user()->id)->where('created_at', '>=', today())->count();
    }

    private function langue(Request $request): string
    {
        $langue = (string) $request->query('langue', 'fr');

        return in_array($langue, ['fr', 'en'], true) ? $langue : 'fr';
    }

    /** La question pour l'écran ; un rapport que le compte ne peut plus ouvrir est signalé (« permis » à faux). */
    private function presenter(QuestionIa $question, Collection $rapports, string $langue): array
    {
        $rapport = function (?array $r) use ($rapports, $langue): ?array {
            if (! $r || ! isset($r['code'])) {
                return null;
            }
            /** @var Rapport|null $definition */
            $definition = $rapports->get($r['code']);

            return [
                'code' => $r['code'],
                'titre' => $definition ? ($definition->titre()[$langue] ?? $definition->titre()['fr']) : $r['code'],
                'parametres' => (object) ($r['parametres'] ?? []),
                'permis' => $definition !== null,
            ];
        };

        return $question->only(['id', 'question', 'langue', 'statut', 'titre', 'reponse', 'erreur', 'modele', 'jetons_entree', 'jetons_sortie', 'duree_ms']) + [
            'rapport' => $rapport($question->rapport),
            'rapports' => array_values(array_filter(array_map($rapport, $question->rapports ?? []))),
            'created_at' => $question->created_at?->toIso8601String(),
        ];
    }
}
