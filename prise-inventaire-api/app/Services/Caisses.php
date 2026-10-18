<?php

namespace App\Services;

use App\Models\Acompte;
use App\Models\AcompteRemboursement;
use App\Models\AdminUser;
use App\Models\Caisse;
use App\Models\CaisseMouvement;
use App\Models\CaisseSession;
use App\Models\CarteCadeauMouvement;
use App\Models\FacturePaiement;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gestion de caisse (plan de parité fripv2, phase 1.10a), comme « Gestion de caisse » de fripv2 :
 * - une caisse s'ouvre avec un fond initial ; une seule session ouverte par caisse ;
 * - les encaissements faits sur un poste de caisse (paiements de factures, acomptes reçus, remboursements d'acompte)
 *   se rattachent à la session ouverte de sa caisse ; caisse fermée, l'encaissement est refusé ;
 * - entrées et sorties d'argent en cours de session (raison obligatoire pour une sortie) ;
 * - attendu par mode de paiement et devise = fond + encaissements + acomptes − remboursements + entrées − sorties.
 *   Appliquer un acompte sur une facture, ou la payer avec une carte cadeau, n'apporte pas d'argent : ces paiements-là
 *   ne sont pas comptés ; une carte cadeau vendue ou rechargée est un encaissement, son remboursement une sortie ;
 * - la fermeture fige l'attendu, le compté et l'écart : le sommaire réimprimé plus tard reste identique.
 */
class Caisses
{
    /** Modes de paiement, dans l'ordre d'affichage. */
    public const MODES = Acomptes::MODES;

    public const LIBELLES_MODES = ['especes' => 'Espèces', 'cheque' => 'Chèque', 'virement' => 'Virement', 'carte' => 'Carte', 'autre' => 'Autre'];

    public const LIBELLES_TYPES = [
        'fond' => 'Fond de caisse', 'encaissement' => 'Encaissement', 'acompte' => 'Acompte reçu',
        'remboursement' => "Remboursement d'acompte", 'entree' => "Entrée d'argent", 'sortie' => "Sortie d'argent",
        'carte_cadeau' => 'Carte cadeau vendue', 'recharge_carte' => 'Recharge de carte cadeau', 'remboursement_carte' => 'Remboursement de carte cadeau',
    ];

    /** Mouvement d'une carte cadeau qui fait entrer ou sortir de l'argent => type d'opération de la caisse. */
    private const OPERATIONS_CARTES = ['vente' => 'carte_cadeau', 'recharge' => 'recharge_carte', 'remboursement' => 'remboursement_carte'];

    /** Colonnes additionnées d'une ligne (mode, devise). */
    private const COLONNES = ['fond', 'encaissements', 'acomptes', 'remboursements', 'entrees', 'sorties'];

    /** Mouvement manuel => colonne. */
    private const COLONNE_MOUVEMENT = ['fond' => 'fond', 'entree' => 'entrees', 'sortie' => 'sorties'];

    /** Encaissements hors caisse montrés au plus dans un sommaire. */
    private const MAX_SANS_CAISSE = 500;

    private ?string $reference = null;

    public function __construct(private readonly DevisesVente $devises) {}

    /**
     * Session où rattacher un encaissement fait sur le poste d'une caisse (null : poste sans caisse). À appeler dans
     * la transaction de l'encaissement : la session ne peut pas se fermer avant que celui-ci soit enregistré.
     */
    public function sessionPourEncaissement(mixed $caisseId): ?int
    {
        if (empty($caisseId)) {
            return null;
        }
        $caisse = Caisse::find((int) $caisseId);
        if (! $caisse) {
            throw new DomainException(__("La caisse de ce poste n'existe plus : choisissez-en une autre dans Gestion de caisse."));
        }
        // Verrou partagé : les encaissements passent ensemble ; la fermeture attend qu'ils soient enregistrés.
        $session = CaisseSession::where('caisse_id', $caisse->id)->where('statut', CaisseSession::OUVERTE)->sharedLock()->first();
        if (! $session) {
            throw ValidationException::withMessages(['caisse_id' => __("La caisse :code n'est pas ouverte : ouvrez-la avant d'encaisser.", ['code' => $caisse->code])]);
        }

        return $session->id;
    }

    /** Ouvre la caisse avec son fond initial (qui peut être nul). */
    public function ouvrir(Caisse $caisse, float $fond, string $mode, ?string $devise, ?string $note, ?int $auteur): CaisseSession
    {
        return DB::transaction(function () use ($caisse, $fond, $mode, $devise, $note, $auteur) {
            // Verrou sur la caisse : deux ouvertures simultanées ne créent pas deux sessions.
            $caisse = Caisse::whereKey($caisse->id)->lockForUpdate()->firstOrFail();
            if (! $caisse->actif) {
                throw new DomainException(__('La caisse :code est désactivée.', ['code' => $caisse->code]));
            }
            if (CaisseSession::where('caisse_id', $caisse->id)->where('statut', CaisseSession::OUVERTE)->exists()) {
                throw new DomainException(__('La caisse :code est déjà ouverte.', ['code' => $caisse->code]));
            }
            $session = CaisseSession::create([
                'caisse_id' => $caisse->id, 'statut' => CaisseSession::OUVERTE, 'ouverte_le' => now(), 'ouverte_par' => $auteur,
            ]);
            if ($fond > 0) {
                CaisseMouvement::create([
                    'caisse_session_id' => $session->id, 'type' => 'fond', 'mode' => $mode, 'devise' => $this->devise($devise),
                    'montant' => round($fond, 2), 'note' => $note, 'cree_par' => $auteur,
                ]);
            }

            return $session;
        });
    }

    /** Entrée ou sortie d'argent d'une session ouverte. */
    public function mouvement(CaisseSession $session, string $type, string $mode, ?string $devise, float $montant, ?string $note, ?int $auteur): CaisseMouvement
    {
        return DB::transaction(function () use ($session, $type, $mode, $devise, $montant, $note, $auteur) {
            $session = CaisseSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if (! $session->estOuverte()) {
                throw new DomainException(__('Cette session de caisse est fermée.'));
            }

            return CaisseMouvement::create([
                'caisse_session_id' => $session->id, 'type' => $type, 'mode' => $mode, 'devise' => $this->devise($devise),
                'montant' => round($montant, 2), 'note' => $note, 'cree_par' => $auteur,
            ]);
        });
    }

    /**
     * Ferme la session : pour chaque mode et devise, l'attendu, le compté (0 s'il n'est pas saisi) et l'écart sont
     * figés, avec le total des écarts dans la devise de l'entreprise.
     *
     * @param  list<array{mode: string, devise?: ?string, montant: float|string}>  $comptes
     */
    public function fermer(CaisseSession $session, array $comptes, ?string $note, ?int $auteur): CaisseSession
    {
        return DB::transaction(function () use ($session, $comptes, $note, $auteur) {
            // Verrou exclusif : les encaissements en cours sur la caisse finissent d'abord, les suivants sont refusés.
            $session = CaisseSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if (! $session->estOuverte()) {
                throw new DomainException(__('Cette session de caisse est déjà fermée.'));
            }
            $lignes = [];
            foreach ($this->chiffres($session) as $ligne) {
                $lignes[$this->cle($ligne['mode'], $ligne['devise'])] = $ligne;
            }
            foreach ($comptes as $compte) {
                $devise = $this->devise($compte['devise'] ?? null);
                $cle = $this->cle($compte['mode'], $devise);
                $lignes[$cle] ??= $this->ligne($compte['mode'], $devise);
                $lignes[$cle]['compte'] = round((float) $compte['montant'], 2);
            }
            foreach ($lignes as &$ligne) {
                $ligne['compte'] ??= 0.0;
                $ligne['ecart'] = round($ligne['compte'] - $ligne['attendu'], 2);
            }
            unset($ligne);
            $chiffres = $this->trier($lignes);
            $session->update([
                'statut' => CaisseSession::FERMEE, 'fermee_le' => now(), 'fermee_par' => $auteur, 'note_fermeture' => $note,
                'chiffres' => $chiffres,
                'total_ecart' => round(array_sum(array_map(fn (array $l) => $l['devise'] === null ? $l['ecart'] : 0.0, $chiffres)), 2),
            ]);

            return $session;
        });
    }

    /**
     * Chiffres de la session par mode et devise : figés pour une session fermée, sinon calculés. Les modes de la
     * devise de l'entreprise y sont toujours ; une autre devise, dès qu'elle a servi.
     *
     * @return list<array{mode: string, devise: ?string, fond: float, encaissements: float, acomptes: float, remboursements: float, entrees: float, sorties: float, attendu: float, compte: ?float, ecart: ?float}>
     */
    public function chiffres(CaisseSession $session): array
    {
        if (! $session->estOuverte() && is_array($session->chiffres)) {
            return $session->chiffres;
        }
        $lignes = [];
        foreach (self::MODES as $mode) {
            $lignes[$this->cle($mode, null)] = $this->ligne($mode, null);
        }
        $ajouter = function (object $somme, string $colonne) use (&$lignes): void {
            $devise = $this->devise($somme->devise);
            $cle = $this->cle($somme->mode, $devise);
            $lignes[$cle] ??= $this->ligne($somme->mode, $devise);
            $lignes[$cle][$colonne] = round($lignes[$cle][$colonne] + (float) $somme->montant, 2);
        };

        CaisseMouvement::where('caisse_session_id', $session->id)->toBase()
            ->groupBy('type', 'mode', 'devise')->selectRaw('type, mode, devise, SUM(montant) AS montant')->get()
            ->each(fn ($s) => $ajouter($s, self::COLONNE_MOUVEMENT[$s->type] ?? 'entrees'));
        DB::table('facture_paiements')->join('factures', 'factures.id', '=', 'facture_paiements.facture_id')
            ->where('facture_paiements.caisse_session_id', $session->id)->whereNull('facture_paiements.acompte_id')
            ->whereNull('facture_paiements.carte_cadeau_id')
            ->groupBy('facture_paiements.mode_paiement', 'factures.devise')
            ->selectRaw('facture_paiements.mode_paiement AS mode, factures.devise AS devise, SUM(facture_paiements.montant) AS montant')->get()
            ->each(fn ($s) => $ajouter($s, 'encaissements'));
        Acompte::where('caisse_session_id', $session->id)->toBase()
            ->groupBy('mode', 'devise')->selectRaw('mode, devise, SUM(montant) AS montant')->get()
            ->each(fn ($s) => $ajouter($s, 'acomptes'));
        DB::table('acompte_remboursements')->join('acomptes', 'acomptes.id', '=', 'acompte_remboursements.acompte_id')
            ->where('acompte_remboursements.caisse_session_id', $session->id)
            ->groupBy('acompte_remboursements.mode', 'acomptes.devise')
            ->selectRaw('acompte_remboursements.mode AS mode, acomptes.devise AS devise, SUM(acompte_remboursements.montant) AS montant')->get()
            ->each(fn ($s) => $ajouter($s, 'remboursements'));
        // Cartes cadeaux vendues ou rechargées (encaissements) et remboursées (en positif dans les remboursements).
        DB::table('carte_cadeau_mouvements')->join('cartes_cadeaux', 'cartes_cadeaux.id', '=', 'carte_cadeau_mouvements.carte_cadeau_id')
            ->where('carte_cadeau_mouvements.caisse_session_id', $session->id)->whereIn('carte_cadeau_mouvements.type', array_keys(self::OPERATIONS_CARTES))
            ->groupBy('carte_cadeau_mouvements.type', 'carte_cadeau_mouvements.mode', 'cartes_cadeaux.devise')
            ->selectRaw('carte_cadeau_mouvements.type AS type, carte_cadeau_mouvements.mode AS mode, cartes_cadeaux.devise AS devise, SUM(carte_cadeau_mouvements.montant) AS montant')->get()
            ->each(fn ($s) => $s->type === 'remboursement'
                ? $ajouter((object) ['mode' => $s->mode, 'devise' => $s->devise, 'montant' => -(float) $s->montant], 'remboursements')
                : $ajouter($s, 'encaissements'));

        return $this->trier($lignes);
    }

    /**
     * Totaux d'une liste de lignes, par devise (celle de l'entreprise, null, d'abord).
     *
     * @return list<array<string, mixed>>
     */
    public function totaux(array $lignes): array
    {
        return collect($lignes)->groupBy(fn (array $l) => (string) $l['devise'])->map(function (Collection $groupe, string $devise) {
            $total = ['devise' => $devise === '' ? null : $devise];
            foreach ([...self::COLONNES, 'attendu'] as $colonne) {
                $total[$colonne] = round($groupe->sum($colonne), 2);
            }
            // Compté et écart : seulement une fois la caisse fermée.
            $comptee = $groupe->contains(fn (array $l) => $l['compte'] !== null);
            $total['compte'] = $comptee ? round($groupe->sum('compte'), 2) : null;
            $total['ecart'] = $comptee ? round($groupe->sum('ecart'), 2) : null;

            return $total;
        })->sortBy(fn (array $t) => $t['devise'] ?? '')->values()->all();
    }

    /**
     * Opérations de la session, dans l'ordre où elles ont eu lieu : fond, encaissements (facture payée, client),
     * acomptes reçus, remboursements d'acompte, entrées et sorties d'argent. Sorties et remboursements en négatif.
     *
     * @return list<array<string, mixed>>
     */
    public function operations(CaisseSession $session): array
    {
        $operations = collect();
        foreach (CaisseMouvement::where('caisse_session_id', $session->id)->get() as $mouvement) {
            $operations->push($this->operation($mouvement->created_at, $mouvement->type, null, $mouvement->mode, $mouvement->devise,
                $mouvement->type === 'sortie' ? -(float) $mouvement->montant : (float) $mouvement->montant, $mouvement->cree_par, $mouvement->note,
                ['mouvement_id' => $mouvement->id]));
        }
        $deLaSession = fn (Builder $q) => $q->where('caisse_session_id', $session->id);
        $operations = $operations->merge($this->encaissements($deLaSession, $deLaSession, $deLaSession, $deLaSession));

        return $this->avecCommis($operations->sortBy([['date', 'asc'], ['ordre', 'asc']])->values())->all();
    }

    /**
     * Encaissements faits hors caisse pendant la session (postes qui ne sont pas des caisses), pour ne rien perdre de
     * vue ; au plus MAX_SANS_CAISSE.
     *
     * @return array{operations: list<array<string, mixed>>, tronque: bool}
     */
    public function sansCaisse(CaisseSession $session): array
    {
        $periode = [$session->ouverte_le, $session->fermee_le ?? now()];
        $filtre = fn (Builder $q) => $q->whereNull('caisse_session_id')->whereBetween('created_at', $periode);
        $operations = $this->encaissements($filtre, $filtre, $filtre, $filtre)->sortBy([['date', 'asc'], ['ordre', 'asc']])->values();

        return [
            'operations' => $this->avecCommis($operations->take(self::MAX_SANS_CAISSE))->all(),
            'tronque' => $operations->count() > self::MAX_SANS_CAISSE,
        ];
    }

    /**
     * Encaissements par employé, mode et devise (acomptes compris, moins leurs remboursements), utile quand plusieurs
     * caissiers partagent la caisse.
     *
     * @param  list<array<string, mixed>>  $operations
     * @return list<array{commis: ?string, mode: string, devise: ?string, montant: float, nombre: int}>
     */
    public function parUsager(array $operations): array
    {
        return collect($operations)->whereIn('type', ['encaissement', 'acompte', 'remboursement', ...array_values(self::OPERATIONS_CARTES)])
            ->groupBy(fn (array $o) => ($o['commis'] ?? '').'|'.$o['mode'].'|'.$o['devise'])
            ->map(fn (Collection $groupe) => [
                'commis' => $groupe->first()['commis'], 'mode' => $groupe->first()['mode'], 'devise' => $groupe->first()['devise'],
                'montant' => round($groupe->sum('montant'), 2), 'nombre' => $groupe->count(),
            ])
            ->sortBy([['commis', 'asc'], [fn (array $a, array $b) => $this->rang($a['mode'], $a['devise']) <=> $this->rang($b['mode'], $b['devise'])]])
            ->values()->all();
    }

    /** Tout ce que montrent l'écran et le sommaire imprimé d'une session. */
    public function sommaire(CaisseSession $session): array
    {
        $session->loadMissing(['caisse:id,code,nom', 'ouvreur:id,nom', 'fermeur:id,nom']);
        $lignes = $this->chiffres($session);
        $operations = $this->operations($session);

        return collect($session->toArray())->except('chiffres')->all() + [
            'lignes' => $lignes,
            'totaux' => $this->totaux($lignes),
            'operations' => $operations,
            'par_usager' => $this->parUsager($operations),
            'sans_caisse' => $this->sansCaisse($session),
        ];
    }

    /**
     * Sessions d'une période (jours du fuseau de l'utilisateur) : celles qui y ont été ouvertes, plus celles encore
     * ouvertes, les plus récentes d'abord, avec leurs totaux (attendu en cours pour une session ouverte).
     */
    public function liste(?int $caisseId, string $du, string $au, string $fuseau): Collection
    {
        $debut = Carbon::parse($du, $fuseau)->startOfDay()->utc();
        $fin = Carbon::parse($au, $fuseau)->addDay()->startOfDay()->utc();

        return CaisseSession::with(['caisse:id,code,nom', 'ouvreur:id,nom', 'fermeur:id,nom'])
            ->when($caisseId, fn ($q) => $q->where('caisse_id', $caisseId))
            ->where(fn ($q) => $q->where('statut', CaisseSession::OUVERTE)
                ->orWhere(fn ($r) => $r->where('ouverte_le', '<', $fin)->where('fermee_le', '>=', $debut)))
            ->orderByDesc('ouverte_le')->orderByDesc('id')->limit(500)->get()
            ->map(fn (CaisseSession $s) => collect($s->toArray())->except('chiffres')->all() + ['totaux' => $this->totaux($this->chiffres($s))]);
    }

    /**
     * Encaissements (paiements de factures, hors application d'acompte et paiement par carte cadeau, acomptes reçus,
     * remboursements d'acompte, cartes cadeaux vendues, rechargées ou remboursées) retenus par les filtres donnés.
     */
    private function encaissements(callable $paiements, callable $acomptes, callable $remboursements, callable $cartes): Collection
    {
        $operations = collect();
        $requete = FacturePaiement::whereNull('acompte_id')->whereNull('carte_cadeau_id')->whereHas('facture')
            ->with(['facture:id,numero,client_id,devise,passage_nom', 'facture.client:id,code,raison_sociale']);
        foreach ($paiements($requete)->get() as $paiement) {
            $facture = $paiement->facture;
            $operations->push($this->operation($paiement->created_at, 'encaissement', $facture?->numero, $paiement->mode_paiement,
                $facture?->devise, (float) $paiement->montant, $paiement->enregistre_par, $paiement->reference ?: $paiement->notes,
                ['facture_id' => $facture?->id, 'client' => $facture?->passage_nom ?: $facture?->client?->raison_sociale]));
        }
        foreach ($acomptes(Acompte::with('client:id,code,raison_sociale'))->get() as $acompte) {
            $operations->push($this->operation($acompte->created_at, 'acompte', $this->documentAcompte($acompte), $acompte->mode,
                $acompte->devise, (float) $acompte->montant, $acompte->enregistre_par, $acompte->notes,
                ['acompte_id' => $acompte->id, 'client' => $acompte->client?->raison_sociale]));
        }
        $requete = AcompteRemboursement::whereHas('acompte')->with('acompte.client:id,code,raison_sociale');
        foreach ($remboursements($requete)->get() as $remboursement) {
            $acompte = $remboursement->acompte;
            $operations->push($this->operation($remboursement->created_at, 'remboursement', $this->documentAcompte($acompte),
                $remboursement->mode, $acompte?->devise, -(float) $remboursement->montant, $remboursement->enregistre_par,
                $remboursement->reference ?: $remboursement->notes, ['acompte_id' => $acompte?->id, 'client' => $acompte?->client?->raison_sociale]));
        }
        $requete = CarteCadeauMouvement::whereIn('type', array_keys(self::OPERATIONS_CARTES))->with('carte.client:id,code,raison_sociale');
        foreach ($cartes($requete)->get() as $mouvement) {
            $carte = $mouvement->carte;
            $operations->push($this->operation($mouvement->created_at, self::OPERATIONS_CARTES[$mouvement->type], 'Carte cadeau '.$carte?->numero,
                (string) $mouvement->mode, $carte?->devise, (float) $mouvement->montant, $mouvement->cree_par, $mouvement->reference,
                ['carte_cadeau_id' => $mouvement->carte_cadeau_id, 'client' => $carte?->client?->raison_sociale]));
        }

        return $operations;
    }

    /** Une opération ; `$liens` : document d'origine (facture, acompte ou mouvement) et client. */
    private function operation(mixed $date, string $type, ?string $document, string $mode, ?string $devise, float $montant, ?int $auteur, ?string $note, array $liens): array
    {
        return array_merge([
            'date' => Carbon::parse($date)->toIso8601String(), 'type' => $type, 'ordre' => array_search($type, array_keys(self::LIBELLES_TYPES), true),
            'document' => $document, 'facture_id' => null, 'acompte_id' => null, 'mouvement_id' => null, 'carte_cadeau_id' => null, 'client' => null,
            'mode' => $mode, 'devise' => $this->devise($devise), 'montant' => round($montant, 2), 'auteur' => $auteur, 'note' => $note,
        ], $liens);
    }

    /** Nom de l'employé de chaque opération. */
    private function avecCommis(Collection $operations): Collection
    {
        $noms = AdminUser::whereKey($operations->pluck('auteur')->filter()->unique()->values())->pluck('nom', 'id');

        return $operations->map(fn (array $o) => collect($o)->except(['auteur', 'ordre'])->all() + ['commis' => $noms[$o['auteur']] ?? null]);
    }

    private function documentAcompte(?Acompte $acompte): ?string
    {
        if (! $acompte) {
            return null;
        }

        return 'Acompte '.($acompte->reference ?: 'du '.$acompte->date_acompte->format('d/m/Y'));
    }

    /** Devise d'un montant : null pour celle de l'entreprise. */
    private function devise(?string $devise): ?string
    {
        $code = strtoupper(trim((string) $devise));
        $this->reference ??= $this->devises->reference();

        return $code === '' || $code === $this->reference ? null : $code;
    }

    private function cle(string $mode, ?string $devise): string
    {
        return $mode.'|'.$devise;
    }

    private function ligne(string $mode, ?string $devise): array
    {
        return ['mode' => $mode, 'devise' => $devise] + array_fill_keys(self::COLONNES, 0.0) + ['attendu' => 0.0, 'compte' => null, 'ecart' => null];
    }

    /** Lignes complétées (attendu) et classées : devise de l'entreprise d'abord, modes dans leur ordre. */
    private function trier(array $lignes): array
    {
        $lignes = array_map(function (array $l) {
            $l['attendu'] = round($l['fond'] + $l['encaissements'] + $l['acomptes'] - $l['remboursements'] + $l['entrees'] - $l['sorties'], 2);

            return $l;
        }, array_values($lignes));
        usort($lignes, fn (array $a, array $b) => $this->rang($a['mode'], $a['devise']) <=> $this->rang($b['mode'], $b['devise']));

        return $lignes;
    }

    private function rang(string $mode, ?string $devise): string
    {
        $position = array_search($mode, self::MODES, true);

        return ($devise === null ? '0' : '1'.$devise).'|'.str_pad((string) ($position === false ? 99 : $position), 2, '0', STR_PAD_LEFT);
    }
}
