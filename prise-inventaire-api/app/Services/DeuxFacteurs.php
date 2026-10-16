<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Support\Totp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Double authentification par application (TOTP) : préparation, activation, codes de secours, et étape de
 * connexion (« défi ») entre le mot de passe et le code.
 */
final class DeuxFacteurs
{
    public const NOMBRE_CODES_SECOURS = 8;

    // Codes faux permis par utilisateur avant blocage, et durée du blocage.
    public const ECHECS_MAX = 10;

    public const BLOCAGE_MINUTES = 15;

    // Sans caractères ambigus (0/O, 1/I/L).
    private const ALPHABET_CODES = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Nouveau secret en attente de confirmation (la double authentification n'est pas encore active). */
    public function preparer(AdminUser $utilisateur): array
    {
        $secret = Totp::genererSecret();
        $utilisateur->forceFill([
            'deux_facteurs_secret' => $secret,
            'deux_facteurs_active_le' => null,
            'deux_facteurs_codes_secours' => null,
            'deux_facteurs_dernier_pas' => null,
        ])->save();

        return [
            'secret' => $secret,
            'uri' => Totp::uri($secret, $utilisateur->email, (string) config('comptes.deux_facteurs_emetteur')),
        ];
    }

    /** Active avec un premier code valide ; renvoie les codes de secours en clair (affichés une seule fois). */
    public function activer(AdminUser $utilisateur, string $code): ?array
    {
        if (! filled($utilisateur->deux_facteurs_secret)) {
            return null;
        }
        $pas = Totp::verifier($utilisateur->deux_facteurs_secret, $code);
        if ($pas === null) {
            return null;
        }

        $codes = $this->nouveauxCodes();
        $utilisateur->forceFill([
            'deux_facteurs_active_le' => now(),
            'deux_facteurs_dernier_pas' => $pas,
            'deux_facteurs_codes_secours' => array_map(fn ($c) => $this->empreinte($c), $codes),
        ])->save();

        return $codes;
    }

    public function desactiver(AdminUser $utilisateur): void
    {
        $utilisateur->forceFill([
            'deux_facteurs_secret' => null,
            'deux_facteurs_active_le' => null,
            'deux_facteurs_codes_secours' => null,
            'deux_facteurs_dernier_pas' => null,
        ])->save();
    }

    public function regenererCodes(AdminUser $utilisateur): array
    {
        $codes = $this->nouveauxCodes();
        $utilisateur->forceFill(['deux_facteurs_codes_secours' => array_map(fn ($c) => $this->empreinte($c), $codes)])->save();

        return $codes;
    }

    public function codesRestants(AdminUser $utilisateur): int
    {
        return count($utilisateur->deux_facteurs_codes_secours ?? []);
    }

    /**
     * Vérifie un code de l'application (une seule fois par pas de 30 s) ou un code de secours (consommé).
     * Verrou sur la ligne : deux requêtes simultanées ne peuvent pas utiliser le même code.
     */
    public function verifier(AdminUser $utilisateur, string $code): bool
    {
        return DB::transaction(function () use ($utilisateur, $code) {
            $frais = AdminUser::whereKey($utilisateur->id)->lockForUpdate()->first();
            if (! $frais?->deuxFacteursActive()) {
                return false;
            }

            $pas = Totp::verifier($frais->deux_facteurs_secret, $code, $frais->deux_facteurs_dernier_pas);
            if ($pas !== null) {
                $frais->forceFill(['deux_facteurs_dernier_pas' => $pas])->save();

                return true;
            }

            $codes = $frais->deux_facteurs_codes_secours ?? [];
            $position = array_search($this->empreinte($code), $codes, true);
            if ($position === false) {
                return false;
            }
            unset($codes[$position]);
            $frais->forceFill(['deux_facteurs_codes_secours' => array_values($codes)])->save();

            return true;
        });
    }

    /** Défi de connexion : prouve que le mot de passe a été vérifié, valable quelques minutes. */
    public function creerDefi(AdminUser $utilisateur): string
    {
        $defi = Str::random(64);
        $expire = now()->addMinutes((int) config('comptes.deux_facteurs_defi_minutes'));
        Cache::put($this->cleDefi($defi), ['utilisateur' => $utilisateur->id, 'essais' => 0, 'expire' => $expire->timestamp], $expire);

        return $defi;
    }

    /** Utilisateur du défi s'il est encore valable, sinon null. */
    public function utilisateurDuDefi(string $defi): ?AdminUser
    {
        $donnees = Cache::get($this->cleDefi($defi));

        return $donnees ? AdminUser::where('actif', true)->find($donnees['utilisateur']) : null;
    }

    /**
     * Compte un code faux. Au-delà du nombre d'essais permis, le défi est annulé (il faut se reconnecter) ;
     * les échecs sont aussi comptés par utilisateur, tous défis et adresses IP confondus.
     */
    public function echec(string $defi, AdminUser $utilisateur): void
    {
        $cleUtilisateur = $this->cleEchecs($utilisateur);
        Cache::add($cleUtilisateur, 0, now()->addMinutes(self::BLOCAGE_MINUTES));
        Cache::increment($cleUtilisateur);

        $cle = $this->cleDefi($defi);
        $donnees = Cache::get($cle);
        if (! $donnees) {
            return;
        }
        $donnees['essais']++;
        $donnees['essais'] >= (int) config('comptes.deux_facteurs_essais')
            ? Cache::forget($cle)
            : Cache::put($cle, $donnees, now()->setTimestamp($donnees['expire']));
    }

    /** Trop de codes faux pour cet utilisateur récemment : l'étape du code est bloquée un moment. */
    public function bloque(AdminUser $utilisateur): bool
    {
        return (int) Cache::get($this->cleEchecs($utilisateur), 0) >= self::ECHECS_MAX;
    }

    public function reussite(AdminUser $utilisateur): void
    {
        Cache::forget($this->cleEchecs($utilisateur));
    }

    private function cleEchecs(AdminUser $utilisateur): string
    {
        return 'deux-facteurs-echecs:'.$utilisateur->id;
    }

    public function oublierDefi(string $defi): void
    {
        Cache::forget($this->cleDefi($defi));
    }

    private function cleDefi(string $defi): string
    {
        return 'connexion-deux-facteurs:'.hash('sha256', $defi);
    }

    /** @return list<string> codes « XXXXX-XXXXX » */
    private function nouveauxCodes(): array
    {
        $codes = [];
        while (count($codes) < self::NOMBRE_CODES_SECOURS) {
            $brut = '';
            for ($i = 0; $i < 10; $i++) {
                $brut .= self::ALPHABET_CODES[random_int(0, strlen(self::ALPHABET_CODES) - 1)];
            }
            $codes[] = substr($brut, 0, 5).'-'.substr($brut, 5);
        }

        return $codes;
    }

    /** Empreinte d'un code de secours, insensible à la casse, aux espaces et au tiret. */
    private function empreinte(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[\s-]+/', '', $code)));
    }
}
