<?php

namespace App\Services;

use App\Models\CarteCadeau;
use App\Models\CarteCadeauMouvement;
use App\Models\Configuration;
use App\Models\Devise;
use App\Models\FacturePaiement;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cartes cadeaux (plan de parité fripv2, phase 1.10c), comme « Cartes cadeaux » de fripv2 :
 * - une carte au porteur porte un numéro unique (automatique, ou celui d'une carte pré-imprimée si la numérotation
 *   n'est pas automatique) et un solde, la somme de ses mouvements ;
 * - à la vente et à chaque recharge, le montant est encaissé par son mode, sur la caisse du poste s'il en a une ;
 * - la carte paie une facture (point de vente, encaissement d'une facture) jusqu'à son solde : un paiement de la
 *   facture rattaché à la carte, qui n'apporte pas d'argent dans la caisse ;
 * - le remboursement rend tout le solde et annule la carte ; une carte annulée ne sert plus, une carte expirée ne paie
 *   plus.
 */
class CartesCadeaux
{
    public const MODES = Caisses::MODES;

    private const TOLERANCE = 0.005;

    public function __construct(private readonly Caisses $caisses, private readonly DevisesVente $devises) {}

    /**
     * Vend une carte : numéro attribué (ou saisi), montant initial encaissé.
     *
     * @param  array{numero?: ?string, client_id?: ?int, devise?: ?string, montant: float|string, mode: string, reference?: ?string, date: string, expire_le?: ?string, notes?: ?string, caisse_id?: ?int}  $donnees
     */
    public function vendre(array $donnees, ?int $auteur, int $tenantId): CarteCadeau
    {
        $config = Configuration::pourEntite('carte_cadeau', $tenantId);
        $automatique = $config && $config->auto_increment;
        $saisi = mb_strtoupper(trim((string) ($donnees['numero'] ?? '')));
        if (! $automatique && $saisi === '') {
            throw ValidationException::withMessages(['numero' => 'Saisissez ou scannez le numéro de la carte.']);
        }
        $devise = $this->devise($donnees['devise'] ?? null);
        if ($devise !== null && ! Devise::where('code', $devise)->where('actif', true)->exists()) {
            throw ValidationException::withMessages(['devise' => "La devise {$devise} n'est pas dans le référentiel des devises."]);
        }

        return DB::transaction(function () use ($donnees, $auteur, $tenantId, $automatique, $saisi, $devise) {
            $session = $this->caisses->sessionPourEncaissement($donnees['caisse_id'] ?? null);
            $numero = $automatique ? $this->prochainNumero($tenantId) : $saisi;
            if (! $automatique && CarteCadeau::where('numero', $numero)->exists()) {
                throw ValidationException::withMessages(['numero' => "Le numéro {$numero} est déjà utilisé : une carte ne réutilise jamais un numéro."]);
            }
            $montant = round((float) $donnees['montant'], 2);
            $carte = CarteCadeau::create([
                'numero' => $numero,
                'client_id' => $donnees['client_id'] ?? null,
                'devise' => $devise,
                'montant_initial' => $montant,
                'solde' => 0,
                'date_vente' => $donnees['date'],
                'expire_le' => $donnees['expire_le'] ?? null,
                'notes' => $donnees['notes'] ?? null,
                'cree_par' => $auteur,
            ]);
            $this->mouvement($carte, 'vente', $montant, $auteur, ['mode' => $donnees['mode'], 'reference' => $donnees['reference'] ?? null, 'caisse_session_id' => $session]);

            return $carte->fresh();
        });
    }

    /** Ajoute du solde à une carte qui n'est pas annulée ; le montant est encaissé. */
    public function recharger(CarteCadeau $carte, float $montant, string $mode, ?string $reference, mixed $caisseId, ?int $auteur): CarteCadeau
    {
        return DB::transaction(function () use ($carte, $montant, $mode, $reference, $caisseId, $auteur) {
            $session = $this->caisses->sessionPourEncaissement($caisseId);
            $carte = CarteCadeau::whereKey($carte->id)->lockForUpdate()->firstOrFail();
            if ($carte->annulee) {
                throw new DomainException("La carte {$carte->numero} est annulée : elle ne se recharge plus.");
            }
            $this->mouvement($carte, 'recharge', round($montant, 2), $auteur, ['mode' => $mode, 'reference' => $reference, 'caisse_session_id' => $session]);

            return $carte->fresh();
        });
    }

    /** Rend tout le solde par le mode choisi et annule la carte. */
    public function rembourser(CarteCadeau $carte, string $mode, ?string $reference, mixed $caisseId, ?int $auteur): CarteCadeau
    {
        return DB::transaction(function () use ($carte, $mode, $reference, $caisseId, $auteur) {
            $session = $this->caisses->sessionPourEncaissement($caisseId);
            $carte = CarteCadeau::whereKey($carte->id)->lockForUpdate()->firstOrFail();
            if ($carte->annulee) {
                throw new DomainException("La carte {$carte->numero} est déjà annulée.");
            }
            if ((float) $carte->solde > self::TOLERANCE) {
                $this->mouvement($carte, 'remboursement', -round((float) $carte->solde, 2), $auteur, ['mode' => $mode, 'reference' => $reference, 'caisse_session_id' => $session]);
            }
            $carte->update(['annulee' => true]);

            return $carte->fresh();
        });
    }

    /**
     * Carte qui paie : elle existe, n'est ni annulée ni expirée, est dans la devise de la facture et a assez de solde. À
     * appeler dans la transaction du paiement : la carte reste verrouillée jusqu'à la fin.
     */
    public function pourPaiement(string $numero, ?string $devise, float $montant, string $date): CarteCadeau
    {
        $numero = mb_strtoupper(trim($numero));
        $carte = CarteCadeau::where('numero', $numero)->lockForUpdate()->first();
        $refus = match (true) {
            ! $carte => "Carte cadeau {$numero} introuvable.",
            $carte->annulee => "La carte cadeau {$numero} est annulée.",
            $carte->expire_le !== null && $carte->expire_le->toDateString() < $date => "La carte cadeau {$numero} a expiré le {$carte->expire_le->format('d/m/Y')}.",
            ! DevisesVente::memeDevise($this->devise($carte->devise), $this->devise($devise)) => "La carte cadeau {$numero} n'est pas dans la devise de la facture.",
            $montant > (float) $carte->solde + self::TOLERANCE => "Solde insuffisant sur la carte {$numero} (solde disponible : ".number_format((float) $carte->solde, 2, ',', ' ').' '.$this->devises->symbole($carte->devise).').',
            default => null,
        };
        if ($refus) {
            throw ValidationException::withMessages(['carte_cadeau' => $refus]);
        }

        return $carte;
    }

    /** La carte a payé une facture : son solde baisse d'autant (le paiement est déjà enregistré). */
    public function utiliser(CarteCadeau $carte, FacturePaiement $paiement, ?int $auteur): void
    {
        $this->mouvement($carte, 'utilisation', -round((float) $paiement->montant, 2), $auteur, [
            'facture_id' => $paiement->facture_id, 'facture_paiement_id' => $paiement->id, 'reference' => null,
        ]);
    }

    /** Ce que la carte peut payer aujourd'hui (consultation au moment de payer). */
    public function consulter(string $numero): ?CarteCadeau
    {
        return CarteCadeau::where('numero', mb_strtoupper(trim($numero)))->first();
    }

    private function mouvement(CarteCadeau $carte, string $type, float $montant, ?int $auteur, array $details): CarteCadeauMouvement
    {
        $solde = round((float) $carte->solde + $montant, 2);
        $carte->update(['solde' => $solde]);

        return CarteCadeauMouvement::create($details + [
            'carte_cadeau_id' => $carte->id, 'type' => $type, 'montant' => $montant, 'solde_apres' => $solde, 'cree_par' => $auteur,
        ]);
    }

    /** Numéro automatique ; s'il a déjà été saisi à la main, le suivant. */
    private function prochainNumero(int $tenantId): string
    {
        for ($essai = 0; $essai < 100; $essai++) {
            $numero = Configuration::consommerNumero('carte_cadeau', $tenantId);
            if (! CarteCadeau::where('numero', $numero)->exists()) {
                return $numero;
            }
        }
        throw new DomainException('Aucun numéro de carte libre : vérifiez la numérotation des cartes cadeaux.');
    }

    /** Devise d'une carte : null pour celle de l'entreprise. */
    private function devise(?string $devise): ?string
    {
        $code = strtoupper(trim((string) $devise));

        return $code === '' || $code === $this->devises->reference() ? null : $code;
    }
}
