<?php

namespace App\Observers;

use App\Models\Acompte;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Avoir;
use App\Models\BonLivraison;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\ComFourEntete;
use App\Models\Configuration;
use App\Models\Debourse;
use App\Models\DemandeAcompte;
use App\Models\Devis;
use App\Models\EmployeTenant;
use App\Models\Encaissement;
use App\Models\Facture;
use App\Models\Fournisseur;
use App\Models\MouvementTenant;
use App\Models\ProduitTenant;
use App\Models\ScanTenant;
use App\Models\Secteur;
use App\Models\TenantParametres;
use App\Models\TenantTaxe;
use App\Models\TransactionBancaire;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Journal des modifications (écran Historique) : création, modification et suppression des fiches et des
 * documents, avec l'auteur, l'adresse IP et les valeurs avant / après. Le journal existait (table, écran,
 * AuditLog::log) sans que rien ne l'alimente : il restait vide.
 *
 * Une erreur d'écriture du journal n'empêche jamais l'opération elle-même.
 */
class JournalModifications
{
    /**
     * Modèles journalisés => [libellé dans le journal, actions journalisées]. Les scans et les mouvements, très
     * nombreux et déjà historisés par nature, ne journalisent que leurs corrections et suppressions.
     */
    public const MODELES = [
        ProduitTenant::class => ['Produit', ['create', 'update', 'delete']],
        Secteur::class => ['Secteur', ['create', 'update', 'delete']],
        EmployeTenant::class => ['Employé', ['create', 'update', 'delete']],
        AdminUser::class => ['Compte utilisateur', ['create', 'update', 'delete']],
        Client::class => ['Client', ['create', 'update', 'delete']],
        Fournisseur::class => ['Fournisseur', ['create', 'update', 'delete']],
        Devis::class => ['Devis', ['create', 'update', 'delete']],
        ComClientEntete::class => ['Commande client', ['create', 'update', 'delete']],
        ComFourEntete::class => ['Commande fournisseur', ['create', 'update', 'delete']],
        Facture::class => ['Facture', ['create', 'update', 'delete']],
        Avoir::class => ['Note de crédit', ['create', 'update', 'delete']],
        Acompte::class => ['Acompte', ['create', 'update', 'delete']],
        Encaissement::class => ['Encaissement', ['create', 'update', 'delete']],
        Debourse::class => ['Déboursé', ['create', 'update', 'delete']],
        TransactionBancaire::class => ['Transaction bancaire', ['create', 'update', 'delete']],
        DemandeAcompte::class => ["Demande d'acompte", ['create', 'update', 'delete']],
        BonLivraison::class => ['Bon de livraison', ['create', 'update', 'delete']],
        TenantParametres::class => ['Paramètres', ['create', 'update', 'delete']],
        TenantTaxe::class => ['Taxe', ['create', 'update', 'delete']],
        Configuration::class => ['Numérotation', ['create', 'update', 'delete']],
        ScanTenant::class => ['Scan', ['update', 'delete']],
        MouvementTenant::class => ['Mouvement', ['update', 'delete']],
    ];

    /** Jamais écrit dans le journal : secrets et horodatages techniques. */
    private const EXCLUS = [
        'password', 'remember_token', 'deux_facteurs_secret', 'deux_facteurs_codes_secours',
        'created_at', 'updated_at', 'deleted_at', 'tenant_id',
    ];

    public function created(Model $modele): void
    {
        $this->journaliser('create', $modele, null, $this->valeurs($modele, $modele->getAttributes()));
    }

    public function updated(Model $modele): void
    {
        $changements = $modele->getChanges();

        // Suppression « douce » faite par un simple enregistrement de deleted_at (scans) : une suppression.
        if (array_key_exists('deleted_at', $changements) && $modele->getAttribute('deleted_at') !== null && $modele->getOriginal('deleted_at') === null) {
            $this->journaliser('delete', $modele, $this->valeurs($modele, $modele->getOriginal()), null);

            return;
        }

        $apres = $this->valeurs($modele, $changements);
        if ($apres === []) {
            return;
        }
        $avant = array_intersect_key($this->valeurs($modele, $modele->getOriginal()), $apres);

        $this->journaliser('update', $modele, $avant, $apres);
    }

    public function deleted(Model $modele): void
    {
        $this->journaliser('delete', $modele, $this->valeurs($modele, $modele->getOriginal()), null);
    }

    private function valeurs(Model $modele, array $valeurs): array
    {
        return array_diff_key($valeurs, array_flip([...self::EXCLUS, ...$modele->getHidden()]));
    }

    private function journaliser(string $action, Model $modele, ?array $avant, ?array $apres): void
    {
        [$libelle, $actions] = self::MODELES[$modele::class] ?? [class_basename($modele), ['create', 'update', 'delete']];
        if (! in_array($action, $actions, true)) {
            return;
        }

        // Hors d'un contexte d'entreprise (commandes, initialisation des données), rien n'est journalisé.
        $contexte = app(TenantContext::class);
        if (! $contexte->hasTenant() && ! $contexte->isSuperAdmin()) {
            return;
        }

        try {
            $auteur = auth()->user();
            $tenantId = $modele->getAttribute('tenant_id') ?? $auteur?->tenant_id;
            if (! $tenantId) {
                return;
            }

            AuditLog::create([
                'tenant_id' => $tenantId,
                'action' => $action,
                'model_type' => $libelle,
                'model_id' => (string) $modele->getKey(),
                'user_id' => $auteur ? (string) $auteur->getAuthIdentifier() : null,
                'user_name' => $auteur?->nom ?: $auteur?->email,
                'old_values' => $avant,
                'new_values' => $apres,
                'ip_address' => app()->runningInConsole() ? null : request()->ip(),
                'user_agent' => app()->runningInConsole() ? null : request()->userAgent(),
            ]);
        } catch (Throwable $erreur) {
            report($erreur);
        }
    }
}
