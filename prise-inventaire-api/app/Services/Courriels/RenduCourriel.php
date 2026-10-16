<?php

namespace App\Services\Courriels;

use App\Models\CourrielParametres;
use App\Support\Courriels;
use Illuminate\Support\Str;

/**
 * Rend un modèle de courriel avec les valeurs d'un document : objet, corps HTML dans la mise en
 * page commune (signature et coordonnées de l'entreprise), version texte et nom du PDF joint.
 *
 * Le contenu accepte une mise en forme légère (**gras**, *italique*, listes, liens) ; le HTML
 * saisi est affiché tel quel, jamais interprété, et les valeurs insérées sont échappées.
 */
class RenduCourriel
{
    public function __construct(private readonly VariablesCourriel $variables) {}

    /**
     * @param  array{objet: string, contenu: string, nom_fichier?: ?string}  $modele
     * @param  bool  $surligner  surligne les variables inconnues (aperçu)
     * @return array{objet: string, html: string, texte: string, nom_fichier: ?string, inconnues: list<string>}
     */
    public function rendre(string $type, array $modele, array $valeurs, string $langue, bool $surligner = false): array
    {
        $signature = CourrielParametres::first()?->signature;
        $nomFichier = $modele['nom_fichier'] ?? null;
        $objet = $this->ligne($this->variables->remplacer($modele['objet'], $valeurs));

        $html = view('emails.courriel', [
            'langue' => $langue,
            'titre' => $objet,
            'corps' => $this->html($modele['contenu'], $valeurs, $surligner),
            'signature' => filled($signature) ? $this->html($signature, $valeurs, $surligner) : null,
            'entreprise' => [
                'nom' => $valeurs['entreprise_nom'] ?? '',
                'details' => array_values(array_filter([
                    $this->ligne($valeurs['entreprise_adresse'] ?? ''),
                    $valeurs['entreprise_telephone'] ?? '',
                    $valeurs['entreprise_courriel'] ?? '',
                ])),
            ],
        ])->render();

        $texte = trim($this->variables->remplacer($modele['contenu'], $valeurs));
        if (filled($signature)) {
            $texte .= "\n\n".trim($this->variables->remplacer($signature, $valeurs));
        }

        return [
            'objet' => $objet,
            'html' => $html,
            'texte' => $texte,
            'nom_fichier' => $this->nomFichier($type, $nomFichier, $valeurs),
            'inconnues' => $this->variables->inconnues(implode("\n", [$modele['objet'], $modele['contenu'], $nomFichier ?? '', $signature ?? '']), $valeurs),
        ];
    }

    /** Nom du PDF joint : gabarit du modèle, sinon le nom habituel du document ; null si le type n'a pas de pièce jointe. */
    public function nomFichier(string $type, ?string $gabarit, array $valeurs): ?string
    {
        $prefixe = Courriels::FICHIERS[$type] ?? null;
        if ($prefixe === null) {
            return null;
        }

        $nom = filled($gabarit) ? $this->variables->remplacer($gabarit, $valeurs) : $prefixe.'-'.($valeurs['numero'] ?? '');
        // Caractères interdits dans un nom de fichier ; l'extension est ajoutée ici.
        $nom = $this->ligne(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $nom));
        $nom = preg_replace('/\.pdf$/i', '', $nom);

        return Str::limit($nom !== '' ? $nom : $prefixe, 150, '').'.pdf';
    }

    private function html(string $source, array $valeurs, bool $surligner): string
    {
        $html = Str::markdown($source, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            // Un simple retour à la ligne reste un retour à la ligne.
            'renderer' => ['soft_break' => "<br>\n"],
        ]);
        $html = $this->variables->remplacer($html, $valeurs, fn (string $valeur) => nl2br(e($valeur), false));

        return $surligner ? preg_replace('/\{[a-z_]+\}/', '<mark>$0</mark>', $html) : $html;
    }

    private function ligne(string $texte): string
    {
        return trim(preg_replace('/\s+/u', ' ', $texte));
    }
}
