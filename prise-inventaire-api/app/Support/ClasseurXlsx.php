<?php

namespace App\Support;

/**
 * Classeur Excel (.xlsx) d'une seule feuille, écrit sans bibliothèque : le paquet est un zip non compressé (« stored »),
 * les textes sont des chaînes en ligne, les nombres de vrais nombres (les montants au format 0,00), la première ligne
 * (les titres) en gras. Assez pour exporter un tableau de rapport et l'ouvrir dans Excel ou LibreOffice.
 */
final class ClasseurXlsx
{
    /**
     * @param  list<string>  $titres
     * @param  list<list<mixed>>  $lignes  valeurs : texte, nombre (int ou float) ou null
     * @param  list<bool>  $montants  colonnes à afficher avec deux décimales
     */
    public static function contenu(string $feuille, array $titres, array $lignes, array $montants = []): string
    {
        $fichiers = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="'.self::texte(mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $feuille) ?: 'Rapport', 0, 31)).'" sheetId="1" r:id="rId1"/></sheets>'
                .'</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            // Styles : 0 ordinaire, 1 titre en gras, 2 montant (0,00 avec séparateur de milliers).
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                .'<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
                .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                .'</styleSheet>',
            'xl/worksheets/sheet1.xml' => self::feuille($titres, $lignes, $montants),
        ];

        return self::zip($fichiers);
    }

    private static function feuille(array $titres, array $lignes, array $montants): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $xml .= self::ligne(1, $titres, [], true);
        foreach (array_values($lignes) as $index => $ligne) {
            $xml .= self::ligne($index + 2, $ligne, $montants, false);
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function ligne(int $numero, array $valeurs, array $montants, bool $titre): string
    {
        $xml = '<row r="'.$numero.'">';
        foreach (array_values($valeurs) as $colonne => $valeur) {
            $reference = self::colonne($colonne).$numero;
            if ($valeur === null || $valeur === '') {
                continue;
            }
            if (! $titre && (is_int($valeur) || is_float($valeur))) {
                $style = ($montants[$colonne] ?? false) ? ' s="2"' : '';
                $xml .= '<c r="'.$reference.'"'.$style.'><v>'.(is_float($valeur) ? rtrim(rtrim(sprintf('%.6F', $valeur), '0'), '.') : $valeur).'</v></c>';
            } else {
                $xml .= '<c r="'.$reference.'" t="inlineStr"'.($titre ? ' s="1"' : '').'><is><t xml:space="preserve">'.self::texte((string) $valeur).'</t></is></c>';
            }
        }

        return $xml.'</row>';
    }

    /** Lettres de la colonne : 0 → A, 25 → Z, 26 → AA. */
    private static function colonne(int $index): string
    {
        $lettres = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $lettres = chr(65 + ($n - 1) % 26).$lettres;
        }

        return $lettres;
    }

    /** Texte sûr pour le XML : caractères de contrôle retirés, caractères spéciaux échappés. */
    private static function texte(string $valeur): string
    {
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $valeur) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Paquet zip non compressé (méthode « stored ») : en-tête local et données de chaque fichier, répertoire central,
     * fin du répertoire.
     *
     * @param  array<string, string>  $fichiers  nom => contenu
     */
    private static function zip(array $fichiers): string
    {
        $donnees = '';
        $repertoire = '';
        // Date et heure DOS fixes (1er janvier 1980) : le classeur est le même pour les mêmes données.
        [$heure, $jour] = [0, (1 << 5) | 1];
        foreach ($fichiers as $nom => $contenu) {
            $crc = crc32($contenu);
            $taille = strlen($contenu);
            $decalage = strlen($donnees);
            $donnees .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0x0800, 0, $heure, $jour, $crc, $taille, $taille, strlen($nom), 0).$nom.$contenu;
            $repertoire .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0x0800, 0, $heure, $jour, $crc, $taille, $taille, strlen($nom), 0, 0, 0, 0, 0, $decalage).$nom;
        }

        return $donnees.$repertoire.pack('VvvvvVVv', 0x06054B50, 0, 0, count($fichiers), count($fichiers), strlen($repertoire), strlen($donnees), 0);
    }
}
