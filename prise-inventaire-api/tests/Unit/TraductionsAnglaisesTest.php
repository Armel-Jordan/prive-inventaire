<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Messages de l'API en anglais (phase 6.8b) : chaque texte passé à __() dans le code de l'API (en français, la langue
 * par défaut) a sa traduction dans lang/en.json, avec les mêmes paramètres (« :nom »). Un message ajouté sans
 * traduction fait échouer ce test.
 */
class TraductionsAnglaisesTest extends TestCase
{
    /** @return array<string, string> texte français => fichier où il apparaît */
    private function textesDuCode(): array
    {
        $textes = [];
        $racine = dirname(__DIR__, 2).'/app';
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS));
        foreach ($fichiers as $fichier) {
            if ($fichier->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($fichier->getPathname());
            // __('…') ou __("…") : chaîne littérale en premier argument (sans variable).
            preg_match_all('/\b__\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\$]|\\\\.)*")/u', $source, $trouves);
            foreach ($trouves[1] as $litteral) {
                $texte = $litteral[0] === "'"
                    ? strtr(substr($litteral, 1, -1), ['\\\'' => '\'', '\\\\' => '\\'])
                    : strtr(substr($litteral, 1, -1), ['\\"' => '"', '\\\\' => '\\', '\\$' => '$']);
                $textes[$texte] = substr($fichier->getPathname(), strlen($racine) + 1);
            }
        }

        return $textes;
    }

    private function traductions(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/lang/en.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_chaque_message_de_l_api_a_sa_traduction_anglaise(): void
    {
        $traductions = $this->traductions();
        $manquants = [];
        foreach ($this->textesDuCode() as $texte => $fichier) {
            if (! array_key_exists($texte, $traductions)) {
                $manquants[] = "{$fichier} : {$texte}";
            }
        }

        $this->assertSame([], $manquants, 'Messages sans traduction dans lang/en.json');
    }

    public function test_les_traductions_gardent_les_parametres_des_messages(): void
    {
        $differents = [];
        foreach ($this->traductions() as $francais => $anglais) {
            preg_match_all('/:([a-z_]+)/i', $francais, $attendus);
            preg_match_all('/:([a-z_]+)/i', $anglais, $obtenus);
            $attendus = array_unique(array_map('strtolower', $attendus[1]));
            $obtenus = array_unique(array_map('strtolower', $obtenus[1]));
            sort($attendus);
            sort($obtenus);
            if ($attendus !== $obtenus) {
                $differents[] = "{$francais} => {$anglais}";
            }
        }

        $this->assertSame([], $differents, 'Paramètres différents entre le français et l\'anglais');
    }
}
