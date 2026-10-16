<?php

namespace App\Services;

use App\Models\Avoir;
use App\Models\BonLivraison;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\ComFourEntete;
use App\Models\DemandeAcompte;
use App\Models\Devis;
use App\Models\EmployeTenant;
use App\Models\Facture;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\RetourClient;
use App\Models\Secteur;
use App\Models\Tenant;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Recherche globale (plan de parité fripv2, phase 6.4) : fiches et documents de l'entreprise courante
 * dont un champ clé contient le texte cherché, les correspondances exactes puis par début en premier.
 * Les types d'un module inactif (Ventes, Achats) sont ignorés. Lecture seule.
 */
final class RechercheGlobale
{
    /** Types disponibles, dans l'ordre d'affichage. */
    public const TYPES = [
        'produits', 'clients', 'fournisseurs', 'devis', 'commandes_client', 'factures', 'avoirs', 'retours_clients',
        'demandes_acompte', 'bons_livraison', 'commandes_fournisseur', 'secteurs', 'employes',
    ];

    /**
     * @param  list<string>  $types
     * @return list<array{type: string, total: int, resultats: list<array>}>
     */
    public function rechercher(string $texte, array $types, int $limite, ?Tenant $tenant): array
    {
        $motif = '%'.$texte.'%';
        $groupes = [];
        foreach ($this->definitions() as $type => $definition) {
            if (! in_array($type, $types, true) || ($definition['module'] && ! $tenant?->hasModule($definition['module']))) {
                continue;
            }

            $requete = $definition['requete']($motif);
            $total = (clone $requete)->count();
            if ($total === 0) {
                continue;
            }

            // Pertinence : valeur exacte, puis commençant par le texte, puis le reste.
            $cle = $definition['cle'];
            $resultats = $requete
                ->orderByRaw("CASE WHEN {$cle} = ? THEN 0 WHEN {$cle} LIKE ? THEN 1 ELSE 2 END", [$texte, $texte.'%'])
                ->orderBy($cle)
                ->limit($limite)
                ->get()
                ->map(fn ($modele) => ['type' => $type, ...$definition['resultat']($modele)])
                ->all();

            $groupes[] = ['type' => $type, 'total' => $total, 'resultats' => $resultats];
        }

        return $groupes;
    }

