<?php

namespace App\Services\Banque;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Lecture d'un relevé bancaire exporté par la banque :
 * - OFX (1.x SGML ou 2.x XML) : chaque transaction (date, montant signé, FITID, nom, mémo, n° de chèque) ;
 * - CAMT (ISO 20022, camt.053 surtout) : chaque opération passée par la banque (date, montant et sens, référence de la
 *   banque, information, tiers, communication, référence de bout en bout) ;
 * - CSV : les colonnes de la date, du libellé, du montant signé (ou du débit et du crédit, vus par la banque : un débit
 *   sort du compte), et de la référence, numérotées à partir de 1, à partir d'une ligne de début ; séparateur détecté,
 *   dates aux formats usuels, montants « 1 234,56 », « -12.50 », « (12,50) ».
 * Chaque opération reçoit un identifiant stable (le FITID, sinon une empreinte de la ligne) : un réimport ne la double pas.
 */
class LecteurReleves
{
    public const MAX_LIGNES = 5000;

    public function estOfx(string $contenu): bool
    {
        return (bool) preg_match('/OFXHEADER|<OFX>/i', substr($contenu, 0, 2000));
    }

    /**
     * @return array{lignes: list<array{date: string, libelle: string, reference: ?string, montant: float, identifiant: string}>, ignorees: list<array{ligne: int, raison: string}>}
     */
    public function lireOfx(string $contenu): array
    {
        $contenu = $this->utf8($contenu);
        preg_match_all('/<STMTTRN>(.*?)(?=<\/STMTTRN>|<STMTTRN>|<\/BANKTRANLIST>|$)/is', $contenu, $blocs);
        if (($blocs[1] ?? []) === []) {
            throw ValidationException::withMessages(['fichier' => 'Aucune opération trouvée dans ce fichier OFX.']);
        }
        $lignes = [];
        $ignorees = [];
        foreach ($blocs[1] as $index => $bloc) {
            $valeur = fn (string $balise) => preg_match('/<'.$balise.'>([^<\r\n]*)/i', $bloc, $m) ? trim(html_entity_decode($m[1])) : '';
            $date = preg_match('/^(\d{4})(\d{2})(\d{2})/', $valeur('DTPOSTED'), $d) ? "{$d[1]}-{$d[2]}-{$d[3]}" : null;
            $montant = $this->montant($valeur('TRNAMT'));
            if (! $date || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)) || $montant === null || $montant == 0) {
                $ignorees[] = ['ligne' => $index + 1, 'raison' => 'Date ou montant illisible.'];

                continue;
            }
            $libelle = $this->libelle($valeur('NAME') ?: $valeur('PAYEE'), $valeur('MEMO'));
            $fitid = $valeur('FITID');
            $lignes[] = [
                'date' => $date,
                'libelle' => mb_substr($libelle, 0, 255),
                'reference' => mb_substr($valeur('CHECKNUM') ?: $valeur('REFNUM'), 0, 100) ?: null,
                'montant' => $montant,
                'identifiant' => $fitid !== '' ? mb_substr('ofx:'.$fitid, 0, 100) : $this->empreinte($date, $montant, $libelle, '', $index),
            ];
        }

        return ['lignes' => $lignes, 'ignorees' => $ignorees];
    }

    /** Fichier CAMT (ISO 20022 : relevé camt.053, ou camt.052 et camt.054) ? */
    public function estCamt(string $contenu): bool
    {
        return (bool) preg_match('/camt\.05[234]|<(\w+:)?BkToCstmr/i', substr($contenu, 0, 4000));
    }

    /**
     * Opérations d'un fichier CAMT : celles passées par la banque (statut BOOK), datées de leur comptabilisation (sinon
     * de leur valeur), signées par le sens (DBIT sort du compte) ; le libellé est l'information de l'opération, sinon
     * le tiers, avec la communication ; la référence est l'identifiant de bout en bout ; l'identifiant stable est la
     * référence de la banque.
     *
     * @return array{lignes: list<array{date: string, libelle: string, reference: ?string, montant: float, identifiant: string}>, ignorees: list<array{ligne: int, raison: string}>}
     */
    public function lireCamt(string $contenu): array
    {
        // Un relevé n'a pas de déclaration de type : refusée, elle ne peut ni lire un fichier ni s'étendre (XXE).
        if (stripos($contenu, '<!DOCTYPE') !== false || stripos($contenu, '<!ENTITY') !== false) {
            throw ValidationException::withMessages(['fichier' => 'Ce fichier CAMT contient une déclaration de type de document : il est refusé.']);
        }
        $document = new DOMDocument;
        $erreurs = libxml_use_internal_errors(true);
        $lu = $document->loadXML($contenu, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($erreurs);
        $xpath = $lu ? new DOMXPath($document) : null;
        $entrees = $xpath?->query('//'.$this->chemin('Ntry'));
        if (! $xpath || ! $entrees || $entrees->length === 0) {
            throw ValidationException::withMessages(['fichier' => 'Aucune opération trouvée dans ce fichier CAMT.']);
        }
        $valeur = fn (DOMNode $noeud, string $chemin) => trim((string) preg_replace('/\s+/u', ' ', (string) $xpath->evaluate("string({$chemin})", $noeud)));

        $lignes = [];
        $ignorees = [];
        $occurrences = [];
        foreach ($entrees as $index => $entree) {
            $statut = strtoupper($valeur($entree, $this->chemin('Sts')));
            if ($statut !== '' && ! str_starts_with($statut, 'BOOK')) {
                $ignorees[] = ['ligne' => $index + 1, 'raison' => 'Opération pas encore passée par la banque.'];

                continue;
            }
            $date = substr($valeur($entree, $this->chemin('BookgDt').'/*') ?: $valeur($entree, $this->chemin('ValDt').'/*'), 0, 10);
            $montant = $this->montant($valeur($entree, $this->chemin('Amt')));
            if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $d) || ! checkdate((int) $d[2], (int) $d[3], (int) $d[1]) || $montant === null || $montant == 0) {
                $ignorees[] = ['ligne' => $index + 1, 'raison' => 'Date ou montant illisible.'];

                continue;
            }
            $debit = strtoupper($valeur($entree, $this->chemin('CdtDbtInd'))) === 'DBIT';
            $montant = round(($debit ? -1 : 1) * abs($montant), 2);
            // Le tiers : le créancier d'une sortie, le débiteur d'une entrée.
            $tiers = $valeur($entree, './/'.$this->chemin('RltdPties/'.($debit ? 'Cdtr' : 'Dbtr')).'//'.$this->chemin('Nm'));
            $libelle = $this->libelle($valeur($entree, $this->chemin('AddtlNtryInf')) ?: $tiers, $valeur($entree, './/'.$this->chemin('RmtInf/Ustrd')));
            $reference = $valeur($entree, './/'.$this->chemin('Refs/EndToEndId'));
            $reference = strtoupper($reference) === 'NOTPROVIDED' ? '' : $reference;
            $cle = $valeur($entree, $this->chemin('AcctSvcrRef')) ?: $valeur($entree, $this->chemin('NtryRef'));
            $rang = "{$date}|{$montant}|{$libelle}|{$reference}";
            $occurrences[$rang] = ($occurrences[$rang] ?? 0) + 1;
            $lignes[] = [
                'date' => $date,
                'libelle' => mb_substr($libelle, 0, 255),
                'reference' => $reference !== '' ? mb_substr($reference, 0, 100) : null,
                'montant' => $montant,
                'identifiant' => $cle !== '' ? mb_substr('camt:'.$cle, 0, 100) : $this->empreinte($date, $montant, $libelle, $reference, $occurrences[$rang]),
            ];
        }

        return ['lignes' => $lignes, 'ignorees' => $ignorees];
    }

    /**
     * @param  array{colonne_date: int, colonne_libelle: int, colonne_montant?: ?int, colonne_debit?: ?int, colonne_credit?: ?int, colonne_reference?: ?int, ligne_debut?: ?int, format_date?: ?string}  $reglages
     * @return array{lignes: list<array{date: string, libelle: string, reference: ?string, montant: float, identifiant: string}>, ignorees: list<array{ligne: int, raison: string}>}
     */
    public function lireCsv(string $contenu, array $reglages): array
    {
        if (empty($reglages['colonne_date']) || empty($reglages['colonne_libelle'])) {
            throw ValidationException::withMessages(['colonne_date' => 'Indiquez les colonnes de la date et du libellé.']);
        }
        if (empty($reglages['colonne_montant']) && empty($reglages['colonne_debit']) && empty($reglages['colonne_credit'])) {
            throw ValidationException::withMessages(['colonne_montant' => 'Indiquez la colonne du montant, ou celles du débit et du crédit.']);
        }
        $lignes = preg_split('/\r\n|\r|\n/', preg_replace('/^\xEF\xBB\xBF/', '', $this->utf8($contenu)));
        $nonVides = array_values(array_filter($lignes, fn (string $l) => trim($l) !== ''));
        if ($nonVides === []) {
            throw ValidationException::withMessages(['fichier' => 'Le fichier est vide.']);
        }
        if (count($lignes) > self::MAX_LIGNES) {
            throw ValidationException::withMessages(['fichier' => 'Le fichier dépasse '.self::MAX_LIGNES.' lignes.']);
        }
        $separateur = collect([';', "\t", ','])->sortByDesc(fn (string $s) => substr_count($nonVides[0], $s))->first();
        $cellule = fn (array $cellules, mixed $colonne) => empty($colonne) ? '' : trim((string) ($cellules[(int) $colonne - 1] ?? ''));

        $resultat = [];
        $ignorees = [];
        $occurrences = [];
        foreach (array_slice($lignes, max(1, (int) ($reglages['ligne_debut'] ?? 2)) - 1, null, true) as $index => $brute) {
            if (trim($brute) === '') {
                continue;
            }
            $cellules = str_getcsv($brute, $separateur);
            $date = $this->date($cellule($cellules, $reglages['colonne_date']), $reglages['format_date'] ?? null);
            if (! empty($reglages['colonne_montant'])) {
                $montant = $this->montant($cellule($cellules, $reglages['colonne_montant']));
            } else {
                // Vus par la banque : un débit sort du compte, un crédit y entre.
                $debit = $this->montant($cellule($cellules, $reglages['colonne_debit'] ?? null));
                $credit = $this->montant($cellule($cellules, $reglages['colonne_credit'] ?? null));
                $montant = $debit === null && $credit === null ? null : round(abs((float) $credit) - abs((float) $debit), 2);
            }
            if (! $date || $montant === null || $montant == 0) {
                $ignorees[] = ['ligne' => $index + 1, 'raison' => ! $date ? 'Date illisible.' : 'Montant illisible ou nul.'];

                continue;
            }
            $libelle = $cellule($cellules, $reglages['colonne_libelle']) ?: 'Opération';
            $reference = $cellule($cellules, $reglages['colonne_reference'] ?? null);
            // Deux opérations identiques du même jour se distinguent par leur rang.
            $cle = "{$date}|{$montant}|{$libelle}|{$reference}";
            $occurrences[$cle] = ($occurrences[$cle] ?? 0) + 1;
            $resultat[] = [
                'date' => $date,
                'libelle' => mb_substr($libelle, 0, 255),
                'reference' => $reference !== '' ? mb_substr($reference, 0, 100) : null,
                'montant' => $montant,
                'identifiant' => $this->empreinte($date, $montant, $libelle, $reference, $occurrences[$cle]),
            ];
        }

        return ['lignes' => $resultat, 'ignorees' => $ignorees];
    }

    /** Date d'une cellule : au format demandé (jma, amj, mja), sinon AAAA-MM-JJ ou JJ/MM/AAAA (et variantes). */
    private function date(string $brut, ?string $format): ?string
    {
        $brut = trim(explode(' ', trim($brut))[0] ?? '');
        $formats = match ($format) {
            'amj' => ['Y-m-d', 'Y/m/d', 'Ymd'],
            'mja' => ['m/d/Y', 'm-d-Y', 'm/d/y'],
            'jma' => ['d/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y'],
            default => ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'Ymd'],
        };
        foreach ($formats as $f) {
            $date = \DateTime::createFromFormat('!'.$f, $brut);
            if ($date && $date->format($f) === $brut) {
                return Carbon::instance($date)->toDateString();
            }
        }

        return null;
    }

    /** Montant signé d'une cellule : « 1 234,56 », « -12.50 », « (12,50) », « 1.234,56 € » ; null s'il est illisible. */
    private function montant(string $brut): ?float
    {
        $brut = trim($brut);
        $negatif = str_starts_with($brut, '-') || str_ends_with($brut, '-') || (str_starts_with($brut, '(') && str_ends_with($brut, ')'));
        $texte = preg_replace('/[^\d,.]/u', '', $brut);
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
        if (! is_numeric($texte)) {
            return null;
        }

        return round(($negatif ? -1 : 1) * (float) $texte, 2);
    }

    /** Libellé d'une opération : le nom, avec le mémo s'il ne le répète pas. */
    private function libelle(string $nom, string $memo): string
    {
        if ($nom === '' || $memo === '' || str_contains(mb_strtolower($nom), mb_strtolower($memo))) {
            return ($nom ?: $memo) ?: 'Opération';
        }

        return "{$nom} — {$memo}";
    }

    /** Chemin XPath sans espace de noms : « BookgDt/Dt » donne *[local-name()="BookgDt"]/*[local-name()="Dt"]. */
    private function chemin(string $chemin): string
    {
        return implode('/', array_map(fn (string $nom) => '*[local-name()="'.$nom.'"]', explode('/', $chemin)));
    }

    private function empreinte(string $date, float $montant, string $libelle, string $reference, int $rang): string
    {
        return 'csv:'.sha1(implode('|', [$date, number_format($montant, 2, '.', ''), mb_strtolower(trim($libelle)), trim($reference), $rang]));
    }

    /** Contenu en UTF-8 : les exports des banques sont souvent en Windows-1252. */
    private function utf8(string $contenu): string
    {
        return mb_check_encoding($contenu, 'UTF-8') ? $contenu : mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
    }
}
