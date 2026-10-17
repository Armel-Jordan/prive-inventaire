<?php

namespace App\Rapports;

use App\Models\Client;
use App\Models\Fournisseur;
use App\Support\ClasseurXlsx;
use App\Support\EntreprisePdf;
use App\Support\FichierCsv;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

/**
 * Production d'un rapport de la bibliothèque : ses données (colonnes dans la langue du rapport, lignes, totaux) et ses
 * fichiers CSV, Excel et PDF, les mêmes au téléchargement et en pièce jointe d'un courriel.
 */
final class Production
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    public const TYPES_MIME = [
        'csv' => 'text/csv',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf' => 'application/pdf',
    ];

    /**
     * Colonnes (clé => libellé dans la langue du rapport, type, total), lignes et totaux.
     *
     * @return array{colonnes: array<string, array{libelle: string, type: string, total: bool}>, lignes: list<array<string, mixed>>, totaux: array<string, int|float>}
     */
    public function donnees(Rapport $rapport, array $valeurs): array
    {
        $colonnes = array_map(fn (array $c) => ['libelle' => $rapport->texte($c['libelle']), 'type' => $c['type'], 'total' => $c['total'] ?? false],
            $rapport->colonnes($valeurs));
        $lignes = $rapport->lignes($valeurs);

        return ['colonnes' => $colonnes, 'lignes' => $lignes, 'totaux' => $this->totaux($colonnes, $lignes)];
    }

    /** Nom du fichier : code du rapport et date du jour. */
    public function nomFichier(Rapport $rapport, string $format): string
    {
        return $rapport->code().'-'.today()->toDateString().'.'.$format;
    }

    /**
     * Le rapport en fichier ; le PDF donne son heure d'établissement dans le fuseau indiqué.
     *
     * @return array{nom: string, mime: string, contenu: string}
     */
    public function fichier(Rapport $rapport, array $valeurs, string $format, ?string $fuseau = null): array
    {
        ['colonnes' => $colonnes, 'lignes' => $lignes, 'totaux' => $totaux] = $this->donnees($rapport, $valeurs);
        $cles = array_keys($colonnes);
        $titres = array_column($colonnes, 'libelle');
        $valeursLignes = array_map(fn (array $l) => array_map(fn (string $cle) => $l[$cle] ?? null, $cles), $lignes);
        if ($totaux) {
            $valeursLignes[] = array_map(fn (string $cle, int $index) => $totaux[$cle] ?? ($index === 0 ? 'Total' : null), $cles, array_keys($cles));
        }

        $contenu = match ($format) {
            'csv' => FichierCsv::contenu($titres, array_map(fn (array $l) => array_map(fn ($v) => is_int($v) ? (string) $v : $v, $l), $valeursLignes)),
            'xlsx' => ClasseurXlsx::contenu($rapport->texte($rapport->titre()), $titres, $valeursLignes,
                array_map(fn (string $cle) => $colonnes[$cle]['type'] === 'montant', $cles)),
            'pdf' => Pdf::loadView('pdf.rapport', [
                'langue' => $rapport->langue,
                'titre' => $rapport->texte($rapport->titre()),
                'description' => $rapport->texte($rapport->description()),
                'parametres' => $this->parametresLisibles($rapport, $valeurs),
                'colonnes' => array_map(fn (array $c) => ['libelle' => $c['libelle'], 'type' => $c['type']], $colonnes),
                'lignes' => $lignes,
                'totaux' => $totaux,
                'genere' => now()->setTimezone($fuseau ?: config('app.timezone'))->format($rapport->langue === 'en' ? 'Y-m-d H:i' : 'd/m/Y H:i'),
            ] + EntreprisePdf::entete())
                ->setPaper('A4', count($colonnes) > 6 ? 'landscape' : 'portrait')->setOption('isFontSubsettingEnabled', true)
                ->output(),
        };

        return ['nom' => $this->nomFichier($rapport, $format), 'mime' => self::TYPES_MIME[$format], 'contenu' => $contenu];
    }

    /** Paramètres renseignés, lisibles : libellé => valeur (date, option, client, fournisseur). */
    public function parametresLisibles(Rapport $rapport, array $valeurs): array
    {
        $lisibles = [];
        foreach ($rapport->parametres() as $cle => $parametre) {
            $valeur = $valeurs[$cle] ?? null;
            if ($valeur === null || $valeur === '') {
                continue;
            }
            $lisibles[$rapport->texte($parametre['libelle'])] = match ($parametre['type']) {
                'date' => Carbon::parse($valeur)->format($rapport->langue === 'en' ? 'Y-m-d' : 'd/m/Y'),
                'choix' => $rapport->texte($parametre['options'][$valeur] ?? ['fr' => (string) $valeur]),
                'client' => Client::withTrashed()->find($valeur)?->raison_sociale ?? (string) $valeur,
                'fournisseur' => Fournisseur::withTrashed()->find($valeur)?->raison_sociale ?? (string) $valeur,
                default => (string) $valeur,
            };
        }

        return $lisibles;
    }

    /** Totaux des colonnes qui en ont (montants arrondis au centime). */
    private function totaux(array $colonnes, array $lignes): array
    {
        $totaux = [];
        foreach ($colonnes as $cle => $colonne) {
            if ($colonne['total']) {
                $somme = array_sum(array_map(fn (array $l) => (float) ($l[$cle] ?? 0), $lignes));
                $totaux[$cle] = match ($colonne['type']) {
                    'entier' => (int) round($somme),
                    'pourcentage' => round($somme, 1),
                    'quantite' => round($somme, 4),
                    default => round($somme, 2),
                };
            }
        }

        return $totaux;
    }
}
