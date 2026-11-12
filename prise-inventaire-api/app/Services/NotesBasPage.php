<?php

namespace App\Services;

use App\Models\BonLivraison;
use App\Models\ComClientEntete;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\NoteBasPage;
use Illuminate\Database\Eloquent\Model;

/**
 * Notes de bas de page des documents de vente (plan de parité fripv2, socle S4.4).
 *
 * - Le document retient sa note : vide, celle par défaut de son type (dans la langue, sinon pour toutes les langues) ;
 *   0, aucune ; sinon la note choisie.
 * - Son texte se fige avec l'identité du document (envoi, émission, voir IdentitesFigees) : une réimpression ressort la
 *   même note, même si elle a été modifiée ou retirée depuis.
 */
class NotesBasPage
{
    /** Modèle => type de document des notes. */
    public const DOCUMENTS = [
        Devis::class => 'devis',
        ComClientEntete::class => 'commande_client',
        Facture::class => 'facture',
        BonLivraison::class => 'bon_livraison',
    ];

    /** Texte de la note du document (figé s'il l'est), ou null. */
    public function texte(Model $document, string $langue = 'fr'): ?string
    {
        $figee = $document->getAttribute('identite_figee');
        if (is_array($figee) && array_key_exists('note_bas_page', $figee)) {
            return $figee['note_bas_page'];
        }

        return $this->actuelle($document, $langue);
    }

    /** Texte de la note d'après le choix du document et les notes d'aujourd'hui. */
    public function actuelle(Model $document, string $langue = 'fr'): ?string
    {
        $type = self::DOCUMENTS[$document::class] ?? null;
        $choix = $document->getAttribute('note_bas_page_id');
        if (! $type || $choix === 0) {
            return null;
        }
        if ($choix) {
            return NoteBasPage::withTrashed()->whereKey($choix)->value('contenu');
        }

        return NoteBasPage::query()->where('document', $type)->where('actif', true)->where('par_defaut', true)
            ->where(fn ($q) => $q->where('langue', $langue)->orWhereNull('langue'))
            // La note de la langue avant celle de toutes les langues, puis la plus récente.
            ->orderByRaw('langue is null')->orderByDesc('id')
            ->value('contenu');
    }

    /** Règle du choix d'un document : une note active de son type, 0 (aucune) ou vide (par défaut). */
    public static function regle(string $type, ?int $actuelle = null): array
    {
        return ['nullable', 'integer', function (string $attribut, mixed $valeur, \Closure $echec) use ($type, $actuelle) {
            if ((int) $valeur === 0 || (int) $valeur === $actuelle) {
                return;
            }
            if (! NoteBasPage::query()->whereKey($valeur)->where('document', $type)->where('actif', true)->exists()) {
                $echec(__('Cette note de bas de page ne convient pas à ce document.'));
            }
        }];
    }
}
