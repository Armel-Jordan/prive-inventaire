<?php

namespace App\Services\Comptabilite;

use App\Models\CompteComptable;
use App\Models\EcritureComptableLigne;
use App\Support\PlansComptables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Règles du plan comptable et imports (plan type, fichier CSV). Les contrôles reprennent ceux de fripv2 en
 * les rendant indépendants de la numérotation : hiérarchie sans boucle, cohérence des comptes actifs, un seul
 * compte qui reçoit le résultat de l'exercice.
 */
class PlanComptable
{
    /** Colonnes du fichier d'import (et de l'export), dans l'ordre. */
    public const COLONNES = ['numero', 'libelle', 'nature', 'type', 'parent'];

    private const MAX_LIGNES = 5000;

    /**
     * Règles métier d'un compte avant enregistrement. $donnees : valeurs validées (numero, parent_id, nature,
     * type, actif). Renvoie [champ => message] au premier refus, null si tout est conforme.
     */
    public function controler(array $donnees, ?CompteComptable $compte = null): ?array
    {
        $parent = ! empty($donnees['parent_id']) ? CompteComptable::find($donnees['parent_id']) : null;
        $actif = (bool) ($donnees['actif'] ?? true);

        if ($parent && $compte) {
            // Ni lui-même ni l'un de ses sous-comptes : la hiérarchie bouclerait.
            for ($ancetre = $parent; $ancetre; $ancetre = $ancetre->parent) {
                if ($ancetre->id === $compte->id) {
                    return ['parent_id' => 'Un compte ne peut pas être rattaché à lui-même ni à l\'un de ses sous-comptes.'];
                }
            }
        }
        if ($parent && $actif && ! $parent->actif) {
            return ['parent_id' => 'Un compte actif ne peut pas dépendre d\'un compte inactif.'];
        }
        if ($compte && ! $actif && $compte->enfants()->where('actif', true)->exists()) {
            return ['actif' => 'Ce compte a des sous-comptes actifs : désactivez-les d\'abord.'];
        }
        if ($compte && ($donnees['type'] ?? null) === CompteComptable::EN_TETE && $compte->type !== CompteComptable::EN_TETE && $this->aDesEcritures($compte)) {
            return ['type' => 'Ce compte a des écritures : il ne peut pas devenir un en-tête.'];
        }
        if (($donnees['type'] ?? null) === CompteComptable::RESULTAT) {
            if ($donnees['nature'] !== 'capitaux_propres') {
                return ['nature' => 'Le compte qui reçoit le résultat de l\'exercice doit être un compte de capitaux propres.'];
            }
            $autre = CompteComptable::where('type', CompteComptable::RESULTAT)
                ->when($compte, fn ($q) => $q->where('id', '!=', $compte->id))->first();
            if ($autre) {
                return ['type' => "Le compte {$autre->numero} reçoit déjà le résultat de l'exercice : un seul compte par entreprise."];
            }
        }

        return null;
    }

    /** Raison pour laquelle un compte ne peut pas être supprimé, null s'il peut l'être. */
    public function refusSuppression(CompteComptable $compte): ?string
    {
        if ($compte->enfants()->exists()) {
            return 'Ce compte a des sous-comptes : supprimez-les ou rattachez-les à un autre compte d\'abord.';
        }
        if ($this->aDesEcritures($compte)) {
            return 'Ce compte a des écritures : désactivez-le plutôt.';
        }

        return null;
    }

    public function aDesEcritures(CompteComptable $compte): bool
    {
        return EcritureComptableLigne::where('compte_id', $compte->id)->exists();
    }

    /**
     * Crée les comptes d'un plan type. Sans $completer, le plan doit être vide ; avec, seuls les numéros absents
     * sont ajoutés (rattachés à leur parent s'il existe déjà). Renvoie le nombre de comptes créés.
     */
    public function importerPlan(string $plan, bool $completer): int
    {
        return DB::transaction(function () use ($plan) {
            $ids = CompteComptable::pluck('id', 'numero')->all();
            $resultatPris = CompteComptable::where('type', CompteComptable::RESULTAT)->exists();
            $crees = 0;
            foreach (PlansComptables::comptes($plan) as $ligne) {
                if (isset($ids[$ligne['numero']])) {
                    continue;
                }
                // Le compte de résultat existe déjà : le compte du plan type devient un compte ordinaire.
                $type = $ligne['type'] === CompteComptable::RESULTAT && $resultatPris ? 'standard' : $ligne['type'];
                $resultatPris = $resultatPris || $type === CompteComptable::RESULTAT;
                $compte = CompteComptable::create([
                    'numero' => $ligne['numero'],
                    'libelle' => $ligne['libelle'],
                    'nature' => $ligne['nature'],
                    'type' => $type,
                    'parent_id' => $ligne['parent'] !== null ? ($ids[$ligne['parent']] ?? null) : null,
                    'saisie_manuelle' => $ligne['saisie_manuelle'],
                    'actif' => true,
                ]);
                $ids[$compte->numero] = $compte->id;
                $crees++;
            }

            return $crees;
        });
    }