    /**
     * Pour chaque type : module requis (null = cœur), colonne de pertinence, requête filtrée et mise en forme
     * du résultat (titre, sous-titre, détails pour l'aperçu, page de l'application).
     *
     * @return array<string, array{module: ?string, cle: string, requete: Closure(string): Builder, resultat: Closure}>
     */
    private function definitions(): array
    {
        $contient = fn (array $colonnes) => fn (string $motif) => fn (Builder $q) => $q->where(function (Builder $q) use ($colonnes, $motif) {
            foreach ($colonnes as $colonne) {
                $q->orWhere($colonne, 'like', $motif);
            }
        });
        $date = fn ($valeur) => $valeur ? Carbon::parse($valeur)->toDateString() : null;
        $lien = fn (string $page, string $recherche) => $page.'?recherche='.rawurlencode($recherche);

        return [
            'produits' => [
                'module' => null,
                'cle' => 'numero',
                'requete' => fn (string $motif) => ProduitTenant::query()->with('secteur:id,code')->where($contient(['numero', 'description', 'categorie'])($motif)),
                'resultat' => fn (ProduitTenant $p) => [
                    'id' => $p->id,
                    'titre' => "{$p->numero} — {$p->description}",
                    'sous_titre' => $p->categorie,
                    'details' => ['categorie' => $p->categorie, 'prix' => $p->prix_unitaire !== null ? (float) $p->prix_unitaire : null, 'mesure' => $p->mesure, 'secteur' => $p->secteur?->code, 'actif' => (bool) $p->actif],
                    'lien' => $lien('/produits', $p->numero),
                ],
            ],
            'clients' => [
                'module' => 'ventes',
                'cle' => 'code',
                'requete' => fn (string $motif) => Client::query()->where($contient(['code', 'raison_sociale', 'ville', 'email', 'telephone'])($motif)),
                'resultat' => fn (Client $c) => [
                    'id' => $c->id,
                    'titre' => "{$c->code} — {$c->raison_sociale}",
                    'sous_titre' => $c->ville,
                    'details' => ['ville' => $c->ville, 'telephone' => $c->telephone, 'email' => $c->email, 'contact' => $c->contact_nom, 'encours' => (float) $c->encours_actuel, 'actif' => (bool) $c->actif],
                    // Les clients et les fournisseurs ont leur fiche : le résultat l'ouvre directement.
                    'lien' => "/clients/{$c->id}",
                ],
            ],
            'fournisseurs' => [
                'module' => 'achats',
                'cle' => 'code',
                'requete' => fn (string $motif) => Fournisseur::query()->where($contient(['code', 'raison_sociale', 'email', 'telephone'])($motif)),
                'resultat' => fn (Fournisseur $f) => [
                    'id' => $f->id,
                    'titre' => "{$f->code} — {$f->raison_sociale}",
                    'sous_titre' => $f->contact_nom,
                    'details' => ['telephone' => $f->telephone, 'email' => $f->email, 'contact' => $f->contact_nom, 'devise' => $f->devise, 'actif' => (bool) $f->actif],
                    'lien' => "/fournisseurs/{$f->id}",
                ],
            ],
            'devis' => [
                'module' => 'ventes',
                'cle' => 'numero',
                'requete' => fn (string $motif) => Devis::query()->with('client:id,raison_sociale')
                    ->where(fn ($q) => $q->where('numero', 'like', $motif)->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif))),
                'resultat' => fn (Devis $d) => [
                    'id' => $d->id,
                    'titre' => $d->numero,
                    'sous_titre' => $d->client?->raison_sociale,
                    'details' => ['client' => $d->client?->raison_sociale, 'date' => $date($d->date_devis), 'validite' => $date($d->date_validite), 'statut' => $d->statut, 'montant' => (float) $d->montant_total, 'devise' => $d->devise],
                    'lien' => $lien('/devis', $d->numero),
                ],
            ],
            'commandes_client' => [
                'module' => 'ventes',
                'cle' => 'numero',
                'requete' => fn (string $motif) => ComClientEntete::query()->with('client:id,raison_sociale')
                    ->where(fn ($q) => $q->where('numero', 'like', $motif)->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif))),
                'resultat' => fn (ComClientEntete $c) => [
                    'id' => $c->id,
                    'titre' => $c->numero,
                    'sous_titre' => $c->client?->raison_sociale,
                    'details' => ['client' => $c->client?->raison_sociale, 'date' => $date($c->date_commande), 'statut' => $c->statut, 'montant' => (float) $c->montant_ttc, 'devise' => $c->devise],
                    'lien' => $lien('/commandes-client', $c->numero),
                ],
            ],
            'factures' => [
                'module' => 'ventes',
                'cle' => 'numero',
                'requete' => fn (string $motif) => Facture::query()->with('client:id,raison_sociale')
                    ->where(fn ($q) => $q->where('numero', 'like', $motif)->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif))),
                'resultat' => fn (Facture $f) => [
                    'id' => $f->id,
                    'titre' => $f->numero,
                    'sous_titre' => $f->client?->raison_sociale,
                    'details' => ['client' => $f->client?->raison_sociale, 'date' => $date($f->date_facture), 'echeance' => $date($f->date_echeance), 'statut' => $f->statut, 'montant' => (float) $f->montant_ttc, 'reste' => (float) $f->reste_a_payer, 'devise' => $f->devise],
                    'lien' => $lien('/factures', $f->numero),
                ],
            ],
            // Notes de crédit émises (un brouillon n'a pas encore de numéro).
            'avoirs' => [
                'module' => 'ventes',
                'cle' => 'numero',
                'requete' => fn (string $motif) => Avoir::query()->with('client:id,raison_sociale', 'facture:id,numero')->whereNotNull('numero')
                    ->where(fn ($q) => $q->where('numero', 'like', $motif)
                        ->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif))
                        ->orWhereHas('facture', fn ($f) => $f->where('numero', 'like', $motif))),
                'resultat' => fn (Avoir $a) => [
                    'id' => $a->id,
                    'titre' => $a->numero,
                    'sous_titre' => $a->client?->raison_sociale,
                    'details' => ['client' => $a->client?->raison_sociale, 'facture' => $a->facture?->numero, 'date' => $date($a->date_avoir), 'statut' => $a->statut, 'montant' => (float) $a->montant_ttc, 'disponible' => $a->credit_disponible, 'devise' => $a->devise],
                    'lien' => $lien('/notes-credit', $a->numero),
                ],
            ],
            // Retours traités (un brouillon n'a pas encore de numéro).
            'retours_clients' => [
                'module' => 'ventes',
                'cle' => 'numero',
                'requete' => fn (string $motif) => RetourClient::query()->with('client:id,raison_sociale', 'facture:id,numero')
                    ->withSum('lignes as quantite_totale', 'quantite')->whereNotNull('numero')
                    ->where(fn ($q) => $q->where('numero', 'like', $motif)
                        ->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif))
                        ->orWhereHas('facture', fn ($f) => $f->where('numero', 'like', $motif))),
                'resultat' => fn (RetourClient $r) => [
                    'id' => $r->id,
                    'titre' => $r->numero,
                    'sous_titre' => $r->client?->raison_sociale,
                    'details' => ['client' => $r->client?->raison_sociale, 'facture' => $r->facture?->numero, 'date' => $date($r->date_retour), 'statut' => $r->statut, 'quantite' => (int) $r->quantite_totale],
                    'lien' => $lien('/retours-clients', $r->numero),
                ],
            ],
            'demandes_acompte' => [
                'module' => 'ventes',
                'cle' => 'numero',
                'requete' => fn (string $motif) => DemandeAcompte::query()->with('client:id,raison_sociale')
                    ->where(fn ($q) => $q->where('numero', 'like', $motif)->orWhere('description', 'like', $motif)
                        ->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif))),
                'resultat' => fn (DemandeAcompte $d) => [
                    'id' => $d->id,
                    'titre' => $d->numero,
                    'sous_titre' => $d->client?->raison_sociale,
                    'details' => ['client' => $d->client?->raison_sociale, 'date' => $date($d->date_demande), 'statut' => $d->statut, 'montant' => (float) $d->montant],
                    'lien' => $lien('/demandes-acompte', $d->numero),
                ],
            ],
            'bons_livraison' => [
                'module' => 'ventes',
                'cle' => 'numero',
                'requete' => fn (string $motif) => BonLivraison::query()->with('facture:id,numero,client_id', 'facture.client:id,raison_sociale')
                    ->where(fn ($q) => $q->where('numero', 'like', $motif)
                        ->orWhereHas('facture', fn ($f) => $f->where('numero', 'like', $motif)->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif)))),
                'resultat' => fn (BonLivraison $b) => [
                    'id' => $b->id,
                    'titre' => $b->numero,
                    'sous_titre' => $b->facture?->client?->raison_sociale,
                    'details' => ['client' => $b->facture?->client?->raison_sociale, 'facture' => $b->facture?->numero, 'statut' => $b->statut, 'date' => $date($b->date_livraison ?? $b->date_preparation)],
                    'lien' => $lien('/bons-livraison', $b->numero),
                ],
            ],
            'commandes_fournisseur' => [
                'module' => 'achats',
                'cle' => 'numero',
                'requete' => fn (string $motif) => ComFourEntete::query()->with('fournisseur:id,raison_sociale')
                    ->where(fn ($q) => $q->where('numero', 'like', $motif)->orWhereHas('fournisseur', fn ($f) => $f->where('raison_sociale', 'like', $motif))),
                'resultat' => fn (ComFourEntete $c) => [
                    'id' => $c->id,
                    'titre' => $c->numero,
                    'sous_titre' => $c->fournisseur?->raison_sociale,
                    'details' => ['fournisseur' => $c->fournisseur?->raison_sociale, 'date' => $date($c->date_commande), 'statut' => $c->statut, 'montant' => (float) $c->montant_total, 'devise' => $c->devise],
                    'lien' => $lien('/commandes-fournisseur', $c->numero),
                ],
            ],
            'secteurs' => [
                'module' => null,
                'cle' => 'code',
                'requete' => fn (string $motif) => Secteur::query()->where($contient(['code', 'nom'])($motif)),
                'resultat' => fn (Secteur $s) => [
                    'id' => $s->id,
                    'titre' => "{$s->code} — {$s->nom}",
                    'sous_titre' => $s->description,
                    'details' => ['description' => $s->description, 'actif' => (bool) $s->actif],
                    'lien' => $lien('/secteurs', $s->code),
                ],
            ],
            'employes' => [
                'module' => null,
                'cle' => 'nom',
                'requete' => fn (string $motif) => EmployeTenant::query()->where($contient(['numero', 'nom', 'prenom', 'email'])($motif)),
                'resultat' => fn (EmployeTenant $e) => [
                    'id' => $e->id,
                    'titre' => trim("{$e->prenom} {$e->nom}"),
                    'sous_titre' => $e->poste,
                    'details' => ['numero' => $e->numero, 'poste' => $e->poste, 'email' => $e->email, 'telephone' => $e->telephone, 'actif' => (bool) $e->actif],
                    'lien' => $lien('/employes', $e->nom),
                ],
            ],
        ];
    }
}
