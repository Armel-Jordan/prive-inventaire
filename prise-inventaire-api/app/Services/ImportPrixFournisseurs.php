<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Import d'une grille de prix fournisseur (plan de parité fripv2, phase 3.6), comme « Importer une grille de prix
 * fournisseur » :
 * - le fichier (CSV) est lu selon des réglages : la colonne du code fournisseur (ou un fournisseur fixé pour tout le
 *   fichier), celle du code de l'article et celle du coût, la ligne où commencent les données ;
 * - l'article se cherche d'abord par la référence du fournisseur (son SKU, au catalogue du fournisseur), puis par le
 *   numéro de produit, qui doit alors être au catalogue de ce fournisseur : c'est là que le prix est inscrit ;
 * - les lignes reconnues mettent à jour le prix d'achat du catalogue, d'un seul tenant, chaque changement journalisé ;
 *   les autres sont ignorées et rapportées avec leur motif. L'import ne crée ni produit ni lien fournisseur ;
 * - une simulation rend le même rapport sans rien écrire.
 */
class ImportPrixFournisseurs
{
    public const MAX_LIGNES = 20000;

    /**
     * @param  array{fournisseur_id?: ?int, colonne_fournisseur?: ?int, colonne_code: int, colonne_cout: int, ligne_debut?: ?int}  $reglages  colonnes numérotées à partir de 1
     * @return array{lignes: int, mises_a_jour: int, inchangees: int, changements: list<array<string, mixed>>, ignorees: list<array<string, mixed>>}
     */
    public function importer(string $contenu, array $reglages, bool $simuler): array
    {
        $lignes = preg_split('/\r\n|\r|\n/', preg_replace('/^\xEF\xBB\xBF/', '', $contenu));
        $nonVides = array_values(array_filter($lignes, fn (string $l) => trim($l) !== ''));
        if ($nonVides === []) {
            throw ValidationException::withMessages(['fichier' => __('Le fichier est vide.')]);
        }
        if (count($lignes) > self::MAX_LIGNES) {
            throw ValidationException::withMessages(['fichier' => __('Le fichier dépasse :max lignes.', ['max' => self::MAX_LIGNES])]);
        }
        $separateur = collect([';', "\t", ','])->sortByDesc(fn (string $s) => substr_count($nonVides[0], $s))->first();
        $fixe = ! empty($reglages['fournisseur_id']) ? Fournisseur::findOrFail((int) $reglages['fournisseur_id']) : null;
        if (! $fixe && empty($reglages['colonne_fournisseur'])) {
            throw ValidationException::withMessages(['colonne_fournisseur' => __('Indiquez la colonne du code fournisseur, ou choisissez le fournisseur de tout le fichier.')]);
        }

        $fournisseurs = ($fixe ? collect([$fixe]) : Fournisseur::query()->get(['id', 'code', 'raison_sociale']))->keyBy(fn (Fournisseur $f) => mb_strtoupper(trim((string) $f->code)));
        $catalogue = DB::table('produit_fournisseur')->whereIn('fournisseur_id', $fournisseurs->pluck('id'))
            ->get(['produit_id', 'fournisseur_id', 'reference_fournisseur', 'prix_achat']);
        $parReference = [];
        $prix = [];
        foreach ($catalogue as $article) {
            if (filled($article->reference_fournisseur)) {
                $parReference[$article->fournisseur_id][mb_strtoupper(trim($article->reference_fournisseur))] = $article->produit_id;
            }
            $prix[$article->fournisseur_id][$article->produit_id] = $article->prix_achat === null ? null : round((float) $article->prix_achat, 2);
        }
        $produits = ProduitTenant::query()->get(['id', 'numero']);
        $parNumero = $produits->mapWithKeys(fn ($p) => [mb_strtoupper($p->numero) => $p->id])->all();
        $numeros = $produits->pluck('numero', 'id')->all();

        $rapport = ['lignes' => 0, 'mises_a_jour' => 0, 'inchangees' => 0, 'changements' => [], 'ignorees' => []];
        $vues = [];
        $cellule = fn (array $cellules, ?int $colonne) => $colonne ? trim((string) ($cellules[$colonne - 1] ?? '')) : '';
        foreach (array_slice($lignes, max(1, (int) ($reglages['ligne_debut'] ?? 2)) - 1, null, true) as $index => $brute) {
            if (trim($brute) === '') {
                continue;
            }
            $rang = $index + 1;
            $rapport['lignes']++;
            $cellules = str_getcsv($brute, $separateur);
            $codeFournisseur = $fixe ? $fixe->code : $cellule($cellules, (int) $reglages['colonne_fournisseur']);
            $code = $cellule($cellules, (int) $reglages['colonne_code']);
            $cout = $this->cout($cellule($cellules, (int) $reglages['colonne_cout']));
            $fournisseur = $fournisseurs[mb_strtoupper($codeFournisseur)] ?? null;
            $produitId = $fournisseur ? ($parReference[$fournisseur->id][mb_strtoupper($code)] ?? $parNumero[mb_strtoupper($code)] ?? null) : null;
            $cle = $fournisseur && $produitId ? "{$fournisseur->id}|{$produitId}" : null;
            $motif = match (true) {
                ! $fournisseur => __('Fournisseur introuvable avec ce numéro'),
                $code === '' => __('Code article vide'),
                $cout === null => __('Coût illisible ou négatif'),
                ! $produitId => __('Produit introuvable'),
                ! array_key_exists($produitId, $prix[$fournisseur->id] ?? []) => __('Produit non rattaché à ce fournisseur'),
                isset($vues[$cle]) => __('Déjà présent à la ligne :ligne', ['ligne' => $vues[$cle]]),
                default => null,
            };
            if ($motif) {
                $rapport['ignorees'][] = ['ligne' => $rang, 'fournisseur' => $codeFournisseur, 'code' => $code, 'motif' => $motif];

                continue;
            }
            $vues[$cle] = $rang;
            $ancien = $prix[$fournisseur->id][$produitId];
            if ($ancien !== null && abs($ancien - $cout) < 0.005) {
                $rapport['inchangees']++;

                continue;
            }
            $rapport['changements'][] = [
                'ligne' => $rang, 'fournisseur_id' => $fournisseur->id, 'fournisseur' => $fournisseur->code, 'produit_id' => $produitId,
                'produit' => $numeros[$produitId] ?? null, 'ancien' => $ancien, 'nouveau' => $cout,
            ];
        }
        $rapport['mises_a_jour'] = count($rapport['changements']);

        if (! $simuler && $rapport['changements'] !== []) {
            DB::transaction(function () use ($rapport) {
                $auteur = auth()->user();
                foreach ($rapport['changements'] as $changement) {
                    DB::table('produit_fournisseur')->where('fournisseur_id', $changement['fournisseur_id'])->where('produit_id', $changement['produit_id'])
                        ->update(['prix_achat' => $changement['nouveau'], 'updated_at' => now()]);
                    AuditLog::create([
                        'tenant_id' => $auteur?->tenant_id,
                        'action' => 'update',
                        'model_type' => 'Prix fournisseur',
                        'model_id' => "{$changement['fournisseur']}/{$changement['produit']}",
                        'user_id' => $auteur ? (string) $auteur->getAuthIdentifier() : null,
                        'user_name' => $auteur?->nom ?: $auteur?->email,
                        'old_values' => ['prix_achat' => $changement['ancien']],
                        'new_values' => ['prix_achat' => $changement['nouveau'], 'source' => 'Import de grille de prix'],
                        'ip_address' => app()->runningInConsole() ? null : request()->ip(),
                        'user_agent' => app()->runningInConsole() ? null : request()->userAgent(),
                    ]);
                }
            });
        }

        return $rapport;
    }

    /** Coût lu dans une cellule : « 1 234,56 », « 1234.56 », « 1.234,56 € »… ; null s'il est illisible ou négatif. */
    private function cout(string $brut): ?float
    {
        $texte = preg_replace('/[^\d,.\-]/u', '', $brut);
        if ($texte === '' || $texte === null) {
            return null;
        }
        $virgule = strrpos($texte, ',');
        $point = strrpos($texte, '.');
        if ($virgule !== false && $point !== false) {
            // Le dernier séparateur est celui des décimales ; l'autre sépare les milliers.
            $texte = $virgule > $point ? str_replace(['.', ','], ['', '.'], $texte) : str_replace(',', '', $texte);
        } elseif ($virgule !== false) {
            $texte = str_replace(',', '.', $texte);
        }
        if (! is_numeric($texte) || (float) $texte < 0) {
            return null;
        }

        return round((float) $texte, 2);
    }
}
