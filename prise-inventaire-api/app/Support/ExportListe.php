<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Export Excel d'une liste (plan de parité fripv2, S5) : `?format=xlsx` sur le GET de la liste renvoie toutes les lignes
 * de la requête filtrée (pas seulement la page affichée), dans l'ordre de la liste, avec les mêmes droits que sa lecture.
 */
final class ExportListe
{
    /** Au plus ce nombre de lignes par fichier. */
    public const LIMITE = 20000;

    public static function demande(Request $request): bool
    {
        return $request->query('format') === 'xlsx';
    }

    /**
     * @param  Builder<Model>  $requete  la requête filtrée et triée de la liste
     * @param  list<array{0: string, 1: string, 2: callable(Model): mixed, 3?: bool}>  $colonnes  [titre fr, titre en, valeur, montant]
     */
    public static function reponse(Builder $requete, string $fichier, string $feuille, array $colonnes): Response
    {
        $anglais = app()->getLocale() === 'en';
        $lignes = $requete->limit(self::LIMITE)->get()
            ->map(fn (Model $ligne) => array_map(fn (array $colonne) => self::valeur(($colonne[2])($ligne), $colonne[3] ?? false, $anglais), $colonnes))
            ->all();
        $contenu = ClasseurXlsx::contenu(
            $feuille,
            array_map(fn (array $colonne) => $anglais ? $colonne[1] : $colonne[0], $colonnes),
            $lignes,
            array_map(fn (array $colonne) => $colonne[3] ?? false, $colonnes),
        );

        return response($contenu, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$fichier.'-'.now()->format('Y-m-d').'.xlsx"',
        ]);
    }

    /** Libellé d'un statut de document, celui de l'entreprise (Paramètres → Statuts des documents) dans la langue. */
    public static function statut(string $document, ?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $anglais = app()->getLocale() === 'en';
        foreach (app(StatutsDocuments::class)->tous()[$document] ?? [] as $statut) {
            if ($statut['code'] === $code) {
                return $anglais ? $statut['libelle_en'] : $statut['libelle_fr'];
            }
        }

        return $code;
    }

    /** Valeur d'une cellule : date au format du pays, oui ou non, montant en nombre, sinon le texte. */
    private static function valeur(mixed $valeur, bool $montant, bool $anglais): mixed
    {
        return match (true) {
            $valeur === null || $valeur === '' => null,
            $valeur instanceof DateTimeInterface => $valeur->format($anglais ? 'Y-m-d' : 'd/m/Y'),
            is_bool($valeur) => $valeur ? ($anglais ? 'Yes' : 'Oui') : ($anglais ? 'No' : 'Non'),
            $montant && is_numeric($valeur) => (float) $valeur,
            is_int($valeur), is_float($valeur) => $valeur,
            default => (string) $valeur,
        };
    }
}