    /**
     * Importe un fichier CSV (séparateur ; , ou tabulation, première ligne d'en-têtes). Les comptes déjà au plan
     * sont ignorés. Tout ou rien pour les autres : à la moindre erreur, rien n'est créé et les erreurs sont
     * renvoyées avec leur numéro de ligne.
     *
     * @return array{crees: int, ignores: int, erreurs: list<string>}
     */
    public function importerFichier(string $contenu): array
    {
        $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu);
        $lignes = preg_split('/\r\n|\r|\n/', trim($contenu));
        if (! $lignes || trim($lignes[0]) === '') {
            return ['crees' => 0, 'ignores' => 0, 'erreurs' => ['Le fichier est vide.']];
        }
        $separateur = collect([';', "\t", ','])->sortByDesc(fn ($s) => substr_count($lignes[0], $s))->first();
        $alias = ['compte' => 'numero', 'no' => 'numero', 'numero_de_compte' => 'numero', 'nom' => 'libelle', 'description' => 'libelle',
            'intitule' => 'libelle', 'compte_parent' => 'parent', 'numero_parent' => 'parent'];
        $entetes = array_map(fn ($e) => $alias[$this->normaliser($e)] ?? $this->normaliser($e), str_getcsv($lignes[0], $separateur));
        $manquantes = array_diff(['numero', 'libelle', 'nature'], $entetes);
        if ($manquantes) {
            return ['crees' => 0, 'ignores' => 0, 'erreurs' => ['Colonnes obligatoires absentes : '.implode(', ', $manquantes).'. Colonnes attendues : '.implode(';', self::COLONNES).'.']];
        }
        if (count($lignes) - 1 > self::MAX_LIGNES) {
            return ['crees' => 0, 'ignores' => 0, 'erreurs' => ['Le fichier dépasse '.self::MAX_LIGNES.' comptes.']];
        }

        // Clés en texte : PHP range les numéros purement numériques (401) sous des clés entières.
        $existants = CompteComptable::pluck('id', 'numero')->mapWithKeys(fn ($id, $numero) => [(string) $numero => $id])->all();
        $resultatExistant = CompteComptable::where('type', CompteComptable::RESULTAT)->value('numero');
        $comptes = [];
        $erreurs = [];
        $ignores = 0;
        foreach (array_slice($lignes, 1) as $index => $brute) {
            $rang = $index + 2;
            if (trim($brute) === '') {
                continue;
            }
            $valeurs = array_pad(str_getcsv($brute, $separateur), count($entetes), '');
            $ligne = array_combine($entetes, array_slice($valeurs, 0, count($entetes)));
            $numero = preg_replace('/\s+/', '', (string) ($ligne['numero'] ?? ''));
            if ($numero !== '' && isset($existants[$numero])) {
                $ignores++;

                continue;
            }
            $nature = $this->valeurConnue($ligne['nature'] ?? '', CompteComptable::NATURES);
            $type = trim((string) ($ligne['type'] ?? '')) === '' ? 'standard' : $this->valeurConnue($ligne['type'], CompteComptable::TYPES);
            $libelle = trim((string) ($ligne['libelle'] ?? ''));

            $erreur = match (true) {
                $numero === '' => 'numéro absent',
                strlen($numero) > 20 || ! preg_match('/^[0-9A-Za-z][0-9A-Za-z.\-]*$/', $numero) => "numéro « {$numero} » invalide (20 caractères au plus : chiffres, lettres, point, tiret)",
                isset($comptes[$numero]) => "le compte {$numero} figure deux fois dans le fichier",
                $libelle === '' || mb_strlen($libelle) > 150 => 'libellé absent ou de plus de 150 caractères',
                $nature === null => 'nature « '.trim((string) $ligne['nature']).' » inconnue ('.implode(', ', CompteComptable::NATURES).')',
                $type === null => 'type « '.trim((string) $ligne['type']).' » inconnu ('.implode(', ', CompteComptable::TYPES).')',
                default => null,
            };
            if ($erreur) {
                $erreurs[] = "Ligne {$rang} : {$erreur}.";

                continue;
            }
            $comptes[$numero] = [
                'rang' => $rang, 'numero' => $numero, 'libelle' => $libelle, 'nature' => $nature, 'type' => $type,
                'parent' => preg_replace('/\s+/', '', (string) ($ligne['parent'] ?? '')),
            ];
        }

