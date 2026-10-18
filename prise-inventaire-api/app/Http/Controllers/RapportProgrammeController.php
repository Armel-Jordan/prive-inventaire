<?php

namespace App\Http\Controllers;

use App\Models\RapportProgramme;
use App\Models\TachePlanifiee;
use App\Rapports\AssistantRapports;
use App\Rapports\Catalogue;
use App\Rapports\Production;
use App\Rapports\Programmation;
use App\Rapports\Rapport;
use App\Services\Planificateur;
use App\Support\Droits;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Envois programmés des rapports (plan de parité fripv2, phase 5.2) : chaque jour, chaque semaine ou chaque mois, un
 * rapport de la bibliothèque part par courriel sur une période relative. On ne voit et ne règle que les envois des
 * rapports que l'on peut ouvrir ; celui qui règle un envoi en devient le responsable (ses droits s'appliquent).
 */
class RapportProgrammeController extends Controller
{
    /** Code de la tâche planifiée qui fait partir les envois. */
    public const TACHE = 'rapports_programmes';

    public function __construct(
        private readonly Programmation $programmation,
        private readonly Planificateur $planificateur,
        private readonly Droits $droits,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $langue = $this->langue($request);
        $rapports = $this->rapports($request);
        $programmes = RapportProgramme::with(['compte:id,nom', 'dernierEnvoi:id,statut,erreur'])
            ->whereIn('code', $rapports->keys()->all())->orderBy('nom')->get();

        return response()->json([
            'programmes' => $programmes->map(fn (RapportProgramme $p) => $this->presenter($p, $rapports[$p->code], $langue))->values()->all(),
            'periodes' => collect(Programmation::PERIODES)->map(fn (array $l, string $code) => ['code' => $code, 'libelle' => $l[$langue] ?? $l['fr']])->values()->all(),
            // Sans la tâche planifiée active, rien ne part : l'écran le dit.
            'tache_active' => (bool) TachePlanifiee::query()->where('code', self::TACHE)->value('actif'),
            // Question à l'IA possible sur les envois (clé du serveur, module de l'entreprise, droit du compte).
            'ia_disponible' => $this->iaDisponible($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$rapport, $donnees] = $this->valider($request);
        $programme = RapportProgramme::create($donnees + ['compte_id' => $request->user()->id]);
        $this->activerTache();

        return response()->json($this->presenter($programme->load('compte:id,nom'), $rapport, $this->langue($request)), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $programme = $this->programme($request, $id);
        [$rapport, $donnees] = $this->valider($request);
        // Même fréquence, toujours actif : la date du prochain envoi ne bouge pas (un envoi du jour n'est pas sauté).
        if ($programme->actif && $donnees['actif'] && $programme->prochain_envoi
            && $programme->frequence === $donnees['frequence'] && $programme->jour === $donnees['jour']) {
            unset($donnees['prochain_envoi']);
        }
        // Celui qui règle l'envoi en devient le responsable : ce sont ses droits qui s'appliquent.
        $programme->update($donnees + ['compte_id' => $request->user()->id, 'derniere_erreur' => null]);

        return response()->json($this->presenter($programme->load(['compte:id,nom', 'dernierEnvoi:id,statut,erreur']), $rapport, $this->langue($request)));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->programme($request, $id)->delete();

        return response()->json(null, 204);
    }

    /** Envoie le rapport tout de suite, avec les droits du compte qui le demande ; la date du prochain envoi ne bouge pas. */
    public function envoyer(Request $request, int $id): JsonResponse
    {
        $programme = $this->programme($request, $id);
        try {
            $envoi = $this->programmation->envoyer($programme, now(config('taches.fuseau') ?: config('app.timezone'))->startOfDay(), $request->user());
        } catch (DomainException $erreur) {
            return response()->json(['message' => $erreur->getMessage()], 422);
        }

        return response()->json([
            'envoi' => $envoi->load('auteur:id,nom')->resume(),
            'programme' => $this->presenter($programme->load(['compte:id,nom', 'dernierEnvoi:id,statut,erreur']), $this->rapports($request)[$programme->code], $this->langue($request)),
        ]);
    }

    /**
     * Règles d'un envoi programmé, puis ses données : paramètres du rapport hors période, période relative (pour un
     * rapport qui en a une), date du premier envoi (strictement après aujourd'hui).
     *
     * @return array{Rapport, array<string, mixed>}
     */
    private function valider(Request $request): array
    {
        $rapports = $this->rapports($request);
        $request->validate(['code' => ['required', 'string', Rule::in($rapports->keys()->all())]],
            ['code.in' => __('Ce rapport est introuvable, ou réservé à un autre droit.')]);
        $rapport = $rapports[$request->input('code')];
        $definitions = $rapport->parametres();
        $avecPeriode = isset($definitions['du'], $definitions['au']);
        $hebdomadaire = $request->input('frequence') === 'hebdomadaire';
        $reglesParametres = collect($rapport->regles())->except($avecPeriode ? ['du', 'au'] : [])
            ->mapWithKeys(fn (array $regles, string $cle) => ["parametres.{$cle}" => $regles])->all();

        $donnees = $request->validate([
            'code' => ['required', 'string'],
            'nom' => ['required', 'string', 'max:150'],
            'parametres' => ['nullable', 'array'],
            'periode' => [$avecPeriode ? 'required' : 'nullable', Rule::in(array_keys(Programmation::PERIODES))],
            'format' => ['required', Rule::in(Production::FORMATS)],
            'langue' => ['required', Rule::in(['fr', 'en'])],
            'destinataires' => ['required', 'array', 'min:1', 'max:20'],
            'destinataires.*' => ['required', 'email', 'max:255'],
            'frequence' => ['required', Rule::in(RapportProgramme::FREQUENCES)],
            'jour' => ['nullable', 'required_if:frequence,hebdomadaire,mensuelle', 'integer', $hebdomadaire ? 'between:1,7' : 'between:1,31'],
            'actif' => ['sometimes', 'boolean'],
            // Question à l'IA : son analyse du rapport entre dans chaque courriel.
            'question_ia' => ['nullable', 'string', 'max:1000'],
        ] + $reglesParametres, [
            'periode.required' => __('Choisissez la période du rapport envoyé.'),
            'jour.required_if' => __("Choisissez le jour de l'envoi."),
        ] + RapportBibliothequeController::messagesAdresses());

        $questionIa = trim((string) ($donnees['question_ia'] ?? ''));
        if ($questionIa !== '' && ! $this->iaDisponible($request)) {
            throw ValidationException::withMessages(['question_ia' => [__("Les questions à l'IA ne sont pas disponibles pour votre compte ou votre entreprise.")]]);
        }

        $parametres = array_filter(array_intersect_key($donnees['parametres'] ?? [], array_diff_key($definitions, $avecPeriode ? ['du' => 1, 'au' => 1] : [])),
            fn ($valeur) => $valeur !== null && $valeur !== '');
        $jour = $donnees['frequence'] === 'quotidienne' ? null : (int) $donnees['jour'];
        $destinataires = [];
        foreach ($donnees['destinataires'] as $adresse) {
            $destinataires[mb_strtolower(trim($adresse))] ??= trim($adresse);
        }

        return [$rapport, [
            'code' => $donnees['code'],
            'nom' => $donnees['nom'],
            'parametres' => $parametres ?: null,
            'periode' => $avecPeriode ? $donnees['periode'] : null,
            'format' => $donnees['format'],
            'langue' => $donnees['langue'],
            'destinataires' => array_values($destinataires),
            'frequence' => $donnees['frequence'],
            'jour' => $jour,
            'actif' => $donnees['actif'] ?? true,
            'question_ia' => $questionIa !== '' ? $questionIa : null,
            'prochain_envoi' => Programmation::prochainEnvoi($donnees['frequence'], $jour, now(config('taches.fuseau') ?: config('app.timezone')))->toDateString(),
        ]];
    }

    /**
     * La tâche des envois programmés, activée au premier envoi programmé si personne ne l'a encore réglée (sinon les
     * envois ne partiraient pas). Une tâche désactivée exprès le reste.
     */
    private function activerTache(): void
    {
        $tache = $this->planificateur->taches()->firstWhere('code', self::TACHE);
        if ($tache && ! $tache->actif && $tache->derniere_execution === null && $tache->created_at?->equalTo($tache->updated_at)) {
            $tache->actif = true;
            $tache->prochaine_execution = $this->planificateur->prochaine($tache, now());
            $tache->save();
        }
    }

    /** L'IA est-elle disponible pour l'entreprise, et le compte peut-il lui poser des questions ? */
    private function iaDisponible(Request $request): bool
    {
        return AssistantRapports::indisponibilite($request->attributes->get('tenant')) === null
            && $this->droits->permet($request->user(), 'rapports', 'questionner');
    }

    /** Un envoi programmé d'un rapport que le compte peut ouvrir ; sinon « introuvable ». */
    private function programme(Request $request, int $id): RapportProgramme
    {
        $programme = RapportProgramme::findOrFail($id);
        abort_unless($this->rapports($request)->has($programme->code), 404, __('Envoi programmé introuvable.'));

        return $programme;
    }

    /** @return Collection<string, Rapport> */
    private function rapports(Request $request): Collection
    {
        return Catalogue::pour($request->user(), $request->attributes->get('tenant'));
    }

    private function langue(Request $request): string
    {
        $langue = (string) $request->query('langue', 'fr');

        return in_array($langue, ['fr', 'en'], true) ? $langue : 'fr';
    }

    private function presenter(RapportProgramme $programme, Rapport $rapport, string $langue): array
    {
        return $programme->only(['id', 'code', 'nom', 'parametres', 'periode', 'format', 'langue', 'destinataires', 'frequence', 'jour', 'actif', 'derniere_erreur', 'question_ia']) + [
            'rapport' => $rapport->titre()[$langue] ?? $rapport->titre()['fr'],
            'compte' => $programme->compte?->nom,
            'prochain_envoi' => $programme->prochain_envoi?->toDateString(),
            'dernier_envoi_le' => $programme->dernier_envoi_le?->toIso8601String(),
            'dernier_envoi' => $programme->dernierEnvoi?->only(['id', 'statut', 'erreur']),
        ];
    }
}