        // Parents : colonne « parent » (compte du fichier ou déjà au plan), sinon déduit du numéro.
        $numeros = array_map('strval', array_merge(array_keys($existants), array_keys($comptes)));
        foreach ($comptes as $numero => &$compte) {
            if ($compte['parent'] === '') {
                $compte['parent'] = PlansComptables::parentDe((string) $numero, $numeros);
            } elseif ($compte['parent'] === (string) $numero || ! in_array($compte['parent'], $numeros, true)) {
                $erreurs[] = "Ligne {$compte['rang']} : compte parent « {$compte['parent']} » introuvable.";
            }
        }
        unset($compte);

        $resultats = array_keys(array_filter($comptes, fn ($c) => $c['type'] === CompteComptable::RESULTAT));
        if (count($resultats) + ($resultatExistant ? 1 : 0) > 1) {
            $erreurs[] = 'Un seul compte peut recevoir le résultat de l\'exercice (type resultat) : '.implode(', ', array_filter([$resultatExistant, ...$resultats])).'.';
        }
        foreach ($comptes as $numero => $compte) {
            if ($compte['type'] === CompteComptable::RESULTAT && $compte['nature'] !== 'capitaux_propres') {
                $erreurs[] = "Ligne {$compte['rang']} : le compte de résultat {$numero} doit être de nature capitaux_propres.";
            }
        }
        if ($erreurs) {
            return ['crees' => 0, 'ignores' => $ignores, 'erreurs' => array_slice($erreurs, 0, 50)];
        }

        DB::transaction(function () use ($comptes, $existants) {
            $ids = $existants;
            foreach ($comptes as $numero => $compte) {
                $ids[(string) $numero] = CompteComptable::create([
                    'numero' => (string) $numero,
                    'libelle' => $compte['libelle'],
                    'nature' => $compte['nature'],
                    'type' => $compte['type'],
                    'saisie_manuelle' => $compte['type'] !== CompteComptable::EN_TETE,
                    'actif' => true,
                ])->id;
            }
            foreach ($comptes as $numero => $compte) {
                if ($compte['parent'] !== null) {
                    CompteComptable::where('id', $ids[(string) $numero])->update(['parent_id' => $ids[$compte['parent']]]);
                }
            }
        });

        return ['crees' => count($comptes), 'ignores' => $ignores, 'erreurs' => []];
    }

    /** Plan comptable en CSV (mêmes colonnes que l'import), avec BOM pour Excel. */
    public function exporter(): string
    {
        $comptes = CompteComptable::with('parent:id,numero')->orderBy('numero')->get();
        $flux = fopen('php://temp', 'r+');
        fwrite($flux, "\xEF\xBB\xBF");
        fputcsv($flux, self::COLONNES, ';');
        foreach ($comptes as $compte) {
            fputcsv($flux, [$compte->numero, $compte->libelle, $compte->nature, $compte->type, $compte->parent?->numero ?? ''], ';');
        }
        rewind($flux);

        return stream_get_contents($flux);
    }

    /** « Numéro », « Capitaux propres », « En-tête » → numero, capitaux_propres, en_tete. */
    private function normaliser(string $valeur): string
    {
        return Str::of($valeur)->ascii()->lower()->trim()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    private function valeurConnue(string $valeur, array $valeurs): ?string
    {
        $alias = ['entete' => 'en_tete', 'produits' => 'produit', 'charges' => 'charge', 'capitaux' => 'capitaux_propres'];
        $normalisee = $this->normaliser($valeur);
        $normalisee = $alias[$normalisee] ?? $normalisee;

        return in_array($normalisee, $valeurs, true) ? $normalisee : null;
    }
}
